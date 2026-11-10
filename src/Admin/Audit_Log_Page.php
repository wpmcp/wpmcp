<?php

namespace WPMCP\Admin;

use WPMCP\MCP\Request_Log;
use WPMCP\Plugin;
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
 *    snapshot. Filterable by date, user, tool and outcome, with a redacted
 *    CSV export of the filtered rows (issue #303).
 *
 * Gated at the management capability, matching History_Page and Restore_Controller.
 * get_operations()/get_requests() are the testable seams: they return data
 * with no HTML, mirroring how List_Operations itself is unit-testable
 * independent of render().
 */
class Audit_Log_Page
{
    public const SLUG = 'wpmcp-audit-log';

    public const TAB_MUTATIONS = 'mutations';
    public const TAB_REQUESTS  = 'requests';

    /** Undo points per page on the Mutations tab (issue #759). */
    public const MUTATIONS_PER_PAGE = 20;

    /** Request rows per page on the Requests tab (issue #770). */
    public const REQUESTS_PER_PAGE = 20;

    /** admin-post action and nonce action of the request log CSV export. */
    public const EXPORT_ACTION = 'wpmcp_export_request_log';

    /** Query-string filters the Requests tab and its export accept. */
    private const REQUEST_FILTERS = [ 'date_from', 'date_to', 'user_id', 'tool', 'outcome' ];

    /**
     * Test seam: receives the CSV the export would stream, so a test can run
     * the export through its real admin-post hook (issue #587) without the
     * exit. Guarded by WPMCP_TESTING.
     *
     * @var callable|null
     */
    private static $sender_for_tests = null;

    /** Set or clear the export's test sender. Ignored outside WPMCP_TESTING. */
    public static function send_for_tests(?callable $sender): void
    {
        self::$sender_for_tests = defined('WPMCP_TESTING') && WPMCP_TESTING ? $sender : null;
    }

    /**
     * @param array<string, mixed> $filters Same shape as wpmcp/list-operations'
     *                                       input_schema (user_id, tool_name,
     *                                       domain, object_type, object_id,
     *                                       date_from, date_to, limit,
     *                                       offset).
     */
    public function get_operations(array $filters): array
    {
        return (new List_Operations())->handle($filters);
    }

    /**
     * Newest-first MCP request outcome rows matching $filters (see
     * Request_Log::query()), $limit of them from $offset.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function get_requests(int $limit = self::REQUESTS_PER_PAGE, array $filters = [], int $offset = 0): array
    {
        return Request_Log::query($filters, $limit, $offset);
    }

    /**
     * admin-post handler: the filtered request log as a redacted CSV
     * download. Needs the management capability and the export nonce. $sender is the
     * test seam; it receives the CSV and file name instead of the response.
     *
     * @return \WP_Error|null
     */
    public function export_requests(?callable $sender = null)
    {
        $nonce      = is_string($_GET['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $authorized = current_user_can(Management_Access::CAPABILITY)
            && '' !== $nonce
            && wp_verify_nonce($nonce, self::EXPORT_ACTION);

        if (! $authorized) {
            if (null !== $sender) {
                return new \WP_Error('wpmcp_forbidden', __('You are not allowed to export the request log.', 'wpmcp'));
            }
            wp_die(esc_html__('You are not allowed to export the request log.', 'wpmcp'), 403);
        }

        $csv      = Request_Log::to_csv(Request_Log::query($this->request_filters(), Request_Log::cap()));
        $filename = 'wpmcp-request-log-' . gmdate('Ymd-His') . '.csv';

        // Only the download is diverted for a hook-level test; a refusal
        // above still dies with the 403 the real request gets.
        if (null === $sender && defined('WPMCP_TESTING') && WPMCP_TESTING) {
            $sender = self::$sender_for_tests;
        }
        if (null !== $sender) {
            $sender($csv, $filename);
            return null;
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a text/csv attachment built by Request_Log::to_csv(), redacted and formula-safe; HTML escaping would corrupt it.
        echo $csv;
        exit;
    }

    public function render(): void
    {
        $tab = $this->current_tab();

        echo '<div class="wrap"><h1>' . esc_html(Plugin::page_title(_x('Audit Log', 'admin menu', 'wpmcp'))) . '</h1>';
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection on an admin list screen; the value only picks which list renders.
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
        $filters  = $this->filters_from_request();
        $per_page = self::MUTATIONS_PER_PAGE;
        $paged    = $this->current_page();

        // The rows and the total come from List_Operations, which scopes
        // both to the caller's own undo points below manage_options (#461);
        // the screen itself is gated at the management capability.
        $result = $this->get_operations($filters + ['limit' => $per_page, 'offset' => ($paged - 1) * $per_page]);
        $total  = (int) $result['total_count'];
        $pages  = max(1, (int) ceil($total / $per_page));
        if ($paged > $pages) {
            // A page past the end (rows undone or filtered away since the
            // link was made) shows the last page instead of an empty table.
            $paged  = $pages;
            $result = $this->get_operations($filters + ['limit' => $per_page, 'offset' => ($paged - 1) * $per_page]);
        }
        $ops   = $result['operations'];
        $nonce = wp_create_nonce('wpmcp_restore');

        $this->render_filter_form($filters);
        $this->render_pagination(self::TAB_MUTATIONS, $filters, $total, $paged, $pages, 'top');
        echo '<table class="widefat"><thead><tr>'
            . '<th>' . esc_html__('Who', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('When', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('What', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Domain', 'wpmcp') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        foreach ($ops as $op) {
            $user  = get_userdata((int) $op['user_id']);
            /* translators: %d: numeric user ID. */
            $user_label = __('User #%d', 'wpmcp');
            $who   = $user ? $user->display_name : sprintf($user_label, (int) $op['user_id']);
            $object = List_Operations::object_label($op);
            $what   = '' !== $object ? sprintf('%s (%s)', $op['tool_name'], $object) : (string) $op['tool_name'];

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
            } elseif (! empty($op['rolled_back_at'])) {
                // Already undone (issue #593): no second Restore.
                echo esc_html(History_Page::undone_label((string) $op['rolled_back_at']));
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        $this->render_pagination(self::TAB_MUTATIONS, $filters, $total, $paged, $pages, 'bottom');
    }

    /** The requested page of either tab, 1 or more. */
    private function current_page(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page number on an admin list screen; it only picks which rows render.
        $paged = isset($_GET['paged']) && is_scalar($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1;
        return max(1, $paged);
    }

    /**
     * The list-table style "N items" count and page links (issues #759 and
     * #770). Each link keeps the tab and the filters, so paging never drops
     * them.
     *
     * @param array<string, mixed> $filters
     */
    private function render_pagination(string $tab, array $filters, int $total, int $paged, int $pages, string $which): void
    {
        printf('<div class="tablenav %s"><div class="tablenav-pages%s">', esc_attr($which), $pages > 1 ? '' : ' one-page');
        printf(
            '<span class="displaying-num">%s</span>',
            /* translators: %s: number of rows (undo points or requests). */
            esc_html(sprintf(_n('%s item', '%s items', $total, 'wpmcp'), number_format_i18n($total)))
        );

        if ($pages > 1) {
            $base = add_query_arg(
                array_map('rawurlencode', array_merge(['page' => self::SLUG, 'tab' => $tab], array_map('strval', $filters))),
                admin_url('admin.php')
            );
            $links = paginate_links([
                'base'      => add_query_arg('paged', '%#%', $base),
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages,
                'prev_text' => __('&lsaquo; Previous', 'wpmcp'),
                'next_text' => __('Next &rsaquo;', 'wpmcp'),
            ]);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() escapes its own URLs and the link text above is translator-provided entities.
            echo ' <span class="pagination-links">' . $links . '</span>';
        }

        echo '</div><br class="clear" /></div>';
    }

    private function render_requests(): void
    {
        $filters  = $this->request_filters();
        $per_page = self::REQUESTS_PER_PAGE;
        $paged    = $this->current_page();

        // Every stored request is reachable through the pages (issue #770);
        // the CSV export keeps taking the whole filtered log in one go.
        $total = Request_Log::count($filters);
        $pages = max(1, (int) ceil($total / $per_page));
        if ($paged > $pages) {
            // A page past the end (rows pruned or filtered away since the
            // link was made) shows the last page instead of an empty table.
            $paged = $pages;
        }
        $rows = $this->get_requests($per_page, $filters, ($paged - 1) * $per_page);

        printf(
            '<p class="description">%s</p>',
            esc_html(
                Request_Log::is_capturing_arguments()
                    ? __('Argument capture is ON: tool arguments are stored with secret-looking values redacted. Turn it off to stop recording payloads.', 'wpmcp')
                    : __('Tool arguments are not recorded. Enable the wpmcp_request_log_capture_args option or filter to capture redacted payloads while debugging.', 'wpmcp')
            )
        );

        $this->render_request_filter_form($filters);
        $this->render_pagination(self::TAB_REQUESTS, $filters, $total, $paged, $pages, 'top');

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
            /* translators: %s: machine-readable error code. */
            $error_label = __('Error: %s', 'wpmcp');
            printf(
                '<td>%s</td>',
                empty($row['ok'])
                    ? esc_html(sprintf($error_label, (string) ($row['error_code'] ?? '')))
                    : esc_html__('OK', 'wpmcp')
            );
            /* translators: %d: duration in milliseconds. */
            $duration_label = __('%d ms', 'wpmcp');
            printf(
                '<td>%s</td>',
                esc_html(sprintf($duration_label, (int) ($row['duration_ms'] ?? 0)))
            );
            echo '<td>';
            $this->render_undo_link((string) ($row['operation_id'] ?? ''));
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        $this->render_pagination(self::TAB_REQUESTS, $filters, $total, $paged, $pages, 'bottom');
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

    /** @return array<string, string> the Requests tab filters present in the query string */
    private function request_filters(): array
    {
        $filters = [];
        foreach (self::REQUEST_FILTERS as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter; the export verifies its own nonce before using these.
            $value = isset($_GET[ $key ]) ? sanitize_text_field(wp_unslash($_GET[ $key ])) : '';
            if ('' !== $value) {
                $filters[ $key ] = $value;
            }
        }
        return $filters;
    }

    /** @param array<string, string> $filters */
    private function render_request_filter_form(array $filters): void
    {
        echo '<form method="get">';
        printf('<input type="hidden" name="page" value="%s" />', esc_attr(self::SLUG));
        printf('<input type="hidden" name="tab" value="%s" />', esc_attr(self::TAB_REQUESTS));
        printf(
            '<input type="date" name="date_from" value="%s" aria-label="%s" />',
            esc_attr($filters['date_from'] ?? ''),
            esc_attr__('From date (UTC)', 'wpmcp')
        );
        printf(
            '<input type="date" name="date_to" value="%s" aria-label="%s" />',
            esc_attr($filters['date_to'] ?? ''),
            esc_attr__('To date (UTC)', 'wpmcp')
        );
        printf(
            '<input type="number" name="user_id" placeholder="%s" value="%s" />',
            esc_attr__('User ID', 'wpmcp'),
            esc_attr($filters['user_id'] ?? '')
        );
        printf(
            '<input type="text" name="tool" placeholder="%s" value="%s" />',
            esc_attr__('Tool', 'wpmcp'),
            esc_attr($filters['tool'] ?? '')
        );
        $outcome = $filters['outcome'] ?? '';
        echo '<select name="outcome">';
        foreach ([ '' => __('Any outcome', 'wpmcp'), 'ok' => __('OK', 'wpmcp'), 'error' => __('Error', 'wpmcp') ] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($outcome, $value, false), esc_html($label));
        }
        echo '</select> ';
        printf('<button type="submit" class="button">%s</button> ', esc_html__('Filter', 'wpmcp'));
        printf(
            '<a class="button" href="%s">%s</a>',
            esc_url(wp_nonce_url(add_query_arg(array_merge([ 'action' => self::EXPORT_ACTION ], $filters), admin_url('admin-post.php')), self::EXPORT_ACTION)),
            esc_html__('Export CSV', 'wpmcp')
        );
        echo '</form>';
    }

    /** @return array<string, mixed> */
    private function filters_from_request(): array
    {
        $filters = [];
        foreach (['user_id', 'tool_name', 'domain', 'object_type', 'object_id', 'date_from', 'date_to'] as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on an admin list screen; the value only narrows what is displayed.
            $value = isset($_GET[ $key ]) ? sanitize_text_field(wp_unslash($_GET[ $key ])) : '';
            if ('' !== $value) {
                $filters[ $key ] = $value;
            }
        }
        return $filters;
    }

    /** @param array<string, mixed> $filters */
    private function render_filter_form(array $filters): void
    {
        echo '<form method="get">';
        // The submenu callback is registered only under self::SLUG (see Plugin::register_admin_menu),
        // so $_GET['page'] can never hold anything else here; echo the constant instead of
        // round-tripping the superglobal.
        printf('<input type="hidden" name="page" value="%s" />', esc_attr(self::SLUG));
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
