<?php
/**
 * Lucid Lab Database Cleaner: read-only scanner.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds unused, orphaned and leftover database records and tables. Every check is a SELECT;
 * nothing is changed. Each finding carries a spec that the cleaner turns back into SQL.
 */
class LucidLab_DBC_Scanner {

	public const SAFE   = 'safe';
	public const REVIEW = 'review';
	public const RISKY  = 'risky';
	public const INFO   = 'info';

	/**
	 * Action Scheduler filters that use the age setting (Settings tab).
	 */
	private const AS_AGE_FILTERS = array( 'as_complete', 'as_canceled', 'as_failed' );

	/** Days before an auto-draft counts as abandoned. */
	private const AUTO_DRAFT_DAYS = 7;
	/** Days without changes before a draft is listed. */
	private const OLD_DRAFT_DAYS = 365;
	/** Samples shown per finding. */
	private const SAMPLES = 25;

	/**
	 * Scan categories in display order.
	 *
	 * @return array<string,array{label:string,blurb:string}>
	 */
	public static function categories(): array {
		$core = array(
			'content'     => array(
				'label' => __( 'Pages, posts & products', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Trashed and long-forgotten drafts of pages, posts and products, and product variations whose product is gone.', 'lucidlab-db-cleanup' ),
			),
			'post_types'  => array(
				'label' => __( 'Newsletters & other post types', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Posts of types that no active plugin or theme registers any more, such as old newsletter campaigns. They usually return 404 on the front end.', 'lucidlab-db-cleanup' ),
			),
			'revisions'   => array(
				'label' => __( 'Revisions & auto-drafts', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Post revisions, revisions of deleted posts and abandoned auto-drafts.', 'lucidlab-db-cleanup' ),
			),
			'meta'        => array(
				'label' => __( 'Orphaned & leftover meta', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Post, user, term and comment meta whose parent row is gone, and meta keys written by plugins that are no longer active.', 'lucidlab-db-cleanup' ),
			),
			'terms'       => array(
				'label' => __( 'Terms & taxonomies', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Broken term relationships, terms of unregistered taxonomies and tags that nothing uses.', 'lucidlab-db-cleanup' ),
			),
			'comments'    => array(
				'label' => __( 'Comments', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Spam, trashed comments and comments or order notes of deleted posts.', 'lucidlab-db-cleanup' ),
			),
			'options'     => array(
				'label' => __( 'Options & autoload', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Settings left by plugins and themes that are no longer active, and the autoloaded options WordPress loads on every page.', 'lucidlab-db-cleanup' ),
			),
			'transients'  => array(
				'label' => __( 'Transients & caches', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Expired transients and oEmbed caches. WordPress rebuilds them when needed.', 'lucidlab-db-cleanup' ),
			),
			'woocommerce' => array(
				'label' => __( 'WooCommerce & Action Scheduler', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Expired sessions, order items and lookup rows of deleted orders and products, and finished scheduled actions with their logs.', 'lucidlab-db-cleanup' ),
			),
			'tables'      => array(
				'label' => __( 'Database tables', 'lucidlab-db-cleanup' ),
				'blurb' => __( 'Every table with this site’s prefix, mapped to WordPress core or the plugin that created it. Tables of removed plugins and of unknown owners are listed for review.', 'lucidlab-db-cleanup' ),
			),
		);
		/**
		 * Filters the scan categories. Add-ons add categories here and produce their findings with the
		 * lucidlab_dbc_scan_category filter. Core categories cannot be removed.
		 *
		 * @param array<string,array{label:string,blurb:string}> $categories Categories in display order.
		 */
		$all = apply_filters( 'lucidlab_dbc_scan_categories', $core );
		return is_array( $all ) ? array_merge( $core, array_diff_key( $all, $core ) ) : $core;
	}

	/**
	 * Run one category.
	 *
	 * @param string $category Category.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function scan_category( string $category ): array {
		$method = 'scan_' . $category;
		if ( ! array_key_exists( $category, self::categories() ) ) {
			return array();
		}
		if ( method_exists( $this, $method ) ) {
			$items = (array) $this->$method();
		} else {
			/**
			 * Filters the findings of a category added through lucidlab_dbc_scan_categories.
			 *
			 * @param array<int,array<string,mixed>> $items    Findings.
			 * @param string                         $category Category key.
			 * @param LucidLab_DBC_Scanner           $scanner  Scanner.
			 */
			$items = (array) apply_filters( 'lucidlab_dbc_scan_category', array(), $category, $this );
		}
		foreach ( $items as &$item ) {
			$item += array(
				'category'     => $category,
				'group'        => '',
				'detail'       => '',
				'source'       => '',
				'source_label' => '',
				'source_state' => LucidLab_DBC_Known::STATUS_UNKNOWN,
				'samples'      => array(),
				'more'         => 0,
				'bytes'        => 0,
				'autoload'     => 0,
				'deletable'    => true,
			);
			if ( self::INFO === $item['confidence'] ) {
				$item['deletable'] = false;
			}
		}
		unset( $item );
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return ! empty( $item['count'] ) || ! empty( $item['always'] );
				}
			)
		);
	}

	/* ---------- shared bits ---------- */

	/**
	 * Owner fields for a finding.
	 *
	 * @param array<string,mixed>|null $owner Owner.
	 *
	 * @return array{source:string,source_label:string,source_state:string}
	 */
	private function owner_fields( ?array $owner ): array {
		if ( ! $owner ) {
			return array(
				'source'       => __( 'Unknown', 'lucidlab-db-cleanup' ),
				'source_label' => LucidLab_DBC_Known::status_label( LucidLab_DBC_Known::STATUS_UNKNOWN ),
				'source_state' => LucidLab_DBC_Known::STATUS_UNKNOWN,
			);
		}
		return array(
			'source'          => (string) $owner['name'],
			'source_label'    => (string) $owner['status_label'],
			'source_state'    => (string) $owner['status'],
			'source_vendor'   => (string) ( $owner['vendor'] ?? '' ),
			'source_declared' => ! empty( $owner['declared'] ),
		);
	}

	/**
	 * Confidence for leftovers of an owner: missing = safe (review for Lucid Lab, declared and
	 * discovered owners), inactive = review, unknown = risky.
	 *
	 * @param array<string,mixed>|null $owner Owner.
	 *
	 * @return string
	 */
	private function leftover_confidence( ?array $owner ): string {
		if ( ! $owner ) {
			return self::RISKY;
		}
		switch ( (string) $owner['status'] ) {
			case LucidLab_DBC_Known::STATUS_MISSING:
				// Lucid Lab data, data declared through the lucidlab_dbc_known_owners filter and owners
				// guessed from plugin code are never "safe": review only.
				if ( ! empty( $owner['discovered'] ) || ! empty( $owner['declared'] ) || 'lucidlab' === ( $owner['vendor'] ?? '' ) ) {
					return self::REVIEW;
				}
				return self::SAFE;
			case LucidLab_DBC_Known::STATUS_INACTIVE:
				return self::REVIEW;
			default:
				return self::RISKY;
		}
	}

	/**
	 * Count, size and samples for a posts selection.
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return array{count:int,bytes:int,samples:array<int,array<string,mixed>>,more:int,media:int}
	 */
	private function posts_summary( array $spec ): array {
		global $wpdb;
		$where = $this->post_where( $spec );
		$stats = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.post_content)+LENGTH(t.post_title)+LENGTH(t.post_excerpt)+LENGTH(t.guid)+LENGTH(t.post_name)+300),0) AS bytes, COALESCE(SUM((LENGTH(t.post_content)-LENGTH(REPLACE(t.post_content,'/wp-content/uploads/','')))/20),0) AS media FROM {$wpdb->posts} AS t WHERE {$where}", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in post_where().
		$count = (int) ( $stats['cnt'] ?? 0 );
		if ( $count < 1 ) {
			return array(
				'count'   => 0,
				'bytes'   => 0,
				'samples' => array(),
				'more'    => 0,
				'media'   => 0,
			);
		}
		$meta    = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(m.meta_key)+LENGTH(m.meta_value)+40),0) FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS t ON t.ID = m.post_id WHERE {$where}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in post_where().
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.ID, t.post_title, t.post_type, t.post_status, t.post_date, t.post_modified, t.post_parent, ROUND((LENGTH(t.post_content)-LENGTH(REPLACE(t.post_content,'/wp-content/uploads/','')))/20) AS media FROM {$wpdb->posts} AS t WHERE {$where} ORDER BY t.ID DESC LIMIT %d", self::SAMPLES ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in post_where().
		$samples = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['ID'];
			$bits  = array( '#' . $id, (string) $row['post_type'], (string) $row['post_status'], mysql2date( (string) get_option( 'date_format' ), (string) $row['post_modified'] ) );
			$media = (int) $row['media'];
			if ( $media > 0 ) {
				/* translators: %d: number of media URLs in the post content. */
				$bits[] = sprintf( _n( '%d media URL in content', '%d media URLs in content', $media, 'lucidlab-db-cleanup' ), $media );
			}
			$samples[] = array(
				'label'   => '' !== trim( (string) $row['post_title'] ) ? LucidLab_DBC_Helpers::excerpt( (string) $row['post_title'], 90 ) : __( '(no title)', 'lucidlab-db-cleanup' ),
				'meta'    => implode( ' · ', $bits ),
				'view'    => add_query_arg( 'p', $id, home_url( '/' ) ),
				'edit'    => ( post_type_exists( (string) $row['post_type'] ) && 'revision' !== $row['post_type'] ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '',
				'inspect' => array(
					'type' => 'post',
					'id'   => $id,
				),
			);
		}
		return array(
			'count'   => $count,
			'bytes'   => (int) ( $stats['bytes'] ?? 0 ) + $meta,
			'samples' => $samples,
			'more'    => max( 0, $count - count( $samples ) ),
			'media'   => (int) round( (float) ( $stats['media'] ?? 0 ) ),
		);
	}

	/**
	 * Safe SQL WHERE for a posts spec (table alias "t").
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return string
	 */
	public function post_where( array $spec ): string {
		global $wpdb;
		$parts = array( '1=1' );
		if ( isset( $spec['type'] ) ) {
			$parts[] = $wpdb->prepare( 't.post_type = %s', (string) $spec['type'] );
		}
		if ( isset( $spec['status'] ) ) {
			$parts[] = $wpdb->prepare( 't.post_status = %s', (string) $spec['status'] );
		}
		if ( isset( $spec['older_gmt'] ) ) {
			$parts[] = $wpdb->prepare( 't.post_modified_gmt < %s', (string) $spec['older_gmt'] );
		}
		if ( isset( $spec['ids'] ) ) {
			$ids     = array_values( array_filter( array_map( 'intval', (array) $spec['ids'] ) ) );
			$parts[] = $ids ? 't.ID IN (' . LucidLab_DBC_Helpers::in_ints( $ids ) . ')' : '0=1';
		}
		if ( ! empty( $spec['parent_missing'] ) ) {
			$parts[] = "t.post_parent > 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p2 WHERE p2.ID = t.post_parent)";
		}
		if ( ! empty( $spec['parent_present'] ) ) {
			$parts[] = "EXISTS (SELECT 1 FROM {$wpdb->posts} AS p2 WHERE p2.ID = t.post_parent)";
		}
		// Orders and payments of registered types are never selected, whatever the spec says.
		$critical = array_values( array_filter( LucidLab_DBC_Known::CRITICAL_POST_TYPES, 'post_type_exists' ) );
		if ( $critical ) {
			$parts[] = 't.post_type NOT IN (' . LucidLab_DBC_Helpers::in_strings( $critical ) . ')';
		}
		return implode( ' AND ', $parts );
	}

	/**
	 * Registered post types (names).
	 *
	 * @return array<int,string>
	 */
	private function registered_types(): array {
		return array_values( get_post_types( array(), 'names' ) );
	}

	/* ---------- content ---------- */

	/**
	 * Pages, posts & products: trash, old drafts, orphaned variations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_content(): array {
		$items = array();
		$types = array(
			'page'    => __( 'Pages', 'lucidlab-db-cleanup' ),
			'post'    => __( 'Posts', 'lucidlab-db-cleanup' ),
			'product' => __( 'Products', 'lucidlab-db-cleanup' ),
		);
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( ! isset( $types[ $name ] ) && 'attachment' !== $name && ! in_array( $name, LucidLab_DBC_Known::CRITICAL_POST_TYPES, true ) ) {
				$types[ $name ] = (string) $object->labels->name;
			}
		}
		$all = LucidLab_DBC_Known::all();
		foreach ( $types as $type => $label ) {
			$owner = in_array( $type, array( 'page', 'post' ), true ) ? $all['core'] : LucidLab_DBC_Known::owner( 'post_types', $type );
			$spec  = array(
				'mode'   => 'posts',
				'type'   => $type,
				'status' => 'trash',
			);
			$sum   = $this->posts_summary( $spec );
			if ( $sum['count'] ) {
				$items[] = array(
					'id'         => 'content:trash:' . $type,
					'group'      => $label,
					/* translators: %s: post type label, such as "Pages". */
					'label'      => sprintf( __( '%s in the Trash', 'lucidlab-db-cleanup' ), $label ),
					'reason'     => __( 'Already moved to the Trash. Deleting removes them permanently, together with their meta, term links, comments and revisions.', 'lucidlab-db-cleanup' ),
					'confidence' => 'product' === $type ? self::REVIEW : self::SAFE,
					'spec'       => $spec,
				) + $sum + $this->owner_fields( $owner );
			}
			$spec = array(
				'mode'      => 'posts',
				'type'      => $type,
				'status'    => 'draft',
				'older_gmt' => LucidLab_DBC_Helpers::gmt_days_ago( self::OLD_DRAFT_DAYS ),
			);
			$sum  = $this->posts_summary( $spec );
			if ( $sum['count'] ) {
				$items[] = array(
					'id'         => 'content:olddraft:' . $type,
					'group'      => $label,
					/* translators: %s: post type label, such as "Pages". */
					'label'      => sprintf( __( '%s: drafts not edited for over a year', 'lucidlab-db-cleanup' ), $label ),
					'reason'     => __( 'Drafts that were never published and have not changed for more than a year. Check them; a draft can still be work in progress.', 'lucidlab-db-cleanup' ),
					'confidence' => self::RISKY,
					'spec'       => $spec,
				) + $sum + $this->owner_fields( $owner );
			}
		}
		$spec = array(
			'mode'           => 'posts',
			'type'           => 'product_variation',
			'parent_missing' => true,
		);
		$sum  = $this->posts_summary( $spec );
		if ( $sum['count'] ) {
			$items[] = array(
				'id'         => 'content:variations',
				'group'      => __( 'Products', 'lucidlab-db-cleanup' ),
				'label'      => __( 'Product variations of deleted products', 'lucidlab-db-cleanup' ),
				'reason'     => __( 'The parent product no longer exists, so WooCommerce can never show these variations.', 'lucidlab-db-cleanup' ),
				'confidence' => self::SAFE,
				'spec'       => $spec,
			) + $sum + $this->owner_fields( $all['woocommerce'] );
		}
		return $items;
	}

	/* ---------- post types ---------- */

	/**
	 * Posts of unregistered post types, grouped by type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_post_types(): array {
		$items = array();
		foreach ( $this->unregistered_types() as $type ) {
			$owner = $this->post_type_owner( $type );
			$spec  = array(
				'mode' => 'posts',
				'type' => $type,
			);
			$sum   = $this->posts_summary( $spec );
			if ( ! $sum['count'] ) {
				continue;
			}
			$state = $owner ? (string) $owner['status'] : LucidLab_DBC_Known::STATUS_UNKNOWN;
			if ( LucidLab_DBC_Known::STATUS_ACTIVE === $state || LucidLab_DBC_Known::STATUS_CORE === $state ) {
				$confidence = self::RISKY;
				$reason     = __( 'Nothing registers this post type on admin screens, but its owner is active. It may register the type only on some requests. Leave it unless you are sure.', 'lucidlab-db-cleanup' );
			} else {
				$confidence = $this->leftover_confidence( $owner );
				$reason     = __( 'No active plugin or theme registers this post type, so WordPress cannot display these posts: their links return 404.', 'lucidlab-db-cleanup' );
			}
			if ( in_array( $type, LucidLab_DBC_Known::CRITICAL_POST_TYPES, true ) ) {
				$confidence = self::RISKY;
				$reason    .= ' ' . __( 'This type stores orders or payments. Keep it if you need the history.', 'lucidlab-db-cleanup' );
			}
			if ( $sum['media'] > 0 ) {
				$reason .= ' ' . sprintf(
					/* translators: %d: number of media URLs. */
					_n( 'The content still links %d media URL, which media scanners count as “in use”.', 'The content still links %d media URLs, which media scanners count as “in use”.', $sum['media'], 'lucidlab-db-cleanup' ),
					$sum['media']
				);
			}
			$items[] = array(
				'id'         => 'post_types:' . $type,
				'group'      => $this->post_type_group( $type, $owner ),
				/* translators: %s: post type name. */
				'label'      => sprintf( __( 'Post type “%s”', 'lucidlab-db-cleanup' ), $type ),
				'detail'     => $this->status_breakdown( $type ),
				'reason'     => $reason,
				'confidence' => $confidence,
				'spec'       => $spec,
			) + $sum + $this->owner_fields( $owner );
		}
		return $items;
	}

	/**
	 * Post types stored in the posts table that nothing registers (excluding core types).
	 *
	 * @return array<int,string>
	 */
	private function unregistered_types(): array {
		global $wpdb;
		$types = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan; no core API lists the post types stored in the table.
		return array_values( array_filter( array_diff( array_map( 'strval', $types ), $this->registered_types(), LucidLab_DBC_Known::CORE_POST_TYPES ) ) );
	}

	/**
	 * Owner of a post type: knowledge base first, then the dominant meta-key prefix of its posts.
	 *
	 * @param string $type Post type.
	 *
	 * @return array<string,mixed>|null
	 */
	private function post_type_owner( string $type ): ?array {
		global $wpdb;
		$owner = LucidLab_DBC_Known::owner( 'post_types', $type );
		$keys  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_key, COUNT(*) AS cnt FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id WHERE p.post_type = %s GROUP BY m.meta_key ORDER BY cnt DESC LIMIT 40", $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan.
		$votes = array();
		foreach ( $keys as $row ) {
			$hit = LucidLab_DBC_Known::owner( 'meta', (string) $row['meta_key'] );
			// SEO plugins write meta on every post type; they never identify the owner.
			if ( $hit && ! in_array( $hit['id'], array( 'core', 'rank-math', 'yoast', 'aioseo' ), true ) ) {
				$votes[ $hit['id'] ] = ( $votes[ $hit['id'] ] ?? 0 ) + (int) $row['cnt'];
			}
		}
		if ( ! $votes ) {
			return $owner;
		}
		arsort( $votes );
		$best = LucidLab_DBC_Known::all()[ (string) array_key_first( $votes ) ] ?? null;
		// Meta evidence decides when the name alone is ambiguous (for example "newsletter").
		if ( $best && ( ! $owner || $owner['id'] !== $best['id'] ) && ( ! $owner || in_array( $type, (array) $best['post_types'], true ) ) ) {
			return $best;
		}
		return $owner;
	}

	/**
	 * Display group for a post type finding.
	 *
	 * @param string                   $type  Post type.
	 * @param array<string,mixed>|null $owner Owner.
	 *
	 * @return string
	 */
	private function post_type_group( string $type, ?array $owner ): string {
		$newsletter_owners = array( 'mailster', 'newsletter', 'mailpoet', 'mc4wp', 'yaymail', 'yith-et', 'villatheme-email', 'yith-rac' );
		if ( ( $owner && in_array( $owner['id'], $newsletter_owners, true ) ) || preg_match( '/newsletter|mail|campaign|email/i', $type ) ) {
			return __( 'Newsletters & emails', 'lucidlab-db-cleanup' );
		}
		if ( $owner && 'theme' === $owner['type'] ) {
			return __( 'Theme content', 'lucidlab-db-cleanup' );
		}
		if ( $owner ) {
			return __( 'Plugin content', 'lucidlab-db-cleanup' );
		}
		return __( 'Unknown owner', 'lucidlab-db-cleanup' );
	}

	/**
	 * "3 publish, 1 draft" style breakdown of a post type.
	 *
	 * @param string $type Post type.
	 *
	 * @return string
	 */
	private function status_breakdown( string $type ): string {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status ORDER BY cnt DESC", $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan.
		$bits = array();
		foreach ( $rows as $row ) {
			$bits[] = number_format_i18n( (int) $row['cnt'] ) . ' ' . (string) $row['post_status'];
		}
		return implode( ', ', $bits );
	}

	/* ---------- revisions ---------- */

	/**
	 * Revisions and auto-drafts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_revisions(): array {
		$core  = $this->owner_fields( LucidLab_DBC_Known::all()['core'] );
		$items = array();
		$defs  = array(
			array(
				'id'         => 'revisions:orphan',
				'group'      => __( 'Revisions', 'lucidlab-db-cleanup' ),
				'label'      => __( 'Revisions of deleted posts', 'lucidlab-db-cleanup' ),
				'reason'     => __( 'The post these revisions belong to no longer exists.', 'lucidlab-db-cleanup' ),
				'confidence' => self::SAFE,
				'spec'       => array(
					'mode'           => 'posts',
					'type'           => 'revision',
					'parent_missing' => true,
				),
			),
			array(
				'id'         => 'revisions:all',
				'group'      => __( 'Revisions', 'lucidlab-db-cleanup' ),
				'label'      => __( 'Revisions of existing posts', 'lucidlab-db-cleanup' ),
				'reason'     => __( 'Earlier versions of pages, posts and products. The current version stays; you lose the ability to roll back.', 'lucidlab-db-cleanup' ),
				'confidence' => self::REVIEW,
				'spec'       => array(
					'mode'           => 'posts',
					'type'           => 'revision',
					'parent_present' => true,
				),
			),
			array(
				'id'         => 'revisions:autodraft',
				'group'      => __( 'Auto-drafts', 'lucidlab-db-cleanup' ),
				/* translators: %d: number of days. */
				'label'      => sprintf( __( 'Auto-drafts older than %d days', 'lucidlab-db-cleanup' ), self::AUTO_DRAFT_DAYS ),
				'reason'     => __( 'Created automatically when an editor was opened and never saved. WordPress would remove them itself after a week if WP-Cron runs.', 'lucidlab-db-cleanup' ),
				'confidence' => self::SAFE,
				'spec'       => array(
					'mode'      => 'posts',
					'status'    => 'auto-draft',
					'older_gmt' => LucidLab_DBC_Helpers::gmt_days_ago( self::AUTO_DRAFT_DAYS ),
				),
			),
		);
		foreach ( $defs as $item ) {
			$items[] = $item + $this->posts_summary( $item['spec'] ) + $core;
		}
		return $items;
	}

	/* ---------- meta ---------- */

	/**
	 * Meta table definitions.
	 *
	 * @return array<string,array{table:string,pk:string,fk:string,parent:string,parent_pk:string,label:string}>
	 */
	public function meta_tables(): array {
		global $wpdb;
		return array(
			'postmeta'    => array(
				'table'     => $wpdb->postmeta,
				'pk'        => 'meta_id',
				'fk'        => 'post_id',
				'parent'    => $wpdb->posts,
				'parent_pk' => 'ID',
				'label'     => __( 'Post meta', 'lucidlab-db-cleanup' ),
			),
			'usermeta'    => array(
				'table'     => $wpdb->usermeta,
				'pk'        => 'umeta_id',
				'fk'        => 'user_id',
				'parent'    => $wpdb->users,
				'parent_pk' => 'ID',
				'label'     => __( 'User meta', 'lucidlab-db-cleanup' ),
			),
			'termmeta'    => array(
				'table'     => $wpdb->termmeta,
				'pk'        => 'meta_id',
				'fk'        => 'term_id',
				'parent'    => $wpdb->terms,
				'parent_pk' => 'term_id',
				'label'     => __( 'Term meta', 'lucidlab-db-cleanup' ),
			),
			'commentmeta' => array(
				'table'     => $wpdb->commentmeta,
				'pk'        => 'meta_id',
				'fk'        => 'comment_id',
				'parent'    => $wpdb->comments,
				'parent_pk' => 'comment_ID',
				'label'     => __( 'Comment meta', 'lucidlab-db-cleanup' ),
			),
		);
	}

	/**
	 * Orphaned meta and meta keys of inactive or removed plugins.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_meta(): array {
		global $wpdb;
		$items = array();
		$core  = $this->owner_fields( LucidLab_DBC_Known::all()['core'] );
		// Posts of unregistered types are listed (with their meta) under Post types; do not count them twice.
		$flagged_types = $this->unregistered_types();
		foreach ( $this->meta_tables() as $key => $def ) {
			$spec    = array(
				'mode' => 'orphan_meta',
				'meta' => $key,
			);
			$where   = $this->rows_where( $spec );
			$row     = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.meta_key)+LENGTH(t.meta_value)+40),0) AS bytes FROM {$def['table']} AS t WHERE {$where}", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $where is generated by rows_where().
			$items[] = array(
				'id'         => 'meta:orphan:' . $key,
				'group'      => __( 'Orphaned meta', 'lucidlab-db-cleanup' ),
				/* translators: %s: meta table label such as "Post meta". */
				'label'      => sprintf( __( '%s without a parent', 'lucidlab-db-cleanup' ), $def['label'] ),
				/* translators: 1: meta table name, 2: parent table name. */
				'detail'     => sprintf( __( 'Rows in %1$s whose %2$s row no longer exists.', 'lucidlab-db-cleanup' ), $def['table'], $def['parent'] ),
				'reason'     => __( 'The post, user, term or comment these rows describe was deleted without its meta. Nothing can read them.', 'lucidlab-db-cleanup' ),
				'confidence' => self::SAFE,
				'count'      => (int) ( $row['cnt'] ?? 0 ),
				'bytes'      => (int) ( $row['bytes'] ?? 0 ),
				'samples'    => $this->meta_samples( $def, $where ),
				'spec'       => $spec,
			) + $core;

			// Leftover keys from inactive / removed plugins.
			$exclude = ( 'postmeta' === $key && $flagged_types ) ? ' AND t.post_id NOT IN (SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type IN (' . LucidLab_DBC_Helpers::in_strings( $flagged_types ) . '))' : '';
			$keys    = (array) $wpdb->get_results( "SELECT t.meta_key, COUNT(*) AS cnt, SUM(LENGTH(t.meta_key)+LENGTH(t.meta_value)+40) AS bytes FROM {$def['table']} AS t WHERE 1=1 {$exclude} GROUP BY t.meta_key", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; the exclusion list is a prepared IN list.
			$groups  = array();
			foreach ( $keys as $meta ) {
				$name = (string) $meta['meta_key'];
				if ( '' === $name || str_starts_with( $name, '_oembed_' ) || str_starts_with( $name, $wpdb->prefix ) ) {
					continue;
				}
				$owner = LucidLab_DBC_Known::owner( 'meta', $name );
				if ( ! $owner || ! in_array( $owner['status'], array( LucidLab_DBC_Known::STATUS_MISSING, LucidLab_DBC_Known::STATUS_INACTIVE ), true ) ) {
					continue;
				}
				$id = (string) $owner['id'];
				if ( ! isset( $groups[ $id ] ) ) {
					$groups[ $id ] = array(
						'owner' => $owner,
						'keys'  => array(),
						'count' => 0,
						'bytes' => 0,
					);
				}
				$groups[ $id ]['keys'][ $name ] = (int) $meta['cnt'];
				$groups[ $id ]['count']        += (int) $meta['cnt'];
				$groups[ $id ]['bytes']        += (int) $meta['bytes'];
			}
			foreach ( $groups as $id => $group ) {
				arsort( $group['keys'] );
				$samples = array();
				foreach ( array_slice( $group['keys'], 0, self::SAMPLES, true ) as $name => $cnt ) {
					$samples[] = array(
						'label' => (string) $name,
						/* translators: %s: number of rows. */
						'meta'  => sprintf( _n( '%s row', '%s rows', $cnt, 'lucidlab-db-cleanup' ), number_format_i18n( $cnt ) ),
					);
				}
				$items[] = array(
					'id'         => 'meta:plugin:' . $key . ':' . $id,
					'group'      => __( 'Meta of inactive or removed plugins', 'lucidlab-db-cleanup' ),
					/* translators: 1: meta table label, 2: plugin name. */
					'label'      => sprintf( __( '%1$s written by %2$s', 'lucidlab-db-cleanup' ), $def['label'], $group['owner']['name'] ),
					/* translators: %d: number of distinct meta keys. */
					'detail'     => sprintf( _n( '%d meta key', '%d meta keys', count( $group['keys'] ), 'lucidlab-db-cleanup' ), count( $group['keys'] ) ),
					'reason'     => __( 'Only the plugin that wrote these keys reads them, and it is not active.', 'lucidlab-db-cleanup' ),
					'confidence' => $this->leftover_confidence( $group['owner'] ),
					'count'      => $group['count'],
					'bytes'      => $group['bytes'],
					'samples'    => $samples,
					'more'       => max( 0, count( $group['keys'] ) - count( $samples ) ),
					'spec'       => array(
						'mode'          => 'meta_keys',
						'meta'          => $key,
						'owner'         => $id,
						'keys'          => array_map( 'strval', array_keys( $group['keys'] ) ),
						'exclude_types' => 'postmeta' === $key ? $flagged_types : array(),
					),
				) + $this->owner_fields( $group['owner'] );
			}
		}
		return $items;
	}

	/**
	 * Sample rows of a meta selection.
	 *
	 * @param array<string,string> $def   Meta table definition.
	 * @param string               $where Where.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function meta_samples( array $def, string $where ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.%i AS fk, t.meta_key, LEFT(t.meta_value, 120) AS val FROM {$def['table']} AS t WHERE {$where} LIMIT %d", $def['fk'], self::SAMPLES ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $where is generated by rows_where().
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'label' => (string) $row['meta_key'],
				'meta'  => $def['fk'] . ' ' . (int) $row['fk'] . ' · ' . LucidLab_DBC_Helpers::excerpt( (string) $row['val'], 60 ),
			);
		}
		return $out;
	}

	/* ---------- terms ---------- */

	/**
	 * Term relationships, orphaned terms, unregistered taxonomies and unused tags.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_terms(): array {
		global $wpdb;
		$all   = LucidLab_DBC_Known::all();
		$core  = $this->owner_fields( $all['core'] );
		$items = array();
		$defs  = array(
			'tr_object' => array(
				'table' => $wpdb->term_relationships,
				'label' => __( 'Term links to deleted posts', 'lucidlab-db-cleanup' ),
				'why'   => __( 'Category, tag or attribute links of posts and products that no longer exist.', 'lucidlab-db-cleanup' ),
			),
			'tr_tt'     => array(
				'table' => $wpdb->term_relationships,
				'label' => __( 'Term links to deleted terms', 'lucidlab-db-cleanup' ),
				'why'   => __( 'Links to a term taxonomy row that no longer exists.', 'lucidlab-db-cleanup' ),
			),
			'tt_term'   => array(
				'table' => $wpdb->term_taxonomy,
				'label' => __( 'Taxonomy rows without a term', 'lucidlab-db-cleanup' ),
				'why'   => __( 'Taxonomy rows whose term was deleted.', 'lucidlab-db-cleanup' ),
			),
			'term_tt'   => array(
				'table' => $wpdb->terms,
				'label' => __( 'Terms without a taxonomy', 'lucidlab-db-cleanup' ),
				'why'   => __( 'Terms that belong to no taxonomy at all, so WordPress never shows them.', 'lucidlab-db-cleanup' ),
			),
		);
		foreach ( $defs as $key => $def ) {
			$spec    = array(
				'mode'   => 'orphan_terms',
				'filter' => $key,
			);
			$where   = $this->rows_where( $spec );
			$count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$def['table']} AS t WHERE {$where}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $where is generated by rows_where().
			$items[] = array(
				'id'         => 'terms:orphan:' . $key,
				'group'      => __( 'Broken links', 'lucidlab-db-cleanup' ),
				'label'      => $def['label'],
				'detail'     => $def['table'],
				'reason'     => $def['why'],
				'confidence' => self::SAFE,
				'count'      => $count,
				'bytes'      => $count * LucidLab_DBC_Helpers::avg_row_bytes( $def['table'] ),
				'spec'       => $spec,
			) + $core;
		}

		// Taxonomies stored in term_taxonomy that nothing registers.
		$taxes = (array) $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS cnt FROM {$wpdb->term_taxonomy} GROUP BY taxonomy", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan.
		foreach ( $taxes as $tax ) {
			$name = (string) $tax['taxonomy'];
			if ( '' === $name || taxonomy_exists( $name ) || str_starts_with( $name, 'pa_' ) ) {
				continue; // Product attributes that WooCommerce did not load yet are never offered.
			}
			$owner   = LucidLab_DBC_Known::owner( 'taxonomies', $name );
			$state   = $owner ? (string) $owner['status'] : LucidLab_DBC_Known::STATUS_UNKNOWN;
			$spec    = array(
				'mode'     => 'terms',
				'taxonomy' => $name,
			);
			$items[] = array(
				'id'         => 'terms:tax:' . $name,
				'group'      => __( 'Unregistered taxonomies', 'lucidlab-db-cleanup' ),
				/* translators: %s: taxonomy name. */
				'label'      => sprintf( __( 'Taxonomy “%s”', 'lucidlab-db-cleanup' ), $name ),
				'detail'     => $this->terms_detail( $name ),
				'reason'     => __( 'No active plugin or theme registers this taxonomy, so its terms and links are never used.', 'lucidlab-db-cleanup' ),
				'confidence' => in_array( $state, array( LucidLab_DBC_Known::STATUS_ACTIVE, LucidLab_DBC_Known::STATUS_CORE ), true ) ? self::RISKY : $this->leftover_confidence( $owner ),
				'count'      => (int) $tax['cnt'],
				'bytes'      => $this->terms_bytes( $spec ),
				'samples'    => $this->term_samples( $spec ),
				'spec'       => $spec,
			) + $this->owner_fields( $owner );
		}

		// Registered tag-like taxonomies with terms nothing uses.
		$candidates = array(
			'post_tag'    => self::REVIEW,
			'product_tag' => self::REVIEW,
			'category'    => self::RISKY,
			'product_cat' => self::RISKY,
		);
		foreach ( $candidates as $taxonomy => $confidence ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$spec    = array(
				'mode'     => 'terms',
				'taxonomy' => $taxonomy,
				'unused'   => true,
			);
			$where   = $this->term_where( $spec );
			$count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} AS t WHERE {$where}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by term_where().
			$tax_obj = get_taxonomy( $taxonomy );
			$items[] = array(
				'id'         => 'terms:unused:' . $taxonomy,
				'group'      => __( 'Unused terms', 'lucidlab-db-cleanup' ),
				/* translators: %s: taxonomy label such as "Tags". */
				'label'      => sprintf( __( '%s without any posts or products', 'lucidlab-db-cleanup' ), $tax_obj ? $tax_obj->labels->name : $taxonomy ),
				'reason'     => ( 'category' === $taxonomy || 'product_cat' === $taxonomy )
					? __( 'Empty categories can be intentional (menus, future products). Default categories, parent categories and menu items are never included.', 'lucidlab-db-cleanup' )
					: __( 'No post or product uses these terms. Their archive URLs will return 404 after deletion.', 'lucidlab-db-cleanup' ),
				'confidence' => $confidence,
				'count'      => $count,
				'bytes'      => $count ? $this->terms_bytes( $spec ) : 0,
				'samples'    => $count ? $this->term_samples( $spec ) : array(),
				'spec'       => $spec,
			) + $this->owner_fields( in_array( $taxonomy, array( 'post_tag', 'category' ), true ) ? $all['core'] : $all['woocommerce'] );
		}
		return $items;
	}

	/**
	 * Safe WHERE for a terms spec (alias "t" = term_taxonomy).
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return string
	 */
	public function term_where( array $spec ): string {
		global $wpdb;
		$where = (string) $wpdb->prepare( 't.taxonomy = %s', (string) ( $spec['taxonomy'] ?? '' ) );
		if ( ! empty( $spec['unused'] ) ) {
			$where  .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} AS r WHERE r.term_taxonomy_id = t.term_taxonomy_id)";
			$where  .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} AS c WHERE c.parent = t.term_id AND c.taxonomy = t.taxonomy)";
			$protect = array_filter( array( (int) get_option( 'default_category' ), (int) get_option( 'default_product_cat' ) ) );
			if ( $protect ) {
				$where .= ' AND t.term_id NOT IN (' . LucidLab_DBC_Helpers::in_ints( $protect ) . ')';
			}
			// Terms linked from a navigation menu stay.
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS mm WHERE mm.meta_key = '_menu_item_object_id' AND mm.meta_value = CAST(t.term_id AS CHAR) AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS mo WHERE mo.post_id = mm.post_id AND mo.meta_key = '_menu_item_type' AND mo.meta_value = 'taxonomy'))";
		}
		return $where;
	}

	/**
	 * "340 links to posts" detail.
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return string
	 */
	private function terms_detail( string $taxonomy ): string {
		global $wpdb;
		$links = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} AS r INNER JOIN {$wpdb->term_taxonomy} AS t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE t.taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan.
		/* translators: %s: number of term links. */
		return sprintf( _n( '%s link to posts', '%s links to posts', $links, 'lucidlab-db-cleanup' ), number_format_i18n( $links ) );
	}

	/**
	 * Estimated bytes of a terms selection (terms, taxonomy rows, links, term meta).
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return int
	 */
	private function terms_bytes( array $spec ): int {
		global $wpdb;
		$where = $this->term_where( $spec );
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.description)),0) AS descr FROM {$wpdb->term_taxonomy} AS t WHERE {$where}", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by term_where().
		$links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} AS r INNER JOIN {$wpdb->term_taxonomy} AS t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE {$where}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by term_where().
		$meta  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(m.meta_key)+LENGTH(m.meta_value)+40),0) FROM {$wpdb->termmeta} AS m INNER JOIN {$wpdb->term_taxonomy} AS t ON t.term_id = m.term_id WHERE {$where}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by term_where().
		return (int) ( $row['cnt'] ?? 0 ) * ( LucidLab_DBC_Helpers::avg_row_bytes( $wpdb->term_taxonomy ) + LucidLab_DBC_Helpers::avg_row_bytes( $wpdb->terms ) ) + (int) ( $row['descr'] ?? 0 ) + $links * LucidLab_DBC_Helpers::avg_row_bytes( $wpdb->term_relationships ) + $meta;
	}

	/**
	 * Sample terms.
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function term_samples( array $spec ): array {
		global $wpdb;
		$where = $this->term_where( $spec );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.count, n.name, n.slug FROM {$wpdb->term_taxonomy} AS t LEFT JOIN {$wpdb->terms} AS n ON n.term_id = t.term_id WHERE {$where} ORDER BY t.term_id DESC LIMIT %d", self::SAMPLES ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by term_where().
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'label' => '' !== (string) $row['name'] ? (string) $row['name'] : '#' . (int) $row['term_id'],
				'meta'  => '#' . (int) $row['term_id'] . ' · ' . (string) $row['slug'],
			);
		}
		return $out;
	}

	/* ---------- comments ---------- */

	/**
	 * Spam, trashed and orphaned comments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_comments(): array {
		global $wpdb;
		$core  = $this->owner_fields( LucidLab_DBC_Known::all()['core'] );
		$items = array();
		$defs  = array(
			'spam'   => array(
				__( 'Spam comments', 'lucidlab-db-cleanup' ),
				__( 'Marked as spam. WordPress keeps them only so you can review false positives.', 'lucidlab-db-cleanup' ),
				self::SAFE,
			),
			'trash'  => array(
				__( 'Trashed comments', 'lucidlab-db-cleanup' ),
				__( 'Comments and reviews already moved to the Trash.', 'lucidlab-db-cleanup' ),
				self::SAFE,
			),
			'pings'  => array(
				__( 'Pingbacks and trackbacks', 'lucidlab-db-cleanup' ),
				__( 'Automatic notifications from other sites that link to a post. Most sites do not show them; check first if your theme lists them under posts.', 'lucidlab-db-cleanup' ),
				self::REVIEW,
			),
			'orphan' => array(
				__( 'Comments of deleted posts', 'lucidlab-db-cleanup' ),
				__( 'Comments, reviews and order notes whose post, product or order no longer exists.', 'lucidlab-db-cleanup' ),
				self::SAFE,
			),
		);
		foreach ( $defs as $key => $def ) {
			$spec    = array(
				'mode'   => 'comments',
				'filter' => $key,
			);
			$where   = $this->rows_where( $spec );
			$row     = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.comment_content)+LENGTH(t.comment_author)+LENGTH(t.comment_author_email)+LENGTH(t.comment_agent)+200),0) AS bytes FROM {$wpdb->comments} AS t WHERE {$where}", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
			$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.comment_ID, t.comment_post_ID, t.comment_type, t.comment_author, LEFT(t.comment_content, 100) AS txt FROM {$wpdb->comments} AS t WHERE {$where} ORDER BY t.comment_ID DESC LIMIT %d", self::SAMPLES ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
			$samples = array();
			foreach ( $rows as $comment ) {
				$samples[] = array(
					'label' => LucidLab_DBC_Helpers::excerpt( (string) $comment['txt'], 80 ),
					'meta'  => '#' . (int) $comment['comment_ID'] . ' · ' . ( '' !== (string) $comment['comment_type'] ? (string) $comment['comment_type'] : 'comment' ) . ' · post ' . (int) $comment['comment_post_ID'] . ' · ' . LucidLab_DBC_Helpers::excerpt( (string) $comment['comment_author'], 30 ),
				);
			}
			$count   = (int) ( $row['cnt'] ?? 0 );
			$items[] = array(
				'id'         => 'comments:' . $key,
				'group'      => __( 'Comments', 'lucidlab-db-cleanup' ),
				'label'      => $def[0],
				'reason'     => $def[1],
				'confidence' => $def[2],
				'count'      => $count,
				'bytes'      => (int) ( $row['bytes'] ?? 0 ),
				'samples'    => $samples,
				'more'       => max( 0, $count - count( $samples ) ),
				'spec'       => $spec,
			) + $core;
		}
		return $items;
	}

	/* ---------- options ---------- */

	/**
	 * Options of inactive or removed plugins and themes, plus an autoload report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_options(): array {
		global $wpdb;
		$items    = array();
		$rows     = (array) $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS len, autoload FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan of all option names.
		$groups   = array();
		$copies   = array();
		$autoload = array();
		$auto_sum = 0;
		$themes   = LucidLab_DBC_Known::plugin_inventory()['themes'];
		$no_auto  = array( 'no', 'off', 'auto-off' );
		foreach ( $rows as $row ) {
			$name = (string) $row['option_name'];
			$len  = (int) $row['len'];
			$auto = ! in_array( (string) $row['autoload'], $no_auto, true );
			if ( $auto ) {
				$auto_sum         += $len;
				$autoload[ $name ] = $len;
			}
			if ( str_starts_with( $name, 'theme_mods_' ) ) {
				$theme = substr( $name, 11 );
				if ( ! in_array( $theme, $themes, true ) ) {
					$installed = wp_get_theme( $theme )->exists();
					$items[]   = array(
						'id'           => 'options:theme_mods:' . $theme,
						'group'        => __( 'Theme settings', 'lucidlab-db-cleanup' ),
						/* translators: %s: theme folder name. */
						'label'        => sprintf( __( 'Customizer settings of theme “%s”', 'lucidlab-db-cleanup' ), $theme ),
						'detail'       => $name,
						'reason'       => $installed ? __( 'Settings of an installed theme that is not active. You lose them if you switch back to this theme.', 'lucidlab-db-cleanup' ) : __( 'Settings of a theme that is no longer installed.', 'lucidlab-db-cleanup' ),
						'confidence'   => self::REVIEW,
						'count'        => 1,
						'bytes'        => $len + strlen( $name ),
						'autoload'     => $auto ? $len : 0,
						'samples'      => $this->option_samples( array( $name ) ),
						'spec'         => array(
							'mode'  => 'options',
							'names' => array( $name ),
						),
						'source'       => $theme,
						'source_label' => LucidLab_DBC_Known::status_label( $installed ? LucidLab_DBC_Known::STATUS_INACTIVE : LucidLab_DBC_Known::STATUS_MISSING, 'theme' ),
						'source_state' => $installed ? LucidLab_DBC_Known::STATUS_INACTIVE : LucidLab_DBC_Known::STATUS_MISSING,
					);
				}
				continue;
			}
			// Options copied with another table prefix as part of the name (site migrations).
			if ( str_starts_with( $name, $wpdb->prefix ) && 'wp_' !== $wpdb->prefix && $wpdb->prefix . 'user_roles' !== $name ) {
				$copies[ $name ] = $len;
				continue;
			}
			$owner = LucidLab_DBC_Known::owner( 'options', $name );
			if ( ! $owner || ! in_array( $owner['status'], array( LucidLab_DBC_Known::STATUS_MISSING, LucidLab_DBC_Known::STATUS_INACTIVE ), true ) ) {
				continue;
			}
			$id = (string) $owner['id'];
			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'owner'    => $owner,
					'names'    => array(),
					'bytes'    => 0,
					'autoload' => 0,
				);
			}
			$groups[ $id ]['names'][ $name ] = $len;
			$groups[ $id ]['bytes']         += $len + strlen( $name ) + 30;
			$groups[ $id ]['autoload']      += $auto ? $len : 0;
		}
		foreach ( $groups as $id => $group ) {
			arsort( $group['names'] );
			$names   = array_map( 'strval', array_keys( $group['names'] ) );
			$items[] = array(
				'id'         => 'options:plugin:' . $id,
				'group'      => 'theme' === $group['owner']['type'] ? __( 'Theme settings', 'lucidlab-db-cleanup' ) : __( 'Plugin settings', 'lucidlab-db-cleanup' ),
				/* translators: %s: plugin name. */
				'label'      => sprintf( __( 'Settings of %s', 'lucidlab-db-cleanup' ), $group['owner']['name'] ),
				/* translators: 1: number of options, 2: autoloaded size. */
				'detail'     => sprintf( _n( '%1$d option, %2$s autoloaded on every page view', '%1$d options, %2$s autoloaded on every page view', count( $names ), 'lucidlab-db-cleanup' ), count( $names ), LucidLab_DBC_Helpers::bytes( $group['autoload'] ) ),
				'reason'     => LucidLab_DBC_Known::STATUS_INACTIVE === $group['owner']['status'] ? __( 'The plugin is installed but not active. Its settings are lost if you delete them and activate it again later.', 'lucidlab-db-cleanup' ) : __( 'The plugin that saved these options is no longer installed; nothing reads them.', 'lucidlab-db-cleanup' ),
				'confidence' => $this->leftover_confidence( $group['owner'] ),
				'count'      => count( $names ),
				'bytes'      => $group['bytes'],
				'autoload'   => $group['autoload'],
				'samples'    => $this->option_samples( array_slice( $names, 0, self::SAMPLES ) ),
				'more'       => max( 0, count( $names ) - self::SAMPLES ),
				'spec'       => array(
					'mode'  => 'options',
					'owner' => $id,
					'names' => $names,
				),
			) + $this->owner_fields( $group['owner'] );
		}
		if ( $copies ) {
			arsort( $copies );
			$names   = array_map( 'strval', array_keys( $copies ) );
			$items[] = array(
				'id'           => 'options:prefixcopies',
				'group'        => __( 'Migration leftovers', 'lucidlab-db-cleanup' ),
				/* translators: %s: table prefix. */
				'label'        => sprintf( __( 'Options whose name starts with the table prefix “%s”', 'lucidlab-db-cleanup' ), $wpdb->prefix ),
				'reason'       => __( 'Usually created when a site was moved and a migration tool renamed “wp_…” option names as if they were tables. Plugins look for the original names, so these copies are normally unused. Check the list before deleting.', 'lucidlab-db-cleanup' ),
				'confidence'   => self::REVIEW,
				'count'        => count( $names ),
				'bytes'        => array_sum( $copies ) + 40 * count( $names ),
				'samples'      => $this->option_samples( array_slice( $names, 0, self::SAMPLES ) ),
				'more'         => max( 0, count( $names ) - self::SAMPLES ),
				'spec'         => array(
					'mode'  => 'options',
					'names' => $names,
				),
				'source'       => __( 'Site migration', 'lucidlab-db-cleanup' ),
				'source_label' => LucidLab_DBC_Known::status_label( LucidLab_DBC_Known::STATUS_UNKNOWN ),
				'source_state' => LucidLab_DBC_Known::STATUS_UNKNOWN,
			);
		}

		// Autoload report (information only).
		arsort( $autoload );
		$top     = array_slice( $autoload, 0, self::SAMPLES, true );
		$samples = array();
		foreach ( $top as $name => $len ) {
			$owner     = LucidLab_DBC_Known::owner( 'options', (string) $name );
			$samples[] = array(
				'label'   => (string) $name,
				'meta'    => LucidLab_DBC_Helpers::bytes( $len ) . ' · ' . ( $owner ? $owner['name'] . ' (' . $owner['status_label'] . ')' : __( 'Unknown owner', 'lucidlab-db-cleanup' ) ),
				'inspect' => array(
					'type' => 'option',
					'id'   => (string) $name,
				),
			);
		}
		$items[] = array(
			'id'         => 'options:autoload',
			'group'      => __( 'Autoload report', 'lucidlab-db-cleanup' ),
			/* translators: 1: total size, 2: number of options. */
			'label'      => sprintf( __( 'Autoloaded options: %1$s in %2$s options', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( $auto_sum ), number_format_i18n( count( $autoload ) ) ),
			'detail'     => $auto_sum > 800 * KB_IN_BYTES ? __( 'Above 800 KB: WordPress Site Health warns about this and every page view gets slower.', 'lucidlab-db-cleanup' ) : __( 'Below the 800 KB that Site Health warns about.', 'lucidlab-db-cleanup' ),
			'reason'     => __( 'Loaded into memory on every request. This list shows the largest ones; remove leftovers above to make it smaller.', 'lucidlab-db-cleanup' ),
			'confidence' => self::INFO,
			'count'      => count( $autoload ),
			'bytes'      => 0,
			'autoload'   => $auto_sum,
			'samples'    => $samples,
			'always'     => true,
			'spec'       => array( 'mode' => 'none' ),
		) + $this->owner_fields( LucidLab_DBC_Known::all()['core'] );
		return $items;
	}

	/**
	 * Option sample rows.
	 *
	 * @param array<int,string> $names Option names.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function option_samples( array $names ): array {
		global $wpdb;
		if ( ! $names ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS len, autoload, LEFT(option_value, 80) AS val FROM {$wpdb->options} WHERE option_name IN (" . LucidLab_DBC_Helpers::in_strings( $names ) . ') ORDER BY len DESC', ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The IN list is prepared by in_strings().
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'label'   => (string) $row['option_name'],
				'meta'    => LucidLab_DBC_Helpers::bytes( (int) $row['len'] ) . ' · autoload ' . (string) $row['autoload'] . ' · ' . LucidLab_DBC_Helpers::excerpt( (string) $row['val'], 50 ),
				'inspect' => array(
					'type' => 'option',
					'id'   => (string) $row['option_name'],
				),
			);
		}
		return $out;
	}

	/* ---------- transients ---------- */

	/**
	 * Expired transients and oEmbed caches.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_transients(): array {
		global $wpdb;
		$core  = $this->owner_fields( LucidLab_DBC_Known::all()['core'] );
		$items = array();
		$now   = time();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan.
		$names = array();
		foreach ( $rows as $row ) {
			$timeout = (string) $row['option_name'];
			$names[] = $timeout;
			$names[] = (string) preg_replace( '/_timeout_/', '_', $timeout, 1 );
		}
		if ( $names ) {
			$bytes   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_name)+LENGTH(option_value)+30),0) FROM {$wpdb->options} WHERE option_name IN (" . LucidLab_DBC_Helpers::in_strings( $names ) . ')' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The IN list is prepared by in_strings().
			$items[] = array(
				'id'         => 'transients:expired',
				'group'      => __( 'Transients', 'lucidlab-db-cleanup' ),
				'label'      => __( 'Expired transients', 'lucidlab-db-cleanup' ),
				'reason'     => __( 'Temporary cache entries past their expiry date. WordPress would recreate them when needed.', 'lucidlab-db-cleanup' ),
				'confidence' => self::SAFE,
				'count'      => count( $rows ),
				'bytes'      => $bytes,
				'samples'    => $this->option_samples( array_slice( $names, 0, self::SAMPLES ) ),
				'spec'       => array(
					'mode'  => 'transients',
					'names' => $names,
				),
			) + $core;
		}

		$spec    = array( 'mode' => 'oembed_meta' );
		$where   = $this->rows_where( $spec );
		$row     = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.meta_key)+LENGTH(t.meta_value)+40),0) AS bytes FROM {$wpdb->postmeta} AS t WHERE {$where}", ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
		$items[] = array(
			'id'         => 'transients:oembed_meta',
			'group'      => __( 'oEmbed caches', 'lucidlab-db-cleanup' ),
			'label'      => __( 'oEmbed cache in post meta', 'lucidlab-db-cleanup' ),
			'reason'     => __( 'Cached HTML of embedded videos and posts. WordPress fetches it again the next time the post is shown.', 'lucidlab-db-cleanup' ),
			'confidence' => self::SAFE,
			'count'      => (int) ( $row['cnt'] ?? 0 ),
			'bytes'      => (int) ( $row['bytes'] ?? 0 ),
			'samples'    => $this->meta_samples( $this->meta_tables()['postmeta'], $where ),
			'spec'       => $spec,
		) + $core;

		$spec    = array(
			'mode' => 'posts',
			'type' => 'oembed_cache',
		);
		$items[] = array(
			'id'         => 'transients:oembed_posts',
			'group'      => __( 'oEmbed caches', 'lucidlab-db-cleanup' ),
			'label'      => __( 'oEmbed cache posts', 'lucidlab-db-cleanup' ),
			'reason'     => __( 'Cached embeds used by the block editor. WordPress recreates them on demand.', 'lucidlab-db-cleanup' ),
			'confidence' => self::SAFE,
			'spec'       => $spec,
		) + $this->posts_summary( $spec ) + $core;
		return $items;
	}

	/* ---------- WooCommerce ---------- */

	/**
	 * WooCommerce row-group definitions: table suffix, primary key, label, reason, confidence, cascade.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function wc_defs(): array {
		$days = LucidLab_DBC_Automation::as_days();
		return array(
			'wc_sessions'    => array(
				'table'  => 'woocommerce_sessions',
				'pk'     => 'session_id',
				'group'  => __( 'Sessions', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Expired customer sessions', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Shopping-cart sessions past their expiry time. WooCommerce removes them itself when WP-Cron runs.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
			'wc_items'       => array(
				'table'    => 'woocommerce_order_items',
				'pk'       => 'order_item_id',
				'group'    => __( 'Orders', 'lucidlab-db-cleanup' ),
				'label'    => __( 'Order items of deleted orders', 'lucidlab-db-cleanup' ),
				'reason'   => __( 'Line items whose order exists neither as a post nor in the HPOS order table.', 'lucidlab-db-cleanup' ),
				'conf'     => self::SAFE,
				'children' => array( array( 'woocommerce_order_itemmeta', 'order_item_id' ) ),
			),
			'wc_itemmeta'    => array(
				'table'  => 'woocommerce_order_itemmeta',
				'pk'     => 'meta_id',
				'group'  => __( 'Orders', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Order item meta without an order item', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Meta rows of order items that no longer exist.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
			'wc_product_lkp' => array(
				'table'  => 'wc_product_meta_lookup',
				'pk'     => 'product_id',
				'group'  => __( 'Lookup tables', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Product lookup rows of deleted products', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Search and filter index rows of products that no longer exist.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
			'wc_attr_lkp'    => array(
				'table'  => 'wc_product_attributes_lookup',
				'pk'     => '',
				'group'  => __( 'Lookup tables', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Attribute lookup rows of deleted products', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Layered-navigation index rows of products that no longer exist.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
			'wc_order_stats' => array(
				'table'  => 'wc_order_stats',
				'pk'     => 'order_id',
				'group'  => __( 'Analytics', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Analytics rows of deleted orders', 'lucidlab-db-cleanup' ),
				'reason' => __( 'WooCommerce Analytics statistics of orders that no longer exist. Removing them changes historic reports.', 'lucidlab-db-cleanup' ),
				'conf'   => self::REVIEW,
			),
			'wc_order_prod'  => array(
				'table'  => 'wc_order_product_lookup',
				'pk'     => 'order_item_id',
				'group'  => __( 'Analytics', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Analytics product rows of deleted orders', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Per-product analytics rows of orders that no longer exist. Removing them changes historic reports.', 'lucidlab-db-cleanup' ),
				'conf'   => self::REVIEW,
			),
			'as_complete'    => array(
				'table'    => 'actionscheduler_actions',
				'pk'       => 'action_id',
				'group'    => __( 'Scheduled actions', 'lucidlab-db-cleanup' ),
				/* translators: %s: number of days. */
				'label'    => sprintf( __( 'Completed scheduled actions (older than %s days)', 'lucidlab-db-cleanup' ), number_format_i18n( $days ) ),
				'reason'   => __( 'Background tasks that already ran. Action Scheduler keeps them only as history and normally purges them after 30 days. The age can be changed on the Settings tab.', 'lucidlab-db-cleanup' ),
				'conf'     => self::SAFE,
				'children' => array( array( 'actionscheduler_logs', 'action_id' ) ),
			),
			'as_canceled'    => array(
				'table'    => 'actionscheduler_actions',
				'pk'       => 'action_id',
				'group'    => __( 'Scheduled actions', 'lucidlab-db-cleanup' ),
				/* translators: %s: number of days. */
				'label'    => sprintf( __( 'Canceled scheduled actions (older than %s days)', 'lucidlab-db-cleanup' ), number_format_i18n( $days ) ),
				'reason'   => __( 'Background tasks that were canceled and will never run.', 'lucidlab-db-cleanup' ),
				'conf'     => self::SAFE,
				'children' => array( array( 'actionscheduler_logs', 'action_id' ) ),
			),
			'as_failed'      => array(
				'table'    => 'actionscheduler_actions',
				'pk'       => 'action_id',
				'group'    => __( 'Scheduled actions', 'lucidlab-db-cleanup' ),
				/* translators: %s: number of days. */
				'label'    => sprintf( __( 'Failed scheduled actions (older than %s days)', 'lucidlab-db-cleanup' ), number_format_i18n( $days ) ),
				'reason'   => __( 'Background tasks that failed. Check Tools › Scheduled Actions first if you want to know why.', 'lucidlab-db-cleanup' ),
				'conf'     => self::REVIEW,
				'children' => array( array( 'actionscheduler_logs', 'action_id' ) ),
			),
			'as_logs'        => array(
				'table'  => 'actionscheduler_logs',
				'pk'     => 'log_id',
				'group'  => __( 'Scheduled actions', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Scheduled-action logs without an action', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Log lines of actions that were already deleted.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
			'as_claims'      => array(
				'table'  => 'actionscheduler_claims',
				'pk'     => 'claim_id',
				'group'  => __( 'Scheduled actions', 'lucidlab-db-cleanup' ),
				'label'  => __( 'Scheduled-action claims without an action', 'lucidlab-db-cleanup' ),
				'reason' => __( 'Claims that no scheduled action refers to any more, created more than an hour ago. A queue runner claims actions while it works and releases the claim afterwards; claims of runners that stopped halfway stay behind.', 'lucidlab-db-cleanup' ),
				'conf'   => self::SAFE,
			),
		);
	}

	/**
	 * Orphaned and stale WooCommerce data.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_woocommerce(): array {
		global $wpdb;
		$owner = $this->owner_fields( LucidLab_DBC_Known::all()['woocommerce'] );
		$items = array();
		foreach ( $this->wc_defs() as $key => $def ) {
			$table = $wpdb->prefix . $def['table'];
			if ( ! LucidLab_DBC_Helpers::table_exists( $table ) ) {
				continue;
			}
			$spec = array(
				'mode'   => 'wc',
				'filter' => $key,
			);
			if ( in_array( $key, self::AS_AGE_FILTERS, true ) ) {
				// The age used for the scan is kept with the finding, so a later change of the setting
				// never deletes more than the scan showed.
				$spec['days'] = LucidLab_DBC_Automation::as_days();
			}
			$where = $this->rows_where( $spec );
			if ( '' === $where ) {
				continue;
			}
			$count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i AS t WHERE {$where}", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
			$bytes   = $count * LucidLab_DBC_Helpers::avg_row_bytes( $table );
			$samples = array();
			foreach ( (array) ( $def['children'] ?? array() ) as $child ) {
				$child_table = $wpdb->prefix . $child[0];
				if ( $count && LucidLab_DBC_Helpers::table_exists( $child_table ) ) {
					$children  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i AS c WHERE c.%i IN (SELECT t.%i FROM %i AS t WHERE {$where})", $child_table, $child[1], $def['pk'], $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
					$bytes    += $children * LucidLab_DBC_Helpers::avg_row_bytes( $child_table );
					$samples[] = array(
						/* translators: 1: number of rows, 2: table name. */
						'label' => sprintf( __( '+ %1$s related rows in %2$s', 'lucidlab-db-cleanup' ), number_format_i18n( $children ), $child_table ),
						'meta'  => __( 'Deleted together with the main rows', 'lucidlab-db-cleanup' ),
					);
				}
			}
			if ( $count && in_array( $key, self::AS_AGE_FILTERS, true ) ) {
				$hooks = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.hook, COUNT(*) AS cnt FROM %i AS t WHERE {$where} GROUP BY t.hook ORDER BY cnt DESC LIMIT %d", $table, self::SAMPLES ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is generated by rows_where().
				foreach ( $hooks as $hook ) {
					$samples[] = array(
						'label' => (string) $hook['hook'],
						/* translators: %s: number of actions. */
						'meta'  => sprintf( _n( '%s action', '%s actions', (int) $hook['cnt'], 'lucidlab-db-cleanup' ), number_format_i18n( (int) $hook['cnt'] ) ),
					);
				}
			}
			$items[] = array(
				'id'         => 'woocommerce:' . $key,
				'group'      => $def['group'],
				'label'      => $def['label'],
				'detail'     => $table,
				'reason'     => $def['reason'],
				'confidence' => $def['conf'],
				'count'      => $count,
				'bytes'      => $bytes,
				'samples'    => $samples,
				'spec'       => $spec,
			) + $owner;
		}
		return $items;
	}

	/* ---------- tables ---------- */

	/**
	 * Every table with the WordPress prefix, attributed to an owner.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_tables(): array {
		global $wpdb;
		$items     = array();
		$protected = array();
		$prefix    = (string) $wpdb->prefix;
		$all       = LucidLab_DBC_Known::all();
		foreach ( LucidLab_DBC_Helpers::prefixed_tables() as $table ) {
			$suffix = substr( $table, strlen( $prefix ) );
			if ( '' === $suffix || preg_match( '/^\d+_/', $suffix ) ) {
				continue; // Sub-site tables of a former network are left alone.
			}
			$stats = LucidLab_DBC_Helpers::table_stats( $table );
			if ( in_array( $suffix, LucidLab_DBC_Known::CORE_TABLES, true ) ) {
				$protected['core'][] = array( $table, $stats );
				continue;
			}
			if ( in_array( $suffix, LucidLab_DBC_Known::MULTISITE_TABLES, true ) ) {
				$items[] = $this->table_item( $table, $stats, $all['core'], self::REVIEW, __( 'Leftover multisite tables', 'lucidlab-db-cleanup' ), __( 'Network (multisite) tables on a single site. They are often created by hosting installers and are not used here. Keep them if this database was ever part of a network you still need.', 'lucidlab-db-cleanup' ) );
				continue;
			}
			$owner = LucidLab_DBC_Known::owner( 'tables', $suffix );
			if ( LucidLab_DBC_Known::is_protected( $owner ) ) {
				$protected[ (string) $owner['id'] ][] = array( $table, $stats );
				continue;
			}
			if ( ! $owner ) {
				$items[] = $this->table_item( $table, $stats, null, self::RISKY, __( 'Unknown tables', 'lucidlab-db-cleanup' ), __( 'No known plugin, theme or WordPress itself uses this table. Inspect its contents before you delete it.', 'lucidlab-db-cleanup' ) );
				continue;
			}
			if ( LucidLab_DBC_Known::STATUS_INACTIVE === $owner['status'] ) {
				$conf   = self::REVIEW;
				$reason = __( 'Belongs to a plugin that is installed but not active. The plugin loses this data if you activate it again.', 'lucidlab-db-cleanup' );
			} elseif ( LucidLab_DBC_Known::STATUS_MISSING === $owner['status'] ) {
				$conf   = $this->leftover_confidence( $owner );
				$reason = __( 'Belongs to a plugin that is no longer installed; nothing reads this table.', 'lucidlab-db-cleanup' );
				// Business records (payments, orders, users, form entries) may be needed for your own administration.
				if ( $stats['rows'] > 0 && preg_match( '/transaction|order|payment|subscri|user|customer|invoice|lead|entr(?:y|ies)|submission/i', $suffix ) ) {
					$conf    = self::REVIEW;
					$reason .= ' ' . __( 'It still holds business records such as payments, orders or customer data; keep it if you may need them for your administration.', 'lucidlab-db-cleanup' );
				}
			} else {
				$conf   = self::RISKY;
				$reason = __( 'Ownership is shared or unclear. Inspect its contents before you delete it.', 'lucidlab-db-cleanup' );
			}
			if ( 'lucidlab' === ( $owner['vendor'] ?? '' ) ) {
				/* translators: %s: plugin or theme name. */
				$reason .= ' ' . sprintf( __( 'This is data of %s (Lucid Lab). Lucid Lab plugins keep their data when they are deactivated or removed, so it comes back when you install the plugin again. Only delete it if you are sure you no longer need it.', 'lucidlab-db-cleanup' ), (string) $owner['name'] );
			} elseif ( ! empty( $owner['matched_literal'] ) ) {
				/* translators: 1: plugin name, 2: text found in its code. */
				$reason .= ' ' . sprintf( __( 'Recognised because the code of %1$s uses the table name “%2$s”.', 'lucidlab-db-cleanup' ), (string) $owner['name'], (string) $owner['matched_literal'] );
			}
			$items[] = $this->table_item( $table, $stats, $owner, $conf, (string) $owner['name'], $reason );
		}
		foreach ( $protected as $id => $tables ) {
			$owner   = $all[ $id ] ?? null;
			$samples = array();
			$bytes   = 0;
			foreach ( $tables as $pair ) {
				$bytes    += $pair[1]['bytes'];
				$samples[] = array(
					'label'   => $pair[0],
					/* translators: 1: number of rows, 2: size. */
					'meta'    => sprintf( __( '%1$s rows · %2$s', 'lucidlab-db-cleanup' ), ( $pair[1]['approx'] ? '~' : '' ) . number_format_i18n( $pair[1]['rows'] ), LucidLab_DBC_Helpers::bytes( $pair[1]['bytes'] ) ),
					'inspect' => array(
						'type' => 'table',
						'id'   => $pair[0],
					),
				);
			}
			$items[] = array(
				'id'         => 'tables:protected:' . $id,
				'group'      => __( 'Protected tables', 'lucidlab-db-cleanup' ),
				/* translators: %s: owner name. */
				'label'      => sprintf( __( 'Tables of %s', 'lucidlab-db-cleanup' ), $owner ? $owner['name'] : $id ),
				/* translators: %s: total size. */
				'detail'     => sprintf( __( 'In use, %s in total', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( $bytes ) ),
				'reason'     => __( 'Used by WordPress or an active plugin or theme. Never offered for deletion.', 'lucidlab-db-cleanup' ),
				'confidence' => self::INFO,
				'count'      => count( $tables ),
				'bytes'      => 0,
				'samples'    => $samples,
				'spec'       => array( 'mode' => 'none' ),
			) + $this->owner_fields( $owner );
		}
		return $items;
	}

	/**
	 * One table finding.
	 *
	 * @param string                   $table  Table.
	 * @param array<string,mixed>      $stats  Stats.
	 * @param array<string,mixed>|null $owner  Owner.
	 * @param string                   $conf   Confidence.
	 * @param string                   $group  Group label.
	 * @param string                   $reason Reason.
	 *
	 * @return array<string,mixed>
	 */
	private function table_item( string $table, array $stats, ?array $owner, string $conf, string $group, string $reason ): array {
		return array(
			'id'         => 'tables:' . $table,
			'group'      => $group,
			/* translators: %s: table name. */
			'label'      => sprintf( __( 'Table %s', 'lucidlab-db-cleanup' ), $table ),
			/* translators: 1: number of rows, 2: storage engine. */
			'detail'     => sprintf( __( '%1$s rows · engine %2$s', 'lucidlab-db-cleanup' ), ( $stats['approx'] ? '~' : '' ) . number_format_i18n( (int) $stats['rows'] ), (string) $stats['engine'] ),
			'reason'     => $reason,
			'confidence' => $conf,
			'count'      => 1,
			'rows'       => (int) $stats['rows'],
			'bytes'      => max( (int) $stats['bytes'], 16384 ),
			'samples'    => array(
				array(
					'label'   => $table,
					/* translators: 1: data size, 2: index size. */
					'meta'    => sprintf( __( 'Data %1$s · index %2$s', 'lucidlab-db-cleanup' ), LucidLab_DBC_Helpers::bytes( (int) $stats['data'] ), LucidLab_DBC_Helpers::bytes( (int) $stats['index'] ) ),
					'inspect' => array(
						'type' => 'table',
						'id'   => $table,
					),
				),
			),
			'spec'       => array(
				'mode'  => 'table',
				'table' => $table,
			),
		) + $this->owner_fields( $owner );
	}

	/* ---------- selections ---------- */

	/**
	 * Safe WHERE (alias "t") for row-based specs. Values are prepared; names come from $wpdb or fixed lists.
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return string '' when the spec is not row based or unknown.
	 */
	public function rows_where( array $spec ): string {
		global $wpdb;
		$mode = (string) ( $spec['mode'] ?? '' );
		switch ( $mode ) {
			case 'orphan_meta':
				$def = $this->meta_tables()[ (string) ( $spec['meta'] ?? '' ) ] ?? null;
				if ( ! $def ) {
					return '';
				}
				$where = "NOT EXISTS (SELECT 1 FROM {$def['parent']} AS p WHERE p.{$def['parent_pk']} = t.{$def['fk']})";
				return 'postmeta' === $spec['meta'] ? $where . $this->hpos_guard( 't.post_id' ) : $where;
			case 'meta_keys':
				$def  = $this->meta_tables()[ (string) ( $spec['meta'] ?? '' ) ] ?? null;
				$keys = array_values( array_filter( array_map( 'strval', (array) ( $spec['keys'] ?? array() ) ) ) );
				if ( ! $def || ! $keys ) {
					return '';
				}
				$where   = 't.meta_key IN (' . LucidLab_DBC_Helpers::in_strings( $keys ) . ')';
				$exclude = array_values( array_filter( array_map( 'strval', (array) ( $spec['exclude_types'] ?? array() ) ) ) );
				if ( 'postmeta' === $spec['meta'] && $exclude ) {
					$where .= " AND t.post_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . LucidLab_DBC_Helpers::in_strings( $exclude ) . '))';
				}
				return $where;
			case 'oembed_meta':
				return (string) $wpdb->prepare( 't.meta_key LIKE %s', $wpdb->esc_like( '_oembed_' ) . '%' );
			case 'orphan_terms':
				switch ( (string) ( $spec['filter'] ?? '' ) ) {
					case 'tr_object':
						return "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p WHERE p.ID = t.object_id) AND EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} AS x WHERE x.term_taxonomy_id = t.term_taxonomy_id" . $this->object_taxonomy_guard() . ')' . $this->hpos_guard( 't.object_id' );
					case 'tr_tt':
						return "NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} AS x WHERE x.term_taxonomy_id = t.term_taxonomy_id)";
					case 'tt_term':
						return "NOT EXISTS (SELECT 1 FROM {$wpdb->terms} AS x WHERE x.term_id = t.term_id)";
					case 'term_tt':
						return "NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} AS x WHERE x.term_id = t.term_id)";
				}
				return '';
			case 'comments':
				switch ( (string) ( $spec['filter'] ?? '' ) ) {
					case 'spam':
						return "t.comment_approved = 'spam'";
					case 'trash':
						return "t.comment_approved = 'trash'";
					case 'pings':
						return "t.comment_type IN ('pingback','trackback') AND t.comment_approved NOT IN ('spam','trash')";
					case 'orphan':
						return "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p WHERE p.ID = t.comment_post_ID)" . $this->hpos_guard( 't.comment_post_ID' );
				}
				return '';
			case 'options':
			case 'transients':
				$names = array_values( array_filter( array_map( 'strval', (array) ( $spec['names'] ?? array() ) ) ) );
				return $names ? 't.option_name IN (' . LucidLab_DBC_Helpers::in_strings( $names ) . ')' : '';
			case 'wc':
				return $this->wc_where( (string) ( $spec['filter'] ?? '' ), isset( $spec['days'] ) ? (int) $spec['days'] : LucidLab_DBC_Automation::as_days() );
		}
		/**
		 * Filters the WHERE clause (alias "t") of a row-based spec of an add-on. Return '' for unknown specs.
		 *
		 * @param string               $where   Default ''.
		 * @param array<string,mixed>  $spec    Spec.
		 * @param LucidLab_DBC_Scanner $scanner Scanner.
		 */
		return (string) apply_filters( 'lucidlab_dbc_rows_where', '', $spec, $this );
	}

	/**
	 * Table, key and cascade for a row-based spec.
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return array{table:string,pk:string,where:string,children:array<int,array{0:string,1:string}>}|null
	 */
	public function target( array $spec ): ?array {
		global $wpdb;
		$where = $this->rows_where( $spec );
		if ( '' === $where ) {
			return null;
		}
		$mode     = (string) $spec['mode'];
		$table    = '';
		$pk       = '';
		$children = array();
		if ( in_array( $mode, array( 'orphan_meta', 'meta_keys' ), true ) ) {
			$def   = $this->meta_tables()[ (string) $spec['meta'] ];
			$table = $def['table'];
			$pk    = $def['pk'];
		} elseif ( 'oembed_meta' === $mode ) {
			$table = $wpdb->postmeta;
			$pk    = 'meta_id';
		} elseif ( 'orphan_terms' === $mode ) {
			$filter = (string) $spec['filter'];
			if ( 'tt_term' === $filter ) {
				$table    = $wpdb->term_taxonomy;
				$pk       = 'term_taxonomy_id';
				$children = array( array( $wpdb->term_relationships, 'term_taxonomy_id' ) );
			} elseif ( 'term_tt' === $filter ) {
				$table    = $wpdb->terms;
				$pk       = 'term_id';
				$children = array( array( $wpdb->termmeta, 'term_id' ) );
			} else {
				$table = $wpdb->term_relationships;
			}
		} elseif ( 'comments' === $mode ) {
			$table    = $wpdb->comments;
			$pk       = 'comment_ID';
			$children = array( array( $wpdb->commentmeta, 'comment_id' ) );
		} elseif ( in_array( $mode, array( 'options', 'transients' ), true ) ) {
			$table = $wpdb->options;
			$pk    = 'option_id';
		} elseif ( 'wc' === $mode ) {
			$def = $this->wc_defs()[ (string) $spec['filter'] ] ?? null;
			if ( ! $def ) {
				return null;
			}
			$table = $wpdb->prefix . $def['table'];
			$pk    = (string) $def['pk'];
			foreach ( (array) ( $def['children'] ?? array() ) as $child ) {
				if ( LucidLab_DBC_Helpers::table_exists( $wpdb->prefix . $child[0] ) ) {
					$children[] = array( $wpdb->prefix . $child[0], $child[1] );
				}
			}
		}
		if ( '' === $table ) {
			/**
			 * Filters the table, key and cascade of a row-based spec of an add-on.
			 *
			 * @param array|null           $target  Default null: array{table:string,pk:string,where:string,children:array}.
			 * @param array<string,mixed>  $spec    Spec.
			 * @param string               $where   WHERE from lucidlab_dbc_rows_where.
			 */
			$custom = apply_filters( 'lucidlab_dbc_target', null, $spec, $where );
			if ( is_array( $custom ) && ! empty( $custom['table'] ) && LucidLab_DBC_Helpers::table_exists( (string) $custom['table'] ) && str_starts_with( (string) $custom['table'], (string) $wpdb->prefix ) ) {
				return array(
					'table'    => (string) $custom['table'],
					'pk'       => (string) ( $custom['pk'] ?? '' ),
					'where'    => $where,
					'children' => (array) ( $custom['children'] ?? array() ),
				);
			}
			return null;
		}
		if ( ! LucidLab_DBC_Helpers::table_exists( $table ) ) {
			return null;
		}
		return array(
			'table'    => $table,
			'pk'       => $pk,
			'where'    => $where,
			'children' => $children,
		);
	}

	/**
	 * Excludes taxonomies whose terms link to something other than posts (links, users, ...).
	 *
	 * @return string SQL fragment for alias "x" (term_taxonomy).
	 */
	private function object_taxonomy_guard(): string {
		$guarded = array( 'link_category' );
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			$types = array_filter( (array) $taxonomy->object_type, 'post_type_exists' );
			if ( ! $types && ! empty( $taxonomy->object_type ) ) {
				$guarded[] = (string) $name;
			}
		}
		return ' AND x.taxonomy NOT IN (' . LucidLab_DBC_Helpers::in_strings( array_values( array_unique( $guarded ) ) ) . ')';
	}

	/**
	 * Keeps rows that point at an HPOS order (WooCommerce custom order tables).
	 *
	 * @param string $column Column holding the object id.
	 *
	 * @return string
	 */
	private function hpos_guard( string $column ): string {
		global $wpdb;
		$orders = $wpdb->prefix . 'wc_orders';
		if ( ! LucidLab_DBC_Helpers::table_exists( $orders ) ) {
			return '';
		}
		return " AND NOT EXISTS (SELECT 1 FROM {$orders} AS o WHERE o.id = {$column})";
	}

	/**
	 * WHERE for a WooCommerce filter.
	 *
	 * @param string $filter Filter key.
	 * @param int    $days   Age in days of finished scheduled actions.
	 *
	 * @return string
	 */
	private function wc_where( string $filter, int $days ): string {
		global $wpdb;
		$cutoff = LucidLab_DBC_Helpers::gmt_days_ago( LucidLab_DBC_Automation::clamp_as_days( $days ) );
		switch ( $filter ) {
			case 'wc_sessions':
				return (string) $wpdb->prepare( 't.session_expiry < %d', time() );
			case 'wc_items':
				return "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p WHERE p.ID = t.order_id)" . $this->hpos_guard( 't.order_id' );
			case 'wc_itemmeta':
				return "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items AS i WHERE i.order_item_id = t.order_item_id)";
			case 'wc_product_lkp':
			case 'wc_attr_lkp':
				return "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p WHERE p.ID = t.product_id)";
			case 'wc_order_stats':
			case 'wc_order_prod':
				return "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS p WHERE p.ID = t.order_id)" . $this->hpos_guard( 't.order_id' );
			case 'as_complete':
				return (string) $wpdb->prepare( 't.status = %s AND t.scheduled_date_gmt < %s AND t.last_attempt_gmt < %s', 'complete', $cutoff, $cutoff );
			case 'as_canceled':
				return (string) $wpdb->prepare( 't.status = %s AND t.scheduled_date_gmt < %s', 'canceled', $cutoff );
			case 'as_failed':
				return (string) $wpdb->prepare( 't.status = %s AND t.scheduled_date_gmt < %s', 'failed', $cutoff );
			case 'as_logs':
				return "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions AS a WHERE a.action_id = t.action_id)";
			case 'as_claims':
				// Claim ids are set on actions while a runner works on them; an hour of margin keeps a
				// claim that a running queue has just created.
				return (string) $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions AS a WHERE a.claim_id = t.claim_id) AND t.date_created_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		}
		return '';
	}

	/* ---------- re-validation before cleanup ---------- */

	/**
	 * Options that are never deleted, whatever a scan result claims.
	 */
	private const NEVER_OPTIONS = array( 'siteurl', 'home', 'blogname', 'blogdescription', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', 'admin_email', 'permalink_structure', 'woocommerce_version', 'woocommerce_db_version', 'recently_activated', 'uninstall_plugins' );

	/**
	 * Re-check a stored finding against the live site right before it is cleaned.
	 *
	 * @param array<string,mixed> $item Stored finding.
	 *
	 * @return array<string,mixed>|WP_Error Item with a possibly narrowed spec.
	 */
	public function revalidate( array $item ) {
		global $wpdb;
		if ( empty( $item['deletable'] ) || self::INFO === ( $item['confidence'] ?? '' ) ) {
			return new WP_Error( 'lucidlab_dbc_info', __( 'This finding is information only.', 'lucidlab-db-cleanup' ) );
		}
		$spec = (array) ( $item['spec'] ?? array() );
		$mode = (string) ( $spec['mode'] ?? '' );
		$id   = (string) ( $item['id'] ?? '' );
		if ( str_starts_with( $id, 'post_types:' ) ) {
			$type = (string) ( $spec['type'] ?? '' );
			if ( '' === $type || post_type_exists( $type ) || in_array( $type, LucidLab_DBC_Known::CORE_POST_TYPES, true ) ) {
				return new WP_Error( 'lucidlab_dbc_registered', __( 'This post type is registered again; skipped.', 'lucidlab-db-cleanup' ) );
			}
		}
		if ( 'table' === $mode ) {
			$table  = (string) ( $spec['table'] ?? '' );
			$suffix = str_starts_with( $table, (string) $wpdb->prefix ) ? substr( $table, strlen( (string) $wpdb->prefix ) ) : '';
			$owner  = '' !== $suffix ? LucidLab_DBC_Known::owner( 'tables', $suffix ) : null;
			$multi  = in_array( $suffix, LucidLab_DBC_Known::MULTISITE_TABLES, true ) && ! is_multisite();
			if ( '' === $suffix || in_array( $suffix, LucidLab_DBC_Known::CORE_TABLES, true ) || ( ! $multi && LucidLab_DBC_Known::is_protected( $owner ) ) || ! LucidLab_DBC_Helpers::table_exists( $table ) ) {
				return new WP_Error( 'lucidlab_dbc_table', __( 'This table is protected or already gone; skipped.', 'lucidlab-db-cleanup' ) );
			}
			return $item;
		}
		if ( 'options' === $mode ) {
			$keep = array();
			foreach ( (array) ( $spec['names'] ?? array() ) as $name ) {
				$name  = (string) $name;
				$owner = LucidLab_DBC_Known::owner( 'options', $name );
				if ( in_array( $name, self::NEVER_OPTIONS, true ) || $wpdb->prefix . 'user_roles' === $name || str_starts_with( $name, '_transient' ) || str_starts_with( $name, '_site_transient' ) ) {
					continue;
				}
				if ( str_starts_with( $name, 'theme_mods_' ) ) {
					if ( ! in_array( substr( $name, 11 ), LucidLab_DBC_Known::plugin_inventory()['themes'], true ) ) {
						$keep[] = $name;
					}
					continue;
				}
				if ( 'options:prefixcopies' === $id ? str_starts_with( $name, (string) $wpdb->prefix ) : ( $owner && ! LucidLab_DBC_Known::is_protected( $owner ) ) ) {
					$keep[] = $name;
				}
			}
			if ( ! $keep ) {
				return new WP_Error( 'lucidlab_dbc_options', __( 'All options of this finding are in use now; skipped.', 'lucidlab-db-cleanup' ) );
			}
			$item['spec']['names'] = $keep;
			return $item;
		}
		if ( 'transients' === $mode ) {
			$item['spec']['names'] = array_values(
				array_filter(
					array_map( 'strval', (array) ( $spec['names'] ?? array() ) ),
					static function ( $name ) {
						return str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' );
					}
				)
			);
			return $item;
		}
		if ( 'meta_keys' === $mode ) {
			$keys = array();
			foreach ( (array) ( $spec['keys'] ?? array() ) as $key ) {
				$owner = LucidLab_DBC_Known::owner( 'meta', (string) $key );
				if ( $owner && ! LucidLab_DBC_Known::is_protected( $owner ) ) {
					$keys[] = (string) $key;
				}
			}
			if ( ! $keys ) {
				return new WP_Error( 'lucidlab_dbc_meta', __( 'All meta keys of this finding are in use now; skipped.', 'lucidlab-db-cleanup' ) );
			}
			$item['spec']['keys'] = $keys;
			return $item;
		}
		if ( 'terms' === $mode ) {
			$taxonomy = (string) ( $spec['taxonomy'] ?? '' );
			if ( '' === $taxonomy || ( empty( $spec['unused'] ) && taxonomy_exists( $taxonomy ) ) ) {
				return new WP_Error( 'lucidlab_dbc_tax', __( 'This taxonomy is registered again; skipped.', 'lucidlab-db-cleanup' ) );
			}
			return $item;
		}
		if ( 'posts' === $mode || null !== $this->target( $spec ) ) {
			return $item;
		}
		return new WP_Error( 'lucidlab_dbc_unknown', __( 'Unknown finding; skipped.', 'lucidlab-db-cleanup' ) );
	}
}
