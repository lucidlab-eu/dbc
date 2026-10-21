<?php
/**
 * Lucid Lab Database Cleaner: optional install ping.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells lucidlab.eu that this site runs the plugin, so Lucid Lab can count active installs.
 *
 * Off by default: nothing is sent until an administrator turns it on (Settings tab, or the notice on
 * the Database Cleaner screen). Only the site address, the plugin version, the plugin slug and a
 * random install ID are sent: right after opting in, after each plugin update and once a week.
 * Turning it off, deactivating or uninstalling the plugin removes the weekly event at once.
 */
final class LucidLab_DBC_Site_Ping {

	/** Endpoint on lucidlab.eu (POST JSON: install_id, site_url, version, plugin). */
	public const ENDPOINT = 'https://lucidlab.eu/wp-json/lucidlab/v1/free/ping';

	/** Weekly WP-Cron hook. */
	public const HOOK = 'lucidlab_dbc_site_ping';

	/** Opt-in option: '1' when an administrator turned the ping on. Absent (off) by default. */
	public const OPTION = 'lucidlab_dbc_install_ping';

	/** Random 32-hex install ID; it identifies the install, not a person. */
	public const INSTALL_ID = 'lucidlab_dbc_install_id';

	/** Last attempt: version signature and time. */
	public const LAST = 'lucidlab_dbc_site_ping_last';

	/** User meta: the opt-in notice was answered or dismissed. */
	public const NOTICE_META = 'lucidlab_dbc_ping_notice';

	/** Unix time the plugin was installed (or first loaded after the update to 1.5.1). Not autoloaded. */
	public const INSTALLED_AT = 'lucidlab_dbc_installed_at';

	/** The opt-in notice is only shown once the plugin has been installed this long. */
	public const NOTICE_DELAY = 7 * DAY_IN_SECONDS;

	/**
	 * Whether the administrator opted in.
	 *
	 * @return bool
	 */
	public static function opted_in(): bool {
		return '1' === (string) get_option( self::OPTION, '' );
	}

	/**
	 * Whether the ping may be sent.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		/**
		 * Filters whether this site may send the install ping to lucidlab.eu.
		 *
		 * @param bool $allowed True when an administrator opted in on the Settings tab.
		 */
		return (bool) apply_filters( 'lucidlab_dbc_site_ping_allowed', self::opted_in() );
	}

	/**
	 * Versions the last ping reported; a new value triggers a ping after an update.
	 *
	 * @return string
	 */
	public static function signature(): string {
		/**
		 * Filters the version signature (an add-on can append its own version, so its updates ping too).
		 *
		 * @param string $signature Free plugin version.
		 */
		return (string) apply_filters( 'lucidlab_dbc_site_ping_signature', LUCIDLAB_DBC_VERSION );
	}

	/**
	 * Exactly what is sent.
	 *
	 * @return array{install_id:string,site_url:string,version:string,plugin:string}
	 */
	public static function payload(): array {
		return array(
			'install_id' => self::install_id(),
			'site_url'   => home_url(),
			'version'    => LUCIDLAB_DBC_VERSION,
			'plugin'     => LUCIDLAB_DBC_SLUG,
		);
	}

	/**
	 * Random install ID, created on the first ping (same format as the Pro add-on: 32 lowercase hex).
	 *
	 * @return string
	 */
	public static function install_id(): string {
		$saved = get_option( self::INSTALL_ID, '' );
		$saved = is_string( $saved ) ? strtolower( $saved ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $saved ) ) {
			return $saved;
		}
		try {
			$id = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$id = substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 32 );
		}
		update_option( self::INSTALL_ID, $id, false );
		return $id;
	}

	/**
	 * Admin requests by an administrator: keep the schedule in line and ping after an update.
	 */
	public static function admin_init(): void {
		self::installed_at();
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::refresh();
	}

	/**
	 * Schedule or unschedule the weekly event, and ping when the version changed since the last ping.
	 */
	public static function refresh(): void {
		if ( ! self::sync_schedule() ) {
			return;
		}
		$last = get_option( self::LAST, array() );
		$last = is_array( $last ) ? $last : array();
		if ( ( $last['signature'] ?? '' ) === self::signature() ) {
			return;
		}
		self::send();
	}

	/**
	 * Weekly event when allowed, none otherwise.
	 *
	 * @return bool Whether pinging is allowed.
	 */
	public static function sync_schedule(): bool {
		if ( ! self::allowed() ) {
			self::stop();
			return false;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::HOOK );
		}
		return true;
	}

	/**
	 * Opted out or plugin deactivated: no event and no remembered ping, so a later opt-in pings again
	 * at once.
	 */
	public static function stop(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		if ( false !== get_option( self::LAST, false ) ) {
			delete_option( self::LAST );
		}
	}

	/**
	 * Weekly event. Checks the opt-in again, because the event may predate an opt-out.
	 */
	public static function cron(): void {
		if ( ! self::sync_schedule() ) {
			return;
		}
		self::send();
	}

	/**
	 * Save the administrator's choice. Turning it on pings at once and schedules the weekly event;
	 * turning it off removes the event at once.
	 *
	 * @param bool $on Opt in or out.
	 *
	 * @return array{enabled:bool,sent:bool}
	 */
	public static function set( bool $on ): array {
		if ( $on ) {
			update_option( self::OPTION, '1', false );
		} else {
			delete_option( self::OPTION );
		}
		update_user_meta( get_current_user_id(), self::NOTICE_META, time() );
		if ( ! $on ) {
			self::sync_schedule();
			return array(
				'enabled' => self::allowed(),
				'sent'    => false,
			);
		}
		delete_option( self::LAST );
		$sent = self::sync_schedule() && self::send();
		return array(
			'enabled' => self::allowed(),
			'sent'    => $sent,
		);
	}

	/**
	 * POST the payload to lucidlab.eu without waiting for the answer (non-blocking, 3 s at most).
	 *
	 * @return bool Whether a request was started.
	 */
	public static function send(): bool {
		if ( ! self::allowed() ) {
			return false;
		}
		wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 3,
				'blocking'    => false,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( self::payload() ),
				'data_format' => 'body',
			)
		);
		update_option(
			self::LAST,
			array(
				'signature' => self::signature(),
				'time'      => time(),
			),
			false
		);
		return true;
	}

	/**
	 * Time of the last ping, or 0.
	 *
	 * @return int
	 */
	public static function last_time(): int {
		$last = get_option( self::LAST, array() );
		return is_array( $last ) ? (int) ( $last['time'] ?? 0 ) : 0;
	}

	/**
	 * Remember when the plugin was installed (activation). An existing value is kept, so deactivating and
	 * activating again does not restart the waiting time.
	 */
	public static function mark_installed(): void {
		add_option( self::INSTALLED_AT, time(), '', false );
	}

	/**
	 * Install time. Sites that updated from a version without it get the current time on their first
	 * admin request, so they also wait NOTICE_DELAY from the update.
	 *
	 * @return int
	 */
	public static function installed_at(): int {
		$time = (int) get_option( self::INSTALLED_AT, 0 );
		if ( $time <= 0 ) {
			$time = time();
			update_option( self::INSTALLED_AT, $time, false );
		}
		return $time;
	}

	/**
	 * Whether the opt-in notice should be shown to the current user: not opted in, not answered or
	 * dismissed, and the plugin has been installed for at least NOTICE_DELAY (7 days).
	 *
	 * @return bool
	 */
	public static function show_notice(): bool {
		return ! self::opted_in()
			&& current_user_can( 'manage_options' )
			&& ! get_user_meta( get_current_user_id(), self::NOTICE_META, true )
			&& time() - self::installed_at() >= self::NOTICE_DELAY;
	}

	/**
	 * Hide the opt-in notice for the current user (nothing is turned on).
	 */
	public static function dismiss_notice(): void {
		update_user_meta( get_current_user_id(), self::NOTICE_META, time() );
	}
}
