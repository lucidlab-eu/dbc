<?php
/**
 * Restore a complete database backup (own .sql.gz or an uploaded .sql / .sql.gz dump).
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step-based restore that never leaves the live site half-restored.
 *
 * 1. unpack: a .sql.gz file is unpacked to a temporary .sql file in the protected backup folder.
 * 2. import: statement by statement into temporary tables (lldbr_<hash>), so the live tables, the
 *    logged-in session and this restore keep working while it runs.
 * 3. check: the imported options table is compared with the current site URL and table prefix.
 * 4. swap: one atomic RENAME TABLE replaces the live tables, the old ones are dropped, foreign key
 *    names and views are restored and the plugin's own backup list and settings are kept.
 *
 * The job state lives in a file (not in wp_options, which is replaced by the restore).
 */
final class LucidLab_DBC_Restore {

	/**
	 * Prefix of temporary foreign key names.
	 */
	private const FK_PREFIX = 'lldbk_';

	/**
	 * Options of this plugin that keep their current value after a restore.
	 */
	private const KEEP_OPTIONS = array(
		'lucidlab_dbc_dir_key',
		'lucidlab_dbc_backups',
		'lucidlab_dbc_full_backups',
		'lucidlab_dbc_settings',
		'lucidlab_dbc_auto',
		'lucidlab_dbc_install_ping',
		'lucidlab_dbc_install_id',
		'lucidlab_dbc_site_ping_last',
	);

	/**
	 * Session variables a dump may set (others are ignored).
	 */
	private const SET_VARIABLES = array( 'foreign_key_checks', 'unique_checks', 'sql_mode', 'time_zone', 'sql_notes' );

	/**
	 * State file (outside the backups folder name, which is stored in an option).
	 *
	 * @return string
	 */
	public static function state_path(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['basedir'] ) . '/lucidlab-db-cleanup/restore-state.php';
	}

	/**
	 * Current state, or an empty array.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$path = self::state_path();
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private state file.
		$pos = strpos( $raw, "\n" );
		$out = false === $pos ? null : json_decode( substr( $raw, $pos + 1 ), true );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * Save the state (atomic replace). The file starts with an exit, so it is never served as data.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @throws RuntimeException When the file cannot be written.
	 */
	private static function save_state( array $state ): void {
		$state['touched'] = time();
		$path             = self::state_path();
		$tmp              = $path . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Private state file next to the backups, replaced atomically; WP_Filesystem may need credentials.
		if ( false === file_put_contents( $tmp, "<?php exit; ?>\n" . wp_json_encode( $state ), LOCK_EX ) || ! rename( $tmp, $path ) ) {
			throw new RuntimeException( esc_html__( 'The restore state file could not be written.', 'lucidlab-db-cleanup' ) );
		}
	}

	/**
	 * Whether a restore is in progress (started and not finished or cancelled).
	 *
	 * @return bool
	 */
	public static function active(): bool {
		$state = self::state();
		return ! empty( $state['phase'] ) && 'done' !== $state['phase'];
	}

	/**
	 * Temporary table name for a table of the backup.
	 *
	 * @param string $name Table.
	 *
	 * @return string
	 */
	public static function temp_name( string $name ): string {
		return LucidLab_DBC_Fullbackup::TEMP_PREFIX . substr( md5( $name ), 0, 20 );
	}

	/**
	 * Name a live table gets during the swap, before it is dropped.
	 *
	 * @param string $name Table.
	 *
	 * @return string
	 */
	private static function old_name( string $name ): string {
		return LucidLab_DBC_Fullbackup::OLD_PREFIX . substr( md5( $name ), 0, 20 );
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	private static function q( string $name ): string {
		return LucidLab_DBC_Fullbackup::quote_ident( $name );
	}

	/**
	 * Remove the quotes of an identifier.
	 *
	 * @param string $name Quoted or bare name.
	 *
	 * @return string
	 */
	private static function unquote( string $name ): string {
		if ( strlen( $name ) > 1 && '`' === $name[0] && '`' === substr( $name, -1 ) ) {
			return str_replace( '``', '`', substr( $name, 1, -1 ) );
		}
		return $name;
	}

	/**
	 * Run one SQL statement on the WordPress connection.
	 *
	 * Statements come from a backup file the administrator chose. They are run on the raw connection
	 * because wpdb::query() would re-check the charset of every multi-megabyte INSERT and refuse
	 * statements for tables it cannot inspect yet (the temporary tables).
	 *
	 * @param string $sql Statement.
	 *
	 * @return string '' on success, otherwise the database error.
	 */
	private static function run( string $sql ): string {
		global $wpdb;
		$dbh = $wpdb->dbh;
		if ( $dbh instanceof mysqli ) {
			try {
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Replays statements of a database backup; see the method description.
				$ok = mysqli_query( $dbh, $sql );
				if ( $ok instanceof mysqli_result ) {
					mysqli_free_result( $ok ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result -- Frees the result of the statement above.
				}
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error -- Error text of the statement above.
				return false === $ok ? (string) mysqli_error( $dbh ) : '';
			} catch ( mysqli_sql_exception $e ) {
				return $e->getMessage();
			}
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fallback for database drop-ins: replays a backup statement.
		$wpdb->suppress_errors( $suppress );
		return false === $ok ? (string) $wpdb->last_error : '';
	}

	/**
	 * Session settings for every request of the restore.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private static function session( array $state ): void {
		global $wpdb;
		if ( $wpdb->has_cap( 'utf8mb4' ) ) {
			$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		}
		self::run( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0, time_zone = '+00:00'" );
		foreach ( (array) ( $state['sets'] ?? array() ) as $set ) {
			self::run( (string) $set );
		}
		self::run( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
	}

	/**
	 * Live tables and views.
	 *
	 * @return array<string,string> Name => BASE TABLE|VIEW.
	 */
	private static function live_tables(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; must not be cached during a restore.
			$out[ (string) $row['TABLE_NAME'] ] = 'VIEW' === strtoupper( (string) $row['TABLE_TYPE'] ) ? 'VIEW' : 'BASE TABLE';
		}
		return $out;
	}

	/**
	 * Drop leftover temporary tables of an earlier restore.
	 *
	 * @return int Tables dropped.
	 */
	private static function drop_temp_tables(): int {
		$count = 0;
		self::run( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		foreach ( self::live_tables() as $name => $type ) {
			if ( LucidLab_DBC_Fullbackup::is_temp_table( $name ) && '' === self::run( ( 'VIEW' === $type ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ' ) . self::q( $name ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Start a restore.
	 *
	 * @param string              $id   Backup id (full backup or upload).
	 * @param array<string,mixed> $opts confirm (must be RESTORE), drop_extra, ack, safety.
	 *
	 * @return array<string,mixed>|WP_Error Progress.
	 */
	public static function start( string $id, array $opts ) {
		if ( 'RESTORE' !== (string) ( $opts['confirm'] ?? '' ) ) {
			return new WP_Error( 'lucidlab_dbc_restore_confirm', __( 'Type RESTORE to confirm.', 'lucidlab-db-cleanup' ) );
		}
		if ( self::active() ) {
			return new WP_Error( 'lucidlab_dbc_restore_busy', __( 'Another restore is not finished. Continue or cancel it first.', 'lucidlab-db-cleanup' ) );
		}
		if ( LucidLab_DBC_Fullbackup::running() || LucidLab_DBC_Automation::running() || LucidLab_DBC_Cleaner::has_unfinished() ) {
			return new WP_Error( 'lucidlab_dbc_restore_busy', __( 'A backup or cleanup is running. Wait until it has finished.', 'lucidlab-db-cleanup' ) );
		}
		$entry = LucidLab_DBC_Fullbackup::index()[ $id ] ?? null;
		$path  = LucidLab_DBC_Fullbackup::path( $id );
		if ( ! is_array( $entry ) || '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'lucidlab_dbc_restore_missing', __( 'Backup file not found.', 'lucidlab-db-cleanup' ) );
		}
		$type = 'upload' === ( $entry['type'] ?? '' ) ? 'upload' : 'full';
		if ( 'full' === $type && ! LucidLab_DBC_Fullbackup::verify( $id ) ) {
			return new WP_Error( 'lucidlab_dbc_restore_signature', __( 'This backup is incomplete or was changed after it was written, so it is not restored from the list. If you trust the file, download it and use “Upload a backup” instead.', 'lucidlab-db-cleanup' ) );
		}
		$gz   = 'sql' !== ( $entry['ext'] ?? 'sql.gz' );
		$dir  = LucidLab_DBC_Backup::dir();
		$temp = $dir . '/restore-' . strtolower( wp_generate_password( 16, false, false ) ) . '.sql';
		self::drop_temp_tables();
		$state = array(
			'id'          => $id,
			'type'        => $type,
			'label'       => 'upload' === $type ? (string) ( $entry['name'] ?? $id ) : $id . '.sql.gz',
			'source'      => $path,
			'gz'          => $gz,
			'sql'         => $gz ? $temp : $path,
			'packed'      => (int) filesize( $path ),
			'unpacked'    => 0,
			'phase'       => $gz ? 'unpack' : 'import',
			'offset'      => 0,
			'size'        => $gz ? 0 : (int) filesize( $path ),
			'delimiter'   => ';',
			'sets'        => array(),
			'tables'      => array(),
			'fks'         => array(),
			'views'       => array(),
			'statements'  => 0,
			'errors'      => array(),
			'error_count' => 0,
			'skipped'     => array(),
			'skip_count'  => 0,
			'drop_extra'  => 'full' === $type && ! empty( $opts['drop_extra'] ),
			'scope'       => (string) ( $entry['scope'] ?? 'all' ),
			'ack'         => ! empty( $opts['ack'] ),
			'safety'      => LucidLab_DBC_Fullbackup::valid_id( (string) ( $opts['safety'] ?? '' ) ) ? (string) $opts['safety'] : '',
			'started'     => time(),
			'seconds'     => 0.0,
			'warnings'    => array(),
		);
		try {
			self::save_state( $state );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'lucidlab_dbc_restore_state', $e->getMessage() );
		}
		return self::progress( $state );
	}

	/**
	 * Cancel: drop the imported temporary tables and remove the temporary files. The live tables
	 * are untouched until the final swap, so cancelling before it changes nothing.
	 *
	 * @return array<string,mixed>
	 */
	public static function cancel(): array {
		$state   = self::state();
		$dropped = self::drop_temp_tables();
		if ( ! empty( $state['gz'] ) && ! empty( $state['sql'] ) && file_exists( (string) $state['sql'] ) ) {
			wp_delete_file( (string) $state['sql'] );
		}
		wp_delete_file( self::state_path() );
		return array(
			'cancelled' => true,
			'dropped'   => $dropped,
		);
	}

	/**
	 * Name of the advisory lock.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		return 'lucidlab_dbc_restore_' . md5( DB_NAME );
	}

	/**
	 * Continue the restore for one time budget.
	 *
	 * @param bool $ack The administrator accepted the warnings of the check phase.
	 *
	 * @return array<string,mixed>|WP_Error Progress.
	 */
	public static function step( bool $ack = false ) {
		global $wpdb;
		$state = self::state();
		if ( empty( $state['phase'] ) ) {
			return new WP_Error( 'lucidlab_dbc_restore_none', __( 'No restore is in progress.', 'lucidlab-db-cleanup' ) );
		}
		if ( 'done' === $state['phase'] ) {
			return self::progress( $state );
		}
		if ( $ack ) {
			$state['ack'] = true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
			return array( 'busy' => true ) + self::progress( $state );
		}
		$start  = microtime( true );
		$budget = LucidLab_DBC_Fullbackup::budget();
		wp_raise_memory_limit( 'admin' );
		try {
			self::session( $state );
			if ( 'unpack' === $state['phase'] ) {
				self::unpack( $state, $start, $budget );
			}
			if ( 'import' === $state['phase'] ) {
				self::import( $state, $start, $budget );
			}
			if ( 'check' === $state['phase'] ) {
				self::check( $state );
			}
			$state['seconds'] = (float) $state['seconds'] + ( microtime( true ) - $start );
			if ( 'swap' === $state['phase'] ) {
				self::save_state( $state );
				self::swap( $state );
			} else {
				self::save_state( $state );
			}
		} catch ( Throwable $e ) {
			if ( end( $state['errors'] ) !== $e->getMessage() ) {
				$state['errors'][] = $e->getMessage();
			}
			try {
				self::save_state( $state );
			} catch ( Throwable $ignored ) {
				unset( $ignored );
			}
			return new WP_Error( 'lucidlab_dbc_restore_failed', $e->getMessage() );
		} finally {
			self::run( 'SET SESSION FOREIGN_KEY_CHECKS = 1' );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		}
		return self::progress( $state );
	}

	/**
	 * Phase 1: unpack the .sql.gz file.
	 *
	 * @param array<string,mixed> $state  State (by reference).
	 * @param float               $start  Start time.
	 * @param float               $budget Seconds.
	 *
	 * @throws RuntimeException On read or write errors.
	 */
	private static function unpack( array &$state, float $start, float $budget ): void {
		$gz = gzopen( (string) $state['source'], 'rb' );
		if ( ! $gz ) {
			throw new RuntimeException( esc_html__( 'The backup file could not be opened.', 'lucidlab-db-cleanup' ) );
		}
		if ( (int) $state['unpacked'] > 0 && 0 !== gzseek( $gz, (int) $state['unpacked'] ) ) {
			gzclose( $gz );
			throw new RuntimeException( esc_html__( 'The backup file could not be read.', 'lucidlab-db-cleanup' ) );
		}
		$out = fopen( (string) $state['sql'], (int) $state['unpacked'] > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Temporary file in the private backup folder.
		if ( ! $out ) {
			gzclose( $gz );
			throw new RuntimeException( esc_html__( 'The temporary restore file could not be written. Check the free disk space.', 'lucidlab-db-cleanup' ) );
		}
		$finished = false;
		while ( ( microtime( true ) - $start ) < $budget ) {
			$data = gzread( $gz, 1048576 );
			if ( false === $data ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen.
				gzclose( $gz );
				throw new RuntimeException( esc_html__( 'The backup file is damaged (gzip error).', 'lucidlab-db-cleanup' ) );
			}
			if ( '' === $data ) {
				$finished = true;
				break;
			}
			if ( false === fwrite( $out, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write.
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen.
				gzclose( $gz );
				throw new RuntimeException( esc_html__( 'The temporary restore file could not be written. Check the free disk space.', 'lucidlab-db-cleanup' ) );
			}
			$state['unpacked'] = (int) $state['unpacked'] + strlen( $data );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen.
		gzclose( $gz );
		if ( $finished ) {
			clearstatcache( true, (string) $state['sql'] );
			$state['size']  = (int) filesize( (string) $state['sql'] );
			$state['phase'] = 'import';
		}
	}

	/**
	 * Phase 2: import statements into temporary tables.
	 *
	 * @param array<string,mixed> $state  State (by reference).
	 * @param float               $start  Start time.
	 * @param float               $budget Seconds.
	 *
	 * @throws RuntimeException On read errors.
	 */
	private static function import( array &$state, float $start, float $budget ): void {
		$fh = fopen( (string) $state['sql'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the SQL file from a byte offset; WP_Filesystem cannot seek.
		if ( ! $fh ) {
			throw new RuntimeException( esc_html__( 'The SQL file could not be opened.', 'lucidlab-db-cleanup' ) );
		}
		$reader = new LucidLab_DBC_Sql_Reader( $fh, (int) $state['offset'], (string) $state['delimiter'] );
		try {
			while ( ( microtime( true ) - $start ) < $budget ) {
				$sql = $reader->next();
				if ( null === $sql ) {
					$state['phase'] = 'check';
					break;
				}
				self::execute( $sql, $state );
				$state['offset']    = $reader->offset();
				$state['delimiter'] = $reader->delimiter();
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen.
		}
	}

	/**
	 * Note a problem (the first 20 are kept).
	 *
	 * @param array<string,mixed> $state State (by reference).
	 * @param string              $text  Message.
	 */
	private static function error( array &$state, string $text ): void {
		$state['error_count'] = (int) $state['error_count'] + 1;
		if ( count( (array) $state['errors'] ) < 20 ) {
			$state['errors'][] = $text;
		}
	}

	/**
	 * Note a skipped statement (the first 10 are kept).
	 *
	 * @param array<string,mixed> $state State (by reference).
	 * @param string              $sql   Statement.
	 */
	private static function skip( array &$state, string $sql ): void {
		$state['skip_count'] = (int) $state['skip_count'] + 1;
		if ( count( (array) $state['skipped'] ) < 10 ) {
			$state['skipped'][] = substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 100 );
		}
	}

	/**
	 * Run one statement of the file against the temporary tables.
	 *
	 * @param string              $sql   Statement.
	 * @param array<string,mixed> $state State (by reference).
	 */
	private static function execute( string $sql, array &$state ): void {
		// Executable comments (/*!40101 … */) as whole statements: unwrap them.
		if ( str_starts_with( $sql, '/*!' ) && strlen( $sql ) < 65536 ) {
			$sql = trim( (string) preg_replace( array( '~/\*!\d*\s?~', '~\s*\*/~' ), array( '', ' ' ), $sql ) );
		}
		$head = substr( $sql, 0, 400 );
		if ( preg_match( '/^SET\s/i', $head ) ) {
			self::set( $sql, $state );
			return;
		}
		if ( preg_match( '/^(?:LOCK\s+TABLES|UNLOCK\s+TABLES|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT)\b/i', $head ) ) {
			return; // Not needed: every statement is committed on its own.
		}
		if ( preg_match( '/^(?:DROP\s+VIEW|CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW)\s/i', $head ) ) {
			$view = (string) preg_replace( '/\s+DEFINER\s*=\s*\S+/i', ' ', $sql, 1 );
			if ( strlen( (string) wp_json_encode( $state['views'] ) ) + strlen( $view ) > 2 * MB_IN_BYTES ) {
				self::error( $state, __( 'Too many view definitions; the remaining views were skipped.', 'lucidlab-db-cleanup' ) );
				return;
			}
			$state['views'][] = $view;
			return;
		}
		$patterns = array(
			'drop'     => '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?/i',
			'create'   => '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i',
			'insert'   => '/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?/i',
			'alter'    => '/^ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+/i',
			'truncate' => '/^TRUNCATE\s+(?:TABLE\s+)?/i',
		);
		foreach ( $patterns as $kind => $pattern ) {
			if ( ! preg_match( $pattern, $head, $m ) ) {
				continue;
			}
			$at = strlen( $m[0] );
			if ( ! preg_match( '/\G(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)(\s*\.)?/', $head, $n, 0, $at ) || ! empty( $n[2] ) ) {
				self::skip( $state, $sql ); // Names with a database qualifier are not restored.
				return;
			}
			$table = self::unquote( $n[1] );
			if ( LucidLab_DBC_Fullbackup::is_temp_table( $table ) ) {
				return;
			}
			$rest = substr( $sql, $at + strlen( $n[1] ) );
			if ( 'drop' === $kind ) {
				if ( preg_match( '/^\s*,/', $rest ) ) {
					self::skip( $state, $sql );
					return;
				}
				$new = 'DROP TABLE IF EXISTS ' . self::q( self::temp_name( $table ) );
				unset( $state['tables'][ $table ] ); // A later CREATE TABLE adds it again.
			} else {
				$new = $m[0] . self::q( self::temp_name( $table ) ) . $rest;
			}
			if ( 'create' === $kind || 'alter' === $kind ) {
				$new = self::rewrite_keys( $new, $table, $state );
			}
			if ( 'create' === $kind ) {
				$state['tables'][ $table ] = 1;
			}
			$error = self::run( $new );
			++$state['statements'];
			if ( '' !== $error ) {
				/* translators: 1: table name, 2: database error. */
				self::error( $state, sprintf( __( '%1$s: %2$s', 'lucidlab-db-cleanup' ), $table, $error ) );
				if ( 'create' === $kind ) {
					unset( $state['tables'][ $table ] );
				}
			}
			return;
		}
		self::skip( $state, $sql );
	}

	/**
	 * Point foreign keys at the temporary tables and give named foreign keys a temporary name (names
	 * are unique per database); the original names are put back after the swap.
	 *
	 * @param string              $sql   CREATE or ALTER statement.
	 * @param string              $table Original table name.
	 * @param array<string,mixed> $state State (by reference).
	 *
	 * @return string
	 */
	private static function rewrite_keys( string $sql, string $table, array &$state ): string {
		if ( false === stripos( $sql, 'REFERENCES' ) ) {
			return $sql;
		}
		$ident = '(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
		$sql   = (string) preg_replace_callback(
			'/CONSTRAINT\s+' . $ident . '\s+FOREIGN\s+KEY\s*(\([^)]*\))\s*REFERENCES\s+' . $ident . '\s*(\([^)]*\))((?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT))*)/i',
			static function ( $m ) use ( $table, &$state ) {
				$orig           = self::unquote( $m[1] );
				$tmp            = self::FK_PREFIX . substr( md5( $table . '|' . $orig ), 0, 20 );
				$tail           = ' FOREIGN KEY ' . $m[2] . ' REFERENCES ' . $m[3] . ' ' . $m[4] . $m[5];
				$state['fks'][] = array(
					'table' => $table,
					'tmp'   => $tmp,
					'def'   => 'CONSTRAINT ' . self::q( $orig ) . $tail,
				);
				return 'CONSTRAINT ' . self::q( $tmp ) . $tail;
			},
			$sql
		);
		return (string) preg_replace_callback(
			'/\bREFERENCES\s+' . $ident . '/i',
			static function ( $m ) {
				return 'REFERENCES ' . self::q( self::temp_name( self::unquote( $m[1] ) ) );
			},
			$sql
		);
	}

	/**
	 * SET statements: only session settings that matter for importing data are applied and
	 * remembered for the next steps; user variables and anything else are ignored.
	 *
	 * @param string              $sql   Statement.
	 * @param array<string,mixed> $state State (by reference).
	 */
	private static function set( string $sql, array &$state ): void {
		$body = trim( (string) preg_replace( '/^SET\s+/i', '', $sql ) );
		if ( preg_match( '/^NAMES\s+[\'"]?(\w+)[\'"]?(?:\s+COLLATE\s+[\'"]?(\w+)[\'"]?)?$/i', $body, $m ) ) {
			$set = 'SET NAMES ' . $m[1] . ( empty( $m[2] ) ? '' : ' COLLATE ' . $m[2] );
			if ( '' === self::run( $set ) ) {
				$state['sets']['names'] = $set;
			}
			return;
		}
		foreach ( preg_split( '/,(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $body ) as $assignment ) {
			if ( ! preg_match( '/^\s*(?:SESSION\s+|@@SESSION\.|@@)?([A-Za-z_]+)\s*=\s*(\'[^\'\\\\]*\'|"[^"\\\\]*"|[A-Za-z0-9_+\-:]+)\s*$/', (string) $assignment, $m ) ) {
				continue;
			}
			$var = strtolower( $m[1] );
			if ( ! in_array( $var, self::SET_VARIABLES, true ) ) {
				continue;
			}
			$set = 'SET SESSION ' . $var . ' = ' . $m[2];
			if ( '' === self::run( $set ) ) {
				$state['sets'][ $var ] = $set;
			}
		}
	}

	/**
	 * Phase 3: compare the imported data with this site before anything live is replaced.
	 *
	 * @param array<string,mixed> $state State (by reference).
	 */
	private static function check( array &$state ): void {
		global $wpdb;
		$warnings = array();
		$live     = self::live_tables();
		$options  = self::temp_name( $wpdb->prefix . 'options' );
		$imported = array_keys( (array) $state['tables'] );
		if ( ! $imported ) {
			$state['phase']    = 'failed';
			$state['errors'][] = __( 'The file contains no CREATE TABLE statements, so there is nothing to restore. Data-only dumps are not supported.', 'lucidlab-db-cleanup' );
			return;
		}
		$state['from'] = array();
		if ( ! isset( $live[ $options ] ) ) {
			/* translators: %s: table prefix. */
			$warnings[] = sprintf( __( 'The backup does not contain the table %soptions of this site. It may come from a site with another table prefix; its tables are restored under their own names and this site keeps using its current tables.', 'lucidlab-db-cleanup' ), $wpdb->prefix );
		} else {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s)', $options, 'siteurl', 'home' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the imported (temporary) options table.
			foreach ( $rows as $row ) {
				$state['from'][ (string) $row['option_name'] ] = (string) $row['option_value'];
			}
			foreach ( array( 'siteurl', 'home' ) as $key ) {
				$was = untrailingslashit( (string) ( $state['from'][ $key ] ?? '' ) );
				$now = untrailingslashit( (string) get_option( $key ) );
				if ( '' !== $was && strtolower( $was ) !== strtolower( $now ) ) {
					/* translators: 1: option name (siteurl or home), 2: URL in the backup, 3: current URL. */
					$warnings[] = sprintf( __( 'The %1$s in the backup is %2$s, but this site uses %3$s. After the restore the site will use the address from the backup, which can make wp-admin unreachable at this address.', 'lucidlab-db-cleanup' ), $key, $was, $now );
				}
			}
		}
		if ( (int) $state['error_count'] > 0 ) {
			/* translators: %s: number of failed statements. */
			$warnings[] = sprintf( _n( '%s statement failed during the import (see the errors). Tables whose CREATE TABLE failed are not replaced.', '%s statements failed during the import (see the errors). Tables whose CREATE TABLE failed are not replaced.', (int) $state['error_count'], 'lucidlab-db-cleanup' ), number_format_i18n( (int) $state['error_count'] ) );
		}
		$state['warnings'] = $warnings;
		if ( $warnings && empty( $state['ack'] ) ) {
			$state['needs_ack'] = true;
			return;
		}
		$state['needs_ack'] = false;
		$state['phase']     = 'swap';
	}

	/**
	 * Phase 4: replace the live tables in one RENAME TABLE, then tidy up.
	 *
	 * @param array<string,mixed> $state State (by reference).
	 *
	 * @throws RuntimeException When the swap fails (nothing was changed then).
	 */
	private static function swap( array &$state ): void {
		$live     = self::live_tables();
		$imported = array_keys( (array) $state['tables'] );
		$renames  = array();
		$old      = array();
		$replaced = 0;
		$added    = 0;
		foreach ( $imported as $table ) {
			$tmp = self::temp_name( $table );
			if ( ! isset( $live[ $tmp ] ) ) {
				/* translators: %s: table name. */
				self::error( $state, sprintf( __( 'Table %s was not imported and is left as it is.', 'lucidlab-db-cleanup' ), $table ) );
				continue;
			}
			if ( isset( $live[ $table ] ) ) {
				if ( 'VIEW' === $live[ $table ] ) {
					self::run( 'DROP VIEW IF EXISTS ' . self::q( $table ) );
					++$added;
				} else {
					$renames[] = self::q( $table ) . ' TO ' . self::q( self::old_name( $table ) );
					$old[]     = self::old_name( $table );
					++$replaced;
				}
			} else {
				++$added;
			}
			$renames[] = self::q( $tmp ) . ' TO ' . self::q( $table );
		}
		$removed = array();
		// A backup of a few tables (scope "tables") never removes other tables.
		if ( ! empty( $state['drop_extra'] ) && 'tables' !== (string) $state['scope'] ) {
			foreach ( LucidLab_DBC_Fullbackup::tables( (string) $state['scope'] ) as $table ) {
				if ( empty( $table['view'] ) && ! isset( $state['tables'][ $table['name'] ] ) ) {
					$renames[] = self::q( $table['name'] ) . ' TO ' . self::q( self::old_name( $table['name'] ) );
					$old[]     = self::old_name( $table['name'] );
					$removed[] = $table['name'];
				}
			}
		}
		if ( ! $renames ) {
			throw new RuntimeException( esc_html__( 'Nothing to restore: no table was imported.', 'lucidlab-db-cleanup' ) );
		}
		// Values that must survive the restore: the backup lists, the backup folder and the settings.
		$keep = array();
		/**
		 * Filters the options that keep their current value after a full restore (add-ons add their license and settings).
		 *
		 * @param array<int,string> $options Option names.
		 */
		$keep_options = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'lucidlab_dbc_restore_keep_options', self::KEEP_OPTIONS ) ) ) );
		foreach ( $keep_options as $option ) {
			wp_cache_delete( $option, 'options' );
			$keep[ $option ] = get_option( $option );
		}
		self::run( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		foreach ( $old as $name ) {
			self::run( 'DROP TABLE IF EXISTS ' . self::q( $name ) );
		}
		$error = self::run( 'RENAME TABLE ' . implode( ', ', $renames ) );
		if ( '' !== $error ) {
			$state['phase'] = 'failed';
			/* translators: %s: database error. */
			$state['errors'][] = sprintf( __( 'Swapping the tables failed, so the live database was not changed: %s', 'lucidlab-db-cleanup' ), $error );
			self::save_state( $state );
			throw new RuntimeException( esc_html( end( $state['errors'] ) ) );
		}
		// From here on the restored database is live.
		foreach ( array_chunk( $old, 50 ) as $chunk ) {
			$drop = self::run( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( array( __CLASS__, 'q' ), $chunk ) ) );
			if ( '' !== $drop ) {
				self::error( $state, $drop );
			}
		}
		foreach ( (array) $state['fks'] as $fk ) {
			$t = self::q( (string) $fk['table'] );
			$e = self::run( 'ALTER TABLE ' . $t . ' DROP FOREIGN KEY ' . self::q( (string) $fk['tmp'] ) );
			if ( '' === $e ) {
				$e = self::run( 'ALTER TABLE ' . $t . ' ADD ' . (string) $fk['def'] );
			}
			if ( '' !== $e ) {
				/* translators: 1: table name, 2: database error. */
				self::error( $state, sprintf( __( 'Foreign key on %1$s keeps a temporary name: %2$s', 'lucidlab-db-cleanup' ), (string) $fk['table'], $e ) );
			}
		}
		foreach ( (array) $state['views'] as $view ) {
			$e = self::run( (string) $view );
			if ( '' !== $e ) {
				/* translators: %s: database error. */
				self::error( $state, sprintf( __( 'View: %s', 'lucidlab-db-cleanup' ), $e ) );
			}
		}
		self::run( 'SET SESSION FOREIGN_KEY_CHECKS = 1' );

		wp_cache_flush();
		foreach ( $keep as $option => $value ) {
			if ( false === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
		delete_option( LucidLab_DBC_Fullbackup::JOB_OPTION );
		delete_option( LucidLab_DBC_Cleaner::STOP_OPTION );
		// Keep this plugin active, so the backup list stays reachable after restoring an older backup.
		$active = (array) get_option( 'active_plugins', array() );
		$self   = plugin_basename( LUCIDLAB_DBC_FILE );
		if ( ! in_array( $self, $active, true ) ) {
			$active[] = $self;
			sort( $active );
			update_option( 'active_plugins', $active );
		}
		$state['phase']    = 'done';
		$state['finished'] = time();
		$state['replaced'] = $replaced;
		$state['added']    = $added;
		$state['removed']  = $removed;
		update_option(
			LucidLab_DBC_Fullbackup::LAST_RESTORE_OPTION,
			array(
				'time'        => time(),
				'id'          => (string) $state['id'],
				'label'       => (string) $state['label'],
				'tables'      => count( $imported ),
				'replaced'    => $replaced,
				'added'       => $added,
				'removed'     => count( $removed ),
				'statements'  => (int) $state['statements'],
				'errors'      => array_slice( (array) $state['errors'], 0, 10 ),
				'error_count' => (int) $state['error_count'],
				'skipped'     => (int) $state['skip_count'],
				'seconds'     => round( (float) $state['seconds'], 1 ),
				'from'        => (array) ( $state['from'] ?? array() ),
				'safety'      => (string) $state['safety'],
			),
			false
		);
		wp_cache_flush();
		if ( ! empty( $state['gz'] ) && file_exists( (string) $state['sql'] ) ) {
			wp_delete_file( (string) $state['sql'] );
		}
		wp_delete_file( self::state_path() );
	}

	/**
	 * Progress for the screen.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return array<string,mixed>
	 */
	public static function progress( array $state ): array {
		$phase = (string) ( $state['phase'] ?? '' );
		switch ( $phase ) {
			case 'unpack':
				$pct = 15 * min( 0.99, (int) $state['unpacked'] / max( 1, 6 * (int) $state['packed'] ) );
				break;
			case 'import':
				$pct = ( ! empty( $state['gz'] ) ? 15 : 0 ) + ( ! empty( $state['gz'] ) ? 80 : 95 ) * ( (int) $state['offset'] / max( 1, (int) $state['size'] ) );
				break;
			case 'check':
			case 'swap':
				$pct = 96;
				break;
			default:
				$pct = 'done' === $phase ? 100 : 0;
		}
		return array(
			'phase'           => $phase,
			'done'            => 'done' === $phase,
			'failed'          => 'failed' === $phase,
			'percent'         => round( $pct, 1 ),
			'label'           => (string) ( $state['label'] ?? '' ),
			'unpacked'        => (int) ( $state['unpacked'] ?? 0 ),
			'offset'          => (int) ( $state['offset'] ?? 0 ),
			'size'            => (int) ( $state['size'] ?? 0 ),
			'tables'          => count( (array) ( $state['tables'] ?? array() ) ),
			'statements'      => (int) ( $state['statements'] ?? 0 ),
			'errors'          => array_slice( (array) ( $state['errors'] ?? array() ), 0, 10 ),
			'errorCount'      => (int) ( $state['error_count'] ?? 0 ),
			'skipped'         => (int) ( $state['skip_count'] ?? 0 ),
			'skippedExamples' => (array) ( $state['skipped'] ?? array() ),
			'needsAck'        => ! empty( $state['needs_ack'] ),
			'warnings'        => (array) ( $state['warnings'] ?? array() ),
			'replaced'        => (int) ( $state['replaced'] ?? 0 ),
			'added'           => (int) ( $state['added'] ?? 0 ),
			'removed'         => count( (array) ( $state['removed'] ?? array() ) ),
			'seconds'         => round( (float) ( $state['seconds'] ?? 0 ), 1 ),
		);
	}
}
