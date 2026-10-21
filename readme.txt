=== Lucid Lab Database Cleaner ===
Contributors: vanschaik12
Tags: database, cleanup, optimize, woocommerce, orphaned data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds data and tables your site no longer uses, explains where they came from, and removes what you select after a backup you can restore.

== Description ==

Lucid Lab Database Cleaner scans the whole WordPress database for data that nothing uses any more: posts of plugins you removed years ago (such as old newsletter campaigns that still show up as "content URLs" in media scanners and return 404), orphaned meta, broken term links, settings and tables of removed plugins, and stale WooCommerce data.

Every finding shows what it is, the plugin or theme that most likely created it, whether that plugin is active, inactive or no longer installed, why it is considered unused, how many rows it has and how much space it takes. Examples can be opened, viewed and inspected before you decide.

Categories:
* Pages, posts & products: trash, drafts not edited for over a year, product variations of deleted products.
* Newsletters & other post types: posts of post types that no active plugin or theme registers (Mailster campaigns, email templates, form plugins, theme blocks…).
* Revisions & auto-drafts: delete all revisions, or keep the newest N revisions of every post.
* Orphaned & leftover meta: post, user, term and comment meta without a parent, and meta keys written by plugins that are no longer active.
* Terms & taxonomies: broken term relationships, terms of unregistered taxonomies, unused tags (and, marked risky, empty categories).
* Comments: spam, trash, and comments or order notes of deleted posts.
* Options & autoload: settings of removed plugins and themes, migration copies, and a read-only preview of the 25 largest autoloaded options with the total autoload size.
* Transients & caches: expired transients and oEmbed caches.
* WooCommerce & Action Scheduler: expired sessions, order items and lookup rows of deleted orders and products, completed, canceled and failed scheduled actions older than 30 days (the age can be set on the Settings tab, 1 to 365 days) with their logs, logs without an action, and claims that no action refers to any more.
* Database tables: every table with the site's prefix, attributed to WordPress, WooCommerce or a plugin, with rows and size. Tables of unknown origin are marked risky.

Database summary, optimization and cleanup log:
* The Database summary & log tab shows the size (data + index), free space (overhead), number of tables and number of records of this site's tables and of the whole database. Records are counted exactly with COUNT(*); only InnoDB tables with more than 500,000 rows use the information_schema estimate, and those totals are marked with ≈.
* After a cleanup has deleted rows, every affected table is optimized automatically (dropped tables excluded), one table per step so large tables do not hit time limits. InnoDB tables are rebuilt ("recreate + analyze", which is what OPTIMIZE TABLE does for InnoDB), MyISAM and Aria tables are defragmented and their indexes sorted, and other engines (such as MEMORY) are skipped. The result of every table is shown.
* "Before" is measured at the start of a cleanup and "after" once deleting and optimizing are done, both right after ANALYZE TABLE refreshed the statistics. The before/after totals and the savings (MB and %, tables removed, records removed) are shown and kept in the cleanup log (last 50 runs).
* "Optimize all tables" optimizes every table with this site's prefix on request, with the same before/after measurement.

Clean all safe items:
* "Clean all safe items" on the Overview shows every finding marked safe per category (count and size) and, after confirmation, runs the normal cleanup for exactly those findings: backup, delete, optimize the affected tables, before/after measurement and a log entry. Selecting per category and per finding still works as before.
* Backup retention: keep the last N cleanup backups (default 8) and/or delete backups older than N days. It is applied after every cleanup and never removes the backup of the cleanup that just finished.
* Findings marked review, risky or info, protected data and data of Lucid Lab plugins are never part of "Clean all safe items".

Autoload preview and engines & charset report (read-only):
* The Options & autoload tab shows the total autoloaded size and the 25 largest options that WordPress loads on every page, with their likely source, size and share. The full Autoload checker (50 options, a switch per option with undo) is part of the Pro add-on.
* The Engines & charset tab lists every table with its storage engine (InnoDB, MyISAM, Aria, MEMORY) and collation and marks tables that are not InnoDB or not utf8mb4. It changes nothing.

Full database backup and restore:
* Under Full backup, "Create full backup" writes the structure and data of every table of the database (or only the tables with this site's prefix) to a gzip-compressed .sql.gz file in the protected backup folder: SET FOREIGN_KEY_CHECKS=0, DROP TABLE IF EXISTS + SHOW CREATE TABLE per table, views as CREATE VIEW, multi-row INSERTs with binary, BIT, NULL and utf8mb4 values written exactly. It is pure PHP through $wpdb (no exec or mysqldump needed), in AJAX steps of about 8 seconds that read 1,000 rows at a time (keyset paging on the primary key), so large databases do not time out on shared hosting. A progress bar shows the table being written; an interrupted backup can be continued or discarded. The file can also be imported with phpMyAdmin or the mysql client.
* The list of full backups shows date, type (manual, safety, before a scheduled cleanup or another Pro change, uploaded), tables, records, size and the site URL when it differs, with Restore, Download and Delete.
* Restore asks to type RESTORE, makes a safety full backup of the current database first (on by default) and warns when the backup comes from another site URL. The dump is read statement by statement (quotes, escapes, comments and DELIMITER are handled) and imported into temporary tables while the site keeps running; then all live tables are replaced in one atomic RENAME TABLE, so the site is never half restored. Until that moment a restore can be cancelled without any change. The admin who runs the restore stays logged in when the backup contains the same user; otherwise WordPress asks for the login stored in the backup. Optionally, tables that are not in the backup are removed.
* The last 3 full backups are kept, separate from the cleanup backups. Uploaded files are never removed automatically.

Pro add-on (optional, sold separately):
The free plugin is complete on its own. Lucid Lab Database Cleaner Pro is a separate add-on plugin that adds: scheduled cleanups with a report e-mail and an optional full backup first; full backup retention settings and restoring an uploaded .sql/.sql.gz dump; the full Autoload checker (the 50 largest autoloaded options with a switch per option and undo); extra WooCommerce cleanup (configurable age, HPOS orphans, stock reservations, rate limits); a revisions limit for new revisions; extra orphaned and duplicate meta; converting tables to InnoDB and utf8mb4; index tools (tables without a primary key, missing indexes with "add index" and undo); a database growth chart; a serialized-safe search & replace; and health alerts by e-mail. These features are shown as tabs and cards marked "Pro" that only describe the feature; the free plugin contains none of their code and nothing is time-limited.

The sidebar groups every section with the related free feature: Overview & reports (overview, database summary & log, growth chart, health alerts), Clean up (the scan categories with the extended orphan scan, the Autoload checker next to Options & autoload, WooCommerce deep clean next to WooCommerce, and schedule & e-mail), Backups (backups & restore, full backup with dump upload and retention), Tools (engines & charset with table conversion, index tools, search & replace) and Settings. Pro sections keep their "Pro" badge.

Lucid Lab plugins and themes:
* Tables, options, transients, meta keys, post types, cron hooks and upload folders of Lucid Lab Plannings Manager, Media & Products Cleanup (and Pro), Image to Video, Products Category Visibility, Flight Tracker Castelldefels, Web Watcher / Found Text, the Lucid Lab theme, the Nummers Schilderen theme, Nummers Schilderen Functions and Nummers Schilderen Importer are recognised by name.
* While the plugin or theme is active its data is protected. When it is inactive or not installed, its data is listed as "review" with the name of the plugin, never as "safe".

Safety:
* Scanning only reads the database.
* Nothing is pre-selected. "Select all safe" selects only findings marked safe; risky findings need an extra confirmation.
* WordPress core data, WooCommerce core data, this plugin and every active plugin and theme are protected and never offered for deletion. Orders and payments of registered post types are never selected.
* Every finding is checked again on the server right before it is deleted (for example, a post type that is registered again is skipped).
* Before anything is deleted, the affected rows (and complete tables) are written to an SQL backup in a protected folder below wp-content/uploads/lucidlab-db-cleanup/. Restore it from the Backups tab, or import the file with any MySQL tool.
* Cleanup and restore run in small AJAX steps, so large tables do not hit PHP time limits.

Limitations:
* Single sites only. On a multisite network the plugin shows a notice and does nothing.
* Ownership of options, meta keys and tables is derived from a built-in list of common plugins plus the code of installed plugins (full table names and table-name prefixes such as $wpdb->prefix . 'lpm_' . $name). It is a well-informed guess: always read the "Likely source" column.
* WP-Cron only runs when the site gets visits. Scheduled runs start with the first visit after the planned time; for exact times use a real server cron job.
* Sizes of findings are estimates from row lengths and table statistics. The database summary uses the figures MySQL/MariaDB report in information_schema (data, index and free space are allocated in pages and extents, so small changes can show as 0 or as a whole extent).
* Optimizing an InnoDB table rebuilds it: this needs free disk space about the size of the table and can take minutes for very large tables. MyISAM and Aria tables are locked while they are optimized. With innodb_file_per_table off, InnoDB space goes back to the shared system file, which never shrinks.
* Full backups and restores use PHP and the database connection of WordPress; a restore needs free database space for a temporary copy of the restored tables. Stored procedures, functions, triggers and events are not part of a full backup (WordPress does not use them); in uploaded dumps they are skipped and counted.
* On nginx the .htaccess file of the backup folder has no effect: the random folder and file names still keep backups unguessable, but adding a deny rule for wp-content/uploads/lucidlab-db-cleanup/ is recommended.

== Installation ==
1. Upload the ZIP under Plugins > Add New > Upload Plugin and activate it.
2. Open Database Cleaner in the admin menu (below your other Lucid Lab plugins) and click Scan the database.
3. Review a category, select findings and click Delete selected. Keep the backup until you are sure the site works as expected.

== Frequently Asked Questions ==
= Why does a post of a removed plugin return 404? =
WordPress can only display posts whose post type is registered. When the plugin that registered it (for example a newsletter plugin) is removed, the posts stay in the database but every link to them returns 404. Media scanners still see the images in their content as "in use".
= Can I undo a cleanup? =
Yes. Every cleanup writes an SQL backup first. Restore it from the Backups tab. Rows that were created again since the cleanup are left untouched. A backup that was changed after it was written, or that comes from another site, is not restored from the admin screen.
= Are backups deleted when I uninstall the plugin? =
No, backups are the only way to undo a cleanup, so the files and their index are kept. Delete them in the Backups tab first, or define LUCIDLAB_DBC_UNINSTALL_DELETE_BACKUPS as true in wp-config.php to remove them on uninstall. The scan result and job state are always removed.
= What does "recreate + analyze" mean? =
InnoDB does not support OPTIMIZE TABLE in place. MySQL and MariaDB answer "Table does not support optimize, doing recreate + analyze instead": the table is rebuilt into a new, compact file and its statistics are refreshed. That is the normal and expected result for InnoDB tables.
= Why is "records removed" not exactly the number of deleted rows? =
The difference between before and after is measured on the whole set of tables, and WordPress, WooCommerce and other plugins keep writing (sessions, scheduled actions, caches) while the cleanup runs. The log also shows the exact number of rows the cleanup itself deleted.
= What happens if the request times out while a large table is optimized? =
The database finishes the statement on its own. The next step waits until it is done (an advisory lock is held for the whole statement), records the table as "finished in background" and continues.
= The menu item is not below my other Lucid Lab plugins. =
Open any admin page with ?lldbc_menu_debug=1 added to the URL (as an administrator). The notice shows which menus were recognised as Lucid Lab, which plugins filter the menu order and the final order, so the cause can be found quickly.
= My server runs nginx. Are the backups safe? =
The backup folder has a random name and the files have random names, and .htaccess/web.config deny access on Apache and IIS. On nginx, add a rule that denies access to /wp-content/uploads/lucidlab-db-cleanup/.

= Does the plugin send anything to Lucid Lab? =
Only if you allow it. The optional install count is off by default; when you turn it on (Settings tab), the site sends its address, the plugin version, the plugin name and a random install ID to lucidlab.eu once a week and after updates, so Lucid Lab can count active installs. Turn it off on the same tab. See External services.

= What does the Pro add-on change in the free plugin? =
Nothing until it is installed, activated and licensed: the free features work the same with or without it. The Pro notice on the Database Cleaner screen can be dismissed and is shown on this plugin's own screen only.
= Can a plugin tell the cleaner which data is its own? =
Yes, with the filter lucidlab_dbc_known_owners (since 1.2.0). It is optional: tables are also recognised from the plugin code. Data of a declared owner is protected while its plugin is active and listed as "review" (never "safe") when it is inactive or removed. Table names are given without the site's table prefix; prefixes need at least three characters.

`add_filter( 'lucidlab_dbc_known_owners', function ( $owners ) {
	$owners['my-plugin'] = array(
		'name'               => 'My Plugin',
		'slugs'              => 'my-plugin',            // Plugin folder(s); omit to treat it as always active.
		'tables'             => array( 'myp_jobs' ),    // Exact table names (without $wpdb->prefix).
		'table_prefixes'     => array( 'myp_' ),
		'options'            => array( 'myp_settings' ),
		'option_prefixes'    => array( 'myp_' ),
		'transient_prefixes' => array( 'myp_' ),
		'meta_prefixes'      => array( '_myp_' ),
		'post_types'         => array( 'myp_item' ),
		'cron'               => array( 'myp_daily' ),
	);
	return $owners;
} );`

Also accepted: type ('plugin' or 'theme'), transients, meta, taxonomies and uploads. An id that already exists (for example lucidlab-lpm) adds to the built-in entry.

== External services ==

Install count (off by default): only after an administrator turns on "Tell Lucid Lab that this site uses the plugin" on the Settings tab (or clicks "Yes, count this site" in the notice on the Database Cleaner screen), the site sends a POST request to Lucid Lab at https://lucidlab.eu/wp-json/lucidlab/v1/free/ping with the site address (home URL), the plugin version, the plugin name (lucidlab-db-cleanup) and a random install ID. It is sent right after opting in, after each plugin update and once a week, and is used only to count active installs. No users, e-mail addresses, content, database data or settings are sent; the HTTP request necessarily includes the site's network address. Turning it off, deactivating or uninstalling the plugin stops it at once. Lucid Lab privacy policy: https://lucidlab.eu/privacy/ ; terms: https://lucidlab.eu/terms/ .

Nothing else in the free plugin connects to an external service: scans, cleanups, backups and restores run on your own server only. The separate Pro add-on contacts lucidlab.eu for its license checks and updates; see its own readme.

== Source code ==

The JavaScript and CSS are shipped unminified and are the editable sources: assets/js/admin.js and assets/css/admin.css. No build step or bundler is needed.

== Changelog ==

= 1.5.2 =
* Settings link on the Plugins page: it opens the Settings tab of Database Cleaner (administrators only) and replaces the former Open link.
* The page header shows the edition and version like Media & Products Cleanup: "v1.5.2 Free · Documentation", or "v1.5.2 Free & v1.0.7 Pro · Documentation" when the Pro add-on is active.

= 1.5.1 =
* New Lucid Lab icon: the admin menu and the plugin header use the new Database Cleaner icon in the Lucid Lab style.
* Colored menu section headers: the sidebar group titles (Overview & reports, Clean up, Backups, ...) are shown in Lucid Lab blue so they stand out from the sections.
* Install-count question now appears after 7 days instead of right away. The install count stays off until an administrator turns it on, and what it sends is unchanged.

= 1.5.0 =
* New: the age of completed, canceled and failed scheduled actions (Action Scheduler) is a setting on the Settings tab ("Scan rules"), 1 to 365 days. The default is now 30 days (was a fixed 7), the period Action Scheduler itself keeps finished actions. The age used by a scan is kept with its findings, so changing the setting never deletes more than the scan showed; the next scan uses the new age.
* New: "Scheduled-action claims without an action" (safe): rows in actionscheduler_claims that no scheduled action refers to any more and that are older than an hour. They work like every other finding: per-finding cleanup, "Clean all safe items", scheduled cleanups of the Pro add-on, backup before deleting, restore and automatic optimization of the table.
* Changed: the autoload preview on the Options & autoload tab also says when the total is below the 800 KB that WordPress recommends, and the warning above 800 KB says what to do.

= 1.4.2 =
* New, off by default: an optional install count. On the Settings tab (or from a dismissible notice on the Database Cleaner screen) an administrator can let the site tell Lucid Lab that it uses the plugin. Nothing is sent before that; see External services for exactly what is sent, when and where. Turning it off, deactivating or uninstalling stops it, and uninstall removes its options.
* New: Documentation link in the plugin's row on the Plugins screen and in the plugin header.
* Changed: the sidebar is grouped (Overview & reports, Clean up, Backups, Tools, Settings) and every Pro section sits next to the related free feature instead of in one block at the bottom. Pro sections keep their Pro badge.
* Changed: the Autoload checker is a Pro section. Free shows a read-only preview of the 25 largest autoloaded options and the total autoload size on the Options & autoload tab.
* Changed: index tools have their own Pro section under Tools (with Pro 1.0.4 or newer; older Pro versions keep them on the growth tab).
* Changed: locked Pro cards have a white background.
* Fixed: readme version information matches the plugin version.

= 1.4.1 =
* Changed: Get Pro links now point to the Lucid Lab shop.

= 1.4.0 =
* New: Revisions can be cleaned keeping the newest N revisions of every post, besides deleting all of them.
* New: Engines & charset tab, a read-only report of the storage engine and collation of every table.
* New: hooks for the separate Lucid Lab Database Cleaner Pro add-on (tabs, findings, AJAX operations, backup triggers). Pro features are shown as locked tabs and cards marked "Pro" with a link; the free plugin contains none of their code, and the "Get Pro" link can be changed with the lucidlab_dbc_pro_url filter.
* Changed: scheduled cleanups, the report e-mail, full backup retention settings and restoring an uploaded dump are now part of Pro. Saved schedule settings are kept, and they run again as soon as Pro is active; without Pro no scheduled run starts. Existing backups, full backups and uploaded files stay listed and can still be restored.
* Changed: uninstall also removes the stored version and the dismissed state of the Pro notice.
* Fixed: plugin settings saved by an add-on are no longer dropped when the cleanup backup retention is saved.

= 1.3.0 =
* New: Full backup tab. Complete database backup (structure + data, gzip-compressed .sql.gz) in resumable AJAX steps with a progress bar, list with Download, Delete and Restore, upload of .sql/.sql.gz files in parts, restore into temporary tables with an atomic switch, RESTORE confirmation, automatic safety backup, site URL check, and retention of full backups (default 3).
* New: optional full database backup before each scheduled cleanup, reported in the e-mail.
* New: filter lucidlab_dbc_full_step_seconds for the seconds of work per backup or restore step.
* Changed: all buttons use the Lucid Lab button style (same colours as Media & Products Cleaner, slight hover lift, busy state with spinner, aria-busy and no clicks while working). Icons and the spinner sit on one line with the text, vertically centred.

= 1.2.0 =
* New: Lucid Lab section in the known-owners list. Tables, options, transients, meta, post types, cron hooks and upload folders of all Lucid Lab plugins and themes are recognised by name (Plannings Manager tables such as lpm_departments and lpm_employees were listed as "Unknown tables, risky"). Lucid Lab data is protected while its plugin is active and "review" with the plugin name when it is inactive or removed, never "safe".
* New: table-name prefixes in plugin code ($wpdb->prefix . 'lpm_' . $name) and table-name literals are matched against unknown tables, so tables of installed plugins that are not in the built-in list are attributed to them. Meta keys read with nested calls (get_user_meta( get_current_user_id(), 'key' )) are recognised.
* New: filter lucidlab_dbc_known_owners for plugins that want to declare their data.
* New: "Clean all safe items" button on the Overview with a confirmation per category (findings and size): backup, delete, optimize, before/after measurement and log, as before.
* New: scheduled automatic cleanup (off, weekly, monthly, time in the site time zone) through WP-Cron in batches, with a lock against overlapping runs, resume after time-outs, Stop and Run now.
* New: backup retention (keep the last 8 backups by default, optionally a maximum age in days).
* New: HTML report e-mail with plain-text alternative after scheduled runs (and optionally manual cleanups), configurable recipients and a Send test e-mail button.
* Nothing new runs on front-end requests except the registration of the two cron hooks. All new options are stored with autoload off and removed on uninstall; the cron events are cleared on deactivation and uninstall.

= 1.1.4 =
* Performance: the plugin now adds nothing to front-end, REST, cron and CLI requests (the translation loader no longer runs there), and nothing to other plugins' AJAX requests (heartbeat, WooCommerce, …). On other wp-admin screens only the menu entry and the menu placement are loaded; the scanner, cleaner, backup and database classes load on demand (autoloader) on the Database Cleaner screen, its AJAX requests and backup downloads only.
* Performance: after a cleanup or "Optimize all tables" the plugin no longer empties the whole object cache (wp_cache_flush). With Redis, Memcached or LiteSpeed object cache that made every page of the site slow until the cache was rebuilt. Only the cache entries of the deleted rows are removed now (one full flush only for cleanups of more than 50,000 rows, and after a restore).
* Performance: all plugin options are kept out of autoload; existing installs are migrated on activation and when the Database Cleaner screen opens. The menu diagnostic hook is only added when ?lldbc_menu_debug=1 is in the URL.
* Code quality: passes the WordPress Coding Standards (WordPress, WordPress-Extra, WordPress-Docs) and PHPCompatibilityWP for PHP 8.1+, @wordpress/eslint-plugin for the JavaScript and @wordpress/stylelint-config for the CSS. CSS classes now use plain hyphenated names.
* Accessibility: problems after a cleanup or optimize are shown in the live progress area with a link to the summary instead of a blocking alert; group filter buttons expose aria-pressed; the details dialog is labelled by its title.

= 1.1.3 =
* Fixed: the menu placement below the other Lucid Lab menus now also survives other plugins that reorder the menu later or switch custom ordering off. Besides the menu_order filter (now at the latest possible priority), the placement is re-applied on the final, sorted menu right before WordPress prints it (parent_file filter), and the first/last menu classes are recalculated.
* Improved: Lucid Lab menus are recognised by slug, by their toplevel_page_* hook name and by their visible title (Media & Products Cleanup, Image to Video, Products & Category Visibility, Plannings Manager, Web Watcher, Flight Tracker), so a different slug no longer breaks the placement.
* New: read-only menu diagnostic for administrators: add ?lldbc_menu_debug=1 to any wp-admin URL to see the plugin version and file dates, OPcache status, what the menu_order filter received and returned, every menu_order / custom_menu_order / parent_file callback with its priority, the matched Lucid Lab items and the final rendered menu order.

= 1.1.2 =
* Fixed: the Database Cleaner menu now really sits directly below the last Lucid Lab menu (Media & Products Cleanup, Image to Video, Plannings Manager, Products & Category Visibility, Web Watcher, Flight Tracker or any other "lucidlab"/"llft" menu). Position numbers differ per plugin version and site, so the item is now placed in the final menu order (menu_order filter, late priority) instead of guessing a position. Other menu items and other plugins' menu orders are kept. Without other Lucid Lab menus it stays at position 58.9, below WooCommerce Marketing.

= 1.1.1 =
* Changed: the plugin is now called "Lucid Lab Database Cleaner" (Plugins screen, admin page title, readme and translations); the admin menu label is "Database Cleaner", short like the other Lucid Lab menus. The folder and slug stay lucidlab-db-cleanup, so it updates in place and keeps its settings, scan results, backups and log.
* Changed: the admin menu now sits directly below the lowest Lucid Lab menu present (Media & Products Cleanup, Image to Video, Plannings Manager, Products & Category Visibility, Flight Tracker, Web Watcher or any other "lucidlab" menu), registered late on admin_menu. Without other Lucid Lab menus it sits at position 58.9, below WooCommerce Marketing and above Appearance.

= 1.1.0 =
* New: after a cleanup every affected table is optimized automatically, batched per table via AJAX with progress. InnoDB (recreate + analyze), MyISAM and Aria are handled and reported per table; other engines are skipped.
* New: "Optimize all tables" for this site's prefix, with confirmation.
* New: Database summary & log tab: size (data + index), free space, tables and records for this site and the whole database, with exact counts where cheap and clearly marked estimates otherwise.
* New: before/after measurement of every cleanup (ANALYZE TABLE first), savings in MB and %, tables and records removed, and a cleanup log of the last 50 runs. The newest result is also shown on the Overview tab.
* Improved: job steps are serialised with a database lock, and failed step requests are retried, so a time-out during a long OPTIMIZE TABLE no longer stops the run.
* Improved: fewer writes of the job state during ANALYZE steps.

= 1.0.0 =
* First release: database scan in ten categories with owner attribution and confidence levels, cleanup with SQL backup, restore, inspect dialog and autoload report.
