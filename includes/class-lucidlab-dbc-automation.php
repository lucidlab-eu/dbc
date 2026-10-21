<?php
/**
 * "Clean all safe items", backup retention and the hand-off to add-ons after a cleanup.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selection of safe findings, retention of cleanup backups and the lucidlab_dbc_after_job action.
 * Scheduled cleanups and report e-mails are part of the separate Pro add-on.
 */
final class LucidLab_DBC_Automation {

	/**
	 * Settings option (autoload off). Shared with the Pro add-on, which stores its own keys in it.
	 */
	public const SETTINGS_OPTION = 'lucidlab_dbc_settings';

	/**
	 * Smallest and largest age in days of finished Action Scheduler actions that the scan offers.
	 */
	public const AS_DAYS_MIN = 1;
	public const AS_DAYS_MAX = 365;

	/**
	 * Default settings of the free plugin.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'keep_count' => 8,
			'keep_days'  => 0,
			'full_scope' => 'all',
			'as_days'    => 30,
		);
	}

	/**
	 * Saved settings: the free keys validated and merged with the defaults; keys of the Pro add-on
	 * (schedule, e-mail, full backup retention) are passed through unchanged, so they survive while
	 * Pro is inactive.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( $saved, self::sanitize( $saved ) );
	}

	/**
	 * Validate the free settings.
	 *
	 * @param array<string,mixed> $in Raw values.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ): array {
		$out = self::defaults();
		if ( isset( $in['keep_count'] ) ) {
			$out['keep_count'] = min( 500, max( 0, (int) $in['keep_count'] ) );
		}
		if ( isset( $in['keep_days'] ) ) {
			$out['keep_days'] = min( 3650, max( 0, (int) $in['keep_days'] ) );
		}
		if ( isset( $in['as_days'] ) ) {
			$out['as_days'] = self::clamp_as_days( (int) $in['as_days'] );
		}
		$out['full_scope'] = 'prefix' === ( $in['full_scope'] ?? '' ) ? 'prefix' : 'all';
		return $out;
	}

	/**
	 * Keep an Action Scheduler age within the allowed range.
	 *
	 * @param int $days Days.
	 *
	 * @return int
	 */
	public static function clamp_as_days( int $days ): int {
		return min( self::AS_DAYS_MAX, max( self::AS_DAYS_MIN, $days ) );
	}

	/**
	 * Age in days of completed, canceled and failed scheduled actions that the scan offers.
	 *
	 * @return int
	 */
	public static function as_days(): int {
		return (int) self::settings()['as_days'];
	}

	/**
	 * Save some settings; other saved keys (also those of Pro) are kept.
	 *
	 * The free keys are validated here. Other keys (the Pro schedule settings) are stored as given;
	 * the code that passes them validates them first.
	 *
	 * @param array<string,mixed> $in Values to change.
	 *
	 * @return array<string,mixed> All settings.
	 */
	public static function save_settings( array $in ): array {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$all   = array_merge( $saved, $in, self::sanitize( array_merge( $saved, $in ) ) );
		update_option( self::SETTINGS_OPTION, $all, false );
		return $all;
	}

	/**
	 * Whether a finding may be cleaned by "Clean all safe items" and scheduled runs: marked safe,
	 * deletable, and not Lucid Lab data or data declared by another plugin.
	 *
	 * @param array<string,mixed> $item Finding.
	 *
	 * @return bool
	 */
	public static function is_safe( array $item ): bool {
		return ! empty( $item['deletable'] )
			&& LucidLab_DBC_Scanner::SAFE === ( $item['confidence'] ?? '' )
			&& 'lucidlab' !== ( $item['source_vendor'] ?? '' )
			&& empty( $item['source_declared'] );
	}

	/**
	 * Safe findings of the stored scan, with counts and sizes per category.
	 *
	 * @return array{ids:array<int,string>,categories:array<string,array{label:string,findings:int,bytes:int}>,findings:int,bytes:int,time:int}
	 */
	public static function safe_selection(): array {
		$scan   = LucidLab_DBC_Plugin::stored_scan();
		$labels = LucidLab_DBC_Scanner::categories();
		$out    = array(
			'ids'        => array(),
			'categories' => array(),
			'findings'   => 0,
			'bytes'      => 0,
			'time'       => (int) $scan['time'],
		);
		foreach ( $scan['categories'] as $key => $category ) {
			if ( ! isset( $labels[ $key ] ) ) {
				continue; // A category of an add-on that is no longer active.
			}
			foreach ( (array) ( $category['items'] ?? array() ) as $item ) {
				if ( ! self::is_safe( (array) $item ) ) {
					continue;
				}
				if ( ! isset( $out['categories'][ $key ] ) ) {
					$out['categories'][ $key ] = array(
						'label'    => (string) ( $labels[ $key ]['label'] ?? $key ),
						'findings' => 0,
						'bytes'    => 0,
					);
				}
				$out['ids'][] = (string) $item['id'];
				++$out['categories'][ $key ]['findings'];
				$out['categories'][ $key ]['bytes'] += (int) ( $item['bytes'] ?? 0 );
				++$out['findings'];
				$out['bytes'] += (int) ( $item['bytes'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * Start "Clean all safe items" from the stored scan.
	 *
	 * @param LucidLab_DBC_Scanner $scanner Scanner.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start_safe_clean( LucidLab_DBC_Scanner $scanner ) {
		if ( self::running() ) {
			return new WP_Error( 'lucidlab_dbc_busy', __( 'A background cleanup is running. Wait until it has finished.', 'lucidlab-db-cleanup' ) );
		}
		$selection = self::safe_selection();
		if ( ! $selection['ids'] ) {
			return new WP_Error( 'lucidlab_dbc_nothing', __( 'There are no safe items to clean. Run the scan first.', 'lucidlab-db-cleanup' ) );
		}
		return ( new LucidLab_DBC_Cleaner( $scanner ) )->start( $selection['ids'], LucidLab_DBC_Plugin::stored_findings(), false, true, 'oneclick' );
	}

	/**
	 * Whether an add-on (the Pro scheduler) is running a cleanup in the background.
	 *
	 * @return bool
	 */
	public static function running(): bool {
		/**
		 * Filters whether a background cleanup is running, so manual cleanups and restores wait.
		 *
		 * @param bool $running Default false.
		 */
		return (bool) apply_filters( 'lucidlab_dbc_automation_running', false );
	}

	/**
	 * Called by the cleaner when a job is complete: retention of cleanup backups, then the
	 * lucidlab_dbc_after_job action (the Pro add-on sends its report e-mail there).
	 *
	 * @param array<string,mixed> $run Log entry of the run.
	 */
	public static function after_job( array $run ): void {
		if ( 'cleanup' !== ( $run['type'] ?? '' ) ) {
			return;
		}
		$settings = self::settings();
		$deleted  = LucidLab_DBC_Backup::prune( (int) $settings['keep_count'], (int) $settings['keep_days'], (string) ( $run['backup'] ?? '' ) );
		$run     += array( 'pruned' => $deleted );
		/**
		 * Fires after a cleanup run was logged and backup retention was applied.
		 *
		 * @param array<string,mixed> $run Log entry of the run, with the ids of pruned backups.
		 */
		do_action( 'lucidlab_dbc_after_job', $run );
	}
}
