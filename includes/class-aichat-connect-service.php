<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Service {
    private static $instance;
    private $repo;
    private $api;
    // Lightweight normalization cache to avoid recomputing inside tight loops.
    private $normalized_cache = [];

    public static function instance(){ if(!self::$instance){ self::$instance = new self(); } return self::$instance; }

    private function __construct(){
        $this->repo = AIChat_Connect_Repository::instance();
        $this->api  = AIChat_Connect_API_Client::instance();
    }

    public function ensure_session_id($phone){
        // Simple deterministic session key
        return 'wa_' . md5($phone);
    }

    public function handle_incoming_text($phone, $body_text, $wa_message_id, $business_id = null){
        // Idempotencia
        if ($this->repo->message_exists($wa_message_id)){
            return ['skipped' => 'duplicate'];
        }
    // Clamp the message id to fit the UNIQUE index length just in case.
        if (strlen($wa_message_id) > 100) {
            $wa_message_id = substr($wa_message_id, 0, 100);
        }
    $t_map_start = microtime(true);
    $resolution = $this->repo->resolve_bot_slug($business_id, $phone);
    $t_map_end = microtime(true);
    $bot_slug = $resolution['bot_slug'];
    $service  = $resolution['service'] ?? 'aichat';
    $map_ms = (int) round(($t_map_end - $t_map_start) * 1000);
    aichat_connect_log_debug('Mapping resolved', [ 'phone'=>$phone, 'business_id'=>$business_id, 'bot_slug'=>$bot_slug, 'provider_service'=>$service, 'match_type'=>$resolution['match_type'] ?? null, 'ms'=>$map_ms ]);
        if (!$bot_slug){
            $this->repo->log_message([
                'wa_message_id' => $wa_message_id,
                'phone' => $phone,
                'direction' => 'in',
                'user_text' => $body_text,
                'status' => 'no_bot'
            ]);
            return ['error' => 'no_bot_mapping'];
        }
        $session_id = $this->ensure_session_id($phone);

    // Dispatch to the configured provider (AI Chat by default, AI Engine optional).
    // Load provider configuration (timeout and fast-ack settings).
    $provider_cfg = $this->repo->get_provider_by_key($service);
    if ($provider_cfg) {
        aichat_connect_log_debug('Provider config loaded', [ 'service'=>$service, 'timeout_ms'=>$provider_cfg['timeout_ms'], 'fast_ack'=> (int)$provider_cfg['fast_ack_enabled'] ]);
    }

    // Pre-provider hook allows text mutation or short-circuit.
    $pre = apply_filters('aichat_connect_pre_provider', [
        'proceed' => true,
        'text' => $body_text,
        'meta' => []
    ], $service, $phone, $bot_slug);
    if (empty($pre['proceed'])) {
        aichat_connect_log_debug('Pre-provider aborted', [ 'service'=>$service ]);
        return ['error' => 'aborted_by_filter'];
    }
    if (!empty($pre['text']) && is_string($pre['text'])) {
        $body_text = $pre['text'];
    }

    // Fast acknowledgement (if configured and enabled).
    if ($provider_cfg && (int)$provider_cfg['fast_ack_enabled'] === 1 && !empty($provider_cfg['fast_ack_message'])) {
    // Send a non-blocking acknowledgement (logged separately as outbound only).
        try {
            $creds_ack = $this->repo->resolve_credentials($business_id, $phone);
            $this->api->send_text($phone, $provider_cfg['fast_ack_message'], $creds_ack);
            aichat_connect_log_debug('Fast ack sent', [ 'service'=>$service ]);
        } catch (\Throwable $e) {
            aichat_connect_log_debug('Fast ack error', [ 'msg'=>$e->getMessage() ]);
        }
    }

    $t_provider_start = microtime(true);
    $result = null; $assistant = '';
    $timeout_ms = $provider_cfg ? (int)$provider_cfg['timeout_ms'] : 0;
    $deadline = $timeout_ms > 0 ? ($t_provider_start + ($timeout_ms / 1000.0)) : null;

    if ($service === 'aipkit') {
        $assistant = '';
        $bot_id = is_numeric($bot_slug) ? (int)$bot_slug : $bot_slug;
        $messages = [ [ 'role' => 'user', 'content' => $body_text ] ];
        $api_key = '';
        $hist_enabled = 1; $hist_limit = 12; $history_pairs = [];
        if ($provider_cfg && !empty($provider_cfg['meta'])) {
            $meta_dec = json_decode($provider_cfg['meta'], true);
            if (is_array($meta_dec)) {
                if (!empty($meta_dec['aipkit_api_key'])) { $api_key = $meta_dec['aipkit_api_key']; }
                if (isset($meta_dec['aipkit_history_enabled'])) { $hist_enabled = (int)$meta_dec['aipkit_history_enabled']; }
                if (isset($meta_dec['aipkit_history_limit'])) { $hist_limit = (int)$meta_dec['aipkit_history_limit']; }
            }
        }
        if ($hist_limit < 1) { $hist_limit = 1; } elseif ($hist_limit > 50) { $hist_limit = 50; }
        if ($hist_enabled === 1) {
            $history_pairs = $this->repo->get_recent_messages_for_phone($phone, $hist_limit);
            $built = [];
            foreach ($history_pairs as $pair) {
                if ($pair['user'] !== '') { $built[] = [ 'role' => 'user', 'content' => $pair['user'] ]; }
                if ($pair['assistant'] !== '') { $built[] = [ 'role' => 'assistant', 'content' => $pair['assistant'] ]; }
            }
            $built[] = [ 'role' => 'user', 'content' => $body_text ];
            $messages = $built;
            aichat_connect_log_debug('AIPKit history included', [ 'pairs'=>count($history_pairs), 'messages_sent'=>count($messages) ]);
        }
        if (!class_exists('AIChat_Connect_Provider_AIPKit')) {
            $file = plugin_dir_path(__FILE__) . 'class-aichat-connect-provider-aipkit.php';
            if (file_exists($file)) { require_once $file; }
        }
        if (class_exists('AIChat_Connect_Provider_AIPKit')) {
            $prov = AIChat_Connect_Provider_AIPKit::instance();
            $result = $prov->chat($bot_id, $messages, $api_key, [ 'timeout' => ($timeout_ms>0? ceil($timeout_ms/1000):20), 'session_id'=>$session_id, 'phone'=>$phone ]);
        } else {
            $result = new WP_Error('aipkit_provider_missing','AIPKit provider file not loaded.');
        }
    } elseif ($service === 'ai-engine' || $service === 'aiengine' || $service === 'ai_engine') {
            // Prefer the PHP API when the plugin is active.
            $assistant = '';
            $result = null;
            if ( isset($GLOBALS['mwai']) && is_object($GLOBALS['mwai']) && method_exists($GLOBALS['mwai'], 'simpleChatbotQuery') ){
                try {
                    $assistant = (string)$GLOBALS['mwai']->simpleChatbotQuery($bot_slug ?: 'default', $body_text, 'WHATSAPP_'.$phone);
                    $result = ['provider'=>'ai-engine','message'=>$assistant];
                } catch (\Throwable $e){
                    $result = new WP_Error('ai_engine_error', $e->getMessage());
                }
            } else {
                // If the PHP API is missing, surface a configuration requirement.
                $result = new WP_Error('ai_engine_not_available', 'AI Engine is not available (enable the plugin or configure REST access).');
            }
        } else {
            // AI Chat (core)
            $has_new = function_exists('aichat_generate_bot_response_for_phone');
            $has_legacy = function_exists('aichat_generate_bot_response');
            if (!$has_new && !$has_legacy){
                $result = new WP_Error('aichat_core_missing', 'AI Chat core plugin no disponible.');
            } else {
                $normalized = $this->normalize_user_phone($phone);
                // Base context shared by both helper functions.
                $core_context = [
                    'source_channel'     => 'whatsapp',
                    'user_phone'         => $phone,            // Original inbound value.
                    'user_phone_normal'  => $normalized,       // E164 or cleaned value.
                    'business_phone_id'  => $business_id,
                    'session_id'         => $session_id,
                ];
                $core_context = apply_filters('aichat_connect_core_context_args', $core_context, $bot_slug, $phone, $business_id);
                if ($has_new) {
                    // Use the newer helper when available.
                    $result = call_user_func('aichat_generate_bot_response_for_phone', $bot_slug, $normalized, $body_text, $core_context);
                } else {
                    // Fallback to the legacy helper for older core versions.
                    $result = call_user_func('aichat_generate_bot_response', $bot_slug, $body_text, $session_id, $core_context);
                }
            }
        }
        $t_provider_end = microtime(true);
        $prov_ms = (int) round(($t_provider_end - $t_provider_start) * 1000);
        if (is_wp_error($result)) {
            aichat_connect_log_debug('Provider call error', [ 'bot_slug'=>$bot_slug, 'service'=>$service, 'ms'=>$prov_ms, 'code'=>$result->get_error_code(), 'msg'=>$result->get_error_message() ]);
            $this->repo->log_message([
                'wa_message_id' => $wa_message_id,
                'phone' => $phone,
                'direction' => 'in',
                'bot_slug' => $bot_slug,
                'session_id' => $session_id,
                'user_text' => $body_text,
                'bot_response' => '',
                'status' => 'error',
                'meta' => ['error' => $result->get_error_message(), 'code'=>$result->get_error_code(), 'match'=>$resolution, 'business_id'=>$business_id]
            ]);
            return ['error' => $result->get_error_message(), 'code'=>$result->get_error_code()];
        }
    // Core returns a message key today; keep compatibility if response/answer are added later.
    $assistant = isset($assistant) ? $assistant : '';
        if (is_array($result)) {
            if (isset($result['message']) && is_string($result['message'])) {
                $assistant = (string)$result['message'];
            } elseif (isset($result['response']) && is_string($result['response'])) {
                $assistant = (string)$result['response'];
            } elseif (isset($result['answer']) && is_string($result['answer'])) {
                $assistant = (string)$result['answer'];
            }
        }
        if ($assistant === '') {
            aichat_connect_log_debug('Provider empty assistant', [ 'service'=>$service ]);
            $this->repo->log_message([
                'wa_message_id' => $wa_message_id,
                'phone' => $phone,
                'direction' => 'in',
                'bot_slug' => $bot_slug,
                'session_id' => $session_id,
                'user_text' => $body_text,
                'bot_response' => '',
                'status' => 'empty',
                'meta' => ['core'=>$result, 'match'=>$resolution, 'business_id'=>$business_id]
            ]);
            return ['error' => 'empty_assistant'];
        }
        aichat_connect_log_debug('Provider call ok', [ 'bot_slug'=>$bot_slug, 'service'=>$service, 'ms'=>$prov_ms, 'assistant_chars'=>strlen($assistant) ]);

    // Timeout check when the provider exceeds the configured limit.
        if ($provider_cfg && $timeout_ms > 0 && $prov_ms > $timeout_ms) {
            aichat_connect_log_debug('Provider timeout triggered', [ 'prov_ms'=>$prov_ms, 'timeout_ms'=>$timeout_ms, 'action'=>$provider_cfg['on_timeout_action'] ]);
            $action = $provider_cfg['on_timeout_action'];
            if ($action === 'fallback_message' && !empty($provider_cfg['fallback_message'])) {
                $assistant = $provider_cfg['fallback_message'];
            } elseif ($action === 'silent') {
                // No respondemos nada (registramos log y salimos)
                $this->repo->log_message([
                    'wa_message_id' => $wa_message_id,
                    'phone' => $phone,
                    'direction' => 'in',
                    'bot_slug' => $bot_slug,
                    'session_id' => $session_id,
                    'user_text' => $body_text,
                    'bot_response' => '',
                    'status' => 'timeout_silent',
                    'meta' => ['match'=>$resolution, 'business_id'=>$business_id, 'timeout_ms'=>$timeout_ms, 'elapsed_ms'=>$prov_ms]
                ]);
                return ['error'=>'timeout'];
            } else {
                // fast_ack_followup: keep the assistant text; the ack already went out earlier.
                aichat_connect_log_debug('Timeout action fallback fast ack followup', []);
            }
        }
    // Log before sending so we keep diagnostics even if delivery fails.
        $this->repo->log_message([
            'wa_message_id' => $wa_message_id,
            'phone' => $phone,
            'direction' => 'in',
            'bot_slug' => $bot_slug,
            'session_id' => $session_id,
            'user_text' => $body_text,
            'bot_response' => $assistant,
            'status' => 'ok',
            'meta' => ['core'=>$result, 'match'=>$resolution, 'business_id'=>$business_id]
        ]);

    // Resolve credentials (support per-number overrides) and send WhatsApp reply.
    $creds = $this->repo->resolve_credentials($business_id, $phone);
    aichat_connect_log_debug('Sending WA message', [ 'phone'=>$phone, 'bot_slug'=>$bot_slug, 'provider_service'=>$service, 'using_custom_token'=> empty($creds['access_token'])?0:1, 'phone_id'=>$creds['phone_id'] ? '***set***':'***empty***' ]);
    $send = $this->api->send_text($phone, $assistant, $creds);
        if (is_wp_error($send)){
            $code = $send->get_error_code();
            $data = $send->get_error_data();
            aichat_connect_log_debug('WA send error', [ 'code'=>$code, 'message'=>$send->get_error_message() ]);
            if ($code === 'wa_token_expired' || $code === 'wa_token_blocked'){
                return ['error' => $send->get_error_message(), 'code' => $code];
            }
            return ['error' => $send->get_error_message(), 'details' => $data];
        }
        aichat_connect_log_debug('WA message sent OK', [ 'phone'=>$phone, 'chars'=>strlen($assistant) ]);
    // Post-provider hook for inspection; informational only because the send already happened.
    do_action('aichat_connect_post_provider', [
            'phone' => $phone,
            'bot_slug' => $bot_slug,
            'service' => $service,
            'assistant' => $assistant,
            'provider_ms' => $prov_ms,
            'mapping_ms' => $map_ms
        ]);
        return ['ok' => true, 'assistant' => $assistant, 'provider_ms'=>$prov_ms, 'mapping_ms'=>$map_ms];
    }

    public function send_outbound_text($phone, $text, $bot_slug = null){
        if (!$bot_slug){
            $bot_slug = $this->repo->get_bot_for_phone($phone);
        }
        if (!$bot_slug){
            return new WP_Error('no_bot', __('No mapping for this phone','andromeda-connect'));
        }
        $session_id = $this->ensure_session_id($phone);
    $creds = $this->repo->resolve_credentials(null, $phone);
    aichat_connect_log_debug('Manual outbound send', [ 'phone'=>$phone, 'bot_slug'=>$bot_slug, 'chars'=>strlen($text) ]);
    $resp = $this->api->send_text($phone, $text, $creds);
        $this->repo->log_message([
            'phone' => $phone,
            'direction' => 'out',
            'bot_slug' => $bot_slug,
            'session_id' => $session_id,
            'bot_response' => $text,
            'status' => is_wp_error($resp)? 'error':'ok',
            'meta' => ['send' => is_wp_error($resp)? $resp->get_error_message():$resp]
        ]);
        if (is_wp_error($resp)) {
            aichat_connect_log_debug('Manual outbound error', [ 'code'=>$resp->get_error_code(), 'message'=>$resp->get_error_message() ]);
        } else {
            aichat_connect_log_debug('Manual outbound ok', [ 'phone'=>$phone ]);
        }
        return $resp;
    }

    /**
     * Telegram channel handler: processes the inbound message and answers through the Bot API (no WhatsApp Graph).
     * @param array $mapping_row Row from aichat_connect_numbers (uses bot_slug, service, access_token)
     * @param string $chat_id Telegram chat id (user or group)
     * @param string $body_text User text received
     * @param string $tg_message_id Telegram message id (idempotency)
     */
    public function handle_incoming_text_telegram($mapping_row, $chat_id, $body_text, $tg_message_id){
        // Idempotency: reuse Telegram message id with a prefix to avoid collisions
        $wa_like_id = 'tg_' . substr((string)$tg_message_id, 0, 90);
        if ($this->repo->message_exists($wa_like_id)){
            return ['skipped' => 'duplicate'];
        }

        $bot_slug = $mapping_row['bot_slug'] ?? '';
        $service  = $mapping_row['service'] ?? 'aichat';
        if (!$bot_slug){
            $this->repo->log_message([
                'wa_message_id' => $wa_like_id,
                'phone' => (string)$chat_id,
                'direction' => 'in',
                'user_text' => $body_text,
                'status' => 'no_bot'
            ]);
            return ['error' => 'no_bot_mapping'];
        }
        $session_id = 'tg_' . md5((string)$chat_id);

        // Provider config (timeouts/ack)
        $provider_cfg = $this->repo->get_provider_by_key($service);
        if ($provider_cfg) {
            aichat_connect_log_debug('Provider config loaded', [ 'service'=>$service, 'timeout_ms'=>$provider_cfg['timeout_ms'], 'fast_ack'=> (int)$provider_cfg['fast_ack_enabled'] ]);
        }

        // Pre-provider filter
        $pre = apply_filters('aichat_connect_pre_provider', [ 'proceed'=>true, 'text'=>$body_text, 'meta'=>[] ], $service, (string)$chat_id, $bot_slug);
        if (empty($pre['proceed'])) { return ['error' => 'aborted_by_filter']; }
        if (!empty($pre['text']) && is_string($pre['text'])) { $body_text = $pre['text']; }

        // Fast ack via Telegram when it is configured
        $bot_token = $mapping_row['access_token'] ?? '';
        if ($provider_cfg && (int)$provider_cfg['fast_ack_enabled'] === 1 && !empty($provider_cfg['fast_ack_message'])) {
            try {
                if (class_exists('AIChat_Connect_Channel_Telegram')){
                    AIChat_Connect_Channel_Telegram::instance()->send_text($chat_id, $provider_cfg['fast_ack_message'], $bot_token);
                    aichat_connect_log_debug('Fast ack sent', [ 'service'=>$service ]);
                }
            } catch (\Throwable $e) {
                aichat_connect_log_debug('Fast ack error', [ 'msg'=>$e->getMessage() ]);
            }
        }

        $t_provider_start = microtime(true);
        $timeout_ms = $provider_cfg ? (int)$provider_cfg['timeout_ms'] : 0;
        $assistant = '';
        $result = null;

        // Dispatch a providers (reutilizamos las ramas tal cual, cambiando session/context)
        if ($service === 'aipkit') {
            $bot_id = is_numeric($bot_slug) ? (int)$bot_slug : $bot_slug;
            $messages = [ [ 'role' => 'user', 'content' => $body_text ] ];
            $api_key = '';
            $hist_enabled = 1; $hist_limit = 12; $history_pairs = [];
            if ($provider_cfg && !empty($provider_cfg['meta'])) {
                $meta_dec = json_decode($provider_cfg['meta'], true);
                if (is_array($meta_dec)) {
                    if (!empty($meta_dec['aipkit_api_key'])) { $api_key = $meta_dec['aipkit_api_key']; }
                    if (isset($meta_dec['aipkit_history_enabled'])) { $hist_enabled = (int)$meta_dec['aipkit_history_enabled']; }
                    if (isset($meta_dec['aipkit_history_limit'])) { $hist_limit = (int)$meta_dec['aipkit_history_limit']; }
                }
            }
            if ($hist_limit < 1) { $hist_limit = 1; } elseif ($hist_limit > 50) { $hist_limit = 50; }
            if ($hist_enabled === 1) {
                $pairs = $this->repo->get_recent_messages_for_phone((string)$chat_id, $hist_limit);
                $built = [];
                foreach ($pairs as $pair) {
                    if ($pair['user'] !== '') { $built[] = [ 'role' => 'user', 'content' => $pair['user'] ]; }
                    if ($pair['assistant'] !== '') { $built[] = [ 'role' => 'assistant', 'content' => $pair['assistant'] ]; }
                }
                $built[] = [ 'role' => 'user', 'content' => $body_text ];
                $messages = $built;
                aichat_connect_log_debug('AIPKit history included', [ 'pairs'=>count($pairs), 'messages_sent'=>count($messages) ]);
            }
            if (!class_exists('AIChat_Connect_Provider_AIPKit')) {
                $file = plugin_dir_path(__FILE__) . 'class-aichat-connect-provider-aipkit.php';
                if (file_exists($file)) { require_once $file; }
            }
            if (class_exists('AIChat_Connect_Provider_AIPKit')) {
                $prov = AIChat_Connect_Provider_AIPKit::instance();
                $result = $prov->chat($bot_id, $messages, $api_key, [ 'timeout' => ($timeout_ms>0? ceil($timeout_ms/1000):20), 'session_id'=>$session_id, 'phone'=>(string)$chat_id ]);
            } else {
                $result = new WP_Error('aipkit_provider_missing','AIPKit provider file not loaded.');
            }
        } elseif ($service === 'ai-engine' || $service === 'aiengine' || $service === 'ai_engine') {
            if ( isset($GLOBALS['mwai']) && is_object($GLOBALS['mwai']) && method_exists($GLOBALS['mwai'], 'simpleChatbotQuery') ){
                try { $assistant = (string)$GLOBALS['mwai']->simpleChatbotQuery($bot_slug ?: 'default', $body_text, 'TELEGRAM_'.$chat_id); $result=['provider'=>'ai-engine','message'=>$assistant]; }
                catch (\Throwable $e){ $result = new WP_Error('ai_engine_error', $e->getMessage()); }
            } else { $result = new WP_Error('ai_engine_not_available','AI Engine is not available (enable the plugin or configure REST access).'); }
        } else {
            $has_new = function_exists('aichat_generate_bot_response_for_phone');
            $has_legacy = function_exists('aichat_generate_bot_response');
            if (!$has_new && !$has_legacy){ $result = new WP_Error('aichat_core_missing','AI Chat core plugin no disponible.'); }
            else {
                $core_context = [ 'source_channel'=>'telegram', 'user_phone'=>(string)$chat_id, 'user_phone_normal'=>(string)$chat_id, 'business_phone_id'=> null, 'session_id'=>$session_id ];
                $core_context = apply_filters('aichat_connect_core_context_args', $core_context, $bot_slug, (string)$chat_id, null);
                if ($has_new){ $result = call_user_func('aichat_generate_bot_response_for_phone', $bot_slug, (string)$chat_id, $body_text, $core_context); }
                else { $result = call_user_func('aichat_generate_bot_response', $bot_slug, $body_text, $session_id, $core_context); }
            }
        }

        $t_provider_end = microtime(true);
        $prov_ms = (int) round(($t_provider_end - $t_provider_start) * 1000);
        if (is_wp_error($result)){
            aichat_connect_log_debug('Provider call error', [ 'bot_slug'=>$bot_slug, 'service'=>$service, 'ms'=>$prov_ms, 'code'=>$result->get_error_code(), 'msg'=>$result->get_error_message() ]);
            $this->repo->log_message([
                'wa_message_id' => $wa_like_id,
                'phone' => (string)$chat_id,
                'direction' => 'in',
                'bot_slug' => $bot_slug,
                'session_id' => $session_id,
                'user_text' => $body_text,
                'bot_response' => '',
                'status' => 'error',
                'meta' => ['error'=>$result->get_error_message(), 'code'=>$result->get_error_code()]
            ]);
            return ['error' => $result->get_error_message(), 'code'=>$result->get_error_code()];
        }

        if (is_array($result)){
            if (isset($result['message']) && is_string($result['message'])) { $assistant = (string)$result['message']; }
            elseif (isset($result['response']) && is_string($result['response'])) { $assistant = (string)$result['response']; }
            elseif (isset($result['answer']) && is_string($result['answer'])) { $assistant = (string)$result['answer']; }
        }
        if ($assistant === ''){
            $this->repo->log_message([
                'wa_message_id' => $wa_like_id,
                'phone' => (string)$chat_id,
                'direction' => 'in',
                'bot_slug' => $bot_slug,
                'session_id' => $session_id,
                'user_text' => $body_text,
                'bot_response' => '',
                'status' => 'empty',
                'meta' => ['core'=>$result]
            ]);
            return ['error'=>'empty_assistant'];
        }

        $this->repo->log_message([
            'wa_message_id' => $wa_like_id,
            'phone' => (string)$chat_id,
            'direction' => 'in',
            'bot_slug' => $bot_slug,
            'session_id' => $session_id,
            'user_text' => $body_text,
            'bot_response' => $assistant,
            'status' => 'ok',
            'meta' => ['provider_ms'=>$prov_ms]
        ]);

        // Enviar por Telegram
        if (!class_exists('AIChat_Connect_Channel_Telegram')){
            return ['error'=>'telegram_channel_missing'];
        }
        $send = AIChat_Connect_Channel_Telegram::instance()->send_text($chat_id, $assistant, $bot_token);
        if (is_wp_error($send)){
            aichat_connect_log_debug('Telegram send error', [ 'code'=>$send->get_error_code(), 'message'=>$send->get_error_message() ]);
            return ['error'=>$send->get_error_message(), 'code'=>$send->get_error_code()];
        }
        aichat_connect_log_debug('Telegram message sent OK', [ 'chat_id'=>$chat_id, 'chars'=>strlen($assistant) ]);
        do_action('aichat_connect_post_provider', [ 'phone'=>(string)$chat_id, 'bot_slug'=>$bot_slug, 'service'=>$service, 'assistant'=>$assistant, 'provider_ms'=>$prov_ms ]);
        return ['ok'=>true, 'assistant'=>$assistant, 'provider_ms'=>$prov_ms];
    }

    /**
     * Normalize the user phone into a simplified E164-like format (keep leading + when present, digits only).
     * Does not validate country; extra filters can refine it when needed.
     * Filter: aichat_connect_normalize_user_phone( string $normalized, string $raw )
     */
    private function normalize_user_phone($raw){
        if (!is_string($raw) || $raw==='') return '';
        if (isset($this->normalized_cache[$raw])) return $this->normalized_cache[$raw];
        $digits = preg_replace('/[^0-9]/','', $raw);
        // Preserve the + prefix when the original starts with it and we still have digits
        if (strpos($raw, '+') === 0) {
            $normalized = '+' . $digits;
        } else {
            $normalized = $digits; // Core can infer the country later if future logic requires it
        }
        $normalized = substr($normalized, 0, 30); // defensive length guard
        $normalized = apply_filters('aichat_connect_normalize_user_phone', $normalized, $raw);
        $this->normalized_cache[$raw] = $normalized;
        return $normalized;
    }
}


