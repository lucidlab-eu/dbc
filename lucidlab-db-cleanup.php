<?php
/**
 * Plugin Name:       Lucid Lab Database Cleaner
 * Description:       Read-only scan of the whole database for unused, orphaned and leftover records and tables, grouped by category. Delete only what you select, after an automatic SQL backup that you can restore. Complete database backup and restore, autoload checker and table engine report.
 * Version:           1.5.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lucid Lab
 * Author URI:        https://lucidlab.eu
 * Plugin URI:        https://lucidlab.eu/
 * License:           GPL-2.0-or-later
 * Text Domain:       lucidlab-db-cleanup
 * Domain Path:       /languages
 *
 * Single-site plugin. The scan never changes data. Cleanup only runs on rows and
 * tables you select, and writes an SQL backup of every affected row before it deletes.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'LUCIDLAB_DBC_VERSION' ) ) {
	return;
}

define( 'LUCIDLAB_DBC_VERSION', '1.5.3' );
define( 'LUCIDLAB_DBC_FILE', __FILE__ );
define( 'LUCIDLAB_DBC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUCIDLAB_DBC_URL', plugin_dir_url( __FILE__ ) );
define( 'LUCIDLAB_DBC_SLUG', 'lucidlab-db-cleanup' );

/**
 * Load a LucidLab_DBC_* class on first use, so a request only compiles the classes it needs.
 *
 * @param string $class_name Class name.
 */
function lucidlab_dbc_autoload( string $class_name ): void {
	if ( 0 !== strpos( $class_name, 'LucidLab_DBC_' ) ) {
		return;
	}
	$file = LUCIDLAB_DBC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

/**
 * Whether this request needs the plugin at all.
 *
 * Front-end, REST, cron and CLI requests get nothing. AJAX requests only when they are our own
 * action; other admin screens only get the menu entry (the heavy classes load on our screen).
 *
 * @return bool
 */
function lucidlab_dbc_needed(): bool {
	if ( ! is_admin() ) {
		return false;
	}
	if ( wp_doing_ajax() ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the AJAX handler verifies the nonce.
		return isset( $_REQUEST['action'] ) && 'lucidlab_dbc' === $_REQUEST['action'];
	}
	return true;
}

/**
 * Start the plugin (admin only).
 */
function lucidlab_dbc_boot(): void {
	static $booted = false;
	if ( $booted ) {
		return;
	}
	$booted = true;
	spl_autoload_register( 'lucidlab_dbc_autoload' );
	add_action( 'init', 'lucidlab_dbc_load_textdomain' );
	add_action( 'admin_menu', 'lucidlab_dbc_register_menu' );
	add_filter( 'plugin_action_links_' . plugin_basename( LUCIDLAB_DBC_FILE ), 'lucidlab_dbc_action_links' );
	add_filter( 'plugin_row_meta', 'lucidlab_dbc_row_meta', 10, 2 );
	add_action( 'activated_plugin', 'lucidlab_dbc_on_pro_plugin_activated' );
	// Optional install ping: only does something after an administrator opted in.
	add_action( 'admin_init', array( 'LucidLab_DBC_Site_Ping', 'admin_init' ) );
	// Places the menu below the other Lucid Lab menus (menu_order + a final pass before output).
	new LucidLab_DBC_Menu( LUCIDLAB_DBC_SLUG );
	if ( lucidlab_dbc_is_own_request() ) {
		LucidLab_DBC_Plugin::instance();
	}
}

/**
 * Whether this is our screen, our AJAX action or our backup download. Only then are the scanner,
 * cleaner, backup and database classes loaded.
 *
 * @return bool
 */
function lucidlab_dbc_is_own_request(): bool {
	if ( wp_doing_ajax() ) {
		return true; // lucidlab_dbc_needed() only lets our own AJAX action through.
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Routing only; the handlers check capability and nonce.
	if ( isset( $_GET['page'] ) && LUCIDLAB_DBC_SLUG === $_GET['page'] ) {
		return true;
	}
	return isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'lucidlab_dbc_download', 'lucidlab_dbc_full_download' ), true );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Register the top-level menu. Its place below the other Lucid Lab menus is set by LucidLab_DBC_Menu.
 */
function lucidlab_dbc_register_menu(): void {
	add_menu_page(
		__( 'Database Cleaner', 'lucidlab-db-cleanup' ),
		__( 'Database Cleaner', 'lucidlab-db-cleanup' ),
		'manage_options',
		LUCIDLAB_DBC_SLUG,
		'lucidlab_dbc_render_page',
		lucidlab_dbc_menu_icon(),
		LucidLab_DBC_Menu::POSITION
	);
}

/**
 * Admin menu icon: the Lucid Lab database icon (assets/img/menu-icon.svg) as a data URI. It is a single
 * filled shape, so WordPress recolors it to match the admin color scheme.
 *
 * @return string
 */
function lucidlab_dbc_menu_icon(): string {
	return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCIgdmlld0JveD0iMCAwIDIwIDIwIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTYuNSAxLjdjMyAwIDUuNSAxIDUuNSAyLjNTOS41IDYuMyA2LjUgNi4zIDEgNS4zIDEgNHMyLjUtMi4zIDUuNS0yLjNaTTEgNS42djMuNWMwIDEuMyAyLjUgMi4zIDUuNSAyLjNTMTIgMTAuNCAxMiA5LjFWNS42Yy0xLjIuOS0zLjIgMS41LTUuNSAxLjVTMi4yIDYuNSAxIDUuNlptMCA0Ljd2My42YzAgMS4zIDIuNSAyLjMgNS41IDIuM3M1LjUtMSA1LjUtMi4zdi0zLjZjLTEuMi45LTMuMiAxLjUtNS41IDEuNXMtNC4zLS42LTUuNS0xLjVabTE1LjQuNGMuNCAyIDEuOCAzLjQgMy44IDMuOC0yIC40LTMuNCAxLjgtMy44IDMuOC0uNC0yLTEuOC0zLjQtMy44LTMuOCAyLS40IDMuNC0xLjggMy44LTMuOFpNMTYuNSAyYy4yIDEgLjkgMS43IDEuOSAxLjktMSAuMi0xLjcuOS0xLjkgMS45LS4yLTEtLjktMS43LTEuOS0xLjkgMS0uMiAxLjctLjkgMS45LTEuOVoiLz48L3N2Zz4K';
}

/**
 * Render our screen.
 */
function lucidlab_dbc_render_page(): void {
	LucidLab_DBC_Plugin::instance()->render_page();
}

/**
 * "Settings" link on the Plugins screen (before Deactivate), to the Settings tab. Administrators only.
 *
 * @param array<int|string,string> $links Links.
 *
 * @return array<int|string,string>
 */
function lucidlab_dbc_action_links( array $links ): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}
	$url = add_query_arg(
		array(
			'page' => LUCIDLAB_DBC_SLUG,
			'tab'  => 'settings',
		),
		admin_url( 'admin.php' )
	);
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lucidlab-db-cleanup' ) . '</a>' );
	return $links;
}

/**
 * "Documentation" link in the plugin's row on the Plugins screen.
 *
 * @param array<int|string,string> $links       Row meta links.
 * @param string                   $plugin_file Plugin basename of the row.
 *
 * @return array<int|string,string>
 */
function lucidlab_dbc_row_meta( array $links, string $plugin_file ): array {
	if ( plugin_basename( LUCIDLAB_DBC_FILE ) === $plugin_file ) {
		$links[] = '<a href="' . esc_url( lucidlab_dbc_docs_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'lucidlab-db-cleanup' ) . '</a>';
	}
	return $links;
}

/**
 * Address of the documentation.
 *
 * @return string
 */
function lucidlab_dbc_docs_url(): string {
	return 'https://lucidlab.eu/database-cleaner/documentation/';
}

/**
 * Weekly install ping (only scheduled after an administrator opted in). Registered on every request,
 * because WP-Cron runs outside wp-admin; the class is loaded only when the event fires.
 */
function lucidlab_dbc_site_ping_cron(): void {
	lucidlab_dbc_load_classes();
	LucidLab_DBC_Site_Ping::cron();
}
add_action( 'lucidlab_dbc_site_ping', 'lucidlab_dbc_site_ping_cron' );

/**
 * Load the translations shipped in /languages (the plugin is not hosted on WordPress.org, so they
 * are not loaded just in time). Admin requests only.
 */
function lucidlab_dbc_load_textdomain(): void {
	$locale = determine_locale();
	$mo     = LUCIDLAB_DBC_DIR . 'languages/lucidlab-db-cleanup-' . $locale . '.mo';
	if ( is_readable( $mo ) ) {
		load_textdomain( 'lucidlab-db-cleanup', $mo, $locale );
	}
}

add_action(
	'plugins_loaded',
	static function () {
		if ( lucidlab_dbc_needed() ) {
			lucidlab_dbc_boot();
		}
	},
	20
);

/**
 * Whether the Pro add-on is active and licensed. The free plugin never unlocks anything itself: the
 * separate "Lucid Lab Database Cleaner Pro" plugin returns true here when its license is active.
 *
 * @return bool
 */
function lucidlab_dbc_is_pro(): bool {
	/**
	 * Filters whether the Pro features are available.
	 *
	 * @param bool $is_pro Default false.
	 */
	return (bool) apply_filters( 'lucidlab_dbc_is_pro', false );
}

/**
 * Whether the Pro add-on plugin is installed and active (licensed or not).
 *
 * @return bool
 */
function lucidlab_dbc_pro_installed(): bool {
	return defined( 'LUCIDLAB_DBCP_VERSION' );
}

/**
 * Plugin basename of the Pro add-on under wp-content/plugins.
 *
 * @return string
 */
function lucidlab_dbc_pro_plugin_basename(): string {
	return 'lucidlab-db-cleanup-pro/lucidlab-db-cleanup-pro.php';
}

/**
 * Clear the "Pro installed but not activated" dismissal when Pro is activated, so the notice can
 * return if Pro is later deactivated again.
 *
 * @param string $plugin Plugin basename that was activated.
 */
function lucidlab_dbc_on_pro_plugin_activated( string $plugin ): void {
	if ( lucidlab_dbc_pro_plugin_basename() !== $plugin ) {
		return;
	}
	$user_id = get_current_user_id();
	if ( $user_id ) {
		delete_user_meta( $user_id, 'lucidlab_dbc_hide_pro_inactive_notice' );
	}
}

/**
 * Address of the "Get Pro" links. Placeholder until the shop page exists.
 *
 * @return string
 */
function lucidlab_dbc_pro_url(): string {
	/**
	 * Filters the "Get Pro" link target.
	 *
	 * @param string $url Default the Lucid Lab shop page.
	 */
	return (string) apply_filters( 'lucidlab_dbc_pro_url', 'https://lucidlab.eu/product/database-cleaner-pro/' );
}

/**
 * Make the classes available outside our own admin requests (cron, Pro add-on).
 */
function lucidlab_dbc_load_classes(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	spl_autoload_register( 'lucidlab_dbc_autoload' );
	if ( ! is_textdomain_loaded( 'lucidlab-db-cleanup' ) ) {
		lucidlab_dbc_load_textdomain();
	}
}

/**
 * Version 1.4.0 moved scheduled cleanups to the Pro add-on: remove the cron events of 1.2/1.3. The
 * saved schedule settings are kept, so Pro continues with them when it is installed.
 */
function lucidlab_dbc_upgrade(): void {
	if ( get_option( 'lucidlab_dbc_version' ) === LUCIDLAB_DBC_VERSION ) {
		return;
	}
	wp_clear_scheduled_hook( 'lucidlab_dbc_scheduled_run' );
	wp_clear_scheduled_hook( 'lucidlab_dbc_auto_work' );
	update_option( 'lucidlab_dbc_version', LUCIDLAB_DBC_VERSION, false );
}
add_action( 'admin_init', 'lucidlab_dbc_upgrade' );

/*
 * Old (1.2/1.3) scheduled events that fire before the upgrade ran are ignored: nothing is attached
 * to them any more, so WP-Cron simply drops them.
 */

/**
 * Tell add-ons that the free plugin is loaded (after its own bootstrap on plugins_loaded).
 */
add_action(
	'plugins_loaded',
	static function () {
		do_action( 'lucidlab_dbc_loaded' );
	},
	25
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'lucidlab_dbc_scheduled_run' );
		wp_clear_scheduled_hook( 'lucidlab_dbc_auto_work' );
		spl_autoload_register( 'lucidlab_dbc_autoload' );
		LucidLab_DBC_Site_Ping::stop();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		spl_autoload_register( 'lucidlab_dbc_autoload' );
		LucidLab_DBC_Backup::ensure_dir();
		LucidLab_DBC_Site_Ping::mark_installed();
		LucidLab_DBC_Plugin::migrate_autoload();
	}
);
