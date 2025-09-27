<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Admin_Providers {
    private static $instance;    
    public static function instance(){ if(!self::$instance){ self::$instance = new self(); } return self::$instance; }

    private function __construct(){
        add_action('admin_menu', [$this,'menu']);
    add_action('admin_post_aichat_connect_save_provider', [$this,'handle_save']);
    add_action('admin_post_aichat_connect_delete_provider', [$this,'handle_delete']);
    }

    public function menu(){
        // Submenú bajo el menú principal 'aichat-connect'
        add_submenu_page(
            'aichat-connect',
            'AI Chat Connect - Providers',
            'Providers',
            'manage_options',
            'aichat-connect-providers',
            [$this,'render_list']
        );
    }

    private function get_actions_base(){
        return admin_url('admin-post.php');
    }

    public function render_list(){
        if (!current_user_can('manage_options')) return;
        $repo = AIChat_Connect_Repository::instance();
        $providers = $repo->list_providers(false);
        $editing = null;
        if ( isset($_GET['edit']) ) {
            $editing = $repo->get_provider((int)$_GET['edit']);
        }
        echo '<div class="wrap aichat-wa-wrap container-fluid">';
        echo '<div class="d-flex align-items-center gap-2 mb-4">';
        echo '<h1 class="h3 m-0"><i class="bi bi-plug-fill text-success"></i> Providers</h1>';
        echo '</div>';
        if ( isset($_GET['saved']) ) {
            echo '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check2-circle me-2"></i>Guardado correctamente<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
        echo '<div class="card shadow-sm mb-4">';
        echo '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
        echo '<span class="fw-semibold"><i class="bi bi-hdd-network"></i> Providers disponibles</span>';
        echo '<span class="text-muted small">Definidos por el sistema</span>';
        echo '</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-striped table-hover align-middle mb-0">';
        echo '<thead class="table-light"><tr>';
        echo '<th>ID</th><th>Clave</th><th>Nombre</th><th>Activo</th><th>Timeout</th><th>Fast Ack</th><th class="text-end">Acciones</th>';
        echo '</tr></thead><tbody>';
        if ($providers){
            foreach ($providers as $p){
                $edit_url = esc_url(add_query_arg(['page'=>'aichat-connect-providers','edit'=>$p['id']], admin_url('admin.php')));
                // Delete deshabilitado: no hay botón de borrar.
                echo '<tr>';
                echo '<td><span class="text-muted">'.(int)$p['id'].'</span></td>';
                echo '<td><code>'.esc_html($p['provider_key']).'</code></td>';
                echo '<td>'.esc_html($p['name']).'</td>';
                echo '<td>'.((int)$p['is_active']? '<span class="badge text-bg-success">Sí</span>' : '<span class="badge text-bg-danger">No</span>').'</td>';
                echo '<td><span class="badge text-bg-secondary">'.(int)$p['timeout_ms'].' ms</span></td>';
                echo '<td>'.((int)$p['fast_ack_enabled']? '<span class="badge text-bg-info">On</span>' : '<span class="badge text-bg-light text-muted">Off</span>').'</td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="'.$edit_url.'"><i class="bi bi-pencil-square"></i></a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> Sin registros</td></tr>';
        }
        echo '</tbody></table></div></div>';

        if ($editing) {
            $row = $editing;
            echo '<div class="card shadow-sm mb-5">';
            echo '<div class="card-header py-2"><strong><i class="bi bi-pencil-square"></i> Editar Provider</strong></div>';
            echo '<div class="card-body">';
            echo '<form method="post" action="'.esc_url($this->get_actions_base()).'" class="row g-3">';
            echo '<input type="hidden" name="action" value="aichat_connect_save_provider" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';
            wp_nonce_field('aichat_connect_save_provider');
            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label">Clave</label>';
            echo '<div class="form-control-plaintext"><code>'.esc_html($row['provider_key']).'</code></div>';
            echo '</div>';
            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label">Nombre</label>';
            echo '<div class="form-control-plaintext">'.esc_html($row['name']).'</div>';
            echo '</div>';
            echo '<div class="col-12">';
            echo '<label class="form-label">Descripción</label>';
            echo '<div class="small text-muted">'.nl2br(esc_html($row['description'])).'</div>';
            echo '</div>';
            echo '<div class="col-6 col-md-2">';
            echo '<div class="form-check mt-4">';
            echo '<input class="form-check-input" type="checkbox" name="is_active" value="1" '.checked(1,(int)$row['is_active'],false).' id="prov_active" />';
            echo '<label class="form-check-label" for="prov_active">Activo</label>';
            echo '</div>';
            echo '</div>';
            echo '<div class="col-6 col-md-2">';
            echo '<label class="form-label">Timeout (ms)</label>';
            echo '<input type="number" class="form-control" name="timeout_ms" value="'.esc_attr($row['timeout_ms']).'" min="1000" step="500" />';
            echo '</div>';
            echo '<div class="col-6 col-md-2">';
            echo '<div class="form-check mt-4">';
            echo '<input class="form-check-input" type="checkbox" name="fast_ack_enabled" value="1" '.checked(1,(int)$row['fast_ack_enabled'],false).' id="prov_fastack" />';
            echo '<label class="form-check-label" for="prov_fastack">Fast Ack</label>';
            echo '</div>';
            echo '</div>';
            echo '<div class="col-12 col-md-6">';
            echo '<label class="form-label">Mensaje Fast Ack</label>';
            echo '<input type="text" class="form-control" name="fast_ack_message" value="'.esc_attr($row['fast_ack_message']).'" />';
            echo '</div>';
            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label">Acción Timeout</label>';
            echo '<select name="on_timeout_action" class="form-select">';
        $actions = [ 'silent'=>'Silencio', 'fast_ack_followup'=>'Fast Ack previo y luego followup', 'fallback_message'=>'Enviar mensaje fallback' ];
        foreach ($actions as $k=>$lbl){
                echo '<option value="'.esc_attr($k).'" '.selected($row['on_timeout_action'],$k,false).'>'.esc_html($lbl).'</option>';
        }
            echo '</select>';
            echo '</div>';
            echo '<div class="col-12 col-md-8">';
            echo '<label class="form-label">Mensaje Fallback</label>';
            echo '<input type="text" class="form-control" name="fallback_message" value="'.esc_attr($row['fallback_message']).'" />';
            echo '</div>';
            echo '<div class="col-12">';
            echo '<label class="form-label">Meta (JSON)</label>';
            echo '<textarea name="meta" rows="4" class="form-control" placeholder="{ }">'.esc_textarea(is_string($row['meta'])?$row['meta']:'').'</textarea>';
            echo '</div>';
            echo '<div class="col-12">';
            echo '<button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar cambios</button>';
            echo ' <a href="'.esc_url(admin_url('admin.php?page=aichat-connect-providers')).'" class="btn btn-outline-secondary"><i class="bi bi-x"></i> Cancelar</a>';
            echo '</div>';
            echo '</form>';
            echo '</div></div>';
        }
        echo '</div>'; // wrap end
    }

    public function handle_save(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('aichat_connect_save_provider');
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!$id) { wp_safe_redirect(admin_url('admin.php?page=aichat-connect-providers')); exit; }
        $existing = AIChat_Connect_Repository::instance()->get_provider($id);
    if (!$existing){ wp_safe_redirect(admin_url('admin.php?page=aichat-connect-providers')); exit; }
        // Solo campos configurables
        $data = [
            'id' => $id,
            'provider_key' => $existing['provider_key'],
            'name' => $existing['name'],
            'description' => $existing['description'],
            'is_active' => isset($_POST['is_active']) ? 1:0,
            'timeout_ms' => max(500, (int)($_POST['timeout_ms'] ?? $existing['timeout_ms'])),
            'fast_ack_enabled' => isset($_POST['fast_ack_enabled']) ? 1:0,
            'fast_ack_message' => sanitize_text_field($_POST['fast_ack_message'] ?? $existing['fast_ack_message']),
            'on_timeout_action' => sanitize_text_field($_POST['on_timeout_action'] ?? $existing['on_timeout_action']),
            'fallback_message' => sanitize_text_field($_POST['fallback_message'] ?? $existing['fallback_message']),
            'meta' => wp_unslash($_POST['meta'] ?? $existing['meta']),
        ];
        AIChat_Connect_Repository::instance()->upsert_provider($data);
    wp_safe_redirect( admin_url('admin.php?page=aichat-connect-providers&saved=1') );
        exit;
    }

    public function handle_delete(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        // Desactivado: no se permite borrar providers desde la UI
    wp_safe_redirect( admin_url('admin.php?page=aichat-connect-providers') );
        exit;
    }
}
