# Lucid Lab Database Cleaner

Finds data and tables your site no longer uses, explains where they came from, and removes what you select after a backup you can restore.

This repository is the free edition. The separate Pro add-on at https://lucidlab.eu/product/database-cleaner-pro/ is not in this repo.

## Requirements

- WordPress 6.4+
- PHP 8.1+

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Installation

Copy the folder to `wp-content/plugins/lucidlab-db-cleanup`.

Download: https://lucidlab.eu/download/db-cleaner-free/

Documentation: https://lucidlab.eu/database-cleaner/documentation/

## Changelog

See [readme.txt](readme.txt).

### 1.5.3

Released 21 October 2026.

- The browser tab title of the Database Cleaner screens is now "Database Cleaner" (was "Lucid Lab Database Cleaner"). The menu label and the page heading are unchanged.
- New: when Database Cleaner Pro is installed but not activated, the Database Cleaner screens show a notice to users who can activate plugins, with an "Activate Pro" button and a "Hide this message" link (remembered per user), like Media & Products Cleanup.
- Fixed: "Get Pro" buttons and other links that open in a new tab or go to another site no longer show a spinner or become disabled when clicked; they open lucidlab.eu in a new tab and stay usable. Other buttons keep their busy state.
