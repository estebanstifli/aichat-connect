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
        $page = $_GET['page'] ?? '';
        return in_array($page, [
            'aichat-connect',            // Mapeos
            'aichat-connect-settings',   // Settings / Config
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
        // Submenú Settings con configuración + guía
        add_submenu_page(
            'aichat-connect',
            __('AI Chat Connect - Settings','aichat-connect'),
            __('Settings','aichat-connect'),
            'manage_options',
            'aichat-connect-settings',
            [$this,'render_settings']
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
        register_setting(
            'aichat_connect',
            'aichat_connect_access_token',
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_access_token' ],
                'show_in_rest'      => false,
            ]
        );
        register_setting(
            'aichat_connect',
            'aichat_connect_default_phone_id',
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_phone_id' ],
                'show_in_rest'      => false,
            ]
        );
        register_setting(
            'aichat_connect',
            'aichat_connect_verify_token',
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_verify_token' ],
                'show_in_rest'      => false,
            ]
        );
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
    echo '<a href="'.esc_url(admin_url('admin.php?page=aichat-connect-settings')).'" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> '.esc_html__('Settings','aichat-connect').'</a>';
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
    echo '<th>'.esc_html__('Phone ID','aichat-connect').'</th><th>'.esc_html__('Bot','aichat-connect').'</th><th>'.esc_html__('Display','aichat-connect').'</th><th>'.esc_html__('Active','aichat-connect').'</th><th>'.esc_html__('Specific Token','aichat-connect').'</th><th class="text-end">'.esc_html__('Actions','aichat-connect').'</th>';
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
                echo '<tr>';
                echo '<td><code>'.esc_html($n['phone']).'</code></td>';
                echo '<td><span class="badge text-bg-secondary">'.esc_html($n['bot_slug']).'</span></td>';
                echo '<td>'.esc_html($n['display_name']).'</td>';
                echo '<td>'.($n['is_active'] ? '<span class="badge text-bg-success">'.esc_html__('Yes','aichat-connect').'</span>' : '<span class="badge text-bg-danger">'.esc_html__('No','aichat-connect').'</span>').'</td>';
                echo '<td>'.($n['access_token']? '<i class="bi bi-key-fill text-warning" title="Tiene token"></i>' : '<span class="text-muted">—</span>').'</td>';
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
    if ( isset($_GET['edit']) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'aichat_connect_edit_'.(int)$_GET['edit'] ) ){
            $editing = $repo->get_number((int)$_GET['edit']);
        }
        $defaults = [
            'phone' => '',
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

        echo '<div class="col-md-4">';
    echo '<label class="form-label">'.esc_html__('Phone ID (Meta)','aichat-connect').' <span class="text-danger">*</span></label>';
        echo '<input type="text" class="form-control" name="phone" value="'.esc_attr($row['phone']).'" required placeholder="123456789012345">';
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
    echo '<input type="text" class="form-control" name="access_token" value="'.esc_attr($row['access_token']).'" placeholder="'.esc_attr__('Leave empty to use global','aichat-connect').'">';
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
        echo '</div></div>';

        echo '</div>'; // wrap
    }

    // Nueva página de Settings: tokens, webhook y guía.
    public function render_settings(){
        if (!current_user_can('manage_options')) return;
        $webhook = esc_url( site_url('/wp-json/aichat-wa/v1/webhook') );
    $access_token = get_option('aichat_connect_access_token','');
    $default_phone = get_option('aichat_connect_default_phone_id','');
    $verify_token = get_option('aichat_connect_verify_token','');
        echo '<div class="wrap aichat-wa-wrap container-fluid">';
        echo '<div class="d-flex align-items-center mb-4 gap-2">';
        echo '<h1 class="h3 m-0"><i class="bi bi-gear-wide-connected text-success"></i> '.esc_html__('WhatsApp Configuration','aichat-connect').'</h1>';
    echo '<a href="'.esc_url(admin_url('admin.php?page=aichat-connect')).'" class="btn btn-outline-secondary btn-sm"><i class="bi bi-diagram-3"></i> '.esc_html__('Mappings','aichat-connect').'</a>';
        echo '</div>';
        if ( isset($_GET['updated']) ) {
            echo '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>'.esc_html__('Settings saved','aichat-connect').'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
        echo '<div class="row g-4">';
        // Card Webhook Info
        echo '<div class="col-12 col-xl-6">';
        echo '<div class="card shadow-sm">';
        echo '<div class="card-header py-2"><strong><i class="bi bi-link-45deg"></i> Webhook Meta</strong></div>';
        echo '<div class="card-body small">';
    echo '<p>'.esc_html__('Use this URL in your Meta App (WhatsApp Cloud API) configuration:','aichat-connect').'</p>';
    echo '<div class="mb-2"><code style="user-select:all">'.esc_html($webhook).'</code></div>';
        echo '<ul class="mb-3 ps-3">';
    echo '<li>'.esc_html__('Method: GET (verification) and POST (messages)','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Set the Verify Token exactly as configured below.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Subscribe to the messages field of the whatsapp_business_account object.','aichat-connect').'</li>';
        echo '</ul>';
    echo '<p class="text-muted">'.esc_html__('Quick guide: 1) Create App in developers.facebook.com 2) Add WhatsApp product 3) Generate System User / Token 4) Configure Webhook with URL + Verify Token 5) Subscribe events 6) Add your number/test.','aichat-connect').'</p>';
        echo '</div></div></div>';
        // Card Settings Form
        echo '<div class="col-12 col-xl-6">';
        echo '<div class="card shadow-sm">';
    echo '<div class="card-header py-2"><strong><i class="bi bi-sliders"></i> '.esc_html__('Credentials & Settings','aichat-connect').'</strong></div>';
        echo '<div class="card-body">';
        echo '<form method="post" action="options.php" class="row g-3">';
    settings_fields('aichat_connect');
        echo '<div class="col-12">';
    echo '<label class="form-label">'.esc_html__('Access Token (Graph API)','aichat-connect').' <span class="text-danger">*</span></label>';
    echo '<input type="text" name="aichat_connect_access_token" class="form-control" value="'.esc_attr($access_token).'" placeholder="EAAG..." />';
    echo '<div class="form-text">'.esc_html__('Access token with WhatsApp Business permissions (recommended: long-lived system user).','aichat-connect').'</div>';
        echo '</div>';
        echo '<div class="col-12 col-md-6">';
    echo '<label class="form-label">'.esc_html__('Default Business Phone ID','aichat-connect').'</label>';
    echo '<input type="text" name="aichat_connect_default_phone_id" class="form-control" value="'.esc_attr($default_phone).'" placeholder="123456789012345" />';
    echo '<div class="form-text">'.esc_html__('Used if a specific mapping does not provide phone/token.','aichat-connect').'</div>';
        echo '</div>';
        echo '<div class="col-12 col-md-6">';
    echo '<label class="form-label">'.esc_html__('Verify Token','aichat-connect').'</label>';
    echo '<input type="text" name="aichat_connect_verify_token" class="form-control" value="'.esc_attr($verify_token).'" placeholder="mi-token-seguro" />';
    echo '<div class="form-text">'.esc_html__('Must match the one set in Meta to validate the webhook (GET).','aichat-connect').'</div>';
        echo '</div>';
        echo '<div class="col-12">';
    echo '<button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> '.esc_html__('Save settings','aichat-connect').'</button>';
        echo '</div>';
        echo '</form>';
        echo '</div></div></div>';
        // Info adicional (span full width row)
        echo '<div class="col-12">';
        echo '<div class="card shadow-sm">';
    echo '<div class="card-header py-2"><strong><i class="bi bi-info-circle"></i> '.esc_html__('Notes','aichat-connect').'</strong></div>';
        echo '<div class="card-body small">';
        echo '<ul class="mb-0 ps-3">';
        echo '<li>'.esc_html__('Mappings define which bot responds for each Phone Number ID (business).','aichat-connect').'</li>';
        echo '<li>'.esc_html__('Session context is generated as wa_{md5(user_phone)}.','aichat-connect').'</li>';
    echo '<li>'.esc_html__('Enable debug by defining AICHAT_CONNECT_DEBUG true in the main file to see detailed logs.','aichat-connect').'</li>';
        echo '<li>'.esc_html__('Providers manage timeouts, fast ack and fallback. Adjust them in the Providers tab.','aichat-connect').'</li>';
        echo '</ul>';
        echo '</div></div></div>';
        echo '</div>'; // row
        echo '</div>'; // wrap
    }

    // AJAX: devolver lista de bots según servicio
    public function ajax_list_bots(){
        if (!current_user_can('manage_options')){ wp_send_json_error('forbidden', 403); }
        $service = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : 'aichat';
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
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'service' => sanitize_text_field($_POST['service'] ?? 'aichat'),
            'bot_slug' => sanitize_text_field($_POST['bot_slug'] ?? ''),
            'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
            'access_token' => sanitize_text_field($_POST['access_token'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        // Eliminado: lógica de is_default y reseteo global
    $id = AIChat_Connect_Repository::instance()->upsert_number($data);
    wp_safe_redirect( admin_url('admin.php?page=aichat-connect&updated=1') );
        exit;
    }

    public function handle_delete_number(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
    echo '<a href="'.esc_url(admin_url('admin.php?page=aichat-connect-settings')).'" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Settings</a>';
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
        $day = isset($_GET['day']) ? sanitize_text_field($_GET['day']) : '';
        $phone = isset($_GET['phone']) ? sanitize_text_field($_GET['phone']) : '';
    $ok = $day && $phone && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'aichat_connect_logs_view_'.$phone.'_'.$day);

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
