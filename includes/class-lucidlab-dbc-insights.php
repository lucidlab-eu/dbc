<?php
/**
 * Read-only reports: autoloaded options and table engines/charsets. Keep-last-N revisions cleanup.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoload checker, engine and charset report, and the "keep the last N revisions" selection.
 */
final class LucidLab_DBC_Insights {

	/**
	 * Site Health warns above this autoload size.
	 */
	public const AUTOLOAD_WARN = 800 * KB_IN_BYTES;

	/**
	 * Most revisions removed by one "keep the last N" run.
	 */
	public const REVISIONS_MAX = 20000;

	/**
	 * Autoload values that WordPress loads on every request.
	 *
	 * @return array<int,string>
	 */
	public static function autoload_values(): array {
		return function_exists( 'wp_autoload_values_to_autoload' ) ? (array) wp_autoload_values_to_autoload() : array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Largest autoloaded options with size and owner, and the totals.
	 *
	 * @param int $limit Number of options.
	 *
	 * @return array{total:int,count:int,warn:bool,rows:array<int,array<string,mixed>>}
	 */
	public static function autoload( int $limit = 50 ): array {
		global $wpdb;
		$in    = LucidLab_DBC_Helpers::in_strings( self::autoload_values() );
		$sum   = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS total FROM {$wpdb->options} WHERE autoload IN ({$in})", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN list is prepared by in_strings().
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE autoload IN ({$in}) ORDER BY len DESC, option_name LIMIT %d", max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN list is prepared by in_strings().
		$out   = array();
		$total = (int) ( $sum['total'] ?? 0 );
		foreach ( $rows as $row ) {
			$name  = (string) $row['option_name'];
			$owner = LucidLab_DBC_Known::owner( 'options', $name );
			if ( ! $owner && ( str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) ) ) {
				$owner = LucidLab_DBC_Known::owner( 'transients', (string) preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name ) );
			}
			$out[] = array(
				'name'         => $name,
				'autoload'     => (string) $row['autoload'],
				'bytes'        => (int) $row['len'],
				'share'        => $total ? round( (int) $row['len'] / $total * 100, 1 ) : 0.0,
				'owner'        => $owner ? (string) $owner['name'] : '',
				'owner_label'  => $owner ? (string) $owner['status_label'] : LucidLab_DBC_Known::status_label( LucidLab_DBC_Known::STATUS_UNKNOWN ),
				'owner_status' => $owner ? (string) $owner['status'] : LucidLab_DBC_Known::STATUS_UNKNOWN,
			);
		}
		return array(
			'total' => $total,
			'count' => (int) ( $sum['cnt'] ?? 0 ),
			'warn'  => $total > self::AUTOLOAD_WARN,
			'rows'  => $out,
		);
	}

	/**
	 * Engine, collation, size and problems of every base table with this site's prefix.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function engines(): array {
		global $wpdb;
		$like    = $wpdb->esc_like( (string) $wpdb->prefix ) . '%';
		$tables  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema report; there is no core API for it.
		$columns = (array) $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, COUNT(*) AS cnt, GROUP_CONCAT(DISTINCT CHARACTER_SET_NAME) AS sets FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4' GROUP BY TABLE_NAME", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema report.
		$by_col  = array();
		foreach ( $columns as $col ) {
			$by_col[ (string) $col['TABLE_NAME'] ] = array( (int) $col['cnt'], (string) $col['sets'] );
		}
		$out = array();
		foreach ( $tables as $row ) {
			$name      = (string) $row['TABLE_NAME'];
			$engine    = (string) $row['ENGINE'];
			$collation = (string) $row['TABLE_COLLATION'];
			$issues    = array();
			if ( 'innodb' !== strtolower( $engine ) ) {
				$issues[] = 'engine';
			}
			if ( ! str_starts_with( $collation, 'utf8mb4_' ) || isset( $by_col[ $name ] ) ) {
				$issues[] = 'charset';
			}
			$out[] = array(
				'name'      => $name,
				'engine'    => $engine,
				'collation' => $collation,
				'rows'      => (int) $row['TABLE_ROWS'],
				'bytes'     => (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'],
				'columns'   => $by_col[ $name ][0] ?? 0,
				'sets'      => $by_col[ $name ][1] ?? '',
				'issues'    => $issues,
			);
		}
		return $out;
	}

	/**
	 * Revisions above the newest $keep per post (autosaves are never counted or removed).
	 *
	 * @param int $keep Revisions to keep per post.
	 *
	 * @return array{posts:int,revisions:int}
	 */
	public static function revisions_preview( int $keep ): array {
		global $wpdb;
		$keep = max( 0, $keep );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.post_parent, COUNT(*) AS cnt FROM {$wpdb->posts} AS r INNER JOIN {$wpdb->posts} AS p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s GROUP BY r.post_parent HAVING cnt > %d", '%' . $wpdb->esc_like( '-autosave-v1' ), $keep ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
		$sum  = 0;
		foreach ( $rows as $row ) {
			$sum += (int) $row['cnt'] - $keep;
		}
		return array(
			'posts'     => count( $rows ),
			'revisions' => $sum,
		);
	}

	/**
	 * Ids of the revisions above the newest $keep per post (at most REVISIONS_MAX).
	 *
	 * @param int $keep Revisions to keep per post.
	 *
	 * @return array<int,int>
	 */
	public static function revisions_ids( int $keep ): array {
		global $wpdb;
		$keep    = max( 0, $keep );
		$like    = '%' . $wpdb->esc_like( '-autosave-v1' );
		$parents = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.post_parent FROM {$wpdb->posts} AS r INNER JOIN {$wpdb->posts} AS p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s GROUP BY r.post_parent HAVING COUNT(*) > %d", $like, $keep ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only selection.
		$ids     = array();
		foreach ( $parents as $parent ) {
			$more = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND post_name NOT LIKE %s ORDER BY post_date DESC, ID DESC LIMIT %d, 100000", $parent, $like, $keep ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only selection.
			$ids  = array_merge( $ids, $more );
			if ( count( $ids ) >= self::REVISIONS_MAX ) {
				return array_slice( $ids, 0, self::REVISIONS_MAX );
			}
		}
		return $ids;
	}

	/**
	 * Start a backed-up cleanup of the revisions above the newest $keep per post.
	 *
	 * @param LucidLab_DBC_Scanner $scanner Scanner.
	 * @param int                  $keep    Revisions to keep per post.
	 *
	 * @return array<string,mixed>|WP_Error Cleanup progress.
	 */
	public static function start_revisions_keep( LucidLab_DBC_Scanner $scanner, int $keep ) {
		if ( LucidLab_DBC_Automation::running() ) {
			return new WP_Error( 'lucidlab_dbc_busy', __( 'A background cleanup is running. Wait until it has finished.', 'lucidlab-db-cleanup' ) );
		}
		$keep = min( 500, max( 0, $keep ) );
		$ids  = self::revisions_ids( $keep );
		if ( ! $ids ) {
			return new WP_Error( 'lucidlab_dbc_nothing', __( 'No post has more revisions than that.', 'lucidlab-db-cleanup' ) );
		}
		$id   = 'revisions:keep:' . $keep;
		$item = array(
			'id'         => $id,
			'category'   => 'revisions',
			/* translators: %s: number of revisions kept per post. */
			'label'      => sprintf( __( 'Revisions above the newest %s per post', 'lucidlab-db-cleanup' ), number_format_i18n( $keep ) ),
			'confidence' => LucidLab_DBC_Scanner::REVIEW,
			'deletable'  => true,
			'spec'       => array(
				'mode' => 'posts',
				'type' => 'revision',
				'ids'  => $ids,
			),
		);
		return ( new LucidLab_DBC_Cleaner( $scanner ) )->start( array( $id ), array( $id => $item ), false );
	}
}
