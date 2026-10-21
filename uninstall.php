<?php
/**
 * Lucid Lab Database Cleaner: uninstall.
 *
 * Removes the scan result, the job state and the discovery caches. SQL backups (cleanup backups and
 * full database backups) are the only way to undo a cleanup or go back to an earlier database, so
 * they (and the small indexes that list them) are kept when backup files exist.
 * Define LUCIDLAB_DBC_UNINSTALL_DELETE_BACKUPS as true in wp-config.php to delete them as well.
 *
 * @package LucidLab_DB_Cleanup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'lucidlab_dbc_uninstall_rmdir' ) ) {
	/**
	 * Recursively delete a plugin-owned folder. Symbolic links are removed, never followed.
	 *
	 * @param string $dir Absolute folder path.
	 */
	function lucidlab_dbc_uninstall_rmdir( string $dir ): void {
		if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall removes the plugin's own backup folder; WP_Filesystem may need credentials here.
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
	}
}

global $wpdb;

delete_option( 'lucidlab_dbc_scan' );
delete_option( 'lucidlab_dbc_job' );
delete_option( 'lucidlab_dbc_runs' );
delete_option( 'lucidlab_dbc_measure' );
delete_option( 'lucidlab_dbc_settings' );
delete_option( 'lucidlab_dbc_auto' );
delete_option( 'lucidlab_dbc_stop' );
delete_option( 'lucidlab_dbc_last_mail' );
delete_option( 'lucidlab_dbc_version' );
delete_metadata( 'user', 0, 'lucidlab_dbc_pro_dismissed', '', true );
delete_option( 'lucidlab_dbc_full_job' );
delete_option( 'lucidlab_dbc_full_restore' );

// Optional install ping: opt-in, install ID, last ping, install time, notice answers and the weekly event.
delete_option( 'lucidlab_dbc_install_ping' );
delete_option( 'lucidlab_dbc_install_id' );
delete_option( 'lucidlab_dbc_site_ping_last' );
delete_option( 'lucidlab_dbc_installed_at' );
delete_metadata( 'user', 0, 'lucidlab_dbc_ping_notice', '', true );
wp_clear_scheduled_hook( 'lucidlab_dbc_site_ping' );

// Scheduled cleanups.
wp_clear_scheduled_hook( 'lucidlab_dbc_scheduled_run' );
wp_clear_scheduled_hook( 'lucidlab_dbc_auto_work' );

// Discovery caches (transients named lucidlab_dbc_disc_*).
$lucidlab_dbc_transients = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_lucidlab_dbc_disc_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time lookup during uninstall; there is no API to list transients by prefix.
foreach ( $lucidlab_dbc_transients as $lucidlab_dbc_name ) {
	delete_transient( substr( (string) $lucidlab_dbc_name, strlen( '_transient_' ) ) );
}

$lucidlab_dbc_uploads = wp_upload_dir( null, false );
$lucidlab_dbc_root    = untrailingslashit( (string) ( $lucidlab_dbc_uploads['basedir'] ?? '' ) ) . '/lucidlab-db-cleanup';
$lucidlab_dbc_files   = '' !== (string) ( $lucidlab_dbc_uploads['basedir'] ?? '' ) ? array_merge( (array) glob( $lucidlab_dbc_root . '/backups-*/*.sql' ), (array) glob( $lucidlab_dbc_root . '/backups-*/*.sql.gz' ) ) : array();
$lucidlab_dbc_wipe    = defined( 'LUCIDLAB_DBC_UNINSTALL_DELETE_BACKUPS' ) && LUCIDLAB_DBC_UNINSTALL_DELETE_BACKUPS;

if ( $lucidlab_dbc_wipe || ! $lucidlab_dbc_files ) {
	lucidlab_dbc_uninstall_rmdir( $lucidlab_dbc_root );
	delete_option( 'lucidlab_dbc_backups' );
	delete_option( 'lucidlab_dbc_full_backups' );
	delete_option( 'lucidlab_dbc_dir_key' );
}
