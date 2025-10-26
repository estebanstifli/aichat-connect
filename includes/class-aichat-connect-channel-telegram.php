<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Channel_Telegram {
    private static $instance;
    public static function instance(){ return self::$instance ?: (self::$instance = new self()); }
    private function __construct(){}

    public function send_text($chat_id, $text, $bot_token){
        if (!$chat_id || !$bot_token || $text===''){
            return new WP_Error('telegram_missing_args','Missing chat_id, token or text');
        }
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($bot_token));
        $body = [
            'chat_id' => $chat_id,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode($body),
            'timeout' => 20,
            'user-agent' => 'andromeda-connect-Telegram/1.0; ' . home_url(),
        ];
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)){
            return new WP_Error('telegram_http_error', $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok'])){
            return new WP_Error('telegram_bad_response', 'HTTP '.$code.' body: '.substr(wp_remote_retrieve_body($resp),0,300));
        }
        return $json;
    }
}

