<?php
/**
 * Plugin Name: AI Chat - WhatsApp Addon
 * Description: Integración de WhatsApp (Meta Cloud API) para el plugin AI Chat. Enruta mensajes entrantes a los bots existentes y responde automáticamente.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: estebandezafra
 * Text Domain: ai-chat-wa
 */

if (!defined('ABSPATH')) { exit; }

// Constants
define('AICHAT_WA_VERSION', '0.1.0');
define('AICHAT_WA_DIR', plugin_dir_path(__FILE__));
define('AICHAT_WA_URL', plugin_dir_url(__FILE__));
// Enable temporary debug by defining in wp-config.php or uncomment next line.
if (!defined('AICHAT_WA_DEBUG')) {
    define('AICHAT_WA_DEBUG', true); // Cambia a false en producción.
}

// Debug helper similar to core plugin but scoped to WA addon
if ( ! function_exists( 'aichat_wa_log_debug' ) ) {
    /**
     * Conditional debug logger for WA addon.
     * Prefixes messages and JSON-encodes safe context values.
     *
     * @param string $message Short log message without prefix.
     * @param array  $context Optional associative array with scalar / WP_Error values.
     */
    function aichat_wa_log_debug( $message, array $context = [] ) {
        if ( ! ( defined( 'AICHAT_WA_DEBUG' ) && AICHAT_WA_DEBUG ) ) {
            return;
        }
        if ( ! empty( $context ) ) {
            $safe = [];
            foreach ( $context as $k => $v ) {
                if ( is_scalar( $v ) || $v === null ) {
                    $safe[ $k ] = $v;
                } elseif ( $v instanceof WP_Error ) {
                    $safe[ $k ] = 'WP_Error: ' . $v->get_error_code() . ' - ' . $v->get_error_message();
                } else {
                    $safe[ $k ] = is_object( $v ) ? get_class( $v ) : gettype( $v );
                }
            }
            $json = wp_json_encode( $safe );
            if ( $json ) {
                $message .= ' | ' . $json;
            }
        }
        error_log( '[AIChat-WA] ' . $message );
    }
}

// Includes
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-activator.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-api-client.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-repository.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-service.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-webhook.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-admin.php';
require_once AICHAT_WA_DIR . 'includes/class-aichat-wa-admin-providers.php';

// Activation
register_activation_hook(__FILE__, ['AIChat_WA_Activator','activate']);
// Schema upgrade on admin_init
add_action('admin_init', ['AIChat_WA_Activator','maybe_update_schema']);

// Bootstrap
add_action('plugins_loaded', function(){
    $core_active = function_exists('aichat_generate_bot_response');
    if (!$core_active) {
        // Mostrar aviso pero igualmente habilitar webhook para usar AI Engine u otros servicios.
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p>AI Chat - WhatsApp Addon: el plugin principal AI Chat no está activo. Las integraciones configuradas con servicio "AI Chat" fallarán, pero las de "AI Engine" seguirán intentando responder.</p></div>';
        });
    }
    // Siempre cargamos webhook y admin para permitir uso con AI Engine / futuros proveedores.
    AIChat_WA_Webhook::instance();
    AIChat_WA_Admin::instance();
    AIChat_WA_Admin_Providers::instance();
});

// Simple helper for outbound manual sending (future extension)
function aichat_wa_send_message($phone, $text, $bot_slug = null){
    $svc = AIChat_WA_Service::instance();
    return $svc->send_outbound_text($phone, $text, $bot_slug);
}
