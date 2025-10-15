<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Admin {
    private static $instance;
    private $assets_loaded = false;
    public static function instance(){ if(!self::$instance){ self::$instance = new self(); } return self::$instance; }
    private function __construct(){
        // Defensive guard: in algunos entornos la inclusión del repositorio puede no haberse ejecutado aún
        // (subida incompleta, rename del archivo principal, instancia prematura). Aseguramos su carga.
        if ( ! class_exists('AIChat_Connect_Repository') && defined('AICHAT_CONNECT_DIR') ) {
            $repo_file = AICHAT_CONNECT_DIR . 'includes/class-aichat-connect-repository.php';
            if ( file_exists( $repo_file ) ) {
                require_once $repo_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
            }
        }
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'register_settings']);
    add_action('admin_post_aichat_connect_save_number', [$this,'handle_save_number']);
    add_action('admin_post_aichat_connect_delete_number', [$this,'handle_delete_number']);
        add_action('admin_enqueue_scripts', [$this,'enqueue_assets']);
    add_action('wp_ajax_aichat_connect_list_bots', [$this,'ajax_list_bots']);
    add_action('wp_ajax_nopriv_aichat_connect_list_bots', [$this,'ajax_list_bots']);
        // Eliminado: ensure_default_exists() (mapeo por defecto)
    // add_action('admin_init', function(){ AIChat_Connect_Repository::instance()->ensure_default_exists(); });
    }

    private function is_plugin_screen(){
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, capability check gates actions.
        $page = isset($_GET['page']) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        return in_array($page, [
            'aichat-connect',            // Mapeos
            'aichat-connect-logs',
            'aichat-connect-logs-detail',
            'aichat-connect-providers'
        ], true);
    }

    public function enqueue_assets($hook){
        if (!$this->is_plugin_screen()) return;
        if ($this->assets_loaded) return;

    // Use defined constant instead of hardcoded legacy main file name
    $base = AICHAT_CONNECT_URL;

        wp_enqueue_style(
            'aichat-connect-bootstrap',
            $base . 'assets/vendor/bootstrap/css/bootstrap.min.css',
            [],
            '5.3.3'
        );

        wp_enqueue_style(
            'aichat-connect-bootstrap-icons',
            $base . 'assets/vendor/bootstrap-icons/font/bootstrap-icons.css',
            ['aichat-connect-bootstrap'],
            '1.11.3'
        );

        wp_enqueue_style(
            'aichat-connect-admin',
            $base . 'assets/css/aichat-connect-admin.css',
            ['aichat-connect-bootstrap','aichat-connect-bootstrap-icons'],
            '1.0.0'
        );

        wp_enqueue_script(
            'aichat-connect-bootstrap',
            $base . 'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
            ['jquery'],
            '5.3.3',
            true
        );

        wp_enqueue_script(
            'aichat-connect-admin',
            $base . 'assets/js/aichat-connect-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        $this->assets_loaded = true;
    }

    public function menu(){
        // Menú principal apuntando a Mapeos (gestión de phone IDs → bots)
        add_menu_page(
            __('AI Chat Connect','aichat-connect'),
            __('AI Chat Connect','aichat-connect'),
            'manage_options',
            'aichat-connect',
            [$this,'render_mappings'],
            'dashicons-whatsapp',
            80
        );
        // Submenú Mapeos (alias explícito) para claridad
        add_submenu_page(
            'aichat-connect',
            __('AI Chat Connect - Mappings','aichat-connect'),
            __('Mappings','aichat-connect'),
            'manage_options',
            'aichat-connect',
            [$this,'render_mappings']
        );
        // Logs
        add_submenu_page(
            'aichat-connect',
            __('AI Chat Connect - Logs','aichat-connect'),
            __('Logs','aichat-connect'),
            'manage_options',
            'aichat-connect-logs',
            [$this,'render_logs']
        );
        // Detalle de logs oculto
        add_submenu_page(
            null,
            __('AI Chat Connect - Logs (detail)','aichat-connect'),
            '__HIDDEN__',
            'manage_options',
            'aichat-connect-logs-detail',
            [$this,'render_logs_detail']
        );
        remove_submenu_page('aichat-connect','aichat-connect-logs-detail');
    }

    public function register_settings(){
        // Global settings removed: tokens and verify token are now per mapping.
    }

    /* ================= Sanitization Callbacks (PluginCheck) ================= */
    public static function sanitize_access_token( $value ){
        // Trim whitespace, remove control chars, keep printable ASCII + extended utf-8.
        if ( ! is_string( $value ) ) { return ''; }
        $value = trim( $value );
        // Tokens de Meta suelen ser largos, limitamos a 255 para DB/options seguridad.
        if ( strlen( $value ) > 255 ) { $value = substr( $value, 0, 255 ); }
        // Eliminamos caracteres de control invisibles.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return $value;
    }

    public static function sanitize_phone_id( $value ){
        if ( ! is_string( $value ) ) { return ''; }
        $value = preg_replace('/[^0-9]/', '', $value); // Solo dígitos
        // Longitud típica 10-20, limitamos a 30 por seguridad.
        if ( strlen( $value ) > 30 ) { $value = substr( $value, 0, 30 ); }
        return $value;
    }

    public static function sanitize_verify_token( $value ){
        if ( ! is_string( $value ) ) { return ''; }
        $value = trim( $value );
        // Permitimos alfanumérico + guiones y subrayados; eliminamos lo demás.
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
        if ( strlen( $value ) > 64 ) { $value = substr( $value, 0, 64 ); }
        return $value;
    }

    public function render_mappings(){
        if (!current_user_can('manage_options')) return;
    $repo = AIChat_Connect_Repository::instance();
        $numbers = $repo->list_numbers();
        echo '<div class="wrap aichat-wa-wrap container-fluid">';
        echo '<div class="d-flex align-items-center mb-4 gap-2">';
        echo '<h1 class="h3 m-0"><i class="bi bi-diagram-3 text-success"></i> '.esc_html__('Phone ID → Bot Mappings','aichat-connect').'</h1>';
        echo '</div>';

        // Mensajes de estado
        if ( isset($_GET['updated']) ){
            echo '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>'.esc_html__('Saved successfully','aichat-connect').'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }

        // Tabla mapeos
        echo '<div class="card mb-5 shadow-sm">';
        echo '<div class="card-header d-flex justify-content-between align-items-center py-2">';
    echo '<span class="fw-semibold"><i class="bi bi-link-45deg"></i> '.esc_html__('Phone ID → Bot Mappings','aichat-connect').'</span>';
    echo '<a href="#aichat-wa-form" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> '.esc_html__('Add','aichat-connect').'</a>';
        echo '</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-striped table-hover align-middle mb-0">';
        echo '<thead class="table-light"><tr>';
    echo '<th>'.esc_html__('Channel','aichat-connect').'</th><th>'.esc_html__('Endpoint ID','aichat-connect').'</th><th>'.esc_html__('Bot','aichat-connect').'</th><th>'.esc_html__('Display','aichat-connect').'</th><th>'.esc_html__('Active','aichat-connect').'</th><th>'.esc_html__('Specific Token','aichat-connect').'</th><th class="text-end">'.esc_html__('Actions','aichat-connect').'</th>';
        echo '</tr></thead><tbody>';
        if ($numbers){
            foreach($numbers as $n){
                $edit_url = wp_nonce_url(
                    add_query_arg(['page'=>'aichat-connect','edit'=>$n['id']], admin_url('admin.php')),
                    'aichat_connect_edit_'.$n['id']
                );
                $del_url = wp_nonce_url(
                    admin_url('admin-post.php?action=aichat_connect_delete_number&id='.(int)$n['id']),
                    'aichat_connect_delete_'.$n['id']
                );
                $chan = isset($n['channel']) ? $n['channel'] : 'whatsapp';
                echo '<tr>';
                echo '<td><span class="badge text-bg-light border">'.esc_html($chan).'</span></td>';
                echo '<td><code>'.esc_html($n['phone']).'</code></td>';
                echo '<td><span class="badge text-bg-secondary">'.esc_html($n['bot_slug']).'</span></td>';
                echo '<td>'.esc_html($n['display_name']).'</td>';
                echo '<td>'.($n['is_active'] ? '<span class="badge text-bg-success">'.esc_html__('Yes','aichat-connect').'</span>' : '<span class="badge text-bg-danger">'.esc_html__('No','aichat-connect').'</span>').'</td>';
                echo '<td>'.($n['access_token']? '<i class="bi bi-key-fill text-warning" title="'.esc_attr__('Has token','aichat-connect').'"></i>' : '<span class="text-muted">—</span>').'</td>';
                echo '<td class="text-end">';
                echo '<a class="btn btn-sm btn-outline-primary me-1" href="'.esc_url($edit_url).'"><i class="bi bi-pencil-square"></i></a>';
                echo '<a class="btn btn-sm btn-outline-danger" href="'.esc_url($del_url).'" onclick="return confirm(\''.esc_js(__('Delete mapping?','aichat-connect')).'\')"><i class="bi bi-trash"></i></a>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> '.esc_html__('No mappings','aichat-connect').'</td></tr>';
        }
        echo '</tbody></table></div></div>';

        // Formulario
        $editing = null;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only edit form load; protected by nonce.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unsplash + cast applied; nonce verified below.
    $edit_id = isset($_GET['edit']) ? (int) wp_unslash($_GET['edit']) : 0;
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce token read; verified with wp_verify_nonce.
    $edit_nonce = isset($_GET['_wpnonce']) ? wp_unslash( $_GET['_wpnonce'] ) : '';
    if ( $edit_id && wp_verify_nonce( $edit_nonce, 'aichat_connect_edit_' . $edit_id ) ){
            $editing = $repo->get_number((int)$edit_id);
        }
        $defaults = [
            'phone' => '',
            'channel' => 'whatsapp',
            'service' => 'aichat',
            'bot_slug' => get_option('aichat_global_bot_slug',''),
            'display_name' => '',
            'access_token' => '',
            'is_active' => 1,
        ];
        $row = $editing ? array_merge($defaults,$editing) : $defaults;

    global $wpdb; $bots_t = $wpdb->prefix.'aichat_bots';
        $current_service = $row['service'] ?: 'aichat';
        $bots = [];
        if ($current_service === 'aichat') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table name from $wpdb->prefix; admin read-only listing.
            $bots = $wpdb->get_results("SELECT slug,name FROM $bots_t WHERE is_active=1 ORDER BY name ASC", ARRAY_A);
        } elseif ($current_service === 'ai-engine') {
            $bots = $this->get_ai_engine_bots();
        }
    $providers_active = AIChat_Connect_Repository::instance()->list_providers(true);

    echo '<div class="card shadow-sm mb-5" id="aichat-wa-form">';
    echo '<div class="card-header py-2"><strong>'.( $editing
        ? '<i class="bi bi-pencil-square"></i> '.esc_html__('Edit mapping','aichat-connect')
        : '<i class="bi bi-plus-circle"></i> '.esc_html__('Add mapping','aichat-connect')
    ).'</strong></div>';
        echo '<div class="card-body">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="row g-3">';
    echo '<input type="hidden" name="action" value="aichat_connect_save_number">';
        if ($editing){ echo '<input type="hidden" name="id" value="'.(int)$row['id'].'">'; }
    wp_nonce_field('aichat_connect_save_number');

        echo '<div class="col-md-3">';
        echo '<label class="form-label">'.esc_html__('Channel','aichat-connect').'</label>';
        echo '<select name="channel" id="aichat-wa-channel" class="form-select">';
        $channels = [ 'whatsapp'=>'WhatsApp', 'telegram'=>'Telegram' /*, 'messenger'=>'Messenger', 'twilio_sms'=>'Twilio SMS'*/ ];
        foreach ($channels as $ck=>$clbl){ echo '<option value="'.esc_attr($ck).'"'.selected($row['channel'],$ck,false).'>'.esc_html($clbl).'</option>'; }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">'.esc_html__('Endpoint ID','aichat-connect').' <span class="text-danger">*</span></label>';
        $ph_ph = $row['channel']==='whatsapp' ? '123456789012345 (phone_number_id)' : ($row['channel']==='telegram' ? 'telegram-bot (free text)' : 'endpoint id');
        echo '<input type="text" class="form-control" name="phone" value="'.esc_attr($row['phone']).'" required placeholder="'.esc_attr($ph_ph).'">';
        echo '<div class="form-text">';
        echo ($row['channel']==='whatsapp' ? esc_html__('Use your Business Phone Number ID (Meta Cloud API).','aichat-connect') : esc_html__('Free label for your Telegram bot; token goes below.','aichat-connect'));
        echo '</div>';
        echo '</div>';

        echo '<div class="col-md-3">';
    echo '<label class="form-label">'.esc_html__('Provider','aichat-connect').'</label>';
        echo '<select name="service" id="aichat-wa-provider" class="form-select">';
        foreach ($providers_active as $p){
            $selected_attr = selected($current_service, $p['provider_key'], false);
            echo '<option value="'.esc_attr($p['provider_key']).'"'.($selected_attr ? ' selected="selected"' : '').'>'.esc_html($p['name']).'</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-3">';
    echo '<label class="form-label">'.esc_html__('Bot','aichat-connect').'</label>';
        echo '<select name="bot_slug" id="aichat-wa-bot" class="form-select" data-current="'.esc_attr($row['bot_slug']).'">';
        if (!empty($bots)){
            foreach($bots as $b){
                $slug = $b['slug']; $name = $b['name'];
                $selected_attr = selected($row['bot_slug'], $slug, false);
                echo '<option value="'.esc_attr($slug).'"'.($selected_attr ? ' selected="selected"' : '').'>'.esc_html($name).' ('.esc_html($slug).')</option>';
            }
        } else {
            echo '<option value="">'.esc_html__('-- select provider --','aichat-connect').'</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-4">';
    echo '<label class="form-label">'.esc_html__('Display Name','aichat-connect').'</label>';
        echo '<input type="text" class="form-control" name="display_name" value="'.esc_attr($row['display_name']).'">';
        echo '</div>';

    echo '<div class="col-md-8">';
        echo '<label class="form-label">'.esc_html__('Specific Access Token','aichat-connect').'</label>';
        $tok_ph = $row['channel']==='whatsapp' ? 'EAAG...' : ($row['channel']==='telegram' ? 'Telegram Bot Token (e.g. 123456:ABC...)' : 'Token');
        echo '<div class="input-group">';
        echo '<input type="text" class="form-control" name="access_token" value="'.esc_attr($row['access_token']).'" placeholder="'.esc_attr($tok_ph).'">';
    echo '<button class="btn btn-outline-secondary" type="button" id="aichat-toggle-token-visibility"><i class="bi bi-eye"></i> '.esc_html__('Show/Hide','aichat-connect').'</button>';
    echo '<button class="btn btn-outline-secondary" type="button" data-copy-input-name="access_token"><i class="bi bi-clipboard"></i> '.esc_html__('Copy','aichat-connect').'</button>';
        echo '</div>';
        echo '<div class="form-text">';
    echo ($row['channel']==='whatsapp' ? esc_html__('Graph access token for this WhatsApp mapping.','aichat-connect') : esc_html__('Required: Telegram Bot Token for this mapping.','aichat-connect'));
        echo '</div>';
        echo '</div>';

    // WhatsApp Verify Token (per mapping)
    echo '<div class="col-md-4" data-channel-only="whatsapp">';
    echo '<label class="form-label">'.esc_html__('Verify Token (Webhook)','aichat-connect').'</label>';
    echo '<div class="input-group">';
    echo '<input type="text" class="form-control" name="verify_token" value="'.esc_attr($row['verify_token'] ?? '').'" placeholder="my-verify-token" />';
    echo '<button class="btn btn-outline-secondary" type="button" id="aichat-generate-verify-token"><i class="bi bi-magic"></i> '.esc_html__('Generate','aichat-connect').'</button>';
    echo '<button class="btn btn-outline-secondary" type="button" data-copy-input-name="verify_token"><i class="bi bi-clipboard"></i> '.esc_html__('Copy','aichat-connect').'</button>';
    echo '</div>';
    echo '<div class="form-text">'.esc_html__('Used to validate the WhatsApp webhook (GET verification).','aichat-connect').'</div>';
    echo '</div>';

        echo '<div class="col-md-2 d-flex align-items-end">';
        echo '<div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" name="is_active" value="1" '.checked(1,(int)$row['is_active'],false).'>';
    echo '<label class="form-check-label">'.esc_html__('Active','aichat-connect').'</label>';
        echo '</div>';
        echo '</div>';

        echo '<div class="col-12">';
    submit_button($editing? __('Save changes','aichat-connect'):__('Add mapping','aichat-connect'),'primary','submit',false,['class'=>'btn btn-primary']);
        echo '</div>';
        
        echo '</form>';
    // Per-channel user guides
    echo '<div class="px-3 pb-3 mt-3">';
    // WhatsApp guide (beginner-friendly, step-by-step) — EN
    $wa_webhook = esc_url( site_url('/wp-json/aichat-wa/v1/webhook') );
    echo '<div class="alert alert-info" data-channel-only="whatsapp" style="display:none">';
    echo '<div class="fw-semibold mb-2"><i class="bi bi-whatsapp text-success me-1"></i>'.esc_html__('Guide to connect WhatsApp with your WordPress plugin','aichat-connect').'</div>';

    // Webhook URL quick reference
    echo '<div class="mb-3">'.esc_html__('Use this URL as the Callback URL in your Meta App (WhatsApp Cloud API):','aichat-connect').' <code id="aichat-wa-webhook" class="text-primary" style="user-select:all">'.esc_html($wa_webhook).'</code> <button type="button" class="btn btn-outline-secondary btn-sm ms-2" data-copy-target-id="aichat-wa-webhook"><i class="bi bi-clipboard"></i> '.esc_html__('Copy','aichat-connect').'</button></div>';

    // 1) Qué necesitas
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-1-circle me-1"></i>'.esc_html__('What you need before you start','aichat-connect').'</div>';
    echo '<ul class="mb-3 ps-3">';
    echo '<li>'.esc_html__('A Meta for Developers account (your personal Facebook/Meta account works).','aichat-connect').' <a href="https://developers.facebook.com/" target="_blank" rel="noopener">developers.facebook.com</a></li>';
    echo '<li>'.esc_html__('Create an App and add the "WhatsApp" product.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('A business phone number or the free WhatsApp Cloud API test number.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('A Meta Access Token with WhatsApp Business permissions (long-lived preferred).','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Your WordPress site must have valid HTTPS (e.g., https://your-site.com).','aichat-connect').'</li>';
    echo '</ul>';

    // 2) Crear la app y obtener datos
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-2-circle me-1"></i>'.esc_html__('Create the app and get your credentials','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('Go to Meta → Apps and click "Create App" (Business or Other).','aichat-connect').' <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a></li>';
    echo '<li>'.esc_html__('Add the WhatsApp product and click "Configure". Meta will provide a test number.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Save these values (you will need them here):','aichat-connect').'<div class="mt-1 small"><code>Phone Number ID</code>, <code>WhatsApp Business Account ID</code>, <code>Access Token</code></div></li>';
    echo '<li>'.esc_html__('If you do not have a long-lived Access Token, create one in Business Settings → System Users and grant whatsapp_business_messaging.','aichat-connect').'</li>';
    echo '</ol>';

    // 3) Configurar el plugin
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-3-circle me-1"></i>'.esc_html__('Set up the plugin in WordPress','aichat-connect').'</div>';
    echo '<ul class="mb-3 ps-3">';
    echo '<li><strong>'.esc_html__('Channel','aichat-connect').':</strong> WhatsApp</li>';
    echo '<li><strong>'.esc_html__('Endpoint ID','aichat-connect').':</strong> '.esc_html__('your Phone Number ID (e.g., 123456789012345).','aichat-connect').'</li>';
    echo '<li><strong>'.esc_html__('Specific Access Token','aichat-connect').':</strong> '.esc_html__('your Meta Access Token (for this number).','aichat-connect').'</li>';
    echo '<li><strong>'.esc_html__('Verify Token','aichat-connect').':</strong> '.esc_html__('choose any word/code (e.g., mydomain123) and remember it.','aichat-connect').'</li>';
    echo '<li><strong>'.esc_html__('Bot / Provider','aichat-connect').':</strong> '.esc_html__('select which bot/AI should answer.','aichat-connect').'</li>';
    echo '</ul>';

    // 4) Configurar el Webhook en Meta
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-4-circle me-1"></i>'.esc_html__('Configure the Webhook in Meta Developers','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('In your Meta App, go to WhatsApp → Configuration → Webhooks.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Click "Edit callback URL" or "Configure Webhook" and paste:','aichat-connect').'<div class="mt-1">';
    echo '<div>- <strong>Callback URL:</strong> <code class="text-primary" style="user-select:all">'.esc_html($wa_webhook).'</code></div>';
    echo '<div>- <strong>Verify Token:</strong> '.esc_html__('the same value you set in this mapping.','aichat-connect').'</div>';
    echo '</div></li>';
    echo '<li>'.esc_html__('Click Verify and Save. If correct, Meta will confirm.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Under "Subscriptions", enable the messages field (required).','aichat-connect').'</li>';
    echo '</ol>';

    // 5) Probar
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-5-circle me-1"></i>'.esc_html__('Test that everything works','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('In Meta, use the test number and add your phone as recipient.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('From your WhatsApp, send a message (e.g., "Hello").','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Open the plugin Logs tab: you should see the incoming message and the bot reply.','aichat-connect').'</li>';
    echo '</ol>';

    // 6) Número real (opcional)
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-6-circle me-1"></i>'.esc_html__('Connect your real number (optional)','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('In WhatsApp Manager, add your real number and verify by SMS/call.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Update in the mapping the Phone Number ID and (if needed) the Access Token.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Repeat the webhook verification if Meta requests it.','aichat-connect').'</li>';
    echo '</ol>';

    // 7) Problemas frecuentes
    echo '<div class="mb-1 fw-semibold"><i class="bi bi-7-circle me-1"></i>'.esc_html__('Common problems','aichat-connect').'</div>';
    echo '<ul class="mb-0 ps-3">';
    echo '<li><strong>400 / 403 (verification):</strong> '.esc_html__('Verify Token mismatch. Make sure the value matches in Meta and in this mapping.','aichat-connect').'</li>';
    echo '<li><strong>190 token expired:</strong> '.esc_html__('your Access Token has expired. Generate a new one or use a long-lived token.','aichat-connect').'</li>';
    echo '<li><strong>'.esc_html__('No messages received','aichat-connect').':</strong> '.esc_html__('enable the messages subscription and confirm the Phone Number ID is correct.','aichat-connect').'</li>';
    echo '<li><strong>'.esc_html__('Messages delayed','aichat-connect').':</strong> '.esc_html__('could be server latency or temporary Meta issues; check Logs.','aichat-connect').'</li>';
    echo '<li><strong>SSL / 404:</strong> '.esc_html__('your site must have valid HTTPS and the webhook URL must exist exactly as above.','aichat-connect').'</li>';
    echo '</ul>';
    echo '</div>';

    // Telegram guide (rich, dynamic) — EN
    $tg_base = esc_url( site_url('/wp-json/aichat-tg/v1/webhook/') );
    $tg_initial = $row['channel']==='telegram' && !empty($row['phone']) ? ($tg_base . rawurlencode($row['phone'])) : $tg_base;
    $tg_api_base = 'https://api.telegram.org/bot';
    $token_val = isset($row['access_token']) ? (string)$row['access_token'] : '';
    $setwebhook_initial = $tg_api_base . ( $token_val ? $token_val : 'botTOKEN' ) . '/setWebhook?url=' . $tg_initial;
    $dn = isset($row['display_name']) ? (string)$row['display_name'] : '';
    $maybe_user = ltrim($dn, '@');
    echo '<div class="alert alert-info" data-channel-only="telegram" style="display:none">';
    echo '<div class="fw-semibold mb-2"><i class="bi bi-rocket-takeoff-fill text-primary me-1"></i>'.esc_html__('Guide to connect Telegram','aichat-connect').'</div>';

    echo '<div class="mb-1 fw-semibold"><i class="bi bi-1-circle me-1"></i>'.esc_html__('Create a public bot in Telegram','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('Open Telegram and find @BotFather.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Send the command /newbot and follow the steps.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Choose a name and a username ending with "bot" (e.g., MyStoreBot).','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Copy the TOKEN BotFather gives you (you will need it below).','aichat-connect').'</li>';
    echo '</ol>';

    echo '<div class="mb-1 fw-semibold"><i class="bi bi-2-circle me-1"></i>'.esc_html__('Configure the mapping in WordPress','aichat-connect').'</div>';
    echo '<ul class="mb-3 ps-3">';
    echo '<li>'.esc_html__('Fill out the form above.','aichat-connect').'</li>';
    echo '</ul>';

    echo '<div class="mb-1 fw-semibold"><i class="bi bi-3-circle me-1"></i>'.esc_html__('Activate the webhook in Telegram','aichat-connect').'</div>';
    echo '<ol class="mb-3 ps-3">';
    echo '<li>'.esc_html__('Open your browser.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Visit:','aichat-connect').' <code id="aichat-tg-setwebhook" class="text-primary" data-tg-api-base="'.esc_attr($tg_api_base).'" data-tg-base="'.esc_attr($tg_base).'" style="user-select:all">'.esc_html($setwebhook_initial).'</code> <button type="button" class="btn btn-outline-secondary btn-sm ms-2" data-copy-target-id="aichat-tg-setwebhook"><i class="bi bi-clipboard"></i> '.esc_html__('Copy','aichat-connect').'</button> <a id="aichat-tg-open-setwebhook" href="#" class="btn btn-outline-primary btn-sm ms-2" target="_blank" rel="noopener" style="display:none"><i class="bi bi-box-arrow-up-right"></i> '.esc_html__('Open','aichat-connect').'</a></li>';
    echo '<li>'.esc_html__('You should see {"ok":true,"result":true,"description":"Webhook was set"}.','aichat-connect').'</li>';
    echo '</ol>';

    echo '<div class="mb-1 fw-semibold"><i class="bi bi-4-circle me-1"></i>'.esc_html__('Test that everything works','aichat-connect').'</div>';
    echo '<ol class="mb-0 ps-3">';
    echo '<li>'.esc_html__('Find your bot on Telegram by its username and send it a message.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Check the Logs tab in WordPress to see the received message.','aichat-connect').'</li>';
    echo '</ol>';
    if ($maybe_user !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $maybe_user)){
        echo '<div class="mt-2"><i class="bi bi-link-45deg me-1"></i><span class="fw-semibold">'.esc_html__('Public link to your bot','aichat-connect').':</span> <code class="text-primary">'.esc_html('https://t.me/'.$maybe_user).'</code></div>';
    }
    echo '</div>';
    echo '</div>'; // guides wrapper

    echo '</div></div>';

        echo '</div>'; // wrap
    }


    // AJAX: devolver lista de bots según servicio
    public function ajax_list_bots(){
        if (!current_user_can('manage_options')){ wp_send_json_error('forbidden', 403); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only AJAX endpoint gated by capability.
        $service = isset($_GET['service']) ? sanitize_text_field( wp_unslash($_GET['service']) ) : 'aichat';
        $items = [];
        if (in_array($service, ['ai-engine','aiengine','ai_engine'], true)){
            $bots = $this->get_ai_engine_bots();
            foreach ($bots as $b){ $items[] = ['value'=>$b['slug'], 'label'=>$b['name'].' ('.$b['slug'].')']; }
        } elseif ($service === 'aipkit') {
            // List AIPKit chatbots (CPT: aipkit_chatbot). 'bot_slug' mapping will store numeric ID.
            if (class_exists('WP_Query')) {
                $q = new WP_Query([
                    'post_type' => 'aipkit_chatbot',
                    'post_status' => ['publish','draft','private','pending','future'],
                    'posts_per_page' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'fields' => 'ids',
                    'suppress_filters' => false,
                ]);
                if ($q && !is_wp_error($q) && !empty($q->posts)) {
                    foreach ($q->posts as $pid) {
                        $title = get_the_title($pid);
                        $items[] = [
                            'value' => (string)$pid,
                            'label' => ($title ?: ('Chatbot '.$pid)).' ['.$pid.']'
                        ];
                    }
                }
            }
        } else {
            global $wpdb; $bots_t = $wpdb->prefix.'aichat_bots';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table name from $wpdb->prefix; admin read-only.
            $bots = $wpdb->get_results("SELECT slug, name FROM $bots_t WHERE is_active=1 ORDER BY name ASC", ARRAY_A);
            foreach ($bots as $b){ $items[] = ['value'=>$b['slug'], 'label'=>$b['name'].' ('.$b['slug'].')']; }
        }
        wp_send_json_success($items);
    }

    // Helper: obtener bots de AI Engine (post_type mwai_chatbot) + opción 'default'
    private function get_ai_engine_bots(){
        // 1) Preferir lista desde wp_options ('mwai_chatbots')
        $out = [];
        $opt = get_option('mwai_chatbots');
        if (is_array($opt)){
            $seen = [];
            foreach ($opt as $item){
                if (!is_array($item)) continue;
                $id = isset($item['botId']) ? (string)$item['botId'] : '';
                $name = isset($item['name']) ? (string)$item['name'] : '';
                if ($id === '') continue;
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $label = ($name !== '' ? $name : $id) . ' ['.$id.']';
                $out[] = [ 'slug' => $id, 'name' => $label ];
            }
            // Añadir 'default' si no estaba
            if (!isset($seen['default'])){
                array_unshift($out, [ 'slug' => 'default', 'name' => 'Default [default]' ]);
            }
            if (!empty($out)) return $out;
        }

        // 2) Fallback: intentar recuperar CPT 'mwai_chatbot'
        $out = [ [ 'slug' => 'default', 'name' => 'Default [default]' ] ];
        if (class_exists('WP_Query')){
            $args = [
                'post_type'      => 'mwai_chatbot',
                'post_status'    => ['publish','draft','private','pending','future'],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'suppress_filters' => false,
            ];
            $q = new WP_Query($args);
            if ($q && !is_wp_error($q) && !empty($q->posts)){
                foreach ($q->posts as $pid){
                    $title = get_the_title($pid);
                    $slug  = get_post_field('post_name', $pid);
                    $label = ($title ?: ('Bot '.$pid)) . ' ['.($slug ?: $pid).']';
                    $out[] = [ 'slug' => ($slug ?: (string)$pid), 'name' => $label ];
                }
                return $out;
            }
        }

        // 3) Fallback mínimo
        return $out;
    }

    public function handle_save_number(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('aichat_connect_save_number');
        $data = [
            'id' => isset($_POST['id']) ? (int)$_POST['id'] : null,
            'phone' => sanitize_text_field( wp_unslash($_POST['phone'] ?? '') ),
            'channel' => sanitize_text_field( wp_unslash($_POST['channel'] ?? 'whatsapp') ),
            'service' => sanitize_text_field( wp_unslash($_POST['service'] ?? 'aichat') ),
            'bot_slug' => sanitize_text_field( wp_unslash($_POST['bot_slug'] ?? '') ),
            'display_name' => sanitize_text_field( wp_unslash($_POST['display_name'] ?? '') ),
            'access_token' => sanitize_text_field( wp_unslash($_POST['access_token'] ?? '') ),
            'verify_token' => sanitize_text_field( wp_unslash($_POST['verify_token'] ?? '') ),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        // Eliminado: lógica de is_default y reseteo global
    $id = AIChat_Connect_Repository::instance()->upsert_number($data);
    wp_safe_redirect( admin_url('admin.php?page=aichat-connect&updated=1') );
        exit;
    }

    public function handle_delete_number(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified next line; reading id only.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unsplash + cast applied; nonce checked next.
    $id = isset($_GET['id']) ? (int) wp_unslash($_GET['id']) : 0;
    check_admin_referer('aichat_connect_delete_'.$id);
    $res = AIChat_Connect_Repository::instance()->delete_number($id);
        if (!$res){
            wp_safe_redirect( admin_url('admin.php?page=aichat-connect&error='.rawurlencode(__('Could not delete','aichat-connect'))) );
        } else {
            wp_safe_redirect( admin_url('admin.php?page=aichat-connect&deleted=1') );
        }
        exit;
    }

    // Listado agrupado por día y teléfono
    public function render_logs(){
        if (!current_user_can('manage_options')) return;
    $repo = AIChat_Connect_Repository::instance();
        $groups = $repo->list_conversation_groups(300);

        echo '<div class="wrap aichat-wa-wrap container-fluid">';
        echo '<div class="d-flex align-items-center gap-2 mb-4">';
        echo '<h1 class="h3 m-0"><i class="bi bi-chat-dots text-success"></i> WhatsApp Logs</h1>';
        echo '</div>';

        echo '<div class="card shadow-sm">';
    echo '<div class="card-header py-2"><strong><i class="bi bi-list-ul"></i> '.esc_html__('Conversations (day + phone)','aichat-connect').'</strong></div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-striped table-hover align-middle mb-0">';
        echo '<thead class="table-light"><tr>';
    echo '<th>'.esc_html__('Date','aichat-connect').'</th><th>'.esc_html__('Phone','aichat-connect').'</th><th>'.esc_html__('Incoming','aichat-connect').'</th><th>'.esc_html__('Outgoing','aichat-connect').'</th><th>'.esc_html__('Total','aichat-connect').'</th><th>'.esc_html__('Last','aichat-connect').'</th><th class="text-end">'.esc_html__('Actions','aichat-connect').'</th>';
        echo '</tr></thead><tbody>';
        if ($groups){
            foreach($groups as $g){
                $nonce = wp_create_nonce('aichat_connect_logs_view_'.$g['phone'].'_'.$g['day']);
                $url = add_query_arg([
                    'page'=>'aichat-connect-logs-detail',
                    'day'=>$g['day'],
                    'phone'=>$g['phone'],
                    '_wpnonce'=>$nonce
                ], admin_url('admin.php'));
                echo '<tr>';
                echo '<td>'.esc_html($g['day']).'</td>';
                echo '<td><code>'.esc_html($g['phone']).'</code></td>';
                echo '<td><span class="badge text-bg-info">'.(int)$g['in_count'].'</span></td>';
                echo '<td><span class="badge text-bg-success">'.(int)$g['out_count'].'</span></td>';
                echo '<td><span class="badge text-bg-secondary">'.(int)$g['total'].'</span></td>';
                echo '<td><small>'.esc_html($g['last_at']).'</small></td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="'.esc_url($url).'"><i class="bi bi-eye"></i> '.esc_html__('View','aichat-connect').'</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> '.esc_html__('No records','aichat-connect').'</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    // Detalle de conversación (por día y teléfono)
    public function render_logs_detail(){
        if (!current_user_can('manage_options')) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading GET for building view; nonce verified below.
        $day = isset($_GET['day']) ? sanitize_text_field( wp_unslash($_GET['day']) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $phone = isset($_GET['phone']) ? sanitize_text_field( wp_unslash($_GET['phone']) ) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce token read; verified immediately.
    $ok = $day && $phone && wp_verify_nonce(isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '', 'aichat_connect_logs_view_'.$phone.'_'.$day);

        echo '<div class="wrap aichat-wa-wrap container-fluid">';
        echo '<div class="d-flex align-items-center gap-2 mb-4">';
        echo '<h1 class="h3 m-0"><i class="bi bi-chat-text text-success"></i> '.esc_html__('Conversation detail','aichat-connect').'</h1>';
    echo '<a href="'.esc_url(admin_url('admin.php?page=aichat-connect-logs')).'" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> '.esc_html__('Back','aichat-connect').'</a>';
        echo '</div>';

        if (!$ok){
            echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>'.esc_html__('Invalid parameters.','aichat-connect').'</div></div>';
            return;
        }

    $repo = AIChat_Connect_Repository::instance();
        $messages = $repo->get_conversation_for_day($phone, $day);

        echo '<div class="card shadow-sm mb-4">';
        echo '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
        echo '<div><strong><i class="bi bi-telephone"></i> '.esc_html($phone).'</strong> <span class="text-muted ms-2"><i class="bi bi-calendar-event"></i> '.esc_html($day).'</span></div>';
    // translators: %d is the number of messages in the conversation for the selected day/phone.
    echo '<span class="badge text-bg-secondary">'.sprintf(esc_html__('%d messages','aichat-connect'), count($messages)).'</span>';
        echo '</div>';
        echo '<div class="card-body aichat-wa-conversation">';

        if(!$messages){
            echo '<p class="text-muted"><i class="bi bi-inbox"></i> '.esc_html__('No messages for this day.','aichat-connect').'</p>';
        } else {
            foreach($messages as $m){
                // Siempre mostramos la parte del usuario si existe
                if ($m['direction'] === 'in') {
                    $user_text = $m['user_text'] ?? '';
                    echo '<div class="aichat-wa-msg aichat-wa-in">';
                    echo '<div class="aichat-wa-meta">';
                    echo '<i class="bi bi-person me-1"></i>'.esc_html__('User','aichat-connect');
                    echo ' · <span class="text-muted">'.esc_html($m['created_at']).'</span>';
                    if (!empty($m['bot_slug'])) {
                        echo ' · <span class="badge text-bg-light border">'.esc_html($m['bot_slug']).'</span>';
                    }
                    echo '</div>';
                    echo '<div class="aichat-wa-body">'.nl2br(esc_html($user_text)).'</div>';
                    echo '</div>';
                    // Si en la MISMA fila hay respuesta del bot (modelo compacto) la mostramos como burbuja out aparte
                    if (!empty($m['bot_response'])) {
                        echo '<div class="aichat-wa-msg aichat-wa-out">';
                        echo '<div class="aichat-wa-meta">';
                        echo '<i class="bi bi-robot me-1"></i>'.esc_html__('Bot','aichat-connect');
                        echo ' · <span class="text-muted">'.esc_html($m['created_at']).'</span>';
                        if (!empty($m['bot_slug'])) {
                            echo ' · <span class="badge text-bg-light border">'.esc_html($m['bot_slug']).'</span>';
                        }
                        if (!empty($m['status'])) {
                            echo ' · <span class="badge text-bg-success">'.esc_html($m['status']).'</span>';
                        }
                        echo '</div>';
                        echo '<div class="aichat-wa-body">'.nl2br(esc_html($m['bot_response'])).'</div>';
                        echo '</div>';
                    }
                } else { // direction = out (fila independiente)
                    $bot_resp = $m['bot_response'] ?? '';
                    echo '<div class="aichat-wa-msg aichat-wa-out">';
                    echo '<div class="aichat-wa-meta">';
                    echo '<i class="bi bi-robot me-1"></i>'.esc_html__('Bot','aichat-connect');
                    echo ' · <span class="text-muted">'.esc_html($m['created_at']).'</span>';
                    if (!empty($m['bot_slug'])) {
                        echo ' · <span class="badge text-bg-light border">'.esc_html($m['bot_slug']).'</span>';
                    }
                    if (!empty($m['status'])) {
                        echo ' · <span class="badge text-bg-success">'.esc_html($m['status']).'</span>';
                    }
                    echo '</div>';
                    echo '<div class="aichat-wa-body">'.nl2br(esc_html($bot_resp)).'</div>';
                    echo '</div>';
                }
            }
        }

        echo '</div></div></div>';
    }
}
