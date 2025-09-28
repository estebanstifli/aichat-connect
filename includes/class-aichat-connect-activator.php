<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Activator {
    public static function activate(){
        global $wpdb;        
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $numbers   = $wpdb->prefix . 'aichat_connect_numbers';
    $messages  = $wpdb->prefix . 'aichat_connect_messages';
    $providers = $wpdb->prefix . 'aichat_connect_providers';

        // Nota compatibilidad: Algunos entornos MariaDB antiguos fallan con DATETIME DEFAULT CURRENT_TIMESTAMP.
        // Usamos TIMESTAMP para created_at y eliminamos updated_at ON UPDATE (se puede manejar vía código si se requiere).
        // Campo 'phone' contiene directamente el phone_number_id de Meta (sin wildcards)
        $sql_numbers = "CREATE TABLE $numbers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(64) NOT NULL COMMENT 'Phone Number ID de Meta',
            bot_slug VARCHAR(100) NOT NULL,
            service VARCHAR(50) NOT NULL DEFAULT 'aichat',
            display_name VARCHAR(100) NULL,
            access_token LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone (phone),
            KEY bot_slug (bot_slug),
            KEY service (service)
        ) $charset";

        // Cambiamos meta JSON a LONGTEXT para compatibilidad (se puede almacenar JSON stringificado).
        $sql_messages = "CREATE TABLE $messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wa_message_id VARCHAR(100) NOT NULL,
            phone VARCHAR(32) NOT NULL,
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
            KEY bot_slug (bot_slug)
        ) $charset";

        // Tabla de providers configurables (capa previa a ejecución)
        $sql_providers = "CREATE TABLE $providers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_key VARCHAR(60) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
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

        // Insertar providers por defecto si la tabla está vacía
        $exists = (int)$wpdb->get_var("SELECT COUNT(*) FROM $providers");
        if ($exists === 0) {
            $wpdb->insert($providers, [
                'provider_key' => 'aichat',
                'name' => __('AI Chat Core','aichat-connect'),
                'description' => __('Internal provider (AI Chat plugin).','aichat-connect'),
                'is_active' => 1,
                'timeout_ms' => 15000,
                'fast_ack_enabled' => 0,
                'fast_ack_message' => __('One moment, generating response...','aichat-connect'),
                'on_timeout_action' => 'fast_ack_followup',
                'fallback_message' => __('Sorry, it took too long. Please try again.','aichat-connect'),
                'meta' => null,
            ]);
            $wpdb->insert($providers, [
                'provider_key' => 'ai-engine',
                'name' => __('AI Engine (Meow)','aichat-connect'),
                'description' => __('Integration with AI Engine.','aichat-connect'),
                'is_active' => 1,
                'timeout_ms' => 20000,
                'fast_ack_enabled' => 1,
                'fast_ack_message' => __('Processing your message, just a moment...','aichat-connect'),
                'on_timeout_action' => 'fallback_message',
                'fallback_message' => __('I could not reply right now. Please try again shortly.','aichat-connect'),
                'meta' => null,
            ]);
        }
        // Ensure AIPKit provider exists (added in later version); insert if missing.
        $has_aipkit = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $providers WHERE provider_key=%s", 'aipkit'));
        if ($has_aipkit === 0) {
            $wpdb->insert($providers, [
                'provider_key' => 'aipkit',
                'name' => __('AIPKit','aichat-connect'),
                'description' => __('AIPKit chatbot REST provider.','aichat-connect'),
                'is_active' => 1,
                'timeout_ms' => 20000,
                'fast_ack_enabled' => 1,
                'fast_ack_message' => __('Processing your message, one moment...','aichat-connect'),
                'on_timeout_action' => 'fallback_message',
                'fallback_message' => __('Sorry, I could not answer on time. Please try again.','aichat-connect'),
                'meta' => null,
            ]);
        }
    }

    // Allow running dbDelta on upgrades without deactivate/activate
    public static function maybe_update_schema(){
        self::activate();
    }
}
