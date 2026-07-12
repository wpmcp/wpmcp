<?php

namespace WPMCP\Admin;

use WPMCP\Tools\List_Operations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only admin screen listing agent mutations (who/when/what) with a
 * one-click Restore wired to the existing Restore_Controller/Rollback_Service
 * ajax endpoint. Gated at manage_options, matching History_Page and
 * Restore_Controller. get_operations() is the testable seam: it returns data
 * with no HTML, mirroring how List_Operations itself is unit-testable
 * independent of render().
 */
class Audit_Log_Page
{
    /**
     * @param array<string, mixed> $filters Same shape as wpmcp/list-operations'
     *                                       input_schema (user_id, tool_name,
     *                                       domain, object_type, object_id,
     *                                       date_from, date_to, limit).
     */
    public function get_operations(array $filters): array
    {
        return (new List_Operations())->handle($filters);
    }

    public function render(): void
    {
        $filters = $this->filters_from_request();
        $ops     = $this->get_operations($filters)['operations'];
        $nonce   = wp_create_nonce('wpmcp_restore');

        echo '<div class="wrap"><h1>' . esc_html__('wpmcp: Audit Log', 'wpmcp') . '</h1>';
        $this->render_filter_form($filters);
        echo '<table class="widefat"><thead><tr>'
            . '<th>' . esc_html__('Who', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('When', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('What', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Domain', 'wpmcp') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        foreach ($ops as $op) {
            $user  = get_userdata((int) $op['user_id']);
            $who   = $user ? $user->display_name : sprintf(__('User #%d', 'wpmcp'), (int) $op['user_id']);
            $what  = sprintf('%s (#%d)', $op['tool_name'], (int) $op['object_id']);

            echo '<tr>';
            printf('<td>%s</td>', esc_html($who));
            printf('<td>%s</td>', esc_html($op['created_at']));
            printf('<td>%s</td>', esc_html($what));
            printf('<td>%s</td>', esc_html((string) ($op['domain'] ?? '')));
            echo '<td>';
            if (! empty($op['rollback_available'])) {
                printf(
                    '<button class="button wpmcp-restore" data-op="%s" data-nonce="%s">%s</button>',
                    esc_attr($op['operation_id']),
                    esc_attr($nonce),
                    esc_html__('Restore', 'wpmcp')
                );
            }
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
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

    /** @return array<string, mixed> */
    private function filters_from_request(): array
    {
        $filters = [];
        foreach (['user_id', 'tool_name', 'domain', 'object_type', 'object_id', 'date_from', 'date_to'] as $key) {
            if (isset($_GET[ $key ]) && '' !== $_GET[ $key ]) {
                $filters[ $key ] = sanitize_text_field(wp_unslash($_GET[ $key ]));
            }
        }
        return $filters;
    }

    /** @param array<string, mixed> $filters */
    private function render_filter_form(array $filters): void
    {
        echo '<form method="get">';
        printf('<input type="hidden" name="page" value="%s" />', esc_attr((string) ($_GET['page'] ?? 'wpmcp-audit-log')));
        printf(
            '<input type="text" name="tool_name" placeholder="%s" value="%s" />',
            esc_attr__('Tool name', 'wpmcp'),
            esc_attr((string) ($filters['tool_name'] ?? ''))
        );
        printf(
            '<input type="number" name="user_id" placeholder="%s" value="%s" />',
            esc_attr__('User ID', 'wpmcp'),
            esc_attr((string) ($filters['user_id'] ?? ''))
        );
        printf(
            '<input type="date" name="date_from" value="%s" />',
            esc_attr((string) ($filters['date_from'] ?? ''))
        );
        printf(
            '<input type="date" name="date_to" value="%s" />',
            esc_attr((string) ($filters['date_to'] ?? ''))
        );
        printf('<button type="submit" class="button">%s</button>', esc_html__('Filter', 'wpmcp'));
        echo '</form>';
    }
}
