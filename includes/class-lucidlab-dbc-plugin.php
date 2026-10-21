<?php
/**
 * Admin screen, AJAX router and downloads.
 *
 * @package LucidLab_DB_Cleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 */
final class LucidLab_DBC_Plugin {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'lucidlab-db-cleanup';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'lucidlab_dbc_admin';

	/**
	 * Option holding the last scan result.
	 */
	public const SCAN_OPTION = 'lucidlab_dbc_scan';

	/**
	 * Capability.
	 */
	private const CAP = 'manage_options';

	/**
	 * Instance.
	 *
	 * @var LucidLab_DBC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Scanner, created on first use (only on our screen and our AJAX requests).
	 *
	 * @var LucidLab_DBC_Scanner|null
	 */
	private $scanner = null;

	/**
	 * Singleton.
	 *
	 * @return LucidLab_DBC_Plugin
	 */
	public static function instance(): LucidLab_DBC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks. Only created on our own screen, our AJAX action and our download request; the menu
	 * entry and the Plugins-screen link are registered by the main plugin file.
	 */
	private function __construct() {
		add_action( 'load-toplevel_page_' . self::SLUG, array( __CLASS__, 'migrate_autoload' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lucidlab_dbc', array( $this, 'ajax' ) );
		add_action( 'admin_post_lucidlab_dbc_download', array( $this, 'download' ) );
		add_action( 'admin_post_lucidlab_dbc_full_download', array( $this, 'full_download' ) );
	}

	/**
	 * The scanner (loads the scanner classes on first use).
	 *
	 * @return LucidLab_DBC_Scanner
	 */
	private function scanner(): LucidLab_DBC_Scanner {
		if ( null === $this->scanner ) {
			$this->scanner = new LucidLab_DBC_Scanner();
		}
		return $this->scanner;
	}

	/**
	 * Make sure none of our options is autoloaded (they can be hundreds of KB and are only read on
	 * our screen). Runs on activation and when our screen opens, so an update that was installed
	 * without re-activation is migrated as well. Costs one SELECT when nothing has to change.
	 */
	public static function migrate_autoload(): void {
		wp_set_options_autoload(
			array(
				self::SCAN_OPTION,
				LucidLab_DBC_Cleaner::JOB_OPTION,
				LucidLab_DBC_Database::RUNS_OPTION,
				LucidLab_DBC_Database::MEASURE_OPTION,
				LucidLab_DBC_Backup::OPTION,
				LucidLab_DBC_Backup::DIR_KEY_OPTION,
				LucidLab_DBC_Automation::SETTINGS_OPTION,
				LucidLab_DBC_Cleaner::STOP_OPTION,
				LucidLab_DBC_Fullbackup::INDEX_OPTION,
				LucidLab_DBC_Fullbackup::JOB_OPTION,
				LucidLab_DBC_Fullbackup::LAST_RESTORE_OPTION,
				LucidLab_DBC_Site_Ping::OPTION,
				LucidLab_DBC_Site_Ping::INSTALL_ID,
				LucidLab_DBC_Site_Ping::LAST,
				LucidLab_DBC_Site_Ping::INSTALLED_AT,
			),
			false
		);
	}

	/**
	 * Enqueue CSS and JS on our screen only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'lucidlab-dbc-admin', LUCIDLAB_DBC_URL . 'assets/css/admin.css', array(), LUCIDLAB_DBC_VERSION . '.' . filemtime( LUCIDLAB_DBC_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'lucidlab-dbc-admin', LUCIDLAB_DBC_URL . 'assets/js/admin.js', array(), LUCIDLAB_DBC_VERSION . '.' . filemtime( LUCIDLAB_DBC_DIR . 'assets/js/admin.js' ), true );
		wp_localize_script(
			'lucidlab-dbc-admin',
			'LucidLabDBC',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'categories'  => wp_list_pluck( LucidLab_DBC_Scanner::categories(), 'label' ),
				'tab'         => $this->current_tab(),
				'databaseUrl' => $this->tab_url( 'database' ),
				'fullUrl'     => $this->tab_url( 'fullbackup' ),
				'isPro'       => lucidlab_dbc_is_pro(),
				'i18n'        => array(
					/* translators: 1: table number, 2: number of tables. */
					'phaseBeforeAnalyze' => __( 'Measuring the database before the cleanup: ANALYZE TABLE %1$s of %2$s…', 'lucidlab-db-cleanup' ),
					'phaseBeforeMeasure' => __( 'Measuring size, tables and records before the cleanup…', 'lucidlab-db-cleanup' ),
					/* translators: 1: table name, 2: table number, 3: number of tables. */
					'phaseOptimize'      => __( 'OPTIMIZE TABLE %1$s (%2$s of %3$s)… InnoDB tables are rebuilt, which takes longer for large tables.', 'lucidlab-db-cleanup' ),
					/* translators: 1: table number, 2: number of tables. */
					'phaseAfterAnalyze'  => __( 'Refreshing table statistics: ANALYZE TABLE %1$s of %2$s…', 'lucidlab-db-cleanup' ),
					'phaseAfterMeasure'  => __( 'Measuring size, tables and records after the cleanup…', 'lucidlab-db-cleanup' ),
					/* translators: %s: table name. */
					'busy'               => __( 'The database is still busy with the previous step (%s). Waiting…', 'lucidlab-db-cleanup' ),
					/* translators: 1: attempt, 2: maximum attempts. */
					'retrying'           => __( 'The request failed; trying again (%1$s of %2$s)…', 'lucidlab-db-cleanup' ),
					/* translators: 1: size saved, 2: percentage. */
					'saved'              => __( 'Done. Saved %1$s (%2$s%%). Opening the summary…', 'lucidlab-db-cleanup' ),
					'measuring'          => __( 'Measuring the database…', 'lucidlab-db-cleanup' ),
					/* translators: %s: category name. */
					'scanning'           => __( 'Scanning %s…', 'lucidlab-db-cleanup' ),
					'scanDone'           => __( 'Scan finished. Reloading…', 'lucidlab-db-cleanup' ),
					'problems'           => __( 'Finished with problems:', 'lucidlab-db-cleanup' ),
					'viewSummary'        => __( 'View the database summary and log', 'lucidlab-db-cleanup' ),
					/* translators: 1: number of selected findings, 2: size. */
					'selected'           => __( '%1$s selected · about %2$s', 'lucidlab-db-cleanup' ),
					'noneSelected'       => __( 'Nothing selected', 'lucidlab-db-cleanup' ),
					/* translators: 1: current finding, 2: finding number, 3: total findings, 4: rows deleted. */
					'cleaning'           => __( 'Cleaning “%1$s” (%2$s of %3$s) · %4$s rows backed up and deleted', 'lucidlab-db-cleanup' ),
					'rescanning'         => __( 'Cleanup finished. Updating the scan results…', 'lucidlab-db-cleanup' ),
					/* translators: %s: percentage. */
					'restoring'          => __( 'Restoring… %s%%', 'lucidlab-db-cleanup' ),
					'restored'           => __( 'Backup restored. Run a new scan to update the results.', 'lucidlab-db-cleanup' ),
					'failed'             => __( 'The request failed. Check the PHP error log; nothing after the last finished batch was changed.', 'lucidlab-db-cleanup' ),
					'loading'            => __( 'Loading…', 'lucidlab-db-cleanup' ),
					'close'              => __( 'Close', 'lucidlab-db-cleanup' ),
					'deleted'            => __( 'Backup deleted.', 'lucidlab-db-cleanup' ),
					'safeStarting'       => __( 'Checking the safe findings and writing the backup…', 'lucidlab-db-cleanup' ),
					'saving'             => __( 'Saving…', 'lucidlab-db-cleanup' ),
					'settingsSaved'      => __( 'Settings saved.', 'lucidlab-db-cleanup' ),
					'scanRulesSaved'     => __( 'Settings saved. Run the scan again to use the new age.', 'lucidlab-db-cleanup' ),
					'sending'            => __( 'Sending…', 'lucidlab-db-cleanup' ),
					'notScheduled'       => __( 'Not scheduled', 'lucidlab-db-cleanup' ),
					'working'            => __( 'Working…', 'lucidlab-db-cleanup' ),
					'fullStarting'       => __( 'Preparing the full backup…', 'lucidlab-db-cleanup' ),
					/* translators: 1: table number, 2: number of tables, 3: table name, 4: records written, 5: file size. */
					'fullTable'          => __( 'Table %1$s of %2$s: %3$s · %4$s records written · %5$s', 'lucidlab-db-cleanup' ),
					/* translators: 1: file size, 2: number of tables, 3: number of records, 4: seconds. */
					'fullDone'           => __( 'Full backup finished: %1$s, %2$s tables, %3$s records in %4$s s.', 'lucidlab-db-cleanup' ),
					'safetyFirst'        => __( 'Safety backup of the current database first…', 'lucidlab-db-cleanup' ),
					/* translators: %s: amount unpacked. */
					'unpacking'          => __( 'Unpacking the backup… %s', 'lucidlab-db-cleanup' ),
					/* translators: 1: percentage, 2: number of tables, 3: number of statements. */
					'importing'          => __( 'Importing into temporary tables… %1$s%% · %2$s tables · %3$s statements', 'lucidlab-db-cleanup' ),
					'swapping'           => __( 'Replacing the live tables…', 'lucidlab-db-cleanup' ),
					'checking'           => __( 'All statements are imported into temporary tables. Checking them before the live tables are replaced…', 'lucidlab-db-cleanup' ),
					/* translators: 1: number of tables, 2: seconds. */
					'restoreDone'        => __( 'Restore finished: %1$s tables restored in %2$s s. If WordPress asks you to log in again, use the login of the restored database. Reloading…', 'lucidlab-db-cleanup' ),
					'restoreFailed'      => __( 'The restore stopped. The live tables were not replaced. Fix the problem and continue, or cancel to remove the temporary tables.', 'lucidlab-db-cleanup' ),
					'ackTitle'           => __( 'Please check before the live tables are replaced:', 'lucidlab-db-cleanup' ),
					'cancelled'          => __( 'Restore cancelled; the temporary tables were removed and nothing was changed.', 'lucidlab-db-cleanup' ),
					/* translators: 1: amount uploaded, 2: total size. */
					'uploading'          => __( 'Uploading… %1$s of %2$s', 'lucidlab-db-cleanup' ),
					'uploaded'           => __( 'Upload finished. Reloading…', 'lucidlab-db-cleanup' ),
					/* translators: %s: site URL stored in the backup. */
					'urlMismatch'        => __( 'This backup was made on %s, not on this site. After the restore the site uses that address, which can make wp-admin unreachable here.', 'lucidlab-db-cleanup' ),
					'chooseFile'         => __( 'Choose a .sql or .sql.gz file first.', 'lucidlab-db-cleanup' ),
					/* translators: %s: number of skipped statements. */
					'skipped'            => __( '%s statements were skipped (not table data).', 'lucidlab-db-cleanup' ),
					/* translators: 1: number of revisions, 2: number of posts. */
					'revPreview'         => __( '%1$s revisions of %2$s posts would be removed.', 'lucidlab-db-cleanup' ),
					'revNone'            => __( 'No post has more revisions than that. Nothing to remove.', 'lucidlab-db-cleanup' ),
					'pingOn'             => __( 'Saved. This site now tells Lucid Lab that it uses the plugin. You can turn it off on the Settings tab.', 'lucidlab-db-cleanup' ),
					'pingOff'            => __( 'Saved. Nothing is sent to Lucid Lab.', 'lucidlab-db-cleanup' ),
				),
			)
		);
		/**
		 * Fires after the free plugin enqueued its assets on its own screen (add-ons enqueue theirs here).
		 *
		 * @param string $tab Current tab.
		 */
		do_action( 'lucidlab_dbc_enqueue_assets', $this->current_tab() );
	}

	/**
	 * Tabs: overview, summary, one per scan category, the free reports, backups, and the Pro sections
	 * (shown locked with a "Pro" badge while the Pro add-on is not active).
	 *
	 * @return array<string,string>
	 */
	public function tabs(): array {
		$tabs = array(
			'overview' => __( 'Overview', 'lucidlab-db-cleanup' ),
			'database' => __( 'Database summary & log', 'lucidlab-db-cleanup' ),
		);
		foreach ( LucidLab_DBC_Scanner::categories() as $key => $category ) {
			$tabs[ $key ] = $category['label'];
		}
		$tabs['autoload']   = __( 'Autoload checker', 'lucidlab-db-cleanup' );
		$tabs['engines']    = __( 'Engines & charset', 'lucidlab-db-cleanup' );
		$tabs['backups']    = __( 'Backups & restore', 'lucidlab-db-cleanup' );
		$tabs['fullbackup'] = __( 'Full backup', 'lucidlab-db-cleanup' );
		$tabs['settings']   = __( 'Settings', 'lucidlab-db-cleanup' );
		/**
		 * Filters the tabs of the Database Cleaner screen. Add a renderer for a new tab with the
		 * lucidlab_dbc_render_tab_{tab} action.
		 *
		 * @param array<string,string> $tabs Tab key => label.
		 */
		$tabs = (array) apply_filters( 'lucidlab_dbc_tabs', $tabs );
		foreach ( self::pro_features() as $key => $feature ) {
			if ( ! isset( $tabs[ $key ] ) ) {
				$tabs[ $key ] = $feature['label'];
			}
		}
		if ( ! self::indexes_split() ) {
			unset( $tabs['indexes'] );
		}
		// Each Pro section sits next to the related free section (see nav_groups()).
		$ordered = array();
		foreach ( self::nav_groups() as $group ) {
			foreach ( $group['tabs'] as $key ) {
				if ( isset( $tabs[ $key ] ) ) {
					$ordered[ $key ] = $tabs[ $key ];
				}
			}
		}
		$tabs = $ordered + $tabs;
		return array_map( 'strval', $tabs );
	}

	/**
	 * Sidebar groups, in order. Tabs that no group lists (added by other add-ons) are shown under
	 * "Clean up" when they are a scan category and under "Tools" otherwise.
	 *
	 * @return array<string,array{label:string,tabs:array<int,string>}>
	 */
	public static function nav_groups(): array {
		return array(
			'reports'  => array(
				'label' => __( 'Overview & reports', 'lucidlab-db-cleanup' ),
				'tabs'  => array( 'overview', 'database', 'growth', 'alerts' ),
			),
			'clean'    => array(
				'label' => __( 'Clean up', 'lucidlab-db-cleanup' ),
				'tabs'  => array( 'content', 'post_types', 'revisions', 'meta', 'orphans_pro', 'terms', 'comments', 'options', 'autoload', 'transients', 'woocommerce', 'woo_pro', 'tables', 'schedule' ),
			),
			'backups'  => array(
				'label' => __( 'Backups', 'lucidlab-db-cleanup' ),
				'tabs'  => array( 'backups', 'fullbackup' ),
			),
			'tools'    => array(
				'label' => __( 'Tools', 'lucidlab-db-cleanup' ),
				'tabs'  => array( 'engines', 'indexes', 'replace' ),
			),
			'settings' => array(
				'label' => __( 'Settings', 'lucidlab-db-cleanup' ),
				'tabs'  => array( 'settings' ),
			),
		);
	}

	/**
	 * Group of a tab in the sidebar.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string Group key.
	 */
	private static function nav_group_of( string $tab ): string {
		foreach ( self::nav_groups() as $key => $group ) {
			if ( in_array( $tab, $group['tabs'], true ) ) {
				return $key;
			}
		}
		return array_key_exists( $tab, LucidLab_DBC_Scanner::categories() ) ? 'clean' : 'tools';
	}

	/**
	 * Whether index tools have their own tab. Pro 1.0.3 and older show them on the growth tab, so
	 * with such a licensed Pro (no renderer for the indexes tab) the growth tab keeps them.
	 *
	 * @return bool
	 */
	private static function indexes_split(): bool {
		return ! lucidlab_dbc_is_pro() || has_action( 'lucidlab_dbc_render_tab_indexes' );
	}

	/**
	 * Sections of the Pro add-on. While Pro is not active they are listed as locked tabs that only
	 * describe the feature; the free plugin contains none of their code.
	 *
	 * @return array<string,array{label:string,text:string,points:array<int,string>}>
	 */
	public static function pro_features(): array {
		return array(
			'schedule'    => array(
				'label'  => __( 'Schedule & e-mail', 'lucidlab-db-cleanup' ),
				'text'   => __( 'Clean the safe items automatically every week or month at night, with an optional full backup first, and get a report by e-mail.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Weekly or monthly cleanups at a time of your choice', 'lucidlab-db-cleanup' ),
					__( 'Scheduled full backup before each cleanup', 'lucidlab-db-cleanup' ),
					__( 'Report e-mail with before/after numbers', 'lucidlab-db-cleanup' ),
				),
			),
			'woo_pro'     => array(
				'label'  => __( 'WooCommerce deep clean', 'lucidlab-db-cleanup' ),
				'text'   => __( 'Old WooCommerce sessions, finished Action Scheduler actions and logs older than a number of days you choose, and order item meta and HPOS order data of deleted orders.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Action Scheduler: completed, failed and canceled actions older than N days, with their logs', 'lucidlab-db-cleanup' ),
					__( 'Orphaned order item meta and HPOS order meta, addresses and operational data', 'lucidlab-db-cleanup' ),
					__( 'Backup before every delete, restore with one click', 'lucidlab-db-cleanup' ),
				),
			),
			'orphans_pro' => array(
				'label'  => __( 'Extended orphan scan', 'lucidlab-db-cleanup' ),
				'text'   => __( 'Goes beyond the orphaned meta of the free scan: duplicate meta rows, meta with empty keys, term relationships of deleted objects and term data whose parent is gone.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Duplicate post, user, term and comment meta', 'lucidlab-db-cleanup' ),
					__( 'Term taxonomy rows and term meta without a term', 'lucidlab-db-cleanup' ),
					__( 'Every delete is backed up first', 'lucidlab-db-cleanup' ),
				),
			),
			'growth'      => self::indexes_split() ? array(
				'label'  => __( 'Growth chart', 'lucidlab-db-cleanup' ),
				'text'   => __( 'A weekly size snapshot with a chart of two years and the biggest and fastest-growing tables.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Database growth chart (104 weeks)', 'lucidlab-db-cleanup' ),
					__( 'Biggest and fastest-growing tables per week', 'lucidlab-db-cleanup' ),
				),
			) : array(
				'label'  => __( 'Growth & indexes', 'lucidlab-db-cleanup' ),
				'text'   => __( 'A weekly size snapshot with a chart of two years, the biggest and fastest-growing tables, and missing indexes on meta tables that you can add with one click.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Database growth chart (104 weeks)', 'lucidlab-db-cleanup' ),
					__( 'Fastest-growing tables per week', 'lucidlab-db-cleanup' ),
					__( 'Index suggestions with backup, confirmation and undo', 'lucidlab-db-cleanup' ),
				),
			),
			'indexes'     => array(
				'label'  => __( 'Index tools', 'lucidlab-db-cleanup' ),
				'text'   => __( 'Finds tables without a primary key and missing indexes on common WordPress and WooCommerce lookup columns, and adds an index with one click after a structure backup.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Tables without a primary key', 'lucidlab-db-cleanup' ),
					__( 'Index suggestions with backup, confirmation and undo', 'lucidlab-db-cleanup' ),
				),
			),
			'autoload'    => array(
				'label'  => __( 'Autoload checker', 'lucidlab-db-cleanup' ),
				'text'   => __( 'The full autoload checker: the 50 largest autoloaded options with their likely source, size and share, and a switch to turn autoload off (or on again) per option. Every switch is backed up and can be undone.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Total autoload size with the 800 KB warning of WordPress', 'lucidlab-db-cleanup' ),
					__( 'Turn autoload off or on per option, core options protected', 'lucidlab-db-cleanup' ),
					__( 'Backup and undo of every switch', 'lucidlab-db-cleanup' ),
				),
			),
			'replace'     => array(
				'label'  => __( 'Search & replace', 'lucidlab-db-cleanup' ),
				'text'   => __( 'Replace text in the database safely, for example after a domain change: serialized and JSON data stay valid, a dry run shows the counts first and the affected rows are backed up.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Dry run with counts per table', 'lucidlab-db-cleanup' ),
					__( 'Serialized and JSON aware, GUIDs skipped by default', 'lucidlab-db-cleanup' ),
					__( 'Backup of the changed rows, undo from Backups & restore', 'lucidlab-db-cleanup' ),
				),
			),
			'alerts'      => array(
				'label'  => __( 'Health alerts', 'lucidlab-db-cleanup' ),
				'text'   => __( 'A daily check that e-mails you when the database, the autoloaded options or a table grows past the limits you set.', 'lucidlab-db-cleanup' ),
				'points' => array(
					__( 'Database size, autoload size and weekly table growth', 'lucidlab-db-cleanup' ),
					__( 'One e-mail per problem, not every day', 'lucidlab-db-cleanup' ),
				),
			),
		);
	}

	/**
	 * Whether a tab is a Pro section that is locked (Pro not active, or no renderer registered).
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	private function is_locked_tab( string $tab ): bool {
		return isset( self::pro_features()[ $tab ] ) && ! lucidlab_dbc_is_pro();
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch on our admin page.
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'overview';
	}

	/**
	 * URL of a tab (for add-ons).
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	public static function url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Stored scan result.
	 *
	 * @return array{time:int,categories:array<string,array{time:int,items:array<int,array<string,mixed>>}>}
	 */
	private function scan(): array {
		return self::stored_scan();
	}

	/**
	 * Stored scan result (also used by scheduled runs).
	 *
	 * @return array{time:int,categories:array<string,array{time:int,items:array<int,array<string,mixed>>}>}
	 */
	public static function stored_scan(): array {
		$scan = get_option( self::SCAN_OPTION, array() );
		$scan = is_array( $scan ) ? $scan : array();
		return array(
			'time'       => (int) ( $scan['time'] ?? 0 ),
			'categories' => (array) ( $scan['categories'] ?? array() ),
		);
	}

	/**
	 * Stored findings by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function findings(): array {
		return self::stored_findings();
	}

	/**
	 * Stored findings by id (also used by scheduled runs).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function stored_findings(): array {
		$out = array();
		foreach ( self::stored_scan()['categories'] as $category ) {
			foreach ( (array) ( $category['items'] ?? array() ) as $item ) {
				$out[ (string) $item['id'] ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Totals of a list of findings.
	 *
	 * @param array<int,array<string,mixed>> $items Findings.
	 *
	 * @return array{findings:int,bytes:int,safe_bytes:int,safe:int,review:int,risky:int}
	 */
	private function totals( array $items ): array {
		$out = array(
			'findings'   => 0,
			'bytes'      => 0,
			'safe_bytes' => 0,
			'safe'       => 0,
			'review'     => 0,
			'risky'      => 0,
		);
		foreach ( $items as $item ) {
			if ( empty( $item['deletable'] ) ) {
				continue;
			}
			++$out['findings'];
			$out['bytes'] += (int) $item['bytes'];
			$conf          = (string) $item['confidence'];
			if ( isset( $out[ $conf ] ) ) {
				++$out[ $conf ];
			}
			if ( LucidLab_DBC_Scanner::SAFE === $conf ) {
				$out['safe_bytes'] += (int) $item['bytes'];
			}
		}
		return $out;
	}

	/**
	 * Logo URL.
	 *
	 * @return string
	 */
	private function logo_url(): string {
		return LUCIDLAB_DBC_URL . 'assets/img/logo-icon.svg';
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'lucidlab-db-cleanup' ) );
		}
		$tab   = $this->current_tab();
		$tabs  = $this->tabs();
		$scan  = $this->scan();
		$title = __( 'Lucid Lab Database Cleaner', 'lucidlab-db-cleanup' );
		?>
		<div class="wrap lucidlab-dbc-wrap" data-dbc-tab="<?php echo esc_attr( $tab ); ?>">
			<header class="lucidlab-dbc-admin-head">
				<div class="lucidlab-dbc-admin-title">
					<img class="lucidlab-dbc-admin-logo" src="<?php echo esc_url( $this->logo_url() ); ?>" alt="" width="52" height="52" />
					<div class="lucidlab-dbc-admin-title-text">
						<h1 class="lucidlab-dbc-title">
							<?php
							if ( preg_match( '/^(Lucid Lab)\s+(.+)$/u', $title, $parts ) ) {
								echo '<span class="lucidlab-dbc-title-brand">' . esc_html( $parts[1] ) . '</span> <span class="lucidlab-dbc-title-name">' . esc_html( $parts[2] ) . '</span>';
							} else {
								echo esc_html( $title );
							}
							?>
						</h1>
						<p class="lucidlab-dbc-ver">v<?php echo esc_html( LUCIDLAB_DBC_VERSION ); ?> <?php esc_html_e( 'Free', 'lucidlab-db-cleanup' ); ?><?php echo defined( 'LUCIDLAB_DBCP_VERSION' ) ? ' &amp; v' . esc_html( (string) LUCIDLAB_DBCP_VERSION ) . ' ' . esc_html__( 'Pro', 'lucidlab-db-cleanup' ) : ''; ?> <span aria-hidden="true">·</span> <a class="lucidlab-dbc-head-link" href="<?php echo esc_url( lucidlab_dbc_docs_url() ); ?>" target="_blank" rel="noopener noreferrer" data-testid="docs-link"><?php esc_html_e( 'Documentation', 'lucidlab-db-cleanup' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lucidlab-db-cleanup' ); ?></span></a></p>
					</div>
				</div>
			</header>
			<hr class="wp-header-end">
			<div class="lucidlab-dbc-admin-body">
				<aside class="lucidlab-dbc-nav-card lucidlab-dbc-card">
					<nav class="lucidlab-dbc-tabs lucidlab-dbc-tabs-sidebar" aria-label="<?php esc_attr_e( 'Database Cleaner sections', 'lucidlab-db-cleanup' ); ?>">
						<?php
						$current_group = '';
						$groups        = self::nav_groups();
						foreach ( $tabs as $key => $label ) {
							$group = self::nav_group_of( $key );
							if ( $group !== $current_group ) {
								$current_group = $group;
								echo '<span class="lucidlab-dbc-nav-heading" data-testid="nav-group-' . esc_attr( $group ) . '">' . esc_html( $groups[ $group ]['label'] ) . '</span>';
							}
							// Pro sections keep their Pro badge, also when Pro is active.
							$badge = isset( self::pro_features()[ $key ] ) ? self::pro_badge() : '';
							if ( ! $this->is_locked_tab( $key ) && isset( $scan['categories'][ $key ] ) ) {
								$count = $this->totals( (array) $scan['categories'][ $key ]['items'] )['findings'];
								$badge = '<span class="lucidlab-dbc-tab-badges">' . $badge . '<span class="lucidlab-dbc-tab-badge">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
							}
							printf(
								'<a href="%s" class="nav-tab %s"%s data-testid="tab-%s">%s%s</a>',
								esc_url( $this->tab_url( $key ) ),
								( $key === $tab ? 'nav-tab-active' : '' ) . ( $this->is_locked_tab( $key ) ? ' lucidlab-dbc-tab-locked' : '' ),
								$key === $tab ? ' aria-current="page"' : '',
								esc_attr( $key ),
								esc_html( $label ),
								wp_kses_post( $badge )
							);
						}
						?>
					</nav>
				</aside>
				<div class="lucidlab-dbc-admin-content">
					<div class="lucidlab-dbc-panel lucidlab-dbc-panel-cards" data-dbc-panel="<?php echo esc_attr( $tab ); ?>">
						<?php
						$this->render_hero( $tab, $tabs[ $tab ] );
						if ( ! is_multisite() && 'settings' !== $tab ) {
							$this->render_ping_notice();
						}
						if ( is_multisite() ) {
							echo '<div class="notice inline notice-warning"><p>' . esc_html__( 'This plugin works on single sites only. On a multisite network the tables of every site share one database, which this version does not analyse. Nothing is scanned or deleted.', 'lucidlab-db-cleanup' ) . '</p></div>';
						} elseif ( $this->is_locked_tab( $tab ) ) {
							$this->render_locked_tab( $tab );
						} elseif ( has_action( 'lucidlab_dbc_render_tab_' . $tab ) ) {
							/**
							 * Renders a tab registered by an add-on.
							 *
							 * @param LucidLab_DBC_Plugin $plugin Plugin.
							 */
							do_action( 'lucidlab_dbc_render_tab_' . $tab, $this );
						} elseif ( 'overview' === $tab ) {
							$this->render_overview( $scan );
						} elseif ( 'backups' === $tab ) {
							$this->render_backups();
						} elseif ( 'database' === $tab ) {
							$this->render_database();
						} elseif ( 'fullbackup' === $tab ) {
							$this->render_fullbackup();
						} elseif ( 'autoload' === $tab ) {
							// Only reached with a licensed Pro add-on older than 1.0.4, which has no renderer of its own.
							$this->render_autoload_preview( true );
						} elseif ( 'engines' === $tab ) {
							$this->render_engines();
						} elseif ( 'settings' === $tab ) {
							$this->render_settings();
						} elseif ( array_key_exists( $tab, LucidLab_DBC_Scanner::categories() ) ) {
							$this->render_category( $tab, $scan );
							if ( 'options' === $tab ) {
								$this->render_autoload_preview();
							}
						}
						if ( ! is_multisite() ) {
							/**
							 * Fires at the end of a tab (add-ons append their cards here).
							 *
							 * @param LucidLab_DBC_Plugin $plugin Plugin.
							 */
							do_action( 'lucidlab_dbc_after_tab_' . $tab, $this );
						}
						?>
					</div>
				</div>
			</div>
			<dialog class="lucidlab-dbc-dialog" data-dbc-dialog aria-labelledby="lucidlab-dbc-dialog-title">
				<div class="lucidlab-dbc-dialog-head"><strong id="lucidlab-dbc-dialog-title" data-dbc-dialog-title></strong><button type="button" class="button lldbc-btn" data-dbc-dialog-close><?php esc_html_e( 'Close', 'lucidlab-db-cleanup' ); ?></button></div>
				<div class="lucidlab-dbc-dialog-body" data-dbc-dialog-body></div>
			</dialog>
		</div>
		<?php
	}

	/**
	 * Compact gradient hero.
	 *
	 * @param string $tab   Tab.
	 * @param string $label Label.
	 */
	private function render_hero( string $tab, string $label ): void {
		$categories = LucidLab_DBC_Scanner::categories();
		$blurbs     = array(
			'overview'   => __( 'Finds records and tables that WordPress, WooCommerce and your plugins no longer use, explains where they came from, and removes the ones you select after writing a backup you can restore.', 'lucidlab-db-cleanup' ),
			'database'   => __( 'Size, tables and records of this site and of the whole database, the before/after result of every cleanup, and table optimization.', 'lucidlab-db-cleanup' ),
			'backups'    => __( 'Every cleanup first writes the affected rows to an SQL file in a protected uploads folder. Restore a cleanup here, download the file, or delete old backups.', 'lucidlab-db-cleanup' ),
			'fullbackup' => __( 'A complete copy of the database as a compressed SQL file, written in small steps by PHP so it also works on shared hosting. Download it or restore it.', 'lucidlab-db-cleanup' ),
			'autoload'   => __( 'Options marked “autoload” are read on every page view. This read-only report lists the largest ones, who created them and the total size.', 'lucidlab-db-cleanup' ),
			'settings'   => __( 'Options of the free plugin. Nothing here changes the database.', 'lucidlab-db-cleanup' ),
			'engines'    => __( 'Storage engine and character set of every table. InnoDB and utf8mb4 are what WordPress recommends; this report only reads the database.', 'lucidlab-db-cleanup' ),
		);
		foreach ( self::pro_features() as $key => $feature ) {
			$blurbs[ $key ] = $feature['text'];
		}
		/**
		 * Filters the short description under a tab title.
		 *
		 * @param string $blurb Text.
		 * @param string $tab   Tab.
		 */
		$blurb = (string) apply_filters( 'lucidlab_dbc_tab_blurb', $blurbs[ $tab ] ?? ( $categories[ $tab ]['blurb'] ?? '' ), $tab );
		?>
		<section class="lucidlab-dbc-page-hero" aria-labelledby="lucidlab-dbc-hero-<?php echo esc_attr( $tab ); ?>">
			<div>
				<span class="lucidlab-dbc-kicker"><?php echo isset( self::pro_features()[ $tab ] ) ? esc_html__( 'Database Cleaner Pro', 'lucidlab-db-cleanup' ) : esc_html__( 'Database Cleaner', 'lucidlab-db-cleanup' ); ?></span>
				<h2 id="lucidlab-dbc-hero-<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></h2>
				<?php if ( $blurb ) : ?>
					<p><?php echo esc_html( $blurb ); ?></p>
				<?php endif; ?>
			</div>
			<div class="lucidlab-dbc-hero-icon" aria-hidden="true"><img class="lucidlab-dbc-hero-logo" src="<?php echo esc_url( $this->logo_url() ); ?>" alt="" width="48" height="48" /></div>
		</section>
		<?php
	}

	/**
	 * Scan button with progress bar.
	 *
	 * @param array<string,mixed> $scan Scan.
	 * @param string              $only Limit to one category ('' = all).
	 */
	private function render_scan_controls( array $scan, string $only = '' ): void {
		?>
		<div class="lucidlab-dbc-btn-row">
			<button type="button" class="button lldbc-btn button-primary" data-dbc-scan="<?php echo esc_attr( $only ); ?>">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php echo $scan['time'] ? esc_html__( 'Scan again', 'lucidlab-db-cleanup' ) : esc_html__( 'Scan the database', 'lucidlab-db-cleanup' ); ?>
			</button>
			<span class="lucidlab-dbc-hint">
				<?php
				if ( $scan['time'] ) {
					/* translators: %s: date and time. */
					printf( esc_html__( 'Last scan: %s. Scanning only reads the database.', 'lucidlab-db-cleanup' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['time'] ) ) );
				} else {
					esc_html_e( 'Scanning only reads the database; nothing is changed.', 'lucidlab-db-cleanup' );
				}
				?>
			</span>
		</div>
		<div class="lucidlab-dbc-progress" data-dbc-progress hidden>
			<div class="lucidlab-dbc-progress-track"><div class="lucidlab-dbc-progress-bar" data-dbc-progress-bar></div></div>
			<p class="lucidlab-dbc-progress-text" data-dbc-progress-text aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Overview tab.
	 *
	 * @param array<string,mixed> $scan Scan.
	 */
	private function render_overview( array $scan ): void {
		$all = array();
		foreach ( $scan['categories'] as $category ) {
			$all = array_merge( $all, (array) $category['items'] );
		}
		$totals = $this->totals( $all );
		$this->render_pro_notice();
		?>
		<div class="lucidlab-dbc-card">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Scan', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'The scan checks every table with this site’s prefix: posts of post types that nothing registers any more (such as old newsletters), revisions, trash, orphaned meta, terms and comments, expired transients, settings and tables of removed plugins, and stale WooCommerce data. Nothing is selected for you; WordPress core, WooCommerce core and active plugins are protected.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<?php $this->render_scan_controls( $scan ); ?>
			<?php if ( $scan['time'] ) : ?>
				<div class="lucidlab-dbc-stats">
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( $totals['findings'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Findings', 'lucidlab-db-cleanup' ); ?></span></div>
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $totals['bytes'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Reclaimable (estimate)', 'lucidlab-db-cleanup' ); ?></span></div>
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $totals['safe_bytes'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Of which marked safe', 'lucidlab-db-cleanup' ); ?></span></div>
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( $totals['safe'] ) . ' / ' . number_format_i18n( $totals['review'] ) . ' / ' . number_format_i18n( $totals['risky'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Safe / review / risky', 'lucidlab-db-cleanup' ); ?></span></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		if ( $scan['time'] ) {
			$this->render_safe_clean();
		}
		?>
		<?php if ( $scan['time'] ) : ?>
			<div class="lucidlab-dbc-card">
				<div class="lucidlab-dbc-card-head">
					<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Results per category', 'lucidlab-db-cleanup' ); ?></h3>
					<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Open a category to see each finding, where it came from and why it is considered unused. Sizes are estimates of data plus indexes.', 'lucidlab-db-cleanup' ); ?></p>
				</div>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table">
						<thead><tr>
							<th><?php esc_html_e( 'Category', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Findings', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Confidence', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Open', 'lucidlab-db-cleanup' ); ?></span></th>
						</tr></thead>
						<tbody>
						<?php foreach ( LucidLab_DBC_Scanner::categories() as $key => $category ) : ?>
							<?php $sub = $this->totals( (array) ( $scan['categories'][ $key ]['items'] ?? array() ) ); ?>
							<tr>
								<th scope="row"><a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"><?php echo esc_html( $category['label'] ); ?></a><p class="lucidlab-dbc-hint"><?php echo esc_html( $category['blurb'] ); ?></p></th>
								<td><?php echo esc_html( number_format_i18n( $sub['findings'] ) ); ?></td>
								<td>
									<?php
									foreach ( array( 'safe', 'review', 'risky' ) as $conf ) {
										if ( $sub[ $conf ] ) {
											echo $this->confidence_badge( $conf, (string) $sub[ $conf ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in confidence_badge().
										}
									}
									?>
								</td>
								<td class="lucidlab-dbc-nowrap"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $sub['bytes'] ) ); ?></td>
								<td><a class="button lldbc-btn button-small" href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"><?php esc_html_e( 'Open', 'lucidlab-db-cleanup' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
		<?php
		$this->render_last_run( true );
	}

	/**
	 * "Clean all safe items" card on the overview.
	 */
	private function render_safe_clean(): void {
		$selection = LucidLab_DBC_Automation::safe_selection();
		?>
		<div class="lucidlab-dbc-card" data-dbc-safe-card>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Clean all safe items', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'One click for every finding marked “safe”: first a backup of all affected rows, then the delete, then the affected tables are optimized and the result is measured and logged. Findings marked review, risky or info, protected data and data of Lucid Lab plugins are never included.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<?php if ( ! $selection['ids'] ) : ?>
				<p class="lucidlab-dbc-hint"><?php esc_html_e( 'The last scan found nothing marked safe.', 'lucidlab-db-cleanup' ); ?></p>
			<?php else : ?>
				<div class="lucidlab-dbc-btn-row">
					<button type="button" class="button lldbc-btn button-primary lucidlab-dbc-safe-button" data-dbc-safe-open aria-expanded="false" aria-controls="lucidlab-dbc-safe-confirm">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php
						/* translators: 1: number of findings, 2: size. */
						echo esc_html( sprintf( __( 'Clean all safe items (%1$s · %2$s)…', 'lucidlab-db-cleanup' ), number_format_i18n( $selection['findings'] ), LucidLab_DBC_Helpers::bytes( $selection['bytes'] ) ) );
						?>
					</button>
					<span class="lucidlab-dbc-hint">
						<?php
						/* translators: %s: date and time of the scan. */
						printf( esc_html__( 'Based on the scan of %s; every finding is checked again before it is deleted.', 'lucidlab-db-cleanup' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $selection['time'] ) ) );
						?>
					</span>
				</div>
				<div class="lucidlab-dbc-inline-confirm notice inline notice-info" id="lucidlab-dbc-safe-confirm" data-dbc-safe-confirm hidden>
					<p><strong><?php esc_html_e( 'These safe findings will be backed up and deleted:', 'lucidlab-db-cleanup' ); ?></strong></p>
					<table class="lucidlab-dbc-table lucidlab-dbc-table-compact">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Category', 'lucidlab-db-cleanup' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Findings', 'lucidlab-db-cleanup' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $selection['categories'] as $row ) : ?>
							<tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( number_format_i18n( $row['findings'] ) ); ?></td><td><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $row['bytes'] ) ); ?></td></tr>
						<?php endforeach; ?>
						<tr><th scope="row"><?php esc_html_e( 'Total', 'lucidlab-db-cleanup' ); ?></th><td><strong><?php echo esc_html( number_format_i18n( $selection['findings'] ) ); ?></strong></td><td><strong><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $selection['bytes'] ) ); ?></strong></td></tr>
						</tbody>
					</table>
					<p class="lucidlab-dbc-hint"><?php esc_html_e( 'You can undo the cleanup under Backups & restore.', 'lucidlab-db-cleanup' ); ?></p>
					<div class="lucidlab-dbc-btn-row">
						<button type="button" class="button lldbc-btn button-primary" data-dbc-safe-yes><?php esc_html_e( 'Back up and clean all safe items', 'lucidlab-db-cleanup' ); ?></button>
						<button type="button" class="button lldbc-btn" data-dbc-safe-no><?php esc_html_e( 'Cancel', 'lucidlab-db-cleanup' ); ?></button>
					</div>
				</div>
				<div class="lucidlab-dbc-progress" data-dbc-progress hidden>
					<div class="lucidlab-dbc-progress-track"><div class="lucidlab-dbc-progress-bar" data-dbc-progress-bar></div></div>
					<p class="lucidlab-dbc-progress-text" data-dbc-progress-text aria-live="polite"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Confidence badge.
	 *
	 * @param string $confidence Confidence.
	 * @param string $prefix     Optional text before the label (a count).
	 *
	 * @return string HTML.
	 */
	private function confidence_badge( string $confidence, string $prefix = '' ): string {
		$map = array(
			'safe'   => array( 'safe', __( 'Safe', 'lucidlab-db-cleanup' ) ),
			'review' => array( 'warn', __( 'Review', 'lucidlab-db-cleanup' ) ),
			'risky'  => array( 'risk', __( 'Risky', 'lucidlab-db-cleanup' ) ),
			'info'   => array( 'info', __( 'Info', 'lucidlab-db-cleanup' ) ),
		);
		$def = $map[ $confidence ] ?? $map['info'];
		return '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-' . esc_attr( $def[0] ) . '">' . esc_html( trim( $prefix . ' ' . $def[1] ) ) . '</span>';
	}

	/**
	 * One category tab.
	 *
	 * @param string              $key  Category key.
	 * @param array<string,mixed> $scan Scan.
	 */
	private function render_category( string $key, array $scan ): void {
		$stored = $scan['categories'][ $key ] ?? null;
		$items  = is_array( $stored ) ? (array) $stored['items'] : array();
		$groups = array();
		foreach ( $items as $item ) {
			$group            = '' !== (string) $item['group'] ? (string) $item['group'] : __( 'Other', 'lucidlab-db-cleanup' );
			$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + 1;
		}
		$totals = $this->totals( $items );
		?>
		<div class="lucidlab-dbc-card" data-dbc-category="<?php echo esc_attr( $key ); ?>">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Findings', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Select what you want to remove. Safe = nothing can use it; Review = probably unused, check first; Risky = ownership unclear or still connected to something active. Nothing is pre-selected.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<?php $this->render_scan_controls( $scan, $key ); ?>
			<?php if ( ! is_array( $stored ) ) : ?>
				<div class="notice inline notice-info"><p><?php esc_html_e( 'This category has not been scanned yet.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php elseif ( ! $items ) : ?>
				<div class="notice inline notice-success"><p><?php esc_html_e( 'Nothing found in this category. Your database is clean here.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php else : ?>
				<div class="lucidlab-dbc-stats">
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( $totals['findings'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Findings', 'lucidlab-db-cleanup' ); ?></span></div>
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $totals['bytes'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Reclaimable (estimate)', 'lucidlab-db-cleanup' ); ?></span></div>
					<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $totals['safe_bytes'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Of which marked safe', 'lucidlab-db-cleanup' ); ?></span></div>
				</div>
				<div class="lucidlab-dbc-db-tabs" role="toolbar" aria-label="<?php esc_attr_e( 'Filter by group', 'lucidlab-db-cleanup' ); ?>">
					<button type="button" class="nav-tab nav-tab-active" data-dbc-group-filter="" aria-pressed="true"><?php esc_html_e( 'All', 'lucidlab-db-cleanup' ); ?><span class="lucidlab-dbc-db-tab-count"><?php echo esc_html( number_format_i18n( count( $items ) ) ); ?></span></button>
					<?php foreach ( $groups as $group => $count ) : ?>
						<button type="button" class="nav-tab" data-dbc-group-filter="<?php echo esc_attr( $group ); ?>" aria-pressed="false"><?php echo esc_html( $group ); ?><span class="lucidlab-dbc-db-tab-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span></button>
					<?php endforeach; ?>
				</div>
				<div class="lucidlab-dbc-controls">
					<button type="button" class="button lldbc-btn" data-dbc-select="safe"><?php esc_html_e( 'Select all safe', 'lucidlab-db-cleanup' ); ?></button>
					<button type="button" class="button lldbc-btn" data-dbc-select="none"><?php esc_html_e( 'Clear selection', 'lucidlab-db-cleanup' ); ?></button>
					<label><?php esc_html_e( 'Show', 'lucidlab-db-cleanup' ); ?>
						<select data-dbc-conf-filter>
							<option value=""><?php esc_html_e( 'All confidence levels', 'lucidlab-db-cleanup' ); ?></option>
							<option value="safe"><?php esc_html_e( 'Safe', 'lucidlab-db-cleanup' ); ?></option>
							<option value="review"><?php esc_html_e( 'Review', 'lucidlab-db-cleanup' ); ?></option>
							<option value="risky"><?php esc_html_e( 'Risky', 'lucidlab-db-cleanup' ); ?></option>
							<option value="info"><?php esc_html_e( 'Info only', 'lucidlab-db-cleanup' ); ?></option>
						</select>
					</label>
				</div>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table lucidlab-dbc-findings">
						<thead><tr>
							<th class="lucidlab-dbc-col-check"><input type="checkbox" data-dbc-select-visible aria-label="<?php esc_attr_e( 'Select all visible safe and review findings', 'lucidlab-db-cleanup' ); ?>" /></th>
							<th><?php esc_html_e( 'What it is', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Likely source', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Confidence', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Count', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php
						foreach ( $items as $item ) {
							$this->render_finding( $item );
						}
						?>
						</tbody>
					</table>
				</div>
				<div class="lucidlab-dbc-actionbar">
					<span class="lucidlab-dbc-selection" data-dbc-selection><?php esc_html_e( 'Nothing selected', 'lucidlab-db-cleanup' ); ?></span>
					<button type="button" class="button lldbc-btn lucidlab-dbc-danger" data-dbc-clean disabled><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Delete selected…', 'lucidlab-db-cleanup' ); ?></button>
				</div>
				<div class="lucidlab-dbc-inline-confirm notice inline notice-warning" data-dbc-confirm hidden>
					<p><strong data-dbc-confirm-count></strong></p>
					<p><?php esc_html_e( 'All affected rows (and whole tables) are first written to an SQL backup in a protected folder below wp-content/uploads/lucidlab-db-cleanup/. You can restore it from the Backups tab. Deleting cannot be undone without that backup. Afterwards every affected table is optimized and the database is measured before and after.', 'lucidlab-db-cleanup' ); ?></p>
					<label class="lucidlab-dbc-toggle" data-dbc-risky-ack-wrap hidden><input type="checkbox" data-dbc-risky-ack /> <?php esc_html_e( 'I checked the risky findings and want to delete them too.', 'lucidlab-db-cleanup' ); ?></label>
					<div class="lucidlab-dbc-btn-row">
						<button type="button" class="button lldbc-btn lucidlab-dbc-danger" data-dbc-confirm-yes><?php esc_html_e( 'Back up and delete', 'lucidlab-db-cleanup' ); ?></button>
						<button type="button" class="button lldbc-btn" data-dbc-confirm-no><?php esc_html_e( 'Cancel', 'lucidlab-db-cleanup' ); ?></button>
					</div>
				</div>
				<div class="lucidlab-dbc-progress" data-dbc-clean-progress hidden>
					<div class="lucidlab-dbc-progress-track"><div class="lucidlab-dbc-progress-bar" data-dbc-progress-bar></div></div>
					<p class="lucidlab-dbc-progress-text" data-dbc-progress-text aria-live="polite"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		if ( 'revisions' === $key ) {
			$this->render_revisions_keep();
		}
	}

	/**
	 * One finding row.
	 *
	 * @param array<string,mixed> $item Finding.
	 */
	private function render_finding( array $item ): void {
		$deletable = ! empty( $item['deletable'] );
		$conf      = (string) $item['confidence'];
		$group     = '' !== (string) $item['group'] ? (string) $item['group'] : __( 'Other', 'lucidlab-db-cleanup' );
		$state     = (string) $item['source_state'];
		$state_cls = array(
			'core'     => 'info',
			'active'   => 'safe',
			'inactive' => 'warn',
			'missing'  => 'risk',
		);
		?>
		<tr data-dbc-row data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" data-group="<?php echo esc_attr( $group ); ?>" data-conf="<?php echo esc_attr( $conf ); ?>" data-bytes="<?php echo esc_attr( (string) (int) $item['bytes'] ); ?>">
			<td class="lucidlab-dbc-col-check">
				<?php if ( $deletable ) : ?>
					<input type="checkbox" data-dbc-check value="<?php echo esc_attr( (string) $item['id'] ); ?>" aria-label="<?php echo esc_attr( (string) $item['label'] ); ?>" />
				<?php else : ?>
					<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Information only, never deleted', 'lucidlab-db-cleanup' ); ?>"></span>
				<?php endif; ?>
			</td>
			<td class="lucidlab-dbc-col-main">
				<strong><?php echo esc_html( (string) $item['label'] ); ?></strong>
				<?php if ( '' !== (string) $item['detail'] ) : ?>
					<p class="lucidlab-dbc-hint"><?php echo esc_html( (string) $item['detail'] ); ?></p>
				<?php endif; ?>
				<p class="lucidlab-dbc-reason"><?php echo esc_html( (string) $item['reason'] ); ?></p>
				<?php if ( ! empty( $item['samples'] ) ) : ?>
					<details class="lucidlab-dbc-samples">
						<summary>
							<?php
							$shown = count( (array) $item['samples'] );
							/* translators: %s: number of examples. */
							echo esc_html( sprintf( _n( 'Show %s example', 'Show %s examples', $shown, 'lucidlab-db-cleanup' ), number_format_i18n( $shown ) ) );
							?>
						</summary>
						<ul>
							<?php foreach ( (array) $item['samples'] as $sample ) : ?>
								<li>
									<span class="lucidlab-dbc-sample-label"><?php echo esc_html( (string) ( $sample['label'] ?? '' ) ); ?></span>
									<?php if ( ! empty( $sample['meta'] ) ) : ?>
										<span class="lucidlab-dbc-sample-meta"><?php echo esc_html( (string) $sample['meta'] ); ?></span>
									<?php endif; ?>
									<span class="lucidlab-dbc-sample-links">
										<?php if ( ! empty( $sample['view'] ) ) : ?>
											<a href="<?php echo esc_url( (string) $sample['view'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'lucidlab-db-cleanup' ); ?></a>
										<?php endif; ?>
										<?php if ( ! empty( $sample['edit'] ) ) : ?>
											<a href="<?php echo esc_url( (string) $sample['edit'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit', 'lucidlab-db-cleanup' ); ?></a>
										<?php endif; ?>
										<?php if ( ! empty( $sample['inspect'] ) ) : ?>
											<button type="button" class="button-link" data-dbc-inspect="<?php echo esc_attr( (string) $sample['inspect']['type'] ); ?>" data-dbc-inspect-id="<?php echo esc_attr( (string) $sample['inspect']['id'] ); ?>"><?php esc_html_e( 'Inspect', 'lucidlab-db-cleanup' ); ?></button>
										<?php endif; ?>
									</span>
								</li>
							<?php endforeach; ?>
							<?php if ( ! empty( $item['more'] ) ) : ?>
								<li class="lucidlab-dbc-hint">
									<?php
									/* translators: %s: number of further records. */
									echo esc_html( sprintf( __( '… and %s more', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $item['more'] ) ) );
									?>
								</li>
							<?php endif; ?>
						</ul>
					</details>
				<?php endif; ?>
			</td>
			<td class="lucidlab-dbc-col-source">
				<strong><?php echo esc_html( (string) $item['source'] ); ?></strong><br />
				<span class="lucidlab-dbc-badge lucidlab-dbc-badge-<?php echo esc_attr( $state_cls[ $state ] ?? 'info' ); ?>"><?php echo esc_html( (string) $item['source_label'] ); ?></span>
			</td>
			<td><?php echo $this->confidence_badge( $conf ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in confidence_badge(). ?></td>
			<td><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?>
				<?php if ( isset( $item['rows'] ) ) : ?>
					<p class="lucidlab-dbc-hint">
						<?php
						/* translators: %s: number of rows. */
						echo esc_html( sprintf( __( '%s rows', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $item['rows'] ) ) );
						?>
					</p>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( $deletable ? LucidLab_DBC_Helpers::bytes( (int) $item['bytes'] ) : '—' ); ?>
				<?php if ( ! empty( $item['autoload'] ) ) : ?>
					<p class="lucidlab-dbc-hint">
						<?php
						/* translators: %s: size. */
						echo esc_html( sprintf( __( '%s autoloaded', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( (int) $item['autoload'] ) ) );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Backups tab.
	 */
	private function render_backups(): void {
		$backups   = LucidLab_DBC_Backup::all();
		$dir       = LucidLab_DBC_Backup::dir();
		$protected = file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' );
		?>
		<div class="lucidlab-dbc-card">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Backups', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede">
					<?php esc_html_e( 'Restoring puts the deleted rows and tables back with INSERT IGNORE: rows that exist again are left untouched. Backups marked “Changed rows” (made before rows were changed instead of deleted) put the previous values back. Backups contain personal data from your database; delete them when you no longer need them.', 'lucidlab-db-cleanup' ); ?>
				</p>
			</div>
			<p class="lucidlab-dbc-hint">
				<?php
				/* translators: %s: folder path. */
				printf( esc_html__( 'Folder: %s', 'lucidlab-db-cleanup' ), '<code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code>' );
				echo ' · ';
				echo $protected ? esc_html__( 'Web access blocked with .htaccess, web.config and random file names. On nginx, add a deny rule for this folder.', 'lucidlab-db-cleanup' ) : esc_html__( 'The folder is created on the first cleanup.', 'lucidlab-db-cleanup' );
				?>
			</p>
			<?php if ( ! $backups ) : ?>
				<div class="notice inline notice-info"><p><?php esc_html_e( 'No backups yet. A backup is written automatically before every cleanup.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php else : ?>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table">
						<thead><tr>
							<th><?php esc_html_e( 'Created', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Cleaned findings', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Status', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $backups as $id => $backup ) : ?>
							<?php
							$labels = (array) $backup['labels'];
							$status = (string) $backup['status'];
							?>
							<tr data-dbc-backup="<?php echo esc_attr( (string) $id ); ?>">
								<th scope="row"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['created'] ) ); ?><p class="lucidlab-dbc-hint"><code><?php echo esc_html( (string) $id ); ?>.sql</code></p></th>
								<td>
									<?php if ( 'change' === ( $backup['kind'] ?? '' ) ) : ?>
										<span class="lucidlab-dbc-badge lucidlab-dbc-badge-info"><?php esc_html_e( 'Changed rows', 'lucidlab-db-cleanup' ); ?></span>
									<?php endif; ?>
									<?php echo esc_html( implode( ', ', array_slice( $labels, 0, 3 ) ) ); ?>
									<?php if ( count( $labels ) > 3 ) : ?>
										<p class="lucidlab-dbc-hint">
											<?php
											/* translators: %s: number of further findings. */
											echo esc_html( sprintf( __( '+ %s more', 'lucidlab-db-cleanup' ), number_format_i18n( count( $labels ) - 3 ) ) );
											?>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<?php
									/* translators: 1: number of rows, 2: number of tables, 3: file size. */
									echo esc_html( sprintf( __( '%1$s rows, %2$s tables · %3$s', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $backup['rows'] ), number_format_i18n( (int) $backup['tables'] ), LucidLab_DBC_Helpers::bytes( (int) $backup['size'] ) ) );
									?>
								</td>
								<td>
									<?php
									if ( 'restored' === $status ) {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-info">' . esc_html__( 'Restored', 'lucidlab-db-cleanup' ) . '</span>';
									} elseif ( 'complete' === $status ) {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-safe">' . esc_html__( 'Complete', 'lucidlab-db-cleanup' ) . '</span>';
									} else {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-warn">' . esc_html__( 'Incomplete', 'lucidlab-db-cleanup' ) . '</span>';
									}
									?>
								</td>
								<td class="lucidlab-dbc-backup-actions">
									<button type="button" class="button lldbc-btn" data-dbc-restore="<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Restore', 'lucidlab-db-cleanup' ); ?></button>
									<a class="button lldbc-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_dbc_download&backup=' . rawurlencode( (string) $id ) ), 'lucidlab_dbc_download_' . $id ) ); ?>"><?php esc_html_e( 'Download', 'lucidlab-db-cleanup' ); ?></a>
									<button type="button" class="button lldbc-btn lucidlab-dbc-danger-text" data-dbc-delete-backup="<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Delete', 'lucidlab-db-cleanup' ); ?></button>
									<p class="lucidlab-dbc-row-status" data-dbc-row-status aria-live="polite"></p>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		$this->render_retention();
	}

	/**
	 * Size in MB with two decimals (savings are always reported in MB).
	 *
	 * @param int $bytes Bytes.
	 *
	 * @return string
	 */
	private function mb( int $bytes ): string {
		/* translators: %s: number of megabytes. */
		return sprintf( __( '%s MB', 'lucidlab-db-cleanup' ), number_format_i18n( $bytes / MB_IN_BYTES, 2 ) );
	}

	/**
	 * Records of a snapshot scope, marked when estimates are included.
	 *
	 * @param array<string,mixed> $scope Scope.
	 *
	 * @return string
	 */
	private function records( array $scope ): string {
		return ( ! empty( $scope['estimated'] ) ? '≈ ' : '' ) . number_format_i18n( (int) ( $scope['rows'] ?? 0 ) );
	}

	/**
	 * Database tab: summary, last run, optimize action, cleanup log.
	 */
	private function render_database(): void {
		global $wpdb;
		$latest = LucidLab_DBC_Database::latest();
		?>
		<div class="lucidlab-dbc-card" data-dbc-database>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Database summary', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Size is data plus indexes as MySQL reports it. Free space (overhead) is allocated but unused space inside the table files; OPTIMIZE TABLE gives it back. Records are counted exactly with COUNT(*); only InnoDB tables with more than 500,000 rows use the estimate from information_schema, marked with ≈.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<?php if ( ! $latest ) : ?>
				<div class="notice inline notice-info"><p><?php esc_html_e( 'The database has not been measured yet.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php else : ?>
				<div class="lucidlab-dbc-summary">
					<?php
					$scopes = array(
						/* translators: %s: table prefix. */
						'site' => sprintf( __( 'This site (tables %s*)', 'lucidlab-db-cleanup' ), $wpdb->prefix ),
						/* translators: %s: database name. */
						'db'   => sprintf( __( 'Whole database “%s”', 'lucidlab-db-cleanup' ), DB_NAME ),
					);
					foreach ( $scopes as $key => $label ) :
						$scope = (array) ( $latest[ $key ] ?? array() );
						?>
						<div class="lucidlab-dbc-summary-block">
							<h4><?php echo esc_html( $label ); ?></h4>
							<div class="lucidlab-dbc-stats">
								<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( $this->mb( (int) $scope['data'] + (int) $scope['index'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Size (data + index)', 'lucidlab-db-cleanup' ); ?></span></div>
								<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( $this->mb( (int) $scope['free'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Free space / overhead', 'lucidlab-db-cleanup' ); ?></span></div>
								<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( (int) $scope['tables'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Tables', 'lucidlab-db-cleanup' ); ?></span></div>
								<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( $this->records( $scope ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Records', 'lucidlab-db-cleanup' ); ?></span></div>
							</div>
							<p class="lucidlab-dbc-hint">
								<?php
								$engines = array();
								foreach ( (array) $scope['engines'] as $engine => $count ) {
									$engines[] = $engine . ' ' . number_format_i18n( (int) $count );
								}
								/* translators: %s: list of storage engines with table counts. */
								echo esc_html( sprintf( __( 'Engines: %s.', 'lucidlab-db-cleanup' ), implode( ', ', $engines ) ) ) . ' ';
								if ( ! empty( $scope['estimated'] ) ) {
									/* translators: %s: number of tables. */
									echo esc_html( sprintf( _n( 'Records include the InnoDB estimate for %s large table.', 'Records include InnoDB estimates for %s large tables.', (int) $scope['estimated'], 'lucidlab-db-cleanup' ), number_format_i18n( (int) $scope['estimated'] ) ) );
								} else {
									esc_html_e( 'All records counted exactly.', 'lucidlab-db-cleanup' );
								}
								?>
							</p>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="lucidlab-dbc-hint">
					<?php
					/* translators: 1: date and time, 2: database server version. */
					echo esc_html( sprintf( __( 'Measured %1$s · %2$s.', 'lucidlab-db-cleanup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $latest['time'] ), (string) ( $latest['server'] ?? '' ) ) );
					if ( empty( $latest['file_per_table'] ) ) {
						echo ' ' . esc_html__( 'innodb_file_per_table is off: InnoDB tables share one system file, so free space is counted once and optimizing does not shrink that file.', 'lucidlab-db-cleanup' );
					}
					?>
				</p>
			<?php endif; ?>
			<div class="lucidlab-dbc-btn-row">
				<button type="button" class="button lldbc-btn button-primary" data-dbc-measure<?php echo $latest ? '' : ' data-dbc-auto-measure="1"'; ?>><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> <?php echo $latest ? esc_html__( 'Measure again', 'lucidlab-db-cleanup' ) : esc_html__( 'Measure now', 'lucidlab-db-cleanup' ); ?></button>
				<button type="button" class="button lldbc-btn" data-dbc-optimize><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Optimize all tables…', 'lucidlab-db-cleanup' ); ?></button>
			</div>
			<div class="lucidlab-dbc-inline-confirm notice inline notice-warning" data-dbc-optimize-confirm hidden>
				<p><strong>
					<?php
					/* translators: %s: table prefix. */
					echo esc_html( sprintf( __( 'Optimize every table with the prefix %s?', 'lucidlab-db-cleanup' ), $wpdb->prefix ) );
					?>
				</strong></p>
				<p><?php esc_html_e( 'Nothing is deleted. InnoDB tables are rebuilt (recreate + analyze), which needs free disk space about the size of the largest table and can take minutes for large tables; MyISAM and Aria tables are locked while they are optimized. Run it at a quiet moment. The database is measured before and after.', 'lucidlab-db-cleanup' ); ?></p>
				<div class="lucidlab-dbc-btn-row">
					<button type="button" class="button lldbc-btn button-primary" data-dbc-optimize-yes><?php esc_html_e( 'Optimize all tables', 'lucidlab-db-cleanup' ); ?></button>
					<button type="button" class="button lldbc-btn" data-dbc-optimize-no><?php esc_html_e( 'Cancel', 'lucidlab-db-cleanup' ); ?></button>
				</div>
			</div>
			<div class="lucidlab-dbc-progress" data-dbc-progress hidden>
				<div class="lucidlab-dbc-progress-track"><div class="lucidlab-dbc-progress-bar" data-dbc-progress-bar></div></div>
				<p class="lucidlab-dbc-progress-text" data-dbc-progress-text aria-live="polite"></p>
			</div>
		</div>
		<?php
		$this->render_last_run( false );
		$this->render_log();
	}

	/**
	 * Before/after result of the newest run.
	 *
	 * @param bool $compact Compact version for the overview tab.
	 */
	private function render_last_run( bool $compact ): void {
		$runs = LucidLab_DBC_Database::runs();
		$run  = $runs[0] ?? null;
		if ( ! is_array( $run ) || empty( $run['before'] ) || empty( $run['after'] ) ) {
			return;
		}
		$site    = LucidLab_DBC_Database::savings( (array) $run['before'], (array) $run['after'], 'site' );
		$all     = LucidLab_DBC_Database::savings( (array) $run['before'], (array) $run['after'], 'db' );
		$counted = (int) $run['rows'];
		?>
		<div class="lucidlab-dbc-card" data-dbc-last-run>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php echo 'optimize' === $run['type'] ? esc_html__( 'Last optimization: before and after', 'lucidlab-db-cleanup' ) : esc_html__( 'Last cleanup: before and after', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede">
					<?php
					/* translators: 1: date and time, 2: duration in seconds. */
					echo esc_html( sprintf( __( 'Finished %1$s, took %2$s seconds. “Before” was measured at the start, “after” once deleting and optimizing were done and ANALYZE TABLE had refreshed the statistics.', 'lucidlab-db-cleanup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['finished'] ), number_format_i18n( max( 0, (int) $run['finished'] - (int) $run['started'] ) ) ) );
					?>
				</p>
			</div>
			<div class="lucidlab-dbc-stats lucidlab-dbc-stats-five">
				<div class="lucidlab-dbc-stat lucidlab-dbc-stat-good"><span class="lucidlab-dbc-num"><?php echo esc_html( $this->mb( $site['disk_saved'] ) ); ?></span><span class="lucidlab-dbc-lbl">
					<?php
					/* translators: %s: percentage. */
					echo esc_html( sprintf( __( 'Saved incl. free space (%s%%)', 'lucidlab-db-cleanup' ), number_format_i18n( $site['disk_percent'], 1 ) ) );
					?>
				</span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( $this->mb( $site['saved'] ) ); ?></span><span class="lucidlab-dbc-lbl">
					<?php
					/* translators: %s: percentage. */
					echo esc_html( sprintf( __( 'Data + index saved (%s%%)', 'lucidlab-db-cleanup' ), number_format_i18n( $site['percent'], 1 ) ) );
					?>
				</span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( $site['tables'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Tables removed', 'lucidlab-db-cleanup' ); ?></span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( ( $site['estimated'] ? '≈ ' : '' ) . number_format_i18n( $site['rows'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Records removed', 'lucidlab-db-cleanup' ); ?></span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( count( (array) $run['optimized'] ) ) ); ?></span><span class="lucidlab-dbc-lbl">
					<?php
					/* translators: %s: number of InnoDB tables. */
					echo esc_html( sprintf( __( 'Tables optimized (%s InnoDB recreate + analyze)', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $run['recreated'] ) ) );
					?>
				</span></div>
			</div>
			<?php if ( ! $compact ) : ?>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table lucidlab-dbc-compare">
						<thead><tr>
							<th></th>
							<th><?php esc_html_e( 'Before', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'After', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Difference', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<?php
						foreach ( array(
							'site' => __( 'This site', 'lucidlab-db-cleanup' ),
							'db'   => __( 'Whole database', 'lucidlab-db-cleanup' ),
						) as $key => $label ) :
							$b   = (array) $run['before'][ $key ];
							$a   = (array) $run['after'][ $key ];
							$sav = 'site' === $key ? $site : $all;
							?>
							<tbody>
								<tr class="lucidlab-dbc-compare-group"><th colspan="4"><?php echo esc_html( $label ); ?></th></tr>
								<tr><th scope="row"><?php esc_html_e( 'Size (data + index)', 'lucidlab-db-cleanup' ); ?></th><td><?php echo esc_html( $this->mb( $sav['size_before'] ) ); ?></td><td><?php echo esc_html( $this->mb( $sav['size_after'] ) ); ?></td><td><?php echo esc_html( $this->mb( -$sav['saved'] ) . ' (' . number_format_i18n( -$sav['percent'], 1 ) . '%)' ); ?></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Free space / overhead', 'lucidlab-db-cleanup' ); ?></th><td><?php echo esc_html( $this->mb( (int) $b['free'] ) ); ?></td><td><?php echo esc_html( $this->mb( (int) $a['free'] ) ); ?></td><td><?php echo esc_html( $this->mb( (int) $a['free'] - (int) $b['free'] ) ); ?></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Total incl. free space', 'lucidlab-db-cleanup' ); ?></th><td><?php echo esc_html( $this->mb( $sav['disk_before'] ) ); ?></td><td><?php echo esc_html( $this->mb( $sav['disk_after'] ) ); ?></td><td><strong><?php echo esc_html( $this->mb( -$sav['disk_saved'] ) . ' (' . number_format_i18n( -$sav['disk_percent'], 1 ) . '%)' ); ?></strong></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Tables', 'lucidlab-db-cleanup' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $b['tables'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $a['tables'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( -$sav['tables'] ) ); ?></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Records', 'lucidlab-db-cleanup' ); ?></th><td><?php echo esc_html( $this->records( $b ) ); ?></td><td><?php echo esc_html( $this->records( $a ) ); ?></td><td><?php echo esc_html( ( $sav['estimated'] ? '≈ ' : '' ) . number_format_i18n( -$sav['rows'] ) ); ?></td></tr>
							</tbody>
						<?php endforeach; ?>
					</table>
				</div>
				<?php if ( 'cleanup' === $run['type'] ) : ?>
					<p class="lucidlab-dbc-hint">
						<?php
						/* translators: 1: number of rows, 2: number of tables. */
						echo esc_html( sprintf( __( 'The cleanup itself backed up and deleted %1$s rows and dropped %2$s tables. The record difference above can be a little different because WordPress, WooCommerce and other plugins keep writing (sessions, scheduled actions, caches) while the cleanup runs.', 'lucidlab-db-cleanup' ), number_format_i18n( $counted ), number_format_i18n( (int) $run['tables'] ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p><a href="<?php echo esc_url( $this->tab_url( 'database' ) ); ?>"><?php esc_html_e( 'Open the database summary and cleanup log', 'lucidlab-db-cleanup' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Cleanup log (history of runs).
	 */
	private function render_log(): void {
		$runs = LucidLab_DBC_Database::runs();
		?>
		<div class="lucidlab-dbc-card">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Cleanup log', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Every cleanup and optimization, newest first (the last 50 are kept). Sizes and savings are for this site’s tables.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<?php if ( ! $runs ) : ?>
				<div class="notice inline notice-info"><p><?php esc_html_e( 'No cleanups yet.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php else : ?>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table lucidlab-dbc-log">
						<thead><tr>
							<th><?php esc_html_e( 'Date', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'What', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Removed', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Size before → after', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Saved', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $runs as $run ) : ?>
							<?php
							$has = ! empty( $run['before'] ) && ! empty( $run['after'] );
							$sav = $has ? LucidLab_DBC_Database::savings( (array) $run['before'], (array) $run['after'], 'site' ) : null;
							?>
							<tr>
								<th scope="row" class="lucidlab-dbc-nowrap"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['finished'] ) ); ?></th>
								<td>
									<?php
									if ( 'optimize' === $run['type'] ) {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-info">' . esc_html__( 'Optimize', 'lucidlab-db-cleanup' ) . '</span> ';
										esc_html_e( 'All tables of this site', 'lucidlab-db-cleanup' );
									} else {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-safe">' . esc_html__( 'Cleanup', 'lucidlab-db-cleanup' ) . '</span> ';
										$labels = (array) $run['labels'];
										echo esc_html( implode( ', ', array_slice( $labels, 0, 3 ) ) );
										if ( (int) $run['findings'] > 3 ) {
											/* translators: %s: number of further findings. */
											echo ' ' . esc_html( sprintf( __( '+ %s more', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $run['findings'] - 3 ) ) );
										}
									}
									$this->render_run_details( $run );
									?>
								</td>
								<td class="lucidlab-dbc-nowrap">
									<?php
									/* translators: 1: number of rows, 2: number of tables. */
									echo esc_html( sprintf( __( '%1$s rows, %2$s tables', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $run['rows'] ), number_format_i18n( (int) $run['tables'] ) ) );
									?>
								</td>
								<td class="lucidlab-dbc-nowrap"><?php echo $sav ? esc_html( $this->mb( $sav['disk_before'] ) . ' → ' . $this->mb( $sav['disk_after'] ) ) : '–'; ?></td>
								<td class="lucidlab-dbc-nowrap"><?php echo $sav ? '<strong>' . esc_html( $this->mb( $sav['disk_saved'] ) ) . '</strong> (' . esc_html( number_format_i18n( $sav['disk_percent'], 1 ) ) . '%)' : '–'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Expandable details of a run: optimized tables, errors, skipped findings.
	 *
	 * @param array<string,mixed> $run Run.
	 */
	private function render_run_details( array $run ): void {
		$optimized = (array) $run['optimized'];
		$problems  = array_merge( (array) $run['errors'], (array) $run['skipped'] );
		if ( ! $optimized && ! $problems ) {
			return;
		}
		$actions = array(
			'recreate'   => array( 'info', __( 'Recreate + analyze', 'lucidlab-db-cleanup' ) ),
			'optimize'   => array( 'safe', __( 'Optimized', 'lucidlab-db-cleanup' ) ),
			'up_to_date' => array( 'safe', __( 'Up to date', 'lucidlab-db-cleanup' ) ),
			'skipped'    => array( 'warn', __( 'Skipped', 'lucidlab-db-cleanup' ) ),
			'unknown'    => array( 'warn', __( 'Finished in background', 'lucidlab-db-cleanup' ) ),
			'error'      => array( 'risk', __( 'Error', 'lucidlab-db-cleanup' ) ),
		);
		?>
		<details class="lucidlab-dbc-samples">
			<summary>
				<?php
				/* translators: 1: number of optimized tables, 2: number of problems. */
				echo esc_html( sprintf( __( 'Details: %1$s tables optimized, %2$s notes', 'lucidlab-db-cleanup' ), number_format_i18n( count( $optimized ) ), number_format_i18n( count( $problems ) ) ) );
				?>
			</summary>
			<?php if ( $optimized ) : ?>
				<table class="lucidlab-dbc-table lucidlab-dbc-table-compact">
					<thead><tr>
						<th><?php esc_html_e( 'Table', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Engine', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Result', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Before → after', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Time', 'lucidlab-db-cleanup' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $optimized as $result ) : ?>
						<?php $action = $actions[ (string) ( $result['action'] ?? '' ) ] ?? $actions['unknown']; ?>
						<tr>
							<td><code><?php echo esc_html( (string) $result['table'] ); ?></code></td>
							<td><?php echo esc_html( (string) $result['engine'] ); ?></td>
							<td><span class="lucidlab-dbc-badge lucidlab-dbc-badge-<?php echo esc_attr( $action[0] ); ?>" title="<?php echo esc_attr( (string) $result['message'] ); ?>"><?php echo esc_html( $action[1] ); ?></span></td>
							<td class="lucidlab-dbc-nowrap"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( (int) $result['before'] ) . ' → ' . LucidLab_DBC_Helpers::bytes( (int) $result['after'] ) ); ?></td>
							<td class="lucidlab-dbc-nowrap">
								<?php
								/* translators: %s: seconds. */
								echo esc_html( sprintf( __( '%s s', 'lucidlab-db-cleanup' ), number_format_i18n( (float) $result['seconds'], 2 ) ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $problems ) : ?>
				<ul>
					<?php foreach ( $problems as $problem ) : ?>
						<li><?php echo esc_html( (string) $problem ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * "Full backup" tab: create, list, restore, upload, settings.
	 */
	private function render_fullbackup(): void {
		global $wpdb;
		LucidLab_DBC_Fullbackup::clean_parts();
		$settings = LucidLab_DBC_Automation::settings();
		$totals   = LucidLab_DBC_Fullbackup::scope_totals();
		$backups  = LucidLab_DBC_Fullbackup::all();
		$job      = LucidLab_DBC_Fullbackup::job();
		$restore  = LucidLab_DBC_Restore::state();
		$keep     = max( 0, (int) apply_filters( 'lucidlab_dbc_full_keep', 3 ) ); // Documented in LucidLab_DBC_Fullbackup::complete().
		$last     = get_option( LucidLab_DBC_Fullbackup::LAST_RESTORE_OPTION );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$siteurl  = untrailingslashit( (string) get_option( 'siteurl' ) );
		$dir      = LucidLab_DBC_Backup::dir();
		$scopes   = array(
			/* translators: 1: database name, 2: number of tables, 3: size. */
			'all'    => sprintf( __( 'All tables of database “%1$s” (%2$s tables, about %3$s)', 'lucidlab-db-cleanup' ), DB_NAME, number_format_i18n( $totals['all']['tables'] ), LucidLab_DBC_Helpers::bytes( $totals['all']['bytes'] ) ),
			/* translators: 1: table prefix, 2: number of tables, 3: size. */
			'prefix' => sprintf( __( 'Only this site’s tables %1$s* (%2$s tables, about %3$s)', 'lucidlab-db-cleanup' ), $wpdb->prefix, number_format_i18n( $totals['prefix']['tables'] ), LucidLab_DBC_Helpers::bytes( $totals['prefix']['bytes'] ) ),
		);
		?>
		<?php if ( ! empty( $restore['phase'] ) && 'done' !== $restore['phase'] ) : ?>
			<?php $rp = LucidLab_DBC_Restore::progress( $restore ); ?>
			<div class="lucidlab-dbc-card" data-dbc-restore-pending>
				<div class="notice inline notice-warning">
					<p><strong>
						<?php
						/* translators: %s: backup file name. */
						echo esc_html( sprintf( __( 'A restore of %s is not finished.', 'lucidlab-db-cleanup' ), $rp['label'] ) );
						?>
					</strong></p>
					<p><?php esc_html_e( 'The live tables have not been replaced yet; the data is being imported into temporary tables. Continue the restore, or cancel it to remove the temporary tables without changing anything.', 'lucidlab-db-cleanup' ); ?></p>
					<?php if ( $rp['errors'] ) : ?>
						<ul class="lucidlab-dbc-warn-list">
							<?php foreach ( $rp['errors'] as $error ) : ?>
								<li><?php echo esc_html( (string) $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<div class="lucidlab-dbc-btn-row">
					<button type="button" class="button lldbc-btn button-primary" data-dbc-restore-continue><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e( 'Continue the restore', 'lucidlab-db-cleanup' ); ?></button>
					<button type="button" class="button lldbc-btn lucidlab-dbc-danger-text" data-dbc-restore-cancel><?php esc_html_e( 'Cancel the restore', 'lucidlab-db-cleanup' ); ?></button>
				</div>
				<?php $this->render_ack_box(); ?>
				<?php $this->render_progress_box(); ?>
			</div>
		<?php endif; ?>

		<div class="lucidlab-dbc-card" data-dbc-full-card>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Create a full backup', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Writes the structure and data of every table to a gzip-compressed .sql.gz file in the protected backup folder, table by table and 1,000 rows at a time, so large databases do not hit time limits. You can leave the page open and keep working in another tab. The file can also be imported with phpMyAdmin or the mysql client.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<fieldset class="lucidlab-dbc-field">
				<legend><?php esc_html_e( 'What to back up', 'lucidlab-db-cleanup' ); ?></legend>
				<?php foreach ( $scopes as $value => $label ) : ?>
					<label class="lucidlab-dbc-radio"><input type="radio" name="lucidlab-dbc-full-scope" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['full_scope'], $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
				<?php endforeach; ?>
			</fieldset>
			<div class="lucidlab-dbc-btn-row">
				<button type="button" class="button lldbc-btn button-primary" data-dbc-full-create<?php disabled( ! empty( $restore['phase'] ) && 'done' !== $restore['phase'] ); ?>><span class="dashicons dashicons-database-export" aria-hidden="true"></span> <?php esc_html_e( 'Create full backup', 'lucidlab-db-cleanup' ); ?></button>
				<span class="lucidlab-dbc-hint">
					<?php
					if ( $keep ) {
						/* translators: %s: number of backups. */
						echo esc_html( sprintf( __( 'The newest %s full backups are kept; older ones are deleted automatically.', 'lucidlab-db-cleanup' ), number_format_i18n( $keep ) ) );
					} else {
						esc_html_e( 'All full backups are kept.', 'lucidlab-db-cleanup' );
					}
					?>
				</span>
			</div>
			<?php if ( 'running' === ( $job['status'] ?? '' ) ) : ?>
				<div class="notice inline notice-info" data-dbc-full-pending>
					<p>
						<?php
						$jp = LucidLab_DBC_Fullbackup::progress( $job );
						/* translators: 1: table number, 2: number of tables. */
						echo esc_html( sprintf( __( 'A full backup was started but is not finished (table %1$s of %2$s). Continue it, or discard the partial file.', 'lucidlab-db-cleanup' ), number_format_i18n( $jp['index'] ), number_format_i18n( $jp['tables'] ) ) );
						?>
					</p>
					<div class="lucidlab-dbc-btn-row">
						<button type="button" class="button lldbc-btn" data-dbc-full-continue><?php esc_html_e( 'Continue', 'lucidlab-db-cleanup' ); ?></button>
						<button type="button" class="button lldbc-btn lucidlab-dbc-danger-text" data-dbc-full-discard><?php esc_html_e( 'Discard', 'lucidlab-db-cleanup' ); ?></button>
					</div>
				</div>
			<?php endif; ?>
			<?php $this->render_progress_box(); ?>
		</div>

		<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
			<div class="lucidlab-dbc-card">
				<div class="lucidlab-dbc-card-head">
					<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Last restore', 'lucidlab-db-cleanup' ); ?></h3>
				</div>
				<p>
					<?php
					/* translators: 1: date and time, 2: file, 3: tables, 4: replaced tables, 5: new tables, 6: seconds. */
					echo esc_html( sprintf( __( '%1$s: %2$s restored, %3$s tables (%4$s replaced, %5$s added) in %6$s s.', 'lucidlab-db-cleanup' ), wp_date( $format, (int) $last['time'] ), (string) $last['label'], number_format_i18n( (int) $last['tables'] ), number_format_i18n( (int) $last['replaced'] ), number_format_i18n( (int) $last['added'] ), number_format_i18n( (float) $last['seconds'], 1 ) ) );
					if ( ! empty( $last['removed'] ) ) {
						/* translators: %s: number of tables. */
						echo ' ' . esc_html( sprintf( __( '%s tables that were not in the backup were removed.', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $last['removed'] ) ) );
					}
					if ( ! empty( $last['skipped'] ) ) {
						/* translators: %s: number of skipped statements. */
						echo ' ' . esc_html( sprintf( __( '%s statements were skipped (not table data).', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $last['skipped'] ) ) );
					}
					?>
				</p>
				<?php if ( ! empty( $last['errors'] ) ) : ?>
					<ul class="lucidlab-dbc-warn-list">
						<?php foreach ( (array) $last['errors'] as $error ) : ?>
							<li><?php echo esc_html( (string) $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="lucidlab-dbc-card">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Full backups', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'A restore first imports the backup into temporary tables while the site keeps running, then replaces the live tables in one step. Everything written to the database after the backup (orders, comments, users, settings) is lost. Backups contain personal data; keep the downloads safe.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<p class="lucidlab-dbc-hint">
				<?php
				/* translators: %s: folder path. */
				printf( esc_html__( 'Folder: %s', 'lucidlab-db-cleanup' ), '<code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code>' );
				echo ' · ' . esc_html__( 'Web access is blocked with .htaccess, web.config, index.php and random file names. On nginx, add a deny rule for this folder.', 'lucidlab-db-cleanup' );
				?>
			</p>
			<?php if ( ! $backups ) : ?>
				<div class="notice inline notice-info"><p><?php esc_html_e( 'No full backups yet.', 'lucidlab-db-cleanup' ); ?></p></div>
			<?php else : ?>
				<div class="lucidlab-dbc-table-scroll">
					<table class="lucidlab-dbc-table">
						<thead><tr>
							<th><?php esc_html_e( 'Created', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Type', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'lucidlab-db-cleanup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $backups as $id => $entry ) : ?>
							<?php
							$upload   = 'upload' === ( $entry['type'] ?? '' );
							$complete = 'complete' === ( $entry['status'] ?? '' );
							$from     = untrailingslashit( (string) ( $entry['siteurl'] ?? '' ) );
							$mismatch = '' !== $from && strtolower( $from ) !== strtolower( $siteurl );
							$file     = $id . '.' . ( 'sql' === ( $entry['ext'] ?? '' ) ? 'sql' : 'sql.gz' );
							$label    = $upload ? (string) ( $entry['name'] ?? $file ) : $file;
							?>
							<tr data-dbc-full-row="<?php echo esc_attr( (string) $id ); ?>" data-label="<?php echo esc_attr( $label ); ?>" data-type="<?php echo esc_attr( $upload ? 'upload' : 'full' ); ?>" data-scope="<?php echo esc_attr( (string) ( $entry['scope'] ?? '' ) ); ?>" data-siteurl="<?php echo esc_attr( $from ); ?>" data-mismatch="<?php echo $mismatch ? '1' : '0'; ?>" data-created="<?php echo esc_attr( wp_date( $format, (int) $entry['created'] ) ); ?>">
								<th scope="row" class="lucidlab-dbc-nowrap"><?php echo esc_html( wp_date( $format, (int) $entry['created'] ) ); ?><p class="lucidlab-dbc-hint"><code><?php echo esc_html( $label ); ?></code></p></th>
								<td>
									<?php
									if ( $upload ) {
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-info">' . esc_html__( 'Uploaded', 'lucidlab-db-cleanup' ) . '</span>';
									} else {
										$triggers = array(
											'manual'    => __( 'Manual', 'lucidlab-db-cleanup' ),
											'safety'    => __( 'Safety (before restore)', 'lucidlab-db-cleanup' ),
											'scheduled' => __( 'Before scheduled cleanup', 'lucidlab-db-cleanup' ),
											'convert'   => __( 'Before table conversion', 'lucidlab-db-cleanup' ),
											'replace'   => __( 'Before search & replace', 'lucidlab-db-cleanup' ),
											'index'     => __( 'Before index change', 'lucidlab-db-cleanup' ),
										);
										echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-safe">' . esc_html( $triggers[ (string) ( $entry['trigger'] ?? 'manual' ) ] ?? $triggers['manual'] ) . '</span>';
									}
									if ( ! $complete ) {
										echo ' <span class="lucidlab-dbc-badge lucidlab-dbc-badge-warn">' . esc_html__( 'Incomplete', 'lucidlab-db-cleanup' ) . '</span>';
									}
									if ( $mismatch ) {
										/* translators: %s: site URL. */
										echo '<p class="lucidlab-dbc-hint"><span class="lucidlab-dbc-badge lucidlab-dbc-badge-risk">' . esc_html__( 'Other site URL', 'lucidlab-db-cleanup' ) . '</span> <code>' . esc_html( $from ) . '</code></p>';
									}
									?>
								</td>
								<td>
									<?php
									if ( $upload && empty( $entry['own'] ) ) {
										esc_html_e( 'SQL dump (contents are checked during the restore)', 'lucidlab-db-cleanup' );
									} else {
										$scope_label = 'prefix' === ( $entry['scope'] ?? '' ) ? sprintf( /* translators: %s: table prefix. */ __( 'tables %s*', 'lucidlab-db-cleanup' ), (string) ( $entry['prefix'] ?? '' ) ) : __( 'all tables', 'lucidlab-db-cleanup' );
										if ( 'tables' === ( $entry['scope'] ?? '' ) ) {
											$scope_label = implode( ', ', array_slice( (array) ( $entry['only'] ?? array() ), 0, 5 ) );
										}
										if ( $upload ) {
											/* translators: 1: number of tables, 2: scope. */
											echo esc_html( sprintf( __( '%1$s tables · %2$s', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $entry['tables'] ), $scope_label ) );
										} else {
											/* translators: 1: number of tables, 2: number of records, 3: scope. */
											echo esc_html( sprintf( __( '%1$s tables, %2$s records · %3$s', 'lucidlab-db-cleanup' ), number_format_i18n( (int) $entry['tables'] ), number_format_i18n( (int) $entry['rows'] ), $scope_label ) );
										}
										if ( ! empty( $entry['seconds'] ) ) {
											/* translators: %s: seconds. */
											echo '<p class="lucidlab-dbc-hint">' . esc_html( sprintf( __( 'Written in %s s', 'lucidlab-db-cleanup' ), number_format_i18n( (float) $entry['seconds'], 1 ) ) ) . '</p>';
										}
									}
									?>
								</td>
								<td class="lucidlab-dbc-nowrap"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( (int) $entry['size'] ) ); ?></td>
								<td>
									<div class="lucidlab-dbc-backup-actions">
										<?php if ( $complete ) : ?>
											<button type="button" class="button lldbc-btn" data-dbc-full-restore="<?php echo esc_attr( (string) $id ); ?>"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Restore', 'lucidlab-db-cleanup' ); ?></button>
											<a class="button lldbc-btn" data-dbc-download href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_dbc_full_download&backup=' . rawurlencode( (string) $id ) ), 'lucidlab_dbc_full_download_' . $id ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download', 'lucidlab-db-cleanup' ); ?></a>
										<?php endif; ?>
										<button type="button" class="button lldbc-btn lucidlab-dbc-danger-text" data-dbc-full-delete="<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Delete', 'lucidlab-db-cleanup' ); ?></button>
										<p class="lucidlab-dbc-row-status" data-dbc-row-status aria-live="polite"></p>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php
		if ( ! lucidlab_dbc_is_pro() ) {
			self::pro_card(
				__( 'Upload & restore, retention, scheduled backups', 'lucidlab-db-cleanup' ),
				__( 'Restore an SQL dump you upload (from phpMyAdmin, mysqldump or another site), choose how many full backups are kept, and make a full backup automatically before every scheduled cleanup.', 'lucidlab-db-cleanup' )
			);
		}
		?>

		<dialog class="lucidlab-dbc-dialog lucidlab-dbc-restore-dialog" data-dbc-restore-dialog aria-labelledby="lucidlab-dbc-restore-title">
			<div class="lucidlab-dbc-dialog-head"><strong id="lucidlab-dbc-restore-title"><?php esc_html_e( 'Restore the database', 'lucidlab-db-cleanup' ); ?></strong><button type="button" class="button lldbc-btn" data-dbc-restore-close><?php esc_html_e( 'Close', 'lucidlab-db-cleanup' ); ?></button></div>
			<div class="lucidlab-dbc-dialog-body">
				<p><strong data-dbc-restore-what></strong></p>
				<div class="notice inline notice-error" data-dbc-restore-url hidden><p data-dbc-restore-url-text></p></div>
				<div class="notice inline notice-warning"><p><?php esc_html_e( 'The current tables are replaced by the tables in the backup. Everything that was written after the backup (orders, customers, comments, settings) is lost. You may have to log in again afterwards with the user name and password stored in the backup. Large backups need free database space for a temporary copy.', 'lucidlab-db-cleanup' ); ?></p></div>
				<p class="lucidlab-dbc-field"><label><input type="checkbox" data-dbc-restore-safety checked /> <?php esc_html_e( 'First make a full backup of the current database (recommended)', 'lucidlab-db-cleanup' ); ?></label></p>
				<p class="lucidlab-dbc-field" data-dbc-restore-extra-wrap><label><input type="checkbox" data-dbc-restore-extra /> <?php esc_html_e( 'Also remove tables that are not in the backup (tables created after it), so the database matches the backup exactly', 'lucidlab-db-cleanup' ); ?></label></p>
				<p class="lucidlab-dbc-field">
					<label for="lucidlab-dbc-restore-confirm">
						<?php
						/* translators: %s: the word RESTORE. */
						printf( esc_html__( 'Type %s to confirm', 'lucidlab-db-cleanup' ), '<code>RESTORE</code>' );
						?>
					</label>
					<input type="text" id="lucidlab-dbc-restore-confirm" autocomplete="off" spellcheck="false" data-dbc-restore-confirm />
				</p>
				<div class="lucidlab-dbc-btn-row">
					<button type="button" class="button lldbc-btn lucidlab-dbc-danger" data-dbc-restore-go disabled><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Restore database', 'lucidlab-db-cleanup' ); ?></button>
					<button type="button" class="button lldbc-btn" data-dbc-restore-close><?php esc_html_e( 'Cancel', 'lucidlab-db-cleanup' ); ?></button>
				</div>
				<?php $this->render_ack_box(); ?>
				<?php $this->render_progress_box(); ?>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Small "Pro" badge.
	 *
	 * @return string HTML.
	 */
	public static function pro_badge(): string {
		return '<span class="lucidlab-dbc-pro-badge">' . esc_html__( 'Pro', 'lucidlab-db-cleanup' ) . '</span>';
	}

	/**
	 * "Get Pro" link.
	 *
	 * @param string $context Where the link is shown (added to the URL for statistics).
	 *
	 * @return string HTML.
	 */
	private static function pro_link( string $context ): string {
		$url = lucidlab_dbc_pro_url();
		if ( '#' !== $url ) {
			$url = add_query_arg( 'utm_content', rawurlencode( $context ), $url );
		}
		return '<a class="button lldbc-btn lucidlab-dbc-pro-link" href="' . esc_url( $url ) . '"' . ( '#' === $url ? '' : ' target="_blank" rel="noopener"' ) . '><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> ' . esc_html__( 'Get Pro', 'lucidlab-db-cleanup' ) . '</a>';
	}

	/**
	 * A disabled card that describes a Pro feature.
	 *
	 * @param string            $title  Title.
	 * @param string            $text   Description.
	 * @param array<int,string> $points Bullet points.
	 */
	public static function pro_card( string $title, string $text, array $points = array() ): void {
		?>
		<div class="lucidlab-dbc-card lucidlab-dbc-card-locked" aria-disabled="true">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php echo esc_html( $title ); ?> <?php echo wp_kses_post( self::pro_badge() ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php echo esc_html( $text ); ?></p>
			</div>
			<?php if ( $points ) : ?>
				<ul class="lucidlab-dbc-pro-points">
					<?php foreach ( $points as $point ) : ?>
						<li><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="lucidlab-dbc-btn-row">
				<?php echo self::pro_link( sanitize_title( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pro_link(). ?>
				<span class="lucidlab-dbc-hint">
					<?php
					echo lucidlab_dbc_pro_installed()
						? esc_html__( 'The Pro add-on is installed: activate its license under Database Cleaner → License.', 'lucidlab-db-cleanup' )
						: esc_html__( 'Available in the Lucid Lab Database Cleaner Pro add-on. Everything on the other tabs keeps working without it.', 'lucidlab-db-cleanup' );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * A locked Pro tab.
	 *
	 * @param string $tab Tab.
	 */
	private function render_locked_tab( string $tab ): void {
		$feature = self::pro_features()[ $tab ];
		self::pro_card( $feature['label'], $feature['text'], $feature['points'] );
	}

	/**
	 * Dismissible note about Pro on the overview (once per user, never on other screens).
	 */
	private function render_pro_notice(): void {
		if ( lucidlab_dbc_is_pro() || get_user_meta( get_current_user_id(), 'lucidlab_dbc_pro_dismissed', true ) ) {
			return;
		}
		?>
		<div class="notice inline notice-info lucidlab-dbc-pro-notice" data-dbc-pro-notice>
			<p>
				<?php echo wp_kses_post( self::pro_badge() ); ?>
				<?php esc_html_e( 'Scheduled cleanups, search & replace, the full autoload checker with autoload switches, table conversion, index tools, growth charts and health alerts are available in the Pro add-on.', 'lucidlab-db-cleanup' ); ?>
				<a href="<?php echo esc_url( lucidlab_dbc_pro_url() ); ?>"<?php echo '#' === lucidlab_dbc_pro_url() ? '' : ' target="_blank" rel="noopener"'; ?>><?php esc_html_e( 'Learn more', 'lucidlab-db-cleanup' ); ?></a>
			</p>
			<button type="button" class="button-link lucidlab-dbc-pro-dismiss" data-dbc-pro-dismiss><?php esc_html_e( 'Dismiss', 'lucidlab-db-cleanup' ); ?></button>
		</div>
		<?php
	}

	/**
	 * What the install ping sends, in one sentence (notice and Settings tab).
	 *
	 * @return string
	 */
	private static function ping_text(): string {
		return sprintf(
			/* translators: %s: address the install ping is sent to. */
			__( 'When it is on, this site sends its address (home URL), the plugin version, the plugin name and a random install ID to %s: right away, after each plugin update and once a week. Lucid Lab uses it only to count active installs. Nothing else is sent: no users, e-mail addresses, content, database data or settings. It is off by default and you can turn it off at any time on the Settings tab.', 'lucidlab-db-cleanup' ),
			LucidLab_DBC_Site_Ping::ENDPOINT
		);
	}

	/**
	 * Dismissible opt-in notice for the install ping (this plugin's screen only, once per user).
	 */
	private function render_ping_notice(): void {
		if ( ! LucidLab_DBC_Site_Ping::show_notice() ) {
			return;
		}
		?>
		<div class="notice inline notice-info lucidlab-dbc-ping-notice" data-dbc-ping-notice data-testid="ping-notice">
			<div class="lucidlab-dbc-ping-notice-text">
				<p><strong><?php esc_html_e( 'Help Lucid Lab count active installs?', 'lucidlab-db-cleanup' ); ?></strong></p>
				<p><?php echo esc_html( self::ping_text() ); ?> <a href="<?php echo esc_url( 'https://lucidlab.eu/privacy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lucid Lab privacy policy', 'lucidlab-db-cleanup' ); ?></a></p>
				<p class="lucidlab-dbc-hint" data-dbc-ping-notice-message role="status" aria-live="polite"></p>
			</div>
			<div class="lucidlab-dbc-btn-row">
				<button type="button" class="button lldbc-btn button-primary" data-dbc-ping-yes data-testid="ping-notice-yes"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Yes, count this site', 'lucidlab-db-cleanup' ); ?></button>
				<button type="button" class="button lldbc-btn" data-dbc-ping-no data-testid="ping-notice-no"><?php esc_html_e( 'No thanks', 'lucidlab-db-cleanup' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings tab: scan rules and the install ping opt-in.
	 */
	private function render_settings(): void {
		$on       = LucidLab_DBC_Site_Ping::opted_in();
		$last     = LucidLab_DBC_Site_Ping::last_time();
		$settings = LucidLab_DBC_Automation::settings();
		?>
		<form class="lucidlab-dbc-card" data-dbc-scanrules data-testid="settings-scanrules">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Scan rules', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Completed, canceled and failed scheduled actions (Action Scheduler) are only listed when they are older than this. Pending and running actions are never listed. The new age is used from the next scan.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<div class="lucidlab-dbc-field-row">
				<p class="lucidlab-dbc-field">
					<label for="lucidlab-dbc-as-days"><?php esc_html_e( 'Scheduled actions older than … days', 'lucidlab-db-cleanup' ); ?></label>
					<input type="number" id="lucidlab-dbc-as-days" name="as_days" min="<?php echo esc_attr( (string) LucidLab_DBC_Automation::AS_DAYS_MIN ); ?>" max="<?php echo esc_attr( (string) LucidLab_DBC_Automation::AS_DAYS_MAX ); ?>" value="<?php echo esc_attr( (string) $settings['as_days'] ); ?>" data-testid="settings-as-days" />
					<span class="lucidlab-dbc-hint">
						<?php
						/* translators: 1: smallest number of days, 2: largest number of days. */
						echo esc_html( sprintf( __( 'From %1$s to %2$s days. Default 30, the period Action Scheduler itself keeps finished actions.', 'lucidlab-db-cleanup' ), number_format_i18n( LucidLab_DBC_Automation::AS_DAYS_MIN ), number_format_i18n( LucidLab_DBC_Automation::AS_DAYS_MAX ) ) );
						?>
					</span>
				</p>
			</div>
			<div class="lucidlab-dbc-btn-row">
				<button type="submit" class="button lldbc-btn button-primary" data-testid="settings-scanrules-save"><?php esc_html_e( 'Save', 'lucidlab-db-cleanup' ); ?></button>
				<span class="lucidlab-dbc-hint" data-dbc-scanrules-message role="status" aria-live="polite"></span>
			</div>
		</form>
		<form class="lucidlab-dbc-card" data-dbc-ping-form data-testid="settings-ping">
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Install count (optional)', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php echo esc_html( self::ping_text() ); ?></p>
			</div>
			<p class="lucidlab-dbc-field">
				<label><input type="checkbox" name="install_ping" value="1" <?php checked( $on ); ?> data-testid="settings-ping-checkbox" /> <strong><?php esc_html_e( 'Tell Lucid Lab that this site uses the plugin', 'lucidlab-db-cleanup' ); ?></strong></label>
			</p>
			<p class="lucidlab-dbc-hint">
				<a href="<?php echo esc_url( 'https://lucidlab.eu/privacy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lucid Lab privacy policy', 'lucidlab-db-cleanup' ); ?></a>
				<?php if ( $on && $last ) : ?>
					<span aria-hidden="true">·</span>
					<?php
					/* translators: %s: date and time of the last install ping. */
					echo esc_html( sprintf( __( 'Last sent: %s.', 'lucidlab-db-cleanup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ) );
					?>
				<?php endif; ?>
			</p>
			<div class="lucidlab-dbc-btn-row">
				<button type="submit" class="button lldbc-btn button-primary" data-testid="settings-ping-save"><?php esc_html_e( 'Save', 'lucidlab-db-cleanup' ); ?></button>
				<span class="lucidlab-dbc-hint" data-dbc-ping-message role="status" aria-live="polite"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * Read-only preview of the 25 largest autoloaded options on the Options & autoload tab. The full
	 * Autoload checker (50 options, autoload switches with undo) is part of the Pro add-on.
	 *
	 * @param bool $with_cells Add the Autoload column that add-ons can fill (lucidlab_dbc_autoload_cell).
	 */
	private function render_autoload_preview( bool $with_cells = false ): void {
		$report = LucidLab_DBC_Insights::autoload( 25 );
		?>
		<div class="lucidlab-dbc-card" data-testid="autoload-preview"<?php echo $with_cells ? ' data-dbc-autoload' : ''; ?>>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Largest autoloaded options', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'A read-only preview of the 25 largest options that WordPress loads on every request. Settings of removed plugins can be deleted with the findings above.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<div class="lucidlab-dbc-stats">
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"<?php echo $with_cells ? ' data-dbc-autoload-total' : ''; ?>><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $report['total'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Total autoload size', 'lucidlab-db-cleanup' ); ?></span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( $report['count'] ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Autoloaded options', 'lucidlab-db-cleanup' ); ?></span></div>
			</div>
			<?php if ( $report['warn'] ) : ?>
				<div class="notice inline notice-warning"><p>
					<?php
					/* translators: %s: size. */
					echo esc_html( sprintf( __( 'The autoloaded options take %s, more than the 800 KB that WordPress recommends. Delete settings of removed plugins with the findings above. The Autoload checker of the Pro add-on can turn autoload off per option.', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( $report['total'] ) ) );
					?>
				</p></div>
			<?php else : ?>
				<div class="notice inline notice-success"><p>
					<?php
					/* translators: %s: size. */
					echo esc_html( sprintf( __( 'The autoloaded options take %s, below the 800 KB that WordPress recommends. Nothing to do.', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( $report['total'] ) ) );
					?>
				</p></div>
			<?php endif; ?>
			<div class="lucidlab-dbc-table-scroll">
				<table class="lucidlab-dbc-table">
					<thead><tr>
						<th><?php esc_html_e( 'Option', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Likely source', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Share', 'lucidlab-db-cleanup' ); ?></th>
						<?php if ( $with_cells ) : ?>
							<th><?php esc_html_e( 'Autoload', 'lucidlab-db-cleanup' ); ?></th>
						<?php endif; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<tr data-dbc-option="<?php echo esc_attr( $row['name'] ); ?>">
							<th scope="row"><code><?php echo esc_html( $row['name'] ); ?></code></th>
							<td><?php echo esc_html( '' !== $row['owner'] ? $row['owner'] : __( 'Unknown', 'lucidlab-db-cleanup' ) ); ?></td>
							<td class="lucidlab-dbc-nowrap"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $row['bytes'] ) ); ?></td>
							<td class="lucidlab-dbc-nowrap"><?php echo esc_html( number_format_i18n( $row['share'], 1 ) ); ?>%</td>
							<?php if ( $with_cells ) : ?>
								<td>
									<?php
									/**
									 * Filters the autoload column (an add-on can add a switch).
									 *
									 * @param string              $html Default: the stored value.
									 * @param array<string,mixed> $row  Option row.
									 */
									echo wp_kses( (string) apply_filters( 'lucidlab_dbc_autoload_cell', '<code>' . esc_html( $row['autoload'] ) . '</code>', $row ), self::cell_html() );
									?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! $with_cells ) : ?>
			<div class="lucidlab-dbc-btn-row">
				<?php if ( lucidlab_dbc_is_pro() ) : ?>
					<a class="button lldbc-btn" href="<?php echo esc_url( $this->tab_url( 'autoload' ) ); ?>"><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Open the autoload checker', 'lucidlab-db-cleanup' ); ?></a>
				<?php else : ?>
					<?php echo self::pro_link( 'autoload-preview' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pro_link(). ?>
					<span class="lucidlab-dbc-hint"><?php esc_html_e( 'The full Autoload checker (50 options, autoload switch per option with undo) is part of Pro.', 'lucidlab-db-cleanup' ); ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * HTML allowed in cells that add-ons fill.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function cell_html(): array {
		$attrs = array(
			'class'         => true,
			'type'          => true,
			'value'         => true,
			'disabled'      => true,
			'aria-label'    => true,
			'aria-pressed'  => true,
			'aria-hidden'   => true,
			'data-dbcp-op'  => true,
			'data-dbcp-arg' => true,
			'data-dbcp-val' => true,
			'title'         => true,
		);
		return array(
			'code'   => array(),
			'span'   => $attrs,
			'button' => $attrs,
			'p'      => $attrs,
			'br'     => array(),
		);
	}

	/**
	 * Table engine and character set report (read-only).
	 */
	private function render_engines(): void {
		$tables  = LucidLab_DBC_Insights::engines();
		$engine  = count(
			array_filter(
				$tables,
				static function ( $t ) {
					return in_array( 'engine', $t['issues'], true );
				}
			)
		);
		$charset = count(
			array_filter(
				$tables,
				static function ( $t ) {
					return in_array( 'charset', $t['issues'], true );
				}
			)
		);
		?>
		<div class="lucidlab-dbc-card" data-dbc-engines>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Tables', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'MyISAM and Aria tables lock the whole table on every write and are not crash-safe; InnoDB is the default since MySQL 5.5. Tables or columns that are not utf8mb4 cannot store emoji and some languages.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<div class="lucidlab-dbc-stats">
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num"><?php echo esc_html( number_format_i18n( count( $tables ) ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Tables', 'lucidlab-db-cleanup' ); ?></span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num" data-dbc-engine-issues><?php echo esc_html( number_format_i18n( $engine ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Not InnoDB', 'lucidlab-db-cleanup' ); ?></span></div>
				<div class="lucidlab-dbc-stat"><span class="lucidlab-dbc-num" data-dbc-charset-issues><?php echo esc_html( number_format_i18n( $charset ) ); ?></span><span class="lucidlab-dbc-lbl"><?php esc_html_e( 'Not utf8mb4', 'lucidlab-db-cleanup' ); ?></span></div>
			</div>
			<?php
			/**
			 * Fires above the engine report table (the Pro add-on adds the conversion controls).
			 *
			 * @param array<int,array<string,mixed>> $tables Report rows.
			 */
			do_action( 'lucidlab_dbc_engines_before_table', $tables );
			?>
			<div class="lucidlab-dbc-table-scroll">
				<table class="lucidlab-dbc-table" data-dbc-engine-table>
					<thead><tr>
						<th><?php esc_html_e( 'Table', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Engine', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Collation', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'lucidlab-db-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Status', 'lucidlab-db-cleanup' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $tables as $row ) : ?>
						<tr data-dbc-table="<?php echo esc_attr( $row['name'] ); ?>" data-issues="<?php echo esc_attr( implode( ' ', $row['issues'] ) ); ?>">
							<th scope="row"><code><?php echo esc_html( $row['name'] ); ?></code></th>
							<td><?php echo esc_html( $row['engine'] ); ?></td>
							<td><?php echo esc_html( $row['collation'] ); ?>
								<?php if ( $row['columns'] ) : ?>
									<p class="lucidlab-dbc-hint">
										<?php
										/* translators: 1: number of columns, 2: character sets. */
										echo esc_html( sprintf( _n( '%1$s column in %2$s', '%1$s columns in %2$s', $row['columns'], 'lucidlab-db-cleanup' ), number_format_i18n( $row['columns'] ), $row['sets'] ) );
										?>
									</p>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['rows'] ) ); ?></td>
							<td class="lucidlab-dbc-nowrap"><?php echo esc_html( LucidLab_DBC_Helpers::bytes( $row['bytes'] ) ); ?></td>
							<td>
								<?php
								if ( ! $row['issues'] ) {
									echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-safe">' . esc_html__( 'OK', 'lucidlab-db-cleanup' ) . '</span>';
								}
								if ( in_array( 'engine', $row['issues'], true ) ) {
									echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-warn">' . esc_html__( 'Not InnoDB', 'lucidlab-db-cleanup' ) . '</span> ';
								}
								if ( in_array( 'charset', $row['issues'], true ) ) {
									echo '<span class="lucidlab-dbc-badge lucidlab-dbc-badge-warn">' . esc_html__( 'Not utf8mb4', 'lucidlab-db-cleanup' ) . '</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		if ( ! lucidlab_dbc_is_pro() && ( $engine || $charset ) ) {
			self::pro_card(
				__( 'Convert tables', 'lucidlab-db-cleanup' ),
				__( 'Convert MyISAM and Aria tables to InnoDB and old character sets to utf8mb4_unicode_520_ci, one table at a time, after a full backup of those tables and with a row count and checksum check.', 'lucidlab-db-cleanup' )
			);
		}
	}

	/**
	 * Cleanup backup retention (on the Backups tab).
	 */
	private function render_retention(): void {
		$settings = LucidLab_DBC_Automation::settings();
		?>
		<form class="lucidlab-dbc-card" data-dbc-retention>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Keep backups', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Older cleanup backups are deleted automatically after every cleanup. The backup of the cleanup that just ran is always kept.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<div class="lucidlab-dbc-field-row">
				<p class="lucidlab-dbc-field">
					<label for="lucidlab-dbc-keep-count"><?php esc_html_e( 'Keep the last … backups', 'lucidlab-db-cleanup' ); ?></label>
					<input type="number" id="lucidlab-dbc-keep-count" name="keep_count" min="0" max="500" value="<?php echo esc_attr( (string) $settings['keep_count'] ); ?>" />
					<span class="lucidlab-dbc-hint"><?php esc_html_e( '0 means no limit.', 'lucidlab-db-cleanup' ); ?></span>
				</p>
				<p class="lucidlab-dbc-field">
					<label for="lucidlab-dbc-keep-days"><?php esc_html_e( 'Delete backups older than … days', 'lucidlab-db-cleanup' ); ?></label>
					<input type="number" id="lucidlab-dbc-keep-days" name="keep_days" min="0" max="3650" value="<?php echo esc_attr( (string) $settings['keep_days'] ); ?>" />
					<span class="lucidlab-dbc-hint"><?php esc_html_e( '0 means no age limit.', 'lucidlab-db-cleanup' ); ?></span>
				</p>
			</div>
			<div class="lucidlab-dbc-btn-row">
				<button type="submit" class="button lldbc-btn button-primary"><?php esc_html_e( 'Save', 'lucidlab-db-cleanup' ); ?></button>
				<span class="lucidlab-dbc-hint" data-dbc-retention-message role="status" aria-live="polite"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * "Keep the last N revisions" card on the revisions tab.
	 */
	private function render_revisions_keep(): void {
		?>
		<div class="lucidlab-dbc-card" data-dbc-revkeep>
			<div class="lucidlab-dbc-card-head">
				<h3 class="lucidlab-dbc-card-title"><?php esc_html_e( 'Keep the last revisions', 'lucidlab-db-cleanup' ); ?></h3>
				<p class="lucidlab-dbc-card-lede"><?php esc_html_e( 'Instead of removing all revisions, keep the newest ones of every post and remove the older ones. Autosaves are not touched. The removed revisions are backed up first.', 'lucidlab-db-cleanup' ); ?></p>
			</div>
			<div class="lucidlab-dbc-field-row">
				<p class="lucidlab-dbc-field">
					<label for="lucidlab-dbc-revkeep"><?php esc_html_e( 'Revisions to keep per post', 'lucidlab-db-cleanup' ); ?></label>
					<input type="number" id="lucidlab-dbc-revkeep" min="0" max="500" value="5" data-dbc-revkeep-n />
				</p>
			</div>
			<div class="lucidlab-dbc-btn-row">
				<button type="button" class="button lldbc-btn" data-dbc-revkeep-preview><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Count', 'lucidlab-db-cleanup' ); ?></button>
				<button type="button" class="button lldbc-btn" data-dbc-revkeep-run disabled><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Back up and remove older revisions', 'lucidlab-db-cleanup' ); ?></button>
				<span class="lucidlab-dbc-hint" data-dbc-revkeep-message role="status" aria-live="polite"></span>
			</div>
			<?php $this->render_progress_box(); ?>
		</div>
		<?php
		if ( ! lucidlab_dbc_is_pro() ) {
			self::pro_card(
				__( 'Limit new revisions', 'lucidlab-db-cleanup' ),
				__( 'Set how many revisions WordPress keeps for new edits (wp_revisions_to_keep), without editing wp-config.php.', 'lucidlab-db-cleanup' )
			);
		}
	}

	/**
	 * Progress bar markup.
	 */
	private function render_progress_box(): void {
		?>
		<div class="lucidlab-dbc-progress" data-dbc-progress hidden>
			<div class="lucidlab-dbc-progress-track"><div class="lucidlab-dbc-progress-bar" data-dbc-progress-bar></div></div>
			<p class="lucidlab-dbc-progress-text" data-dbc-progress-text aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Box shown when the restore check needs a confirmation (other site URL, failed statements).
	 */
	private function render_ack_box(): void {
		?>
		<div class="lucidlab-dbc-inline-confirm notice inline notice-error" data-dbc-ack hidden>
			<p><strong><?php esc_html_e( 'Please check before the live tables are replaced:', 'lucidlab-db-cleanup' ); ?></strong></p>
			<ul class="lucidlab-dbc-warn-list" data-dbc-ack-list></ul>
			<div class="lucidlab-dbc-btn-row">
				<button type="button" class="button lldbc-btn lucidlab-dbc-danger" data-dbc-ack-yes><?php esc_html_e( 'Replace the live tables anyway', 'lucidlab-db-cleanup' ); ?></button>
				<button type="button" class="button lldbc-btn" data-dbc-ack-no><?php esc_html_e( 'Cancel the restore', 'lucidlab-db-cleanup' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ---------- AJAX ---------- */

	/**
	 * AJAX router (nonce + capability checked for every operation).
	 */
	public function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'lucidlab-db-cleanup' ) ), 403 );
		}
		if ( is_multisite() ) {
			wp_send_json_error( array( 'message' => __( 'This plugin works on single sites only.', 'lucidlab-db-cleanup' ) ), 400 );
		}
		$op = sanitize_key( (string) LucidLab_DBC_Helpers::request( 'op' ) );
		try {
			switch ( $op ) {
				case 'scan':
					$result = $this->ajax_scan( sanitize_key( (string) LucidLab_DBC_Helpers::request( 'category' ) ) );
					break;
				case 'clean_start':
					$ids    = array_map( 'strval', (array) LucidLab_DBC_Helpers::request( 'ids', array() ) );
					$result = ( new LucidLab_DBC_Cleaner( $this->scanner() ) )->start( $ids, $this->findings(), '1' === (string) LucidLab_DBC_Helpers::request( 'risky' ) );
					break;
				case 'clean_safe':
					$result = LucidLab_DBC_Automation::start_safe_clean( $this->scanner() );
					break;
				case 'retention_save':
					$result = LucidLab_DBC_Automation::save_settings(
						array(
							'keep_count' => absint( LucidLab_DBC_Helpers::request( 'keep_count', 8 ) ),
							'keep_days'  => absint( LucidLab_DBC_Helpers::request( 'keep_days', 0 ) ),
						)
					);
					$result = array(
						'keep_count' => $result['keep_count'],
						'keep_days'  => $result['keep_days'],
					);
					break;
				case 'scanrules_save':
					$result = LucidLab_DBC_Automation::save_settings(
						array(
							'as_days' => LucidLab_DBC_Automation::clamp_as_days( absint( LucidLab_DBC_Helpers::request( 'as_days', 30 ) ) ),
						)
					);
					$result = array( 'as_days' => (int) $result['as_days'] );
					break;
				case 'revisions_preview':
					$result = LucidLab_DBC_Insights::revisions_preview( absint( LucidLab_DBC_Helpers::request( 'keep', 5 ) ) );
					break;
				case 'revisions_keep':
					$result = LucidLab_DBC_Insights::start_revisions_keep( $this->scanner(), absint( LucidLab_DBC_Helpers::request( 'keep', 5 ) ) );
					break;
				case 'ping_save':
					$result = LucidLab_DBC_Site_Ping::set( '1' === (string) LucidLab_DBC_Helpers::request( 'on' ) );
					break;
				case 'ping_dismiss':
					LucidLab_DBC_Site_Ping::dismiss_notice();
					$result = array( 'dismissed' => true );
					break;
				case 'dismiss_pro':
					update_user_meta( get_current_user_id(), 'lucidlab_dbc_pro_dismissed', time() );
					$result = array( 'dismissed' => true );
					break;
				case 'optimize_start':
					$result = ( new LucidLab_DBC_Cleaner( $this->scanner() ) )->start_optimize();
					break;
				case 'measure':
					$snapshot = LucidLab_DBC_Database::measure();
					LucidLab_DBC_Database::remember( $snapshot );
					$result = array( 'time' => $snapshot['time'] );
					break;
				case 'clean_step':
					$result = ( new LucidLab_DBC_Cleaner( $this->scanner() ) )->step();
					break;
				case 'restore_step':
					$result = LucidLab_DBC_Backup::restore_step( (string) LucidLab_DBC_Helpers::request( 'backup' ), absint( LucidLab_DBC_Helpers::request( 'offset', 0 ) ) );
					break;
				case 'delete_backup':
					$result = LucidLab_DBC_Backup::delete( (string) LucidLab_DBC_Helpers::request( 'backup' ) ) ? array( 'deleted' => true ) : new WP_Error( 'lucidlab_dbc_delete', __( 'The backup could not be deleted.', 'lucidlab-db-cleanup' ) );
					break;
				case 'full_start':
					$result = LucidLab_DBC_Fullbackup::start(
						sanitize_key( (string) LucidLab_DBC_Helpers::request( 'scope' ) ),
						'safety' === LucidLab_DBC_Helpers::request( 'trigger' ) ? 'safety' : 'manual',
						array( (string) LucidLab_DBC_Helpers::request( 'protect' ) )
					);
					break;
				case 'full_step':
					$result = LucidLab_DBC_Fullbackup::step();
					break;
				case 'full_discard':
					LucidLab_DBC_Fullbackup::discard();
					$result = array( 'discarded' => true );
					break;
				case 'full_delete':
					$id     = (string) LucidLab_DBC_Helpers::request( 'backup' );
					$result = ( LucidLab_DBC_Fullbackup::valid_id( $id ) && ! LucidLab_DBC_Restore::active() && LucidLab_DBC_Fullbackup::delete( $id ) ) ? array( 'deleted' => true ) : new WP_Error( 'lucidlab_dbc_delete', __( 'The backup could not be deleted.', 'lucidlab-db-cleanup' ) );
					break;
				case 'full_restore_start':
					$result = LucidLab_DBC_Restore::start(
						(string) LucidLab_DBC_Helpers::request( 'backup' ),
						array(
							'confirm'    => (string) LucidLab_DBC_Helpers::request( 'confirm' ),
							'drop_extra' => '1' === (string) LucidLab_DBC_Helpers::request( 'drop_extra' ),
							'ack'        => '1' === (string) LucidLab_DBC_Helpers::request( 'ack' ),
							'safety'     => (string) LucidLab_DBC_Helpers::request( 'safety' ),
						)
					);
					break;
				case 'full_restore_step':
					$result = LucidLab_DBC_Restore::step( '1' === (string) LucidLab_DBC_Helpers::request( 'ack' ) );
					break;
				case 'full_restore_cancel':
					$result = LucidLab_DBC_Restore::cancel();
					break;
				case 'inspect':
					$result = array( 'html' => $this->inspect( sanitize_key( (string) LucidLab_DBC_Helpers::request( 'type' ) ), (string) LucidLab_DBC_Helpers::request( 'id' ) ) );
					break;
				default:
					/**
					 * Handles an AJAX operation of an add-on. The nonce and the capability are already checked.
					 * Return an array (sent as success) or a WP_Error; null means the operation is unknown.
					 *
					 * @param array|WP_Error|null  $result Default null.
					 * @param string               $op     Operation.
					 * @param LucidLab_DBC_Scanner $scanner Scanner.
					 */
					$result = apply_filters( 'lucidlab_dbc_ajax', null, $op, $this->scanner() );
					if ( null === $result ) {
						$result = new WP_Error( 'lucidlab_dbc_op', __( 'Unknown operation.', 'lucidlab-db-cleanup' ) );
					}
			}
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'lucidlab_dbc_exception', $e->getMessage() );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Scan one category and store the result.
	 *
	 * @param string $category Category key.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function ajax_scan( string $category ) {
		if ( ! array_key_exists( $category, LucidLab_DBC_Scanner::categories() ) ) {
			return new WP_Error( 'lucidlab_dbc_category', __( 'Unknown category.', 'lucidlab-db-cleanup' ) );
		}
		$items = self::store_category( $this->scanner(), $category );
		return array( 'category' => $category ) + $this->totals( $items );
	}

	/**
	 * Scan one category and store the result (manual scans and scheduled runs).
	 *
	 * @param LucidLab_DBC_Scanner $scanner  Scanner.
	 * @param string               $category Category key (validated by the caller).
	 *
	 * @return array<int,array<string,mixed>> Findings of the category.
	 */
	public static function store_category( LucidLab_DBC_Scanner $scanner, string $category ): array {
		wp_raise_memory_limit( 'admin' );
		$items                           = $scanner->scan_category( $category );
		$scan                            = self::stored_scan();
		$scan['time']                    = time();
		$scan['categories'][ $category ] = array(
			'time'  => time(),
			'items' => $items,
		);
		$ordered                         = array();
		foreach ( array_keys( LucidLab_DBC_Scanner::categories() ) as $key ) {
			if ( isset( $scan['categories'][ $key ] ) ) {
				$ordered[ $key ] = $scan['categories'][ $key ];
			}
		}
		$scan['categories'] = $ordered;
		update_option( self::SCAN_OPTION, $scan, false );
		return $items;
	}

	/**
	 * Read-only details of a post, option or table for the Inspect dialog.
	 *
	 * @param string $type post|option|table.
	 * @param string $id   Identifier.
	 *
	 * @return string HTML (escaped).
	 */
	private function inspect( string $type, string $id ): string {
		global $wpdb;
		$rows = array();
		if ( 'post' === $type ) {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw row on purpose: get_post() hides unregistered post types' details.
			if ( ! $post ) {
				return '<p>' . esc_html__( 'This post no longer exists.', 'lucidlab-db-cleanup' ) . '</p>';
			}
			foreach ( $post as $field => $value ) {
				$rows[ $field ] = (string) $value;
			}
			$meta = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key LIMIT 100", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inspection.
			$html = $this->kv_table( $rows, 2000 );
			if ( $meta ) {
				$pairs = array();
				foreach ( $meta as $row ) {
					$pairs[] = array( (string) $row['meta_key'], (string) $row['meta_value'] );
				}
				$html .= '<h4>' . esc_html__( 'Post meta', 'lucidlab-db-cleanup' ) . '</h4>' . $this->pair_table( $pairs, 400 );
			}
			return $html;
		}
		if ( 'option' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw row on purpose, without option filters.
			if ( ! $row ) {
				return '<p>' . esc_html__( 'This option no longer exists.', 'lucidlab-db-cleanup' ) . '</p>';
			}
			$value = (string) $row['option_value'];
			$data  = is_serialized( $value ) ? @unserialize( $value, array( 'allowed_classes' => false ) ) : $value; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Display only; objects are never instantiated (allowed_classes false).
			$shown = is_string( $data ) ? $data : (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			return $this->kv_table(
				array(
					__( 'Name', 'lucidlab-db-cleanup' ) => (string) $row['option_name'],
					__( 'Autoload', 'lucidlab-db-cleanup' ) => (string) $row['autoload'],
					__( 'Size', 'lucidlab-db-cleanup' ) => LucidLab_DBC_Helpers::bytes( strlen( $value ) ),
				),
				200
			) . '<pre class="lucidlab-dbc-pre">' . esc_html( $this->cut( $shown, 20000 ) ) . '</pre>';
		}
		if ( 'table' === $type ) {
			if ( ! str_starts_with( $id, (string) $wpdb->prefix ) || ! LucidLab_DBC_Helpers::table_exists( $id ) ) {
				return '<p>' . esc_html__( 'This table no longer exists.', 'lucidlab-db-cleanup' ) . '</p>';
			}
			$stats   = LucidLab_DBC_Helpers::table_stats( $id );
			$columns = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inspection.
			$sample  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT 20', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inspection.
			$html    = $this->kv_table(
				array(
					__( 'Rows', 'lucidlab-db-cleanup' )    => number_format_i18n( $stats['rows'] ),
					__( 'Size', 'lucidlab-db-cleanup' )    => LucidLab_DBC_Helpers::bytes( $stats['bytes'] ),
					__( 'Engine', 'lucidlab-db-cleanup' )  => $stats['engine'],
					__( 'Columns', 'lucidlab-db-cleanup' ) => implode( ', ', wp_list_pluck( $columns, 'Field' ) ),
				),
				2000
			);
			if ( $sample ) {
				$html .= '<h4>' . esc_html__( 'First rows', 'lucidlab-db-cleanup' ) . '</h4><div class="lucidlab-dbc-table-scroll"><table class="lucidlab-dbc-table lucidlab-dbc-table-compact"><thead><tr>';
				foreach ( array_keys( (array) $sample[0] ) as $column ) {
					$html .= '<th>' . esc_html( (string) $column ) . '</th>';
				}
				$html .= '</tr></thead><tbody>';
				foreach ( $sample as $row ) {
					$html .= '<tr>';
					foreach ( $row as $value ) {
						$html .= '<td>' . esc_html( $this->cut( (string) $value, 120 ) ) . '</td>';
					}
					$html .= '</tr>';
				}
				$html .= '</tbody></table></div>';
			}
			return $html;
		}
		return '<p>' . esc_html__( 'Nothing to show.', 'lucidlab-db-cleanup' ) . '</p>';
	}

	/**
	 * Shorten text for display.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max bytes.
	 *
	 * @return string
	 */
	private function cut( string $text, int $max ): string {
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . ' …' : $text;
	}

	/**
	 * Key/value table.
	 *
	 * @param array<string,string> $rows Rows.
	 * @param int                  $max  Max value length.
	 *
	 * @return string
	 */
	private function kv_table( array $rows, int $max ): string {
		$pairs = array();
		foreach ( $rows as $key => $value ) {
			$pairs[] = array( (string) $key, (string) $value );
		}
		return $this->pair_table( $pairs, $max );
	}

	/**
	 * Two-column table.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Pairs.
	 * @param int                                 $max   Max value length.
	 *
	 * @return string
	 */
	private function pair_table( array $pairs, int $max ): string {
		$html = '<table class="lucidlab-dbc-table lucidlab-dbc-table-compact lucidlab-dbc-kv"><tbody>';
		foreach ( $pairs as $pair ) {
			$html .= '<tr><th scope="row">' . esc_html( $pair[0] ) . '</th><td><code>' . esc_html( $this->cut( $pair[1], $max ) ) . '</code></td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Stream a backup file to the browser.
	 */
	public function download(): void {
		$id = sanitize_file_name( wp_unslash( (string) ( $_GET['backup'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked on the next line with the id in the action.
		check_admin_referer( 'lucidlab_dbc_download_' . $id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lucidlab-db-cleanup' ), 403 );
		}
		$path = LucidLab_DBC_Backup::path( $id );
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'lucidlab-db-cleanup' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $id . '.sql"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a large private backup file to an administrator without loading it in memory.
		exit;
	}

	/**
	 * Stream a full backup (or uploaded file) to the browser.
	 */
	public function full_download(): void {
		$id = sanitize_file_name( wp_unslash( (string) ( $_GET['backup'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked on the next line with the id in the action.
		check_admin_referer( 'lucidlab_dbc_full_download_' . $id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lucidlab-db-cleanup' ), 403 );
		}
		$path = LucidLab_DBC_Fullbackup::path( $id );
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'lucidlab-db-cleanup' ), 404 );
		}
		$gz   = str_ends_with( $path, '.gz' );
		$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( $gz ? 'application/gzip' : 'application/sql; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $host . '-' . $id . ( $gz ? '.sql.gz' : '.sql' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a large private backup file to an administrator without loading it in memory.
		exit;
	}
}
