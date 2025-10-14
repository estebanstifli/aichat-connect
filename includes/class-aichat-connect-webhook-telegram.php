<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Webhook_Telegram {
    private static $instance; 
    public static function instance(){ return self::$instance ?: (self::$instance = new self()); }
    private function __construct(){
        add_action('rest_api_init', function(){
            // Route by endpoint id (string label stored in phone)
            register_rest_route('aichat-tg/v1', '/webhook/(?P<endpoint>[^/]+)', [
                [ 'methods' => 'POST', 'callback' => [ $this, 'handle_post_by_endpoint' ], 'permission_callback' => '__return_true' ],
            ]);
        });
    }

    /* Deprecated: numeric mapping route no longer used */
    public function handle_post(WP_REST_Request $req){
        $raw = $req->get_body();
        aichat_connect_log_debug('Telegram webhook received', [ 'len'=>strlen($raw) ]);
        $mapping_id = isset($req['mapping_id']) ? (int)$req['mapping_id'] : 0;
        if ($mapping_id <= 0){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'bad_mapping' ], 400);
        }
        $map = AIChat_Connect_Repository::instance()->get_number($mapping_id);
        if (!$map || (int)$map['is_active'] !== 1){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'mapping_not_found' ], 404);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'bad_json' ], 400);
        }
        // Extract first text message
        $message = isset($json['message']) && is_array($json['message']) ? $json['message'] : null;
        if (!$message || empty($message['text'])){
            return new WP_REST_Response([ 'ok'=>true, 'ignored'=>true ], 200);
        }
        $text = (string)$message['text'];
        $chat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];
        $chat_id = isset($chat['id']) ? (string)$chat['id'] : '';
        $msg_id = isset($message['message_id']) ? (string)$message['message_id'] : ('tg_'.md5($raw));
        if ($chat_id === ''){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'no_chat_id' ], 400);
        }
    // Usar handler específico de Telegram
    $svc = AIChat_Connect_Service::instance();
    $result = $svc->handle_incoming_text_telegram($map, $chat_id, $text, $msg_id);
        if (is_array($result) && isset($result['ok'])){
            return new WP_REST_Response([ 'ok'=>true ], 200);
        }
        if (is_array($result) && isset($result['error'])){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>$result['error'] ], 500);
        }
        return new WP_REST_Response([ 'ok'=>true ], 200);
    }

    public function handle_post_by_endpoint(WP_REST_Request $req){
        $raw = $req->get_body();
        $endpoint = isset($req['endpoint']) ? (string)$req['endpoint'] : '';
        if ($endpoint === ''){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'bad_endpoint' ], 400);
        }
        $map = AIChat_Connect_Repository::instance()->get_mapping_by_channel_and_phone('telegram', $endpoint);
        if (!$map || (int)$map['is_active'] !== 1){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'mapping_not_found' ], 404);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'bad_json' ], 400);
        }
        $message = isset($json['message']) && is_array($json['message']) ? $json['message'] : null;
        if (!$message || empty($message['text'])){
            return new WP_REST_Response([ 'ok'=>true, 'ignored'=>true ], 200);
        }
        $text = (string)$message['text'];
        $chat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];
        $chat_id = isset($chat['id']) ? (string)$chat['id'] : '';
        $msg_id = isset($message['message_id']) ? (string)$message['message_id'] : ('tg_'.md5($raw));
        if ($chat_id === ''){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>'no_chat_id' ], 400);
        }
        $svc = AIChat_Connect_Service::instance();
        $result = $svc->handle_incoming_text_telegram($map, $chat_id, $text, $msg_id);
        if (is_array($result) && isset($result['ok'])){
            return new WP_REST_Response([ 'ok'=>true ], 200);
        }
        if (is_array($result) && isset($result['error'])){
            return new WP_REST_Response([ 'ok'=>false, 'error'=>$result['error'] ], 500);
        }
        return new WP_REST_Response([ 'ok'=>true ], 200);
    }
}
