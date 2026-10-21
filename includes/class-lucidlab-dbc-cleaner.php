<?php
/**
 * Deletes selected findings in small batches, backing up every row first.
 *
 * @package LucidLab_DB_Cleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup job runner.
 */
final class LucidLab_DBC_Cleaner {

	/**
	 * Option holding the running job.
	 */
	public const JOB_OPTION = 'lucidlab_dbc_job';

	/**
	 * Set by "Stop" (scheduled runs): the running job skips the findings it has not started yet,
	 * then signs the backup, optimizes and measures as usual.
	 */
	public const STOP_OPTION = 'lucidlab_dbc_stop';

	/**
	 * Above this many deleted rows per job, one wp_cache_flush() replaces targeted invalidation.
	 */
	private const CACHE_OPS_LIMIT = 50000;

	/**
	 * Posts per batch.
	 */
	private const POST_BATCH = 100;

	/**
	 * Rows per batch.
	 */
	private const ROW_BATCH = 500;

	/**
	 * Rows per table-export chunk.
	 */
	private const EXPORT_BATCH = 1000;

	/**
	 * Seconds per AJAX step.
	 */
	private const BUDGET = 6.0;

	/**
	 * Scanner.
	 *
	 * @var LucidLab_DBC_Scanner
	 */
	private $scanner;

	/**
	 * Running job.
	 *
	 * @var array<string,mixed>
	 */
	private $job = array();

	/**
	 * Constructor.
	 *
	 * @param LucidLab_DBC_Scanner $scanner Scanner.
	 */
	public function __construct( LucidLab_DBC_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * The stored job, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stored_job(): ?array {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Whether a stored cleanup job is unfinished (it can be resumed with step()).
	 *
	 * @return bool
	 */
	public static function has_unfinished(): bool {
		$job = self::stored_job();
		return is_array( $job ) && empty( $job['done'] ) && ( ! empty( $job['items'] ) || ! empty( $job['stopped'] ) || 'optimize' === ( $job['type'] ?? '' ) );
	}

	/**
	 * Whether another job is still running.
	 *
	 * @return bool
	 */
	private function busy(): bool {
		global $wpdb;
		$current = get_option( self::JOB_OPTION );
		if ( is_array( $current ) && empty( $current['done'] ) && ( time() - (int) ( $current['touched'] ?? 0 ) ) < 120 ) {
			return true;
		}
		// A step that is still inside a long OPTIMIZE TABLE holds the lock.
		return '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock check.
	}

	/**
	 * Name of the advisory lock that serialises job steps for this site.
	 *
	 * @return string
	 */
	private function lock_name(): string {
		global $wpdb;
		return 'lucidlab_dbc_' . md5( DB_NAME . '|' . $wpdb->prefix );
	}

	/**
	 * A fresh job.
	 *
	 * @param string                         $type    cleanup|optimize.
	 * @param array<int,array<string,mixed>> $items   Items.
	 * @param array<int,string>              $skipped Skipped findings.
	 * @param string                         $backup  Backup id ('' for optimize-only runs).
	 *
	 * @return array<string,mixed>
	 */
	private function new_job( string $type, array $items, array $skipped, string $backup ): array {
		$job          = self::defaults();
		$job['queue'] = array_keys( LucidLab_DBC_Database::tables( true ) );
		return array_merge(
			$job,
			array(
				'type'    => $type,
				'backup'  => $backup,
				'items'   => $items,
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Default job fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return array(
			'type'      => 'cleanup',
			'backup'    => '',
			'items'     => array(),
			'index'     => 0,
			'rows'      => 0,
			'tables'    => 0,
			'recount'   => array(),
			'errors'    => array(),
			'skipped'   => array(),
			'started'   => time(),
			'touched'   => time(),
			'done'      => false,
			'phase'     => 'before_analyze',
			'queue'     => array(),
			'qi'        => 0,
			'affected'  => array(),
			'dropped'   => array(),
			'optimized' => array(),
			'running'   => '',
			'before'    => array(),
			'after'     => array(),
			'cache_ops' => 0,
			'flush'     => false,
			'trigger'   => 'manual',
		);
	}

	/**
	 * Start a job for finding ids taken from the stored scan result.
	 *
	 * @param array<int,string>                 $ids       Finding ids.
	 * @param array<string,array<string,mixed>> $findings Stored findings by id.
	 * @param bool                              $risky_ok  Whether the user confirmed risky findings.
	 * @param bool                              $safe_only Only accept findings marked "safe" that are not
	 *                                                     Lucid Lab or declared data ("Clean all safe items"
	 *                                                     and scheduled runs).
	 * @param string                            $trigger   manual|oneclick|scheduled.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function start( array $ids, array $findings, bool $risky_ok, bool $safe_only = false, string $trigger = 'manual' ) {
		if ( $this->busy() ) {
			return new WP_Error( 'lucidlab_dbc_busy', __( 'Another cleanup is still running. Wait a moment and try again.', 'lucidlab-db-cleanup' ) );
		}
		$items   = array();
		$skipped = array();
		foreach ( array_unique( $ids ) as $id ) {
			$item = $findings[ $id ] ?? null;
			if ( ! is_array( $item ) ) {
				$skipped[] = $id;
				continue;
			}
			if ( $safe_only && ! LucidLab_DBC_Automation::is_safe( $item ) ) {
				$skipped[] = $item['label'] . ': ' . __( 'not marked safe; skipped.', 'lucidlab-db-cleanup' );
				continue;
			}
			if ( LucidLab_DBC_Scanner::RISKY === $item['confidence'] && ! $risky_ok ) {
				$skipped[] = $item['label'] . ': ' . __( 'risky and not confirmed; skipped.', 'lucidlab-db-cleanup' );
				continue;
			}
			$checked = $this->scanner->revalidate( $item );
			if ( is_wp_error( $checked ) ) {
				$skipped[] = $item['label'] . ': ' . $checked->get_error_message();
				continue;
			}
			$items[] = array(
				'id'       => (string) $checked['id'],
				'label'    => (string) $checked['label'],
				'category' => (string) $checked['category'],
				'spec'     => (array) $checked['spec'],
				'cursor'   => array(),
				'rows'     => 0,
				'done'     => false,
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'lucidlab_dbc_nothing', __( 'Nothing to clean: the selected findings are no longer valid. Run the scan again.', 'lucidlab-db-cleanup' ) );
		}
		$backup = LucidLab_DBC_Backup::create( wp_list_pluck( $items, 'label' ) );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$this->job            = $this->new_job( 'cleanup', $items, $skipped, (string) $backup );
		$this->job['trigger'] = in_array( $trigger, array( 'manual', 'oneclick', 'scheduled' ), true ) ? $trigger : 'manual';
		$this->save();
		return $this->progress();
	}

	/**
	 * Start an "optimize all tables" run for this site's prefix (no rows are deleted, so no backup).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function start_optimize() {
		if ( $this->busy() ) {
			return new WP_Error( 'lucidlab_dbc_busy', __( 'Another cleanup is still running. Wait a moment and try again.', 'lucidlab-db-cleanup' ) );
		}
		$this->job = $this->new_job( 'optimize', array(), array(), '' );
		if ( ! $this->job['queue'] ) {
			return new WP_Error( 'lucidlab_dbc_notables', __( 'No tables with this site’s prefix were found.', 'lucidlab-db-cleanup' ) );
		}
		$this->job['affected'] = array_fill_keys( $this->job['queue'], true );
		$this->save();
		return $this->progress();
	}

	/**
	 * Run one step (several units of work within the time budget) of the current job.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function step() {
		global $wpdb;
		$job = get_option( self::JOB_OPTION );
		if ( ! is_array( $job ) || ( empty( $job['items'] ) && 'optimize' !== ( $job['type'] ?? '' ) && empty( $job['stopped'] ) ) ) {
			return new WP_Error( 'lucidlab_dbc_nojob', __( 'No cleanup is running.', 'lucidlab-db-cleanup' ) );
		}
		// Jobs saved by version 1.0 have no phase: they resume deleting.
		$this->job = array_merge( self::defaults(), array( 'phase' => 'delete' ), $job );
		if ( ! empty( $this->job['done'] ) ) {
			return $this->progress();
		}
		// One step at a time. A step whose HTTP request timed out keeps the lock until its query finishes.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
			return array( 'busy' => true ) + $this->progress();
		}
		try {
			wp_cache_delete( self::STOP_OPTION, 'options' );
			if ( get_option( self::STOP_OPTION ) && in_array( $this->job['phase'], array( 'before_analyze', 'before_measure', 'delete' ), true ) && empty( $this->job['stopped'] ) ) {
				$left = count( (array) $this->job['items'] ) - (int) $this->job['index'];
				if ( $left > 0 ) {
					/* translators: %d: number of findings that were not cleaned. */
					$this->job['skipped'][] = sprintf( _n( 'Stopped on request: %d finding was not cleaned.', 'Stopped on request: %d findings were not cleaned.', $left, 'lucidlab-db-cleanup' ), $left );
				}
				// Findings that were not started are dropped; the current one is not interrupted mid-batch.
				$this->job['items']   = array_slice( (array) $this->job['items'], 0, (int) $this->job['index'] + ( empty( $this->job['items'][ $this->job['index'] ]['cursor'] ) ? 0 : 1 ) );
				$this->job['stopped'] = true;
				delete_option( self::STOP_OPTION );
			}
			$start = microtime( true );
			while ( ( microtime( true ) - $start ) < self::BUDGET && empty( $this->job['done'] ) ) {
				$phase = (string) $this->job['phase'];
				$this->run_phase();
				$this->job['touched'] = time();
				// Deleting and optimizing are saved after every unit; ANALYZE progress only at phase changes and at the end
				// (re-running an ANALYZE is harmless, and fewer writes keep the options table from churning).
				if ( 'delete' === $phase || 'optimize' === $phase || $phase !== $this->job['phase'] ) {
					$this->save();
				}
			}
			$this->save();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		}
		return $this->progress();
	}

	/**
	 * One unit of work of the current phase.
	 *
	 * Phases: before_analyze > before_measure > delete (cleanup runs only) > optimize > after_analyze > after_measure > done.
	 */
	private function run_phase(): void {
		switch ( (string) $this->job['phase'] ) {
			case 'before_analyze':
			case 'after_analyze':
				$this->analyze_next();
				return;
			case 'before_measure':
				$this->job['before'] = LucidLab_DBC_Database::measure();
				if ( 'optimize' === $this->job['type'] ) {
					$this->enter_optimize();
				} else {
					$this->job['phase'] = 'delete';
				}
				return;
			case 'delete':
				$this->delete_next();
				return;
			case 'optimize':
				$this->optimize_next();
				return;
			case 'after_measure':
				$this->job['after'] = LucidLab_DBC_Database::measure();
				$this->complete();
				return;
			default:
				$this->complete();
		}
	}

	/**
	 * ANALYZE the next table of the queue.
	 */
	private function analyze_next(): void {
		$queue = (array) $this->job['queue'];
		$i     = (int) $this->job['qi'];
		if ( $i >= count( $queue ) ) {
			$this->job['phase'] = 'before_analyze' === $this->job['phase'] ? 'before_measure' : 'after_measure';
			$this->job['qi']    = 0;
			return;
		}
		$table = (string) $queue[ $i ];
		if ( LucidLab_DBC_Helpers::table_exists( $table ) ) {
			$error = LucidLab_DBC_Database::analyze( $table );
			if ( '' !== $error ) {
				$this->job['errors'][] = 'ANALYZE ' . $table . ': ' . $error;
			}
		}
		$this->job['qi'] = $i + 1;
	}

	/**
	 * Delete the next batch of the current item.
	 */
	private function delete_next(): void {
		$total = count( (array) $this->job['items'] );
		if ( $this->job['index'] >= $total ) {
			$this->finish_delete();
			$this->enter_optimize();
			return;
		}
		$i    = (int) $this->job['index'];
		$item = &$this->job['items'][ $i ];
		try {
			$finished = $this->run_batch( $item );
		} catch ( Throwable $e ) {
			$this->job['errors'][] = $item['label'] . ': ' . $e->getMessage();
			$finished              = true;
		}
		if ( $finished ) {
			$item['done'] = true;
			++$this->job['index'];
		}
		unset( $item );
	}

	/**
	 * Queue the tables to optimize: every table rows were deleted from (dropped tables excluded), smallest first.
	 */
	private function enter_optimize(): void {
		$stats  = LucidLab_DBC_Database::tables( true );
		$tables = array();
		foreach ( array_keys( (array) $this->job['affected'] ) as $table ) {
			$table = (string) $table;
			if ( isset( $stats[ $table ] ) && ! in_array( $table, (array) $this->job['dropped'], true ) ) {
				$tables[ $table ] = $stats[ $table ]['data'] + $stats[ $table ]['index'] + $stats[ $table ]['free'];
			}
		}
		asort( $tables );
		$this->job['phase'] = 'optimize';
		$this->job['queue'] = array_keys( $tables );
		$this->job['qi']    = 0;
	}

	/**
	 * OPTIMIZE the next table of the queue.
	 */
	private function optimize_next(): void {
		$queue = (array) $this->job['queue'];
		$i     = (int) $this->job['qi'];
		if ( $i >= count( $queue ) ) {
			$this->job['phase'] = 'after_analyze';
			$this->job['queue'] = array_keys( LucidLab_DBC_Database::tables( true ) );
			$this->job['qi']    = 0;
			return;
		}
		$table = (string) $queue[ $i ];
		if ( $table === $this->job['running'] ) {
			// The previous request ended (for example a proxy time-out) while this table was being optimized.
			// We hold the lock again, so the database has finished the statement in the meantime.
			$now                      = LucidLab_DBC_Database::table( $table );
			$this->job['optimized'][] = array(
				'table'   => $table,
				'engine'  => (string) ( $now['engine'] ?? '' ),
				'action'  => 'unknown',
				'message' => __( 'The request ended while the database was optimizing this table; the database finished the work in the background.', 'lucidlab-db-cleanup' ),
				'before'  => 0,
				'after'   => $now ? $now['data'] + $now['index'] + $now['free'] : 0,
				'seconds' => 0,
			);
		} else {
			$this->job['running'] = $table;
			$this->job['touched'] = time();
			$this->save();
			// OPTIMIZE TABLE cannot be split. Time spent waiting for MySQL does not count towards PHP's
			// max_execution_time on Linux; if a proxy ends the request, the next step picks up (see above).
			$result                   = LucidLab_DBC_Database::optimize( $table );
			$this->job['optimized'][] = $result;
			if ( 'error' === $result['action'] ) {
				$this->job['errors'][] = 'OPTIMIZE ' . $table . ': ' . $result['message'];
			}
		}
		$this->job['running'] = '';
		$this->job['qi']      = $i + 1;
	}

	/**
	 * Finish the delete phase: back up and drop cached term hierarchies, recount terms, sign the backup.
	 */
	private function finish_delete(): void {
		global $wpdb;
		if ( $this->job['recount'] ) {
			// Cached term hierarchies are rebuilt by WordPress on demand; back them up like everything else.
			$taxonomies = array( 'category', 'product_cat' );
			foreach ( $this->job['items'] as $item ) {
				if ( ! empty( $item['spec']['taxonomy'] ) ) {
					$taxonomies[] = (string) $item['spec']['taxonomy'];
				}
			}
			$names = array();
			foreach ( array_unique( $taxonomies ) as $taxonomy ) {
				$names[] = $taxonomy . '_children';
			}
			try {
				$this->purge( $wpdb->options, 'option_name IN (' . LucidLab_DBC_Helpers::in_strings( $names ) . ')' );
			} catch ( Throwable $e ) {
				$this->job['errors'][] = $e->getMessage();
			}
		}
		LucidLab_DBC_Backup::add_recount( (string) $this->job['backup'], (array) $this->job['recount'] );
		LucidLab_DBC_Backup::recount( (array) $this->job['recount'] );
		LucidLab_DBC_Backup::finish( (string) $this->job['backup'] );
		if ( ! empty( $this->job['flush'] ) ) {
			// Too many rows for targeted invalidation (see invalidate_cache()); one full flush instead.
			wp_cache_flush();
		}
	}

	/**
	 * Mark the job finished and write it to the cleanup log.
	 */
	private function complete(): void {
		$recreated = 0;
		foreach ( (array) $this->job['optimized'] as $result ) {
			if ( 'recreate' === ( $result['action'] ?? '' ) ) {
				++$recreated;
			}
		}
		$this->job['done']     = true;
		$this->job['phase']    = 'done';
		$this->job['finished'] = time();
		$categories            = array();
		foreach ( (array) $this->job['items'] as $item ) {
			$key = (string) ( $item['category'] ?? '' );
			if ( ! isset( $categories[ $key ] ) ) {
				$categories[ $key ] = array(
					'findings' => 0,
					'rows'     => 0,
				);
			}
			++$categories[ $key ]['findings'];
			$categories[ $key ]['rows'] += (int) ( $item['rows'] ?? 0 );
		}
		$run = array(
			'id'         => '' !== $this->job['backup'] ? (string) $this->job['backup'] : 'optimize-' . gmdate( 'Ymd-His' ),
			'type'       => (string) $this->job['type'],
			'started'    => (int) $this->job['started'],
			'finished'   => (int) $this->job['finished'],
			'backup'     => (string) $this->job['backup'],
			'findings'   => count( (array) $this->job['items'] ),
			'labels'     => array_slice( wp_list_pluck( (array) $this->job['items'], 'label' ), 0, 30 ),
			'rows'       => (int) $this->job['rows'],
			'tables'     => (int) $this->job['tables'],
			'optimized'  => array_slice( (array) $this->job['optimized'], 0, 300 ),
			'recreated'  => $recreated,
			'errors'     => array_slice( (array) $this->job['errors'], 0, 30 ),
			'skipped'    => array_slice( (array) $this->job['skipped'], 0, 30 ),
			'before'     => (array) $this->job['before'],
			'after'      => (array) $this->job['after'],
			'trigger'    => (string) $this->job['trigger'],
			'stopped'    => ! empty( $this->job['stopped'] ),
			'categories' => $categories,
		);
		LucidLab_DBC_Database::log( $run );
		LucidLab_DBC_Automation::after_job( $run );
	}

	/**
	 * Save the job.
	 *
	 * @throws RuntimeException When the job option cannot be written.
	 */
	private function save(): void {
		if ( ! update_option( self::JOB_OPTION, $this->job, false ) && maybe_serialize( get_option( self::JOB_OPTION ) ) !== maybe_serialize( $this->job ) ) {
			throw new RuntimeException( esc_html__( 'The cleanup progress could not be saved; the job was stopped.', 'lucidlab-db-cleanup' ) );
		}
	}

	/**
	 * Progress for the browser.
	 *
	 * @return array<string,mixed>
	 */
	public function progress(): array {
		$total   = count( (array) $this->job['items'] );
		$index   = (int) $this->job['index'];
		$current = $this->job['items'][ min( $index, $total - 1 ) ] ?? array();
		$queue   = (array) ( $this->job['queue'] ?? array() );
		$qi      = (int) ( $this->job['qi'] ?? 0 );
		$out     = array(
			'done'        => ! empty( $this->job['done'] ),
			'type'        => (string) ( $this->job['type'] ?? 'cleanup' ),
			'phase'       => (string) ( $this->job['phase'] ?? 'delete' ),
			'index'       => $index,
			'total'       => $total,
			'current'     => (string) ( $current['label'] ?? '' ),
			'queue_index' => $qi,
			'queue_total' => count( $queue ),
			'table'       => (string) ( $queue[ min( $qi, count( $queue ) - 1 ) ] ?? '' ),
			'optimized'   => count( (array) ( $this->job['optimized'] ?? array() ) ),
			'rows'        => (int) $this->job['rows'],
			'tables'      => (int) $this->job['tables'],
			'errors'      => array_slice( (array) $this->job['errors'], 0, 20 ),
			'skipped'     => array_slice( (array) $this->job['skipped'], 0, 20 ),
			'backup'      => (string) $this->job['backup'],
			'categories'  => array_values( array_unique( wp_list_pluck( (array) $this->job['items'], 'category' ) ) ),
		);
		if ( ! empty( $this->job['done'] ) && ! empty( $this->job['before'] ) && ! empty( $this->job['after'] ) ) {
			$out['savings'] = LucidLab_DBC_Database::savings( (array) $this->job['before'], (array) $this->job['after'], 'site' );
		}
		return $out;
	}

	/**
	 * Process one batch of an item.
	 *
	 * @param array<string,mixed> $item Item (by reference).
	 *
	 * @return bool True when the item is finished.
	 */
	private function run_batch( array &$item ): bool {
		$spec = (array) $item['spec'];
		switch ( (string) ( $spec['mode'] ?? '' ) ) {
			case 'posts':
				return $this->batch_posts( $item );
			case 'terms':
				return $this->batch_terms( $item );
			case 'table':
				return $this->batch_table( $item );
			default:
				return $this->batch_rows( $item );
		}
	}

	/**
	 * Delete a batch of posts with everything attached to them.
	 *
	 * @param array<string,mixed> $item Item.
	 *
	 * @return bool
	 */
	private function batch_posts( array &$item ): bool {
		global $wpdb;
		$where = $this->scanner->post_where( (array) $item['spec'] );
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT t.ID FROM {$wpdb->posts} AS t WHERE {$where} ORDER BY t.ID LIMIT %d", self::POST_BATCH ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments by the scanner.
		if ( ! $ids ) {
			return true;
		}
		// Revisions and variations of these posts go with them (two levels: product > variation > revision).
		$all = $ids;
		for ( $level = 0; $level < 2; $level++ ) {
			$children = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('revision','product_variation') AND post_parent IN (" . LucidLab_DBC_Helpers::in_ints( $all ) . ')' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The IN list holds integers from in_ints().
			$all      = array_values( array_unique( array_merge( $all, $children ) ) );
		}
		$in       = LucidLab_DBC_Helpers::in_ints( $all );
		$comments = array_map( 'intval', (array) $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in})" ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN list holds integers from in_ints().
		if ( $comments ) {
			$cin = LucidLab_DBC_Helpers::in_ints( $comments );
			$this->purge( $wpdb->commentmeta, "comment_id IN ({$cin})" );
			$this->purge( $wpdb->comments, "comment_ID IN ({$cin})" );
		}
		$this->purge( $wpdb->postmeta, "post_id IN ({$in})" );
		$this->purge( $wpdb->term_relationships, "object_id IN ({$in})", true );
		foreach ( array(
			'wc_product_meta_lookup'       => 'product_id',
			'wc_product_attributes_lookup' => 'product_id',
		) as $suffix => $column ) {
			if ( LucidLab_DBC_Helpers::table_exists( $wpdb->prefix . $suffix ) ) {
				$this->purge( $wpdb->prefix . $suffix, "{$column} IN ({$in})" );
			}
		}
		$deleted       = $this->purge( $wpdb->posts, "ID IN ({$in})" );
		$item['rows'] += $deleted;
		if ( $deleted < 1 ) {
			$this->job['errors'][] = $item['label'] . ': ' . __( 'posts could not be deleted.', 'lucidlab-db-cleanup' );
			return true;
		}
		return count( $ids ) < self::POST_BATCH;
	}

	/**
	 * Delete a batch of terms (with links and term meta).
	 *
	 * @param array<string,mixed> $item Item.
	 *
	 * @return bool
	 */
	private function batch_terms( array &$item ): bool {
		global $wpdb;
		$where = $this->scanner->term_where( (array) $item['spec'] );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_taxonomy_id, t.term_id FROM {$wpdb->term_taxonomy} AS t WHERE {$where} ORDER BY t.term_taxonomy_id LIMIT %d", self::POST_BATCH ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by the scanner.
		if ( ! $rows ) {
			return true;
		}
		$tts   = array_map( 'intval', wp_list_pluck( $rows, 'term_taxonomy_id' ) );
		$terms = array_map( 'intval', wp_list_pluck( $rows, 'term_id' ) );
		$tin   = LucidLab_DBC_Helpers::in_ints( $tts );
		$this->purge( $wpdb->term_relationships, "term_taxonomy_id IN ({$tin})" );
		$deleted = $this->purge( $wpdb->term_taxonomy, "term_taxonomy_id IN ({$tin})" );
		// Only terms that no other taxonomy row still uses.
		$orphans = array_map( 'intval', (array) $wpdb->get_col( "SELECT n.term_id FROM {$wpdb->terms} AS n WHERE n.term_id IN (" . LucidLab_DBC_Helpers::in_ints( $terms ) . ") AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} AS x WHERE x.term_id = n.term_id)" ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The IN list holds integers from in_ints().
		if ( $orphans ) {
			$oin = LucidLab_DBC_Helpers::in_ints( $orphans );
			$this->purge( $wpdb->termmeta, "term_id IN ({$oin})" );
			$this->purge( $wpdb->terms, "term_id IN ({$oin})" );
		}
		$item['rows'] += $deleted;
		if ( $deleted < 1 ) {
			$this->job['errors'][] = $item['label'] . ': ' . __( 'terms could not be deleted.', 'lucidlab-db-cleanup' );
			return true;
		}
		return count( $rows ) < self::POST_BATCH;
	}

	/**
	 * Delete a batch of rows for a row-based finding (meta, options, comments, WooCommerce rows, links).
	 *
	 * @param array<string,mixed> $item Item.
	 *
	 * @return bool
	 */
	private function batch_rows( array &$item ): bool {
		global $wpdb;
		$target = $this->scanner->target( (array) $item['spec'] );
		if ( ! $target ) {
			$this->job['errors'][] = $item['label'] . ': ' . __( 'nothing to delete.', 'lucidlab-db-cleanup' );
			return true;
		}
		$table = $target['table'];
		$keys  = $this->key_columns( $table );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.* FROM %i AS t WHERE {$target['where']} LIMIT %d", $table, self::ROW_BATCH ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The WHERE clause is generated by the scanner from prepared fragments.
		if ( ! $rows ) {
			return true;
		}
		if ( '' !== $target['pk'] && $target['children'] ) {
			$pks = wp_list_pluck( $rows, $target['pk'] );
			foreach ( $target['children'] as $child ) {
				$this->purge( $child[0], LucidLab_DBC_Helpers::ident( $child[1] ) . ' IN (' . LucidLab_DBC_Helpers::in_strings( array_map( 'strval', $pks ) ) . ')', $child[0] === $wpdb->term_relationships );
			}
		}
		$deleted       = $this->delete_rows( $table, $keys, $rows, $table === $wpdb->term_relationships );
		$item['rows'] += $deleted;
		if ( $deleted < 1 ) {
			$this->job['errors'][] = $item['label'] . ': ' . __( 'rows could not be deleted.', 'lucidlab-db-cleanup' );
			return true;
		}
		return count( $rows ) < self::ROW_BATCH;
	}

	/**
	 * Export a whole table in chunks, then drop it.
	 *
	 * @param array<string,mixed> $item Item.
	 *
	 * @return bool
	 */
	private function batch_table( array &$item ): bool {
		global $wpdb;
		$table  = (string) $item['spec']['table'];
		$cursor = (array) $item['cursor'];
		$backup = (string) $this->job['backup'];
		if ( ! LucidLab_DBC_Helpers::table_exists( $table ) ) {
			return true;
		}
		if ( empty( $cursor['created'] ) ) {
			if ( ! LucidLab_DBC_Backup::add_create( $backup, $table ) ) {
				$this->job['errors'][] = $item['label'] . ': ' . __( 'could not back up the table structure; the table was kept.', 'lucidlab-db-cleanup' );
				return true;
			}
			$pk             = LucidLab_DBC_Helpers::primary_key( $table );
			$numeric        = '' !== $pk && $this->is_integer_column( $table, $pk );
			$cursor         = array(
				'created' => true,
				'pk'      => $pk,
				'numeric' => $numeric,
				'last'    => null,
				'offset'  => 0,
			);
			$item['cursor'] = $cursor;
		}
		$pk = (string) $cursor['pk'];
		// Keyset paging for integer keys; offset paging otherwise (the table is not modified until it is dropped).
		// Binary key values are never stored in the job: they would break saving the job option.
		if ( ! empty( $cursor['numeric'] ) ) {
			$rows = null === $cursor['last']
				? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d', $table, $pk, self::EXPORT_BATCH ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export of a table that is about to be dropped.
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i LIMIT %d', $table, $pk, (int) $cursor['last'], $pk, self::EXPORT_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export of a table that is about to be dropped.
		} elseif ( '' !== $pk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d, %d', $table, $pk, (int) $cursor['offset'], self::EXPORT_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export of a table that is about to be dropped.
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, (int) $cursor['offset'], self::EXPORT_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export of a table that is about to be dropped.
		}
		if ( null === $rows && $wpdb->last_error ) {
			$this->job['errors'][] = $item['label'] . ': ' . $wpdb->last_error;
			return true;
		}
		$rows = (array) $rows;
		if ( $rows ) {
			if ( ! LucidLab_DBC_Backup::add_rows( $backup, $table, $rows ) ) {
				$this->job['errors'][] = $item['label'] . ': ' . __( 'could not write the backup; the table was kept.', 'lucidlab-db-cleanup' );
				return true;
			}
			$last                     = end( $rows );
			$item['cursor']['last']   = ! empty( $cursor['numeric'] ) ? (int) $last[ $pk ] : null;
			$item['cursor']['offset'] = (int) $cursor['offset'] + count( $rows );
			$item['rows']            += count( $rows );
			$this->job['rows']       += count( $rows );
			if ( count( $rows ) >= self::EXPORT_BATCH ) {
				return false;
			}
		}
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping a leftover table the user selected, after it was exported to the backup.
		++$this->job['tables'];
		$this->job['dropped'][] = $table;
		unset( $this->job['affected'][ $table ] );
		return true;
	}

	/**
	 * Back up and delete all rows of a table matching a WHERE (internally built, integers/prepared only).
	 *
	 * @param string $table   Table.
	 * @param string $where   WHERE clause.
	 * @param bool   $recount Collect term_taxonomy_id values for recounting.
	 *
	 * @return int Rows deleted.
	 */
	private function purge( string $table, string $where, bool $recount = false ): int {
		global $wpdb;
		$keys  = $this->key_columns( $table );
		$total = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} LIMIT %d", $table, self::ROW_BATCH * 4 ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built internally from integer or prepared IN lists.
			if ( ! $rows ) {
				break;
			}
			$deleted = $this->delete_rows( $table, $keys, $rows, $recount );
			$total  += $deleted;
			$more    = count( $rows ) >= self::ROW_BATCH * 4;
		} while ( $deleted > 0 && $more );
		return $total;
	}

	/**
	 * Back up rows, then delete exactly those rows by their key.
	 *
	 * @param string                         $table   Table.
	 * @param array<int,string>              $keys    Key columns.
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param bool                           $recount Collect term_taxonomy_id values.
	 *
	 * @return int
	 * @throws RuntimeException When the backup cannot be written (nothing is deleted then).
	 */
	private function delete_rows( string $table, array $keys, array $rows, bool $recount ): int {
		global $wpdb;
		if ( ! LucidLab_DBC_Backup::add_rows( (string) $this->job['backup'], $table, $rows ) ) {
			throw new RuntimeException( esc_html__( 'could not write the backup file, so nothing was deleted.', 'lucidlab-db-cleanup' ) );
		}
		if ( $recount ) {
			$this->job['recount'] = array_values( array_unique( array_merge( (array) $this->job['recount'], array_map( 'intval', wp_list_pluck( $rows, 'term_taxonomy_id' ) ) ) ) );
		}
		$deleted = 0;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			if ( 1 === count( $keys ) ) {
				$where = LucidLab_DBC_Helpers::ident( $keys[0] ) . ' IN (' . LucidLab_DBC_Helpers::in_strings( array_map( 'strval', wp_list_pluck( $chunk, $keys[0] ) ) ) . ')';
			} else {
				$tuples = array();
				foreach ( $chunk as $row ) {
					$values = array();
					foreach ( $keys as $key ) {
						$values[] = (string) $row[ $key ];
					}
					$tuples[] = '(' . LucidLab_DBC_Helpers::in_strings( $values ) . ')';
				}
				$where = '(' . implode( ',', array_map( array( 'LucidLab_DBC_Helpers', 'ident' ), $keys ) ) . ') IN (' . implode( ',', $tuples ) . ')';
			}
			$result   = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$where}", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Key values are prepared by in_strings(); rows were backed up above.
			$deleted += (int) $result;
		}
		$this->job['rows'] += $deleted;
		if ( $deleted > 0 ) {
			$this->job['affected'][ $table ] = true;
			$this->invalidate_cache( $table, $rows );
		}
		return $deleted;
	}

	/**
	 * Remove the deleted rows from the object cache.
	 *
	 * Targeted instead of wp_cache_flush(): with a persistent object cache (Redis, Memcached,
	 * LiteSpeed) a full flush empties the cache of the whole site, which then has to be rebuilt by
	 * every visitor. Only for very large cleanups (more than CACHE_OPS_LIMIT rows) one full flush is
	 * done at the end. Tables without core caching need nothing.
	 *
	 * @param string                         $table Table.
	 * @param array<int,array<string,mixed>> $rows  Deleted rows.
	 */
	private function invalidate_cache( string $table, array $rows ): void {
		global $wpdb;
		if ( ! empty( $this->job['flush'] ) ) {
			return;
		}
		$this->job['cache_ops'] = (int) $this->job['cache_ops'] + count( $rows );
		if ( $this->job['cache_ops'] > self::CACHE_OPS_LIMIT ) {
			$this->job['flush'] = true;
			return;
		}
		$ids = static function ( string $column ) use ( $rows ): array {
			return array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, $column ) ) ) );
		};
		switch ( $table ) {
			case $wpdb->options:
				foreach ( wp_list_pluck( $rows, 'option_name' ) as $name ) {
					wp_cache_delete( (string) $name, 'options' );
				}
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				break;
			case $wpdb->posts:
				foreach ( $ids( 'ID' ) as $id ) {
					wp_cache_delete( $id, 'posts' );
					wp_cache_delete( $id, 'post_meta' );
				}
				wp_cache_set_posts_last_changed();
				break;
			case $wpdb->postmeta:
				foreach ( $ids( 'post_id' ) as $id ) {
					wp_cache_delete( $id, 'post_meta' );
				}
				wp_cache_set_posts_last_changed();
				break;
			case $wpdb->comments:
				clean_comment_cache( $ids( 'comment_ID' ) );
				break;
			case $wpdb->commentmeta:
				foreach ( $ids( 'comment_id' ) as $id ) {
					wp_cache_delete( $id, 'comment_meta' );
				}
				wp_cache_set_comments_last_changed();
				break;
			case $wpdb->terms:
			case $wpdb->termmeta:
				foreach ( $ids( 'term_id' ) as $id ) {
					wp_cache_delete( $id, 'terms' );
					wp_cache_delete( $id, 'term_meta' );
				}
				wp_cache_set_terms_last_changed();
				break;
			case $wpdb->term_taxonomy:
				foreach ( $ids( 'term_id' ) as $id ) {
					wp_cache_delete( $id, 'terms' );
				}
				foreach ( array_unique( array_map( 'strval', wp_list_pluck( $rows, 'taxonomy' ) ) ) as $taxonomy ) {
					wp_cache_delete( 'all_ids', $taxonomy );
					wp_cache_delete( 'get', $taxonomy );
				}
				wp_cache_set_terms_last_changed();
				break;
			case $wpdb->term_relationships:
				$taxonomies = get_taxonomies();
				foreach ( $ids( 'object_id' ) as $id ) {
					foreach ( $taxonomies as $taxonomy ) {
						wp_cache_delete( $id, $taxonomy . '_relationships' );
					}
				}
				wp_cache_set_terms_last_changed();
				break;
			case $wpdb->usermeta:
				foreach ( $ids( 'user_id' ) as $id ) {
					wp_cache_delete( $id, 'user_meta' );
				}
				break;
			case $wpdb->users:
				foreach ( $ids( 'ID' ) as $id ) {
					wp_cache_delete( $id, 'users' );
					wp_cache_delete( $id, 'user_meta' );
				}
				break;
		}
	}

	/**
	 * Whether a column has an integer type.
	 *
	 * @param string $table  Table.
	 * @param string $column Column.
	 *
	 * @return bool
	 */
	private function is_integer_column( string $table, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
		return is_array( $row ) && (bool) preg_match( '/^(tiny|small|medium|big)?int\b/i', (string) $row['Type'] );
	}

	/**
	 * Primary-key columns of a table (all columns when it has no primary key).
	 *
	 * @param string $table Table.
	 *
	 * @return array<int,string>
	 */
	private function key_columns( string $table ): array {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
			usort(
				$rows,
				static function ( $a, $b ) {
					return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
				}
			);
			$keys = array_map( 'strval', wp_list_pluck( $rows, 'Column_name' ) );
			if ( ! $keys ) {
				$keys = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
			}
			$cache[ $table ] = $keys;
		}
		return $cache[ $table ];
	}
}
