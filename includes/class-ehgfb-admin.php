<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EH_GFB_Admin {

    const MENU_SLUG = 'ehgfb';
    const CAPABILITY = 'manage_options';

    // Options
    const OPT_CONTENT_SOURCE  = 'ehgfb_content_source';
    const OPT_EMAIL_SOURCE    = 'ehgfb_email_source';
    const OPT_CONTENT_URL     = 'ehgfb_content_sheet_url';
    const OPT_EMAIL_URL       = 'ehgfb_email_sheet_url';
    const OPT_CONTENT_FILE    = 'ehgfb_content_csv_file';
    const OPT_EMAIL_FILE      = 'ehgfb_email_csv_file';
    const OPT_BEHAVIOR        = 'ehgfb_spam_behavior';
    const OPT_BLOCK_MESSAGE   = 'ehgfb_block_message';
    const OPT_SYNC_INTERVAL   = 'ehgfb_sync_interval'; // in minutes
    const OPT_LOG_ENABLED     = 'ehgfb_log_enabled';
    const OPT_LOG_RETENTION   = 'ehgfb_log_retention_days';
    const OPT_CONTENT_HEADER  = 'ehgfb_content_has_header';
    const OPT_EMAIL_HEADER    = 'ehgfb_email_has_header';

    // Per-user acknowledgement for viewing lists.
    const USERMETA_LISTS_ACK  = 'ehgfb_lists_ack_v1';

    /** @var EH_GFB_Sync */
    private $sync;

    /** @var EH_GFB_Logger */
    private $logger;

    public function __construct( EH_GFB_Sync $sync, EH_GFB_Logger $logger ) {
        $this->sync   = $sync;
        $this->logger = $logger;

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_ehgfb_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_ehgfb_manual_sync', array( $this, 'handle_manual_sync' ) );
        add_action( 'admin_post_ehgfb_clear_lists', array( $this, 'handle_clear_lists' ) );
        add_action( 'admin_post_ehgfb_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_ehgfb_url_fixer', array( $this, 'handle_url_fixer' ) );
    }

    public function register_menu() : void {
        $icon = EH_GFB_PLUGIN_URL . 'logo-icon.webp';

        add_menu_page(
            __( 'Event Horizon', 'event-horizon-gf-blacklist' ),
            __( 'Event Horizon', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            $icon,
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings', 'event-horizon-gf-blacklist' ),
            __( 'Settings', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Lists', 'event-horizon-gf-blacklist' ),
            __( 'Lists', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-lists',
            array( $this, 'render_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Logs', 'event-horizon-gf-blacklist' ),
            __( 'Logs', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-logs',
            array( $this, 'render_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Tools', 'event-horizon-gf-blacklist' ),
            __( 'Tools', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-tools',
            array( $this, 'render_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Help', 'event-horizon-gf-blacklist' ),
            __( 'Help', 'event-horizon-gf-blacklist' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-help',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() : void {
        // Reasonable defaults
        add_option( self::OPT_CONTENT_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
        add_option( self::OPT_EMAIL_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
        add_option( self::OPT_BEHAVIOR, 'no_entry' );
        add_option( self::OPT_BLOCK_MESSAGE, __( 'Your submission was blocked.', 'event-horizon-gf-blacklist' ) );
        add_option( self::OPT_SYNC_INTERVAL, 60 ); // minutes
        add_option( self::OPT_LOG_ENABLED, 1 );
        add_option( self::OPT_LOG_RETENTION, 30 );
        add_option( self::OPT_CONTENT_HEADER, 1 );
        add_option( self::OPT_EMAIL_HEADER, 1 );
    }

    public function enqueue_assets( $hook ) : void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) { return; }
        wp_enqueue_style( 'ehgfb-admin', EH_GFB_PLUGIN_URL . 'assets/admin.css', array(), EH_GFB_VERSION );
    }

    public function render_page() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { return; }

        $screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG;
        $tab = 'settings';
        if ( $screen === self::MENU_SLUG . '-lists' ) { $tab = 'lists'; }
        if ( $screen === self::MENU_SLUG . '-logs' ) { $tab = 'logs'; }
        if ( $screen === self::MENU_SLUG . '-tools' ) { $tab = 'tools'; }
        if ( $screen === self::MENU_SLUG . '-help' ) { $tab = 'help'; }

        $status = $this->sync->get_status();

        ?>
        <div class="wrap ehgfb-wrap">
            <div class="ehgfb-header">
                <img class="ehgfb-logo" src="<?php echo esc_url( EH_GFB_PLUGIN_URL . 'logo.webp' ); ?>" alt="<?php esc_attr_e( 'Event Horizon', 'event-horizon-gf-blacklist' ); ?>" />
                <div class="ehgfb-title">
                    <h1><?php esc_html_e( 'Event Horizon', 'event-horizon-gf-blacklist' ); ?></h1>
                    <p class="description"><?php esc_html_e( 'Gravity Forms blacklist import + enforcement.', 'event-horizon-gf-blacklist' ); ?></p>
                </div>
            </div>

            <nav class="nav-tab-wrapper ehgfb-tabs">
                <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
                    <?php esc_html_e( 'Settings', 'event-horizon-gf-blacklist' ); ?>
                </a>
                <a class="nav-tab <?php echo $tab === 'lists' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-lists' ) ); ?>">
                    <?php esc_html_e( 'Lists', 'event-horizon-gf-blacklist' ); ?>
                </a>
                <a class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-logs' ) ); ?>">
                    <?php esc_html_e( 'Logs', 'event-horizon-gf-blacklist' ); ?>
                </a>
                <a class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-tools' ) ); ?>">
                    <?php esc_html_e( 'Tools', 'event-horizon-gf-blacklist' ); ?>
                </a>
                <a class="nav-tab <?php echo $tab === 'help' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-help' ) ); ?>">
                    <?php esc_html_e( 'Help', 'event-horizon-gf-blacklist' ); ?>
                </a>
            </nav>

            <?php $this->render_status_banner( $status ); ?>

            <?php $this->render_notices(); ?>

            <?php
                if ( $tab === 'settings' ) { $this->render_settings(); }
                if ( $tab === 'lists' ) { $this->render_lists(); }
                if ( $tab === 'logs' ) { $this->render_logs(); }
                if ( $tab === 'tools' ) { $this->render_tools(); }
                if ( $tab === 'help' ) { $this->render_help(); }
            ?>
        </div>
        <?php
    }

    private function render_notices() : void {
        if ( isset( $_GET['ehgfb_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved and blacklist sources refreshed.', 'event-horizon-gf-blacklist' ) . '</p></div>';
        }

        if ( isset( $_GET['ehgfb_synced'] ) ) {
            $ok = '1' === sanitize_text_field( wp_unslash( $_GET['ehgfb_synced'] ) );
            $message = $ok
                ? __( 'Blacklist refresh completed.', 'event-horizon-gf-blacklist' )
                : __( 'Blacklist refresh completed with errors. Check your settings and source files.', 'event-horizon-gf-blacklist' );
            $class = $ok ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
        }

        if ( isset( $_GET['ehgfb_cleared'] ) ) {
            $which = sanitize_key( wp_unslash( $_GET['ehgfb_cleared'] ) );
            if ( in_array( $which, array( 'content', 'email', 'all' ), true ) ) {
                $label = ( 'content' === $which ) ? __( 'Content cache cleared.', 'event-horizon-gf-blacklist' )
                    : ( ( 'email' === $which ) ? __( 'Email cache cleared.', 'event-horizon-gf-blacklist' ) : __( 'Both caches cleared.', 'event-horizon-gf-blacklist' ) );
                echo '<div class="notice notice-success inline"><p>' . esc_html( $label ) . '</p></div>';
            }
        }

        if ( isset( $_GET['ehgfb_url_fixer_status'] ) ) {
            $status = sanitize_key( wp_unslash( $_GET['ehgfb_url_fixer_status'] ) );
            $fixed  = isset( $_GET['ehgfb_url_fixer_result'] ) ? sanitize_text_field( wp_unslash( $_GET['ehgfb_url_fixer_result'] ) ) : '';

            if ( 'invalid' === $status ) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The URL Fixer could not detect a valid Google Sheets spreadsheet URL.', 'event-horizon-gf-blacklist' ) . '</p></div>';
            } elseif ( 'content_set' === $status ) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'CSV export URL generated and saved as the content blacklist URL.', 'event-horizon-gf-blacklist' ) . '</p></div>';
            } elseif ( 'email_set' === $status ) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'CSV export URL generated and saved as the email blacklist URL.', 'event-horizon-gf-blacklist' ) . '</p></div>';
            } elseif ( 'converted' === $status && '' !== $fixed ) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'CSV export URL generated successfully.', 'event-horizon-gf-blacklist' ) . '</p></div>';
            }
        }
    }

    private function render_lists() : void {
        $status = $this->sync->get_status();
        $content = $this->sync->get_cached_list( 'content' );
        $email   = $this->sync->get_cached_list( 'email' );
        $content_source = $this->sync->get_source_label( 'content' );
        $email_source   = $this->sync->get_source_label( 'email' );

        $user_id = get_current_user_id();
        $acked = $user_id ? (bool) get_user_meta( $user_id, self::USERMETA_LISTS_ACK, true ) : false;

        // Handle acknowledgement toggle.
        if ( isset( $_POST['ehgfb_lists_ack_submit'] ) ) {
            check_admin_referer( 'ehgfb_lists_ack' );
            $new = ! empty( $_POST['ehgfb_lists_ack'] ) ? 1 : 0;
            if ( $user_id ) {
                update_user_meta( $user_id, self::USERMETA_LISTS_ACK, $new );
                $acked = (bool) $new;
            }
        }

        ?>
        <div class="ehgfb-grid">
            <div class="ehgfb-card">
                <h2><?php esc_html_e( 'View cached lists', 'event-horizon-gf-blacklist' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These are the rules currently cached in WordPress and used during Gravity Forms validation.', 'event-horizon-gf-blacklist' ); ?>
                </p>

                <form method="post" class="ehgfb-ack">
                    <?php wp_nonce_field( 'ehgfb_lists_ack' ); ?>
                    <label>
                        <input type="checkbox" name="ehgfb_lists_ack" value="1" <?php checked( $acked, true ); ?> />
                        <?php esc_html_e( 'I understand these lists may contain sensitive or offensive terms. Reveal list contents on this device.', 'event-horizon-gf-blacklist' ); ?>
                    </label>
                    <p>
                        <button type="submit" name="ehgfb_lists_ack_submit" class="button"><?php esc_html_e( 'Save', 'event-horizon-gf-blacklist' ); ?></button>
                    </p>
                </form>

                <div class="ehgfb-inline">
                    <div><strong><?php esc_html_e( 'Last refresh:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( $status['last_sync_human'] ?? __( 'Never', 'event-horizon-gf-blacklist' ) ); ?></div>
                    <div style="margin-left:16px;"><strong><?php esc_html_e( 'Rows cached:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( (int) count( $content ) . ' content, ' . (int) count( $email ) . ' email' ); ?></div>
                    <div style="margin-left:16px;"><strong><?php esc_html_e( 'Sources:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( $content_source . ' / ' . $email_source ); ?></div>
                </div>

                <hr class="ehgfb-hr" />

                <h3 style="margin-top:0;"><?php esc_html_e( 'Clear cached lists', 'event-horizon-gf-blacklist' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'This clears the cached rules stored in WordPress. It does not modify the configured Google Sheet or uploaded CSV file. After clearing, matches will stop until the next refresh.', 'event-horizon-gf-blacklist' ); ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ehgfb-inline" style="gap:8px; align-items:center;">
                    <?php wp_nonce_field( 'ehgfb_clear_lists' ); ?>
                    <input type="hidden" name="action" value="ehgfb_clear_lists" />
                    <button type="submit" class="button" name="ehgfb_clear_lists_type" value="content"><?php esc_html_e( 'Clear content cache', 'event-horizon-gf-blacklist' ); ?></button>
                    <button type="submit" class="button" name="ehgfb_clear_lists_type" value="email"><?php esc_html_e( 'Clear email cache', 'event-horizon-gf-blacklist' ); ?></button>
                    <button type="submit" class="button" name="ehgfb_clear_lists_type" value="all"><?php esc_html_e( 'Clear both', 'event-horizon-gf-blacklist' ); ?></button>
                </form>
            </div>

            <div class="ehgfb-card">
                <h2><?php esc_html_e( 'Content blacklist', 'event-horizon-gf-blacklist' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Rules are matched case-insensitively. Use regex:/pattern/i for regex rules.', 'event-horizon-gf-blacklist' ); ?></p>

                <?php if ( ! $acked ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'Reveal is disabled until you acknowledge the warning above.', 'event-horizon-gf-blacklist' ); ?></p></div>
                <?php else : ?>
                    <textarea class="large-text ehgfb-mono" rows="14" readonly><?php echo esc_textarea( implode( "\n", $content ) ); ?></textarea>
                <?php endif; ?>
            </div>

            <div class="ehgfb-card">
                <h2><?php esc_html_e( 'Email blacklist', 'event-horizon-gf-blacklist' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Rules can be full emails, domains (example.com), or regex:/pattern/i.', 'event-horizon-gf-blacklist' ); ?></p>

                <?php if ( ! $acked ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'Reveal is disabled until you acknowledge the warning above.', 'event-horizon-gf-blacklist' ); ?></p></div>
                <?php else : ?>
                    <textarea class="large-text ehgfb-mono" rows="14" readonly><?php echo esc_textarea( implode( "\n", $email ) ); ?></textarea>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_status_banner( array $status ) : void {
        $last_sync = ! empty( $status['last_sync_human'] ) ? $status['last_sync_human'] : __( 'Never', 'event-horizon-gf-blacklist' );
        $cron_enabled = ! empty( $status['cron_enabled'] );
        $next_sync = ( $cron_enabled && ! empty( $status['next_sync_human'] ) ) ? $status['next_sync_human'] : __( 'Not scheduled', 'event-horizon-gf-blacklist' );
        $content_count = (int) ( $status['content_count'] ?? 0 );
        $email_count   = (int) ( $status['email_count'] ?? 0 );
        $warnings      = $status['warnings'] ?? array();

        ?>
        <div class="ehgfb-status-card">
            <div class="ehgfb-status-row">
                <div><strong><?php esc_html_e( 'Last refresh:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( $last_sync ); ?></div>
                <div><strong><?php esc_html_e( 'Next sync:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( $next_sync ); ?></div>
                <div><strong><?php esc_html_e( 'Cached rows:', 'event-horizon-gf-blacklist' ); ?></strong> <?php echo esc_html( $content_count . ' content, ' . $email_count . ' email' ); ?></div>
                <div class="ehgfb-status-actions">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'ehgfb_manual_sync' ); ?>
                        <input type="hidden" name="action" value="ehgfb_manual_sync" />
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Refresh now', 'event-horizon-gf-blacklist' ); ?></button>
                    </form>
                </div>
            </div>

            <?php if ( ! $cron_enabled ) : ?>
                <p class="description" style="margin:10px 0 0;">
                    <?php esc_html_e( 'Scheduled sync is disabled because both blacklist sources are using uploaded CSV files.', 'event-horizon-gf-blacklist' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( ! empty( $warnings ) ) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php echo esc_html( implode( ' • ', array_map( 'strval', $warnings ) ) ); ?>
                </p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings() : void {
        $content_source = get_option( self::OPT_CONTENT_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
        $email_source   = get_option( self::OPT_EMAIL_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
        $content_url    = get_option( self::OPT_CONTENT_URL, '' );
        $email_url      = get_option( self::OPT_EMAIL_URL, '' );
        $content_file   = get_option( self::OPT_CONTENT_FILE, array() );
        $email_file     = get_option( self::OPT_EMAIL_FILE, array() );
        $behavior       = get_option( self::OPT_BEHAVIOR, 'no_entry' );
        $message        = get_option( self::OPT_BLOCK_MESSAGE, __( 'Your submission was blocked.', 'event-horizon-gf-blacklist' ) );
        $interval       = (int) get_option( self::OPT_SYNC_INTERVAL, 60 );
        $log_enabled    = (int) get_option( self::OPT_LOG_ENABLED, 1 );
        $retention      = (int) get_option( self::OPT_LOG_RETENTION, 30 );
        $content_header = (int) get_option( self::OPT_CONTENT_HEADER, 1 );
        $email_header   = (int) get_option( self::OPT_EMAIL_HEADER, 1 );

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ehgfb-form" enctype="multipart/form-data">
            <?php wp_nonce_field( 'ehgfb_save_settings' ); ?>
            <input type="hidden" name="action" value="ehgfb_save_settings" />

            <div class="ehgfb-grid">
                <div class="ehgfb-card">
                    <h2><?php esc_html_e( 'Blacklist sources', 'event-horizon-gf-blacklist' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Choose a Google Sheets CSV export or upload your own CSV file for each blacklist.', 'event-horizon-gf-blacklist' ); ?></p>

                    <div class="ehgfb-source-grid">
                        <div class="ehgfb-source-panel">
                            <label class="ehgfb-label" for="ehgfb_content_source"><?php esc_html_e( 'Content blacklist source', 'event-horizon-gf-blacklist' ); ?></label>
                            <select id="ehgfb_content_source" name="<?php echo esc_attr( self::OPT_CONTENT_SOURCE ); ?>" class="ehgfb-input ehgfb-source-select" data-target="content">
                                <option value="<?php echo esc_attr( EH_GFB_Sync::SOURCE_GOOGLE_SHEETS ); ?>" <?php selected( $content_source, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS ); ?>><?php esc_html_e( 'Google Sheet CSV URL', 'event-horizon-gf-blacklist' ); ?></option>
                                <option value="<?php echo esc_attr( EH_GFB_Sync::SOURCE_UPLOADED_CSV ); ?>" <?php selected( $content_source, EH_GFB_Sync::SOURCE_UPLOADED_CSV ); ?>><?php esc_html_e( 'Uploaded CSV file', 'event-horizon-gf-blacklist' ); ?></option>
                            </select>

                            <div class="ehgfb-source-fields" data-source-fields="content-google_sheets">
                                <label class="ehgfb-label" for="ehgfb_content_sheet_url"><?php esc_html_e( 'Content blacklist CSV URL', 'event-horizon-gf-blacklist' ); ?></label>
                                <input class="regular-text ehgfb-input" type="url" id="ehgfb_content_sheet_url" name="<?php echo esc_attr( self::OPT_CONTENT_URL ); ?>" value="<?php echo esc_attr( $content_url ); ?>" placeholder="https://docs.google.com/spreadsheets/d/.../export?format=csv&gid=..." />
                            </div>

                            <div class="ehgfb-source-fields" data-source-fields="content-uploaded_csv">
                                <label class="ehgfb-label" for="ehgfb_content_csv_file"><?php esc_html_e( 'Upload content CSV', 'event-horizon-gf-blacklist' ); ?></label>
                                <input class="ehgfb-input" type="file" id="ehgfb_content_csv_file" name="ehgfb_content_csv_upload" accept=".csv,text/csv" />
                                <?php if ( ! empty( $content_file['name'] ) ) : ?>
                                    <p class="description"><?php echo esc_html( sprintf( __( 'Current file: %s', 'event-horizon-gf-blacklist' ), (string) $content_file['name'] ) ); ?></p>
                                <?php endif; ?>
                                <label class="ehgfb-inline">
                                    <input type="checkbox" name="ehgfb_content_csv_remove" value="1" />
                                    <?php esc_html_e( 'Remove uploaded content CSV', 'event-horizon-gf-blacklist' ); ?>
                                </label>
                            </div>

                            <div class="ehgfb-inline">
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT_CONTENT_HEADER ); ?>" value="1" <?php checked( $content_header, 1 ); ?> />
                                    <?php esc_html_e( 'First row is a header (content list)', 'event-horizon-gf-blacklist' ); ?>
                                </label>
                            </div>
                        </div>

                        <div class="ehgfb-source-panel">
                            <label class="ehgfb-label" for="ehgfb_email_source"><?php esc_html_e( 'Email blacklist source', 'event-horizon-gf-blacklist' ); ?></label>
                            <select id="ehgfb_email_source" name="<?php echo esc_attr( self::OPT_EMAIL_SOURCE ); ?>" class="ehgfb-input ehgfb-source-select" data-target="email">
                                <option value="<?php echo esc_attr( EH_GFB_Sync::SOURCE_GOOGLE_SHEETS ); ?>" <?php selected( $email_source, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS ); ?>><?php esc_html_e( 'Google Sheet CSV URL', 'event-horizon-gf-blacklist' ); ?></option>
                                <option value="<?php echo esc_attr( EH_GFB_Sync::SOURCE_UPLOADED_CSV ); ?>" <?php selected( $email_source, EH_GFB_Sync::SOURCE_UPLOADED_CSV ); ?>><?php esc_html_e( 'Uploaded CSV file', 'event-horizon-gf-blacklist' ); ?></option>
                            </select>

                            <div class="ehgfb-source-fields" data-source-fields="email-google_sheets">
                                <label class="ehgfb-label" for="ehgfb_email_sheet_url"><?php esc_html_e( 'Email blacklist CSV URL', 'event-horizon-gf-blacklist' ); ?></label>
                                <input class="regular-text ehgfb-input" type="url" id="ehgfb_email_sheet_url" name="<?php echo esc_attr( self::OPT_EMAIL_URL ); ?>" value="<?php echo esc_attr( $email_url ); ?>" placeholder="https://docs.google.com/spreadsheets/d/.../export?format=csv&gid=..." />
                            </div>

                            <div class="ehgfb-source-fields" data-source-fields="email-uploaded_csv">
                                <label class="ehgfb-label" for="ehgfb_email_csv_file"><?php esc_html_e( 'Upload email CSV', 'event-horizon-gf-blacklist' ); ?></label>
                                <input class="ehgfb-input" type="file" id="ehgfb_email_csv_file" name="ehgfb_email_csv_upload" accept=".csv,text/csv" />
                                <?php if ( ! empty( $email_file['name'] ) ) : ?>
                                    <p class="description"><?php echo esc_html( sprintf( __( 'Current file: %s', 'event-horizon-gf-blacklist' ), (string) $email_file['name'] ) ); ?></p>
                                <?php endif; ?>
                                <label class="ehgfb-inline">
                                    <input type="checkbox" name="ehgfb_email_csv_remove" value="1" />
                                    <?php esc_html_e( 'Remove uploaded email CSV', 'event-horizon-gf-blacklist' ); ?>
                                </label>
                            </div>

                            <div class="ehgfb-inline">
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT_EMAIL_HEADER ); ?>" value="1" <?php checked( $email_header, 1 ); ?> />
                                    <?php esc_html_e( 'First row is a header (email list)', 'event-horizon-gf-blacklist' ); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ehgfb-card">
                    <h2><?php esc_html_e( 'Enforcement', 'event-horizon-gf-blacklist' ); ?></h2>

                    <label class="ehgfb-label" for="ehgfb_spam_behavior"><?php esc_html_e( 'On blacklist match', 'event-horizon-gf-blacklist' ); ?></label>
                    <select id="ehgfb_spam_behavior" name="<?php echo esc_attr( self::OPT_BEHAVIOR ); ?>" class="ehgfb-input">
                        <option value="no_entry" <?php selected( $behavior, 'no_entry' ); ?>><?php esc_html_e( 'Block submission (validation error)', 'event-horizon-gf-blacklist' ); ?></option>
                        <option value="spam" <?php selected( $behavior, 'spam' ); ?>><?php esc_html_e( 'Mark entry as spam', 'event-horizon-gf-blacklist' ); ?></option>
                    </select>

                    <label class="ehgfb-label" for="ehgfb_block_message"><?php esc_html_e( 'User-facing message', 'event-horizon-gf-blacklist' ); ?></label>
                    <textarea id="ehgfb_block_message" name="<?php echo esc_attr( self::OPT_BLOCK_MESSAGE ); ?>" class="large-text ehgfb-textarea" rows="3"><?php echo esc_textarea( $message ); ?></textarea>

                    <hr class="ehgfb-hr" />

                    <label class="ehgfb-label" for="ehgfb_sync_interval"><?php esc_html_e( 'Sync interval (minutes)', 'event-horizon-gf-blacklist' ); ?></label>
                    <input type="number" min="5" max="1440" step="5" id="ehgfb_sync_interval" name="<?php echo esc_attr( self::OPT_SYNC_INTERVAL ); ?>" value="<?php echo esc_attr( $interval ); ?>" class="small-text ehgfb-input" />
                    <p class="description"><?php esc_html_e( 'Uses WP-Cron when at least one blacklist source is a Google Sheet. Uploaded CSV sources are refreshed only when you save settings or click Refresh now.', 'event-horizon-gf-blacklist' ); ?></p>

                    <hr class="ehgfb-hr" />

                    <div class="ehgfb-inline">
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( self::OPT_LOG_ENABLED ); ?>" value="1" <?php checked( $log_enabled, 1 ); ?> />
                            <?php esc_html_e( 'Enable logging + metrics', 'event-horizon-gf-blacklist' ); ?>
                        </label>
                    </div>

                    <label class="ehgfb-label" for="ehgfb_log_retention_days"><?php esc_html_e( 'Log retention (days)', 'event-horizon-gf-blacklist' ); ?></label>
                    <input type="number" min="1" max="365" id="ehgfb_log_retention_days" name="<?php echo esc_attr( self::OPT_LOG_RETENTION ); ?>" value="<?php echo esc_attr( $retention ); ?>" class="small-text ehgfb-input" />
                    <p class="description"><?php esc_html_e( 'Logs store hashes only (no raw emails/content) for privacy.', 'event-horizon-gf-blacklist' ); ?></p>
                </div>
            </div>

            <?php submit_button( __( 'Save settings', 'event-horizon-gf-blacklist' ) ); ?>
        </form>
        <script type="text/javascript">
        (function() {
            function updateSourceFields(selectEl) {
                var target = selectEl.getAttribute('data-target');
                var mode = selectEl.value;
                var groups = document.querySelectorAll('[data-source-fields^="' + target + '-"]');
                for (var i = 0; i < groups.length; i++) {
                    var group = groups[i];
                    var shouldShow = group.getAttribute('data-source-fields') === target + '-' + mode;
                    group.hidden = !shouldShow;
                }
            }

            var selects = document.querySelectorAll('.ehgfb-source-select');
            for (var i = 0; i < selects.length; i++) {
                updateSourceFields(selects[i]);
                selects[i].addEventListener('change', function() {
                    updateSourceFields(this);
                });
            }
        })();
        </script>
        <?php
    }

    private function render_logs() : void {
        $enabled = (int) get_option( self::OPT_LOG_ENABLED, 1 );
        if ( ! $enabled ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Logging is currently disabled in Settings.', 'event-horizon-gf-blacklist' ) . '</p></div>';
            return;
        }

        $page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page = 25;

        $results = $this->logger->get_logs( $page, $per_page );
        $total   = (int) $results['total'];
        $rows    = $results['rows'];

        $total_pages = (int) ceil( max( 1, $total ) / $per_page );

        $metrics = $this->logger->get_metrics( 30 );

        ?>
        <div class="ehgfb-card">
            <h2><?php esc_html_e( 'Metrics (last 30 days)', 'event-horizon-gf-blacklist' ); ?></h2>
            <div class="ehgfb-metrics">
                <div class="ehgfb-metric"><span class="ehgfb-metric-num"><?php echo esc_html( (string) ( $metrics['matches'] ?? 0 ) ); ?></span><span class="ehgfb-metric-label"><?php esc_html_e( 'Matches', 'event-horizon-gf-blacklist' ); ?></span></div>
                <div class="ehgfb-metric"><span class="ehgfb-metric-num"><?php echo esc_html( (string) ( $metrics['sync_success'] ?? 0 ) ); ?></span><span class="ehgfb-metric-label"><?php esc_html_e( 'Successful refreshes', 'event-horizon-gf-blacklist' ); ?></span></div>
                <div class="ehgfb-metric"><span class="ehgfb-metric-num"><?php echo esc_html( (string) ( $metrics['sync_error'] ?? 0 ) ); ?></span><span class="ehgfb-metric-label"><?php esc_html_e( 'Refresh errors', 'event-horizon-gf-blacklist' ); ?></span></div>
            </div>
        </div>

        <div class="ehgfb-card">
            <div class="ehgfb-logs-header">
                <h2><?php esc_html_e( 'Recent logs', 'event-horizon-gf-blacklist' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ehgfb_clear_logs' ); ?>
                    <input type="hidden" name="action" value="ehgfb_clear_logs" />
                    <button type="submit" class="button"><?php esc_html_e( 'Clear logs', 'event-horizon-gf-blacklist' ); ?></button>
                </form>
            </div>

            <div class="ehgfb-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'List', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Form', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Field', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Rule', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Value hash', 'event-horizon-gf-blacklist' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'event-horizon-gf-blacklist' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="8"><?php esc_html_e( 'No logs yet.', 'event-horizon-gf-blacklist' ); ?></td></tr>
                        <?php else : foreach ( $rows as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r['created_at'] ); ?></td>
                                <td><?php echo esc_html( $r['type'] ); ?></td>
                                <td><?php echo esc_html( $r['list_type'] ); ?></td>
                                <td><?php echo esc_html( (string) $r['form_id'] ); ?></td>
                                <td><?php echo esc_html( (string) $r['field_id'] ); ?></td>
                                <td><code><?php echo esc_html( $r['rule'] ); ?></code></td>
                                <td><code><?php echo esc_html( $r['value_hash'] ); ?></code></td>
                                <td><?php echo esc_html( $r['message'] ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
                if ( $total_pages > 1 ) {
                    $base = remove_query_arg( array( 'paged' ) );
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links( array(
                        'base' => esc_url_raw( add_query_arg( 'paged', '%#%', $base ) ),
                        'format' => '',
                        'prev_text' => '«',
                        'next_text' => '»',
                        'total' => $total_pages,
                        'current' => $page,
                    ) );
                    echo '</div></div>';
                }
            ?>
        </div>
        <?php
    }

    private function render_tools() : void {
        $source_url = isset( $_GET['ehgfb_url_fixer_source'] ) ? sanitize_text_field( wp_unslash( $_GET['ehgfb_url_fixer_source'] ) ) : '';
        $fixed_url  = isset( $_GET['ehgfb_url_fixer_result'] ) ? sanitize_text_field( wp_unslash( $_GET['ehgfb_url_fixer_result'] ) ) : '';
        ?>
        <div class="ehgfb-card">
            <h2><?php esc_html_e( 'URL Fixer', 'event-horizon-gf-blacklist' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Paste a normal Google Sheets URL and Event Horizon will convert it into the CSV export URL used by this plugin.', 'event-horizon-gf-blacklist' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ehgfb-form">
                <?php wp_nonce_field( 'ehgfb_url_fixer' ); ?>
                <input type="hidden" name="action" value="ehgfb_url_fixer" />

                <label class="ehgfb-label" for="ehgfb_url_fixer_source"><?php esc_html_e( 'Google Sheets URL', 'event-horizon-gf-blacklist' ); ?></label>
                <textarea id="ehgfb_url_fixer_source" name="ehgfb_url_fixer_source" class="large-text ehgfb-textarea ehgfb-mono" rows="3" placeholder="https://docs.google.com/spreadsheets/d/.../edit?gid=0#gid=0"><?php echo esc_textarea( $source_url ); ?></textarea>

                <div class="ehgfb-tool-actions">
                    <button type="submit" class="button button-primary" name="ehgfb_url_fixer_action" value="convert"><?php esc_html_e( 'Convert URL', 'event-horizon-gf-blacklist' ); ?></button>
                    <?php if ( '' !== $fixed_url ) : ?>
                        <button type="submit" class="button" name="ehgfb_url_fixer_action" value="set_content"><?php esc_html_e( 'Set As Content Blacklist URL', 'event-horizon-gf-blacklist' ); ?></button>
                        <button type="submit" class="button" name="ehgfb_url_fixer_action" value="set_email"><?php esc_html_e( 'Set As Email Blacklist URL', 'event-horizon-gf-blacklist' ); ?></button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ( '' !== $fixed_url ) : ?>
                <hr class="ehgfb-hr" />
                <label class="ehgfb-label" for="ehgfb_url_fixer_result"><?php esc_html_e( 'CSV export URL', 'event-horizon-gf-blacklist' ); ?></label>
                <div class="ehgfb-tool-result">
                    <input id="ehgfb_url_fixer_result" type="text" class="regular-text ehgfb-input ehgfb-mono" value="<?php echo esc_attr( $fixed_url ); ?>" readonly />
                    <button type="button" class="button" data-copy-target="ehgfb_url_fixer_result"><?php esc_html_e( 'Copy URL', 'event-horizon-gf-blacklist' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'You can copy this URL directly or use one of the buttons above to save it into the plugin settings.', 'event-horizon-gf-blacklist' ); ?></p>
                <script type="text/javascript">
                (function() {
                    var button = document.querySelector('[data-copy-target="ehgfb_url_fixer_result"]');
                    if (!button) { return; }
                    button.addEventListener('click', function() {
                        var target = document.getElementById(button.getAttribute('data-copy-target'));
                        if (!target) { return; }
                        target.select();
                        target.setSelectionRange(0, target.value.length);
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(target.value);
                        } else {
                            document.execCommand('copy');
                        }
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_help() : void {
        ?>
        <div class="ehgfb-card">
            <h2><?php esc_html_e( 'Import methods', 'event-horizon-gf-blacklist' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Each blacklist can use either a Google Sheets CSV export or an uploaded CSV file.', 'event-horizon-gf-blacklist' ); ?></p>
            <h3><?php esc_html_e( 'Google Sheets CSV link', 'event-horizon-gf-blacklist' ); ?></h3>
            <ol class="ehgfb-ol">
                <li><?php esc_html_e( 'Open your Google Sheet and click File → Share → Publish to web.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Choose the specific sheet/tab (not the entire document).', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Select “Comma-separated values (.csv)” as the format, then click Publish.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Copy the published URL and paste it into the appropriate setting on the Settings tab.', 'event-horizon-gf-blacklist' ); ?></li>
            </ol>
            <p class="description">
                <?php esc_html_e( 'Tip: If you prefer not to “Publish to web”, you can also use an export link in the format:', 'event-horizon-gf-blacklist' ); ?>
            </p>
            <p><code>https://docs.google.com/spreadsheets/d/&lt;SHEET_ID&gt;/export?format=csv&amp;gid=&lt;TAB_GID&gt;</code></p>

            <h3><?php esc_html_e( 'Uploaded CSV file', 'event-horizon-gf-blacklist' ); ?></h3>
            <ol class="ehgfb-ol">
                <li><?php esc_html_e( 'On the Settings tab, change the blacklist source to Uploaded CSV file.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Choose a .csv file from your computer and save settings.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Use Refresh now after replacing a local CSV file if you want the cache updated immediately.', 'event-horizon-gf-blacklist' ); ?></li>
            </ol>
            <p class="description"><?php esc_html_e( 'When both blacklist sources use uploaded CSV files, scheduled remote sync is disabled.', 'event-horizon-gf-blacklist' ); ?></p>

            <hr class="ehgfb-hr" />

            <h2><?php esc_html_e( 'Blacklist format', 'event-horizon-gf-blacklist' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Put one rule per row in the first column. Extra columns are ignored.', 'event-horizon-gf-blacklist' ); ?></p>

            <h3><?php esc_html_e( 'Content rules', 'event-horizon-gf-blacklist' ); ?></h3>
            <ul class="ehgfb-ul">
                <li><code>casino</code> — <?php esc_html_e( 'Matches as a whole word (case-insensitive).', 'event-horizon-gf-blacklist' ); ?></li>
                <li><code>buy now</code> — <?php esc_html_e( 'Matches as a substring (case-insensitive).', 'event-horizon-gf-blacklist' ); ?></li>
                <li><code>regex:/\bfree\s+money\b/i</code> — <?php esc_html_e( 'Regex match. Use PHP regex delimiters.', 'event-horizon-gf-blacklist' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Email rules', 'event-horizon-gf-blacklist' ); ?></h3>
            <ul class="ehgfb-ul">
                <li><code>badguy@example.com</code> — <?php esc_html_e( 'Exact email match (case-insensitive).', 'event-horizon-gf-blacklist' ); ?></li>
                <li><code>*@spammail.com</code> — <?php esc_html_e( 'Block an entire domain.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><code>regex:/@temp-mail\./i</code> — <?php esc_html_e( 'Regex match (useful for disposable email patterns).', 'event-horizon-gf-blacklist' ); ?></li>


            <hr class="ehgfb-hr" />

            <h2><?php esc_html_e( 'Enable blacklists on form fields', 'event-horizon-gf-blacklist' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Event Horizon checks only the fields where you enable blacklist protection.', 'event-horizon-gf-blacklist' ); ?></p>
            <ol class="ehgfb-ol">
                <li><?php esc_html_e( 'Go to Forms → Edit Form.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Click the field you want to protect (for example: Email, Name, Message).', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'In the field settings, open the Advanced tab.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Enable Content Blacklist for text-based fields and/or Email Blacklist for email fields.', 'event-horizon-gf-blacklist' ); ?></li>
                <li><?php esc_html_e( 'Save the form.', 'event-horizon-gf-blacklist' ); ?></li>
            </ol>
            <p class="description"><?php esc_html_e( 'If these options are not enabled on a field, that field will not be scanned.', 'event-horizon-gf-blacklist' ); ?></p>
            </ul>
        </div>
        <?php
    }

    public function handle_save_settings() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Forbidden' ); }
        check_admin_referer( 'ehgfb_save_settings' );

        $old_interval = (int) get_option( self::OPT_SYNC_INTERVAL, 60 );

        update_option( self::OPT_CONTENT_SOURCE, $this->sanitize_source( $_POST[ self::OPT_CONTENT_SOURCE ] ?? '' ) );
        update_option( self::OPT_EMAIL_SOURCE, $this->sanitize_source( $_POST[ self::OPT_EMAIL_SOURCE ] ?? '' ) );
        update_option( self::OPT_CONTENT_URL, $this->sanitize_url( $_POST[ self::OPT_CONTENT_URL ] ?? '' ) );
        update_option( self::OPT_EMAIL_URL, $this->sanitize_url( $_POST[ self::OPT_EMAIL_URL ] ?? '' ) );
        update_option( self::OPT_BEHAVIOR, $this->sanitize_behavior( $_POST[ self::OPT_BEHAVIOR ] ?? '' ) );
        update_option( self::OPT_BLOCK_MESSAGE, $this->sanitize_message( $_POST[ self::OPT_BLOCK_MESSAGE ] ?? '' ) );

        $new_interval = $this->sanitize_interval( $_POST[ self::OPT_SYNC_INTERVAL ] ?? 60 );
        update_option( self::OPT_SYNC_INTERVAL, $new_interval );
        update_option( self::OPT_LOG_ENABLED, $this->sanitize_bool( $_POST[ self::OPT_LOG_ENABLED ] ?? 0 ) );
        update_option( self::OPT_LOG_RETENTION, $this->sanitize_retention( $_POST[ self::OPT_LOG_RETENTION ] ?? 30 ) );
        update_option( self::OPT_CONTENT_HEADER, $this->sanitize_bool( $_POST[ self::OPT_CONTENT_HEADER ] ?? 0 ) );
        update_option( self::OPT_EMAIL_HEADER, $this->sanitize_bool( $_POST[ self::OPT_EMAIL_HEADER ] ?? 0 ) );

        $this->handle_uploaded_csv( 'content', 'ehgfb_content_csv_upload', ! empty( $_POST['ehgfb_content_csv_remove'] ) );
        $this->handle_uploaded_csv( 'email', 'ehgfb_email_csv_upload', ! empty( $_POST['ehgfb_email_csv_remove'] ) );

        $this->sync->ensure_cron_scheduled( $old_interval !== $new_interval );
        $this->sync->sync_now( true );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&ehgfb_saved=1' ) );
        exit;
    }

    public function handle_manual_sync() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Forbidden' ); }
        check_admin_referer( 'ehgfb_manual_sync' );

        $result = $this->sync->sync_now( true );

        $url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::MENU_SLUG );
        $url = add_query_arg( array( 'ehgfb_synced' => $result ? '1' : '0' ), $url );

        wp_safe_redirect( $url );
        exit;
    }

    public function handle_clear_lists() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Forbidden' ); }
        check_admin_referer( 'ehgfb_clear_lists' );

        $which = isset( $_POST['ehgfb_clear_lists_type'] ) ? sanitize_key( wp_unslash( $_POST['ehgfb_clear_lists_type'] ) ) : 'all';
        if ( ! in_array( $which, array( 'content', 'email', 'all' ), true ) ) {
            $which = 'all';
        }

        if ( $which === 'content' ) {
            $this->sync->clear_cached_list( 'content' );
        } elseif ( $which === 'email' ) {
            $this->sync->clear_cached_list( 'email' );
        } else {
            $this->sync->clear_cached_list( 'content' );
            $this->sync->clear_cached_list( 'email' );
        }

        $url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::MENU_SLUG . '-lists' );
        $url = add_query_arg( array( 'ehgfb_cleared' => $which ), $url );
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_clear_logs() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Forbidden' ); }
        check_admin_referer( 'ehgfb_clear_logs' );
        $this->logger->clear_logs();
        $url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::MENU_SLUG . '-logs' );
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_url_fixer() : void {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Forbidden' ); }
        check_admin_referer( 'ehgfb_url_fixer' );

        $source_url = isset( $_POST['ehgfb_url_fixer_source'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ehgfb_url_fixer_source'] ) ) ) : '';
        $action     = isset( $_POST['ehgfb_url_fixer_action'] ) ? sanitize_key( wp_unslash( $_POST['ehgfb_url_fixer_action'] ) ) : 'convert';
        $fixed_url  = $this->convert_google_sheet_url_to_csv_export( $source_url );

        $redirect = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-tools' );
        $redirect = add_query_arg( 'ehgfb_url_fixer_source', $source_url, $redirect );

        if ( '' === $fixed_url ) {
            $redirect = add_query_arg( 'ehgfb_url_fixer_status', 'invalid', $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( 'set_content' === $action ) {
            update_option( self::OPT_CONTENT_URL, $fixed_url );
            update_option( self::OPT_CONTENT_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
            $this->sync->ensure_cron_scheduled( true );
            $redirect = add_query_arg( 'ehgfb_url_fixer_status', 'content_set', $redirect );
        } elseif ( 'set_email' === $action ) {
            update_option( self::OPT_EMAIL_URL, $fixed_url );
            update_option( self::OPT_EMAIL_SOURCE, EH_GFB_Sync::SOURCE_GOOGLE_SHEETS );
            $this->sync->ensure_cron_scheduled( true );
            $redirect = add_query_arg( 'ehgfb_url_fixer_status', 'email_set', $redirect );
        } else {
            $redirect = add_query_arg( 'ehgfb_url_fixer_status', 'converted', $redirect );
        }

        $redirect = add_query_arg( 'ehgfb_url_fixer_result', $fixed_url, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * When the sync interval changes, reschedule WP-Cron.
     */
    public function handle_interval_change( $old_value, $value ) : void {
        $old = (int) $old_value;
        $new = (int) $value;
        if ( $old === $new ) { return; }
        $this->sync->ensure_cron_scheduled( true );
    }

    // Sanitizers
    public function sanitize_url( $value ) : string {
        $value = trim( (string) $value );
        if ( $value === '' ) { return ''; }
        return esc_url_raw( $value );
    }

    public function sanitize_source( $value ) : string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( EH_GFB_Sync::SOURCE_GOOGLE_SHEETS, EH_GFB_Sync::SOURCE_UPLOADED_CSV ), true )
            ? $value
            : EH_GFB_Sync::SOURCE_GOOGLE_SHEETS;
    }

    public function sanitize_behavior( $value ) : string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'no_entry', 'spam' ), true ) ? $value : 'no_entry';
    }

    public function sanitize_message( $value ) : string {
        $value = (string) $value;
        $value = wp_strip_all_tags( $value );
        return substr( $value, 0, 500 );
    }

    public function sanitize_interval( $value ) : int {
        $v = (int) $value;
        if ( $v < 5 ) { $v = 5; }
        if ( $v > 1440 ) { $v = 1440; }
        return $v;
    }

    public function sanitize_bool( $value ) : int {
        return empty( $value ) ? 0 : 1;
    }

    public function sanitize_retention( $value ) : int {
        $v = (int) $value;
        if ( $v < 1 ) { $v = 1; }
        if ( $v > 365 ) { $v = 365; }
        return $v;
    }

    private function convert_google_sheet_url_to_csv_export( string $url ) : string {
        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return '';
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path   = (string) ( $parts['path'] ?? '' );
        $query  = (string) ( $parts['query'] ?? '' );
        $fragment = (string) ( $parts['fragment'] ?? '' );

        if ( '' === $scheme || '' === $host || 'docs.google.com' !== $host ) {
            return '';
        }

        if ( ! preg_match( '#^/spreadsheets/d/([^/]+)(?:/.*)?$#', $path, $matches ) ) {
            return '';
        }

        $sheet_id = $matches[1];
        if ( '' === $sheet_id ) {
            return '';
        }

        parse_str( $query, $query_params );
        parse_str( $fragment, $fragment_params );

        $gid = '';
        if ( isset( $query_params['gid'] ) ) {
            $gid = (string) $query_params['gid'];
        } elseif ( isset( $fragment_params['gid'] ) ) {
            $gid = (string) $fragment_params['gid'];
        }

        if ( '' === $gid ) {
            $gid = '0';
        }

        $fixed = $scheme . '://docs.google.com/spreadsheets/d/' . rawurlencode( $sheet_id ) . '/export?format=csv&gid=' . rawurlencode( $gid );

        if ( '' !== $fragment ) {
            $fixed .= '#' . $fragment;
        }

        return $fixed;
    }

    private function handle_uploaded_csv( string $type, string $file_input, bool $remove_existing ) : void {
        $option = ( 'email' === $type ) ? self::OPT_EMAIL_FILE : self::OPT_CONTENT_FILE;
        $existing = get_option( $option, array() );

        if ( $remove_existing && ! empty( $existing['path'] ) ) {
            $path = (string) $existing['path'];
            if ( file_exists( $path ) && is_writable( $path ) ) {
                wp_delete_file( $path );
            }
            delete_option( $option );
            $existing = array();
        }

        if ( empty( $_FILES[ $file_input ] ) || empty( $_FILES[ $file_input ]['name'] ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = $_FILES[ $file_input ];
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        if ( empty( $check['ext'] ) || 'csv' !== strtolower( (string) $check['ext'] ) ) {
            return;
        }

        $uploaded = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => array(
                    'csv' => 'text/csv',
                ),
            )
        );

        if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
            return;
        }

        if ( ! empty( $existing['path'] ) ) {
            $old_path = (string) $existing['path'];
            if ( file_exists( $old_path ) && is_writable( $old_path ) ) {
                wp_delete_file( $old_path );
            }
        }

        update_option(
            $option,
            array(
                'path' => (string) $uploaded['file'],
                'url'  => (string) ( $uploaded['url'] ?? '' ),
                'name' => sanitize_file_name( (string) $file['name'] ),
            ),
            false
        );
    }
}
