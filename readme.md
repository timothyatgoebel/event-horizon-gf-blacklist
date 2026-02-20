# Event Horizon - Gravity Forms Blacklist

**Contributors:** goebelmedia  
**Requires at least:** 6.0  
**Tested up to:** 6.5  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Sync content and email blacklists from Google Sheets (CSV) and apply them to Gravity Forms fields.

---

## How to Get a Google Sheets CSV Link

1. Open your Google Sheet and click **File → Share → Publish to web**.  
2. Choose the specific sheet/tab (not the entire document).  
3. Select **Comma-separated values (.csv)** as the format, then click **Publish**.  
4. Copy the published URL and paste it into the appropriate setting on the Settings tab.

**Tip:** If you prefer not to “Publish to web”, you can also use an export link in the format:

`https://docs.google.com/spreadsheets/d/<SHEET_ID>/export?format=csv&gid=<TAB_GID>`

---

## Blacklist Format

Put one rule per row in the first column. Extra columns are ignored.

### Content Rules

- `casino` — Matches as a whole word (case-insensitive).  
- `buy now` — Matches as a substring (case-insensitive).  
- `regex:/\bfree\s+money\b/i` — Regex match. Use PHP regex delimiters.  

### Email Rules

- `badguy@example.com` — Exact email match (case-insensitive).  
- `*@spammail.com` — Block an entire domain.  
- `regex:/@temp-mail\./i` — Regex match (useful for disposable email patterns).  

---

## Enable Blacklists on Form Fields

Event Horizon checks only the fields where you enable blacklist protection.

1. Go to **Forms → Edit Form**.  
2. Click the field you want to protect (for example: Email, Name, Message).  
3. In the field settings, open the **Advanced** tab.  
4. Enable **Content Blacklist** for text-based fields and/or **Email Blacklist** for email fields.  
5. Save the form.  

If these options are not enabled on a field, that field will not be scanned.