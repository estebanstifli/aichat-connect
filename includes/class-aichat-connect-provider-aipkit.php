<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Provider: AIPKit (GPT3 AI Content Generator Chatbot module)
 * Integrates with its REST endpoint POST /wp-json/aipkit/v1/chat
 * Assumptions:
 *  - The AIPKit plugin is active and exposes the route.
 *  - Mapping table stores provider service = 'aipkit'. We reuse numbers.bot_slug to hold the numeric bot_id (cast to int).
 *  - Provider meta (providers table) may contain JSON with key aipkit_api_key for remote auth.
 *
 * Returned value on success: array( 'provider' => 'aipkit', 'message' => string reply, 'raw' => full decoded body )
 * On failure: WP_Error with codes: aipkit_missing, aipkit_http_error, aipkit_bad_response
 */
class AIChat_Connect_Provider_AIPKit {
    private static $instance; 
    public static function instance(){ return self::$instance ?: (self::$instance = new self()); }
    private function __construct(){}

    /**
     * Simple detection to see if AIPKit REST is likely available.
     */
    public function is_available(){
        // We assume route exists if rest_url function present. Could perform a cached OPTIONS, but keep lightweight.
        return function_exists('rest_url');
    }

    /**
     * Perform chat call.
     * @param int|string $bot_id Numeric bot id expected by AIPKit
     * @param array $messages ChatML-like array: [ [role=>user|assistant|system, content=>...], ... ]
     * @param string|null $api_key Optional API key (included in json body as aipkit_api_key per plugin docs)
     * @param array $args Extra optional flags
     */
    public function chat($bot_id, array $messages, $api_key = null, $args = []){
        if (!$this->is_available()){
            return new WP_Error('aipkit_missing','AIPKit plugin/REST not available.');
        }
        $bot_id = is_numeric($bot_id)? (int)$bot_id : $bot_id; // allow numeric string
        if (!$bot_id){
            return new WP_Error('aipkit_bad_bot','Invalid AIPKit bot ID.');
        }
        if (empty($messages)){
            return new WP_Error('aipkit_no_messages','No messages to send to AIPKit.');
        }
        // Base payload (new endpoint no longer requires bot_id inside JSON, but we keep for legacy fallback)
        $payload = [
            'bot_id' => $bot_id,
            'messages' => $messages,
        ];
        // Allow external mutation before selecting endpoint
        $payload = apply_filters('aichat_connect_aipkit_payload', $payload, $args);

        $timeout = isset($args['timeout'])? (int)$args['timeout'] : 20; // seconds
        $headers = [ 'Content-Type' => 'application/json' ];
        if ($api_key) {
            // New spec: Bearer token header
            $headers['Authorization'] = 'Bearer '.$api_key;
        }
        // Allow header customization
        $headers = apply_filters('aichat_connect_aipkit_headers', $headers, $bot_id, $args);

    $used_endpoint = 'new';
    aichat_connect_log_debug('AIPKit request init', [ 'bot_id'=>$bot_id, 'endpoint'=>'new', 'has_api_key'=> $api_key ? 1:0, 'api_key_len'=> $api_key ? strlen($api_key):0 ]);
        $url = rest_url('aipkit/v1/chat/'.$bot_id.'/message');
        $timeout = isset($args['timeout'])? (int)$args['timeout'] : 20; // seconds
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => $timeout,
            'user-agent' => 'andromeda-connect-AIPKit/1.0; ' . home_url(),
        ]);
        if (is_wp_error($response)){
            $msg = $response->get_error_message();
            // Fallback interno: si es timeout DNS/loopback (cURL 28/6) intentamos invocar el endpoint localmente sin HTTP.
            if (strpos($msg, 'cURL error 28') !== false || strpos($msg, 'cURL error 6') !== false) {
                if (class_exists('WP_REST_Request') && function_exists('rest_do_request')) {
                    aichat_connect_log_debug('AIPKit loopback HTTP failed, using internal REST fallback', ['error'=>$msg]);
                    $req = new WP_REST_Request('POST', '/aipkit/v1/chat/'.$bot_id.'/message');
                    $req->set_body(json_encode($payload));
                    $req->set_header('content-type','application/json');
                    if ($api_key) { $req->set_header('authorization','Bearer '.$api_key); }
                    $res = rest_do_request($req);
                    if ($res && ! $res->is_error()) {
                        $data = $res->get_data();
                        if (is_array($data) && isset($data['reply'])) {
                            return [ 'provider' => 'aipkit', 'message' => (string)$data['reply'], 'raw' => $data, 'fallback' => 'internal', 'endpoint'=>$used_endpoint ];
                        }
                        return new WP_Error('aipkit_bad_response','Internal REST fallback returned unexpected structure');
                    } else {
                        $err = $res && method_exists($res,'as_error') ? $res->as_error() : null;
                        return new WP_Error('aipkit_loopback_failed', 'Loopback HTTP failed and internal REST also failed: '.($err? $err->get_error_message(): 'unknown'));
                    }
                }
            }
            return new WP_Error('aipkit_http_error', $msg);
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        // Fallback / auth handling matrix:
        //  - 2xx handled later
        //  - 401 + NO api key configured: immediately return explicit auth error (don't mislead with legacy 404)
        //  - 401 + api key present: try legacy (could be old plugin still expecting body key)
        //  - 404 or 400 (without reply) : try legacy (old route signature)
        $try_legacy = false;
        if ($code === 401 && empty($api_key)) {
            aichat_connect_log_debug('AIPKit auth required but no API key configured', ['code'=>$code]);
            return new WP_Error('aipkit_auth_required', 'AIPKit endpoint returned 401 (authentication required) and no API key is configured in provider meta.', [ 'endpoint'=>$used_endpoint ]);
        }
        if (($code === 404 || $code === 400) && strpos($body,'reply') === false) {
            $try_legacy = true;
        } elseif ($code === 401) {
            // 401 con api key -> intentar legacy (modo backward con clave en body)
            $try_legacy = true;
        }
        if ($try_legacy) {
            aichat_connect_log_debug('AIPKit switching to legacy endpoint', ['code'=>$code]);
            $used_endpoint = 'legacy';
            $legacy_url = rest_url('aipkit/v1/chat');
            $legacy_headers = [ 'Content-Type' => 'application/json' ];
            // Legacy accepted api key inside body (kept for backward compat)
            $legacy_payload = $payload;
            if ($api_key) { $legacy_payload['aipkit_api_key'] = $api_key; }
            $response = wp_remote_post($legacy_url, [
                'headers' => $legacy_headers,
                'body'    => wp_json_encode($legacy_payload),
                'timeout' => $timeout,
                'user-agent' => 'andromeda-connect-AIPKit/1.0; ' . home_url(),
            ]);
            if (is_wp_error($response)) {
                return new WP_Error('aipkit_http_error', $response->get_error_message(), [ 'endpoint'=>$used_endpoint ]);
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            aichat_connect_log_debug('AIPKit legacy response', ['code'=>$code]);
        }
        if ($code < 200 || $code >= 300){
            aichat_connect_log_debug('AIPKit HTTP non-2xx', ['code'=>$code]);
            return new WP_Error('aipkit_bad_response', 'HTTP '.$code.' body: '.substr($body,0,300), [ 'endpoint'=>$used_endpoint ]);
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['reply'])){
            return new WP_Error('aipkit_bad_response','Unexpected AIPKit response format');
        }
        $reply = is_string($json['reply'])? $json['reply'] : '';
        aichat_connect_log_debug('AIPKit success', [ 'endpoint'=>$used_endpoint, 'reply_chars'=>strlen($reply) ]);
        return [ 'provider' => 'aipkit', 'message' => $reply, 'raw' => $json, 'endpoint'=>$used_endpoint ];
    }
}

