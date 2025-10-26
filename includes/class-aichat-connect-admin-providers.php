<?php
if (!defined('ABSPATH')) { exit; }

class AIChat_Connect_Admin_Providers {
	private static $instance;

	public static function instance(){
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct(){
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_post_aichat_connect_save_provider', [$this, 'handle_save']);
		add_action('admin_post_aichat_connect_delete_provider', [$this, 'handle_delete']);
	}

	public function menu(){
		add_submenu_page(
			'andromeda-connect',
			__('Andromeda Connect - Providers', 'andromeda-connect'),
			__('Providers', 'andromeda-connect'),
			'manage_options',
			'andromeda-connect-providers',
			[$this, 'render_list']
		);
	}

	private function get_actions_base(){
		return admin_url('admin-post.php');
	}

	public function render_list(){
		if (!current_user_can('manage_options')) { return; }

		$repo = AIChat_Connect_Repository::instance();
		$providers = $repo->list_providers(false);
		$editing = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen, save handler validates nonce.
		if (isset($_GET['edit'])) {
			$editing = $repo->get_provider(absint(wp_unslash($_GET['edit'])));
		}

		echo '<div class="wrap aichat-wa-wrap container-fluid">';
		echo '<div class="d-flex align-items-center gap-2 mb-4">';
		echo '<h1 class="h3 m-0"><i class="bi bi-plug-fill text-success"></i> ' . esc_html__('Providers', 'andromeda-connect') . '</h1>';
		echo '</div>';

		if (isset($_GET['saved'])) {
			echo '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check2-circle me-2"></i>' . esc_html__('Saved successfully', 'andromeda-connect') . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
		}

		echo '<div class="card shadow-sm mb-4">';
		echo '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
		echo '<span class="fw-semibold"><i class="bi bi-hdd-network"></i> ' . esc_html__('Available Providers', 'andromeda-connect') . '</span>';
		echo '<span class="text-muted small">' . esc_html__('System defined', 'andromeda-connect') . '</span>';
		echo '</div>';
		echo '<div class="table-responsive">';
		echo '<table class="table table-sm table-striped table-hover align-middle mb-0">';
		echo '<thead class="table-light"><tr>';
		echo '<th>' . esc_html__('ID', 'andromeda-connect') . '</th>';
		echo '<th>' . esc_html__('Key', 'andromeda-connect') . '</th>';
		echo '<th>' . esc_html__('Name', 'andromeda-connect') . '</th>';
		echo '<th>' . esc_html__('Active', 'andromeda-connect') . '</th>';
		echo '<th>' . esc_html__('Timeout', 'andromeda-connect') . '</th>';
		echo '<th>' . esc_html__('Fast Ack', 'andromeda-connect') . '</th>';
		echo '<th class="text-end">' . esc_html__('Actions', 'andromeda-connect') . '</th>';
		echo '</tr></thead><tbody>';

		if ($providers) {
			foreach ($providers as $provider) {
				$edit_url = esc_url(add_query_arg([
					'page' => 'andromeda-connect-providers',
					'edit' => $provider['id'],
				], admin_url('admin.php')));

				echo '<tr>';
				echo '<td><span class="text-muted">' . (int) $provider['id'] . '</span></td>';
				echo '<td><code>' . esc_html($provider['provider_key']) . '</code></td>';
				echo '<td>' . esc_html($provider['name']) . '</td>';
				echo '<td>' . ((int) $provider['is_active'] ? '<span class="badge text-bg-success">' . esc_html__('Yes', 'andromeda-connect') . '</span>' : '<span class="badge text-bg-danger">' . esc_html__('No', 'andromeda-connect') . '</span>') . '</td>';
				echo '<td><span class="badge text-bg-secondary">' . (int) $provider['timeout_ms'] . ' ms</span></td>';
				echo '<td>' . ((int) $provider['fast_ack_enabled'] ? '<span class="badge text-bg-info">On</span>' : '<span class="badge text-bg-light text-muted">Off</span>') . '</td>';
				echo '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="' . $edit_url . '"><i class="bi bi-pencil-square"></i></a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> ' . esc_html__('No records', 'andromeda-connect') . '</td></tr>';
		}

		echo '</tbody></table></div></div>';

		if ($editing) {
			$row = $editing;
			echo '<div class="card shadow-sm mb-5">';
			echo '<div class="card-header py-2"><strong><i class="bi bi-pencil-square"></i> ' . esc_html__('Edit Provider', 'andromeda-connect') . '</strong></div>';
			echo '<div class="card-body">';
			echo '<form method="post" action="' . esc_url($this->get_actions_base()) . '" class="row g-3">';
			echo '<input type="hidden" name="action" value="aichat_connect_save_provider" />';
			echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '" />';
			wp_nonce_field('aichat_connect_save_provider');

			echo '<div class="col-12 col-md-4">';
			echo '<label class="form-label">' . esc_html__('Key', 'andromeda-connect') . '</label>';
			echo '<div class="form-control-plaintext"><code>' . esc_html($row['provider_key']) . '</code></div>';
			echo '</div>';

			echo '<div class="col-12 col-md-4">';
			echo '<label class="form-label">' . esc_html__('Name', 'andromeda-connect') . '</label>';
			echo '<div class="form-control-plaintext">' . esc_html($row['name']) . '</div>';
			echo '</div>';

			echo '<div class="col-12">';
			echo '<label class="form-label">' . esc_html__('Description', 'andromeda-connect') . '</label>';
			echo '<div class="small text-muted">' . nl2br(esc_html($row['description'])) . '</div>';
			echo '</div>';

			echo '<div class="col-6 col-md-2">';
			echo '<div class="form-check mt-4">';
			echo '<input class="form-check-input" type="checkbox" name="is_active" value="1" ' . checked(1, (int) $row['is_active'], false) . ' id="prov_active" />';
			echo '<label class="form-check-label" for="prov_active">' . esc_html__('Active', 'andromeda-connect') . '</label>';
			echo '</div>';
			echo '</div>';

			echo '<div class="col-6 col-md-2">';
			echo '<label class="form-label">' . esc_html__('Timeout (ms)', 'andromeda-connect') . '</label>';
			echo '<input type="number" class="form-control" name="timeout_ms" value="' . esc_attr($row['timeout_ms']) . '" min="1000" step="500" />';
			echo '</div>';

			echo '<div class="col-6 col-md-2">';
			echo '<div class="form-check mt-4">';
			echo '<input class="form-check-input" type="checkbox" name="fast_ack_enabled" value="1" ' . checked(1, (int) $row['fast_ack_enabled'], false) . ' id="prov_fastack" />';
			echo '<label class="form-check-label" for="prov_fastack">' . esc_html__('Fast Ack', 'andromeda-connect') . '</label>';
			echo '</div>';
			echo '</div>';

			echo '<div class="col-12 col-md-6">';
			echo '<label class="form-label">' . esc_html__('Fast Ack Message', 'andromeda-connect') . '</label>';
			echo '<input type="text" class="form-control" name="fast_ack_message" value="' . esc_attr($row['fast_ack_message']) . '" />';
			echo '</div>';

			echo '<div class="col-12 col-md-4">';
			echo '<label class="form-label">' . esc_html__('Timeout Action', 'andromeda-connect') . '</label>';
			echo '<select name="on_timeout_action" class="form-select">';
			$actions = [
				'silent' => esc_html__('Silent', 'andromeda-connect'),
				'fast_ack_followup' => esc_html__('Fast Ack then followup', 'andromeda-connect'),
				'fallback_message' => esc_html__('Send fallback message', 'andromeda-connect'),
			];
			foreach ($actions as $key => $label) {
				echo '<option value="' . esc_attr($key) . '" ' . selected($row['on_timeout_action'], $key, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo '</div>';

			echo '<div class="col-12 col-md-8">';
			echo '<label class="form-label">' . esc_html__('Fallback Message', 'andromeda-connect') . '</label>';
			echo '<input type="text" class="form-control" name="fallback_message" value="' . esc_attr($row['fallback_message']) . '" />';
			echo '</div>';

			$meta_arr = [];
			if (!empty($row['meta'])) {
				$decoded = json_decode($row['meta'], true);
				if (is_array($decoded)) {
					$meta_arr = $decoded;
				}
			}

			$aipkit_api_key_val = isset($meta_arr['aipkit_api_key']) ? $meta_arr['aipkit_api_key'] : '';
			$aipkit_hist_enabled = isset($meta_arr['aipkit_history_enabled']) ? (int) $meta_arr['aipkit_history_enabled'] : 1;
			$aipkit_hist_limit = isset($meta_arr['aipkit_history_limit']) ? (int) $meta_arr['aipkit_history_limit'] : 12;

			echo '<div class="col-12 col-md-6">';
			echo '<label class="form-label">' . esc_html__('AIPKit API Key', 'andromeda-connect') . '</label>';
			echo '<input type="text" class="form-control" name="aipkit_api_key" value="' . esc_attr($aipkit_api_key_val) . '" placeholder="(optional)" />';
			echo '<div class="form-text">' . esc_html__('Only if you enabled an API key in AIPKit. Stored inside the provider meta JSON.', 'andromeda-connect') . '</div>';
			echo '</div>';

			echo '<div class="col-12 col-md-3">';
			echo '<label class="form-label">' . esc_html__('Conversation Memory', 'andromeda-connect') . '</label>';
			echo '<div class="form-check form-switch">';
			echo '<input class="form-check-input" type="checkbox" value="1" name="aipkit_history_enabled" id="aipkit_hist_en" ' . checked(1, $aipkit_hist_enabled, false) . ' />';
			echo '<label class="form-check-label" for="aipkit_hist_en">' . esc_html__('Enabled', 'andromeda-connect') . '</label>';
			echo '</div>';
			echo '<div class="form-text">' . esc_html__('If enabled, previous messages are sent to AIPKit for context.', 'andromeda-connect') . '</div>';
			echo '</div>';

			echo '<div class="col-12 col-md-3">';
			echo '<label class="form-label">' . esc_html__('History Limit', 'andromeda-connect') . '</label>';
			echo '<input type="number" class="form-control" name="aipkit_history_limit" value="' . esc_attr($aipkit_hist_limit) . '" min="1" max="50" />';
			echo '<div class="form-text">' . esc_html__('Maximum previous pairs (user plus assistant) to include.', 'andromeda-connect') . '</div>';
			echo '</div>';

			echo '<div class="col-12">';
			echo '<label class="form-label">' . esc_html__('Meta (JSON)', 'andromeda-connect') . '</label>';
			echo '<textarea name="meta" rows="4" class="form-control" placeholder="{ }">' . esc_textarea(is_string($row['meta']) ? $row['meta'] : '') . '</textarea>';
			echo '<div class="form-text">' . esc_html__('Manual edits allowed. Values from the form are merged on save.', 'andromeda-connect') . '</div>';
			echo '</div>';

			echo '<div class="col-12">';
			echo '<button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> ' . esc_html__('Save changes', 'andromeda-connect') . '</button>';
			echo ' <a href="' . esc_url(admin_url('admin.php?page=andromeda-connect-providers')) . '" class="btn btn-outline-secondary"><i class="bi bi-x"></i> ' . esc_html__('Cancel', 'andromeda-connect') . '</a>';
			echo '</div>';

			echo '</form>';
			echo '</div></div>';
		}

		echo '</div>';
	}

	public function handle_save(){
		if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }

		check_admin_referer('aichat_connect_save_provider');

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized below.
		$id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
		if (!$id) {
			wp_safe_redirect(admin_url('admin.php?page=andromeda-connect-providers'));
			exit;
		}

		$existing = AIChat_Connect_Repository::instance()->get_provider($id);
		if (!$existing) {
			wp_safe_redirect(admin_url('admin.php?page=andromeda-connect-providers'));
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Prepared for sanitize later.
		$raw_meta_input = sanitize_textarea_field( wp_unslash(isset($_POST['meta']) ? $_POST['meta'] : $existing['meta']) );
		$decoded_meta = [];
		if ($raw_meta_input) {
			$tmp = json_decode($raw_meta_input, true);
			if (is_array($tmp)) {
				$decoded_meta = $tmp;
			}
		}

		if (isset($_POST['aipkit_api_key'])) {
			$api_key = trim(wp_unslash($_POST['aipkit_api_key']));
			if ($api_key !== '') {
				$decoded_meta['aipkit_api_key'] = sanitize_text_field($api_key);
			} else {
				unset($decoded_meta['aipkit_api_key']);
			}
		}

		$decoded_meta['aipkit_history_enabled'] = isset($_POST['aipkit_history_enabled']) ? 1 : 0;
		if (isset($_POST['aipkit_history_limit'])) {
			$limit = (int) wp_unslash($_POST['aipkit_history_limit']);
			if ($limit < 1) { $limit = 1; }
			if ($limit > 50) { $limit = 50; }
			$decoded_meta['aipkit_history_limit'] = $limit;
		}

		$data = [
			'id' => $id,
			'provider_key' => $existing['provider_key'],
			'name' => $existing['name'],
			'description' => $existing['description'],
			'is_active' => isset($_POST['is_active']) ? 1 : 0,
			'timeout_ms' => max(500, (int) wp_unslash(isset($_POST['timeout_ms']) ? $_POST['timeout_ms'] : $existing['timeout_ms'])),
			'fast_ack_enabled' => isset($_POST['fast_ack_enabled']) ? 1 : 0,
			'fast_ack_message' => sanitize_text_field(wp_unslash(isset($_POST['fast_ack_message']) ? $_POST['fast_ack_message'] : $existing['fast_ack_message'])),
			'on_timeout_action' => sanitize_text_field(wp_unslash(isset($_POST['on_timeout_action']) ? $_POST['on_timeout_action'] : $existing['on_timeout_action'])),
			'fallback_message' => sanitize_text_field(wp_unslash(isset($_POST['fallback_message']) ? $_POST['fallback_message'] : $existing['fallback_message'])),
			'meta' => $decoded_meta ? wp_json_encode($decoded_meta) : '',
		];

		AIChat_Connect_Repository::instance()->upsert_provider($data);

		wp_safe_redirect(admin_url('admin.php?page=andromeda-connect-providers&saved=1'));
		exit;
	}

	public function handle_delete(){
		if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }

		// Provider records cannot be deleted from the UI; keep route for completeness.
		wp_safe_redirect(admin_url('admin.php?page=andromeda-connect-providers'));
		exit;
	}
}

