<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EH_GFB_Sync {

    const SOURCE_GOOGLE_SHEETS = 'google_sheets';
    const SOURCE_UPLOADED_CSV  = 'uploaded_csv';

    const CRON_HOOK = 'ehgfb_cron_sync';
    const CRON_REFRESH_HOOK = 'ehgfb_cron_refresh_sync';
    const CRON_PURGE_HOOK = 'ehgfb_cron_purge_logs';

    const OPT_CACHE_CONTENT = 'ehgfb_cache_content';
    const OPT_CACHE_EMAIL   = 'ehgfb_cache_email';
    const OPT_STATUS        = 'ehgfb_status';

    /** @var EH_GFB_Logger */
    private $logger;

    public function __construct( EH_GFB_Logger $logger ) {
        $this->logger = $logger;
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    public function add_cron_schedules( $schedules ) {
        $minutes = (int) get_option( EH_GFB_Admin::OPT_SYNC_INTERVAL, 60 );
        $minutes = max( 5, min( 1440, $minutes ) );

        $key = 'ehgfb_' . $minutes . 'min';
        $schedules[ $key ] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf( __( 'Event Horizon (%d minutes)', 'event-horizon-gf-blacklist' ), $minutes ),
        );

        return $schedules;
    }

    public function ensure_cron_scheduled( bool $reschedule = false ) : void {
        if ( ! $this->should_schedule_remote_sync() ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_clear_scheduled_hook( self::CRON_REFRESH_HOOK );
            return;
        }

        $minutes = (int) get_option( EH_GFB_Admin::OPT_SYNC_INTERVAL, 60 );
        $minutes = max( 5, min( 1440, $minutes ) );
        $recurrence = 'ehgfb_' . $minutes . 'min';

        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next && $reschedule ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            $next = false;
        }

        if ( ! $next ) {
            wp_schedule_event( time() + 60, $recurrence, self::CRON_HOOK );
        }
    }

    public function run_scheduled_sync() : void {
        $this->sync_now( false );
    }

    public function maybe_background_refresh() : void {
        if ( ! $this->should_schedule_remote_sync() ) {
            return;
        }

        $status  = get_option( self::OPT_STATUS, array() );
        $last    = max(
            (int) ( $status['last_sync_ts'] ?? 0 ),
            (int) ( $status['last_attempt_ts'] ?? 0 )
        );
        $minutes = (int) get_option( EH_GFB_Admin::OPT_SYNC_INTERVAL, 60 );
        $ttl     = max( 5, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;

        if ( $last > 0 && ( time() - $last ) < $ttl ) {
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_REFRESH_HOOK ) ) {
            wp_schedule_single_event( time() + 30, self::CRON_REFRESH_HOOK );
        }
    }

    public function sync_now( bool $force ) : bool {
        $this->ensure_cron_scheduled();

        $ok_content = $this->sync_one( 'content', $force );
        $ok_email   = $this->sync_one( 'email', $force );
        $now        = time();

        $status                     = get_option( self::OPT_STATUS, array() );
        $status['last_attempt_ts']  = $now;
        $status['last_attempt_human'] = date_i18n( 'Y-m-d H:i:s', $now );
        $status['content_count']    = count( $this->get_cached_list( 'content' ) );
        $status['email_count']      = count( $this->get_cached_list( 'email' ) );
        $status['cron_enabled']     = $this->should_schedule_remote_sync();
        $status['content_source']   = $this->get_source_mode( 'content' );
        $status['email_source']     = $this->get_source_mode( 'email' );

        if ( $ok_content && $ok_email ) {
            $status['last_sync_ts']    = $now;
            $status['last_sync_human'] = date_i18n( 'Y-m-d H:i:s', $now );
            $status['last_result']     = 'success';
            update_option( self::OPT_STATUS, $status, false );
            $this->logger->log_event( 'sync', 'system', 0, 0, '', '', 'Blacklist refresh completed successfully.' );
            return true;
        }

        $status['last_result'] = 'error';
        update_option( self::OPT_STATUS, $status, false );
        $this->logger->log_event( 'error', 'system', 0, 0, '', '', 'Blacklist refresh completed with errors.' );
        return false;
    }

    public function get_cached_list( string $type ) : array {
        $opt   = ( 'email' === $type ) ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;
        $cache = get_option( $opt, array() );
        $rules = $cache['rules'] ?? array();

        return is_array( $rules ) ? $rules : array();
    }

    /**
     * Clears the cached rules for a list type.
     * This does NOT modify the configured source file or sheet.
     */
    public function clear_cached_list( string $type ) : void {
        $type = ( 'email' === $type ) ? 'email' : 'content';
        $opt  = ( 'email' === $type ) ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;

        delete_option( $opt );

        $status                   = get_option( self::OPT_STATUS, array() );
        $status['content_count']  = count( $this->get_cached_list( 'content' ) );
        $status['email_count']    = count( $this->get_cached_list( 'email' ) );
        update_option( self::OPT_STATUS, $status, false );

        $this->logger->log_event( 'sync', $type, 0, 0, '', '', 'Cache cleared.' );
    }

    public function get_status() : array {
        $status = get_option( self::OPT_STATUS, array() );

        $warnings = array_merge(
            $this->get_source_warnings( 'content' ),
            $this->get_source_warnings( 'email' )
        );

        if ( ( $status['last_result'] ?? '' ) === 'error' ) {
            $attempt = (string) ( $status['last_attempt_human'] ?? '' );
            $warnings[] = $attempt !== ''
                ? sprintf( __( 'The last refresh attempt failed at %s. Existing cached rules are still in use.', 'event-horizon-gf-blacklist' ), $attempt )
                : __( 'The last refresh attempt failed. Existing cached rules are still in use.', 'event-horizon-gf-blacklist' );
        }

        $next = wp_next_scheduled( self::CRON_HOOK );
        $status['next_sync_human'] = $next ? date_i18n( 'Y-m-d H:i:s', $next ) : '';
        $status['cron_enabled']    = $this->should_schedule_remote_sync();
        $status['warnings']        = $warnings;

        if ( empty( $status['last_sync_human'] ) && ! empty( $status['last_sync_ts'] ) ) {
            $status['last_sync_human'] = date_i18n( 'Y-m-d H:i:s', (int) $status['last_sync_ts'] );
        }
        if ( ! isset( $status['content_count'] ) ) {
            $status['content_count'] = count( $this->get_cached_list( 'content' ) );
        }
        if ( ! isset( $status['email_count'] ) ) {
            $status['email_count'] = count( $this->get_cached_list( 'email' ) );
        }

        return $status;
    }

    public function should_schedule_remote_sync() : bool {
        return self::SOURCE_GOOGLE_SHEETS === $this->get_source_mode( 'content' )
            || self::SOURCE_GOOGLE_SHEETS === $this->get_source_mode( 'email' );
    }

    public function get_source_mode( string $type ) : string {
        $option = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_SOURCE : EH_GFB_Admin::OPT_CONTENT_SOURCE;
        $value  = (string) get_option( $option, self::SOURCE_GOOGLE_SHEETS );

        return in_array( $value, array( self::SOURCE_GOOGLE_SHEETS, self::SOURCE_UPLOADED_CSV ), true )
            ? $value
            : self::SOURCE_GOOGLE_SHEETS;
    }

    public function get_source_label( string $type ) : string {
        return ( self::SOURCE_UPLOADED_CSV === $this->get_source_mode( $type ) )
            ? __( 'Uploaded CSV', 'event-horizon-gf-blacklist' )
            : __( 'Google Sheet', 'event-horizon-gf-blacklist' );
    }

    private function is_allowed_google_sheets_csv_url( string $url ) : bool {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path   = (string) ( $parts['path'] ?? '' );
        $query  = (string) ( $parts['query'] ?? '' );

        if ( 'https' !== $scheme ) {
            return false;
        }

        if ( 'docs.google.com' !== $host ) {
            return false;
        }

        if ( ! preg_match( '#^/spreadsheets/d/[^/]+/export$#', $path ) ) {
            return false;
        }

        parse_str( $query, $params );

        if ( 'csv' !== strtolower( (string) ( $params['format'] ?? '' ) ) ) {
            return false;
        }

        return true;
    }

    private function sync_one( string $type, bool $force ) : bool {
        $header_option = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_HEADER : EH_GFB_Admin::OPT_CONTENT_HEADER;
        $has_header    = (int) get_option( $header_option, 1 );
        $source_mode   = $this->get_source_mode( $type );

        if ( self::SOURCE_UPLOADED_CSV === $source_mode ) {
            return $this->sync_uploaded_csv( $type, $has_header );
        }

        $url_option = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_URL : EH_GFB_Admin::OPT_CONTENT_URL;
        $url        = trim( (string) get_option( $url_option, '' ) );

        return $this->sync_remote_csv( $type, $url, $force, $has_header );
    }

    private function sync_remote_csv( string $type, string $url, bool $force, int $has_header ) : bool {
        if ( '' === $url ) {
            return true;
        }

        if ( ! $this->is_allowed_google_sheets_csv_url( $url ) ) {
            $this->logger->log_event( 'error', $type, 0, 0, '', '', 'CSV URL must be a valid Google Sheets export URL.' );
            return false;
        }

        $opt     = ( 'email' === $type ) ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;
        $cache   = get_option( $opt, array() );
        $headers = array();

        if ( ! $force ) {
            if ( ! empty( $cache['etag'] ) ) {
                $headers['If-None-Match'] = (string) $cache['etag'];
            }
            if ( ! empty( $cache['last_modified'] ) ) {
                $headers['If-Modified-Since'] = (string) $cache['last_modified'];
            }
        }

        $args = array(
            'timeout'     => 10,
            'redirection' => 5,
            'headers'     => $headers,
            'user-agent'  => 'EventHorizonGFBlacklist/' . EH_GFB_VERSION . '; ' . home_url( '/' ),
        );

        $response = wp_safe_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            $this->logger->log_event( 'error', $type, 0, 0, '', '', 'Sync failed: ' . $response->get_error_message() );
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 304 === $code ) {
            $cache['fetched_at'] = time();
            update_option( $opt, $cache, false );
            $this->logger->log_event( 'sync', $type, 0, 0, '', '', 'Google Sheets CSV not modified; cache retained.' );
            return true;
        }

        if ( $code < 200 || $code >= 300 ) {
            $this->logger->log_event( 'error', $type, 0, 0, '', '', 'Sync failed HTTP ' . $code );
            return false;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > 500000 ) {
            $this->logger->log_event( 'error', $type, 0, 0, '', '', 'CSV file exceeded 500KB safety limit.' );
            return false;
        }

        $rules = $this->parse_csv_rules( $body, $has_header );

        $meta = array(
            'etag'          => wp_remote_retrieve_header( $response, 'etag' ),
            'last_modified' => wp_remote_retrieve_header( $response, 'last-modified' ),
            'url_hash'      => md5( $url ),
            'source_mode'   => self::SOURCE_GOOGLE_SHEETS,
            'source_label'  => 'google_sheets',
        );

        return $this->store_rules(
            $type,
            $rules,
            $meta,
            sprintf( 'Fetched %d rule(s) from Google Sheets.', count( $rules ) )
        );
    }

    private function sync_uploaded_csv( string $type, int $has_header ) : bool {
        $file = $this->get_uploaded_csv_details( $type );
        $body = '';

        if ( ! empty( $file['contents'] ) && is_string( $file['contents'] ) ) {
            $body = $file['contents'];
        } elseif ( ! empty( $file['path'] ) ) {
            $path = (string) $file['path'];
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                $this->logger->log_event( 'error', $type, 0, 0, '', '', 'Uploaded CSV file is missing or unreadable.' );
                return false;
            }

            $size = @filesize( $path );
            if ( is_int( $size ) && $size > 500000 ) {
                $this->logger->log_event( 'error', $type, 0, 0, '', '', 'CSV file exceeded 500KB safety limit.' );
                return false;
            }

            $body = file_get_contents( $path );
            if ( false === $body ) {
                $this->logger->log_event( 'error', $type, 0, 0, '', '', 'Failed to read uploaded CSV file.' );
                return false;
            }

            $legacy_hash = @md5_file( $path );
            $this->migrate_uploaded_csv_storage(
                $type,
                array(
                    'contents' => $body,
                    'name'     => (string) ( $file['name'] ?? '' ),
                    'hash'     => $legacy_hash ? $legacy_hash : md5( $body ),
                )
            );
        } else {
            return true;
        }

        if ( strlen( $body ) > 500000 ) {
            $this->logger->log_event( 'error', $type, 0, 0, '', '', 'CSV file exceeded 500KB safety limit.' );
            return false;
        }

        $rules = $this->parse_csv_rules( $body, $has_header );

        $meta = array(
            'etag'          => '',
            'last_modified' => gmdate( 'D, d M Y H:i:s' ) . ' GMT',
            'file_hash'     => ! empty( $file['hash'] ) ? (string) $file['hash'] : md5( $body ),
            'source_mode'   => self::SOURCE_UPLOADED_CSV,
            'source_label'  => 'uploaded_csv',
            'file_name'     => (string) ( $file['name'] ?? '' ),
        );

        return $this->store_rules(
            $type,
            $rules,
            $meta,
            sprintf( 'Imported %d rule(s) from uploaded CSV.', count( $rules ) )
        );
    }

    private function store_rules( string $type, array $rules, array $meta, string $message ) : bool {
        $opt = ( 'email' === $type ) ? self::OPT_CACHE_EMAIL : self::OPT_CACHE_CONTENT;

        $cache = array_merge(
            array(
                'rules'         => array(),
                'etag'          => '',
                'last_modified' => '',
                'fetched_at'    => time(),
            ),
            $meta,
            array(
                'rules'      => $rules,
                'fetched_at' => time(),
            )
        );

        update_option( $opt, $cache, false );
        $this->logger->log_event( 'sync', $type, 0, 0, '', '', $message );

        return true;
    }

    private function get_uploaded_csv_details( string $type ) : array {
        $option = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_FILE : EH_GFB_Admin::OPT_CONTENT_FILE;
        $file   = get_option( $option, array() );

        return is_array( $file ) ? $file : array();
    }

    private function migrate_uploaded_csv_storage( string $type, array $data ) : void {
        $option   = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_FILE : EH_GFB_Admin::OPT_CONTENT_FILE;
        $existing = $this->get_uploaded_csv_details( $type );
        $path     = (string) ( $existing['path'] ?? '' );

        if ( $path !== '' && file_exists( $path ) && is_writable( $path ) ) {
            wp_delete_file( $path );
        }

        update_option(
            $option,
            array(
                'name'      => sanitize_file_name( (string) ( $data['name'] ?? '' ) ),
                'contents'  => (string) ( $data['contents'] ?? '' ),
                'hash'      => (string) ( $data['hash'] ?? '' ),
                'updated_at' => time(),
            ),
            false
        );
    }

    private function get_source_warnings( string $type ) : array {
        $label = ( 'email' === $type )
            ? __( 'Email', 'event-horizon-gf-blacklist' )
            : __( 'Content', 'event-horizon-gf-blacklist' );

        if ( self::SOURCE_UPLOADED_CSV === $this->get_source_mode( $type ) ) {
            $file = $this->get_uploaded_csv_details( $type );

            if ( ! empty( $file['contents'] ) ) {
                return array();
            }

            if ( empty( $file['path'] ) ) {
                return array( sprintf( __( '%s uploaded CSV is not set.', 'event-horizon-gf-blacklist' ), $label ) );
            }

            if ( ! file_exists( (string) $file['path'] ) ) {
                return array( sprintf( __( '%s uploaded CSV file is missing.', 'event-horizon-gf-blacklist' ), $label ) );
            }

            return array();
        }

        $url_option = ( 'email' === $type ) ? EH_GFB_Admin::OPT_EMAIL_URL : EH_GFB_Admin::OPT_CONTENT_URL;
        if ( empty( get_option( $url_option, '' ) ) ) {
            return array( sprintf( __( '%s Google Sheets CSV URL is not set.', 'event-horizon-gf-blacklist' ), $label ) );
        }

        return array();
    }

    private function parse_csv_rules( string $csv, int $has_header ) : array {
        $csv = trim( $csv );
        if ( '' === $csv ) {
            return array();
        }

        $csv = preg_replace( "/\r\n?/", "\n", $csv );
        $lines = explode( "\n", $csv );
        $rules = array();
        $row_index = 0;

        foreach ( $lines as $line ) {
            if ( strlen( $line ) > 2000 ) {
                continue;
            }

            ++$row_index;
            if ( '' === $line ) {
                continue;
            }

            $cols = str_getcsv( $line, ',', '"', '\\' );
            if ( empty( $cols ) ) {
                continue;
            }

            $value = sanitize_text_field( trim( (string) $cols[0] ) );
            $value = preg_replace( '/\s+/', ' ', $value );

            if ( strlen( $value ) > 512 ) {
                continue;
            }

            if ( preg_match( '/^[=+\-@]/', $value ) ) {
                $value = "'" . $value;
            }

            if ( '' === $value ) {
                continue;
            }

            if ( $has_header && 1 === $row_index ) {
                continue;
            }

            if ( preg_match( '/^\s*(#|\/\/)/', $value ) ) {
                continue;
            }

            $rules[] = $value;
        }

        $seen = array();
        $deduped = array();
        foreach ( $rules as $rule ) {
            $key = strtolower( $rule );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $deduped[] = $rule;
        }

        if ( count( $deduped ) > 10000 ) {
            $deduped = array_slice( $deduped, 0, 10000 );
        }

        return $deduped;
    }
}
