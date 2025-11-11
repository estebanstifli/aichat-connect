<?php
/**
 * Plugin Name: Andromeda Connect
 * Description: Connect WhatsApp (Meta Cloud API) and Telegram webhooks to AI chat providers for automated replies with logging and mapping controls.
 * Version: 0.1.3
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
define('AICHAT_CONNECT_VERSION', '0.1.3');
define('AICHAT_CONNECT_DIR', plugin_dir_path(__FILE__));
define('AICHAT_CONNECT_URL', plugin_dir_url(__FILE__));
// Enable temporary debug by defining in wp-config.php or uncomment next line.
if (!defined('AICHAT_CONNECT_DEBUG')) {
    define('AICHAT_CONNECT_DEBUG', false); // Set to false in production.
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

// Activation and schema management (moved from class to functions)
if ( ! function_exists( 'aichat_connect_activate' ) ) {
    function aichat_connect_activate(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $numbers   = $wpdb->prefix . 'aichat_connect_numbers';
        $messages  = $wpdb->prefix . 'aichat_connect_messages';
        $providers = $wpdb->prefix . 'aichat_connect_providers';

        // Numbers mapping table
        $sql_numbers = "CREATE TABLE $numbers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(32) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
            bot_slug VARCHAR(100) NULL,
            service VARCHAR(50) NOT NULL DEFAULT 'aichat',
            display_name VARCHAR(100) NULL,
            access_token LONGTEXT NULL,
            verify_token VARCHAR(64) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone (phone),
            KEY channel (channel),
            KEY bot_slug (bot_slug),
            KEY service (service)
        ) $charset";

        // Messages log table
        $sql_messages = "CREATE TABLE $messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wa_message_id VARCHAR(100) NOT NULL,
            phone VARCHAR(32) NOT NULL,
            external_id VARCHAR(100) NULL,
            direction ENUM('in','out') NOT NULL,
            bot_slug VARCHAR(100) NULL,
            session_id VARCHAR(64) NULL,
            user_text LONGTEXT NULL,
            bot_response LONGTEXT NULL,
            status VARCHAR(30) NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wa_message_id (wa_message_id),
            KEY phone (phone),
            KEY external_id (external_id),
            KEY bot_slug (bot_slug)
        ) $charset";

        // Providers configuration table
        $sql_providers = "CREATE TABLE $providers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_key VARCHAR(60) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            timeout_ms INT UNSIGNED NOT NULL DEFAULT 15000,
            fast_ack_enabled TINYINT(1) NOT NULL DEFAULT 0,
            fast_ack_message VARCHAR(255) NULL,
            on_timeout_action ENUM('silent','fast_ack_followup','fallback_message') NOT NULL DEFAULT 'fast_ack_followup',
            fallback_message VARCHAR(255) NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY provider_key (provider_key),
            KEY is_active (is_active)
        ) $charset";

        dbDelta($sql_numbers);
        dbDelta($sql_messages);
        dbDelta($sql_providers);

        // Seed default providers if empty
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = (int) $wpdb->get_var("SELECT COUNT(*) FROM $providers");
        if ($exists === 0) {
            $wpdb->insert($providers, [
                'provider_key' => 'aichat',
                'name' => __('Axiachat AI','andromeda-connect'),
                'description' => __('Internal provider (Axiachat AI plugin).','andromeda-connect'),
                'is_active' => 1,
                'timeout_ms' => 15000,
                'fast_ack_enabled' => 0,
                'fast_ack_message' => __('One moment, generating response...','andromeda-connect'),
                'on_timeout_action' => 'fast_ack_followup',
                'fallback_message' => __('Sorry, it took too long. Please try again.','andromeda-connect'),
                'meta' => null,
            ]);
            $wpdb->insert($providers, [
                'provider_key' => 'ai-engine',
                'name' => __('AI Engine (Meow)','andromeda-connect'),
                'description' => __('Integration with AI Engine.','andromeda-connect'),
                'is_active' => 1,
                'timeout_ms' => 20000,
                'fast_ack_enabled' => 1,
                'fast_ack_message' => __('Processing your message, just a moment...','andromeda-connect'),
                'on_timeout_action' => 'fallback_message',
                'fallback_message' => __('I could not reply right now. Please try again shortly.','andromeda-connect'),
                'meta' => null,
            ]);
        }
        // Update label/description for legacy key
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $providers,
            [
                'name' => __('Axiachat AI','andromeda-connect'),
                'description' => __('Internal provider (Axiachat AI plugin).','andromeda-connect')
            ],
            [ 'provider_key' => 'aichat' ]
        );
        // Ensure AIPKit exists
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_aipkit = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $providers WHERE provider_key=%s", 'aipkit') );
        if ($has_aipkit === 0) {
            $wpdb->insert($providers, [
                'provider_key' => 'aipkit',
                'name' => __('AIPKit','andromeda-connect'),
                'description' => __('AIPKit chatbot REST provider.','andromeda-connect'),
                'is_active' => 1,
                'timeout_ms' => 20000,
                'fast_ack_enabled' => 1,
                'fast_ack_message' => __('Processing your message, one moment...','andromeda-connect'),
                'on_timeout_action' => 'fallback_message',
                'fallback_message' => __('Sorry, I could not answer on time. Please try again.','andromeda-connect'),
                'meta' => null,
            ]);
        }
    }
}

if ( ! function_exists( 'aichat_connect_maybe_update_schema' ) ) {
    function aichat_connect_maybe_update_schema(){
        aichat_connect_activate();
    }
}

// Includes
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-api-client.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-service.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-provider-aipkit.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-webhook.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-channel-telegram.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-webhook-telegram.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin.php';
require_once AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-admin-providers.php';

// Activation
register_activation_hook(__FILE__, 'aichat_connect_activate');
// Schema upgrade on admin_init
add_action('admin_init', 'aichat_connect_maybe_update_schema');

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


