<?php

namespace WPMCP\Admin;

use WPMCP\Tools\Rollback_Operation;

if (! defined('ABSPATH')) {
    exit;
}

class Restore_Controller
{
    public function restore(string $operation_id): array
    {
        return (new Rollback_Operation())->handle(['operation_id' => $operation_id]);
    }

    public function handle(): void
    {
        if (! current_user_can('edit_posts') || ! check_ajax_referer('wpmcp_restore', 'nonce', false)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        wp_send_json_success($this->restore(sanitize_text_field(wp_unslash($_POST['operation_id'] ?? ''))));
    }
}
