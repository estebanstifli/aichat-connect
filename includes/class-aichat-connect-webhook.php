<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Webhook {
    private static $instance;
    public static function instance(){ if(!self::$instance){ self::$instance=new self(); } return self::$instance; }
    private function __construct(){
        add_action('rest_api_init', [$this,'register_routes']);
    }

    public function register_routes(){
        // Public webhook endpoint by design:
        // - WhatsApp (Meta Cloud API) posts updates from outside WordPress, so no auth is possible.
        // - permission_callback => __return_true is intentional and documented in readme (Security / FAQ).
        // - Verification uses a per-mapping verify token and we only echo the provided challenge when it matches.
        aichat_connect_log_debug('Registering WhatsApp webhook route', ['namespace' => 'aichat-wa/v1', 'route' => '/webhook']);
        register_rest_route('aichat-wa/v1','/webhook', [
            [
                'methods' => 'GET', // verification
                'callback' => [$this,'handle_verify'],
                'permission_callback' => '__return_true'
            ],
            [
                'methods' => 'POST',
                'callback' => [$this,'handle_incoming'],
                'permission_callback' => '__return_true'
            ]
        ]);
    }

    public function handle_verify($request){
        // Verificación: aceptamos si el token proporcionado coincide con cualquier verify_token de un mapeo activo (canal=whatsapp).
        // Meta usa normalmente hub.mode y hub.verify_token (con puntos). WP los normaliza a guiones bajos en get_param?
        // Revisamos ambas variantes por compatibilidad.
        $mode = $request->get_param('hub.mode');
        if ($mode === null) { $mode = $request->get_param('hub_mode'); }
        $token = $request->get_param('hub.verify_token');
        if ($token === null) { $token = $request->get_param('hub_verify_token'); }
        $challenge = $request->get_param('hub.challenge');
        if ($challenge === null) { $challenge = $request->get_param('hub_challenge'); }
        aichat_connect_log_debug('Webhook verify request', [
            'mode' => $mode,
            'provided_token' => $token,
            'has_challenge' => $challenge !== null,
        ]);
        $match = false;
        if ($mode === 'subscribe' && $token){
            global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from $wpdb->prefix; verification lookup.
            $cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE is_active=1 AND (channel='whatsapp' OR channel IS NULL) AND verify_token=%s", $token));
            $match = $cnt > 0;
        }
        if ($match){
            aichat_connect_log_debug('Webhook verify success');
            // Responder exactamente el challenge sin envolturas ni título (compatible con ejemplo oficial Meta).
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            status_header(200);
            // Output the challenge directly as required by Meta verification. It should be a numeric token.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Meta requires exact echo of the challenge token.
            echo $challenge;
            exit;
        }
    aichat_connect_log_debug('Webhook verify failed');
        return new WP_REST_Response('Forbidden', 403);
    }

    public function handle_incoming($request){
        $payload = $request->get_json_params();
    aichat_connect_log_debug('Incoming webhook received', [ 'raw_len' => strlen(file_get_contents('php://input')), 'has_payload' => $payload ? 1:0 ]);
        if (!$payload){
            return new WP_REST_Response(['error' => 'empty_payload'], 400);
        }
        // Navegar estructura WA
        if (!empty($payload['entry'][0]['changes'][0]['value']['messages'][0])){
            $value = $payload['entry'][0]['changes'][0]['value'];
            $msg = $value['messages'][0];
            if (($msg['type'] ?? '') === 'text'){
                $phone = $msg['from'];
                $text  = $msg['text']['body'] ?? '';
                $wa_id = $msg['id'];
                $business_id = $value['metadata']['phone_number_id'] ?? null;
                aichat_connect_log_debug('Processing inbound message', [ 'wa_id'=>$wa_id, 'from'=>$phone, 'business_id'=>$business_id, 'chars'=>strlen($text) ]);
                $svc = AIChat_Connect_Service::instance();
                $result = $svc->handle_incoming_text($phone, $text, $wa_id, $business_id);
                aichat_connect_log_debug('Service processing finished', [ 'wa_id'=>$wa_id, 'has_error'=> isset($result['error'])?1:0 ]);
                return new WP_REST_Response(['processed' => true, 'result' => $result], 200);
            }
        }
    aichat_connect_log_debug('Webhook payload ignored (no text message)');
        return new WP_REST_Response(['ignored' => true], 200);
    }
}
