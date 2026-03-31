<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EH_GFB_Plugin {

    private static $instance = null;

    /** @var EH_GFB_Admin */
    public $admin;

    /** @var EH_GFB_Sync */
    public $sync;

    /** @var EH_GFB_Logger */
    public $logger;

    /** @var EH_GFB_Matcher */
    public $matcher;

    /**
     * Tracks blacklist hits during the current validation cycle.
     * Keyed by form ID; value is an array of hit arrays.
     *
     * @var array<int, array<int, array<string,mixed>>>
     */
    private $validation_hits = array();

    /**
     * Tracks forms that should be marked as spam instead of blocked.
     *
     * @var array<int, bool>
     */
    private $spam_form_ids = array();

    /**
     * Request-local cache of blacklist rules keyed by list type.
     *
     * @var array<string, array<int, string>>
     */
    private $request_lists = array();

    public static function instance() : self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger  = new EH_GFB_Logger();
        $this->logger->maybe_upgrade();
        $this->sync    = new EH_GFB_Sync( $this->logger );
        $this->matcher = new EH_GFB_Matcher();
        $this->admin   = new EH_GFB_Admin( $this->sync, $this->logger );

        $this->hooks();
    }

    private function hooks() : void {
        // Gravity Forms integration.
        // NOTE: GF requires BOTH (1) outputting the markup and (2) registering which field types
        // should show the custom setting via the fieldSettings map in the editor.
        // In GF 2.9+, Advanced settings are the most consistent location.
        add_action( 'gform_field_advanced_settings', array( $this, 'add_field_settings' ), 10, 2 );
        add_action( 'gform_editor_js', array( $this, 'editor_script' ) );
        add_action( 'gform_enqueue_scripts', array( $this, 'enqueue_admin_editor_assets' ), 10, 2 );

        // Keep cache fresh opportunistically (non-blocking) and validate submissions.
        add_filter( 'gform_pre_validation', array( $this, 'pre_validation_refresh' ) );

        // Field validation (works for multi-input fields like Name).
        add_filter( 'gform_field_validation', array( $this, 'field_validation' ), 10, 4 );

        // Overall validation result logging (pass/fail).
        add_filter( 'gform_validation', array( $this, 'validation_result' ), 10 );
        add_filter( 'gform_entry_is_spam', array( $this, 'entry_is_spam' ), 10, 3 );

        // Cron.
        add_action( EH_GFB_Sync::CRON_HOOK, array( $this->sync, 'run_scheduled_sync' ) );
        add_action( EH_GFB_Sync::CRON_REFRESH_HOOK, array( $this->sync, 'run_scheduled_sync' ) );
        add_action( EH_GFB_Sync::CRON_PURGE_HOOK, array( $this->logger, 'purge_old_logs' ) );
    }

    public static function activate() : void {
        // Create/upgrade log table.
        ( new EH_GFB_Logger() )->maybe_upgrade();

        // Ensure schedules exist and events are registered.
        $sync = new EH_GFB_Sync( new EH_GFB_Logger() );
        $sync->ensure_cron_scheduled( true );
        ( new EH_GFB_Logger() )->ensure_purge_scheduled();
    }

    public static function deactivate() : void {
        wp_clear_scheduled_hook( EH_GFB_Sync::CRON_HOOK );
        wp_clear_scheduled_hook( EH_GFB_Sync::CRON_REFRESH_HOOK );
        wp_clear_scheduled_hook( EH_GFB_Sync::CRON_PURGE_HOOK );
    }

    /**
     * Add per-field toggles in the GF editor.
     */
    public function add_field_settings( $position, $form_id ) : void {
        // Render near the bottom of the Advanced panel.
        if ( 50 !== (int) $position ) { return; }
        ?>
        <li class="ehgfb_setting field_setting">
            <input type="checkbox" id="field_ehgfb_content" onclick="SetFieldProperty('ehgfb_content', this.checked);" />
            <label for="field_ehgfb_content" class="inline">
                <?php esc_html_e( 'Enable Content Blacklist', 'event-horizon-gf-blacklist' ); ?>
            </label>
        </li>

        <li class="ehgfb_setting field_setting">
            <input type="checkbox" id="field_ehgfb_email" onclick="SetFieldProperty('ehgfb_email', this.checked);" />
            <label for="field_ehgfb_email" class="inline">
                <?php esc_html_e( 'Enable Email Blacklist', 'event-horizon-gf-blacklist' ); ?>
            </label>
        </li>
        <?php
    }

    /**
     * Ensures the checkboxes reflect saved field properties.
     */
    public function editor_script() : void {
        ?>
        <script type="text/javascript">
        (function($){
            // Ensure our setting rows are actually shown for relevant field types.
            // Gravity Forms hides unknown field_setting rows unless explicitly whitelisted.
            if (typeof window.fieldSettings !== 'undefined') {
                var supported = [
                    'text','textarea','email','number','phone','website','hidden',
                    'name','address','post_title','post_content','post_custom_field',
                    'post_excerpt','post_tags','post_category','product','quantity','option',
                    'shipping','total','date','time','fileupload','consent'
                ];
                for (var i = 0; i < supported.length; i++) {
                    var key = supported[i];
                    if (window.fieldSettings[key] && window.fieldSettings[key].indexOf('.ehgfb_setting') === -1) {
                        window.fieldSettings[key] += ', .ehgfb_setting';
                    }
                }
                // Also show on any custom field types that have a fieldSettings entry.
                for (var k in window.fieldSettings) {
                    if (!window.fieldSettings.hasOwnProperty(k)) { continue; }
                    if (window.fieldSettings[k] && window.fieldSettings[k].indexOf('.ehgfb_setting') === -1) {
                        // Don't add to non-input / layout fields.
                        if (k === 'section' || k === 'html' || k === 'page' || k === 'captcha') { continue; }
                        window.fieldSettings[k] += ', .ehgfb_setting';
                    }
                }
            }

            $(document).on('gform_load_field_settings', function(event, field, form){
                $('#field_ehgfb_content').prop('checked', !!field.ehgfb_content);
                $('#field_ehgfb_email').prop('checked', !!field.ehgfb_email);
            });
        })(jQuery);
        </script>
        <?php
    }

    public function enqueue_admin_editor_assets( $form, $is_ajax ) : void {
        if ( ! is_admin() ) { return; }
        // Currently no external assets required for editor beyond inline JS above.
    }

    /**
     * Opportunistic cache refresh before validation begins.
     * (This does not validate; actual validation happens in gform_field_validation.)
     */
    public function pre_validation_refresh( $form ) {
        if ( class_exists( 'GFCommon' ) ) {
            $this->sync->maybe_background_refresh();
        }

        $this->request_lists = array(
            'content' => $this->sync->get_cached_list( 'content' ),
            'email'   => $this->sync->get_cached_list( 'email' ),
        );

        // Reset hit tracking for this validation cycle.
        $form_id = (int) rgar( $form, 'id' );
        if ( $form_id ) {
            $this->validation_hits[ $form_id ] = array();
            unset( $this->spam_form_ids[ $form_id ] );
        }

        return $form;
    }

    /**
     * Field-level validation hook.
     *
     * This is the correct place to block submissions in GF 2.9+,
     * and it provides full values for multi-input fields (e.g. Name).
     */
    public function field_validation( $result, $value, $form, $field ) {
        // If the field already failed for another reason, don't override.
        if ( isset( $result['is_valid'] ) && ! $result['is_valid'] ) {
            return $result;
        }

        $form_id  = (int) rgar( $form, 'id' );
        $field_id = isset( $field->id ) ? (int) $field->id : 0;

        // Only act on fields where our toggles are enabled.
        $check_content = ! empty( $field->ehgfb_content );
        $check_email   = ! empty( $field->ehgfb_email );
        if ( ! $check_content && ! $check_email ) {
            return $result;
        }

        // Normalize value (string or array) into a single string for matching.
        $value_str = '';
        if ( is_array( $value ) ) {
            $flat = array();
            foreach ( $value as $v ) {
                if ( $v === null ) { continue; }
                $v = trim( (string) $v );
                if ( $v !== '' ) { $flat[] = $v; }
            }
            $value_str = implode( ' ', $flat );
        } else {
            $value_str = trim( (string) $value );
        }

        if ( $value_str === '' ) {
            return $result;
        }

        $content_list = $this->get_request_list( 'content' );
        $email_list   = $this->get_request_list( 'email' );

        $behavior = get_option( EH_GFB_Admin::OPT_BEHAVIOR, 'no_entry' );
        $message  = get_option( EH_GFB_Admin::OPT_BLOCK_MESSAGE, __( 'Your submission was blocked.', 'event-horizon-gf-blacklist' ) );

        $hit = null;
        if ( $check_content ) {
            $hit = $this->matcher->match_content( $value_str, $content_list );
            if ( $hit ) {
                $this->record_hit( $form_id, $field_id, 'content', $hit['rule'], $hit['value_hash'], $field );
            }
        }

        if ( ! $hit && $check_email ) {
            $hit = $this->matcher->match_email( $value_str, $email_list );
            if ( $hit ) {
                $this->record_hit( $form_id, $field_id, 'email', $hit['rule'], $hit['value_hash'], $field );
            }
        }

        if ( $hit ) {
            if ( $behavior === 'spam' ) {
                $this->spam_form_ids[ $form_id ] = true;
            } else {
                // Fail the field.
                $result['is_valid'] = false;
                $result['message']  = $message;
            }
        }

        return $result;
    }

    public function entry_is_spam( $is_spam, $form, $entry ) {
        if ( $is_spam ) {
            return $is_spam;
        }

        $form_id = (int) rgar( $form, 'id' );
        if ( ! $form_id ) {
            return $is_spam;
        }

        return ! empty( $this->spam_form_ids[ $form_id ] );
    }

    /**
     * Logs overall validation pass/fail for a form submission.
     */
    public function validation_result( $validation_result ) {
        $form    = rgar( $validation_result, 'form' );
        $form_id = (int) rgar( $form, 'id' );
        if ( ! $form_id ) {
            return $validation_result;
        }

        $is_valid = (bool) rgar( $validation_result, 'is_valid' );
        $hits     = $this->validation_hits[ $form_id ] ?? array();
        $is_spam  = ! empty( $this->spam_form_ids[ $form_id ] );

        if ( $is_spam ) {
            $count = count( $hits );
            $msg   = $count > 0
                ? sprintf( 'Form flagged as spam (%d blacklist hit(s)).', $count )
                : 'Form flagged as spam.';

            $this->logger->log_event( 'validation_spam', 'system', $form_id, 0, '', '', $msg );
            $this->log_recent_hits( $form_id, $hits );
            return $validation_result;
        }

        if ( empty( $hits ) ) {
            return $validation_result;
        }

        // Only log validation failures caused by blacklist hits so unrelated form traffic does not fill the log table.
        $count = count( $hits );
        $msg = $count > 0
            ? sprintf( 'Form failed validation (%d blacklist hit(s)).', $count )
            : 'Form failed validation.';

        $this->logger->log_event( 'validation_fail', 'system', $form_id, 0, '', '', $msg );
        $this->log_recent_hits( $form_id, $hits );

        return $validation_result;
    }

    private function log_recent_hits( int $form_id, array $hits ) : void {
        // Log up to 3 most recent hits in a human friendly way (rule + field label) without storing raw user input.
        $max_detail = 3;
        $recent     = array_slice( array_reverse( $hits ), 0, $max_detail );
        foreach ( $recent as $h ) {
            $detail = sprintf(
                'Hit on field "%s" (field_id=%d) using %s rule: %s',
                (string) ( $h['field_label'] ?? 'Field' ),
                (int) ( $h['field_id'] ?? 0 ),
                (string) ( $h['list_type'] ?? 'list' ),
                (string) ( $h['rule'] ?? '' )
            );
            $this->logger->log_event( 'validation_hit', (string) ( $h['list_type'] ?? '' ), $form_id, (int) ( $h['field_id'] ?? 0 ), (string) ( $h['rule'] ?? '' ), (string) ( $h['value_hash'] ?? '' ), $detail );
        }
    }

    private function record_hit( int $form_id, int $field_id, string $list_type, string $rule, string $value_hash, $field ) : void {
        if ( ! isset( $this->validation_hits[ $form_id ] ) || ! is_array( $this->validation_hits[ $form_id ] ) ) {
            $this->validation_hits[ $form_id ] = array();
        }

        $label = '';
        if ( is_object( $field ) ) {
            $label = trim( (string) ( $field->label ?? '' ) );
        }

        $this->validation_hits[ $form_id ][] = array(
            'form_id' => $form_id,
            'field_id' => $field_id,
            'field_label' => $label,
            'list_type' => $list_type,
            'rule' => $rule,
            'value_hash' => $value_hash,
        );

        // Existing per-hit match log.
        $this->logger->log_match( $list_type, $form_id, $field_id, $rule, $value_hash );
    }

    private function get_request_list( string $type ) : array {
        $type = ( 'email' === $type ) ? 'email' : 'content';

        if ( ! array_key_exists( $type, $this->request_lists ) ) {
            $this->request_lists[ $type ] = $this->sync->get_cached_list( $type );
        }

        return is_array( $this->request_lists[ $type ] ) ? $this->request_lists[ $type ] : array();
    }
}
