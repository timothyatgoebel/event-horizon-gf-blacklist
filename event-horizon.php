<?php
/*
Plugin Name: Event Horizon - Gravity Forms Blacklist
Description: Imports content and email blacklists from Google Sheets or uploaded CSV files and applies them to Gravity Forms fields.
Version: 1.1.0
Author: Goebel Media
License: GPLv2 or later
Text Domain: event-horizon-gf-blacklist
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EH_GFB_VERSION', '1.1.0' );
define( 'EH_GFB_PLUGIN_FILE', __FILE__ );
define( 'EH_GFB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EH_GFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EH_GFB_PLUGIN_DIR . 'includes/class-ehgfb-plugin.php';
require_once EH_GFB_PLUGIN_DIR . 'includes/class-ehgfb-admin.php';
require_once EH_GFB_PLUGIN_DIR . 'includes/class-ehgfb-sync.php';
require_once EH_GFB_PLUGIN_DIR . 'includes/class-ehgfb-matcher.php';
require_once EH_GFB_PLUGIN_DIR . 'includes/class-ehgfb-logger.php';

register_activation_hook( __FILE__, array( 'EH_GFB_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EH_GFB_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    EH_GFB_Plugin::instance();
} );
