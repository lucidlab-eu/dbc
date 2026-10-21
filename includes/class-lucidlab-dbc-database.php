<?php
/**
 * Database size measurements, OPTIMIZE / ANALYZE TABLE and the cleanup log.
 *
 * @package LucidLab_DB_Cleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database statistics and table maintenance.
 */
final class LucidLab_DBC_Database {

	/**
	 * Option holding the cleanup log (finished runs, newest first).
	 */
	public const RUNS_OPTION = 'lucidlab_dbc_runs';

	/**
	 * Option holding the latest stand-alone measurement.
	 */
	public const MEASURE_OPTION = 'lucidlab_dbc_measure';

	/**
	 * Runs kept in the log.
	 */
	private const MAX_RUNS = 50;

	/**
	 * InnoDB tables with up to this many (estimated) rows are counted exactly.
	 */
	private const EXACT_LIMIT = 500000;

	/**
	 * Maximum (estimated) rows counted exactly per measurement; larger databases fall back to estimates.
	 */
	private const EXACT_TOTAL = 5000000;

	/**
	 * Engines that support OPTIMIZE TABLE in a useful way.
	 */
	private const OPTIMIZABLE = array( 'innodb', 'myisam', 'aria' );

	/**
	 * Base tables of the current database from information_schema.
	 *
	 * @param bool $site_only Only tables with this site's prefix.
	 *
	 * @return array<string,array{name:string,engine:string,rows:int,data:int,index:int,free:int}>
	 */
	public static function tables( bool $site_only = false ): array {
		global $wpdb;
		self::fresh_statistics();
		$sql = "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS est_rows, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, DATA_FREE AS data_free FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
		if ( $site_only ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Static SQL plus a prepared LIKE; table statistics must be live.
		} else {
			$rows = $wpdb->get_results( $sql . ' ORDER BY TABLE_NAME', ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Static SQL without input; table statistics must be live.
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$name         = (string) $row['name'];
			$out[ $name ] = array(
				'name'   => $name,
				'engine' => (string) $row['engine'],
				'rows'   => (int) $row['est_rows'],
				'data'   => (int) $row['data_length'],
				'index'  => (int) $row['index_length'],
				'free'   => (int) $row['data_free'],
			);
		}
		return $out;
	}

	/**
	 * One table's statistics.
	 *
	 * @param string $table Table.
	 *
	 * @return array{name:string,engine:string,rows:int,data:int,index:int,free:int}|null
	 */
	public static function table( string $table ): ?array {
		global $wpdb;
		self::fresh_statistics();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS est_rows, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, DATA_FREE AS data_free FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = %s", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live table statistics.
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'name'   => (string) $row['name'],
			'engine' => (string) $row['engine'],
			'rows'   => (int) $row['est_rows'],
			'data'   => (int) $row['data_length'],
			'index'  => (int) $row['index_length'],
			'free'   => (int) $row['data_free'],
		);
	}

	/**
	 * Ask MySQL 8 not to serve cached information_schema statistics (MariaDB always reads them live).
	 */
	private static function fresh_statistics(): void {
		global $wpdb;
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( null === $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'information_schema_stats_expiry'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server capability check.
			return;
		}
		$wpdb->query( 'SET SESSION information_schema_stats_expiry = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session setting for this request only.
	}

	/**
	 * Whether InnoDB keeps each table in its own file (free space is then per table).
	 *
	 * @return bool
	 */
	public static function file_per_table(): bool {
		global $wpdb;
		$row = $wpdb->get_row( "SHOW VARIABLES LIKE 'innodb_file_per_table'", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server setting.
		return ! is_array( $row ) || in_array( strtoupper( (string) $row[1] ), array( 'ON', '1' ), true );
	}

	/**
	 * Measure this site's tables and the whole database.
	 *
	 * Row counts are exact (COUNT(*)) for MyISAM, Aria and MEMORY tables and for InnoDB tables with up to
	 * EXACT_LIMIT estimated rows; larger InnoDB tables use the information_schema estimate and are counted
	 * in "estimated".
	 *
	 * @return array<string,mixed>
	 */
	public static function measure(): array {
		global $wpdb;
		$tables  = self::tables( false );
		$prefix  = (string) $wpdb->prefix;
		$budget  = self::EXACT_TOTAL;
		$per_fpt = self::file_per_table();
		$empty   = array(
			'tables'    => 0,
			'rows'      => 0,
			'estimated' => 0,
			'data'      => 0,
			'index'     => 0,
			'free'      => 0,
			'engines'   => array(),
		);
		$sum     = array(
			'site' => $empty,
			'all'  => $empty,
		);
		$shared  = array(
			'site' => 0,
			'all'  => 0,
		);
		// Small tables first so the exact-count budget covers as many tables as possible.
		uasort(
			$tables,
			static function ( $a, $b ) {
				return $a['rows'] <=> $b['rows'];
			}
		);
		foreach ( $tables as $name => $table ) {
			$engine = strtolower( $table['engine'] );
			$rows   = $table['rows'];
			$exact  = false;
			if ( in_array( $engine, array( 'myisam', 'aria', 'memory' ), true ) || ( $rows <= self::EXACT_LIMIT && $rows <= $budget ) ) {
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact row count for the database summary.
				if ( null !== $count ) {
					$rows   = (int) $count;
					$exact  = true;
					$budget = max( 0, $budget - $rows );
				}
			}
			$is_site = 0 === strpos( $name, $prefix );
			foreach ( $is_site ? array( 'site', 'all' ) : array( 'all' ) as $scope ) {
				$target = &$sum[ $scope ];
				++$target['tables'];
				$target['rows']  += $rows;
				$target['data']  += $table['data'];
				$target['index'] += $table['index'];
				if ( 'innodb' === $engine && ! $per_fpt ) {
					// Tables in the shared system tablespace all report the same free space: count it once.
					$shared[ $scope ] = max( $shared[ $scope ], $table['free'] );
				} else {
					$target['free'] += $table['free'];
				}
				if ( ! $exact ) {
					++$target['estimated'];
				}
				$key                       = '' !== $table['engine'] ? $table['engine'] : '?';
				$target['engines'][ $key ] = ( $target['engines'][ $key ] ?? 0 ) + 1;
				unset( $target );
			}
		}
		foreach ( array( 'site', 'all' ) as $scope ) {
			$sum[ $scope ]['free'] += $shared[ $scope ];
			ksort( $sum[ $scope ]['engines'] );
		}
		return array(
			'time'           => time(),
			'site'           => $sum['site'],
			'db'             => $sum['all'],
			'file_per_table' => $per_fpt,
			'server'         => (string) $wpdb->db_server_info(),
		);
	}

	/**
	 * ANALYZE TABLE (refreshes the statistics that information_schema reports).
	 *
	 * @param string $table Table.
	 *
	 * @return string '' on success, otherwise the error text.
	 */
	public static function analyze( string $table ): string {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'ANALYZE TABLE %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table maintenance requested by the user.
		foreach ( $rows as $row ) {
			if ( 'error' === strtolower( (string) ( $row['Msg_type'] ?? '' ) ) ) {
				return (string) $row['Msg_text'];
			}
		}
		return $rows ? '' : (string) $wpdb->last_error;
	}

	/**
	 * OPTIMIZE TABLE with engine-specific handling.
	 *
	 * InnoDB does not optimize in place: MySQL and MariaDB rebuild the table and analyze it ("recreate +
	 * analyze"), which returns unused space to the file system when the table has its own .ibd file.
	 * MyISAM and Aria defragment the data file and sort the indexes. Other engines are skipped.
	 *
	 * @param string $table Table.
	 *
	 * @return array<string,mixed> Result: table, engine, action (recreate|optimize|up_to_date|skipped|error), message, before, after, seconds.
	 */
	public static function optimize( string $table ): array {
		global $wpdb;
		$before = self::table( $table );
		$result = array(
			'table'   => $table,
			'engine'  => (string) ( $before['engine'] ?? '' ),
			'action'  => 'skipped',
			'message' => '',
			'before'  => $before ? $before['data'] + $before['index'] + $before['free'] : 0,
			'after'   => 0,
			'seconds' => 0.0,
		);
		if ( ! $before ) {
			$result['message'] = __( 'The table no longer exists.', 'lucidlab-db-cleanup' );
			return $result;
		}
		$engine = strtolower( $before['engine'] );
		if ( ! in_array( $engine, self::OPTIMIZABLE, true ) ) {
			/* translators: %s: storage engine. */
			$result['message'] = sprintf( __( 'The %s storage engine cannot be optimized; skipped.', 'lucidlab-db-cleanup' ), '' !== $before['engine'] ? $before['engine'] : '?' );
			$result['after']   = $result['before'];
			return $result;
		}
		$start             = microtime( true );
		$rows              = $wpdb->get_results( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table maintenance requested by the user.
		$result['seconds'] = round( microtime( true ) - $start, 2 );
		$texts             = array();
		$recreate          = false;
		$error             = '';
		$status            = '';
		foreach ( (array) $rows as $row ) {
			$type = strtolower( (string) ( $row['Msg_type'] ?? '' ) );
			$text = (string) ( $row['Msg_text'] ?? '' );
			if ( false !== stripos( $text, 'recreate' ) ) {
				$recreate = true;
				continue;
			}
			if ( 'error' === $type ) {
				$error = $text;
			} elseif ( 'status' === $type ) {
				$status = $text;
			} elseif ( '' !== $text ) {
				$texts[] = $text;
			}
		}
		if ( ! $rows ) {
			$error = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'The database did not answer.', 'lucidlab-db-cleanup' );
		}
		if ( '' !== $error ) {
			$result['action']  = 'error';
			$result['message'] = $error;
		} elseif ( $recreate ) {
			$result['action']  = 'recreate';
			$result['message'] = __( 'InnoDB: rebuilt the table and refreshed its statistics (recreate + analyze).', 'lucidlab-db-cleanup' );
		} elseif ( false !== stripos( $status, 'up to date' ) ) {
			$result['action']  = 'up_to_date';
			$result['message'] = __( 'Already optimized; nothing to do.', 'lucidlab-db-cleanup' );
		} else {
			$result['action'] = 'optimize';
			/* translators: %s: storage engine. */
			$result['message'] = sprintf( __( '%s: defragmented the data file and sorted the indexes.', 'lucidlab-db-cleanup' ), $before['engine'] );
		}
		if ( $texts ) {
			$result['message'] .= ' ' . implode( ' ', array_slice( $texts, 0, 3 ) );
		}
		$after           = self::table( $table );
		$result['after'] = $after ? $after['data'] + $after['index'] + $after['free'] : 0;
		return $result;
	}

	/**
	 * Store the latest stand-alone measurement.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public static function remember( array $snapshot ): void {
		update_option( self::MEASURE_OPTION, $snapshot, false );
	}

	/**
	 * Latest measurement (stand-alone or the "after" of the newest run).
	 *
	 * @return array<string,mixed>
	 */
	public static function latest(): array {
		$measure = get_option( self::MEASURE_OPTION );
		$measure = is_array( $measure ) ? $measure : array();
		foreach ( self::runs() as $run ) {
			if ( ! empty( $run['after']['time'] ) && (int) $run['after']['time'] > (int) ( $measure['time'] ?? 0 ) ) {
				$measure = (array) $run['after'];
			}
			break;
		}
		return $measure;
	}

	/**
	 * Cleanup log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function runs(): array {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? array_values( array_filter( $runs, 'is_array' ) ) : array();
	}

	/**
	 * Add a finished run to the log.
	 *
	 * @param array<string,mixed> $run Run.
	 */
	public static function log( array $run ): void {
		$runs = self::runs();
		array_unshift( $runs, $run );
		update_option( self::RUNS_OPTION, array_slice( $runs, 0, self::MAX_RUNS ), false );
	}

	/**
	 * Savings between two snapshots for one scope ('site' or 'db').
	 *
	 * @param array<string,mixed> $before Before.
	 * @param array<string,mixed> $after  After.
	 * @param string              $scope  Scope.
	 *
	 * @return array{size_before:int,size_after:int,saved:int,percent:float,disk_before:int,disk_after:int,disk_saved:int,disk_percent:float,tables:int,rows:int,estimated:bool}
	 */
	public static function savings( array $before, array $after, string $scope ): array {
		$b           = (array) ( $before[ $scope ] ?? array() );
		$a           = (array) ( $after[ $scope ] ?? array() );
		$size_before = (int) ( $b['data'] ?? 0 ) + (int) ( $b['index'] ?? 0 );
		$size_after  = (int) ( $a['data'] ?? 0 ) + (int) ( $a['index'] ?? 0 );
		$disk_before = $size_before + (int) ( $b['free'] ?? 0 );
		$disk_after  = $size_after + (int) ( $a['free'] ?? 0 );
		return array(
			'size_before'  => $size_before,
			'size_after'   => $size_after,
			'saved'        => $size_before - $size_after,
			'percent'      => $size_before > 0 ? round( ( $size_before - $size_after ) / $size_before * 100, 1 ) : 0.0,
			'disk_before'  => $disk_before,
			'disk_after'   => $disk_after,
			'disk_saved'   => $disk_before - $disk_after,
			'disk_percent' => $disk_before > 0 ? round( ( $disk_before - $disk_after ) / $disk_before * 100, 1 ) : 0.0,
			'tables'       => (int) ( $b['tables'] ?? 0 ) - (int) ( $a['tables'] ?? 0 ),
			'rows'         => (int) ( $b['rows'] ?? 0 ) - (int) ( $a['rows'] ?? 0 ),
			'estimated'    => ! empty( $b['estimated'] ) || ! empty( $a['estimated'] ),
		);
	}
}
