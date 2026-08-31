<?php

namespace WPMCP\Admin;

use WPMCP\MCP\Request_Log;
use WPMCP\Tools\List_Operations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only admin screen with two tabs, so an admin has one observability
 * page instead of two:
 *  - Mutations (default): agent mutations (who/when/what) with a one-click
 *    Restore wired to the existing Restore_Controller/Rollback_Service ajax
 *    endpoint.
 *  - Requests (issue #134): the MCP request outcome log, every ability call
 *    including reads, with duration and error code, and a link straight from
 *    a row to its undo point on the History screen when the call took a
 *    snapshot.
 *
 * Gated at manage_options, matching History_Page and Restore_Controller.
 * get_operations()/get_requests() are the testable seams: they return data
 * with no HTML, mirroring how List_Operations itself is unit-testable
 * independent of render().
 */
class Audit_Log_Page
{
    public const SLUG = 'wpmcp-audit-log';

    public const TAB_MUTATIONS = 'mutations';
    public const TAB_REQUESTS  = 'requests';

    /** Rows shown on the Requests tab. */
    private const REQUEST_ROWS = 100;

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

    /**
     * Newest-first MCP request outcome rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_requests(int $limit = self::REQUEST_ROWS): array
    {
        return Request_Log::list($limit);
    }

    public function render(): void
    {
        $tab = $this->current_tab();

        echo '<div class="wrap"><h1>' . esc_html__('wpmcp: Audit Log', 'wpmcp') . '</h1>';
        $this->render_tabs($tab);

        if (self::TAB_REQUESTS === $tab) {
            $this->render_requests();
            echo '</div>';
            return;
        }

        $this->render_mutations();
        echo '</div>';
        $this->render_restore_script();
    }

    private function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation tab parameter.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        return self::TAB_REQUESTS === $tab ? self::TAB_REQUESTS : self::TAB_MUTATIONS;
    }

    private function render_tabs(string $current): void
    {
        $tabs = [
            self::TAB_MUTATIONS => __('Mutations', 'wpmcp'),
            self::TAB_REQUESTS  => __('Requests', 'wpmcp'),
        ];

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('admin.php'))),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';
    }

    private function render_mutations(): void
    {
        $filters = $this->filters_from_request();
        $ops     = $this->get_operations($filters)['operations'];
        $nonce   = wp_create_nonce('wpmcp_restore');

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
            /* translators: %d: User ID */
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

        echo '</tbody></table>';
    }

    private function render_requests(): void
    {
        $rows = $this->get_requests();

        printf(
            '<p class="description">%s</p>',
            esc_html(
                Request_Log::is_capturing_arguments()
                    ? __('Argument capture is ON: tool arguments are stored with secret-looking values redacted. Turn it off to stop recording payloads.', 'wpmcp')
                    : __('Tool arguments are not recorded. Enable the wpmcp_request_log_capture_args option or filter to capture redacted payloads while debugging.', 'wpmcp')
            )
        );

        echo '<table class="widefat"><thead><tr>'
            . '<th>' . esc_html__('When', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Tool', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Client', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Outcome', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Duration', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Undo point', 'wpmcp') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            printf(
                '<td>%s</td>',
                esc_html(gmdate('Y-m-d H:i:s', (int) ($row['timestamp'] ?? 0)))
            );
            printf('<td>%s</td>', esc_html((string) ($row['tool'] ?? '')));
            printf('<td>%s</td>', esc_html((string) ($row['client'] ?? '')));
            printf(
                '<td>%s</td>',
                empty($row['ok'])
                    /* translators: %s: Error code name */
                    ? esc_html(sprintf(__('Error: %s', 'wpmcp'), (string) ($row['error_code'] ?? '')))
                    : esc_html__('OK', 'wpmcp')
            );
            printf(
                '<td>%s</td>',
                /* translators: %d: Duration in milliseconds */
                esc_html(sprintf(__('%d ms', 'wpmcp'), (int) ($row['duration_ms'] ?? 0)))
            );
            echo '<td>';
            $this->render_undo_link((string) ($row['operation_id'] ?? ''));
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * A row that took a snapshot links to that exact operation on the History
     * screen, so an admin can go from a suspect or failed write straight to
     * the row whose Restore button undoes it.
     */
    private function render_undo_link(string $operation_id): void
    {
        if ('' === $operation_id) {
            echo '-';
            return;
        }

        printf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=wpmcp') . '#' . History_Page::row_anchor($operation_id)),
            esc_html__('View in History', 'wpmcp')
        );
    }

    /** @return array<string, mixed> */
    private function filters_from_request(): array
    {
        $filters = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters for admin audit display.
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter form preservation.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::SLUG;
        printf('<input type="hidden" name="page" value="%s" />', esc_attr($page));
        printf('<input type="hidden" name="tab" value="%s" />', esc_attr(self::TAB_MUTATIONS));
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

    private function render_restore_script(): void
    {
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
