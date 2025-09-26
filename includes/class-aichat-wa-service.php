<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_WA_Service {
    private static $instance;
    private $repo;
    private $api;

    public static function instance(){ if(!self::$instance){ self::$instance = new self(); } return self::$instance; }

    private function __construct(){
        $this->repo = AIChat_WA_Repository::instance();
        $this->api  = AIChat_WA_API_Client::instance();
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
        // Asegurar longitud (por si algún ID supera el límite del índice UNIQUE)
        if (strlen($wa_message_id) > 100) {
            $wa_message_id = substr($wa_message_id, 0, 100);
        }
    $t_map_start = microtime(true);
    $resolution = $this->repo->resolve_bot_slug($business_id, $phone);
    $t_map_end = microtime(true);
    $bot_slug = $resolution['bot_slug'];
    $service  = $resolution['service'] ?? 'aichat';
    $map_ms = (int) round(($t_map_end - $t_map_start) * 1000);
    aichat_wa_log_debug('Mapping resolved', [ 'phone'=>$phone, 'business_id'=>$business_id, 'bot_slug'=>$bot_slug, 'provider_service'=>$service, 'match_type'=>$resolution['match_type'] ?? null, 'ms'=>$map_ms ]);
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

        // Enviar al servicio configurado (AI Chat por defecto, AI Engine opcional)
    // Obtener configuración del provider (si existe) para timeout / fast ack
    $provider_cfg = $this->repo->get_provider_by_key($service);
    if ($provider_cfg) {
        aichat_wa_log_debug('Provider config loaded', [ 'service'=>$service, 'timeout_ms'=>$provider_cfg['timeout_ms'], 'fast_ack'=> (int)$provider_cfg['fast_ack_enabled'] ]);
    }

    // Hooks pre-proveedor (permiten mutar texto o abortar)
    $pre = apply_filters('aichat_wa_pre_provider', [
        'proceed' => true,
        'text' => $body_text,
        'meta' => []
    ], $service, $phone, $bot_slug);
    if (empty($pre['proceed'])) {
        aichat_wa_log_debug('Pre-provider aborted', [ 'service'=>$service ]);
        return ['error' => 'aborted_by_filter'];
    }
    if (!empty($pre['text']) && is_string($pre['text'])) {
        $body_text = $pre['text'];
    }

    // Fast Ack (si configurado y habilitado)
    if ($provider_cfg && (int)$provider_cfg['fast_ack_enabled'] === 1 && !empty($provider_cfg['fast_ack_message'])) {
        // Enviamos un acuse rápido no bloqueante (no registramos como conversación normal, sólo out separado)
        try {
            $creds_ack = $this->repo->resolve_credentials($business_id, $phone);
            $this->api->send_text($phone, $provider_cfg['fast_ack_message'], $creds_ack);
            aichat_wa_log_debug('Fast ack sent', [ 'service'=>$service ]);
        } catch (\Throwable $e) {
            aichat_wa_log_debug('Fast ack error', [ 'msg'=>$e->getMessage() ]);
        }
    }

    $t_provider_start = microtime(true);
    $result = null; $assistant = '';
    $timeout_ms = $provider_cfg ? (int)$provider_cfg['timeout_ms'] : 0;
    $deadline = $timeout_ms > 0 ? ($t_provider_start + ($timeout_ms / 1000.0)) : null;

    if ($service === 'ai-engine' || $service === 'aiengine' || $service === 'ai_engine') {
            // Preferimos la API PHP si el plugin está activo
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
                // Si no hay API PHP, indicamos configuración requerida (REST requiere setup previo)
                $result = new WP_Error('ai_engine_not_available', 'AI Engine no está disponible (activa el plugin o configura REST).');
            }
        } else {
            // AI Chat (core)
            if (!function_exists('aichat_generate_bot_response')){
                $result = new WP_Error('aichat_core_missing', 'AI Chat core plugin no disponible.');
            } else {
                $result = call_user_func('aichat_generate_bot_response', $bot_slug, $body_text, $session_id, [ 'source_channel' => 'whatsapp' ]);
            }
        }
        $t_provider_end = microtime(true);
        $prov_ms = (int) round(($t_provider_end - $t_provider_start) * 1000);
        if (is_wp_error($result)) {
            aichat_wa_log_debug('Provider call error', [ 'bot_slug'=>$bot_slug, 'service'=>$service, 'ms'=>$prov_ms, 'code'=>$result->get_error_code(), 'msg'=>$result->get_error_message() ]);
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
        // Core devuelve clave 'message'. Mantenemos compatibilidad si en el futuro añade 'response'.
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
            aichat_wa_log_debug('Empty assistant after provider call', [ 'result_keys'=> is_array($result)? implode(',', array_keys($result)) : 'non-array' ]);
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
        aichat_wa_log_debug('Provider call ok', [ 'bot_slug'=>$bot_slug, 'service'=>$service, 'ms'=>$prov_ms, 'assistant_chars'=>strlen($assistant) ]);

        // Timeout check (si tiempo excedido y config indica acción)
        if ($provider_cfg && $timeout_ms > 0 && $prov_ms > $timeout_ms) {
            aichat_wa_log_debug('Provider timeout triggered', [ 'service'=>$service, 'prov_ms'=>$prov_ms, 'timeout_ms'=>$timeout_ms ]);
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
                // fast_ack_followup: dejamos assistant original, se enviará normalmente (el ACK ya se mandó antes)
            }
        }
        // Log antes de enviar para tener trazabilidad aunque falle envío
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

    // Resolver credenciales (permiten multi-número) y enviar respuesta
    $creds = $this->repo->resolve_credentials($business_id, $phone);
    aichat_wa_log_debug('Sending WA message', [ 'phone'=>$phone, 'bot_slug'=>$bot_slug, 'provider_service'=>$service, 'using_custom_token'=> empty($creds['access_token'])?0:1, 'phone_id'=>$creds['phone_id'] ? '***set***':'***empty***' ]);
    $send = $this->api->send_text($phone, $assistant, $creds);
        if (is_wp_error($send)){
            $code = $send->get_error_code();
            $data = $send->get_error_data();
            aichat_wa_log_debug('WA send error', [ 'code'=>$code, 'message'=>$send->get_error_message() ]);
            if ($code === 'wa_token_expired' || $code === 'wa_token_blocked'){
                return ['error' => $send->get_error_message(), 'code' => $code];
            }
            return ['error' => $send->get_error_message(), 'details' => $data];
        }
        aichat_wa_log_debug('WA message sent OK', [ 'phone'=>$phone, 'chars'=>strlen($assistant) ]);
        // Hook post-proveedor (permite inspeccionar y mutar antes de enviar, aunque ya se envió). Sólo informativo aquí.
        do_action('aichat_wa_post_provider', [
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
            return new WP_Error('no_bot','No hay mapping para el teléfono');
        }
        $session_id = $this->ensure_session_id($phone);
    $creds = $this->repo->resolve_credentials(null, $phone);
    aichat_wa_log_debug('Manual outbound send', [ 'phone'=>$phone, 'bot_slug'=>$bot_slug, 'chars'=>strlen($text) ]);
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
            aichat_wa_log_debug('Manual outbound error', [ 'code'=>$resp->get_error_code(), 'message'=>$resp->get_error_message() ]);
        } else {
            aichat_wa_log_debug('Manual outbound ok', [ 'phone'=>$phone ]);
        }
        return $resp;
    }
}
