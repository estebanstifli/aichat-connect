<?php
/**
 * Plugin Name: Andromeda Connect
 * Description: Connect WhatsApp (Meta Cloud API) and Telegram webhooks to AI chat providers for automated replies with logging and mapping controls.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: estebandezafra
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: andromeda-connect
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

// Constants
define('AICHAT_CONNECT_VERSION', '0.1.0');
define('AICHAT_CONNECT_DIR', plugin_dir_path(__FILE__));
define('AICHAT_CONNECT_URL', plugin_dir_url(__FILE__));
// Enable temporary debug by defining in wp-config.php or uncomment next line.
if (!defined('AICHAT_CONNECT_DEBUG')) {
    define('AICHAT_CONNECT_DEBUG', true); // Set to false in production.
}

// Debug helper similar to core plugin but scoped to CONNECT addon
if ( ! function_exists( 'aichat_connect_log_debug' ) ) {
    function aichat_connect_log_debug( $message, array $context = [] ) {
        if ( ! ( defined( 'AICHAT_CONNECT_DEBUG' ) && AICHAT_CONNECT_DEBUG ) ) { return; }
        if ( ! empty( $context ) ) {
            $safe = [];
            foreach ( $context as $k => $v ) {
                if ( is_scalar( $v ) || $v === null ) { $safe[ $k ] = $v; }
                elseif ( $v instanceof WP_Error ) { $safe[ $k ] = 'WP_Error: ' . $v->get_error_code() . ' - ' . $v->get_error_message(); }
                else { $safe[ $k ] = is_object( $v ) ? get_class( $v ) : gettype( $v ); }
            }
            $json = wp_json_encode( $safe );
            if ( $json ) { $message .= ' | ' . $json; }
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging guarded by constant.
        error_log( '[andromeda-connect] ' . $message );
    }
}

// Includes
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-activator.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-api-client.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-service.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-provider-aipkit.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-webhook.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-channel-telegram.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-webhook-telegram.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin-providers.php';

// Activation
register_activation_hook(__FILE__, ['AIChat_Connect_Activator','activate']);
// Schema upgrade on admin_init
add_action('admin_init', ['AIChat_Connect_Activator','maybe_update_schema']);

// Bootstrap
add_action('plugins_loaded', function(){
    // Provider availability checks will be handled in the Providers UI later.
    // No admin notice on load to keep first-publish experience clean.
    AIChat_Connect_Webhook::instance();
    AIChat_Connect_Webhook_Telegram::instance();
    AIChat_Connect_Admin::instance();
    AIChat_Connect_Admin_Providers::instance();
});

// Simple helper for outbound manual sending (future extension)
function aichat_connect_send_message($phone, $text, $bot_slug = null){
    $svc = AIChat_Connect_Service::instance();
    return $svc->send_outbound_text($phone, $text, $bot_slug);
}


