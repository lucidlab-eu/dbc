<?php
/**
 * Lucid Lab Database Cleaner: admin menu placement below the other Lucid Lab plugins, with a diagnostic.
 *
 * @package LucidLab_DB_Cleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu.
 *
 * WordPress builds the menu in wp-admin/includes/menu.php: after 'admin_menu' it sorts $menu (uksort, then the
 * 'custom_menu_order' / 'menu_order' filters and usort), removes duplicate separators and adds the CSS classes.
 * wp-admin/menu-header.php then applies 'parent_file' and prints the menu with _wp_menu_output(). So:
 * 1. 'menu_order' (PHP_INT_MAX) places the item when custom ordering is active;
 * 2. 'parent_file' (PHP_INT_MAX) runs after all sorting and right before output, and re-applies the placement
 *    on the final $menu, so a later menu_order filter or a custom_menu_order that ends up false cannot undo it.
 */
final class LucidLab_DBC_Menu {

	/**
	 * Initial menu position (kept when no other Lucid Lab menu is present): below WooCommerce Marketing (58)
	 * and above separator 59 / Appearance.
	 */
	public const POSITION = '58.9';

	/**
	 * Menu slugs of the other Lucid Lab plugins.
	 */
	private const SLUGS = array( 'lucidlab-mpc-cleanup', 'lucidlab-image-to-video', 'lucidlab-webwatcher', 'nscv-visibility', 'lpm', 'llft-map' );

	/**
	 * Menu titles of the other Lucid Lab plugins (matched case-insensitively inside the visible title).
	 */
	private const TITLES = array( 'Media & Products Cleanup', 'Image to Video', 'Products & Category Visibility', 'Plannings Manager', 'Web Watcher', 'Flight Tracker' );

	/**
	 * Debug query argument.
	 */
	private const DEBUG_ARG = 'lldbc_menu_debug';

	/**
	 * Our menu slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * What happened during this request (for the diagnostic).
	 *
	 * @var array<string,mixed>
	 */
	private $trace = array(
		'menu_order_ran'      => false,
		'menu_order_received' => array(),
		'menu_order_returned' => array(),
		'menu_order_result'   => '',
		'final_pass_ran'      => false,
		'final_pass_result'   => '',
	);

	/**
	 * Constructor.
	 *
	 * @param string $slug Our menu slug.
	 */
	public function __construct( string $slug ) {
		$this->slug = $slug;
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( $this, 'menu_order' ), PHP_INT_MAX );
		add_filter( 'parent_file', array( $this, 'final_pass' ), PHP_INT_MAX );
		if ( isset( $_GET[ self::DEBUG_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether the read-only diagnostic hook is added.
			add_action( 'admin_notices', array( $this, 'debug_notice' ), 1 );
		}
	}

	/**
	 * 'menu_order' filter: move our slug right after the last Lucid Lab slug. Returns the full array.
	 *
	 * @param mixed $order Top-level menu slugs in display order.
	 *
	 * @return mixed
	 */
	public function menu_order( $order ) {
		$this->trace['menu_order_ran']      = true;
		$this->trace['menu_order_received'] = is_array( $order ) ? array_values( $order ) : $order;
		if ( ! is_array( $order ) ) {
			$this->trace['menu_order_result'] = 'not an array; returned unchanged';
			return $order;
		}
		$items = array();
		foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item[2] ) ) {
				$items[ (string) $item[2] ] = $item;
			}
		}
		$list = array();
		foreach ( array_values( $order ) as $slug ) {
			$list[] = $items[ (string) $slug ] ?? array( '', '', (string) $slug );
		}
		$moved                              = $this->place( $list, $result );
		$this->trace['menu_order_result']   = $result;
		$out                                = $moved ? array_map(
			static function ( $item ) {
				return $item[2];
			},
			$list
		) : array_values( $order );
		$this->trace['menu_order_returned'] = $out;
		return $out;
	}

	/**
	 * 'parent_file' filter (runs after all sorting, right before the menu is printed): re-apply the placement.
	 *
	 * @param mixed $parent_file Parent file (returned unchanged).
	 *
	 * @return mixed
	 */
	public function final_pass( $parent_file ) {
		global $menu;
		$this->trace['final_pass_ran'] = true;
		if ( ! is_array( $menu ) ) {
			$this->trace['final_pass_result'] = '$menu is not an array';
			return $parent_file;
		}
		$list = array_values( $menu );
		if ( $this->place( $list, $result ) ) {
			// Recompute the first/last classes for the new order.
			foreach ( $list as $i => $item ) {
				if ( isset( $item[4] ) ) {
					$list[ $i ][4] = trim( (string) preg_replace( '/(^|\s)menu-top-(first|last)(?=\s|$)/', ' ', (string) $item[4] ) );
				}
			}
			$menu = add_menu_classes( $list ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering the admin menu is the purpose of this filter.
		}
		$this->trace['final_pass_result'] = $result;
		return $parent_file;
	}

	/**
	 * Move our item directly below the last Lucid Lab item.
	 *
	 * @param array<int,array<int,mixed>> $items  Menu items in display order (by reference).
	 * @param string                      $result What happened (output).
	 *
	 * @return bool Whether the list was changed.
	 */
	private function place( array &$items, &$result ): bool {
		$own = null;
		foreach ( $items as $i => $item ) {
			if ( (string) ( $item[2] ?? '' ) === $this->slug ) {
				$own = $i;
				break;
			}
		}
		if ( null === $own ) {
			$result = 'our item is not in the menu; unchanged';
			return false;
		}
		$ours = $items[ $own ];
		unset( $items[ $own ] );
		$items = array_values( $items );
		$last  = null;
		foreach ( $items as $i => $item ) {
			if ( '' !== $this->match( $item ) ) {
				$last = $i;
			}
		}
		if ( null === $last ) {
			array_splice( $items, $own, 0, array( $ours ) );
			$result = 'no other Lucid Lab menu found; position kept';
			return false;
		}
		array_splice( $items, $last + 1, 0, array( $ours ) );
		$changed = ( $last + 1 ) !== $own;
		$result  = ( $changed ? 'moved' : 'already in place' ) . ' after ' . (string) $items[ $last ][2];
		return $changed;
	}

	/**
	 * Why an item counts as another Lucid Lab menu ('' when it does not).
	 *
	 * @param array<int,mixed> $item Menu item.
	 *
	 * @return string
	 */
	private function match( array $item ): string {
		$slug = (string) ( $item[2] ?? '' );
		if ( '' === $slug || $this->slug === $slug ) {
			return '';
		}
		if ( in_array( $slug, self::SLUGS, true ) ) {
			return 'slug';
		}
		if ( 0 === strpos( $slug, 'lucidlab' ) || 0 === strpos( $slug, 'llft' ) ) {
			return 'slug prefix';
		}
		$hook = (string) ( $item[5] ?? '' );
		if ( 0 === strpos( $hook, 'toplevel_page_lucidlab' ) || 0 === strpos( $hook, 'toplevel_page_llft' ) ) {
			return 'hookname';
		}
		$title = self::title( $item );
		foreach ( self::TITLES as $name ) {
			if ( '' !== $title && false !== stripos( $title, $name ) ) {
				return 'title';
			}
		}
		return '';
	}

	/**
	 * Visible title of a menu item (tags and counters removed, entities decoded).
	 *
	 * @param array<int,mixed> $item Menu item.
	 *
	 * @return string
	 */
	private static function title( array $item ): string {
		$title = (string) ( $item[0] ?? '' );
		$title = (string) preg_replace( '#<span[^>]*>.*?</span>#s', '', $title );
		return trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Read-only diagnostic for ?lldbc_menu_debug=1 (administrators only).
	 */
	public function debug_notice(): void {
		if ( ! isset( $_GET[ self::DEBUG_ARG ] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only diagnostic display.
			return;
		}
		global $menu, $wp_filter;
		$rows    = array(
			'Plugin version'              => LUCIDLAB_DBC_VERSION,
			'Main file'                   => LUCIDLAB_DBC_FILE . ' (modified ' . gmdate( 'Y-m-d H:i:s', (int) filemtime( LUCIDLAB_DBC_FILE ) ) . ' UTC)',
			'Menu class file'             => __FILE__ . ' (modified ' . gmdate( 'Y-m-d H:i:s', (int) filemtime( __FILE__ ) ) . ' UTC)',
			'OPcache'                     => self::opcache(),
			'custom_menu_order (now)'     => apply_filters( 'custom_menu_order', false ) ? 'true' : 'false', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter.
			'Our menu_order filter'       => ( $this->trace['menu_order_ran'] ? 'ran' : 'did NOT run' ) . ', priority ' . PHP_INT_MAX . ( $this->trace['menu_order_result'] ? ': ' . $this->trace['menu_order_result'] : '' ),
			'menu_order received'         => self::export( $this->trace['menu_order_received'] ),
			'menu_order returned'         => self::export( $this->trace['menu_order_returned'] ),
			'Final pass (parent_file)'    => ( $this->trace['final_pass_ran'] ? 'ran' : 'did NOT run' ) . ', priority ' . PHP_INT_MAX . ( $this->trace['final_pass_result'] ? ': ' . $this->trace['final_pass_result'] : '' ),
			'menu_order callbacks'        => self::callbacks( $wp_filter['menu_order'] ?? null ),
			'custom_menu_order callbacks' => self::callbacks( $wp_filter['custom_menu_order'] ?? null ),
			'parent_file callbacks'       => self::callbacks( $wp_filter['parent_file'] ?? null ),
		);
		$final   = array();
		$matched = array();
		foreach ( array_values( (array) $menu ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$why     = $this->match( $item );
			$slug    = (string) ( $item[2] ?? '' );
			$final[] = ( $i + 1 ) . '. ' . $slug . ( '' !== self::title( $item ) ? ' — ' . self::title( $item ) : '' ) . ( $this->slug === $slug ? '  ← this plugin' : '' ) . ( '' !== $why ? '  [Lucid Lab: ' . $why . ']' : '' );
			if ( '' !== $why ) {
				$matched[] = $slug . ' (' . $why . ')';
			}
		}
		$rows['Matched as Lucid Lab']        = $matched ? implode( ', ', $matched ) : 'none';
		$rows['Final menu order (rendered)'] = implode( "\n", $final );
		echo '<div class="notice notice-info"><p><strong>Lucid Lab Database Cleaner: menu diagnostic</strong> (read-only, shown because of ?' . esc_html( self::DEBUG_ARG ) . '=1)</p><table class="widefat striped" style="margin-bottom:10px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px;vertical-align:top">' . esc_html( $label ) . '</th><td><pre style="margin:0;white-space:pre-wrap;word-break:break-word">' . esc_html( (string) $value ) . '</pre></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * OPcache state of our files.
	 *
	 * @return string
	 */
	private static function opcache(): string {
		if ( ! function_exists( 'opcache_get_status' ) || ! filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 'not enabled for this request';
		}
		$out = sprintf( 'enabled; validate_timestamps=%s, revalidate_freq=%s, restrict_api=%s', (string) ini_get( 'opcache.validate_timestamps' ), (string) ini_get( 'opcache.revalidate_freq' ), '' !== (string) ini_get( 'opcache.restrict_api' ) ? (string) ini_get( 'opcache.restrict_api' ) : '(none)' );
		if ( '' !== (string) ini_get( 'opcache.restrict_api' ) || ! function_exists( 'opcache_is_script_cached' ) ) {
			return $out . '; per-file status not available (restrict_api)';
		}
		$out .= '; main file cached: ' . ( opcache_is_script_cached( LUCIDLAB_DBC_FILE ) ? 'yes' : 'no' );
		$out .= '; menu class cached: ' . ( opcache_is_script_cached( __FILE__ ) ? 'yes' : 'no' );
		return $out;
	}

	/**
	 * Callbacks of a hook, by priority.
	 *
	 * @param mixed $hook WP_Hook or null.
	 *
	 * @return string
	 */
	private static function callbacks( $hook ): string {
		if ( ! $hook instanceof WP_Hook || ! $hook->callbacks ) {
			return '(none)';
		}
		$lines     = array();
		$callbacks = $hook->callbacks;
		ksort( $callbacks );
		foreach ( $callbacks as $priority => $group ) {
			foreach ( (array) $group as $callback ) {
				$lines[] = $priority . ': ' . self::describe( $callback['function'] ?? null );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Readable name of a callable (with file and line for closures).
	 *
	 * @param mixed $callback Callable.
	 *
	 * @return string
	 */
	private static function describe( $callback ): string {
		try {
			if ( is_string( $callback ) ) {
				return $callback . '()';
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				return ( is_object( $callback[0] ) ? get_class( $callback[0] ) . '->' : (string) $callback[0] . '::' ) . (string) $callback[1] . '()';
			}
			if ( $callback instanceof Closure ) {
				$ref = new ReflectionFunction( $callback );
				return 'closure in ' . str_replace( ABSPATH, '', (string) $ref->getFileName() ) . ':' . $ref->getStartLine();
			}
			if ( is_object( $callback ) ) {
				return get_class( $callback ) . '->__invoke()';
			}
		} catch ( Throwable $e ) {
			return '(unknown callable)';
		}
		return '(unknown callable)';
	}

	/**
	 * Compact text of a received/returned order.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private static function export( $value ): string {
		if ( is_array( $value ) ) {
			return $value ? implode( ', ', array_map( 'strval', $value ) ) : '(empty / not called)';
		}
		return gettype( $value );
	}
}
