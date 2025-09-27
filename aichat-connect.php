<?php
/**
 * Plugin Name: AI Chat Connect
 * Description: WhatsApp (Meta Cloud API) integration for the AI Chat plugin. Routes incoming messages to existing bots and replies automatically.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: estebandezafra
 * Text Domain: aichat-connect
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
        error_log( '[AIChat-CONNECT] ' . $message );
    }
}

// Includes
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-activator.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-api-client.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-repository.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-service.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-webhook.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin-providers.php';

// Activation
register_activation_hook(__FILE__, ['AIChat_Connect_Activator','activate']);
// Schema upgrade on admin_init
add_action('admin_init', ['AIChat_Connect_Activator','maybe_update_schema']);

// Bootstrap
add_action('plugins_loaded', function(){
    // Load translations
    load_plugin_textdomain('aichat-connect', false, dirname(plugin_basename(__FILE__)).'/languages');
    $core_active = function_exists('aichat_generate_bot_response');
    if (!$core_active) {
        // Mostrar aviso pero igualmente habilitar webhook para usar AI Engine u otros servicios.
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p>'.esc_html__('AI Chat Connect: the core AI Chat plugin is not active. Mappings using service "AI Chat" will fail, but "AI Engine" mappings will still attempt to respond.','aichat-connect').'</p></div>';
        });
    }
    // Siempre cargamos webhook y admin para permitir uso con AI Engine / futuros proveedores.
    AIChat_Connect_Webhook::instance();
    AIChat_Connect_Admin::instance();
    AIChat_Connect_Admin_Providers::instance();
});

// Simple helper for outbound manual sending (future extension)
function aichat_connect_send_message($phone, $text, $bot_slug = null){
    $svc = AIChat_Connect_Service::instance();
    return $svc->send_outbound_text($phone, $text, $bot_slug);
}
