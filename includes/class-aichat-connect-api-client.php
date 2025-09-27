<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_API_Client {
    private static $instance;    
    private $graph_version;   

    private function __construct(){
        // Allow overriding Graph API version via filter if needed
        $default_version = 'v23.0';
    $this->graph_version = apply_filters('aichat_connect_graph_version', $default_version);
    }

    public static function instance(){
        if (!self::$instance){ self::$instance = new self(); }
        return self::$instance;
    }

    private function get_token(){
    return get_option('aichat_connect_access_token','');
    }

    private function get_phone_id(){
    return get_option('aichat_connect_default_phone_id','');
    }

    public function send_text($phone, $text, $overrides = []){
        $token = isset($overrides['access_token']) && $overrides['access_token'] ? $overrides['access_token'] : $this->get_token();
        $phone_id = isset($overrides['phone_id']) && $overrides['phone_id'] ? $overrides['phone_id'] : $this->get_phone_id();
        if (!$token || !$phone_id) {
            return new WP_Error('wa_config','Token o phone_id no configurados');
        }
        // Optional backoff when we recently detected expired/invalid token to avoid spamming logs
    if (get_transient('aichat_connect_token_block')){
            return new WP_Error('wa_token_blocked', 'Token marcado como inválido recientemente. Reintenta más tarde.');
        }
        aichat_connect_log_debug('Graph send init', [ 'phone'=>$phone, 'phone_id'=> $phone_id ? '***set***':'***empty***', 'chars'=>strlen($text) ]);
        $url = 'https://graph.facebook.com/' . $this->graph_version . '/' . rawurlencode($phone_id) . '/messages';
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 20,
            'body' => wp_json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => ['body' => $text]
            ])
        ];
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        if ($code >= 300) {
            // Detect expired/invalid token (Graph OAuthException code 190)
            $graph_error = is_array($body) && isset($body['error']) ? $body['error'] : null;
            if (is_array($graph_error)){
                $gcode = isset($graph_error['code']) ? (int)$graph_error['code'] : 0;
                $gsub  = isset($graph_error['error_subcode']) ? (int)$graph_error['error_subcode'] : 0;
                if ($gcode === 190){
                    // Block further attempts for 2 minutes to reduce log noise
                    set_transient('aichat_connect_token_block', 1, 2 * MINUTE_IN_SECONDS);
                    aichat_connect_log_debug('Graph token expired/invalid', [ 'phone'=>$phone, 'http_code'=>$code, 'gcode'=>$gcode, 'gsub'=>$gsub ]);
                    return new WP_Error('wa_token_expired', 'El token de WhatsApp ha expirado o es inválido (code 190)', [ 'error' => $graph_error, 'http_code' => $code ]);
                }
            }
            aichat_connect_log_debug('Graph HTTP error', [ 'phone'=>$phone, 'http_code'=>$code ]);
            return new WP_Error('wa_http', 'Error HTTP WA: ' . $code, [ 'body' => $body, 'raw' => $body_raw ]);
        }
        aichat_connect_log_debug('Graph send OK', [ 'code'=>$code ]);
        return $body;
    }
}
