=== Event Horizon - Gravity Forms Blacklist ===
Contributors: goebelmedia
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import content and email blacklists from Google Sheets or uploaded CSV files and apply them to Gravity Forms fields.

== Description ==

Event Horizon lets you import two independent blacklist types for Gravity Forms:

* Content blacklist rules for names, messages, and other text-based fields
* Email blacklist rules for exact addresses, domains, and regex patterns

Each blacklist can use either:

* A Google Sheets CSV export URL for scheduled refreshes via WP-Cron
* An uploaded CSV file stored inside WordPress for local-only management

The plugin includes:

* A polished admin UI with Settings, Lists, Logs, Tools, and Help tabs
* A URL Fixer for converting regular Google Sheets links into CSV export URLs
* Per-field Gravity Forms toggles in the Advanced field settings panel
* Privacy-aware logging that stores hashes instead of raw submitted values

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/event-horizon-gf-blacklist/`, or install the ZIP from the WordPress admin.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Make sure Gravity Forms is installed and active.
4. Open `Event Horizon` in the WordPress admin.
5. Configure the content and email blacklist sources.
6. In Gravity Forms, enable `Content Blacklist` and/or `Email Blacklist` on the fields you want to protect.

== Frequently Asked Questions ==

= What URL format is accepted for Google Sheets? =

Use a CSV export URL in this format:

`https://docs.google.com/spreadsheets/d/<SHEET_ID>/export?format=csv&gid=<TAB_GID>`

If you only have a sharing or edit URL, use the `Tools` tab inside the plugin to convert it.

= How should blacklist CSV files be formatted? =

Put one rule per row in the first column. Extra columns are ignored. Optional header rows can be enabled separately for the content and email lists.

= What email rules are supported? =

You can use:

* Exact email addresses such as `badguy@example.com`
* Wildcard domain rules such as `*@spammail.com`
* Domain shorthand such as `spammail.com`
* Regex rules such as `regex:/@temp-mail\./i`

= What content rules are supported? =

You can use:

* Single words such as `casino`
* Phrases such as `buy now`
* Regex rules such as `regex:/\bfree\s+money\b/i`

= Does this plugin work without Gravity Forms? =

No. Event Horizon can still sync and cache blacklist sources without Gravity Forms, but it cannot protect submissions until Gravity Forms is active.

== Changelog ==

= 1.1.0 =

* Added separate settings, lists, logs, tools, and help admin tabs
* Added Google Sheets URL Fixer tooling
* Added uploaded CSV storage inside WordPress
* Added privacy-aware match logging and blacklist cache management
