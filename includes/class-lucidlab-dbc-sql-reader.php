<?php
/**
 * Streaming SQL statement reader for restores.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads complete SQL statements from a file, starting at a byte offset.
 *
 * Statements end at the delimiter (";" or the one set with a DELIMITER line) outside of quoted
 * strings ('…', "…", `…`, with backslash escapes and doubled quotes) and comments. "-- " and "#"
 * line comments and plain block comments are removed; executable comments are kept.
 * After every statement offset() is the byte position right after its delimiter, so a restore can
 * stop there and continue in the next request.
 */
final class LucidLab_DBC_Sql_Reader {

	/**
	 * Bytes read at a time.
	 */
	private const CHUNK = 1048576;

	/**
	 * Largest statement accepted.
	 */
	private const MAX_STATEMENT = 268435456;

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $fh;

	/**
	 * Buffer.
	 *
	 * @var string
	 */
	private $buf = '';

	/**
	 * Position in the buffer.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * File offset of the first buffer byte.
	 *
	 * @var int
	 */
	private $base = 0;

	/**
	 * End of file reached.
	 *
	 * @var bool
	 */
	private $eof = false;

	/**
	 * Statement delimiter.
	 *
	 * @var string
	 */
	private $delimiter = ';';

	/**
	 * Constructor.
	 *
	 * @param resource $fh        Handle opened for reading.
	 * @param int      $offset    Byte offset to start at.
	 * @param string   $delimiter Current delimiter.
	 */
	public function __construct( $fh, int $offset = 0, string $delimiter = ';' ) {
		$this->fh        = $fh;
		$this->base      = max( 0, $offset );
		$this->delimiter = '' !== $delimiter ? $delimiter : ';';
		fseek( $this->fh, $this->base );
	}

	/**
	 * Byte offset after the last returned statement.
	 *
	 * @return int
	 */
	public function offset(): int {
		return $this->base + $this->pos;
	}

	/**
	 * Current delimiter.
	 *
	 * @return string
	 */
	public function delimiter(): string {
		return $this->delimiter;
	}

	/**
	 * Read more data into the buffer.
	 *
	 * @param int $want Minimum bytes to read.
	 *
	 * @return bool False at the end of the file.
	 */
	private function fill( int $want = 0 ): bool {
		if ( $this->eof ) {
			return false;
		}
		$data = fread( $this->fh, max( self::CHUNK, $want ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streams a large SQL file in chunks.
		if ( false === $data || '' === $data ) {
			$this->eof = true;
			return false;
		}
		$this->buf .= $data;
		return true;
	}

	/**
	 * Drop the consumed part of the buffer.
	 */
	private function compact(): void {
		if ( $this->pos > 4 * self::CHUNK ) {
			$this->buf   = substr( $this->buf, $this->pos );
			$this->base += $this->pos;
			$this->pos   = 0;
		}
	}

	/**
	 * Next statement, or null at the end of the file.
	 *
	 * @return string|null
	 *
	 * @throws RuntimeException When a statement is larger than the limit.
	 */
	public function next(): ?string {
		while ( true ) {
			if ( ! $this->skip_space() ) {
				return null;
			}
			// DELIMITER is a client command; it is only valid at the start of a statement.
			if ( 0 === strncasecmp( substr( $this->buf, $this->pos, 10 ), 'DELIMITER ', 10 ) ) {
				$eol = strpos( $this->buf, "\n", $this->pos );
				while ( false === $eol && $this->fill() ) {
					$eol = strpos( $this->buf, "\n", $this->pos );
				}
				$end             = false === $eol ? strlen( $this->buf ) : $eol;
				$token           = trim( substr( $this->buf, $this->pos + 10, $end - $this->pos - 10 ) );
				$this->delimiter = '' !== $token ? $token : ';';
				$this->pos       = false === $eol ? $end : $eol + 1;
				$this->compact();
				continue;
			}
			$statement = $this->scan();
			$this->compact();
			$statement = trim( $statement );
			if ( '' !== $statement ) {
				return $statement;
			}
		}
	}

	/**
	 * Skip white space and comments before a statement.
	 *
	 * @return bool False at the end of the file.
	 */
	private function skip_space(): bool {
		while ( true ) {
			$len        = strlen( $this->buf );
			$this->pos += strspn( $this->buf, " \t\r\n", $this->pos );
			if ( $this->pos + 3 > $len && ! $this->eof ) {
				$this->fill();
				continue;
			}
			if ( $this->pos >= $len ) {
				return false;
			}
			$c = $this->buf[ $this->pos ];
			if ( '#' === $c || $this->is_dash_comment( $this->pos ) ) {
				if ( ! $this->skip_to( "\n", $this->pos, 1 ) ) {
					return false;
				}
				continue;
			}
			if ( '/' === $c && '*' === ( $this->buf[ $this->pos + 1 ] ?? '' ) && '!' !== ( $this->buf[ $this->pos + 2 ] ?? '' ) ) {
				if ( ! $this->skip_to( '*/', $this->pos + 2, 2 ) ) {
					return false;
				}
				continue;
			}
			return true;
		}
	}

	/**
	 * Move $this->pos past the next $needle (searching from $from).
	 *
	 * @param string $needle Needle.
	 * @param int    $from   Search start.
	 * @param int    $skip   Length to skip after the match.
	 *
	 * @return bool False when the file ended first.
	 */
	private function skip_to( string $needle, int $from, int $skip ): bool {
		$at = strpos( $this->buf, $needle, $from );
		while ( false === $at ) {
			if ( ! $this->fill() ) {
				$this->pos = strlen( $this->buf );
				return false;
			}
			$at = strpos( $this->buf, $needle, $from );
		}
		$this->pos = $at + $skip;
		$this->compact();
		return true;
	}

	/**
	 * Whether "-- " starts a comment at $i (two dashes followed by white space or the end).
	 *
	 * @param int $i Position.
	 *
	 * @return bool
	 */
	private function is_dash_comment( int $i ): bool {
		if ( '-' !== ( $this->buf[ $i ] ?? '' ) || '-' !== ( $this->buf[ $i + 1 ] ?? '' ) ) {
			return false;
		}
		$next = $this->buf[ $i + 2 ] ?? '';
		return '' === $next || ctype_space( $next ) || ctype_cntrl( $next );
	}

	/**
	 * Find the end of a quoted string that starts at $i.
	 *
	 * @param int $i Position of the opening quote.
	 *
	 * @return int Position of the closing quote, or -1 when more data is needed.
	 */
	private function close_quote( int $i ): int {
		$q   = $this->buf[ $i ];
		$len = strlen( $this->buf );
		$j   = $i + 1;
		while ( true ) {
			$k = $j + strcspn( $this->buf, '`' === $q ? '`' : $q . '\\', $j );
			if ( $k >= $len ) {
				return -1;
			}
			if ( '\\' === $this->buf[ $k ] ) {
				$j = $k + 2;
				if ( $j > $len ) {
					return -1;
				}
				continue;
			}
			if ( $k + 1 >= $len && ! $this->eof ) {
				return -1; // Cannot tell yet whether the quote is doubled.
			}
			if ( ( $this->buf[ $k + 1 ] ?? '' ) === $q ) {
				$j = $k + 2;
				continue;
			}
			return $k;
		}
	}

	/**
	 * Scan one statement starting at $this->pos.
	 *
	 * @return string Statement without its delimiter and without plain comments.
	 *
	 * @throws RuntimeException When a statement is larger than the limit.
	 */
	private function scan(): string {
		$start = $this->pos;
		$out   = '';
		$seg   = $this->pos;
		$i     = $this->pos;
		$delim = $this->delimiter;
		$dlen  = strlen( $delim );
		$stops = "'\"`-#/" . $delim[0];
		while ( true ) {
			$len = strlen( $this->buf );
			if ( $i - $start > self::MAX_STATEMENT ) {
				throw new RuntimeException( esc_html__( 'A statement in the file is larger than 256 MB.', 'lucidlab-db-cleanup' ) );
			}
			if ( $i + $dlen + 2 >= $len && ! $this->eof ) {
				$this->fill( $i - $start );
				continue;
			}
			if ( $i >= $len ) {
				$this->pos = $len;
				return $out . substr( $this->buf, $seg );
			}
			$i += strcspn( $this->buf, $stops, $i );
			if ( $i >= $len ) {
				continue;
			}
			$c = $this->buf[ $i ];
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$end = $this->close_quote( $i );
				if ( $end < 0 ) {
					if ( ! $this->fill( $i - $start ) ) {
						$this->pos = strlen( $this->buf );
						return $out . substr( $this->buf, $seg );
					}
					continue;
				}
				$i = $end + 1;
				continue;
			}
			if ( '#' === $c || $this->is_dash_comment( $i ) ) {
				$out .= substr( $this->buf, $seg, $i - $seg );
				$eol  = strpos( $this->buf, "\n", $i );
				while ( false === $eol && $this->fill() ) {
					$eol = strpos( $this->buf, "\n", $i );
				}
				$i   = false === $eol ? strlen( $this->buf ) : $eol;
				$seg = $i;
				continue;
			}
			if ( '/' === $c && '*' === ( $this->buf[ $i + 1 ] ?? '' ) ) {
				$close = strpos( $this->buf, '*/', $i + 2 );
				while ( false === $close && $this->fill() ) {
					$close = strpos( $this->buf, '*/', $i + 2 );
				}
				$close = false === $close ? strlen( $this->buf ) : $close + 2;
				if ( '!' !== ( $this->buf[ $i + 2 ] ?? '' ) ) {
					$out .= substr( $this->buf, $seg, $i - $seg ) . ' ';
					$seg  = $close;
				}
				$i = $close;
				continue;
			}
			if ( $c === $delim[0] && 0 === substr_compare( $this->buf, $delim, $i, $dlen ) ) {
				$statement = $out . substr( $this->buf, $seg, $i - $seg );
				$this->pos = $i + $dlen;
				return $statement;
			}
			++$i;
		}
	}
}
