<?php

namespace WPMCP\Admin;

use WPMCP\Tools\List_Operations;

if (! defined('ABSPATH')) {
    exit;
}

class History_Page
{
    public function render(): void
    {
        $ops = (new List_Operations())->handle(['limit' => 50])['operations'];
        echo '<div class="wrap"><h1>' . esc_html__('wpmcp — Agent History', 'wpmcp') . '</h1><table class="widefat"><thead><tr><th>Tool</th><th>Object</th><th>When</th><th></th></tr></thead><tbody>';
        $nonce = wp_create_nonce('wpmcp_restore');
        foreach ($ops as $op) {
            printf(
                '<tr><td>%s</td><td>#%d</td><td>%s</td><td><button class="button wpmcp-restore" data-op="%s" data-nonce="%s">%s</button></td></tr>',
                esc_html($op['tool_name']),
                (int) $op['object_id'],
                esc_html($op['created_at']),
                esc_attr($op['operation_id']),
                esc_attr($nonce),
                esc_html__('Restore', 'wpmcp')
            );
        }
        echo '</tbody></table></div>';
        // Inline JS: POST operation_id+nonce to ajaxurl action=wpmcp_restore, reload on success.
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpmcp-restore');
            if (! btn) {
                return;
            }
            e.preventDefault();
            var body = new URLSearchParams();
            body.set('action', 'wpmcp_restore');
            body.set('operation_id', btn.dataset.op);
            body.set('nonce', btn.dataset.nonce);
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function () { window.location.reload(); });
        });
        </script>
        <?php
    }
}
