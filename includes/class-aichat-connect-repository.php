<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Repository {
    private static $instance;
    public static function instance(){ if(!self::$instance){ self::$instance = new self(); } return self::$instance; }

    public function get_bot_for_phone($phone){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        return $wpdb->get_var($wpdb->prepare("SELECT bot_slug FROM $t WHERE phone=%s AND is_active=1", $phone));
    }

    public function get_mapping_by_phone($phone){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE phone=%s AND is_active=1", $phone), ARRAY_A);
    }

    public function get_mapping_by_channel_and_phone($channel, $phone){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        $channel = $channel ?: 'whatsapp';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE channel=%s AND phone=%s AND is_active=1", $channel, $phone), ARRAY_A);
    }

    /**
     * Resolución simplificada - solo phone_number_id exacto de Meta:
     * 1. Business phone_number_id mapping (valor directo del webhook)
     * 2. Global bot option (core) como último recurso
     * Returns array: [ 'bot_slug' => ?, 'match_type' => business|global|null ]
     */
    public function resolve_bot_slug($business_id, $user_phone){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        // 1. business phone_number_id directo
        if ($business_id){
            $row = $wpdb->get_row($wpdb->prepare("SELECT bot_slug, service FROM $t WHERE phone=%s AND is_active=1", $business_id), ARRAY_A);
            if ($row) {
                aichat_connect_log_debug('Repo match business id', [ 'business_id'=>$business_id, 'bot_slug'=>$row['bot_slug'], 'service'=>$row['service'] ]);
                return ['bot_slug'=>$row['bot_slug'],'service'=>$row['service'] ?? 'aichat','match_type'=>'business'];
            }
        }
        // 2. global bot (core setting) como último recurso
        $global = get_option('aichat_global_bot_slug','');
        if ($global){
            aichat_connect_log_debug('Repo using global bot fallback', [ 'bot_slug'=>$global ]);
            return ['bot_slug'=>$global,'service'=>'aichat','match_type'=>'global'];
        }
    aichat_connect_log_debug('Repo no mapping found', [ 'business_id'=>$business_id, 'user_phone'=>$user_phone ]);
        return ['bot_slug'=>null,'match_type'=>null];
    }

    public function log_message($data){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_messages';
        $wpdb->insert($t, [
            'wa_message_id' => $data['wa_message_id'] ?? uniqid('local_'),
            'phone' => $data['phone'],
            'direction' => $data['direction'],
            'bot_slug' => $data['bot_slug'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'user_text' => $data['user_text'] ?? null,
            'bot_response' => $data['bot_response'] ?? null,
            'status' => $data['status'] ?? null,
            'meta' => isset($data['meta']) ? wp_json_encode($data['meta']) : null,
        ], [
            '%s','%s','%s','%s','%s','%s','%s','%s','%s'
        ]);
        aichat_connect_log_debug('Repo logged message', [ 'direction'=>$data['direction'] ?? '', 'status'=>$data['status'] ?? '', 'bot_slug'=>$data['bot_slug'] ?? '', 'has_bot_response'=> empty($data['bot_response'])?0:1 ]);
        return $wpdb->insert_id;
    }

    public function message_exists($wa_id){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_messages';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE wa_message_id=%s", $wa_id)) > 0;
    }

    // CRUD for numbers mapping
    public function list_numbers(){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a trusted table name built from $wpdb->prefix.
        return $wpdb->get_results("SELECT * FROM $t ORDER BY phone ASC", ARRAY_A);
    }

    public function get_number($id){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    }

    public function upsert_number($data){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
    $allowed = [ 'phone','channel','bot_slug','service','display_name','access_token','is_active' ];
        $row = array_intersect_key($data, array_flip($allowed));
        if (empty($row['channel'])) { $row['channel'] = 'whatsapp'; }
        $row['updated_at'] = current_time('mysql');
        if (!empty($data['id'])){
            $wpdb->update($t, $row, ['id'=>(int)$data['id']]);
            return (int)$data['id'];
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($t, $row);
            return (int)$wpdb->insert_id;
        }
    }

    public function delete_number($id){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        return $wpdb->delete($t, ['id'=>(int)$id]) !== false;
    }

    // Resolve credentials for sending: usar phone_number_id directo + token por línea o global
    public function resolve_credentials($business_id, $user_phone){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_numbers';
        // Buscar mapeo por business_id
        if ($business_id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT phone, access_token FROM $t WHERE phone=%s AND is_active=1", $business_id), ARRAY_A);
            if ($row) {
                aichat_connect_log_debug('Repo credentials business match', [ 'business_id'=>$business_id, 'has_custom_token'=> empty($row['access_token'])?0:1 ]);
                return [
                    'phone_id' => $row['phone'], // El campo phone contiene el phone_number_id de Meta
                    'access_token' => $row['access_token'],
                ];
            }
        }
        // Fallback: primer mapeo activo de WhatsApp (canal por defecto)
        $row = $wpdb->get_row("SELECT phone, access_token FROM $t WHERE is_active=1 AND (channel='whatsapp' OR channel IS NULL) ORDER BY id ASC LIMIT 1", ARRAY_A);
        aichat_connect_log_debug('Repo credentials fallback first mapping', [ 'found' => $row ? 1:0 ]);
        return [
            'phone_id' => $row['phone'] ?? '',
            'access_token' => $row['access_token'] ?? '',
        ];
    }

    // Logs: agrupación por día y teléfono
    public function list_conversation_groups($limit = 200){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_messages';
        $limit = absint($limit) ?: 200;
        // Build SQL without user input except LIMIT (cast above). Table name is trusted.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t and $limit are sanitized.
        $sql = "SELECT DATE(created_at) AS day, phone,
                SUM(CASE WHEN direction='in' THEN 1 ELSE 0 END) AS in_count,
                SUM(CASE WHEN direction='out' THEN 1 WHEN direction='in' AND bot_response IS NOT NULL AND bot_response <> '' THEN 1 ELSE 0 END) AS out_count,
                ( SUM(CASE WHEN direction='in' THEN 1 ELSE 0 END) + SUM(CASE WHEN direction='out' THEN 1 WHEN direction='in' AND bot_response IS NOT NULL AND bot_response <> '' THEN 1 ELSE 0 END) ) AS total,
                MAX(created_at) AS last_at
                FROM $t
                GROUP BY DATE(created_at), phone
                ORDER BY day DESC, phone ASC
                LIMIT $limit";
        return $wpdb->get_results($sql, ARRAY_A);
    }

    // Logs: detalle por día y teléfono (orden cronológico)
    public function get_conversation_for_day($phone, $day){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_messages';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $t WHERE phone=%s AND DATE(created_at)=%s ORDER BY created_at ASC, id ASC",
                $phone, $day
            ),
            ARRAY_A
        );
    }

    /* ================= PROVIDERS CRUD ================= */
    public function list_providers($only_active = false){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_providers';
        $where = $only_active ? ' WHERE is_active=1' : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t and $where are controlled strings.
        return $wpdb->get_results("SELECT * FROM $t$where ORDER BY name ASC", ARRAY_A);
    }

    public function get_provider($id){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_providers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    }

    public function get_provider_by_key($key){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_providers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE provider_key=%s", $key), ARRAY_A);
    }

    public function upsert_provider($data){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_providers';
        $allowed = [
            'provider_key','name','description','is_active','timeout_ms','fast_ack_enabled','fast_ack_message',
            'on_timeout_action','fallback_message','meta'
        ];
        $row = array_intersect_key($data, array_flip($allowed));
        $row['updated_at'] = current_time('mysql');
        if (!empty($data['id'])) {
            $wpdb->update($t, $row, ['id'=>(int)$data['id']]);
            return (int)$data['id'];
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($t, $row);
            return (int)$wpdb->insert_id;
        }
    }

    public function delete_provider($id){
    global $wpdb; $t = $wpdb->prefix . 'aichat_connect_providers';
        return $wpdb->delete($t, ['id'=>(int)$id]) !== false;
    }

    public function get_recent_messages_for_phone($phone, $limit_pairs = 12){
        global $wpdb; $t = $wpdb->prefix . 'aichat_connect_messages';
        $limit_pairs = max(1, min(50, (int)$limit_pairs));
        // We fetch last 2*limit rows (approx) involving this phone ordered descending then rebuild pairs.
        // direction='in' with user_text, and assistant response can be in same row (bot_response) or separate out rows.
        // Strategy: pull last 200 rows (cap) then iterate oldest->newest building user/assistant turns.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE phone=%s ORDER BY id DESC LIMIT %d", $phone, max(100, $limit_pairs * 4)), ARRAY_A);
        if (!$rows) return [];
        $rows = array_reverse($rows);
        $history = [];
        foreach ($rows as $r){
            if ($r['direction'] === 'in' && !empty($r['user_text'])) {
                $turn = [ 'user' => $r['user_text'], 'assistant' => '' ];
                if (!empty($r['bot_response'])) { $turn['assistant'] = $r['bot_response']; }
                $history[] = $turn;
            } elseif ($r['direction'] === 'out' && !empty($r['bot_response'])) {
                // If last entry has user but no assistant yet, attach
                $last = count($history) - 1;
                if ($last >= 0 && $history[$last]['assistant'] === '') {
                    $history[$last]['assistant'] = $r['bot_response'];
                } else {
                    // Orphan assistant (manual outbound) - treat as its own turn with empty user
                    $history[] = [ 'user'=>'', 'assistant'=>$r['bot_response'] ];
                }
            }
        }
        // Keep only last N pairs
        if (count($history) > $limit_pairs){
            $history = array_slice($history, -1 * $limit_pairs);
        }
        return $history;
    }
}
