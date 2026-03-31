# Event Horizon - Gravity Forms Blacklist

**Contributors:** goebelmedia  
**Requires at least:** 6.0  
**Tested up to:** 6.5  
**Requires PHP:** 7.4  
**Stable tag:** 1.1.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Import content and email blacklists from Google Sheets or uploaded CSV files and apply them to Gravity Forms fields.

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Gravity Forms must be installed and active for field protection to work

---

## Import Methods

Each blacklist can use its own source:

- **Google Sheets CSV URL** for scheduled remote sync via WP-Cron
- **Uploaded CSV file** for local imports managed from the plugin settings

If both blacklist sources use uploaded CSV files, scheduled remote sync is disabled automatically.

---

## Google Sheets Setup

1. Open your Google Sheet and click **File -> Share -> Publish to web**.  
2. Choose the specific sheet/tab, not the entire document.  
3. Select **Comma-separated values (.csv)** as the format, then click **Publish**.  
4. Copy the published URL and paste it into the appropriate blacklist source setting.

You can also use a direct export link:

`https://docs.google.com/spreadsheets/d/<SHEET_ID>/export?format=csv&gid=<TAB_GID>`

If you only have the normal Google Sheets sharing or edit URL, use the **Tools -> URL Fixer** tab inside the plugin to convert it into the CSV export URL automatically.

---

## Uploaded CSV Setup

1. In the plugin settings, choose **Uploaded CSV file** as the source for the content or email blacklist.  
2. Upload your `.csv` file and save settings.  
3. Use **Refresh now** if you want to force an immediate re-import after replacing a file.

Uploaded CSV sources do not use scheduled remote retrieval.

---

## Blacklist Format

Put one rule per row in the first column. Extra columns are ignored.

### Content Rules

- `casino` matches as a whole word, case-insensitively.  
- `buy now` matches as a substring, case-insensitively.  
- `regex:/\bfree\s+money\b/i` runs as a regex rule.  

### Email Rules

- `badguy@example.com` matches an exact email, case-insensitively.  
- `*@spammail.com` blocks an entire domain.  
- `regex:/@temp-mail\./i` matches disposable-email patterns by regex.  

---

## Enable Blacklists On Fields

Event Horizon checks only the fields where you enable blacklist protection.

1. Go to **Forms -> Edit Form**.  
2. Click the field you want to protect, such as Email, Name, or Message.  
3. Open the **Advanced** tab in the field settings.  
4. Enable **Content Blacklist** for text-based fields and/or **Email Blacklist** for email fields.  
5. Save the form.

If these options are not enabled on a field, that field will not be scanned.
