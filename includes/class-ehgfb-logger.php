<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EH_GFB_Logger {

    private function table_name() : string {
        global $wpdb;
        return $wpdb->prefix . 'ehgfb_log';
    }

    public function maybe_create_table() : void {
        global $wpdb;
        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            type VARCHAR(32) NOT NULL,
            list_type VARCHAR(16) NOT NULL DEFAULT '',
            form_id INT(11) NOT NULL DEFAULT 0,
            field_id INT(11) NOT NULL DEFAULT 0,
            rule TEXT NULL,
            value_hash CHAR(64) NOT NULL DEFAULT '',
            message TEXT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY type (type),
            KEY list_type (list_type),
            KEY form_id (form_id)
        ) {$charset};";

        dbDelta( $sql );
    }

    public function ensure_purge_scheduled() : void {
        $next = wp_next_scheduled( EH_GFB_Sync::CRON_PURGE_HOOK );
        if ( ! $next ) {
            wp_schedule_event( time() + 300, 'daily', EH_GFB_Sync::CRON_PURGE_HOOK );
        }
    }

    private function enabled() : bool {
        return (bool) get_option( EH_GFB_Admin::OPT_LOG_ENABLED, 1 );
    }

    public function log_match( string $list_type, int $form_id, int $field_id, string $rule, string $value_hash ) : void {
        $this->log_event( 'match', $list_type, $form_id, $field_id, $rule, $value_hash, 'Blacklist matched.' );
    }

    public function log_event( string $type, string $list_type, int $form_id, int $field_id, string $rule, string $value_hash, string $message ) : void {
        if ( ! $this->enabled() ) { return; }

        // Only log form-related events. We intentionally suppress sync noise.
        // - type 'sync' is always suppressed
        // - type 'error' is suppressed when not tied to a specific form/field (i.e., sync/system errors)
        $t = sanitize_key( $type );
        if ( $t === 'sync' ) { return; }
        if ( $t === 'error' && ( (int) $form_id === 0 ) && ( (int) $field_id === 0 ) ) { return; }

        global $wpdb;
        $table = $this->table_name();

        // Ensure table exists.
        $this->maybe_create_table();

        $wpdb->insert(
            $table,
            array(
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'type' => $t,
                'list_type' => sanitize_key( $list_type ),
                'form_id' => (int) $form_id,
                'field_id' => (int) $field_id,
                'rule' => $rule,
                'value_hash' => $value_hash,
                'message' => $message,
            ),
            array( '%s','%s','%s','%d','%d','%s','%s','%s' )
        );
    }

    public function get_logs( int $page, int $per_page ) : array {
        global $wpdb;
        $table = $this->table_name();

        $page = max( 1, $page );
        $per_page = max( 1, min( 200, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, type, list_type, form_id, field_id, rule, value_hash, message
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                 $per_page, $offset
            ),
            ARRAY_A
        );

        // Convert to site timezone for display.
        foreach ( $rows as &$r ) {
            $ts = strtotime( $r['created_at'] . ' UTC' );
            if ( $ts ) {
                $r['created_at'] = date_i18n( 'Y-m-d H:i:s', $ts );
            }
        }

        return array( 'total' => $total, 'rows' => $rows );
    }

    public function clear_logs() : void {
        if ( ! $this->enabled() ) { return; }
        global $wpdb;
        $table = $this->table_name();
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    public function purge_old_logs() : void {
        if ( ! $this->enabled() ) { return; }
        global $wpdb;
        $table = $this->table_name();

        $days = (int) get_option( EH_GFB_Admin::OPT_LOG_RETENTION, 30 );
        $days = max( 1, min( 365, $days ) );

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
    }

    public function get_metrics( int $days ) : array {
        global $wpdb;
        $table = $this->table_name();

        $days = max( 1, min( 365, $days ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $matches = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type='match' AND created_at >= %s", $cutoff ) );
        $sync_success = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type='sync' AND created_at >= %s", $cutoff ) );
        $sync_error = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type='error' AND created_at >= %s", $cutoff ) );

        return array(
            'matches' => $matches,
            'sync_success' => $sync_success,
            'sync_error' => $sync_error,
        );
    }
}
