<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WS Form (issue #676) on the plugin-data pair, free tier, so it adds no
 * top-level tools. The op names follow the forms adapters (list-forms,
 * get-form, list-fields, list-notifications, list-entries, get-entry,
 * update-entry-status), prefixed wsform- because the pair is shared.
 *
 * WS Form (both editions define WS_FORM_VERSION) keeps everything in
 * tables of its own, created by WS_Form_Activator::database_init() (read in
 * WS Form 1.12.6):
 *  - {prefix}wsf_form: one row per form (label, status draft, publish or
 *    trash, cached counts, checksum and published_checksum);
 *  - {prefix}wsf_form_meta: form settings; meta_key 'action' holds the
 *    form's actions as a serialized data grid, one row per action whose
 *    data[0] is its label and data[1] the JSON {id, meta, events}, with
 *    'disabled' set to 'on' for an action that is switched off;
 *  - {prefix}wsf_group (tabs), {prefix}wsf_section and {prefix}wsf_field,
 *    with {prefix}wsf_field_meta ('required' is 'on' for a required field,
 *    data_grid_checkbox, data_grid_radio and data_grid_select hold the
 *    choices). These are the form as last saved in the editor;
 *  - {prefix}wsf_submit: one row per submission (form_id, status draft "In
 *    Progress", publish "Submitted", error, spam or trash, spam_level,
 *    starred, viewed, encrypted, the actions log), {prefix}wsf_submit_meta
 *    its values (field_id and meta_key field_N for a field, other keys such
 *    as tracking_remote_ip with no field_id) and {prefix}wsf_submit_note
 *    the notes added to it in wp-admin.
 * Meta values are serialized with stdClass objects inside, read back the
 * way WS_Form_Common::maybe_unserialize() does (stdClass only). Values of a
 * submission saved with WS Form's encryption on (encrypted = 1) are
 * decrypted with WS_Form_Encryption, as WS_Form_Submit_Meta::db_read_all()
 * does.
 *
 * Reads: wsform-list-forms, wsform-get-form, wsform-list-fields,
 * wsform-list-actions, wsform-list-notifications (the email actions),
 * wsform-list-entries and wsform-get-entry. The submission counts are
 * counted from wsf_submit, not taken from wsf_form's cached columns, which
 * WS Form only refreshes on its own writes. wsform-list-entries masks every
 * field value (a@*** style) and leaves out tracking data such as the IP
 * address; wsform-get-entry returns one submission in full.
 *
 * Writes (off until a site opts in with wpmcp_enable_ws_form_write):
 *  - wsform-update-entry-status sets status (publish, draft, spam, trash),
 *    starred and viewed on the wsf_submit row the way
 *    WS_Form_Submit::db_set_status(), db_set_starred() and db_set_viewed()
 *    write them (spam sets spam_level 100, publish from spam sets it to 0),
 *    under a db_rows before-image of that row (undo: rollback-operation).
 *    It writes the row directly rather than through db_set_status(), whose
 *    wsf_submit_status hook can report the sender's IP to AbuseIPDB, which
 *    no undo could withdraw;
 *  - wsform-delete-entry permanently deletes a submission with its values
 *    and notes, as WS_Form_Submit::db_delete(true) does, and asks for
 *    confirm:true first. Each table's rows are captured as a db_rows
 *    before-image right before they go, all in one session, so
 *    rollback-session reinserts them with their original ids. Uploaded
 *    files are left on disk (WS Form's own delete removes them), so the
 *    restored submission still links to them.
 * After a write the form's cached counts are refreshed through
 * WS_Form_Form::db_count_update() when WS Form is loaded.
 *
 * Every op takes manage_options: WS Form grants its own form and submission
 * capabilities to administrators only, and submissions are personal data.
 *
 * Presence is filterable (wpmcp_ws_form_active, default: WS_FORM_VERSION is
 * defined). Without WS Form the ops stay documented in list-operations
 * (dependency_met:false) and answer plugin_missing or plugin_inactive with
 * the plugins screen to fix it from; with WS Form but no tables yet they
 * answer ws_form_tables_missing.
 */
final class WS_Form_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes WS Form's own tables live; WS Form has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_ws_form_write';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    /** The plugin's main file under the plugins directory, either edition. */
    public const PLUGIN_FILES = [ 'ws-form-pro/ws-form.php', 'ws-form/ws-form.php' ];

    /** WS_Form_Common::check_submit_status(). */
    private const SUBMIT_STATUSES = [ 'draft', 'publish', 'error', 'spam', 'trash' ];

    /** What update-entry-status may set ('error' is WS Form's own outcome, not a choice). */
    private const WRITABLE_STATUSES = [ 'publish', 'draft', 'spam', 'trash' ];

    /** WS_Form_Submit::db_get_status_name(). */
    private const STATUS_LABELS = [
        'draft'   => 'In Progress',
        'publish' => 'Submitted',
        'error'   => 'Error',
        'spam'    => 'Spam',
        'trash'   => 'Trash',
    ];

    private const FORM_STATUSES = [ 'draft', 'publish', 'trash' ];

    /** WS_Form_Submit_Meta's encryption_excluded_meta_keys. */
    private const UNENCRYPTED_META = [ 'source_form_id', 'source_id' ];

    /** Field types whose choices sit in a data_grid_{type} meta. */
    private const CHOICE_TYPES = [ 'checkbox', 'radio', 'select' ];

    /** Whether WS Form is loaded (filterable). */
    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_ws_form_active', defined('WS_FORM_VERSION'));
    }

    /** One of WS Form's tables, by its name without the {prefix}wsf_ part. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wsf_' . $name;
    }

    /**
     * true when WS Form is loaded, otherwise plugin_inactive (its files are
     * installed) or plugin_missing, naming the plugins screen.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'WS Form is %s on this site. Install and activate WS Form (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'ws-form', 'plugins_url' => $url ],
        ];
    }

    /**
     * presence(), then the tables WS Form creates on activation.
     *
     * @return true|array{code: string, message: string, data?: array}
     */
    public static function tables_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach ([ 'form', 'form_meta', 'field', 'submit', 'submit_meta' ] as $name) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                return [
                    'code'    => 'ws_form_tables_missing',
                    'message' => sprintf('WS Form\'s table %s does not exist on this site. Deactivate and reactivate WS Form so it creates its tables, then try again.', self::table($name)),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id        = [ 'type' => 'integer', 'minimum' => 1 ];
        $form_only = [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ];
        $entry     = [ 'type' => 'object', 'properties' => [ 'entry_id' => $id ], 'required' => [ 'entry_id' ] ];
        $base      = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::tables_presence(),
        ];
        $write     = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_ws_form_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $form      = static fn (array $args): ?array => self::form_refusal((int) $args['form_id']);

        return [
            'wsform-list-forms'           => $base + [
                'mode'         => 'read',
                'description'  => 'List WS Form forms: id, label, status (draft, publish, trash), whether the editor has unpublished changes, shortcode, and submission counts (total, unread, spam, trash) counted live. status filters; trash is left out unless asked for',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'enum' => self::FORM_STATUSES ] ] ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'wsform-get-form'             => $base + [
                'mode'         => 'read',
                'description'  => 'Read one WS Form form: label, status, shortcode, counts, and its tabs with their sections and fields (id, type, label)',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form' => self::form((int) $args['form_id']) ],
            ],
            'wsform-list-fields'          => $base + [
                'mode'         => 'read',
                'description'  => 'List one WS Form form\'s fields in form order: id, key (field_N, the key of its submitted value), type, label, required, tab and section, and the choices of checkbox, radio and select fields',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'fields' => self::fields((int) $args['form_id']) ],
            ],
            'wsform-list-actions'         => $base + [
                'mode'         => 'read',
                'description'  => 'List one WS Form form\'s actions in run order (save to submissions, send email, show message, redirect, webhooks and others): row id, action type, label, active, the events it runs on, and its settings',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'actions' => self::actions((int) $args['form_id']) ],
            ],
            'wsform-list-notifications'   => $base + [
                'mode'         => 'read',
                'description'  => 'List one WS Form form\'s email notifications (its "email" actions): row id, label, active, to, cc, bcc, from, reply-to, subject and message template',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'notifications' => self::notifications((int) $args['form_id']) ],
            ],
            'wsform-list-entries'         => $base + [
                'mode'         => 'read',
                'description'  => 'List a WS Form form\'s submissions newest first: id, status, starred, viewed, dates and every field value MASKED (wsform-get-entry returns one in full). status: publish, draft (in progress), error, spam or trash; by default all but spam and trash, as WS Form lists them. page_size (default 20, max 100) and offset. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => $id,
                        'status'    => [ 'type' => 'string', 'enum' => self::SUBMIT_STATUSES ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'validate'     => $form,
                'handler'      => static fn (array $args): array => self::list_entries($args),
            ],
            'wsform-get-entry'            => $base + [
                'mode'         => 'read',
                'description'  => 'One WS Form submission in full: status, starred, viewed, dates, user, every field value (key, label, type, value), tracking data such as the IP address, the actions log and the wp-admin notes. Personal data',
                'input_schema' => $entry,
                'validate'     => static fn (array $args): ?array => self::entry_refusal((int) $args['entry_id']),
                'handler'      => static fn (array $args): array => [ 'entry' => self::entry((int) $args['entry_id']) ],
            ],
            'wsform-update-entry-status'  => $write + [
                'mode'         => 'write',
                'description'  => 'Set a WS Form submission\'s status (publish, draft, spam or trash), starred and/or viewed. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'entry_id' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => self::WRITABLE_STATUSES ],
                        'starred'  => [ 'type' => 'boolean' ],
                        'viewed'   => [ 'type' => 'boolean' ],
                    ],
                    'required'      => [ 'entry_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => self::status_refusal($args),
                'snapshot'     => static fn (array $args): ?array => self::status_snapshot($args),
                'handler'      => static fn (array $args): array => self::update_status($args),
            ],
            'wsform-delete-entry'         => $write + [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'description'       => 'Permanently delete a WS Form submission with its values and notes (set status trash instead to keep it in WS Form). Uploaded files stay on disk. Needs confirm:true. Undo: rollback-session reinserts every row',
                'input_schema'      => $entry,
                'validate'          => static fn (array $args): ?array => self::entry_refusal((int) $args['entry_id']),
                'confirm_preview'   => static fn (array $args): array => self::delete_preview((int) $args['entry_id']),
                'handler'           => static fn (array $args, array $context): array => self::delete_entry((int) $args['entry_id'], $args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Storage helpers
    // ------------------------------------------------------------------

    /**
     * A meta value as WS_Form_Common::maybe_unserialize() reads it (only
     * stdClass may be instantiated), turned into plain arrays.
     *
     * @return mixed
     */
    private static function unserialize_value($raw)
    {
        if (! is_string($raw) || ! is_serialized($raw)) {
            return $raw;
        }
        if ('b:0;' === $raw) {
            return false;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- WS Form stores its meta with PHP serialize(); only stdClass is allowed, exactly as WS_Form_Common::maybe_unserialize() reads it.
        $value = @unserialize($raw, [ 'allowed_classes' => [ 'stdClass' ] ]);
        if (false === $value) {
            return $raw;
        }
        return self::to_array($value);
    }

    /** stdClass trees as arrays. @return mixed */
    private static function to_array($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map([ self::class, 'to_array' ], $value);
        }
        return $value;
    }

    /** @return array<string, mixed> meta_key => value of one form or field */
    private static function meta(string $object, int $parent_id, array $keys = []): array
    {
        global $wpdb;
        $sql = 'SELECT meta_key, meta_value FROM %i WHERE parent_id = %d';
        $arg = [ self::table($object . '_meta'), $parent_id ];
        if ([] !== $keys) {
            $sql .= ' AND meta_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')';
            $arg  = array_merge($arg, $keys);
        }
        $out = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql holds only placeholders; every value goes through prepare().
        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, ...$arg), ARRAY_A) as $row) {
            $out[ (string) $row['meta_key'] ] = self::unserialize_value($row['meta_value']);
        }
        return $out;
    }

    /** The raw wsf_form row, or null. */
    private static function form_row(int $form_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('form'), $form_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** The raw wsf_submit row, or null. */
    private static function entry_row(int $entry_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('submit'), $entry_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function form_refusal(int $form_id): ?array
    {
        if (null !== self::form_row($form_id)) {
            return null;
        }
        return [ 'code' => 'form_not_found', 'message' => sprintf('WS Form has no form %d.', $form_id), 'data' => [ 'form_id' => $form_id ] ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function entry_refusal(int $entry_id): ?array
    {
        if (null !== self::entry_row($entry_id)) {
            return null;
        }
        return [ 'code' => 'entry_not_found', 'message' => sprintf('WS Form has no submission %d.', $entry_id), 'data' => [ 'entry_id' => $entry_id ] ];
    }

    /**
     * Submission counts per form, counted from wsf_submit.
     *
     * @param int[] $form_ids
     * @return array<int, array{total: int, unread: int, spam: int, trash: int}>
     */
    private static function counts(array $form_ids): array
    {
        global $wpdb;
        if ([] === $form_ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($form_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT form_id, status, viewed, COUNT(*) AS n FROM %i WHERE form_id IN ($in) GROUP BY form_id, status, viewed", self::table('submit'), ...$form_ids), ARRAY_A);
        $out  = [];
        foreach ($form_ids as $form_id) {
            $out[ $form_id ] = [ 'total' => 0, 'unread' => 0, 'spam' => 0, 'trash' => 0 ];
        }
        foreach ($rows as $row) {
            $form_id = (int) $row['form_id'];
            $n       = (int) $row['n'];
            $status  = (string) $row['status'];
            if (in_array($status, [ 'spam', 'trash' ], true)) {
                $out[ $form_id ][ $status ] += $n;
                continue;
            }
            $out[ $form_id ]['total'] += $n;
            // WS_Form_Submit::db_get_count_submit_unread_cached(): unviewed and publish or draft.
            if (0 === (int) $row['viewed'] && in_array($status, [ 'publish', 'draft' ], true)) {
                $out[ $form_id ]['unread'] += $n;
            }
        }
        return $out;
    }

    /** One wsf_form row as the form reads return it. */
    private static function form_summary(array $row, array $counts): array
    {
        $id = (int) $row['id'];
        return [
            'id'                  => $id,
            'label'               => (string) $row['label'],
            'status'              => (string) $row['status'],
            // The editor saves to the tables; publishing copies them into
            // `published` and its checksum.
            'unpublished_changes' => 'publish' === $row['status'] && (string) $row['checksum'] !== (string) $row['published_checksum'],
            'shortcode'           => sprintf('[ws_form id="%d"]', $id),
            'date_added'          => (string) $row['date_added'],
            'date_updated'        => (string) $row['date_updated'],
            'submissions'         => $counts[ $id ] ?? [ 'total' => 0, 'unread' => 0, 'spam' => 0, 'trash' => 0 ],
        ];
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_forms(array $args): array
    {
        global $wpdb;
        if (isset($args['status'])) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE status = %s ORDER BY id ASC', self::table('form'), (string) $args['status']), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE status <> 'trash' ORDER BY id ASC", self::table('form')), ARRAY_A);
        }
        $rows   = (array) $rows;
        $counts = self::counts(array_map('intval', array_column($rows, 'id')));
        $forms  = array_map(static fn (array $row): array => self::form_summary($row, $counts), $rows);

        return [ 'forms' => $forms, 'total' => count($forms) ];
    }

    /**
     * The form's tabs, sections and fields, in the editor's order, from
     * wsf_group, wsf_section and wsf_field.
     *
     * @return array<int, array{id: int, label: string, sections: array}>
     */
    private static function tree(int $form_id): array
    {
        global $wpdb;
        $groups   = (array) $wpdb->get_results($wpdb->prepare('SELECT id, label FROM %i WHERE form_id = %d ORDER BY sort_index ASC, id ASC', self::table('group'), $form_id), ARRAY_A);
        $sections = [];
        $fields   = [];
        $ids      = array_map('intval', array_column($groups, 'id'));
        if ([] !== $ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, group_id, label FROM %i WHERE group_id IN ($in) ORDER BY sort_index ASC, id ASC", self::table('section'), ...$ids), ARRAY_A) as $section) {
                $sections[ (int) $section['group_id'] ][] = $section;
            }
        }
        $section_ids = [];
        foreach ($sections as $list) {
            $section_ids = array_merge($section_ids, array_map('intval', array_column($list, 'id')));
        }
        if ([] !== $section_ids) {
            $in = implode(',', array_fill(0, count($section_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, section_id, label, type FROM %i WHERE section_id IN ($in) ORDER BY sort_index ASC, id ASC", self::table('field'), ...$section_ids), ARRAY_A) as $field) {
                $fields[ (int) $field['section_id'] ][] = [ 'id' => (int) $field['id'], 'type' => (string) $field['type'], 'label' => (string) $field['label'] ];
            }
        }

        $tree = [];
        foreach ($groups as $group) {
            $out = [];
            foreach ($sections[ (int) $group['id'] ] ?? [] as $section) {
                $out[] = [ 'id' => (int) $section['id'], 'label' => (string) $section['label'], 'fields' => $fields[ (int) $section['id'] ] ?? [] ];
            }
            $tree[] = [ 'id' => (int) $group['id'], 'label' => (string) $group['label'], 'sections' => $out ];
        }
        return $tree;
    }

    /** @return array<string, mixed> */
    private static function form(int $form_id): array
    {
        $row = (array) self::form_row($form_id);
        return self::form_summary($row, self::counts([ $form_id ])) + [ 'tabs' => self::tree($form_id) ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function fields(int $form_id): array
    {
        $out = [];
        foreach (self::tree($form_id) as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $keys = [ 'required' ];
                    if (in_array($field['type'], self::CHOICE_TYPES, true)) {
                        $keys[] = 'data_grid_' . $field['type'];
                    }
                    $meta = self::meta('field', $field['id'], $keys);
                    $item = [
                        'id'         => $field['id'],
                        'key'        => 'field_' . $field['id'],
                        'type'       => $field['type'],
                        'label'      => $field['label'],
                        'required'   => 'on' === ($meta['required'] ?? ''),
                        'tab_id'     => $tab['id'],
                        'section_id' => $section['id'],
                    ];
                    if (in_array($field['type'], self::CHOICE_TYPES, true)) {
                        $item['choices'] = self::grid_labels($meta[ 'data_grid_' . $field['type'] ] ?? null);
                    }
                    $out[] = $item;
                }
            }
        }
        return $out;
    }

    /**
     * The rows of a WS Form data grid ({groups: [{rows: [{id, data: [...],
     * disabled}]}]}), every group.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function grid_rows($grid): array
    {
        $rows = [];
        foreach ((array) (is_array($grid) ? ($grid['groups'] ?? []) : []) as $group) {
            foreach ((array) ($group['rows'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    /** @return string[] the first column of each row: a choice's label. */
    private static function grid_labels($grid): array
    {
        return array_values(array_map(static fn (array $row): string => (string) ($row['data'][0] ?? ''), self::grid_rows($grid)));
    }

    // ------------------------------------------------------------------
    // Actions and notifications
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function actions(int $form_id): array
    {
        $out = [];
        foreach (self::grid_rows(self::meta('form', $form_id, [ 'action' ])['action'] ?? null) as $row) {
            $data = json_decode((string) ($row['data'][1] ?? ''), true);
            if (! is_array($data) || ! isset($data['id']) || ! is_string($data['id'])) {
                continue;
            }
            $out[] = [
                'row_id'   => (int) ($row['id'] ?? 0),
                'type'     => $data['id'],
                'label'    => (string) ($row['data'][0] ?? ''),
                'active'   => 'on' !== ($row['disabled'] ?? ''),
                'events'   => array_values((array) ($data['events'] ?? [])),
                'settings' => (array) ($data['meta'] ?? []),
            ];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function notifications(int $form_id): array
    {
        $out = [];
        foreach (self::actions($form_id) as $action) {
            if ('email' !== $action['type']) {
                continue;
            }
            $s      = $action['settings'];
            $editor = (string) ($s['action_email_message_editor'] ?? 'textarea');
            $out[]  = [
                'row_id'   => $action['row_id'],
                'label'    => $action['label'],
                'active'   => $action['active'],
                'to'       => self::recipients($s['action_email_to'] ?? []),
                'cc'       => self::recipients($s['action_email_cc'] ?? []),
                'bcc'      => self::recipients($s['action_email_bcc'] ?? []),
                'from'     => [ 'email' => (string) ($s['action_email_from_email'] ?? ''), 'name' => (string) ($s['action_email_from_name'] ?? '') ],
                'reply_to' => (string) ($s['action_email_reply_to_email'] ?? ''),
                'subject'  => (string) ($s['action_email_subject'] ?? ''),
                // WS_Form_Action_Email::load_config(): the message comes from
                // the editor the action is set to.
                'message'  => (string) ($s[ 'action_email_message_' . $editor ] ?? ''),
                'events'   => $action['events'],
            ];
        }
        return $out;
    }

    /** @return array<int, array{email: string, name: string}> */
    private static function recipients($rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $out[] = [ 'email' => (string) ($row['action_email_email'] ?? ''), 'name' => (string) ($row['action_email_name'] ?? '') ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Entries
    // ------------------------------------------------------------------

    /** One wsf_submit row as the entry reads return it. */
    private static function entry_summary(array $row): array
    {
        $status = (string) $row['status'];
        return [
            'id'           => (int) $row['id'],
            'form_id'      => (int) $row['form_id'],
            'status'       => $status,
            'status_label' => self::STATUS_LABELS[ $status ] ?? $status,
            'starred'      => (bool) (int) $row['starred'],
            'viewed'       => (bool) (int) $row['viewed'],
            'spam_level'   => null === $row['spam_level'] ? null : (int) $row['spam_level'],
            'user_id'      => (int) $row['user_id'],
            'duration'     => (int) $row['duration'],
            'encrypted'    => (bool) (int) $row['encrypted'],
            'date_added'   => (string) $row['date_added'],
            'date_updated' => (string) $row['date_updated'],
        ];
    }

    /**
     * A submission's values: the field values in field order, and the rest
     * (tracking and the like) by key. Decrypted when the submission was
     * saved encrypted; when WS Form's encryption is not loaded the values
     * of such a submission are null.
     *
     * @param array<int, array<string, mixed>> $meta_rows wsf_submit_meta rows of one submission
     * @return array{fields: array<int, array<string, mixed>>, other: array<string, mixed>, decrypted: bool}
     */
    private static function values(array $row, array $meta_rows, array $labels): array
    {
        $encrypted = 1 === (int) $row['encrypted'];
        $crypto    = $encrypted && class_exists('WS_Form_Encryption') ? new \WS_Form_Encryption() : null;
        $fields    = [];
        $other     = [];
        foreach ($meta_rows as $meta) {
            $key   = (string) $meta['meta_key'];
            $raw   = $meta['meta_value'];
            $value = null;
            if (! $encrypted || in_array($key, self::UNENCRYPTED_META, true)) {
                $value = self::unserialize_value($raw);
            } elseif (null !== $crypto) {
                $value = self::unserialize_value($crypto->decrypt($raw));
            }
            $field_id = (int) ($meta['field_id'] ?? 0);
            if ($field_id > 0) {
                $fields[] = [
                    'field_id'         => $field_id,
                    'key'              => '' !== $key ? $key : 'field_' . $field_id,
                    'label'            => $labels[ $field_id ]['label'] ?? null,
                    'type'             => $labels[ $field_id ]['type'] ?? null,
                    'repeatable_index' => null === ($meta['repeatable_index'] ?? null) ? null : (int) $meta['repeatable_index'],
                    'value'            => $value,
                ];
                continue;
            }
            $other[ $key ] = $value;
        }
        return [ 'fields' => $fields, 'other' => $other, 'decrypted' => ! $encrypted || null !== $crypto ];
    }

    /** @return array<int, array{label: string, type: string}> field id => label and type, for the fields of these ids. */
    private static function field_labels(array $field_ids): array
    {
        global $wpdb;
        $field_ids = array_values(array_unique(array_filter(array_map('intval', $field_ids))));
        if ([] === $field_ids) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($field_ids), '%d'));
        $out = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, label, type FROM %i WHERE id IN ($in)", self::table('field'), ...$field_ids), ARRAY_A) as $field) {
            $out[ (int) $field['id'] ] = [ 'label' => (string) $field['label'], 'type' => (string) $field['type'] ];
        }
        return $out;
    }

    /** @return array<int, array<int, array<string, mixed>>> submission id => its meta rows, in insert order. */
    private static function meta_rows(array $entry_ids): array
    {
        global $wpdb;
        if ([] === $entry_ids) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($entry_ids), '%d'));
        $out = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE parent_id IN ($in) ORDER BY id ASC", self::table('submit_meta'), ...$entry_ids), ARRAY_A) as $meta) {
            $out[ (int) $meta['parent_id'] ][] = $meta;
        }
        return $out;
    }

    /**
     * A value with the personal part hidden: an email keeps its first
     * character and its domain, anything else its first character only.
     * Lists are masked item by item; empty values stay as they are.
     *
     * @return mixed
     */
    public static function mask($value)
    {
        if (is_array($value)) {
            return array_map([ self::class, 'mask' ], $value);
        }
        if (null === $value || is_bool($value) || '' === $value) {
            return $value;
        }
        $text = (string) $value;
        if (1 === preg_match('/^([^@\s])[^@\s]*@([^@\s]+)$/u', $text, $m)) {
            return $m[1] . '***@' . $m[2];
        }
        return mb_substr($text, 0, 1) . '***';
    }

    /** @return array<string, mixed> */
    private static function list_entries(array $args): array
    {
        global $wpdb;
        $form_id = (int) $args['form_id'];
        $size    = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));

        // WS_Form_Submit::get_where_default_status(): spam and trash are only listed when asked for.
        if (isset($args['status'])) {
            $where = $wpdb->prepare('form_id = %d AND status = %s', $form_id, (string) $args['status']);
        } else {
            $where = $wpdb->prepare("form_id = %d AND status NOT IN ('spam', 'trash')", $form_id);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built above with prepare().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::table('submit')));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built above with prepare().
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY date_added DESC, id DESC LIMIT %d OFFSET %d", self::table('submit'), $size, $offset), ARRAY_A);

        $metas  = self::meta_rows(array_map('intval', array_column($rows, 'id')));
        $ids    = [];
        foreach ($metas as $list) {
            $ids = array_merge($ids, array_column($list, 'field_id'));
        }
        $labels  = self::field_labels($ids);
        $entries = [];
        foreach ($rows as $row) {
            $values    = self::values($row, $metas[ (int) $row['id'] ] ?? [], $labels);
            $entries[] = self::entry_summary($row) + [
                'fields' => array_map(static function (array $field): array {
                    unset($field['repeatable_index']);
                    $field['value'] = self::mask($field['value']);
                    return $field;
                }, $values['fields']),
            ];
        }

        return [
            'form_id'     => $form_id,
            'entries'     => $entries,
            'total'       => $total,
            'masked'      => true,
            'next_offset' => $offset + count($rows) < $total ? $offset + count($rows) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function entry(int $entry_id): array
    {
        global $wpdb;
        $row     = (array) self::entry_row($entry_id);
        $meta    = self::meta_rows([ $entry_id ])[ $entry_id ] ?? [];
        $values  = self::values($row, $meta, self::field_labels(array_column($meta, 'field_id')));
        $user    = (int) $row['user_id'] > 0 ? get_userdata((int) $row['user_id']) : false;
        $actions = json_decode((string) $row['actions'], true);
        $notes   = [];
        if (Plugin_Table_Rows_Snapshot::table_exists(self::table('submit_note'))) {
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, user_id, user_name, date_added, content FROM %i WHERE submit_id = %d ORDER BY id ASC', self::table('submit_note'), $entry_id), ARRAY_A) as $note) {
                $notes[] = [ 'id' => (int) $note['id'], 'user_id' => (int) $note['user_id'], 'user_name' => (string) $note['user_name'], 'date_added' => (string) $note['date_added'], 'content' => (string) $note['content'] ];
            }
        }

        return self::entry_summary($row) + [
            'user_name' => $user instanceof \WP_User ? $user->display_name : null,
            'fields'    => $values['fields'],
            'meta'      => $values['other'],
            'decrypted' => $values['decrypted'],
            'actions'   => is_array($actions) ? $actions : null,
            'notes'     => $notes,
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function status_refusal(array $args): ?array
    {
        if (! isset($args['status']) && ! isset($args['starred']) && ! isset($args['viewed'])) {
            return [ 'code' => 'invalid_args', 'message' => 'Give status, starred, viewed or any of them together.', 'data' => [] ];
        }
        return self::entry_refusal((int) $args['entry_id']);
    }

    /** The wsf_submit columns update-entry-status writes. */
    private static function status_set(array $args, array $row): array
    {
        $set = [];
        if (isset($args['status'])) {
            $set['status'] = (string) $args['status'];
            if ('spam' === $args['status']) {
                $set['spam_level'] = 100;
            } elseif ('publish' === $args['status'] && 'spam' === $row['status']) {
                // WS Form's "Not Spam": back to submitted, spam level 0.
                $set['spam_level'] = 0;
            }
        }
        if (isset($args['starred'])) {
            $set['starred'] = $args['starred'] ? 1 : 0;
        }
        if (isset($args['viewed'])) {
            $set['viewed'] = $args['viewed'] ? 1 : 0;
        }
        return $set;
    }

    /** A db_rows before-image of the one wsf_submit row (Forms_Integration::row_snapshot()'s shape). */
    private static function status_snapshot(array $args): ?array
    {
        $table = Database_Guard::valid_table(self::table('submit'));
        $row   = self::entry_row((int) $args['entry_id']);
        if (is_wp_error($table) || null === $row) {
            return null;
        }
        $where  = [ 'id' => (int) $args['entry_id'] ];
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
                'set'         => self::status_set($args, $row),
                'rows'        => $before,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function update_status(array $args): array
    {
        global $wpdb;
        $id       = (int) $args['entry_id'];
        $row      = (array) self::entry_row($id);
        $previous = self::entry_summary($row);
        $set      = self::status_set($args, $row);
        if (false === $wpdb->update(self::table('submit'), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update WS Form submission %d: %s', $id, $wpdb->last_error));
        }
        Database_Guard::invalidate_caches(self::table('submit'), [ 'where' => [ 'id' => $id ] ]);
        self::recount((int) $row['form_id']);

        return [ 'entry' => self::entry_summary((array) self::entry_row($id)), 'previous' => array_intersect_key($previous, [ 'status' => 1, 'starred' => 1, 'viewed' => 1 ]) ];
    }

    /** Refresh the form's cached counts the way WS Form does after its own writes, when WS Form is loaded. */
    private static function recount(int $form_id): void
    {
        if (! class_exists('WS_Form_Form')) {
            return;
        }
        try {
            $form     = new \WS_Form_Form();
            $form->id = $form_id;
            $form->db_count_update(false, true);
        } catch (\Throwable $e) {
            // The counts are a cache WS Form rebuilds itself; the write stands.
            unset($e);
        }
    }

    /** @return array{message: string, data: array} */
    private static function delete_preview(int $entry_id): array
    {
        global $wpdb;
        $row    = (array) self::entry_row($entry_id);
        $values = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE parent_id = %d', self::table('submit_meta'), $entry_id));
        $notes  = Plugin_Table_Rows_Snapshot::table_exists(self::table('submit_note'))
            ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE submit_id = %d', self::table('submit_note'), $entry_id))
            : 0;
        $form   = self::form_row((int) $row['form_id']);

        return [
            'message' => sprintf(
                'It permanently deletes submission %d of WS Form form "%s" (%d) with %d stored values and %d notes. Undo: rollback-session.',
                $entry_id,
                null === $form ? '' : (string) $form['label'],
                (int) $row['form_id'],
                $values,
                $notes
            ),
            'data'    => [ 'entry_id' => $entry_id, 'form_id' => (int) $row['form_id'], 'values' => $values, 'notes' => $notes ],
        ];
    }

    /**
     * Delete a submission the way WS_Form_Submit::db_delete(true) does
     * (values, notes, then the submission row), each table's rows under its
     * own db_rows before-image in the call's session. Files stay.
     *
     * @return array{result: array, operation_ids: string[]}
     */
    private static function delete_entry(int $entry_id, array $args, array $context): array
    {
        $summary = self::entry_summary((array) self::entry_row($entry_id));
        $ids     = [];
        foreach ([ [ 'submit_meta', 'parent_id' ], [ 'submit_note', 'submit_id' ], [ 'submit', 'id' ] ] as [$name, $column]) {
            if ('submit_note' === $name && ! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                continue;
            }
            $table = Database_Guard::valid_table(self::table($name));
            if (is_wp_error($table)) {
                throw new Operation_Refused('ws_form_tables_missing', sprintf('WS Form\'s table %s is missing.', self::table($name)));
            }
            $where  = [ $column => $entry_id ];
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
        self::recount($summary['form_id']);

        return [ 'result' => [ 'deleted' => $summary ], 'operation_ids' => $ids ];
    }
}
