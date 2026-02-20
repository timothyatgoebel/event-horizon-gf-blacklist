=== Event Horizon - Gravity Forms Blacklist ===
Contributors: goebelmedia
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync content + email blacklists from Google Sheets (CSV) and apply them to Gravity Forms fields.

== Quick start ==
1) Install & activate the plugin.
2) Go to: WordPress Admin → Event Horizon → Settings.
3) Paste your Google Sheets CSV links (Content + Email).
4) In Gravity Forms, edit a form field and enable:
   - "Enable Content Blacklist" (text/textarea fields)
   - "Enable Email Blacklist" (email fields)
5) Click “Sync now” on the Event Horizon settings screen.

== Getting a Google Sheets CSV link ==
Option A (recommended): Publish to web
- Google Sheets → File → Share → Publish to web
- Choose the specific sheet/tab
- Format: Comma-separated values (.csv)
- Publish → copy the URL

Option B: Export URL format
https://docs.google.com/spreadsheets/d/<SHEET_ID>/export?format=csv&gid=<TAB_GID>

== Blacklist format ==
One rule per row in the first column. Extra columns are ignored.
Comment rows can begin with # or //.

Content rules:
- casino          (whole-word match)
- buy now         (substring match)
- regex:/\bfree\s+money\b/i  (PHP regex)

Email rules:
- badguy@example.com (exact match)
- *@spammail.com     (domain match)
- regex:/@temp-mail\./i (PHP regex)

== Privacy ==
Logs store hashes only (no raw emails/content).

== Notes ==
- Sync uses WP-Cron. On very low-traffic sites, scheduled syncs may run late.
- Conditional GET requests (ETag/Last-Modified) are used to reduce bandwidth when the sheet has not changed.
