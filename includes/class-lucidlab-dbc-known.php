<?php
/**
 * Lucid Lab Database Cleaner: owner knowledge base.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps tables, options, meta keys, post types and taxonomies to the plugin or theme that
 * created them, and tells whether that owner is active, installed but inactive, or missing.
 *
 * Pattern syntax (space separated): "=name" exact name, "name*" prefix,
 * "name" exact name or name followed by "_", "-" or ".".
 */
class LucidLab_DBC_Known {

	public const STATUS_CORE     = 'core';
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_INACTIVE = 'inactive';
	public const STATUS_MISSING  = 'missing';
	public const STATUS_UNKNOWN  = 'unknown';

	/**
	 * Maximum number of code literals remembered per discovered plugin.
	 */
	private const LITERALS_MAX = 3000;

	/**
	 * Core WordPress table suffixes (after the table prefix).
	 */
	public const CORE_TABLES = array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users' );

	/**
	 * Multisite-only core table suffixes.
	 */
	public const MULTISITE_TABLES = array( 'blogs', 'blogmeta', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta' );

	/**
	 * Post types that are never offered for cleanup by type.
	 */
	public const CORE_POST_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * Post types holding orders, payments or memberships: never listed by the content checks.
	 */
	public const CRITICAL_POST_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'shop_coupon', 'wc_user_membership', 'wc_membership_plan', 'payment_retry', 'scheduled-action', 'edd_payment', 'edd_discount' );

	/**
	 * Built-in knowledge base. Keys are internal ids.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function base(): array {
		return array(
			'core'              => array(
				'name'       => 'WordPress core',
				'type'       => 'core',
				'options'    => '=siteurl =home =blogname =blogdescription =users_can_register =admin_email =start_of_week =use_balanceTags =use_smilies =require_name_email =comments_notify =posts_per_rss =rss_use_excerpt =mailserver_url =mailserver_login =mailserver_pass =mailserver_port =default_category =default_comment_status =default_ping_status =default_pingback_flag =posts_per_page =date_format =time_format =links_updated_date_format =comment_moderation =moderation_notify =permalink_structure =rewrite_rules =hack_file =blog_charset =moderation_keys =active_plugins =category_base =ping_sites =comment_max_links =gmt_offset =default_email_category =recently_edited =template =stylesheet =comment_registration =html_type =use_trackback =default_role =db_version =db_upgraded =uploads_use_yearmonth_folders =upload_path =blog_public =default_link_category =show_on_front =tag_base =show_avatars =avatar_rating =upload_url_path =thumbnail_size_w =thumbnail_size_h =thumbnail_crop =medium_size_w =medium_size_h =avatar_default =large_size_w =large_size_h =image_default_link_type =image_default_size =image_default_align =close_comments_for_old_posts =close_comments_days_old =thread_comments =thread_comments_depth =page_comments =comments_per_page =default_comments_page =comment_order =sticky_posts =uninstall_plugins =timezone_string =page_for_posts =page_on_front =default_post_format =link_manager_enabled =finished_splitting_shared_terms =site_icon =medium_large_size_w =medium_large_size_h =wp_page_for_privacy_policy =show_comments_cookies_opt_in =admin_email_lifespan =disallowed_keys =comment_previously_approved =auto_plugin_theme_update_emails =auto_update_core_dev =auto_update_core_minor =auto_update_core_major =auto_update_plugins =auto_update_themes =wp_force_deactivated_plugins =wp_attachment_pages_enabled =WPLANG =cron =recovery_mode_email_last_sent =recovery_keys =https_detection_errors =fresh_site =user_count =initial_db_version =can_compress_scripts =recently_activated =current_theme =theme_switched =theme_switched_via_customizer =theme_switch_menu_locations =customize_stashed_theme_mods =sidebars_widgets =category_children =dismissed_update_core =auto_core_update_notified =wp_calendar_block_has_published_posts =wp_notes_notify =site_logo =nav_menu_options =widget_* =_transient_* =_site_transient_* =theme_mods_* =core_updater.lock =auto_updater.lock =db_upgraded',
				'meta'       => '_edit_lock _edit_last =_thumbnail_id _wp_* _menu_item_* =_encloseme =_pingme =_customize_changeset_uuid =_customize_draft_post_name =_customize_restore_dismissed =rich_editing =syntax_highlighting =comment_shortcuts =admin_color =use_ssl =show_admin_bar_front =locale =first_name =last_name =nickname =description =dismissed_wp_pointers =show_welcome_panel =session_tokens =community-events-location closedpostboxes_* metaboxhidden_* meta-box-order_* manageedit-* screen_layout_* edit_* =_oembed_*',
				'post_types' => 'post page attachment revision nav_menu_item custom_css customize_changeset oembed_cache user_request wp_block wp_template wp_template_part wp_global_styles wp_navigation wp_font_family wp_font_face',
				'taxonomies' => 'category post_tag nav_menu link_category post_format wp_theme wp_template_part_area wp_pattern_category',
			),
			'woocommerce'       => array(
				'name'       => 'WooCommerce',
				'slugs'      => 'woocommerce',
				'tables'     => 'woocommerce_* wc_* actionscheduler_*',
				'options'    => 'woocommerce* wc_* wc-* _wc_* action_scheduler_* =as_has_wp_comment_logs =current_theme_supports_woocommerce =default_product_cat =product_cat_children =product_shipping_class_children =product_type_children =product_visibility_children',
				'meta'       => '_wc_* _woocommerce_* =_price =_regular_price =_sale_price =_sku =_stock =_stock_status =_manage_stock =_backorders =_sold_individually =_virtual =_downloadable =_download_limit =_download_expiry =_tax_status =_tax_class =_product_version =_product_attributes =_product_image_gallery =total_sales =_variation_description attribute_* _billing_* _shipping_* billing_* shipping_* =_order_key =_customer_user =paying_customer =last_update =wc_last_active _crosssell_ids _upsell_ids =_purchase_note =_featured =_visibility =_weight =_length =_width =_height =_children =_default_attributes =_sale_price_dates_from =_sale_price_dates_to =_button_text =_product_url =_low_stock_amount =_global_unique_id',
				'post_types' => 'product product_variation shop_order shop_order_refund shop_coupon shop_order_placehold',
				'taxonomies' => 'product_cat product_tag product_type product_visibility product_shipping_class product_brand pa_* =action-group',
			),
			'lucidlab-dbc'      => array(
				'name'    => 'Lucid Lab Database Cleaner',
				'type'    => 'self',
				'options' => 'lucidlab_dbc_* lucidlab_dbcp_*',
				'meta'    => 'lucidlab_dbc_* _lucidlab_dbc_* lucidlab_dbcp_*',
			),
			// Lucid Lab plugins and themes (vendor => lucidlab). Their data is protected while the owner is
			// active, and never "safe" to delete when it is inactive or missing (review only).
			'lucidlab-mpc'      => array(
				'name'       => 'Lucid Lab Media & Products Cleanup',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-mpc-cleanup media-products-cleanup',
				'tables'     => 'lucidlab_mpc_* =lucidlab_mpc_media_index =lucidlab_mpc_rename_log =mpc_media_index =mpc_rename_log mpc_*',
				'options'    => 'lucidlab_mpc_* _lucidlab_mpc_* mpc_*',
				'transients' => 'lucidlab_mpc_*',
				'meta'       => 'lucidlab_mpc_* _lucidlab_mpc_* _mpc_* mpc_*',
				'cron'       => 'lucidlab_mpc_*',
				'uploads'    => 'lucidlab-mpc-cleanup _cleanup-quarantine _cleanup-format-preview',
			),
			'lucidlab-mpc-pro'  => array(
				'name'       => 'Lucid Lab Media & Products Cleanup Pro',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-mpc-cleanup-pro media-products-cleanup-pro',
				'tables'     => '=lucidlab_mpc_visual =mpc_visual',
				'options'    => 'lucidlab_mpc_pro_* =lucidlab_mpc_visual_db =lucidlab_mpc_ai_halt =lucidlab_mpc_ai_job =lucidlab_mpc_ai_job_history',
				'transients' => 'lucidlab_mpc_pro_* lucidlab_mpc_webpclean_*',
				'meta'       => '=_lucidlab_mpc_ai_result =_lucidlab_mpc_ai_group',
				'cron'       => '=lucidlab_mpc_pro_license_sync =lucidlab_mpc_automation_retry =lucidlab_mpc_ai_tick',
			),
			'lucidlab-i2v'      => array(
				'name'       => 'Lucid Lab Image to Video',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-image-to-video image-to-video',
				'options'    => 'lucidlab_i2v_* i2v_*',
				'transients' => 'lucidlab_i2v_* i2v_*',
				'cron'       => '=lucidlab_i2v_download',
				'uploads'    => 'lucidlab-image-to-video image-to-video',
			),
			'lucidlab-pcv'      => array(
				'name'       => 'Lucid Lab Products & Category Visibility',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-pcv lucid-lab-products-category-visibility products-category-visibility',
				'options'    => 'nscv_* lucidlab_pcv_*',
				'transients' => 'nscv_* lucidlab_pcv_*',
				'meta'       => '_nscv_* nscv_*',
				'post_types' => 'nscv_source',
				'cron'       => 'nscv_*',
			),
			'lucidlab-lpm'      => array(
				'name'       => 'Lucid Lab Plannings Manager',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-plannings-manager lpm',
				'tables'     => '=lpm_departments =lpm_employees =lpm_peaks =lpm_shifts =lpm_absences =lpm_holidays =lpm_runs lpm_*',
				'options'    => '=lpm_settings =lpm_db_version lpm_*',
				'transients' => 'lpm_notice_* lpm_*',
				'meta'       => '=lpm_dept lpm_*',
			),
			'lucidlab-llft'     => array(
				'name'       => 'Lucid Lab Flight Tracker Castelldefels',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-flighttracker-castelldefels lucidlab-flighttracker llft',
				'tables'     => '=llft_air =llft_alerts =llft_flights =llft_hourly =llft_movements =llft_noise =llft_notam_hours =llft_notams =llft_tmr =llft_track =llft_violations llft_*',
				'options'    => 'llft_*',
				'transients' => 'llft_*',
				'cron'       => 'llft_*',
				'uploads'    => 'llft-backup llft-backup-*',
			),
			'lucidlab-ww'       => array(
				'name'       => 'Lucid Lab Web Watcher / Found Text',
				'vendor'     => 'lucidlab',
				'slugs'      => 'lucidlab-webwatcher web-watcher found-text lucidlab-textsearch',
				'tables'     => '=found_text_results found_text_*',
				'options'    => 'found_text_*',
				'transients' => 'found_text_*',
				'cron'       => '=found_text_run_search =found_text_run_now',
			),
			'lucidlab-theme'    => array(
				'name'       => 'Lucid Lab Theme',
				'vendor'     => 'lucidlab',
				'type'       => 'theme',
				'slugs'      => 'lucidlab-theme lucidlab',
				'tables'     => '=mpc_free_installs =mpc_license_events =mpc_license_sites =mpc_licenses',
				'options'    => 'lucidlab_theme_* =mpc_buy_rewrite =mpc_changelog_page_id =mpc_free_installs_db mpc_license_* =mpc_pro_product_id =mpc_pro_product_thumb_id mpc_release_* mpc_releases*',
				'transients' => 'mpc_rel_notice_*',
				'cron'       => '=mpc_free_installs_purge_event =mpc_release_prune_event',
				'uploads'    => 'mpc-releases',
			),
			'nummersschilderen' => array(
				'name'    => 'Nummers Schilderen theme',
				'vendor'  => 'lucidlab',
				'type'    => 'theme',
				'slugs'   => 'nummersschilderen nummerschilderen',
				'options' => '=ns_import_complete =ns_import_pages',
				'meta'    => '=_ns_import_slug',
			),
			'ns-functions'      => array(
				'name'    => 'Nummers Schilderen Functions',
				'vendor'  => 'lucidlab',
				'slugs'   => 'nummersschilderen-functions',
				'options' => 'nummersschilderen_* =ns_import_pages',
			),
			'ns-importer'       => array(
				'name'       => 'Nummers Schilderen Importer',
				'vendor'     => 'lucidlab',
				'slugs'      => 'nummersschilderen-importer',
				'options'    => 'ns_import_*',
				'transients' => 'ns_import_*',
				'meta'       => '=_ns_asset =_ns_import_pending _ns_import_*',
			),
			'mailster'          => array(
				'name'       => 'Mailster (newsletter)',
				'slugs'      => 'mailster myMail',
				'tables'     => 'mailster_* mymail_*',
				'options'    => 'mailster widget_mailster_* mymail',
				'meta'       => '_mailster_* mailster_* _mymail_*',
				'post_types' => 'newsletter mailster-form',
			),
			'newsletter'        => array(
				'name'    => 'Newsletter (Stefano Lissa)',
				'slugs'   => 'newsletter',
				'tables'  => '=newsletter newsletter_*',
				'options' => 'newsletter widget_newsletterwidget*',
				'meta'    => '_newsletter_*',
			),
			'mailpoet'          => array(
				'name'       => 'MailPoet',
				'slugs'      => 'mailpoet wysija-newsletters',
				'tables'     => 'mailpoet_* wysija_*',
				'options'    => 'mailpoet wysija',
				'post_types' => 'mailpoet_page',
			),
			'mc4wp'             => array(
				'name'       => 'MC4WP: Mailchimp for WordPress',
				'slugs'      => 'mailchimp-for-wp mailchimp-for-woocommerce',
				'options'    => 'mc4wp mailchimp-woocommerce* mailchimp_*',
				'meta'       => 'mailchimp_* _mailchimp_*',
				'post_types' => 'mc4wp-form',
			),
			'wordfence'         => array(
				'name'    => 'Wordfence Security',
				'slugs'   => 'wordfence wordfence-login-security',
				'tables'  => 'wf* =wfls_*',
				'options' => 'wordfence* wfls_*',
				'meta'    => 'wfls-* wf_*',
			),
			'litespeed'         => array(
				'name'    => 'LiteSpeed Cache',
				'slugs'   => 'litespeed-cache',
				'tables'  => 'litespeed_*',
				'options' => 'litespeed.* litespeed_*',
				'meta'    => '_litespeed_* litespeed_*',
			),
			'updraftplus'       => array(
				'name'    => 'UpdraftPlus',
				'slugs'   => 'updraftplus',
				'options' => 'updraft updraftplus updraftplus-*',
			),
			'w3tc'              => array(
				'name'    => 'W3 Total Cache',
				'slugs'   => 'w3-total-cache',
				'options' => 'w3tc',
			),
			'wp-rocket'         => array(
				'name'    => 'WP Rocket',
				'slugs'   => 'wp-rocket',
				'tables'  => 'wpr_*',
				'options' => 'wp_rocket rocket wpr_*',
			),
			'wp-optimize'       => array(
				'name'    => 'WP-Optimize',
				'slugs'   => 'wp-optimize',
				'tables'  => 'tm_tasks tm_taskmeta',
				'options' => 'wp-optimize wpo wp_optimize',
			),
			'clearfy'           => array(
				'name'    => 'Clearfy / Webcraftic plugins',
				'slugs'   => 'clearfy robin-image-optimizer cyrlitera hide-login-page gonzales disable-admin-notices',
				'options' => 'wbcr wbcr_* factory',
			),
			'edd'               => array(
				'name'       => 'Easy Digital Downloads',
				'slugs'      => 'easy-digital-downloads easy-digital-downloads-pro',
				'tables'     => 'edd_*',
				'options'    => 'edd',
				'meta'       => '_edd_* edd_*',
				'post_types' => 'download edd_payment edd_log edd_discount',
				'taxonomies' => 'download_category download_tag edd_log_type',
			),
			'wp-all-import'     => array(
				'name'    => 'WP All Import / Export',
				'slugs'   => 'wp-all-import wp-all-import-pro wpai-woocommerce-add-on wp-all-export wp-all-export-pro woocommerce-xml-csv-product-import',
				'tables'  => 'pmxi_* pmxe_*',
				'options' => 'wp_all_import* wp-all-import* wpai* pmxi* pmxe* PMGI* wp_all_export*',
				'meta'    => '_wp_all_import_* pmxi_*',
			),
			'smush'             => array(
				'name'    => 'Smush (WPMU DEV)',
				'slugs'   => 'wp-smushit wp-smush-pro',
				'tables'  => 'smush_*',
				'options' => 'wp-smush* smush* dir_smush_stats skip-smush-setup',
				'meta'    => 'wp-smpro-smush-data wp-smush-* _wp-smush-*',
			),
			'wpmudev'           => array(
				'name'    => 'WPMU DEV Dashboard',
				'slugs'   => 'wpmudev-updates',
				'options' => 'wdp_un_* wdev-frash wpmudev*',
			),
			'revslider'         => array(
				'name'    => 'Slider Revolution',
				'slugs'   => 'revslider',
				'tables'  => 'revslider_*',
				'options' => 'revslider* rs-library rs-templates rs-templates-new rs_tables_created',
			),
			'jetpack'           => array(
				'name'    => 'Jetpack',
				'slugs'   => 'jetpack jetpack-boost',
				'tables'  => 'jetpack_*',
				'options' => 'jetpack* jpsq_*',
				'meta'    => 'jetpack_* _jetpack_*',
			),
			'acf'               => array(
				'name'       => 'Advanced Custom Fields',
				'slugs'      => 'advanced-custom-fields advanced-custom-fields-pro secure-custom-fields',
				'options'    => 'acf',
				'post_types' => 'acf-field-group acf-field acf-post-type acf-taxonomy acf-ui-options-page',
			),
			'yoast'             => array(
				'name'       => 'Yoast SEO',
				'slugs'      => 'wordpress-seo wordpress-seo-premium',
				'tables'     => 'yoast_*',
				'options'    => 'wpseo* yoast*',
				'meta'       => '_yoast_* wpseo_* _yst_* yst_*',
				'taxonomies' => 'yst_prominent_words',
			),
			'rank-math'         => array(
				'name'       => 'Rank Math SEO',
				'slugs'      => 'seo-by-rank-math seo-by-rank-math-pro',
				'tables'     => 'rank_math_*',
				'options'    => 'rank_math* rank-math*',
				'meta'       => 'rank_math_* _rank_math_*',
				'post_types' => 'rank_math_schema',
			),
			'instant-indexing'  => array(
				'name'    => 'Instant Indexing (Rank Math)',
				'slugs'   => 'fast-indexing-api instant-indexing',
				'options' => 'giapi',
			),
			'aioseo'            => array(
				'name'    => 'All in One SEO',
				'slugs'   => 'all-in-one-seo-pack all-in-one-seo-pack-pro',
				'tables'  => 'aioseo_*',
				'options' => 'aioseo* aioseop*',
				'meta'    => '_aioseo_* _aioseop_*',
			),
			'monsterinsights'   => array(
				'name'    => 'MonsterInsights',
				'slugs'   => 'google-analytics-for-wordpress google-analytics-premium',
				'options' => 'monsterinsights*',
			),
			'site-kit'          => array(
				'name'    => 'Site Kit by Google',
				'slugs'   => 'google-site-kit',
				'options' => 'googlesitekit*',
				'meta'    => 'googlesitekit* ' . self::prefix_token() . 'googlesitekit*',
			),
			'gla'               => array(
				'name'    => 'Google for WooCommerce (Listings & Ads)',
				'slugs'   => 'google-listings-and-ads',
				'tables'  => 'gla_*',
				'options' => 'gla',
				'meta'    => '_wc_gla_*',
			),
			'wpforms'           => array(
				'name'       => 'WPForms',
				'slugs'      => 'wpforms-lite wpforms',
				'tables'     => 'wpforms_*',
				'options'    => 'wpforms*',
				'post_types' => 'wpforms wpforms_log',
			),
			'cf7'               => array(
				'name'       => 'Contact Form 7',
				'slugs'      => 'contact-form-7',
				'options'    => 'wpcf7',
				'post_types' => 'wpcf7_contact_form',
			),
			'cf7-conditional'   => array(
				'name'    => 'Conditional Fields for Contact Form 7',
				'slugs'   => 'cf7-conditional-fields',
				'options' => 'wpcf7cf*',
			),
			'cfdb7'             => array(
				'name'   => 'Contact Form CFDB7',
				'slugs'  => 'contact-form-cfdb7',
				'tables' => 'db7_forms',
			),
			'duplicator'        => array(
				'name'    => 'Duplicator / Duplicator Pro',
				'slugs'   => 'duplicator duplicator-pro',
				'tables'  => 'duplicator_*',
				'options' => 'duplicator* dupli_opt_* duplicator_pro*',
			),
			'duplicate-post'    => array(
				'name'    => 'Yoast Duplicate Post',
				'slugs'   => 'duplicate-post',
				'options' => 'duplicate_post*',
				'meta'    => '_dp_*',
			),
			'duplicate-page'    => array(
				'name'    => 'Duplicate Page',
				'slugs'   => 'duplicate-page',
				'options' => 'duplicate_page*',
			),
			'wp-mail-smtp'      => array(
				'name'    => 'WP Mail SMTP',
				'slugs'   => 'wp-mail-smtp wp-mail-smtp-pro',
				'tables'  => 'wpmailsmtp_*',
				'options' => 'wp_mail_smtp*',
			),
			'elementor'         => array(
				'name'       => 'Elementor',
				'slugs'      => 'elementor elementor-pro',
				'tables'     => 'e_events e_submissions* e_notes* e_kit_elements_usage',
				'options'    => 'elementor* _elementor_*',
				'meta'       => '_elementor_* elementor_*',
				'post_types' => 'elementor_library e-landing-page elementor_snippet elementor_font elementor_icons e-floating-buttons',
				'taxonomies' => 'elementor_library_type elementor_library_category elementor_font_type',
			),
			'wpbakery'          => array(
				'name'       => 'WPBakery Page Builder',
				'slugs'      => 'js_composer',
				'options'    => 'vc_* wpb_js_*',
				'meta'       => '_wpb_* _vc_* vc_*',
				'post_types' => 'vc_grid_item templatera',
			),
			'translatepress'    => array(
				'name'       => 'TranslatePress',
				'slugs'      => 'translatepress-multilingual translatepress-business translatepress-developer translatepress-personal',
				'tables'     => 'trp_*',
				'options'    => 'trp',
				'meta'       => '_trp_* trp_*',
				'post_types' => 'language_switcher',
			),
			'wpml'              => array(
				'name'       => 'WPML',
				'slugs'      => 'sitepress-multilingual-cms wpml-string-translation woocommerce-multilingual',
				'tables'     => 'icl_*',
				'options'    => 'icl_* wpml*',
				'meta'       => '_icl_* _wpml_* wpml_*',
				'taxonomies' => 'translation_priority',
			),
			'polylang'          => array(
				'name'       => 'Polylang',
				'slugs'      => 'polylang polylang-pro polylang-wc',
				'options'    => 'polylang* pll_*',
				'taxonomies' => 'language term_language post_translations term_translations',
			),
			'berocket'          => array(
				'name'    => 'BeRocket plugins',
				'slugs'   => 'woocommerce-ajax-filters woo-order-numbers berocket-*',
				'tables'  => 'berocket_*',
				'options' => 'berocket* br-* br_*',
			),
			'yith-wishlist'     => array(
				'name'    => 'YITH WooCommerce Wishlist',
				'slugs'   => 'yith-woocommerce-wishlist yith-woocommerce-wishlist-premium',
				'tables'  => 'yith_wcwl*',
				'options' => 'yith_wcwl* yith-wcwl*',
			),
			'yith-rac'          => array(
				'name'       => 'YITH WooCommerce Recover Abandoned Cart',
				'slugs'      => 'yith-woocommerce-recover-abandoned-cart yith-woocommerce-recover-abandoned-cart-premium',
				'tables'     => 'yith_ywrac_*',
				'options'    => 'ywrac* yit_ywrac_version',
				'meta'       => '_ywrac_* ywrac_*',
				'post_types' => 'ywrac_cart ywrac_email',
			),
			'yith-cos'          => array(
				'name'       => 'YITH WooCommerce Custom Order Status',
				'slugs'      => 'yith-woocommerce-custom-order-status yith-woocommerce-custom-order-status-premium',
				'options'    => 'yith_wccos* yith-wccos*',
				'post_types' => 'yith-wccos-ostatus',
			),
			'yith-et'           => array(
				'name'       => 'YITH WooCommerce Email Templates',
				'slugs'      => 'yith-woocommerce-email-templates yith-woocommerce-email-templates-premium',
				'options'    => 'yith-wcet* yith_wcet*',
				'post_types' => 'yith-wcet-etemplate',
			),
			'yith'              => array(
				'name'    => 'YITH plugin framework',
				'slugs'   => 'yith-*',
				'options' => 'yit_* yith_* yith-*',
			),
			'yaymail'           => array(
				'name'       => 'YayMail',
				'slugs'      => 'yaymail yaymail-pro',
				'options'    => 'yaymail*',
				'meta'       => '_yaymail_* yaymail_*',
				'post_types' => 'yaymail_template',
			),
			'nextmove'          => array(
				'name'       => 'NextMove Thank You Pages',
				'slugs'      => 'thank-you-page-for-woocommerce-nextmove woo-thank-you-page-nextmove-lite',
				'options'    => 'xlwcty* xl_licenses_* xlp_*',
				'meta'       => '_xlwcty_* xlwcty_*',
				'post_types' => 'xlwcty_thankyou',
			),
			'wcuf'              => array(
				'name'    => 'WooCommerce Upload Files',
				'slugs'   => 'woocommerce-upload-files',
				'tables'  => 'wcuf_*',
				'options' => 'wcuf* _options_wcuf* options_wcuf* _options_*_multiple_upload_fields_per_row options_*_multiple_upload_fields_per_row',
				'meta'    => '_wcuf_* wcuf_*',
			),
			'wt-seq'            => array(
				'name'    => 'Sequential Order Numbers (WebToffee)',
				'slugs'   => 'wt-woocommerce-sequential-order-numbers',
				'options' => 'wt_sequence* wt_sequential* wt_last_* wt_seq_* wt_advanced_order_number* wt_custom_order_number* wt_ord_* wt_renumerate',
				'meta'    => '_order_number',
			),
			'stripe'            => array(
				'name'    => 'WooCommerce Stripe Gateway',
				'slugs'   => 'woocommerce-gateway-stripe',
				'options' => 'wcstripe* woocommerce_stripe*',
				'meta'    => '_stripe_* _wcstripe_* ' . self::prefix_token() . '_stripe_customer_id',
			),
			'wcpay'             => array(
				'name'    => 'WooPayments',
				'slugs'   => 'woocommerce-payments',
				'options' => 'wcpay*',
			),
			'paynl'             => array(
				'name'    => 'Pay. (PAY.nl) Payment Methods',
				'slugs'   => 'woocommerce-paynl-payment-methods paynl-payment-methods',
				'tables'  => 'pay_options pay_option_subs pay_transactions pay_processing',
				'options' => 'paynl*',
			),
			'ctx-feed'          => array(
				'name'    => 'CTX Feed (WebAppick)',
				'slugs'   => 'webappick-product-feed-for-woocommerce webappick-product-feed-for-woocommerce-pro',
				'options' => 'woo_feed* woo-feed* webappick* wf_feed_* wf_config*',
			),
			'xfgmc'             => array(
				'name'    => 'XML for Google Merchant Center',
				'slugs'   => 'xml-for-google-merchant-center',
				'options' => 'xfgmc*',
				'meta'    => '_xfgmc_* xfgmc_*',
			),
			'smart-manager'     => array(
				'name'    => 'Smart Manager (StoreApps)',
				'slugs'   => 'smart-manager-for-wp-e-commerce smart-manager-pro',
				'options' => '_sm_* sm_* sa_smart_manager* _storeapps_*',
			),
			'woo-ce'            => array(
				'name'    => 'WooCommerce Store Exporter',
				'slugs'   => 'woocommerce-exporter woocommerce-store-exporter-deluxe',
				'options' => 'woo_ce*',
			),
			'woobe'             => array(
				'name'    => 'BEAR Bulk Editor (WOOBE)',
				'slugs'   => 'woo-bulk-editor woocommerce-bulk-editor',
				'options' => 'woobe*',
			),
			'w3exabe'           => array(
				'name'    => 'WooCommerce Advanced Bulk Edit',
				'slugs'   => 'woocommerce-advanced-bulk-edit',
				'options' => 'w3exabe*',
			),
			'pw-bulk-edit'      => array(
				'name'    => 'PW WooCommerce Bulk Edit / Brands',
				'slugs'   => 'pw-bulk-edit pw-woocommerce-brands pw-brands',
				'options' => 'pw_* external_updates-pw-*',
			),
			'asset-cleanup'     => array(
				'name'    => 'Asset CleanUp',
				'slugs'   => 'wp-asset-clean-up wp-asset-clean-up-pro',
				'options' => 'wpassetcleanup*',
				'meta'    => '_wpassetcleanup_* wpassetcleanup_*',
			),
			'alidropship'       => array(
				'name'    => 'AliDropship',
				'slugs'   => 'alidropship alidropship-woo adsw',
				'tables'  => 'ads_* adsw_*',
				'options' => 'adsw* ads_* ads-* adsaban* dm-* dm_*',
				'meta'    => 'adsw* _adsw*',
			),
			'kadence-woomail'   => array(
				'name'    => 'Kadence WooCommerce Email Designer',
				'slugs'   => 'kadence-woocommerce-email-designer',
				'options' => 'kt_woomail*',
			),
			'kirki'             => array(
				'name'    => 'Kirki Customizer Framework',
				'slugs'   => 'kirki',
				'options' => 'kirki*',
			),
			'redux'             => array(
				'name'    => 'Redux Framework',
				'slugs'   => 'redux-framework',
				'options' => 'redux*',
			),
			'ai1wm'             => array(
				'name'    => 'All-in-One WP Migration',
				'slugs'   => 'all-in-one-wp-migration',
				'options' => 'ai1wm*',
			),
			'bsr'               => array(
				'name'    => 'Better Search Replace',
				'slugs'   => 'better-search-replace',
				'options' => 'bsr*',
			),
			'burst'             => array(
				'name'    => 'Burst Statistics',
				'slugs'   => 'burst-statistics',
				'tables'  => 'burst_*',
				'options' => 'burst*',
			),
			'varnish'           => array(
				'name'    => 'Proxy Cache Purge (Varnish)',
				'slugs'   => 'varnish-http-purge',
				'options' => 'vhp*',
			),
			'ure'               => array(
				'name'    => 'User Role Editor',
				'slugs'   => 'user-role-editor user-role-editor-pro',
				'options' => 'ure*',
			),
			'members'           => array(
				'name'    => 'Members',
				'slugs'   => 'members',
				'options' => 'members*',
			),
			'wpcode'            => array(
				'name'       => 'WPCode (Insert Headers and Footers)',
				'slugs'      => 'insert-headers-and-footers wpcode-premium',
				'options'    => 'wpcode* ihaf*',
				'meta'       => '_wpcode_* wpcode_*',
				'post_types' => 'wpcode',
				'taxonomies' => 'wpcode_type wpcode_location wpcode_tags',
			),
			'woosidebars'       => array(
				'name'       => 'WooSidebars',
				'slugs'      => 'woosidebars',
				'options'    => 'woosidebars*',
				'post_types' => 'sidebar',
			),
			'cbnet-ping'        => array(
				'name'    => 'cbnet Ping Optimizer',
				'slugs'   => 'cbnet-ping-optimizer',
				'options' => 'cbnetpo*',
			),
			'9mail'             => array(
				'name'    => '9MAIL Email Templates Designer',
				'slugs'   => '9mail-wordpress-email-templates-designer',
				'options' => '9mail*',
			),
			'villatheme-email'  => array(
				'name'       => 'Email Template Customizer for WooCommerce (VillaTheme)',
				'slugs'      => 'email-template-customizer-for-woo woocommerce-email-template-customizer',
				'options'    => 'villatheme* emtmpl*',
				'post_types' => 'emtmpl emtmpl_block',
			),
			'thumbpress'        => array(
				'name'    => 'ThumbPress (Image Sizes)',
				'slugs'   => 'image-sizes thumbpress thumbpress-pro',
				'options' => 'thumbpress* image-sizes*',
				'meta'    => '_thumbpress_* thumbpress_*',
			),
			'abandoned-lite'    => array(
				'name'    => 'Abandoned Cart Lite for WooCommerce',
				'slugs'   => 'woocommerce-abandoned-cart woocommerce-abandon-cart-pro',
				'tables'  => 'ac_abandoned_cart_* ac_guest_* ac_sent_*',
				'options' => 'ac_lite* wcal* woocommerce_ac_*',
			),
			'iq-block-country'  => array(
				'name'    => 'iQ Block Country',
				'slugs'   => 'iq-block-country',
				'tables'  => 'blockcountry_*',
				'options' => 'blockcountry*',
			),
			'masterslider'      => array(
				'name'    => 'Master Slider',
				'slugs'   => 'master-slider masterslider',
				'tables'  => 'masterslider_*',
				'options' => 'masterslider* msp_*',
			),
			'nextend-social'    => array(
				'name'    => 'Nextend Social Login',
				'slugs'   => 'nextend-facebook-connect nextend-social-login-pro',
				'tables'  => 'social_users',
				'options' => 'nsl*',
			),
			'indexnow'          => array(
				'name'    => 'IndexNow',
				'slugs'   => 'indexnow',
				'tables'  => 'indexnow_*',
				'options' => 'indexnow*',
			),
			'regen-slug'        => array(
				'name'    => 'Simple Regenerate Slug',
				'slugs'   => 'simple-regenerate-slug',
				'options' => 'simpleregenerateslug*',
			),
			'lowest-prices'     => array(
				'name'    => 'Show only lowest prices in WooCommerce variable products',
				'slugs'   => 'show-only-lowest-prices-in-woocommerce-variable-products',
				'options' => 'ayudawp*',
			),
			'image-to-video'    => array(
				'name'    => 'Image to Video',
				'slugs'   => 'image-to-video',
				'options' => 'i2v*',
			),
			'font-awesome'      => array(
				'name'    => 'Font Awesome',
				'slugs'   => 'font-awesome',
				'options' => 'font-awesome*',
			),
			'permalink-manager' => array(
				'name'    => 'Permalink Manager',
				'slugs'   => 'permalink-manager permalink-manager-pro',
				'options' => 'permalink-manager* external_updates-permalink-manager*',
				'meta'    => 'permalink-manager*',
			),
			'rightpress'        => array(
				'name'    => 'RightPress (Dynamic Pricing & Discounts)',
				'slugs'   => 'wc-dynamic-pricing-and-discounts',
				'options' => 'rp_wcdpd* rightpress*',
				'meta'    => '_rightpress_* rightpress_* _rp_wcdpd_*',
			),
			'freemius'          => array(
				'name'    => 'Freemius SDK (shared by several plugins)',
				'type'    => 'shared',
				'options' => 'fs_accounts fs_active_plugins fs_api_cache fs_debug_mode fs_gdpr',
			),
			'image-watermark'   => array(
				'name'    => 'Image Watermark',
				'slugs'   => 'image-watermark',
				'options' => 'image_watermark*',
			),
			'image-optimizer'   => array(
				'name'    => 'Image Optimizer (Elementor)',
				'slugs'   => 'image-optimization',
				'options' => 'image_optimizer* _image-optimizer*',
			),
			'frou'              => array(
				'name'    => 'File Renaming on Upload',
				'slugs'   => 'file-renaming-on-upload',
				'options' => 'frou*',
			),
			'woo-ce-tdl'        => array(
				'name'    => 'Woocommerce Delivery / tdl theme options',
				'type'    => 'shared',
				'options' => 'tdl_options*',
			),
			'cookie'            => array(
				'name'    => 'Complianz / Cookie plugins',
				'slugs'   => 'complianz-gdpr complianz-gdpr-premium cookie-law-info cookiebot borlabs-cookie',
				'tables'  => 'cmplz_* cky_* borlabs_cookie_*',
				'options' => 'cmplz* cookie_law_info* cookiebot* borlabs*',
			),
			'rsssl'             => array(
				'name'    => 'Really Simple Security',
				'slugs'   => 'really-simple-ssl really-simple-ssl-pro',
				'tables'  => 'rsssl_*',
				'options' => 'rsssl* really_simple_ssl*',
			),
			'itsec'             => array(
				'name'    => 'Solid Security (iThemes)',
				'slugs'   => 'better-wp-security ithemes-security-pro',
				'tables'  => 'itsec_*',
				'options' => 'itsec*',
			),
			'redirection'       => array(
				'name'    => 'Redirection',
				'slugs'   => 'redirection',
				'tables'  => 'redirection_*',
				'options' => 'redirection*',
			),
			'gravityforms'      => array(
				'name'    => 'Gravity Forms',
				'slugs'   => 'gravityforms',
				'tables'  => 'gf_* rg_*',
				'options' => 'gform* rg_gforms* gravityforms*',
			),
			'wp-statistics'     => array(
				'name'    => 'WP Statistics',
				'slugs'   => 'wp-statistics',
				'tables'  => 'statistics_*',
				'options' => 'wp_statistics*',
			),
			'ewww'              => array(
				'name'    => 'EWWW Image Optimizer',
				'slugs'   => 'ewww-image-optimizer',
				'tables'  => 'ewwwio_*',
				'options' => 'ewww_image_optimizer* ewwwio*',
			),
			'autoptimize'       => array(
				'name'    => 'Autoptimize',
				'slugs'   => 'autoptimize',
				'options' => 'autoptimize*',
			),
			'snippets'          => array(
				'name'   => 'Code Snippets',
				'slugs'  => 'code-snippets code-snippets-pro',
				'tables' => '=snippets ms_snippets',
			),
			'woo-sidebars-kt'   => array(
				'name'    => 'WooCommerce Square / misc',
				'type'    => 'shared',
				'options' => '=_wcsc_last_error_check wcsc_*',
			),
			'litespeed-legacy'  => array(
				'name'    => 'Add Expires Headers / Far Future Expiry',
				'slugs'   => 'add-expires-headers far-future-expiry-header',
				'options' => 'add_expires_headers* far_future_expiration*',
			),
			'flatsome'          => array(
				'name'       => 'Flatsome theme',
				'type'       => 'theme',
				'slugs'      => 'flatsome',
				'options'    => 'flatsome*',
				'meta'       => '_flatsome_* flatsome_*',
				'post_types' => 'blocks',
				'taxonomies' => 'block_categories',
			),
			'porto'             => array(
				'name'       => 'Porto theme',
				'type'       => 'theme',
				'slugs'      => 'porto',
				'options'    => 'porto*',
				'meta'       => 'porto_* _porto_*',
				'post_types' => 'porto_builder portfolio member faq event',
			),
			'woodstock'         => array(
				'name'    => 'Woodstock theme',
				'type'    => 'theme',
				'slugs'   => 'woodstock',
				'options' => 'woodstock*',
			),
		);
	}

	/**
	 * Table-prefix token for meta keys that carry the prefix (usermeta).
	 *
	 * @return string
	 */
	private static function prefix_token(): string {
		global $wpdb;
		return (string) $wpdb->prefix;
	}

	/**
	 * Split a space-separated pattern string (or a list) into patterns.
	 *
	 * @param mixed $value String or array.
	 *
	 * @return array<int,string>
	 */
	private static function words( $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}
		$parts = preg_split( '/[\s,]+/', trim( (string) $value ) );
		return array_values( array_filter( is_array( $parts ) ? $parts : array(), 'strlen' ) );
	}

	/**
	 * Add the owners that plugins and themes declare with the lucidlab_dbc_known_owners filter.
	 *
	 * Each entry: owner id => array( 'name', 'type' (plugin|theme), 'slugs', 'tables',
	 * 'table_prefixes', 'options', 'option_prefixes', 'transients', 'transient_prefixes', 'meta',
	 * 'meta_prefixes', 'post_types', 'taxonomies', 'cron', 'uploads' ). Values are arrays or
	 * space-separated strings; names are exact, *_prefixes match the start of a name. An id that
	 * already exists (for example lucidlab-lpm) adds its names to the built-in entry. Declared data
	 * is never offered as "safe": without an active owner it is at most "review".
	 *
	 * @param array<string,array<string,string>> $base Built-in entries.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function merge_declared( array $base ): array {
		/**
		 * Filters the data owners known to Lucid Lab Database Cleaner.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string,array<string,mixed>> $owners Owner id => declaration. Empty by default.
		 */
		$declared = apply_filters( 'lucidlab_dbc_known_owners', array() );
		if ( ! is_array( $declared ) ) {
			return $base;
		}
		$map = array(
			'tables'     => array( 'tables', 'table_prefixes' ),
			'options'    => array( 'options', 'option_prefixes' ),
			'transients' => array( 'transients', 'transient_prefixes' ),
			'meta'       => array( 'meta', 'meta_prefixes' ),
		);
		foreach ( $declared as $raw_id => $decl ) {
			$id = sanitize_key( (string) $raw_id );
			if ( '' === $id || ! is_array( $decl ) || in_array( $id, array( 'core', 'lucidlab-dbc' ), true ) ) {
				continue;
			}
			$entry = array(
				'name'     => sanitize_text_field( (string) ( $decl['name'] ?? $id ) ),
				'type'     => ( 'theme' === ( $decl['type'] ?? '' ) ) ? 'theme' : 'plugin',
				'slugs'    => implode( ' ', array_map( 'sanitize_key', self::words( $decl['slugs'] ?? '' ) ) ),
				'declared' => true,
			);
			foreach ( $map as $field => $keys ) {
				$patterns = array();
				foreach ( self::words( $decl[ $keys[0] ] ?? '' ) as $name ) {
					$patterns[] = '=' . ltrim( $name, '=' );
				}
				foreach ( self::words( $decl[ $keys[1] ] ?? '' ) as $prefix ) {
					$prefix = rtrim( ltrim( $prefix, '=' ), '*' );
					if ( strlen( $prefix ) >= 3 ) {
						$patterns[] = $prefix . '*';
					}
				}
				$entry[ $field ] = implode( ' ', $patterns );
			}
			foreach ( array( 'post_types', 'taxonomies', 'cron', 'uploads' ) as $field ) {
				$entry[ $field ] = implode( ' ', self::words( $decl[ $field ] ?? '' ) );
			}
			if ( isset( $base[ $id ] ) ) {
				foreach ( array( 'tables', 'options', 'transients', 'meta', 'post_types', 'taxonomies', 'cron', 'uploads' ) as $field ) {
					$base[ $id ][ $field ] = trim( ( $base[ $id ][ $field ] ?? '' ) . ' ' . $entry[ $field ] );
				}
				continue;
			}
			if ( '' === $entry['slugs'] ) {
				unset( $entry['slugs'] );
			}
			$base[ $id ] = $entry;
		}
		return $base;
	}

	/**
	 * Lucid Lab owners known to this plugin (id => name), for the readme and the overview.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function lucidlab_owners(): array {
		return array_filter(
			self::all(),
			static function ( $entry ) {
				return 'lucidlab' === ( $entry['vendor'] ?? '' );
			}
		);
	}

	/**
	 * Resolved knowledge base with an owner status per entry. Cached per request.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		static $resolved = null;
		if ( null !== $resolved ) {
			return $resolved;
		}
		$inventory = self::plugin_inventory();
		$resolved  = array();
		foreach ( self::merge_declared( self::base() ) as $id => $raw ) {
			$entry = array(
				'id'       => $id,
				'name'     => (string) $raw['name'],
				'type'     => (string) ( $raw['type'] ?? 'plugin' ),
				'vendor'   => (string) ( $raw['vendor'] ?? '' ),
				'declared' => ! empty( $raw['declared'] ),
			);
			foreach ( array( 'slugs', 'tables', 'options', 'transients', 'meta', 'post_types', 'taxonomies', 'cron', 'uploads' ) as $field ) {
				$entry[ $field ] = self::words( $raw[ $field ] ?? '' );
			}
			// Transients live in the options table under four names.
			foreach ( $entry['transients'] as $pattern ) {
				$exact = str_starts_with( $pattern, '=' );
				$bare  = ltrim( $pattern, '=' );
				foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $lead ) {
					$entry['options'][] = ( $exact ? '=' : '' ) . $lead . $bare . ( $exact || str_ends_with( $bare, '*' ) ? '' : '*' );
				}
			}
			if ( $entry['declared'] && ! $entry['slugs'] ) {
				// Declared at run time by code that is running now, without a folder to look up.
				$entry += array(
					'status'       => self::STATUS_ACTIVE,
					'status_label' => self::status_label( self::STATUS_ACTIVE, $entry['type'] ),
					'plugin_file'  => '',
				);
			} else {
				$entry += self::resolve_status( $entry, $inventory );
			}
			$resolved[ $id ] = $entry;
		}
		// Installed plugins that the knowledge base does not know yet: derive owners from their code.
		foreach ( $inventory['plugins'] as $file => $plugin ) {
			if ( self::kb_claims_slug( $resolved, (string) $plugin['slug'] ) ) {
				continue;
			}
			$found = self::discover( (string) $file, $plugin );
			if ( ! $found['tables'] && ! $found['options'] && ! $found['post_types'] && ! $found['taxonomies'] && ! $found['meta'] && ! $found['literals'] ) {
				continue;
			}
			$id                              = 'discovered:' . $plugin['slug'];
			$resolved[ $id ]                 = array(
				'id'           => $id,
				'name'         => (string) $plugin['name'],
				'type'         => 'plugin',
				'slugs'        => array( (string) $plugin['slug'] ),
				'discovered'   => true,
				'vendor'       => '',
				'declared'     => false,
				'transients'   => array(),
				'cron'         => array(),
				'uploads'      => array(),
				'status'       => $plugin['active'] ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
				'plugin_file'  => (string) $file,
				'status_label' => '',
			) + $found;
			$resolved[ $id ]['status_label'] = self::status_label( $resolved[ $id ]['status'] );
		}
		return $resolved;
	}

	/**
	 * Whether a knowledge-base entry already claims a plugin folder.
	 *
	 * @param array<string,array<string,mixed>> $entries Entries.
	 * @param string                            $slug    Plugin folder.
	 *
	 * @return bool
	 */
	private static function kb_claims_slug( array $entries, string $slug ): bool {
		foreach ( $entries as $entry ) {
			foreach ( (array) $entry['slugs'] as $pattern ) {
				if ( self::slug_matches( (string) $pattern, $slug ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a slug pattern ("yith-*" or an exact folder name) matches a plugin folder.
	 *
	 * @param string $pattern Pattern.
	 * @param string $slug    Folder.
	 *
	 * @return bool
	 */
	private static function slug_matches( string $pattern, string $slug ): bool {
		$pattern = strtolower( $pattern );
		$slug    = strtolower( $slug );
		if ( str_ends_with( $pattern, '*' ) ) {
			return str_starts_with( $slug, substr( $pattern, 0, -1 ) );
		}
		return $pattern === $slug;
	}

	/**
	 * Installed plugins with active state, plus active theme slugs.
	 *
	 * @return array{plugins:array<string,array<string,mixed>>,themes:array<int,string>}
	 */
	public static function plugin_inventory(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$plugins = array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$file             = (string) $file;
			$dir              = dirname( $file );
			$plugins[ $file ] = array(
				'name'       => (string) ( $data['Name'] ?? $file ),
				'slug'       => strtolower( '.' === $dir ? (string) pathinfo( $file, PATHINFO_FILENAME ) : $dir ),
				'textdomain' => (string) ( $data['TextDomain'] ?? '' ),
				'version'    => (string) ( $data['Version'] ?? '' ),
				'active'     => in_array( $file, $active, true ) || in_array( $file, $network, true ),
			);
		}
		return array(
			'plugins' => $plugins,
			'themes'  => array_values( array_unique( array( (string) get_template(), (string) get_stylesheet() ) ) ),
		);
	}

	/**
	 * Owner status for a knowledge-base entry.
	 *
	 * @param array<string,mixed> $entry     Entry.
	 * @param array<string,mixed> $inventory Inventory.
	 *
	 * @return array{status:string,status_label:string,plugin_file:string}
	 */
	private static function resolve_status( array $entry, array $inventory ): array {
		$status = self::STATUS_MISSING;
		$file   = '';
		if ( in_array( $entry['type'], array( 'core', 'self' ), true ) ) {
			$status = self::STATUS_CORE;
		} elseif ( 'shared' === $entry['type'] ) {
			$status = self::STATUS_UNKNOWN;
		} elseif ( 'theme' === $entry['type'] ) {
			foreach ( $entry['slugs'] as $slug ) {
				if ( in_array( $slug, $inventory['themes'], true ) ) {
					$status = self::STATUS_ACTIVE;
					break;
				}
				if ( wp_get_theme( $slug )->exists() ) {
					$status = self::STATUS_INACTIVE;
				}
			}
		} else {
			foreach ( $inventory['plugins'] as $plugin_file => $plugin ) {
				foreach ( $entry['slugs'] as $pattern ) {
					if ( ! self::slug_matches( (string) $pattern, (string) $plugin['slug'] ) ) {
						continue;
					}
					if ( $plugin['active'] ) {
						$status = self::STATUS_ACTIVE;
						$file   = (string) $plugin_file;
						break 2;
					}
					$status = self::STATUS_INACTIVE;
					$file   = (string) $plugin_file;
				}
			}
		}
		return array(
			'status'       => $status,
			'status_label' => self::status_label( $status, (string) $entry['type'] ),
			'plugin_file'  => $file,
		);
	}

	/**
	 * Translated owner status label.
	 *
	 * @param string $status Status.
	 * @param string $type   Owner type.
	 *
	 * @return string
	 */
	public static function status_label( string $status, string $type = 'plugin' ): string {
		$theme = ( 'theme' === $type );
		switch ( $status ) {
			case self::STATUS_CORE:
				return __( 'WordPress core', 'lucidlab-db-cleanup' );
			case self::STATUS_ACTIVE:
				return $theme ? __( 'Active theme', 'lucidlab-db-cleanup' ) : __( 'Active plugin', 'lucidlab-db-cleanup' );
			case self::STATUS_INACTIVE:
				return $theme ? __( 'Theme installed, not active', 'lucidlab-db-cleanup' ) : __( 'Plugin installed, not active', 'lucidlab-db-cleanup' );
			case self::STATUS_MISSING:
				return $theme ? __( 'Theme not installed', 'lucidlab-db-cleanup' ) : __( 'Plugin not installed', 'lucidlab-db-cleanup' );
			default:
				return __( 'Unknown owner', 'lucidlab-db-cleanup' );
		}
	}

	/**
	 * Read up to 60 PHP files of an installed plugin and collect the table names, option names,
	 * meta keys, post types and taxonomies it uses. Cached until the plugin version changes.
	 *
	 * @param string              $file   Plugin file.
	 * @param array<string,mixed> $plugin Plugin data.
	 *
	 * @return array{tables:array<int,string>,options:array<int,string>,meta:array<int,string>,post_types:array<int,string>,taxonomies:array<int,string>,literals:array<int,string>}
	 */
	private static function discover( string $file, array $plugin ): array {
		$cache_key = 'lucidlab_dbc_disc_' . md5( $file . '|' . ( $plugin['version'] ?? '' ) . '|' . LUCIDLAB_DBC_VERSION );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['literals'] ) ) {
			return $cached;
		}
		$out  = array(
			'tables'     => array(),
			'options'    => array(),
			'meta'       => array(),
			'post_types' => array(),
			'taxonomies' => array(),
			'literals'   => array(),
		);
		$path = WP_PLUGIN_DIR . '/' . $file;
		$dir  = dirname( $path );
		$list = array( $path );
		if ( '.' !== dirname( $file ) && is_dir( $dir ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $item ) {
				if ( count( $list ) >= 60 ) {
					break;
				}
				$name = $item->getPathname();
				if ( $item->isFile() && str_ends_with( $name, '.php' ) && ! preg_match( '#/(vendor|node_modules|tests?|languages)/#', str_replace( '\\', '/', $name ) ) ) {
					$list[] = $name;
				}
			}
		}
		foreach ( array_unique( $list ) as $php ) {
			$size = is_readable( $php ) ? filesize( $php ) : false;
			if ( false === $size || $size > 600000 ) {
				continue;
			}
			$code = (string) file_get_contents( $php ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source files to learn their table and option names; WP_Filesystem is not needed for reading.
			// "$wpdb->prefix . 'name'" is a table; "$wpdb->prefix . 'lpm_' . $name" (a literal that is
			// continued with more code, or ends in "_") is a table-name prefix.
			if ( preg_match_all( '/\$wpdb->(?:base_)?prefix\s*\.\s*[\'"]([A-Za-z0-9_]{3,})[\'"](\s*\.)?|\{\$wpdb->(?:base_)?prefix\}([A-Za-z0-9_]{3,})(\{|\$)?/', $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$table = '' !== $hit[1] ? $hit[1] : (string) ( $hit[3] ?? '' );
					$open  = '' !== $hit[1] ? ! empty( $hit[2] ) : ! empty( $hit[4] );
					if ( '' === $table ) {
						continue;
					}
					$out['tables'][] = ( $open || str_ends_with( $table, '_' ) ) ? rtrim( $table, '_' ) . '_*' : '=' . $table;
				}
			}
			// Lowercase identifiers with an underscore in files that talk to the database: used to match
			// tables whose names are built at run time (see owner()).
			if ( false !== strpos( $code, '$wpdb' ) && preg_match_all( '/[\'"]([a-z][a-z0-9]*_[a-z0-9_]*)[\'"]/', $code, $m ) ) {
				foreach ( $m[1] as $literal ) {
					if ( strlen( $literal ) <= 64 && count( $out['literals'] ) < self::LITERALS_MAX ) {
						$out['literals'][ $literal ] = true;
					}
				}
			}
			if ( preg_match_all( '/\b(?:get|update|add|delete)_option\s*\(\s*[\'"]([A-Za-z0-9_\-\.]{4,})[\'"]/', $code, $m ) ) {
				foreach ( $m[1] as $option ) {
					$out['options'][] = '=' . $option;
				}
			}
			if ( preg_match_all( '/\b(?:get|update|add|delete)_(?:post|user|term|comment)_meta\s*\((?:[^,()]|\([^()]*\))+,\s*[\'"]([A-Za-z0-9_\-]{4,})[\'"]/', $code, $m ) ) {
				foreach ( $m[1] as $key ) {
					$out['meta'][] = '=' . $key;
				}
			}
			if ( preg_match_all( '/register_post_type\s*\(\s*[\'"]([a-z0-9_\-]{2,20})[\'"]/', $code, $m ) ) {
				$out['post_types'] = array_merge( $out['post_types'], $m[1] );
			}
			if ( preg_match_all( '/register_taxonomy\s*\(\s*[\'"]([a-z0-9_\-]{2,32})[\'"]/', $code, $m ) ) {
				$out['taxonomies'] = array_merge( $out['taxonomies'], $m[1] );
			}
		}
		$literals = array_keys( $out['literals'] );
		unset( $out['literals'] );
		foreach ( $out as $key => $values ) {
			$out[ $key ] = array_values( array_unique( array_diff( $values, self::CORE_POST_TYPES ) ) );
		}
		$out['literals'] = $literals;
		set_transient( $cache_key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/**
	 * Whether a name matches one pattern.
	 *
	 * @param string $pattern Pattern.
	 * @param string $name    Name.
	 *
	 * @return int Match strength (pattern length) or 0.
	 */
	private static function match_strength( string $pattern, string $name ): int {
		$p = strtolower( $pattern );
		$n = strtolower( $name );
		if ( str_starts_with( $p, '=' ) ) {
			$p = substr( $p, 1 );
			if ( str_ends_with( $p, '*' ) ) {
				$p = substr( $p, 0, -1 );
				return str_starts_with( $n, $p ) ? strlen( $p ) : 0;
			}
			return $p === $n ? strlen( $p ) + 1000 : 0;
		}
		if ( str_ends_with( $p, '*' ) ) {
			$p = substr( $p, 0, -1 );
			return ( '' !== $p && str_starts_with( $n, $p ) ) ? strlen( $p ) : 0;
		}
		if ( $p === $n ) {
			return strlen( $p ) + 500;
		}
		if ( str_starts_with( $n, $p ) && in_array( substr( $n, strlen( $p ), 1 ), array( '_', '-', '.' ), true ) ) {
			return strlen( $p ) + 1;
		}
		return 0;
	}

	/**
	 * Owner of a table, option, meta key, post type or taxonomy. Longest pattern wins.
	 *
	 * @param string $kind tables|options|meta|post_types|taxonomies.
	 * @param string $name Name (table names without the table prefix).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function owner( string $kind, string $name ): ?array {
		static $memo = array();
		$memo_key    = $kind . '|' . $name;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return $memo[ $memo_key ];
		}
		$best     = null;
		$strength = 0;
		foreach ( self::all() as $entry ) {
			foreach ( (array) ( $entry[ $kind ] ?? array() ) as $pattern ) {
				$score = self::match_strength( (string) $pattern, $name );
				// Exact names from an active owner beat equal-length guesses from inactive ones.
				if ( $score > 0 && self::STATUS_ACTIVE === $entry['status'] ) {
					$score += 0.5;
				}
				if ( $score > $strength ) {
					$strength = $score;
					$best     = $entry;
				}
			}
		}
		if ( null === $best && 'tables' === $kind ) {
			$best = self::owner_by_literal( $name );
		}
		$memo[ $memo_key ] = $best;
		return $best;
	}

	/**
	 * Owner of a table that no pattern matched, found through the string literals in the code of an
	 * installed plugin: the full table name, or its first one or two name parts with a trailing "_"
	 * (for example 'lpm_' in "$wpdb->prefix . 'lpm_' . $name"). Prefixes need at least 3 characters.
	 *
	 * @param string $name Table name without the table prefix.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function owner_by_literal( string $name ): ?array {
		$name       = strtolower( $name );
		$candidates = array( $name => 3 );
		$parts      = explode( '_', $name );
		if ( count( $parts ) > 2 ) {
			$candidates[ $parts[0] . '_' . $parts[1] . '_' ] = 2;
		}
		if ( count( $parts ) > 1 && strlen( $parts[0] ) >= 3 ) {
			$candidates[ $parts[0] . '_' ] = 1;
		}
		$best     = null;
		$strength = 0;
		foreach ( self::all() as $entry ) {
			if ( empty( $entry['literals'] ) ) {
				continue;
			}
			$literals = array_flip( (array) $entry['literals'] );
			foreach ( $candidates as $candidate => $score ) {
				if ( isset( $literals[ $candidate ] ) && $score > $strength ) {
					$strength = $score;
					$best     = $entry + array( 'matched_literal' => $candidate );
				}
			}
		}
		return $best;
	}

	/**
	 * Whether an owner's data must be protected (core, this plugin, active plugin or active theme).
	 *
	 * @param array<string,mixed>|null $owner Owner.
	 *
	 * @return bool
	 */
	public static function is_protected( ?array $owner ): bool {
		return is_array( $owner ) && in_array( (string) $owner['status'], array( self::STATUS_CORE, self::STATUS_ACTIVE ), true );
	}
}
