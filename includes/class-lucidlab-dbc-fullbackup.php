<?php
/**
 * Complete database backups: a gzip-compressed SQL dump of every table, written in small AJAX or
 * WP-Cron steps by pure PHP (no exec, no mysqldump), so it works on shared hosting.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full backup writer, list, retention, upload and download helpers.
 *
 * File format: plain SQL (mysqldump style), one INSERT per line with up to about 1 MB per statement,
 * DROP TABLE IF EXISTS + SHOW CREATE TABLE per table, binary columns as hex literals, NULL as NULL,
 * utf8mb4 connection, time zone +00:00 and FOREIGN_KEY_CHECKS=0. Every step appends a gzip member,
 * which gzip, zcat, phpMyAdmin and PHP read as one stream.
 */
final class LucidLab_DBC_Fullbackup {

	/**
	 * Index of full backups and uploaded files (autoload off).
	 */
	public const INDEX_OPTION = 'lucidlab_dbc_full_backups';

	/**
	 * Resumable state of the backup that is being written (autoload off).
	 */
	public const JOB_OPTION = 'lucidlab_dbc_full_job';

	/**
	 * Result of the last restore (autoload off).
	 */
	public const LAST_RESTORE_OPTION = 'lucidlab_dbc_full_restore';

	/**
	 * Prefix of the temporary tables a restore imports into; never part of a backup.
	 */
	public const TEMP_PREFIX = 'lldbr_';

	/**
	 * Prefix of the replaced tables right after a restore swap; never part of a backup.
	 */
	public const OLD_PREFIX = 'lldbo_';

	/**
	 * Target size of one INSERT statement.
	 */
	private const STATEMENT_BYTES = 1048576;

	/**
	 * Target memory per row batch.
	 */
	private const BATCH_BYTES = 4194304;

	/**
	 * Maximum rows per batch.
	 */
	private const BATCH_ROWS = 1000;

	/**
	 * A job without progress for this long may be replaced by a new one.
	 */
	private const STALE = 600;

	/**
	 * Seconds of work per step.
	 *
	 * @param float $budget Requested budget (0 = default).
	 *
	 * @return float
	 */
	public static function budget( float $budget = 0.0 ): float {
		$budget = $budget > 0 ? $budget : 8.0;
		/**
		 * Filters the seconds of work per full backup or restore step (default 8, at most a third
		 * of max_execution_time). Lower it when a proxy cuts off requests early.
		 *
		 * @param float $budget Seconds.
		 */
		$budget = max( 0.2, (float) apply_filters( 'lucidlab_dbc_full_step_seconds', $budget ) );
		$limit  = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 2.0, $limit / 3 ) );
		}
		return $budget;
	}

	/**
	 * Quote an identifier (table or column) for SQL text written to a backup file.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function quote_ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Escape a string value for a single-quoted SQL literal (utf8mb4 / binary safe: these bytes never
	 * occur inside a multi-byte UTF-8 sequence). The restore sets an sql_mode without
	 * NO_BACKSLASH_ESCAPES, like mysqldump files expect.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	public static function escape( string $value ): string {
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Backup id pattern.
	 *
	 * @param string $id Id.
	 *
	 * @return bool
	 */
	public static function valid_id( string $id ): bool {
		return (bool) preg_match( '/^(full|upl)-\d{8}-\d{6}-[a-z0-9]{24}$/', $id );
	}

	/**
	 * Full path of a backup file, or '' when unknown.
	 *
	 * @param string $id Backup id.
	 *
	 * @return string
	 */
	public static function path( string $id ): string {
		if ( ! self::valid_id( $id ) ) {
			return '';
		}
		$entry = self::index()[ $id ] ?? null;
		$ext   = is_array( $entry ) && 'sql' === ( $entry['ext'] ?? '' ) ? 'sql' : 'sql.gz';
		return LucidLab_DBC_Backup::dir() . '/' . $id . '.' . $ext;
	}

	/**
	 * Index, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function index(): array {
		wp_cache_delete( self::INDEX_OPTION, 'options' );
		$index = get_option( self::INDEX_OPTION, array() );
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
	 * Save the index.
	 *
	 * @param array<string,array<string,mixed>> $index Index.
	 */
	public static function save_index( array $index ): void {
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Change one index entry.
	 *
	 * @param string              $id      Id.
	 * @param array<string,mixed> $changes Changes.
	 */
	private static function update_entry( string $id, array $changes ): void {
		$index        = self::index();
		$index[ $id ] = array_merge( (array) ( $index[ $id ] ?? array() ), $changes );
		self::save_index( $index );
	}

	/**
	 * Backups and uploads whose file exists, with the current file size.
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
			clearstatcache( true, $path );
			$out[ (string) $id ] = array_merge( $entry, array( 'size' => (int) filesize( $path ) ) );
		}
		return $out;
	}

	/**
	 * Tables and views of the database (temporary restore tables excluded).
	 *
	 * @param string $scope all|prefix.
	 *
	 * @return array<int,array{name:string,view:bool,rows:int,bytes:int}>
	 */
	public static function tables( string $scope ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
		$out  = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];
			if ( self::is_temp_table( $name ) ) {
				continue;
			}
			if ( 'prefix' === $scope && ! str_starts_with( $name, (string) $wpdb->prefix ) ) {
				continue;
			}
			$out[] = array(
				'name'  => $name,
				'view'  => 'VIEW' === strtoupper( (string) $row['TABLE_TYPE'] ),
				'rows'  => (int) $row['TABLE_ROWS'],
				'bytes' => (int) $row['DATA_LENGTH'],
			);
		}
		// Tables first, views last (a view needs its tables).
		usort(
			$out,
			static function ( $a, $b ) {
				return array( (int) $a['view'], $a['name'] ) <=> array( (int) $b['view'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Whether a table is a temporary table of a restore.
	 *
	 * @param string $name Table.
	 *
	 * @return bool
	 */
	public static function is_temp_table( string $name ): bool {
		return str_starts_with( $name, self::TEMP_PREFIX ) || str_starts_with( $name, self::OLD_PREFIX );
	}

	/**
	 * Counts and sizes for the scope chooser.
	 *
	 * @return array{all:array{tables:int,bytes:int},prefix:array{tables:int,bytes:int}}
	 */
	public static function scope_totals(): array {
		$out = array();
		foreach ( array( 'all', 'prefix' ) as $scope ) {
			$tables        = self::tables( $scope );
			$out[ $scope ] = array(
				'tables' => count( $tables ),
				'bytes'  => (int) array_sum( wp_list_pluck( $tables, 'bytes' ) ),
			);
		}
		return $out;
	}

	/**
	 * Current job.
	 *
	 * @return array<string,mixed>
	 */
	public static function job(): array {
		wp_cache_delete( self::JOB_OPTION, 'options' );
		$job = get_option( self::JOB_OPTION, array() );
		return is_array( $job ) ? $job : array();
	}

	/**
	 * Whether a backup is being written (and not stale).
	 *
	 * @return bool
	 */
	public static function running(): bool {
		$job = self::job();
		return 'running' === ( $job['status'] ?? '' ) && ( time() - (int) ( $job['touched'] ?? 0 ) ) < self::STALE;
	}

	/**
	 * Save the job.
	 *
	 * @param array<string,mixed> $job Job.
	 *
	 * @return bool Whether the stored job now matches.
	 */
	private static function save_job( array $job ): bool {
		$job['touched'] = time();
		if ( update_option( self::JOB_OPTION, $job, false ) ) {
			return true;
		}
		return get_option( self::JOB_OPTION ) === $job; // False also means "unchanged".
	}

	/**
	 * Start a full backup.
	 *
	 * @param string            $scope   all|prefix.
	 * @param string            $trigger manual|safety|scheduled|convert|replace|index.
	 * @param array<int,string> $protect Backup ids retention must keep (for example the backup about to be restored).
	 * @param array<int,string> $only    Back up only these tables (scope "tables"), for example before a table is converted.
	 *
	 * @return array<string,mixed>|WP_Error Progress.
	 */
	public static function start( string $scope, string $trigger, array $protect = array(), array $only = array() ) {
		global $wpdb;
		if ( LucidLab_DBC_Restore::active() ) {
			return new WP_Error( 'lucidlab_dbc_full_busy', __( 'A restore is in progress. Finish or cancel it first.', 'lucidlab-db-cleanup' ) );
		}
		$job = self::job();
		if ( 'running' === ( $job['status'] ?? '' ) ) {
			if ( ( time() - (int) ( $job['touched'] ?? 0 ) ) < self::STALE ) {
				return new WP_Error( 'lucidlab_dbc_full_busy', __( 'A full backup is already being written. Wait until it has finished.', 'lucidlab-db-cleanup' ) );
			}
			self::discard();
		}
		if ( ! LucidLab_DBC_Backup::ensure_dir() ) {
			return new WP_Error( 'lucidlab_dbc_backup_dir', __( 'The backup folder in wp-content/uploads is not writable.', 'lucidlab-db-cleanup' ) );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error( 'lucidlab_dbc_zlib', __( 'The PHP zlib extension is missing, so a compressed backup cannot be written.', 'lucidlab-db-cleanup' ) );
		}
		$scope  = in_array( $scope, array( 'prefix', 'tables' ), true ) ? $scope : 'all';
		$tables = self::tables( 'tables' === $scope ? 'all' : $scope );
		if ( 'tables' === $scope ) {
			$only   = array_map( 'strval', $only );
			$tables = array_values(
				array_filter(
					$tables,
					static function ( $table ) use ( $only ) {
						return in_array( $table['name'], $only, true );
					}
				)
			);
		}
		if ( ! $tables ) {
			return new WP_Error( 'lucidlab_dbc_full_empty', __( 'No tables found.', 'lucidlab-db-cleanup' ) );
		}
		$id     = 'full-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 24, false, false ) );
		$path   = LucidLab_DBC_Backup::dir() . '/' . $id . '.sql.gz';
		$server = (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server version for the file header.
		$header = array(
			'-- Lucid Lab Database Cleaner full backup ' . LUCIDLAB_DBC_VERSION,
			'-- Site URL: ' . get_option( 'siteurl' ),
			'-- Home URL: ' . get_option( 'home' ),
			'-- Database: ' . DB_NAME,
			'-- Table prefix: ' . $wpdb->prefix,
			'-- Scope: ' . $scope,
			'-- Tables: ' . count( $tables ),
			'-- Server: ' . $server,
			'-- Created (UTC): ' . gmdate( 'Y-m-d H:i:s' ),
			'-- Restore it under Database Cleaner > Full backup, or import it with phpMyAdmin or the mysql client.',
			'',
			'SET NAMES utf8mb4;',
			"SET time_zone = '+00:00';",
			'SET FOREIGN_KEY_CHECKS = 0;',
			'SET UNIQUE_CHECKS = 0;',
			"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
			'',
		);
		if ( ! self::append( $path, implode( "\n", $header ) . "\n" ) ) {
			return new WP_Error( 'lucidlab_dbc_full_write', __( 'Could not write the backup file.', 'lucidlab-db-cleanup' ) );
		}
		$index        = self::index();
		$index[ $id ] = array(
			'type'     => 'full',
			'ext'      => 'sql.gz',
			'created'  => time(),
			'scope'    => $scope,
			'trigger'  => in_array( $trigger, array( 'manual', 'safety', 'scheduled', 'convert', 'replace', 'index' ), true ) ? $trigger : 'manual',
			'only'     => 'tables' === $scope ? array_slice( wp_list_pluck( $tables, 'name' ), 0, 50 ) : array(),
			'status'   => 'writing',
			'tables'   => 0,
			'views'    => 0,
			'rows'     => 0,
			'siteurl'  => (string) get_option( 'siteurl' ),
			'home'     => (string) get_option( 'home' ),
			'prefix'   => (string) $wpdb->prefix,
			'database' => DB_NAME,
			'version'  => LUCIDLAB_DBC_VERSION,
		);
		self::save_index( $index );
		$job = array(
			'id'      => $id,
			'status'  => 'running',
			'scope'   => $scope,
			'trigger' => $index[ $id ]['trigger'],
			'protect' => array_values( array_filter( array_map( 'strval', $protect ), array( __CLASS__, 'valid_id' ) ) ),
			'tables'  => $tables,
			'ti'      => 0,
			'stage'   => 'create',
			'last'    => null,
			'offset'  => 0,
			'trows'   => 0,
			'rows'    => 0,
			'views'   => 0,
			'started' => time(),
			'seconds' => 0.0,
			'errors'  => array(),
		);
		self::save_job( $job );
		return self::progress( $job );
	}

	/**
	 * Throw away the current job and its partial file.
	 */
	public static function discard(): void {
		$job = self::job();
		$id  = (string) ( $job['id'] ?? '' );
		if ( self::valid_id( $id ) ) {
			$entry = self::index()[ $id ] ?? array();
			if ( 'complete' !== ( $entry['status'] ?? '' ) ) {
				self::delete( $id );
			}
		}
		delete_option( self::JOB_OPTION );
	}

	/**
	 * Append to the gzip file (each call writes one gzip member).
	 *
	 * @param string $path Path.
	 * @param string $data Data.
	 *
	 * @return bool
	 */
	private static function append( string $path, string $data ): bool {
		$gz = gzopen( $path, 'ab6' );
		if ( ! $gz ) {
			return false;
		}
		$ok = false !== gzwrite( $gz, $data );
		return gzclose( $gz ) && $ok;
	}

	/**
	 * Name of the advisory lock that keeps two steps of the same job apart.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		global $wpdb;
		return 'lucidlab_dbc_full_' . md5( DB_NAME . '|' . $wpdb->prefix );
	}

	/**
	 * Use a utf8mb4 connection and UTC for the dump; returns the charset to switch back to.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function session_begin(): array {
		global $wpdb;
		$old = array( (string) $wpdb->charset, (string) $wpdb->collate );
		if ( $wpdb->has_cap( 'utf8mb4' ) ) {
			$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		}
		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session setting for the dump: TIMESTAMP values are written in UTC.
		return $old;
	}

	/**
	 * Switch the connection back.
	 *
	 * @param array{0:string,1:string} $old Charset and collation from session_begin().
	 */
	public static function session_end( array $old ): void {
		global $wpdb;
		if ( '' !== $old[0] ) {
			$wpdb->set_charset( $wpdb->dbh, $old[0], $old[1] );
		}
		$wpdb->query( "SET time_zone = 'SYSTEM'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores the default session time zone.
	}

	/**
	 * Continue the job for one time budget.
	 *
	 * @param float $budget Seconds (0 = default).
	 *
	 * @return array<string,mixed>|WP_Error Progress.
	 *
	 * @throws RuntimeException Caught here: when the file cannot be opened (turned into a WP_Error).
	 */
	public static function step( float $budget = 0.0 ) {
		global $wpdb;
		$job = self::job();
		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			if ( 'done' === ( $job['status'] ?? '' ) ) {
				return self::progress( $job );
			}
			return new WP_Error( 'lucidlab_dbc_full_nojob', __( 'No full backup is being written.', 'lucidlab-db-cleanup' ) );
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
			return array( 'busy' => true ) + self::progress( $job );
		}
		$budget = self::budget( $budget );
		$start  = microtime( true );
		$old    = self::session_begin();
		$path   = LucidLab_DBC_Backup::dir() . '/' . $job['id'] . '.sql.gz';
		$gz     = is_file( $path ) ? gzopen( $path, 'ab6' ) : false; // Never recreate a file that was deleted mid-job.
		try {
			if ( ! $gz ) {
				throw new RuntimeException( esc_html__( 'The backup file could not be opened. It may have been deleted.', 'lucidlab-db-cleanup' ) );
			}
			wp_raise_memory_limit( 'admin' );
			$tables = (array) $job['tables'];
			$count  = count( $tables );
			while ( ( microtime( true ) - $start ) < $budget && (int) $job['ti'] < $count ) {
				$table = (array) $tables[ (int) $job['ti'] ];
				$name  = (string) $table['name'];
				if ( 'create' === $job['stage'] ) {
					$sql = self::create_sql( $name, ! empty( $table['view'] ) );
					if ( '' === $sql ) {
						$job['errors'][] = sprintf(
							/* translators: %s: table name. */
							__( 'Table %s was skipped: it no longer exists or SHOW CREATE failed.', 'lucidlab-db-cleanup' ),
							$name
						);
						self::next_table( $job );
						continue;
					}
					gzwrite( $gz, $sql );
					if ( ! empty( $table['view'] ) ) {
						++$job['views'];
						self::next_table( $job );
						continue;
					}
					$job['stage'] = 'rows';
					$job['last']  = null;
					$job['trows'] = 0;
					continue;
				}
				$batch = self::dump_rows( $gz, $name, $job );
				if ( $batch < 0 ) {
					$job['errors'][] = sprintf(
						/* translators: 1: table name, 2: database error. */
						__( 'Rows of %1$s could not be read: %2$s', 'lucidlab-db-cleanup' ),
						$name,
						(string) $wpdb->last_error
					);
					self::next_table( $job );
					continue;
				}
				if ( 0 === $batch || 'end' === $job['stage'] ) {
					self::next_table( $job );
				}
			}
			$done = (int) $job['ti'] >= count( $tables );
			if ( $done ) {
				gzwrite( $gz, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- end of full backup\n" );
			}
			gzclose( $gz );
			$gz              = null;
			$job['seconds'] += microtime( true ) - $start;
			if ( $done ) {
				$job = self::complete( $job );
			} elseif ( ! self::save_job( $job ) ) {
				// Without the saved position the next step would write the same rows again.
				throw new RuntimeException( esc_html__( 'The backup position could not be saved in the database.', 'lucidlab-db-cleanup' ) );
			}
		} catch ( Throwable $e ) {
			if ( $gz ) {
				gzclose( $gz );
			}
			$job['status']   = 'failed';
			$job['errors'][] = $e->getMessage();
			self::save_job( $job );
			self::update_entry( (string) $job['id'], array( 'status' => 'failed' ) );
			return new WP_Error( 'lucidlab_dbc_full_failed', $e->getMessage() );
		} finally {
			self::session_end( $old );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		}
		return self::progress( $job );
	}

	/**
	 * Move to the next table.
	 *
	 * @param array<string,mixed> $job Job (by reference).
	 */
	private static function next_table( array &$job ): void {
		++$job['ti'];
		$job['stage']  = 'create';
		$job['last']   = null;
		$job['offset'] = 0;
		$job['trows']  = 0;
	}

	/**
	 * DROP + CREATE statements of a table or view.
	 *
	 * @param string $name Table.
	 * @param bool   $view Whether it is a view.
	 *
	 * @return string SQL ('' when the table is gone).
	 */
	private static function create_sql( string $name, bool $view ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Read-only schema export; the sniff mistakes SHOW CREATE for a schema change.
		if ( empty( $row[1] ) ) {
			return '';
		}
		$q = self::quote_ident( $name );
		if ( $view ) {
			// DEFINER needs the SUPER privilege on import; the view is created with the importing user.
			$create = (string) preg_replace( '/^CREATE\s+(ALGORITHM=\S+\s+)?DEFINER=\S+\s+(SQL\s+SECURITY\s+\w+\s+)?VIEW/i', 'CREATE $1$2VIEW', (string) $row[1] );
			return "\n-- View " . $name . "\nDROP VIEW IF EXISTS " . $q . ";\n" . $create . ";\n";
		}
		return "\n-- Table " . $name . "\nDROP TABLE IF EXISTS " . $q . ";\n" . (string) $row[1] . ";\n";
	}

	/**
	 * Column names, binary flags and the key to page by.
	 *
	 * @param string $table Table.
	 *
	 * @return array{columns:array<int,string>,binary:array<string,bool>,bit:array<string,bool>,key:string,key_binary:bool,order:array<int,string>}
	 */
	private static function layout( string $table ): array {
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		$columns = array();
		$binary  = array();
		$bit     = array();
		$primary = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup for the backup writer.
			$field = (string) $col['Field'];
			$extra = strtoupper( (string) ( $col['Extra'] ?? '' ) );
			if ( str_contains( $extra, 'GENERATED' ) || preg_match( '/\b(VIRTUAL|PERSISTENT|STORED)\b/', $extra ) ) {
				continue; // Generated columns are computed again on import.
			}
			$columns[]        = $field;
			$binary[ $field ] = (bool) preg_match( '/^(tiny|medium|long)?blob|^(var)?binary|geometry|point|linestring|polygon/i', (string) $col['Type'] );
			$bit[ $field ]    = (bool) preg_match( '/^bit\b/i', (string) $col['Type'] );
			if ( 'PRI' === ( $col['Key'] ?? '' ) ) {
				$primary[] = $field;
			}
		}
		$keys  = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup for the backup writer.
		$order = array();
		foreach ( $keys as $key ) {
			$order[ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
		}
		ksort( $order );
		$order = array_values( $order );
		$key   = 1 === count( $order ) && in_array( $order[0], $columns, true ) ? $order[0] : '';

		$cache[ $table ] = array(
			'columns'    => $columns,
			'binary'     => $binary,
			'bit'        => $bit,
			'key'        => $key,
			'key_binary' => '' !== $key && ! empty( $binary[ $key ] ),
			'order'      => $order ? $order : $primary,
		);
		return $cache[ $table ];
	}

	/**
	 * Write the next batch of rows of a table.
	 *
	 * @param resource            $gz    Open gzip file.
	 * @param string              $table Table.
	 * @param array<string,mixed> $job   Job (by reference).
	 *
	 * @return int Rows written (0 when there were none left), -1 on a read error. Sets the stage to
	 *             'end' when this was the last batch of the table.
	 */
	private static function dump_rows( $gz, string $table, array &$job ): int {
		global $wpdb;
		$layout = self::layout( $table );
		if ( ! $layout['columns'] ) {
			return 0;
		}
		$avg   = LucidLab_DBC_Helpers::avg_row_bytes( $table );
		$limit = (int) max( 10, min( self::BATCH_ROWS, floor( self::BATCH_BYTES / max( 1, $avg ) ) ) );
		$cols  = implode(
			', ',
			array_map(
				static function ( $column ) use ( $layout ) {
					// BIT values are read as numbers: the driver returns them as decimal text or raw bytes.
					return empty( $layout['bit'][ $column ] ) ? '%i' : 'CAST(%i AS UNSIGNED)';
				},
				$layout['columns']
			)
		);
		$args  = $layout['columns'];
		if ( '' !== $layout['key'] ) {
			// Keyset paging on the single-column primary key: every batch is an index range scan.
			$args[] = $table;
			if ( null === $job['last'] ) {
				$sql    = 'SELECT ' . $cols . ' FROM %i ORDER BY %i LIMIT %d';
				$args[] = $layout['key'];
			} else {
				$sql    = 'SELECT ' . $cols . ' FROM %i WHERE %i > ' . ( $layout['key_binary'] ? 'UNHEX(%s)' : '%s' ) . ' ORDER BY %i LIMIT %d';
				$args[] = $layout['key'];
				$args[] = (string) $job['last']; // Hex for binary keys (see below), so the job option stays valid text.
				$args[] = $layout['key'];
			}
			$args[] = $limit;
		} else {
			$order  = $layout['order'] ? ' ORDER BY ' . implode( ', ', array_fill( 0, count( $layout['order'] ), '%i' ) ) : '';
			$sql    = 'SELECT ' . $cols . ' FROM %i' . $order . ' LIMIT %d, %d';
			$args[] = $table;
			$args   = array_merge( $args, $layout['order'] );
			$args[] = (int) $job['offset'];
			$args[] = $limit;
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Prepared: the query is built from %i/%s/%d placeholders only, one per column.
		if ( null === $rows || '' !== $wpdb->last_error ) {
			return -1;
		}
		if ( ! $rows ) {
			return 0;
		}
		$binary = array();
		$bits   = array();
		foreach ( $layout['columns'] as $i => $column ) {
			$binary[ $i ] = ! empty( $layout['binary'][ $column ] );
			$bits[ $i ]   = ! empty( $layout['bit'][ $column ] );
		}
		$head  = 'INSERT INTO ' . self::quote_ident( $table ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'quote_ident' ), $layout['columns'] ) ) . ') VALUES ';
		$chunk = array();
		$size  = 0;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $i => $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( $bits[ $i ] && ctype_digit( (string) $value ) ) {
					$values[] = (string) $value;
				} elseif ( $binary[ $i ] || $bits[ $i ] ) {
					$values[] = '' === (string) $value ? "''" : '0x' . bin2hex( (string) $value );
				} else {
					$values[] = "'" . self::escape( (string) $value ) . "'";
				}
			}
			$tuple   = '(' . implode( ',', $values ) . ')';
			$chunk[] = $tuple;
			$size   += strlen( $tuple );
			if ( $size >= self::STATEMENT_BYTES ) {
				gzwrite( $gz, $head . implode( ',', $chunk ) . ";\n" );
				$chunk = array();
				$size  = 0;
			}
		}
		if ( $chunk ) {
			gzwrite( $gz, $head . implode( ',', $chunk ) . ";\n" );
		}
		$count = count( $rows );
		if ( '' !== $layout['key'] ) {
			$pos         = array_search( $layout['key'], $layout['columns'], true );
			$last        = (string) $rows[ $count - 1 ][ (int) $pos ];
			$job['last'] = $layout['key_binary'] ? bin2hex( $last ) : $last;
		} else {
			$job['offset'] = (int) $job['offset'] + $count;
		}
		$job['trows'] = (int) $job['trows'] + $count;
		$job['rows']  = (int) $job['rows'] + $count;
		if ( $count < $limit ) {
			$job['stage'] = 'end'; // A short batch is the last one of the table.
		}
		$wpdb->flush();
		return $count;
	}

	/**
	 * Finish the backup: sign it, update the index, apply retention.
	 *
	 * @param array<string,mixed> $job Job.
	 *
	 * @return array<string,mixed> Job.
	 */
	private static function complete( array $job ): array {
		$id   = (string) $job['id'];
		$path = self::path( $id );
		clearstatcache( true, $path );
		$job['status']   = 'done';
		$job['finished'] = time();
		self::update_entry(
			$id,
			array(
				'status'    => 'complete',
				'tables'    => count( (array) $job['tables'] ) - (int) ( $job['views'] ?? 0 ),
				'views'     => (int) ( $job['views'] ?? 0 ),
				'rows'      => (int) $job['rows'],
				'seconds'   => round( (float) $job['seconds'], 1 ),
				'finished'  => time(),
				'signature' => self::signature( $path ),
				'errors'    => array_slice( (array) $job['errors'], 0, 20 ),
			)
		);
		/**
		 * Filters how many full backups are kept (uploads are never counted). The Pro add-on makes it a setting.
		 *
		 * @param int $keep Default 3; 0 keeps all.
		 */
		$keep           = max( 0, (int) apply_filters( 'lucidlab_dbc_full_keep', 3 ) );
		$job['pruned']  = self::prune( $keep, array_merge( array( $id ), (array) $job['protect'] ) );
		$job['summary'] = self::summary( $id );
		self::save_job( $job );
		return $job;
	}

	/**
	 * Short description of a finished backup (for the e-mail report and the scheduled run state).
	 *
	 * @param string $id Id.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary( string $id ): array {
		$entry = self::all()[ $id ] ?? array();
		return array(
			'id'      => $id,
			'file'    => $id . '.sql.gz',
			'size'    => (int) ( $entry['size'] ?? 0 ),
			'tables'  => (int) ( $entry['tables'] ?? 0 ),
			'rows'    => (int) ( $entry['rows'] ?? 0 ),
			'seconds' => (float) ( $entry['seconds'] ?? 0 ),
			'scope'   => (string) ( $entry['scope'] ?? 'all' ),
		);
	}

	/**
	 * HMAC of a file with this site's secret keys, so a changed or foreign file is never restored
	 * from the list (uploads are restored explicitly instead).
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function signature( string $path ): string {
		return ( '' !== $path && is_readable( $path ) ) ? (string) hash_hmac_file( 'sha256', $path, wp_salt( 'auth' ) . '|lucidlab-dbc-full' ) : '';
	}

	/**
	 * Whether a full backup is complete and unchanged since it was written.
	 *
	 * @param string $id Id.
	 *
	 * @return bool
	 */
	public static function verify( string $id ): bool {
		$entry = self::index()[ $id ] ?? array();
		$known = (string) ( $entry['signature'] ?? '' );
		return 'complete' === ( $entry['status'] ?? '' ) && '' !== $known && hash_equals( $known, self::signature( self::path( $id ) ) );
	}

	/**
	 * Progress for the screen.
	 *
	 * @param array<string,mixed> $job Job.
	 *
	 * @return array<string,mixed>
	 */
	public static function progress( array $job ): array {
		$tables = (array) ( $job['tables'] ?? array() );
		$total  = 0;
		$done   = 0;
		foreach ( $tables as $i => $table ) {
			$weight = max( 1, (int) $table['bytes'] );
			$total += $weight;
			if ( $i < (int) $job['ti'] ) {
				$done += $weight;
			} elseif ( $i === (int) $job['ti'] && 'rows' === ( $job['stage'] ?? '' ) ) {
				$done += $weight * min( 0.99, (int) $job['trows'] / max( 1, (int) $table['rows'] ) );
			}
		}
		$current = $tables[ (int) ( $job['ti'] ?? 0 ) ] ?? array();
		$path    = self::valid_id( (string) ( $job['id'] ?? '' ) ) ? LucidLab_DBC_Backup::dir() . '/' . $job['id'] . '.sql.gz' : '';
		if ( '' !== $path ) {
			clearstatcache( true, $path );
		}
		return array(
			'id'      => (string) ( $job['id'] ?? '' ),
			'done'    => 'done' === ( $job['status'] ?? '' ),
			'percent' => 'done' === ( $job['status'] ?? '' ) ? 100 : round( $total ? $done / $total * 100 : 0, 1 ),
			'table'   => (string) ( $current['name'] ?? '' ),
			'index'   => min( (int) ( $job['ti'] ?? 0 ) + 1, count( $tables ) ),
			'tables'  => count( $tables ),
			'rows'    => (int) ( $job['rows'] ?? 0 ),
			'trows'   => (int) ( $job['trows'] ?? 0 ),
			'size'    => '' !== $path && file_exists( $path ) ? (int) filesize( $path ) : 0,
			'seconds' => round( (float) ( $job['seconds'] ?? 0 ), 1 ),
			'errors'  => array_slice( (array) ( $job['errors'] ?? array() ), 0, 10 ),
			'summary' => (array) ( $job['summary'] ?? array() ),
		);
	}

	/**
	 * Retention: keep the newest $keep full backups (uploads are not counted and never pruned).
	 *
	 * @param int               $keep   Number to keep (0 = no limit).
	 * @param array<int,string> $except Ids that are always kept.
	 *
	 * @return array<int,string> Deleted ids.
	 */
	public static function prune( int $keep, array $except = array() ): array {
		$deleted = array();
		if ( $keep <= 0 ) {
			return $deleted;
		}
		$seen = 0;
		foreach ( self::index() as $id => $entry ) {
			$id = (string) $id;
			if ( 'full' !== ( $entry['type'] ?? '' ) || 'writing' === ( $entry['status'] ?? '' ) ) {
				continue;
			}
			++$seen;
			if ( in_array( $id, $except, true ) ) {
				continue;
			}
			if ( $seen > $keep && self::delete( $id ) ) {
				$deleted[] = $id;
			}
		}
		return $deleted;
	}

	/**
	 * Delete a backup file and its index entry.
	 *
	 * @param string $id Id.
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
		self::save_index( $index );
		return ! file_exists( $path );
	}

	/**
	 * Remove upload parts that were never finished (older than a day).
	 */
	public static function clean_parts(): void {
		$dir = LucidLab_DBC_Backup::dir();
		foreach ( (array) glob( $dir . '/*.part' ) as $file ) {
			if ( is_string( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
