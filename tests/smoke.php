<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('EH_GFB_VERSION', 'test');

$GLOBALS['ehgfb_options'] = array();
$GLOBALS['ehgfb_scheduled'] = array();
$GLOBALS['ehgfb_user_meta'] = array();
$GLOBALS['ehgfb_redirect_to'] = null;

function __(string $text, string $domain = ''): string {
    return $text;
}

function esc_html__(string $text, string $domain = ''): string {
    return $text;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): void {}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void {}

function current_user_can(string $capability): bool {
    return true;
}

function wp_die(string $message): void {
    throw new RuntimeException($message);
}

function check_admin_referer(string $action): void {}

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
    return trim(strip_tags($text));
}

function sanitize_key(string $text): string {
    $text = strtolower($text);
    return preg_replace('/[^a-z0-9_\-]/', '', $text);
}

function wp_salt(string $scheme = 'auth'): string {
    return 'test-salt';
}

function home_url(string $path = '/'): string {
    return 'https://example.test' . $path;
}

function esc_url_raw(string $url): string {
    return trim($url);
}

function wp_strip_all_tags(string $text): string {
    return strip_tags($text);
}

function wp_unslash($value) {
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function admin_url(string $path = ''): string {
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function wp_safe_redirect(string $url): void {
    $GLOBALS['ehgfb_redirect_to'] = $url;
}

function wp_get_referer(): string {
    return 'https://example.test/wp-admin/admin.php?page=ehgfb';
}

function add_query_arg($args, string $url): string {
    if (! is_array($args)) {
        $args = array($args => func_get_arg(2));
    }

    $parts = parse_url($url);
    $query = array();
    if (! empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    foreach ($args as $key => $value) {
        $query[$key] = $value;
    }

    $path = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.test');
    if (! empty($parts['path'])) {
        $path .= $parts['path'];
    }

    return $path . '?' . http_build_query($query);
}

function get_current_user_id(): int {
    return 123;
}

function get_user_meta(int $user_id, string $key, bool $single = false) {
    return $GLOBALS['ehgfb_user_meta'][$user_id][$key] ?? ($single ? '' : array());
}

function update_user_meta(int $user_id, string $key, $value): bool {
    $GLOBALS['ehgfb_user_meta'][$user_id][$key] = $value;
    return true;
}

function rgar($array, string $key) {
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : null;
}

class EH_GFB_Logger {
    public array $events = array();
    public array $matches = array();

    public function maybe_upgrade(): void {}

    public function log_event(string $type, string $list_type, int $form_id, int $field_id, string $rule, string $value_hash, string $message): void {
        $this->events[] = compact('type', 'list_type', 'form_id', 'field_id', 'rule', 'value_hash', 'message');
    }

    public function log_match(string $list_type, int $form_id, int $field_id, string $rule, string $value_hash): void {
        $this->matches[] = compact('list_type', 'form_id', 'field_id', 'rule', 'value_hash');
    }
}

require_once dirname(__DIR__) . '/includes/class-ehgfb-matcher.php';
require_once dirname(__DIR__) . '/includes/class-ehgfb-sync.php';
require_once dirname(__DIR__) . '/includes/class-ehgfb-admin.php';
require_once dirname(__DIR__) . '/includes/class-ehgfb-plugin.php';

function assert_true(bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function call_private_method(object $object, string $method, array $args = array()) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function set_private_property(object $object, string $property, $value): void {
    $reflection = new ReflectionProperty($object, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($object, $value);
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

$logger = new EH_GFB_Logger();
$sync = new EH_GFB_Sync($logger);
$admin = new EH_GFB_Admin($sync, $logger);

assert_same(
    "Quoted and bold text",
    $admin->sanitize_message('Quoted <strong>and</strong> bold text'),
    'sanitize_message should strip tags but preserve readable text.'
);

$converted = call_private_method(
    $admin,
    'convert_google_sheet_url_to_csv_export',
    array('https://docs.google.com/spreadsheets/d/abc123/edit?usp=sharing#gid=456')
);

assert_same(
    'https://docs.google.com/spreadsheets/d/abc123/export?format=csv&gid=456',
    $converted,
    'URL fixer should create a canonical CSV export URL without a leftover fragment.'
);

$parsed_rules = call_private_method(
    $sync,
    'parse_csv_rules',
    array("Rule\ncasino\ncasino\n=SUM(A1)\n# comment\nbuy now", 1)
);

assert_same(
    array('casino', "'=SUM(A1)", 'buy now'),
    $parsed_rules,
    'CSV parsing should skip the header, dedupe rules, and neutralize spreadsheet formulas.'
);

update_option(EH_GFB_Admin::OPT_CONTENT_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS);
update_option(EH_GFB_Admin::OPT_EMAIL_SOURCE, EH_GFB_Sync::SOURCE_UPLOADED_CSV);
update_option(EH_GFB_Admin::OPT_SYNC_INTERVAL, 60);
update_option(EH_GFB_Sync::OPT_STATUS, array());

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

$counting_sync = new class {
    public int $calls = 0;

    public function get_cached_list(string $type): array {
        ++$this->calls;

        if ($type === 'content') {
            return array('casino');
        }

        return array('example.com');
    }
};

$plugin_reflection = new ReflectionClass('EH_GFB_Plugin');
$plugin = $plugin_reflection->newInstanceWithoutConstructor();
$test_logger = new EH_GFB_Logger();

set_private_property($plugin, 'sync', $counting_sync);
set_private_property($plugin, 'matcher', new EH_GFB_Matcher());
set_private_property($plugin, 'logger', $test_logger);
set_private_property($plugin, 'validation_hits', array());
set_private_property($plugin, 'spam_form_ids', array());
set_private_property($plugin, 'request_lists', array());

$form = array('id' => 7);
$field = (object) array(
    'id' => 4,
    'label' => 'Message',
    'ehgfb_content' => true,
    'ehgfb_email' => false,
);

$plugin->pre_validation_refresh($form);
$first = $plugin->field_validation(array('is_valid' => true), 'casino offer', $form, $field);
$second = $plugin->field_validation(array('is_valid' => true), 'casino bonus', $form, $field);

assert_true($first['is_valid'] === false, 'First validation should fail on a content blacklist match.');
assert_true($second['is_valid'] === false, 'Second validation should reuse the same cached rules and still fail.');
assert_same(2, $counting_sync->calls, 'Validation-cycle cache should load each list once during pre-validation and reuse them for fields.');
assert_same(2, count($test_logger->matches), 'Each blacklist hit should still be logged when matches occur.');

echo "Smoke tests passed.\n";
