<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('EH_GFB_VERSION', 'test');

$GLOBALS['ehgfb_options'] = array();
$GLOBALS['ehgfb_scheduled'] = array();

function __(string $text, string $domain = ''): string {
    return $text;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void {}

function get_option(string $key, $default = false) {
    return $GLOBALS['ehgfb_options'][$key] ?? $default;
}

function update_option(string $key, $value, bool $autoload = false): bool {
    $GLOBALS['ehgfb_options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool {
    unset($GLOBALS['ehgfb_options'][$key]);
    return true;
}

function date_i18n(string $format, int $timestamp): string {
    return gmdate($format, $timestamp);
}

function wp_next_scheduled(string $hook) {
    return $GLOBALS['ehgfb_scheduled'][$hook] ?? false;
}

function wp_schedule_single_event(int $timestamp, string $hook): bool {
    $GLOBALS['ehgfb_scheduled'][$hook] = $timestamp;
    return true;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool {
    $GLOBALS['ehgfb_scheduled'][$hook] = $timestamp;
    return true;
}

function wp_clear_scheduled_hook(string $hook): void {
    unset($GLOBALS['ehgfb_scheduled'][$hook]);
}

function wp_parse_url(string $url) {
    return parse_url($url);
}

function sanitize_file_name(string $name): string {
    return preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
}

function sanitize_text_field(string $text): string {
    return trim($text);
}

function wp_salt(string $scheme = 'auth'): string {
    return 'test-salt';
}

function home_url(string $path = '/'): string {
    return 'https://example.test' . $path;
}

class EH_GFB_Admin {
    public const OPT_SYNC_INTERVAL = 'ehgfb_sync_interval';
    public const OPT_CONTENT_SOURCE = 'ehgfb_content_source';
    public const OPT_EMAIL_SOURCE = 'ehgfb_email_source';
    public const OPT_CONTENT_URL = 'ehgfb_content_sheet_url';
    public const OPT_EMAIL_URL = 'ehgfb_email_sheet_url';
    public const OPT_CONTENT_FILE = 'ehgfb_content_csv_file';
    public const OPT_EMAIL_FILE = 'ehgfb_email_csv_file';
    public const OPT_CONTENT_HEADER = 'ehgfb_content_has_header';
    public const OPT_EMAIL_HEADER = 'ehgfb_email_has_header';
}

class EH_GFB_Logger {
    public function log_event(string $type, string $list_type, int $form_id, int $field_id, string $rule, string $value_hash, string $message): void {}
}

require_once dirname(__DIR__) . '/includes/class-ehgfb-matcher.php';
require_once dirname(__DIR__) . '/includes/class-ehgfb-sync.php';

function assert_true(bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$matcher = new EH_GFB_Matcher();

assert_true(
    $matcher->match_email('user@example.com', array('example.com')) !== null,
    'Plain domain shorthand should match the exact domain.'
);

assert_true(
    $matcher->match_email('user@sub.example.com', array('example.com')) !== null,
    'Plain domain shorthand should match subdomains.'
);

assert_true(
    $matcher->match_email('user@notexample.com', array('example.com')) === null,
    'Plain domain shorthand must not match unrelated lookalike domains.'
);

update_option(EH_GFB_Admin::OPT_CONTENT_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS);
update_option(EH_GFB_Admin::OPT_EMAIL_SOURCE, EH_GFB_Sync::SOURCE_UPLOADED_CSV);
update_option(EH_GFB_Admin::OPT_SYNC_INTERVAL, 60);
update_option(EH_GFB_Sync::OPT_STATUS, array());

$sync = new EH_GFB_Sync(new EH_GFB_Logger());
$sync->maybe_background_refresh();

assert_true(
    wp_next_scheduled(EH_GFB_Sync::CRON_REFRESH_HOOK) !== false,
    'Stale remote sources should queue a non-blocking refresh event.'
);

$GLOBALS['ehgfb_scheduled'] = array();
update_option(
    EH_GFB_Sync::OPT_STATUS,
    array(
        'last_attempt_ts' => time(),
    )
);

$sync->maybe_background_refresh();

assert_true(
    wp_next_scheduled(EH_GFB_Sync::CRON_REFRESH_HOOK) === false,
    'Recent failed or attempted refreshes should be throttled.'
);

echo "Smoke tests passed.\n";
