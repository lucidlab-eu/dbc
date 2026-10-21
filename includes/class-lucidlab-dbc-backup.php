<?php
/**
 * SQL backups of every row before it is deleted, and restore.
 *
 * Each backup is a plain .sql file with one statement per line, so it can be restored from the
 * Backups tab in small AJAX steps or imported with any MySQL client.
 *
 * @package LucidLab_DB_Cleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backup writer and restorer.
 */
final class LucidLab_DBC_Backup {

	/**
	 * Option holding the backup index.
	 */
	public const OPTION = 'lucidlab_dbc_backups';

	/**
	 * Folder below the uploads directory.
	 */
	private const SUBDIR = 'lucidlab-db-cleanup';

	/**
	 * Option holding the random part of the backup folder name.
	 */
	public const DIR_KEY_OPTION = 'lucidlab_dbc_dir_key';

	/**
	 * Absolute backup directory (no trailing slash). The folder name carries a random key so it
	 * cannot be guessed on servers that ignore .htaccess (nginx).
	 *
	 * @return string
	 */
	public static function dir(): string {
		$key = (string) get_option( self::DIR_KEY_OPTION, '' );
		if ( ! preg_match( '/^[a-z0-9]{16}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 16, false, false ) );
			update_option( self::DIR_KEY_OPTION, $key, false );
		}
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::SUBDIR . '/backups-' . $key;
	}

	/**
	 * Create the backup directory and lock it down for web access.
	 *
	 * @return bool
	 */
	public static function ensure_dir(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess'  => "# Lucid Lab Database Cleaner: backups are never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( array( $dir, dirname( $dir ) ) as $folder ) {
			foreach ( $files as $name => $content ) {
				if ( dirname( $dir ) === $folder && '.htaccess' !== $name && 'index.php' !== $name ) {
					continue;
				}
				if ( ! file_exists( $folder . '/' . $name ) ) {
					self::write( $folder . '/' . $name, $content, false );
				}
			}
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Write to a file.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 * @param bool   $append  Append.
	 *
	 * @return bool
	 */
	private static function write( string $path, string $content, bool $append = true ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Streaming many small appends to a private backup file; WP_Filesystem has no append mode and may need credentials.
		return false !== file_put_contents( $path, $content, $append ? FILE_APPEND | LOCK_EX : LOCK_EX );
	}

	/**
	 * Full path of a backup id, or '' when the id is not valid.
	 *
	 * @param string $id Backup id.
	 *
	 * @return string
	 */
	public static function path( string $id ): string {
		if ( ! preg_match( '/^dbc-\d{8}-\d{6}-[a-z0-9]{12}$/', $id ) ) {
			return '';
		}
		return self::dir() . '/' . $id . '.sql';
	}

	/**
	 * Start a new backup file.
	 *
	 * @param array<int,string> $labels Labels of the findings being cleaned.
	 * @param string            $kind   What the backup is for: cleanup (rows are deleted afterwards) or change
	 *                                  (rows are changed afterwards; the backup holds REPLACE statements).
	 *
	 * @return string|WP_Error Backup id.
	 */
	public static function create( array $labels, string $kind = 'cleanup' ) {
		if ( ! self::ensure_dir() ) {
			return new WP_Error( 'lucidlab_dbc_backup_dir', __( 'The backup folder in wp-content/uploads is not writable, so nothing was deleted.', 'lucidlab-db-cleanup' ) );
		}
		global $wpdb;
		$id     = 'dbc-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 12, false, false ) );
		$header = array(
			'-- Lucid Lab Database Cleaner backup ' . LUCIDLAB_DBC_VERSION,
			'-- Site: ' . home_url( '/' ),
			'-- Created (UTC): ' . gmdate( 'Y-m-d H:i:s' ),
			'-- Table prefix: ' . $wpdb->prefix,
			'-- Restore from Tools in the plugin, or import this file into the same database.',
			'SET NAMES ' . ( $wpdb->charset ? $wpdb->charset : 'utf8mb4' ) . ';',
		);
		if ( ! self::write( self::path( $id ), implode( "\n", $header ) . "\n", false ) ) {
			return new WP_Error( 'lucidlab_dbc_backup_write', __( 'Could not write the backup file, so nothing was deleted.', 'lucidlab-db-cleanup' ) );
		}
		$index        = self::index();
		$index[ $id ] = array(
			'created'  => time(),
			'labels'   => array_slice( array_values( array_map( 'strval', $labels ) ), 0, 50 ),
			'rows'     => 0,
			'tables'   => 0,
			'status'   => 'writing',
			'restored' => 0,
			'kind'     => 'change' === $kind ? 'change' : 'cleanup',
		);
		update_option( self::OPTION, $index, false );
		return $id;
	}

	/**
	 * Backup index, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function index(): array {
		$index = get_option( self::OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		uasort(
			$index,
			static function ( $a, $b ) {
				return (int) ( $b['created'] ?? 0 ) <=> (int) ( $a['created'] ?? 0 );
			}
		);
		return $index;
	}

	/**
	 * Update counters of a backup.
	 *
	 * @param string              $id      Backup id.
	 * @param array<string,mixed> $changes Changes (rows/tables are added, others replaced).
	 */
	public static function touch( string $id, array $changes ): void {
		$index = self::index();
		if ( ! isset( $index[ $id ] ) ) {
			return;
		}
		$path = self::path( $id );
		if ( '' !== $path && file_exists( $path ) && ! isset( $changes['restored'] ) ) {
			clearstatcache( true, $path );
			// Size and time of the last write: lets an interrupted (unsigned) backup be restored only when unchanged.
			$changes['written_size']  = (int) filesize( $path );
			$changes['written_mtime'] = (int) filemtime( $path );
		}
		foreach ( $changes as $key => $value ) {
			if ( in_array( $key, array( 'rows', 'tables' ), true ) ) {
				$index[ $id ][ $key ] = (int) ( $index[ $id ][ $key ] ?? 0 ) + (int) $value;
			} else {
				$index[ $id ][ $key ] = $value;
			}
		}
		update_option( self::OPTION, $index, false );
	}

	/**
	 * Column names and which ones need hex literals.
	 *
	 * @param string $table Table.
	 *
	 * @return array<string,bool> Column => is binary.
	 */
	private static function columns( string $table ): array {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup for the backup writer.
				$cache[ $table ][ (string) $col['Field'] ] = (bool) preg_match( '/blob|binary|bit|geometry|point|polygon/i', (string) $col['Type'] );
			}
		}
		return $cache[ $table ];
	}

	/**
	 * Append rows of a table as INSERT IGNORE statements (one line per statement, up to ~512 KB each).
	 * With $replace the rows are written as REPLACE statements: restoring then puts back the old
	 * values of rows that were changed (autoload switches, search & replace).
	 *
	 * @param string                         $id      Backup id.
	 * @param string                         $table   Table.
	 * @param array<int,array<string,mixed>> $rows    Rows (ARRAY_A).
	 * @param bool                           $replace Write REPLACE INTO instead of INSERT IGNORE INTO.
	 *
	 * @return bool
	 */
	public static function add_rows( string $id, string $table, array $rows, bool $replace = false ): bool {
		global $wpdb;
		if ( ! $rows ) {
			return true;
		}
		$columns = self::columns( $table );
		$names   = array_keys( (array) $rows[0] );
		$head    = ( $replace ? 'REPLACE INTO ' : 'INSERT IGNORE INTO ' ) . LucidLab_DBC_Helpers::ident( $table ) . ' (' . implode( ', ', array_map( array( 'LucidLab_DBC_Helpers', 'ident' ), $names ) ) . ') VALUES ';
		$out     = '';
		$chunk   = array();
		$size    = 0;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $names as $name ) {
				$value = $row[ $name ] ?? null;
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( ! empty( $columns[ $name ] ) ) {
					$values[] = '' === (string) $value ? "''" : '0x' . bin2hex( (string) $value );
				} else {
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $value ) ) . "'";
				}
			}
			$tuple   = '(' . implode( ',', $values ) . ')';
			$chunk[] = $tuple;
			$size   += strlen( $tuple );
			if ( $size > 512 * KB_IN_BYTES ) {
				$out  .= $head . implode( ',', $chunk ) . ";\n";
				$chunk = array();
				$size  = 0;
			}
		}
		if ( $chunk ) {
			$out .= $head . implode( ',', $chunk ) . ";\n";
		}
		// Real line breaks inside values are escaped by _real_escape(), so every statement is one line.
		if ( ! self::write( self::path( $id ), $out ) ) {
			return false;
		}
		self::touch( $id, array( 'rows' => count( $rows ) ) );
		return true;
	}

	/**
	 * Append the CREATE TABLE statement of a table that is about to be dropped.
	 *
	 * @param string $id    Backup id.
	 * @param string $table Table.
	 *
	 * @return bool
	 */
	public static function add_create( string $id, string $table ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Read-only; the sniff mistakes SHOW CREATE for a schema change. Schema export for the backup.
		if ( empty( $row[1] ) ) {
			return false;
		}
		$sql = preg_replace( '/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', (string) $row[1] );
		$sql = str_replace( array( "\r\n", "\n", "\r" ), ' ', (string) $sql );
		if ( ! self::write( self::path( $id ), '-- table ' . $table . "\n" . $sql . ";\n" ) ) {
			return false;
		}
		self::touch( $id, array( 'tables' => 1 ) );
		return true;
	}

	/**
	 * Record term taxonomy ids whose counts must be refreshed after a restore.
	 *
	 * @param string         $id  Backup id.
	 * @param array<int,int> $tts Term taxonomy ids.
	 */
	public static function add_recount( string $id, array $tts ): void {
		$tts = array_values( array_unique( array_filter( array_map( 'intval', $tts ) ) ) );
		if ( $tts ) {
			self::write( self::path( $id ), '-- recount ' . implode( ',', $tts ) . "\n" );
			self::touch( $id, array() );
		}
	}

	/**
	 * Finish a backup.
	 *
	 * @param string $id Backup id.
	 */
	public static function finish( string $id ): void {
		self::write( self::path( $id ), '-- end of backup' . "\n" );
		self::touch(
			$id,
			array(
				'status'    => 'complete',
				'signature' => self::signature( $id ),
			)
		);
	}

	/**
	 * HMAC of a backup file with this site's secret keys, so a changed or foreign file is never replayed.
	 *
	 * @param string $id Backup id.
	 *
	 * @return string
	 */
	private static function signature( string $id ): string {
		$path = self::path( $id );
		return ( '' !== $path && is_readable( $path ) ) ? (string) hash_hmac_file( 'sha256', $path, wp_salt( 'auth' ) . '|lucidlab-dbc' ) : '';
	}

	/**
	 * Whether a backup file is unchanged since it was written.
	 *
	 * @param string $id Backup id.
	 *
	 * @return bool
	 */
	public static function verify( string $id ): bool {
		$entry = self::index()[ $id ] ?? array();
		$known = (string) ( $entry['signature'] ?? '' );
		if ( '' !== $known ) {
			return hash_equals( $known, self::signature( $id ) );
		}
		// Interrupted cleanup: no signature yet, so require the file to be exactly as last written.
		$path = self::path( $id );
		if ( '' === $path || ! file_exists( $path ) || empty( $entry['written_size'] ) ) {
			return false;
		}
		clearstatcache( true, $path );
		return (int) filesize( $path ) === (int) $entry['written_size'] && (int) filemtime( $path ) === (int) ( $entry['written_mtime'] ?? 0 );
	}

	/**
	 * Restore part of a backup, starting at a byte offset.
	 *
	 * @param string $id     Backup id.
	 * @param int    $offset Byte offset.
	 * @param float  $budget Seconds to spend.
	 *
	 * @return array{offset:int,size:int,done:bool,statements:int,errors:array<int,string>}|WP_Error
	 */
	public static function restore_step( string $id, int $offset, float $budget = 8.0 ) {
		global $wpdb;
		$path = self::path( $id );
		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'lucidlab_dbc_backup_missing', __( 'Backup file not found.', 'lucidlab-db-cleanup' ) );
		}
		if ( 0 === $offset && ! self::verify( $id ) ) {
			return new WP_Error( 'lucidlab_dbc_backup_signature', __( 'This backup file was changed after it was written, was not made on this site, or is incomplete, so it is not restored. You can still download it and import it with your own database tool.', 'lucidlab-db-cleanup' ) );
		}
		$size = (int) filesize( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the backup line by line from a byte offset; WP_Filesystem cannot seek.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'lucidlab_dbc_backup_open', __( 'Backup file could not be opened.', 'lucidlab-db-cleanup' ) );
		}
		fseek( $handle, max( 0, $offset ) );
		$start      = microtime( true );
		$statements = 0;
		$errors     = array();
		$recount    = array();
		$allowed    = array( 'INSERT IGNORE INTO `' . $wpdb->prefix, 'REPLACE INTO `' . $wpdb->prefix, 'CREATE TABLE IF NOT EXISTS `' . $wpdb->prefix );
		while ( ( microtime( true ) - $start ) < $budget ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			if ( str_starts_with( $line, '-- recount ' ) ) {
				$recount = array_merge( $recount, array_map( 'intval', explode( ',', substr( $line, 11 ) ) ) );
				continue;
			}
			if ( str_starts_with( $line, '--' ) || str_starts_with( $line, 'SET NAMES ' ) ) {
				continue;
			}
			$ok = false;
			foreach ( $allowed as $prefix ) {
				if ( str_starts_with( $line, $prefix ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				$errors[] = __( 'Skipped a line that is not a backup statement for this site.', 'lucidlab-db-cleanup' );
				continue;
			}
			// The line was written by add_rows()/add_create() and is limited to this site's tables above.
			$result = $wpdb->query( $line ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Replays a statement (INSERT IGNORE or CREATE TABLE IF NOT EXISTS) from our own backup file; values were escaped when the file was written.
			++$statements;
			if ( false === $result && $wpdb->last_error ) {
				$errors[] = $wpdb->last_error;
			}
		}
		$position = (int) ftell( $handle );
		$done     = feof( $handle ) || $position >= $size;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen above.
		if ( $recount ) {
			self::recount( $recount );
		}
		if ( $done ) {
			wp_cache_flush();
			self::touch(
				$id,
				array(
					'status'   => 'restored',
					'restored' => time(),
				)
			);
		}
		return array(
			'offset'     => $position,
			'size'       => $size,
			'done'       => $done,
			'statements' => $statements,
			'errors'     => array_slice( $errors, 0, 10 ),
		);
	}

	/**
	 * Refresh term counts.
	 *
	 * @param array<int,int> $tts Term taxonomy ids.
	 */
	public static function recount( array $tts ): void {
		global $wpdb;
		$tts = array_values( array_unique( array_filter( array_map( 'intval', $tts ) ) ) );
		if ( ! $tts ) {
			return;
		}
		$rows = (array) $wpdb->get_results( "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN (" . LucidLab_DBC_Helpers::in_ints( $tts ) . ')', ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The IN list holds integers from in_ints().
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ (string) $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
		}
		foreach ( $by as $taxonomy => $ids ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( $ids, $taxonomy );
			}
		}
	}

	/**
	 * Delete a backup file and its index entry.
	 *
	 * @param string $id Backup id.
	 *
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$path = self::path( $id );
		if ( '' === $path ) {
			return false;
		}
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		$index = self::index();
		unset( $index[ $id ] );
		update_option( self::OPTION, $index, false );
		return ! file_exists( $path );
	}

	/**
	 * Backups with file sizes; entries whose file vanished are dropped.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::index() as $id => $entry ) {
			$path = self::path( (string) $id );
			if ( '' === $path || ! file_exists( $path ) ) {
				continue;
			}
			$out[ $id ] = $entry + array( 'size' => (int) filesize( $path ) );
		}
		return $out;
	}

	/**
	 * Retention: keep the newest $keep backups and, when $days > 0, delete backups older than $days
	 * days. A backup that is still being written, and $except (the backup of the run that just
	 * finished), are never deleted.
	 *
	 * @param int    $keep   Number of backups to keep (0 = no limit).
	 * @param int    $days   Maximum age in days (0 = no limit).
	 * @param string $except Backup id to keep in any case.
	 *
	 * @return array<int,string> Deleted backup ids.
	 */
	public static function prune( int $keep, int $days, string $except = '' ): array {
		$deleted = array();
		$cutoff  = $days > 0 ? time() - $days * DAY_IN_SECONDS : 0;
		$seen    = 0;
		foreach ( self::index() as $id => $entry ) {
			$id = (string) $id;
			if ( $id === $except || 'writing' === ( $entry['status'] ?? '' ) ) {
				++$seen;
				continue;
			}
			++$seen;
			$too_many = $keep > 0 && $seen > $keep;
			$too_old  = $cutoff > 0 && (int) ( $entry['created'] ?? 0 ) < $cutoff;
			if ( ( $too_many || $too_old ) && self::delete( $id ) ) {
				$deleted[] = $id;
			}
		}
		return $deleted;
	}
}
