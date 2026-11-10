<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor Pro form submissions (issue #654) on the plugin-data pair,
 * paid-tier, so it adds no top-level tools.
 *
 * Elementor Pro 4.3 keeps what its forms collect in three tables of its own
 * (ElementorPro\Modules\Forms\Submissions\Database\Query and its
 * migrations): {prefix}e_submissions holds one row per submission (form,
 * page, status 'new' or 'trash', is_read, referer, user, dates),
 * {prefix}e_submissions_values one row per field (submission_id, key,
 * value), and {prefix}e_submissions_actions_log one row per action that ran
 * after the submit (email, webhook, ...). A form is identified the way
 * Elementor's own submissions screen filters by it: "{post_id}_{element_id}".
 * The fields a form had when it was last submitted are kept in the
 * `__elementor_forms_snapshot` post meta (Form_Snapshot_Repository); the form
 * itself is a widget ("widgetType":"form") or an atomic form ("elType":
 * "e-form") inside the page's `_elementor_data`.
 *
 * Reads: elementor-list-forms, elementor-list-submissions,
 * elementor-get-submission and elementor-export-submissions. They read the
 * tables directly with the same filters Elementor's screen uses (status
 * all, unread, read or trash; form; search; date range), so a site without
 * a license key still answers.
 *
 * Writes (off until a site opts in with wpmcp_enable_elementor_pro_write):
 *  - elementor-update-submission-status sets status ('new' restores from
 *    the trash, 'trash' moves there) and is_read, and bumps updated_at the
 *    way Query::update_submission() does, under a db_rows before-image of
 *    that one row (undo: rollback-operation);
 *  - elementor-delete-submission permanently deletes a submission, its
 *    values and its action log, as Query::delete_submission() does, and asks
 *    for confirm:true first. Each table's rows are captured as a db_rows
 *    before-image right before they go, all in one session, so
 *    rollback-session reinserts them with their original ids.
 *
 * Every op takes manage_options, the capability Elementor Pro's own
 * submissions screen and REST controller require. Submissions are personal
 * data (names, emails, IP addresses).
 *
 * Presence is filterable (wpmcp_elementor_pro_active, default:
 * ELEMENTOR_PRO_VERSION is defined). Without Elementor Pro the ops stay
 * documented in list-operations (dependency_met:false) and answer
 * plugin_missing or plugin_inactive with the plugins screen to fix it from;
 * with Elementor Pro but no submissions tables yet (Elementor Pro creates
 * them on its next wp-admin load) they answer
 * elementor_submissions_unavailable.
 */
final class Elementor_Pro_Forms_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Elementor Pro's own submissions tables live; Elementor Pro has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_EXPORT = 1000;

    public const DEFAULT_EXPORT = 500;

    public const ENABLE_HINT = 'A site enables Elementor Pro submission writes with the wpmcp_enable_elementor_pro_write filter.';

    /** The plugin's main file under the plugins directory. */
    public const PLUGIN_FILE = 'elementor-pro/elementor-pro.php';

    /** Form_Snapshot_Repository::POST_META_KEY. */
    public const SNAPSHOT_META = '__elementor_forms_snapshot';

    /** Query::STATUS_NEW and Query::STATUS_TRASH. */
    private const STATUSES = [ 'new', 'trash' ];

    /** The status filter of Elementor's submissions screen. */
    private const LIST_STATUSES = [ 'all', 'unread', 'read', 'trash' ];

    /** A form key: "{post_id}_{element_id}". */
    private const FORM_PATTERN = '^[1-9][0-9]*_[A-Za-z0-9]{1,20}$';

    private const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

    /** The form widget's defaults (modules/forms/widgets/form.php), for settings Elementor did not store. */
    private const DEFAULT_NAME = 'New Form';

    private const DEFAULT_FIELDS = [
        [ 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name' ],
        [ 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'required' => 'true' ],
        [ 'custom_id' => 'message', 'field_type' => 'textarea', 'field_label' => 'Message' ],
    ];

    /** The widget's default submit actions with Save_To_Database's elementor_pro/forms/default_submit_actions addition. */
    private const DEFAULT_ACTIONS = [ 'email', 'save-to-database' ];

    /** Whether Elementor Pro is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_elementor_pro_active', defined('ELEMENTOR_PRO_VERSION'));
    }

    /** One of Elementor Pro's submissions tables: '' (submissions), 'values' or 'actions_log'. */
    public static function table(string $which = ''): string
    {
        global $wpdb;
        return $wpdb->prefix . 'e_submissions' . ('' === $which ? '' : '_' . $which);
    }

    /** Whether all three submissions tables are on this site. */
    public static function tables_exist(): bool
    {
        foreach ([ '', 'values', 'actions_log' ] as $which) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($which))) {
                return false;
            }
        }
        return true;
    }

    /**
     * true when Elementor Pro is loaded, otherwise plugin_inactive (its files
     * are installed) or plugin_missing, naming the plugins screen.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if ((bool) apply_filters('wpmcp_elementor_pro_active', defined('ELEMENTOR_PRO_VERSION'))) {
            return true;
        }
        $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
        $url       = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Elementor Pro is %s on this site. Install and activate Elementor Pro (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'elementor-pro', 'plugins_url' => $url ],
        ];
    }

    /**
     * presence(), then the submissions tables.
     *
     * @return true|array{code: string, message: string, data?: array}
     */
    public static function submissions_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        if (self::tables_exist()) {
            return true;
        }
        $disabled = '1' === (string) get_option('elementor_form-submissions');

        return [
            'code'    => 'elementor_submissions_unavailable',
            'message' => $disabled
                ? 'Elementor Pro form submissions are turned off on this site (Elementor > Settings > Advanced > Form Submissions), and its submissions tables do not exist.'
                : 'Elementor Pro has not created its form submissions tables on this site yet. It creates them on the next wp-admin page load; open wp-admin once, then try again.',
            'data'    => [ 'settings_url' => admin_url('admin.php?page=elementor-settings') ],
        ];
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $form   = [ 'type' => 'string', 'pattern' => self::FORM_PATTERN ];
        $filter = [
            'status' => [ 'type' => 'string', 'enum' => self::LIST_STATUSES ],
            'form'   => $form,
            'search' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
            'after'  => [ 'type' => 'string', 'pattern' => self::DATE_PATTERN ],
            'before' => [ 'type' => 'string', 'pattern' => self::DATE_PATTERN ],
        ];
        $writes = (bool) apply_filters('wpmcp_enable_elementor_pro_write', false);
        $base   = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::submissions_presence(),
        ];
        $write  = $base + [ 'enabled_by_default' => $writes, 'enable_hint' => self::ENABLE_HINT ];

        return [
            'elementor-list-forms'               => [
                'mode'         => 'read',
                'requires'     => static fn () => self::presence(),
                'objects'      => [ 'post_id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'List Elementor Pro forms found in pages, templates and popups: form key ({post_id}_{element_id}, the form filter of the other ops), page, form name, kind (widget or atomic), fields (id, type, label, required), whether it saves submissions, whether it is still on the page, and submission counts. post_id limits to one page; page and per_page count pages (max 100)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $id ] + $paging ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ] + $base,
            'elementor-list-submissions'         => $base + [
                'mode'         => 'read',
                'description'  => 'List Elementor Pro form submissions newest first: id, form key and name, page, status, read flag, main field value, referer and dates, plus counts (all, unread, read, trash). status: all (default, not trashed), unread, read or trash; form ({post_id}_{element_id}); search matches any field value; after and before are dates (YYYY-MM-DD); page and per_page (max 100). Personal data',
                'input_schema' => [ 'type' => 'object', 'properties' => $filter + $paging ],
                'handler'      => static fn (array $args): array => self::list_submissions($args),
            ],
            'elementor-get-submission'           => $base + [
                'mode'         => 'read',
                'description'  => 'One Elementor Pro form submission with every field value (key, label, type, value), the actions that ran after submit and their result, user, IP, user agent, referer and dates. Personal data',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::missing_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'submission' => self::submission((int) $args['id']) ],
            ],
            'elementor-export-submissions'       => $base + [
                'mode'         => 'read',
                'description'  => 'Export one Elementor Pro form\'s submissions as CSV (default) or JSON rows, one column per field (labels from the form) then Elementor\'s own columns (form, id, created at, user id, user agent, IP, referrer). Same status filter as list-submissions; ids picks submissions; offset and limit (default 500, max 1000) page through; next_offset says where to continue. CSV cells that start with = + - @ are prefixed with \' so a spreadsheet does not run them. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form'   => $form,
                        'status' => $filter['status'],
                        'ids'    => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_EXPORT ],
                        'format' => [ 'type' => 'string', 'enum' => [ 'csv', 'json' ] ],
                        'offset' => [ 'type' => 'integer', 'minimum' => 0 ],
                        'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_EXPORT ],
                    ],
                    'required'   => [ 'form' ],
                ],
                'handler'      => static fn (array $args): array => self::export($args),
            ],
            'elementor-update-submission-status' => $write + [
                'mode'         => 'write',
                'description'  => 'Set an Elementor Pro submission\'s status (trash moves it to the trash, new restores it) and/or is_read. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'id'      => $id,
                        'status'  => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'is_read' => [ 'type' => 'boolean' ],
                    ],
                    'required'      => [ 'id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => self::status_refusal($args),
                'snapshot'     => static fn (array $args): ?array => self::status_snapshot($args),
                'handler'      => static fn (array $args): array => self::update_status($args),
            ],
            'elementor-delete-submission'        => $write + [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'description'       => 'Permanently delete an Elementor Pro submission with its field values and action log (trash it with update-submission-status instead to keep it restorable in Elementor). Needs confirm:true. Undo: rollback-session reinserts every row',
                'input_schema'      => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'          => static fn (array $args): ?array => self::missing_refusal((int) $args['id']),
                'confirm_preview'   => static fn (array $args): array => self::delete_preview((int) $args['id']),
                'handler'           => static fn (array $args, array $context): array => self::delete_submission((int) $args['id'], $args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_forms(array $args): array
    {
        global $wpdb;

        $per_page = (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page     = (int) ($args['page'] ?? 1);
        $only     = (int) ($args['post_id'] ?? 0);
        $where    = $only > 0 ? $wpdb->prepare(' AND p.ID = %d', $only) : '';
        $match    = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm WHERE (pm.meta_key = '_elementor_data' AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)) OR pm.meta_key = %s",
            '%' . $wpdb->esc_like('"widgetType":"form"') . '%',
            '%' . $wpdb->esc_like('"elType":"e-form"') . '%',
            self::SNAPSHOT_META
        );
        $from     = "FROM {$wpdb->posts} p WHERE p.ID IN ({$match}) AND p.post_type <> 'revision' AND p.post_status <> 'auto-draft'{$where}";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from is built above from prepared fragments and table names only.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) {$from}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from is built above from prepared fragments and table names only.
        $posts = (array) $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_title, p.post_type, p.post_status {$from} ORDER BY p.ID ASC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A);

        $counts = self::tables_exist() ? self::form_counts(array_map('intval', array_column($posts, 'ID'))) : null;
        $forms  = [];
        foreach ($posts as $post) {
            foreach (self::post_forms((int) $post['ID']) as $found) {
                $key           = $found['form'];
                $found['page'] = [ 'id' => (int) $post['ID'], 'title' => (string) $post['post_title'], 'type' => (string) $post['post_type'], 'status' => (string) $post['post_status'] ];
                $found['submissions'] = null === $counts ? null : ($counts[ $key ] ?? [ 'total' => 0, 'unread' => 0, 'trash' => 0 ]);
                $forms[]       = $found;
            }
        }

        return [
            'forms'           => $forms,
            'page'            => $page,
            'per_page'        => $per_page,
            'total_pages'     => $total,
            'has_more'        => $page * $per_page < $total,
            'tables_present'  => null !== $counts,
        ];
    }

    /**
     * The forms of one post: every form widget and atomic form in its
     * Elementor data, then the forms its submissions snapshot remembers that
     * are no longer on the page (in_page:false).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function post_forms(int $post_id): array
    {
        $snapshots = self::snapshots($post_id);
        $data      = json_decode((string) get_post_meta($post_id, '_elementor_data', true), true);
        $found     = [];
        self::walk(is_array($data) ? $data : [], $found);

        $forms = [];
        foreach ($found as $element) {
            $key = $post_id . '_' . $element['id'];
            if ('atomic' === $element['kind']) {
                $snap    = $snapshots[ (string) $element['id'] ] ?? null;
                $forms[] = [
                    'form'              => $key,
                    'element_id'        => $element['id'],
                    'kind'              => 'atomic',
                    'name'              => null === $snap ? null : (string) ($snap['name'] ?? ''),
                    'fields'            => null === $snap ? [] : self::snapshot_fields($snap),
                    'saves_submissions' => null,
                    'in_page'           => true,
                ];
                continue;
            }
            $settings = (array) ($element['settings'] ?? []);
            $actions  = isset($settings['submit_actions']) ? array_values(array_map('strval', (array) $settings['submit_actions'])) : self::DEFAULT_ACTIONS;
            $forms[]  = [
                'form'              => $key,
                'element_id'        => $element['id'],
                'kind'              => 'widget',
                'name'              => (string) ($settings['form_name'] ?? self::DEFAULT_NAME),
                'fields'            => array_map(
                    static fn ($field): array => [
                        'id'       => (string) ($field['custom_id'] ?? ''),
                        'type'     => (string) ($field['field_type'] ?? 'text'),
                        'label'    => (string) ($field['field_label'] ?? ''),
                        'required' => 'true' === (string) ($field['required'] ?? ''),
                    ],
                    array_values(array_filter((array) ($settings['form_fields'] ?? self::DEFAULT_FIELDS), 'is_array'))
                ),
                'submit_actions'    => $actions,
                'saves_submissions' => in_array('save-to-database', $actions, true),
                'in_page'           => true,
            ];
        }

        $on_page = array_column($found, 'id');
        foreach ($snapshots as $element_id => $snap) {
            if (in_array((string) $element_id, $on_page, true)) {
                continue;
            }
            $forms[] = [
                'form'              => $post_id . '_' . $element_id,
                'element_id'        => (string) $element_id,
                'kind'              => null,
                'name'              => (string) ($snap['name'] ?? ''),
                'fields'            => self::snapshot_fields($snap),
                'saves_submissions' => null,
                'in_page'           => false,
            ];
        }

        return $forms;
    }

    /**
     * Collect form elements from an Elementor element tree, depth first.
     *
     * @param array<int, mixed>               $elements
     * @param array<int, array<string,mixed>> $found
     */
    private static function walk(array $elements, array &$found): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $id = (string) ($element['id'] ?? '');
            if ('' !== $id && 'form' === ($element['widgetType'] ?? null)) {
                $found[] = [ 'id' => $id, 'kind' => 'widget', 'settings' => (array) ($element['settings'] ?? []) ];
            } elseif ('' !== $id && 'e-form' === ($element['elType'] ?? null)) {
                $found[] = [ 'id' => $id, 'kind' => 'atomic' ];
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                self::walk($element['elements'], $found);
            }
        }
    }

    /**
     * The post's form snapshots (Form_Snapshot_Repository), element id => [name, fields].
     *
     * @return array<string, array<string, mixed>>
     */
    private static function snapshots(int $post_id): array
    {
        $raw = get_post_meta($post_id, self::SNAPSHOT_META, true);
        $out = [];
        foreach ((array) json_decode(is_string($raw) ? $raw : '', true) as $item) {
            if (is_array($item) && isset($item['id'])) {
                $out[ (string) $item['id'] ] = $item;
            }
        }
        return $out;
    }

    /** @return array<int, array{id: string, type: string, label: string}> */
    private static function snapshot_fields(array $snapshot): array
    {
        return array_map(
            static fn ($field): array => [
                'id'    => (string) ($field['id'] ?? ''),
                'type'  => (string) ($field['type'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
            ],
            array_values(array_filter((array) ($snapshot['fields'] ?? []), 'is_array'))
        );
    }

    /**
     * Submission counts per form key for the given posts.
     *
     * @param int[] $post_ids
     * @return array<string, array{total: int, unread: int, trash: int}>
     */
    private static function form_counts(array $post_ids): array
    {
        global $wpdb;

        if ([] === $post_ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($post_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT post_id, element_id, SUM(status <> 'trash') AS total, SUM(status <> 'trash' AND is_read = 0) AS unread, SUM(status = 'trash') AS trash FROM %i WHERE post_id IN ($in) GROUP BY post_id, element_id", self::table(), ...$post_ids), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $out[ $row['post_id'] . '_' . $row['element_id'] ] = [ 'total' => (int) $row['total'], 'unread' => (int) $row['unread'], 'trash' => (int) $row['trash'] ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Submissions
    // ------------------------------------------------------------------

    /**
     * WHERE clause (without WHERE) for the list filters, as Query::apply_filter()
     * builds it, plus the status part; '1=1' when nothing filters.
     */
    private static function where(array $args, bool $with_status = true): string
    {
        global $wpdb;

        $parts = [];
        if (isset($args['after']) || isset($args['before'])) {
            $after   = get_gmt_from_date((string) ($args['after'] ?? '0000-01-01') . ' 00:00:00');
            $before  = get_gmt_from_date((string) ($args['before'] ?? '9999-12-31') . ' 23:59:59');
            $parts[] = $wpdb->prepare('s.created_at_gmt BETWEEN %s AND %s', $after, $before);
        }
        if ($with_status) {
            switch ((string) ($args['status'] ?? 'all')) {
                case 'unread':
                    $parts[] = "s.status = 'new' AND s.is_read = 0";
                    break;
                case 'read':
                    $parts[] = "s.status = 'new' AND s.is_read > 0";
                    break;
                case 'trash':
                    $parts[] = "s.status = 'trash'";
                    break;
                default:
                    $parts[] = "s.status <> 'trash'";
            }
        }
        if (isset($args['search'])) {
            $like    = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $parts[] = $wpdb->prepare('(EXISTS (SELECT 1 FROM %i v WHERE v.submission_id = s.id AND v.value LIKE %s) OR s.id LIKE %s)', self::table('values'), $like, $like);
        }
        if (isset($args['form'])) {
            [$post_id, $element_id] = explode('_', (string) $args['form'], 2);
            $parts[]                = $wpdb->prepare('s.post_id = %d AND s.element_id = %s', (int) $post_id, $element_id);
        }
        if (isset($args['ids'])) {
            $ids     = array_values(array_unique(array_map('intval', (array) $args['ids'])));
            $in      = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            $parts[] = $wpdb->prepare("s.id IN ($in)", ...$ids);
        }
        return [] === $parts ? '1=1' : implode(' AND ', $parts);
    }

    /** @return array<string, mixed> */
    private static function list_submissions(array $args): array
    {
        global $wpdb;

        $per_page = (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page     = (int) ($args['page'] ?? 1);
        $where    = self::where($args);
        $any      = self::where($args, false);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in where().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i s WHERE {$where}", self::table()));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in where().
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT s.* FROM %i s WHERE {$where} ORDER BY s.created_at_gmt DESC, s.id DESC LIMIT %d OFFSET %d", self::table(), $per_page, ($page - 1) * $per_page), ARRAY_A);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in where().
        $count = (array) $wpdb->get_row($wpdb->prepare("SELECT SUM(s.status <> 'trash') AS `all`, SUM(s.status = 'trash') AS trash, SUM(s.is_read = 1 AND s.status <> 'trash') AS `read`, SUM(s.is_read = 0 AND s.status <> 'trash') AS unread FROM %i s WHERE {$any}", self::table()), ARRAY_A);

        $main = self::values_by_id(array_values(array_filter(array_map('intval', array_column($rows, 'main_meta_id')))));

        return [
            'submissions' => array_map(static fn (array $row): array => self::summary($row, $main[ (int) $row['main_meta_id'] ] ?? null), $rows),
            'counts'      => array_map('intval', array_merge([ 'all' => 0, 'unread' => 0, 'read' => 0, 'trash' => 0 ], array_filter($count, static fn ($v): bool => null !== $v))),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'has_more'    => $page * $per_page < $total,
        ];
    }

    /**
     * Value rows by their own id.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private static function values_by_id(array $ids): array
    {
        global $wpdb;

        if ([] === $ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, `key`, value FROM %i WHERE id IN ($in)", self::table('values'), ...$ids), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['id'] ] = [ 'key' => (string) $row['key'], 'value' => $row['value'] ];
        }
        return $out;
    }

    /** One submissions row as list-submissions returns it. */
    private static function summary(array $row, ?array $main): array
    {
        return [
            'id'                      => (int) $row['id'],
            'form'                    => $row['post_id'] . '_' . $row['element_id'],
            'form_name'               => (string) $row['form_name'],
            'post_id'                 => (int) $row['post_id'],
            'element_id'              => (string) $row['element_id'],
            'status'                  => (string) $row['status'],
            'is_read'                 => (bool) (int) $row['is_read'],
            'main'                    => $main,
            'referer'                 => (string) $row['referer'],
            'referer_title'           => isset($row['referer_title']) ? (string) $row['referer_title'] : null,
            'actions_count'           => (int) $row['actions_count'],
            'actions_succeeded_count' => (int) $row['actions_succeeded_count'],
            'created_at_gmt'          => (string) $row['created_at_gmt'],
            'created_at'              => get_date_from_gmt((string) $row['created_at_gmt']),
            'updated_at_gmt'          => (string) $row['updated_at_gmt'],
        ];
    }

    /** The raw submissions row, or null. */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows_of(string $which, int $id): array
    {
        global $wpdb;
        return array_values((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE submission_id = %d ORDER BY id ASC', self::table($which), $id), ARRAY_A));
    }

    /** @return array<string, mixed> */
    private static function submission(int $id): array
    {
        $row    = (array) self::row($id);
        $fields = [];
        foreach (self::snapshots((int) $row['post_id'])[ (string) $row['element_id'] ]['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['id'])) {
                $fields[ (string) $field['id'] ] = $field;
            }
        }
        $values = [];
        $main   = null;
        foreach (self::rows_of('values', $id) as $value) {
            $field    = $fields[ (string) $value['key'] ] ?? [];
            $values[] = [
                'id'    => (int) $value['id'],
                'key'   => (string) $value['key'],
                'label' => isset($field['label']) ? (string) $field['label'] : null,
                'type'  => isset($field['type']) ? (string) $field['type'] : null,
                'value' => $value['value'],
            ];
            if ((int) $value['id'] === (int) $row['main_meta_id']) {
                $main = [ 'key' => (string) $value['key'], 'value' => $value['value'] ];
            }
        }
        $user = (int) $row['user_id'] > 0 ? get_userdata((int) $row['user_id']) : false;
        $meta = json_decode((string) ($row['meta'] ?? ''), true);

        return self::summary($row, $main) + [
            'values'     => $values,
            'actions'    => array_map(
                static fn (array $log): array => [
                    'name'       => (string) $log['action_name'],
                    'label'      => isset($log['action_label']) ? (string) $log['action_label'] : null,
                    'status'     => (string) $log['status'],
                    'log'        => $log['log'],
                    'created_at' => (string) $log['created_at'],
                ],
                self::rows_of('actions_log', $id)
            ),
            'user'       => [ 'id' => (int) $row['user_id'], 'name' => $user instanceof \WP_User ? $user->display_name : null ],
            'user_ip'    => (string) $row['user_ip'],
            'user_agent' => (string) $row['user_agent'],
            'meta'       => is_array($meta) ? $meta : null,
            'updated_at' => get_date_from_gmt((string) $row['updated_at_gmt']),
        ];
    }

    /** @return array<string, mixed> */
    private static function export(array $args): array
    {
        global $wpdb;

        [$post_id, $element_id] = explode('_', (string) $args['form'], 2);
        $limit  = (int) ($args['limit'] ?? self::DEFAULT_EXPORT);
        $offset = (int) ($args['offset'] ?? 0);
        $where  = self::where($args);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in where().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i s WHERE {$where}", self::table()));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments in where().
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT s.* FROM %i s WHERE {$where} ORDER BY s.created_at_gmt DESC, s.id DESC LIMIT %d OFFSET %d", self::table(), $limit, $offset), ARRAY_A);

        $values = [];
        $ids    = array_map('intval', array_column($rows, 'id'));
        if ([] !== $ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT submission_id, `key`, value FROM %i WHERE submission_id IN ($in) ORDER BY id ASC", self::table('values'), ...$ids), ARRAY_A) as $value) {
                $values[ (int) $value['submission_id'] ][ (string) $value['key'] ] = $value['value'];
            }
        }

        // Field columns as CSV_Export orders them: the form's fields first,
        // then any other key its submissions carry.
        $snapshot = self::snapshots((int) $post_id)[ $element_id ] ?? null;
        $labels   = [];
        foreach (null === $snapshot ? [] : self::snapshot_fields($snapshot) as $field) {
            $labels[ $field['id'] ] = $field['label'];
        }
        $keys = array_keys($labels);
        foreach ($values as $by_key) {
            foreach (array_keys($by_key) as $key) {
                if (! in_array((string) $key, $keys, true)) {
                    $keys[] = (string) $key;
                }
            }
        }
        $form_label = null === $snapshot ? '(' . $element_id . ')' : sprintf('%s (%s)', (string) ($snapshot['name'] ?? ''), $element_id);
        $columns    = array_merge(
            array_map(static fn (string $key): string => '' !== ($labels[ $key ] ?? '') ? (string) $labels[ $key ] : $key, $keys),
            [ 'Form Name (ID)', 'Submission ID', 'Created At', 'User ID', 'User Agent', 'User IP', 'Referrer' ]
        );

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $cells = [];
            foreach ($keys as $key) {
                $cells[ $key ] = $values[ $id ][ $key ] ?? '';
            }
            $out[] = [
                'fields'     => $cells,
                'form'       => $form_label,
                'id'         => $id,
                'created_at' => get_date_from_gmt((string) $row['created_at_gmt']),
                'user_id'    => (int) $row['user_id'],
                'user_agent' => (string) $row['user_agent'],
                'user_ip'    => (string) $row['user_ip'],
                'referrer'   => (string) $row['referer'],
            ];
        }

        $next   = $offset + count($rows) < $total ? $offset + count($rows) : null;
        $result = [
            'form'        => (string) $args['form'],
            'form_label'  => $form_label,
            'columns'     => $columns,
            'count'       => count($out),
            'total'       => $total,
            'next_offset' => $next,
        ];
        if ('json' === ($args['format'] ?? 'csv')) {
            return $result + [ 'rows' => $out ];
        }
        return $result + [ 'csv' => self::csv($columns, $out) ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function csv(array $columns, array $rows): string
    {
        $lines = [ self::csv_line($columns) ];
        foreach ($rows as $row) {
            $lines[] = self::csv_line(array_merge(array_values($row['fields']), [ $row['form'], $row['id'], $row['created_at'], $row['user_id'], $row['user_agent'], $row['user_ip'], $row['referrer'] ]));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** One RFC 4180 line; a cell that starts with = + - @ (or a tab or CR) is prefixed with ' so a spreadsheet shows it as text. */
    private static function csv_line(array $cells): string
    {
        return implode(',', array_map(static function ($cell): string {
            $cell = is_scalar($cell) || null === $cell ? (string) $cell : (string) wp_json_encode($cell);
            if (! is_numeric($cell) && 1 === preg_match('/^[=+\-@\t\r]/', $cell)) {
                $cell = "'" . $cell;
            }
            return '"' . str_replace('"', '""', $cell) . '"';
        }, $cells));
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function missing_refusal(int $id): ?array
    {
        if (null !== self::row($id)) {
            return null;
        }
        return [ 'code' => 'submission_not_found', 'message' => sprintf('Elementor Pro submission %d does not exist.', $id), 'data' => [ 'id' => $id ] ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function status_refusal(array $args): ?array
    {
        if (! isset($args['status']) && ! isset($args['is_read'])) {
            return [ 'code' => 'invalid_args', 'message' => 'Give status, is_read or both.', 'data' => [] ];
        }
        return self::missing_refusal((int) $args['id']);
    }

    /** The columns update-submission-status writes, with updated_at as Query::update_submission() sets it. */
    private static function status_set(array $args): array
    {
        $set = [];
        if (isset($args['status'])) {
            $set['status'] = (string) $args['status'];
        }
        if (isset($args['is_read'])) {
            $set['is_read'] = $args['is_read'] ? 1 : 0;
        }
        $set['updated_at_gmt'] = current_time('mysql', true);
        $set['updated_at']     = get_date_from_gmt($set['updated_at_gmt']);
        return $set;
    }

    /** A db_rows before-image of the one submissions row (Forms_Integration::row_snapshot()'s shape). */
    private static function status_snapshot(array $args): ?array
    {
        $table = Database_Guard::valid_table(self::table());
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'id' => (int) $args['id'] ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'update',
                'primary_key' => [ 'id' ],
                'where'       => $where,
                'set'         => self::status_set($args),
                'rows'        => $before,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function update_status(array $args): array
    {
        global $wpdb;

        $id  = (int) $args['id'];
        $set = self::status_set($args);
        if (false === $wpdb->update(self::table(), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update Elementor Pro submission %d: %s', $id, $wpdb->last_error));
        }
        Database_Guard::invalidate_caches(self::table(), [ 'where' => [ 'id' => $id ] ]);
        return [ 'submission' => self::summary((array) self::row($id), null) ];
    }

    /** @return array{message: string, data: array} */
    private static function delete_preview(int $id): array
    {
        $row     = (array) self::row($id);
        $values  = count(self::rows_of('values', $id));
        $actions = count(self::rows_of('actions_log', $id));

        return [
            'message' => sprintf(
                'It permanently deletes submission %d of form "%s" (%s) with %d field values and %d action log rows. Undo: rollback-session.',
                $id,
                (string) $row['form_name'],
                $row['post_id'] . '_' . $row['element_id'],
                $values,
                $actions
            ),
            'data'    => [ 'id' => $id, 'form' => $row['post_id'] . '_' . $row['element_id'], 'values' => $values, 'actions_log' => $actions ],
        ];
    }

    /**
     * Delete a submission the way Query::delete_submission() does (values,
     * action log, then the submission), each table's rows under its own
     * db_rows before-image in the call's session.
     *
     * @return array{result: array, operation_ids: string[]}
     */
    private static function delete_submission(int $id, array $args, array $context): array
    {
        $summary = self::summary((array) self::row($id), null);
        $ids     = [];
        foreach ([ [ 'values', 'submission_id' ], [ 'actions_log', 'submission_id' ], [ '', 'id' ] ] as [$which, $column]) {
            $table = Database_Guard::valid_table(self::table($which));
            if (is_wp_error($table)) {
                throw new Operation_Refused('elementor_submissions_unavailable', sprintf('The Elementor Pro table %s is missing.', self::table($which)));
            }
            $where  = [ $column => $id ];
            $before = Database_Guard::before_image($table, $where);
            if ([] === $before) {
                continue;
            }
            $out   = Safe_Mutation::run(
                [
                    'object_type'         => 'db_rows',
                    'object_id'           => $table,
                    'session_id'          => (string) $context['session_id'],
                    'tool_name'           => (string) $context['tool_name'],
                    'args'                => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                    'extra_snapshot_data' => [
                        'table'       => $table,
                        'operation'   => 'delete',
                        'primary_key' => [ 'id' ],
                        'where'       => $where,
                        'set'         => [],
                        'rows'        => $before,
                    ],
                ],
                static function () use ($table, $where, $before): int {
                    global $wpdb;
                    $affected = $wpdb->delete($table, $where);
                    if (false === $affected) {
                        throw new Operation_Refused('delete_failed', sprintf('Could not delete rows of %s: %s', $table, $wpdb->last_error));
                    }
                    Database_Guard::invalidate_caches($table, [ 'rows' => $before, 'where' => $where ]);
                    return (int) $affected;
                }
            );
            $ids[] = (string) $out['operation_id'];
        }

        return [ 'result' => [ 'deleted' => $summary ], 'operation_ids' => $ids ];
    }
}
