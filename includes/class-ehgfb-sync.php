<?php
if (! defined('ABSPATH')) {
    exit;
}

class EH_GFB_Sync
{

    const CRON_HOOK = 'ehgfb_cron_sync';
    const CRON_PURGE_HOOK = 'ehgfb_cron_purge_logs';

    const OPT_CACHE_CONTENT = 'ehgfb_cache_content';
    const OPT_CACHE_EMAIL   = 'ehgfb_cache_email';
    const OPT_STATUS        = 'ehgfb_status';

    /** @var EH_GFB_Logger */
    private $logger;

    public function __construct(EH_GFB_Logger $logger)
    {
        $this->logger = $logger;
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }

    public function add_cron_schedules($schedules)
    {
        $minutes = (int) get_option(EH_GFB_Admin::OPT_SYNC_INTERVAL, 60);
        $minutes = max(5, min(1440, $minutes));

        $key = 'ehgfb_' . $minutes . 'min';
        $schedules[$key] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf(__('Event Horizon (%d minutes)', 'event-horizon-gf-blacklist'), $minutes),
        );
        return $schedules;
    }

    public function ensure_cron_scheduled(bool $reschedule = false): void
    {
        $minutes = (int) get_option(EH_GFB_Admin::OPT_SYNC_INTERVAL, 60);
        $minutes = max(5, min(1440, $minutes));
        $recurrence = 'ehgfb_' . $minutes . 'min';

        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next && $reschedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $next = false;
        }

        if (! $next) {
            wp_schedule_event(time() + 60, $recurrence, self::CRON_HOOK);
        }
    }

    public function run_scheduled_sync(): void
    {
        $this->sync_now(false);
    }

    public function maybe_background_refresh(): void
    {
        $status = get_option(self::OPT_STATUS, array());
        $last   = (int) ($status['last_sync_ts'] ?? 0);
        $minutes = (int) get_option(EH_GFB_Admin::OPT_SYNC_INTERVAL, 60);
        $ttl = max(5, min(1440, $minutes)) * MINUTE_IN_SECONDS;

        if ($last > 0 && (time() - $last) < $ttl) {
            return;
        }

        // Attempt a light refresh asynchronously (still runs in-request, but only once per TTL).
        // If your site has object cache, this keeps user requests snappy because we use conditional requests.
        $this->sync_now(false);
    }

    public function sync_now(bool $force): bool
    {
        $this->ensure_cron_scheduled();

        $ok_content = $this->sync_one('content', get_option(EH_GFB_Admin::OPT_CONTENT_URL, ''), $force, (int) get_option(EH_GFB_Admin::OPT_CONTENT_HEADER, 1));
        $ok_email   = $this->sync_one('email', get_option(EH_GFB_Admin::OPT_EMAIL_URL, ''), $force, (int) get_option(EH_GFB_Admin::OPT_EMAIL_HEADER, 1));

        $status = get_option(self::OPT_STATUS, array());
        $status['last_sync_ts'] = time();
        $status['last_sync_human'] = date_i18n('Y-m-d H:i:s', $status['last_sync_ts']);
        $status['content_count'] = count($this->get_cached_list('content'));
        $status['email_count']   = count($this->get_cached_list('email'));

        update_option(self::OPT_STATUS, $status, false);

        if ($ok_content && $ok_email) {
            $this->logger->log_event('sync', 'system', 0, 0, '', '', 'Sync completed successfully.');
            return true;
        }

        $this->logger->log_event('error', 'system', 0, 0, '', '', 'Sync completed with errors.');
        return false;
    }

    public function get_cached_list(string $type): array
    {
        $opt = ($type === 'email') ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;
        $cache = get_option($opt, array());
        $rules = $cache['rules'] ?? array();
        return is_array($rules) ? $rules : array();
    }

    /**
     * Clears the cached rules for a list type.
     * This does NOT modify the source Google Sheet.
     */
    public function clear_cached_list(string $type): void
    {
        $type = ($type === 'email') ? 'email' : 'content';
        $opt  = ($type === 'email') ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;

        delete_option($opt);

        // Update status counts.
        $status = get_option(self::OPT_STATUS, array());
        $status['content_count'] = count($this->get_cached_list('content'));
        $status['email_count']   = count($this->get_cached_list('email'));
        update_option(self::OPT_STATUS, $status, false);

        $this->logger->log_event('sync', $type, 0, 0, '', '', 'Cache cleared.');
    }

    public function get_status(): array
    {
        $status = get_option(self::OPT_STATUS, array());

        $warnings = array();
        if (empty(get_option(EH_GFB_Admin::OPT_CONTENT_URL, ''))) {
            $warnings[] = __('Content CSV URL is not set.', 'event-horizon-gf-blacklist');
        }
        if (empty(get_option(EH_GFB_Admin::OPT_EMAIL_URL, ''))) {
            $warnings[] = __('Email CSV URL is not set.', 'event-horizon-gf-blacklist');
        }

        $next = wp_next_scheduled(self::CRON_HOOK);
        $status['next_sync_human'] = $next ? date_i18n('Y-m-d H:i:s', $next) : '';

        $status['warnings'] = $warnings;

        if (empty($status['last_sync_human']) && ! empty($status['last_sync_ts'])) {
            $status['last_sync_human'] = date_i18n('Y-m-d H:i:s', (int) $status['last_sync_ts']);
        }
        if (! isset($status['content_count'])) {
            $status['content_count'] = count($this->get_cached_list('content'));
        }
        if (! isset($status['email_count'])) {
            $status['email_count'] = count($this->get_cached_list('email'));
        }

        return $status;
    }

    private function is_allowed_google_sheets_csv_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = (string) ($parts['path'] ?? '');
        $query  = (string) ($parts['query'] ?? '');

        if ($scheme !== 'https') {
            return false;
        }

        if ($host !== 'docs.google.com') {
            return false;
        }

        // Require the standard Sheets export path
        if (! preg_match('#^/spreadsheets/d/[^/]+/export$#', $path)) {
            return false;
        }

        parse_str($query, $params);

        // Must explicitly be CSV export
        if (strtolower((string) ($params['format'] ?? '')) !== 'csv') {
            return false;
        }

        return true;
    }

    private function sync_one(string $type, string $url, bool $force, int $has_header): bool
    {
        $url = trim($url);

        if ($url === '') {
            return true;
        } // Not configured: not an error.

        // Only allow Google Sheets CSV URLs over
        if (! $this->is_allowed_google_sheets_csv_url($url)) {
            $this->logger->log_event(
                'error',
                $type,
                0,
                0,
                '',
                '',
                'CSV URL must be a valid Google Sheets export URL.'
            );
            return false;
        }

        $opt = ($type === 'email') ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;
        $cache = get_option($opt, array());
        $headers = array();

        if (! $force) {
            if (! empty($cache['etag'])) {
                $headers['If-None-Match'] = (string) $cache['etag'];
            }
            if (! empty($cache['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $cache['last_modified'];
            }
        }

        $args = array(
            'timeout' => 10,
            'redirection' => 5,
            'headers' => $headers,
            'user-agent' => 'EventHorizonGFBlacklist/' . EH_GFB_VERSION . '; ' . home_url('/'),
        );

        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response)) {
            $this->logger->log_event('error', $type, 0, 0, '', '', 'Sync failed: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 304) {
            // Not modified - touch timestamp.
            $cache['fetched_at'] = time();
            update_option($opt, $cache, false);
            $this->logger->log_event('sync', $type, 0, 0, '', '', 'Not modified (304), cache retained.');
            return true;
        }

        if ($code < 200 || $code >= 300) {
            $this->logger->log_event('error', $type, 0, 0, '', '', 'Sync failed HTTP ' . $code);
            return false;
        }

        $body = (string) wp_remote_retrieve_body($response);

        // Prevent extremely large CSV downloads
        if (strlen($body) > 500000) { // 500KB
            $this->logger->log_event(
                'error',
                $type,
                0,
                0,
                '',
                '',
                'CSV file exceeded 500KB safety limit.'
            );
            return false;
        }

        $rules = $this->parse_csv_rules($body, $has_header);

        $new_cache = array(
            'rules' => $rules,
            'etag' => wp_remote_retrieve_header($response, 'etag'),
            'last_modified' => wp_remote_retrieve_header($response, 'last-modified'),
            'fetched_at' => time(),
            'url_hash' => md5($url),
        );

        update_option($opt, $new_cache, false);

        $this->logger->log_event('sync', $type, 0, 0, '', '', sprintf('Fetched %d rule(s).', count($rules)));
        return true;
    }

    private function parse_csv_rules(string $csv, int $has_header): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return array();
        }

        // Normalize newlines.
        $csv = preg_replace("/\r\n?/", "\n", $csv);

        $lines = explode("\n", $csv);
        $rules = array();
        $row_index = 0;

        foreach ($lines as $line) {

            // Skip suspiciously long lines
            if (strlen($line) > 2000) {
                continue;
            }

            $row_index++;
            if ($line === '') {
                continue;
            }

            $cols = str_getcsv($line);
            if (empty($cols)) {
                continue;
            }

            $value = sanitize_text_field(trim((string) $cols[0]));
            $value = preg_replace('/\s+/', ' ', $value);

            // Prevent too long strings
            if (strlen($value) > 512) {
                continue;
            }

            // Prevent formula injection
            if (preg_match('/^[=+\-@]/', $value)) {
                $value = "'" . $value;
            }

            if ($value === '') {
                continue;
            }

            if ($has_header && $row_index === 1) {
                continue;
            }

            // Ignore comment rows
            if (preg_match('/^\s*(#|\/\/)/', $value)) {
                continue;
            }

            $rules[] = $value;
        }

        // Deduplicate while preserving order (case-insensitive)
        $seen = array();
        $deduped = array();
        foreach ($rules as $r) {
            $k = strtolower($r);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $deduped[] = $r;
        }

        // Hard safety limit to prevent memory abuse
        if (count($deduped) > 10000) {
            $deduped = array_slice($deduped, 0, 10000);
        }

        return $deduped;
    }
}
