<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Everest Forms (issue #676) on the plugin-data pair, free tier, so it adds
 * no top-level tools. The op names follow the WS Form ops (list-forms,
 * get-form, list-fields, list-notifications, list-entries, get-entry,
 * update-entry-status, delete-entry), prefixed everestforms-.
 *
 * Everest Forms (EVF_VERSION, read in 3.6.4) keeps:
 *  - each form as an everest_form post whose post_content is JSON:
 *    form_fields (field id => id, type, label, meta-key, required, choices
 *    keyed 1..n), settings (form_title, successful_form_submission_message,
 *    submit_button_text, disabled_entries, and email: connection id =>
 *    connection_name, evf_to_email, evf_from_name, evf_from_email,
 *    evf_reply_to, evf_email_subject, evf_email_message and
 *    enable_email_notification, '1' or absent when on) and structure
 *    (row => grid => field ids, the order the form shows them in);
 *  - each entry as a {prefix}evf_entries row (form_id, user_id,
 *    user_device, user_ip_address, referer, the `fields` JSON of every
 *    formatted field, status, viewed, starred, date_created) with one
 *    {prefix}evf_entrymeta row per field value keyed by its meta key
 *    (EVF_Form_Task::entry_save()). Keys starting with an underscore are
 *    Everest Forms' own bookkeeping, such as _evf_trash_entry_status.
 * Entry statuses are publish, spam, trash and draft (a partial entry saved
 * by an add-on), plus pending, approved and denied with entry approval.
 *
 * Reads: everestforms-list-forms, everestforms-get-form,
 * everestforms-list-fields, everestforms-list-notifications,
 * everestforms-list-entries (every value masked, no IP, user agent or
 * referer) and everestforms-get-entry (one entry in full). Reads never mark
 * an entry read, unlike Everest Forms' own evf_get_entry().
 *
 * Writes (off until a site opts in with wpmcp_enable_everest_forms_write):
 *  - everestforms-update-entry-status sets status (publish or spam, the
 *    plugin's Spam and Not Spam), starred and viewed on the evf_entries row
 *    as EVF_Admin_Entries::update_status() writes them, under a db_rows
 *    before-image of that row (undo: rollback-operation). Trashing is left
 *    out, and so is any status change of an entry in trash, draft or
 *    approval: Everest Forms records the status a trashed entry had in an
 *    entry meta row and reads it back on restore, and its approval statuses
 *    email the submitter; neither fits a one-row undo. Delete is the
 *    undoable way to remove an entry;
 *  - everestforms-delete-entry permanently deletes an entry and its meta
 *    rows, as EVF_Admin_Entries::remove_entry() does, and asks for
 *    confirm:true first. Each table's rows are captured as a db_rows
 *    before-image right before they go, all in one session, so
 *    rollback-session reinserts them with their original ids. Uploaded
 *    files stay on disk.
 *
 * Every op takes manage_options: submissions are personal data, and Everest
 * Forms grants its entry capabilities to administrators.
 *
 * Presence is filterable (wpmcp_everest_forms_active, default: EVF_VERSION
 * is defined). Without Everest Forms the ops stay documented in
 * list-operations (dependency_met:false) and answer plugin_missing or
 * plugin_inactive with the plugins screen to fix it from; with Everest Forms
 * but no entry tables yet the entry ops answer everest_forms_tables_missing.
 */
final class Everest_Forms_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Everest Forms' own entry tables live; it has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_everest_forms_write';

    public const POST_TYPE = 'everest_form';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILE = 'everest-forms/everest-forms.php';

    /** Statuses an entry can have, evf_get_entry_statuses() plus draft and the approval ones. */
    private const ENTRY_STATUSES = [ 'publish', 'spam', 'trash', 'draft', 'pending', 'approved', 'denied' ];

    /** What update-entry-status may set and change between. */
    private const WRITABLE_STATUSES = [ 'publish', 'spam' ];

    /** Field types whose choices sit in the field's 'choices'. */
    private const CHOICE_TYPES = [ 'checkbox', 'radio', 'select', 'payment-checkbox', 'payment-multiple' ];

    /** Field types Everest Forms never stores a value for. */
    private const LAYOUT_TYPES = [ 'html', 'title' ];

    /** Whether Everest Forms is loaded (filterable). */
    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_everest_forms_active', defined('EVF_VERSION'));
    }

    /** One of Everest Forms' tables, entries or entrymeta. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'evf_' . $name;
    }

    /**
     * true when Everest Forms is loaded, otherwise plugin_inactive (its
     * files are installed) or plugin_missing, naming the plugins screen.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
        $url       = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Everest Forms is %s on this site. Install and activate Everest Forms (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'everest-forms', 'plugins_url' => $url ],
        ];
    }

    /**
     * presence(), then the entry tables Everest Forms creates on activation.
     *
     * @return true|array{code: string, message: string, data?: array}
     */
    public static function tables_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach ([ 'entries', 'entrymeta' ] as $name) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                return [
                    'code'    => 'everest_forms_tables_missing',
                    'message' => sprintf('Everest Forms\' table %s does not exist on this site. Deactivate and reactivate Everest Forms so it creates its tables, then try again.', self::table($name)),
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
        $form_obj  = [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ];
        $write     = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_everest_forms_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $form      = static fn (array $args): ?array => self::form_refusal((int) $args['form_id']);

        return [
            'everestforms-list-forms'          => $base + [
                'mode'         => 'read',
                'description'  => 'List Everest Forms forms: id, title, status, shortcode, and entry counts (total, unread, spam, trash). status filters (publish, draft, trash); trash is left out unless asked for',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'trash' ] ] ] ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'everestforms-get-form'            => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'Read one Everest Forms form: title, status, shortcode, entry counts, its fields in form order, and its main settings (success message, redirect, submit button text, whether entries are stored)',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form' => self::form((int) $args['form_id']) ],
            ],
            'everestforms-list-fields'         => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'List one Everest Forms form\'s fields in form order: id, key (the meta key its value is stored under), type, label, required, and the choices of checkbox, radio and select fields',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'fields' => self::fields((int) $args['form_id']) ],
            ],
            'everestforms-list-notifications'  => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'List one Everest Forms form\'s email notifications: connection id, name, active, to, from name and email, reply-to, subject and message template',
                'input_schema' => $form_only,
                'validate'     => $form,
                'handler'      => static fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'notifications' => self::notifications((int) $args['form_id']) ],
            ],
            'everestforms-list-entries'        => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'List an Everest Forms form\'s entries newest first: id, status, starred, viewed, date and every field value MASKED (everestforms-get-entry returns one in full). status: publish, spam, trash, draft, pending, approved or denied; by default all but spam, trash and draft. page_size (default 20, max 100) and offset. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => $id,
                        'status'    => [ 'type' => 'string', 'enum' => self::ENTRY_STATUSES ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'validate'     => $form,
                'handler'      => static fn (array $args): array => self::list_entries($args),
            ],
            'everestforms-get-entry'           => $base + [
                'mode'         => 'read',
                'description'  => 'One Everest Forms entry in full: status, starred, viewed, date, user, every field value (id, key, label, type, value), IP address, user agent and referer. Does not mark it read. Personal data',
                'input_schema' => $entry,
                'validate'     => static fn (array $args): ?array => self::entry_refusal((int) $args['entry_id']),
                'handler'      => static fn (array $args): array => [ 'entry' => self::entry((int) $args['entry_id']) ],
            ],
            'everestforms-update-entry-status' => $write + [
                'mode'         => 'write',
                'description'  => 'Mark an Everest Forms entry spam or not spam (status spam or publish), starred and/or read (viewed). Entries in trash, draft or approval keep their status. Undo: rollback-operation',
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
            'everestforms-delete-entry'        => $write + [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'description'       => 'Permanently delete an Everest Forms entry with its stored values. Uploaded files stay on disk. Needs confirm:true. Undo: rollback-session reinserts every row',
                'input_schema'      => $entry,
                'validate'          => static fn (array $args): ?array => self::entry_refusal((int) $args['entry_id']),
                'confirm_preview'   => static fn (array $args): array => self::delete_preview((int) $args['entry_id']),
                'handler'           => static fn (array $args, array $context): array => self::delete_entry((int) $args['entry_id'], $args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    /** The everest_form post, or null. */
    private static function form_post(int $form_id): ?\WP_Post
    {
        $post = get_post($form_id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? $post : null;
    }

    /** The form's decoded JSON, as evf_decode() reads it. @return array<string, mixed> */
    private static function content(\WP_Post $post): array
    {
        $data = json_decode((string) $post->post_content, true);
        return is_array($data) ? $data : [];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function form_refusal(int $form_id): ?array
    {
        if (null !== self::form_post($form_id)) {
            return null;
        }
        return [ 'code' => 'form_not_found', 'message' => sprintf('Everest Forms has no form %d.', $form_id), 'data' => [ 'form_id' => $form_id ] ];
    }

    /**
     * Entry counts per form, counted from evf_entries as the entries
     * screen counts them: total leaves out spam, trash and drafts, unread
     * is unviewed among those.
     *
     * @param int[] $form_ids
     * @return array<int, array{total: int, unread: int, spam: int, trash: int}>
     */
    private static function counts(array $form_ids): array
    {
        global $wpdb;
        $out = [];
        foreach ($form_ids as $form_id) {
            $out[ $form_id ] = [ 'total' => 0, 'unread' => 0, 'spam' => 0, 'trash' => 0 ];
        }
        if ([] === $form_ids) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($form_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT form_id, status, viewed, COUNT(*) AS n FROM %i WHERE form_id IN ($in) GROUP BY form_id, status, viewed", self::table('entries'), ...$form_ids), ARRAY_A);
        foreach ($rows as $row) {
            $form_id = (int) $row['form_id'];
            $n       = (int) $row['n'];
            $status  = (string) $row['status'];
            if (in_array($status, [ 'spam', 'trash' ], true)) {
                $out[ $form_id ][ $status ] += $n;
                continue;
            }
            if ('draft' === $status) {
                continue;
            }
            $out[ $form_id ]['total'] += $n;
            if (0 === (int) $row['viewed']) {
                $out[ $form_id ]['unread'] += $n;
            }
        }
        return $out;
    }

    /** One form as the form reads return it. */
    private static function form_summary(\WP_Post $post, array $counts): array
    {
        $id = (int) $post->ID;
        return [
            'id'           => $id,
            'title'        => (string) $post->post_title,
            'status'       => (string) $post->post_status,
            'shortcode'    => sprintf('[everest_form id="%d"]', $id),
            'date_created' => (string) $post->post_date_gmt,
            'date_updated' => (string) $post->post_modified_gmt,
            'entries'      => $counts[ $id ] ?? [ 'total' => 0, 'unread' => 0, 'spam' => 0, 'trash' => 0 ],
        ];
    }

    /** @return array<string, mixed> */
    private static function list_forms(array $args): array
    {
        $status = isset($args['status']) ? (string) $args['status'] : [ 'publish', 'draft' ];
        $posts  = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => $status,
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
        ]);
        $posts  = array_values(array_filter($posts, static fn ($p): bool => $p instanceof \WP_Post));
        $counts = self::counts(array_map(static fn (\WP_Post $p): int => (int) $p->ID, $posts));
        $forms  = array_map(static fn (\WP_Post $p): array => self::form_summary($p, $counts), $posts);

        return [ 'forms' => $forms, 'total' => count($forms) ];
    }

    /** @return array<string, mixed> */
    private static function form(int $form_id): array
    {
        // validate has already refused an id that is not a form.
        $post     = self::form_post($form_id);
        $settings = (array) ((null === $post ? [] : self::content($post))['settings'] ?? []);
        $summary  = null === $post ? [ 'id' => $form_id ] : self::form_summary($post, self::counts([ $form_id ]));

        return $summary + [
            'fields'   => self::fields($form_id),
            'settings' => [
                'success_message'    => (string) ($settings['successful_form_submission_message'] ?? ''),
                'redirect_to'        => (string) ($settings['redirect_to'] ?? 'same'),
                'redirect_page_id'   => isset($settings['custom_page']) ? (int) $settings['custom_page'] : null,
                'redirect_url'       => (string) ($settings['external_url'] ?? ''),
                'submit_button_text' => (string) ($settings['submit_button_text'] ?? ''),
                'entries_stored'     => '1' !== (string) ($settings['disabled_entries'] ?? ''),
            ],
        ];
    }

    /**
     * The form's field definitions in the order its layout shows them
     * (structure rows and grids), then any field the layout does not name.
     *
     * @return array<string, array<string, mixed>> field id => definition
     */
    private static function field_defs(array $content): array
    {
        $defs  = array_filter((array) ($content['form_fields'] ?? []), 'is_array');
        $order = [];
        foreach ((array) ($content['structure'] ?? []) as $row) {
            foreach ((array) $row as $grid) {
                foreach ((array) $grid as $field_id) {
                    $field_id = (string) $field_id;
                    if (isset($defs[ $field_id ]) && ! isset($order[ $field_id ])) {
                        $order[ $field_id ] = $defs[ $field_id ];
                    }
                }
            }
        }
        foreach ($defs as $field_id => $def) {
            if (! isset($order[ (string) $field_id ])) {
                $order[ (string) $field_id ] = $def;
            }
        }
        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fields(int $form_id): array
    {
        $post = self::form_post($form_id);
        $out  = [];
        foreach (self::field_defs(null === $post ? [] : self::content($post)) as $field_id => $def) {
            $type = (string) ($def['type'] ?? '');
            $item = [
                'id'       => (string) $field_id,
                'key'      => (string) ($def['meta-key'] ?? ''),
                'type'     => $type,
                'label'    => (string) ($def['label'] ?? ''),
                'required' => '1' === (string) ($def['required'] ?? ''),
            ];
            if (in_array($type, self::CHOICE_TYPES, true)) {
                $item['choices'] = array_values(array_map(static fn ($c): string => is_array($c) ? (string) ($c['label'] ?? '') : (string) $c, (array) ($def['choices'] ?? [])));
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function notifications(int $form_id): array
    {
        $post     = self::form_post($form_id);
        $settings = (array) ((null === $post ? [] : self::content($post))['settings'] ?? []);
        $out      = [];
        foreach ((array) ($settings['email'] ?? []) as $connection_id => $n) {
            if (! is_array($n)) {
                continue;
            }
            $out[] = [
                'id'         => (string) $connection_id,
                'name'       => (string) ($n['connection_name'] ?? ''),
                // EVF_Form_Task::entry_email(): off only when set and not '1'.
                'active'     => ! isset($n['enable_email_notification']) || '1' === (string) $n['enable_email_notification'],
                'to'         => (string) ($n['evf_to_email'] ?? ''),
                'from_name'  => (string) ($n['evf_from_name'] ?? ''),
                'from_email' => (string) ($n['evf_from_email'] ?? ''),
                'reply_to'   => (string) ($n['evf_reply_to'] ?? ''),
                'subject'    => (string) ($n['evf_email_subject'] ?? ''),
                'message'    => (string) ($n['evf_email_message'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Entries
    // ------------------------------------------------------------------

    /** The raw evf_entries row, or null. */
    private static function entry_row(int $entry_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE entry_id = %d', self::table('entries'), $entry_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function entry_refusal(int $entry_id): ?array
    {
        if (null !== self::entry_row($entry_id)) {
            return null;
        }
        return [ 'code' => 'entry_not_found', 'message' => sprintf('Everest Forms has no entry %d.', $entry_id), 'data' => [ 'entry_id' => $entry_id ] ];
    }

    /** One evf_entries row as the entry reads return it, without the visit data. */
    private static function entry_summary(array $row): array
    {
        return [
            'id'           => (int) $row['entry_id'],
            'form_id'      => (int) $row['form_id'],
            'status'       => (string) $row['status'],
            'starred'      => (bool) (int) $row['starred'],
            'viewed'       => (bool) (int) $row['viewed'],
            'user_id'      => (int) $row['user_id'],
            'date_created' => (string) $row['date_created'],
        ];
    }

    /** A checkbox or multiple-choice value as its chosen label(s); anything else as stored. @return mixed */
    private static function plain_value($value)
    {
        if (is_array($value) && array_key_exists('label', $value) && array_key_exists('type', $value)) {
            return $value['label'];
        }
        return $value;
    }

    /**
     * An entry's field values in form order: from the `fields` JSON
     * entry_save() wrote, or, when that is empty, from the meta rows,
     * matched to the form's fields by meta key.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function values(array $row, array $defs, array $meta_rows): array
    {
        $stored = json_decode((string) ($row['fields'] ?? ''), true);
        $by_id  = [];
        if (is_array($stored) && [] !== $stored) {
            foreach ($stored as $key => $field) {
                if (is_array($field) && ! in_array((string) ($field['type'] ?? ''), self::LAYOUT_TYPES, true)) {
                    $by_id[ (string) ($field['id'] ?? $key) ] = $field;
                }
            }
        } else {
            $by_key = [];
            foreach ($defs as $field_id => $def) {
                $by_key[ sanitize_key((string) ($def['meta-key'] ?? '')) ] = (string) $field_id;
            }
            foreach ($meta_rows as $meta) {
                $key = (string) $meta['meta_key'];
                if ('' === $key || '_' === $key[0]) {
                    continue;
                }
                $field_id           = $by_key[ $key ] ?? $key;
                $by_id[ $field_id ] = [ 'id' => $field_id, 'stored_key' => $key, 'type' => (string) ($defs[ $field_id ]['type'] ?? ''), 'value' => maybe_unserialize((string) $meta['meta_value']) ];
            }
        }

        $out = [];
        foreach (array_keys($defs + $by_id) as $field_id) {
            if (! isset($by_id[ $field_id ])) {
                continue;
            }
            $field = $by_id[ $field_id ];
            $def   = $defs[ $field_id ] ?? [];
            $out[] = [
                'id'       => (string) $field_id,
                'key'      => (string) ($field['meta_key'] ?? ($field['stored_key'] ?? ($def['meta-key'] ?? ''))),
                'label'    => isset($def['label']) ? (string) $def['label'] : (isset($field['name']) ? (string) $field['name'] : null),
                'type'     => (string) ($field['type'] ?? ($def['type'] ?? '')),
                'value'    => self::plain_value($field['value'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> the field definitions of a form, in form order. */
    private static function defs_of(int $form_id): array
    {
        $post = self::form_post($form_id);
        return null === $post ? [] : self::field_defs(self::content($post));
    }

    /** @return array<int, array<int, array<string, mixed>>> entry id => its meta rows, oldest first. */
    private static function meta_rows(array $entry_ids): array
    {
        global $wpdb;
        if ([] === $entry_ids) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($entry_ids), '%d'));
        $out = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE entry_id IN ($in) ORDER BY meta_id ASC", self::table('entrymeta'), ...$entry_ids), ARRAY_A) as $meta) {
            $out[ (int) $meta['entry_id'] ][] = $meta;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_entries(array $args): array
    {
        global $wpdb;
        $form_id = (int) $args['form_id'];
        $size    = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));

        if (isset($args['status'])) {
            $where = $wpdb->prepare('form_id = %d AND status = %s', $form_id, (string) $args['status']);
        } else {
            $where = $wpdb->prepare("form_id = %d AND status NOT IN ('spam', 'trash', 'draft')", $form_id);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built above with prepare().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::table('entries')));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built above with prepare().
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY date_created DESC, entry_id DESC LIMIT %d OFFSET %d", self::table('entries'), $size, $offset), ARRAY_A);

        $defs    = self::defs_of($form_id);
        $metas   = self::meta_rows(array_map('intval', array_column($rows, 'entry_id')));
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = self::entry_summary($row) + [
                'fields' => array_map(static function (array $field): array {
                    $field['value'] = Masking::value($field['value']);
                    return $field;
                }, self::values($row, $defs, $metas[ (int) $row['entry_id'] ] ?? [])),
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
        $row  = (array) self::entry_row($entry_id);
        $user = (int) $row['user_id'] > 0 ? get_userdata((int) $row['user_id']) : false;

        return self::entry_summary($row) + [
            'user_name'  => $user instanceof \WP_User ? $user->display_name : null,
            'fields'     => self::values($row, self::defs_of((int) $row['form_id']), self::meta_rows([ $entry_id ])[ $entry_id ] ?? []),
            'ip_address' => (string) ($row['user_ip_address'] ?? ''),
            'user_agent' => (string) $row['user_device'],
            'referer'    => (string) $row['referer'],
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
        $refusal = self::entry_refusal((int) $args['entry_id']);
        if (null !== $refusal || ! isset($args['status'])) {
            return $refusal;
        }
        $current = (string) ((array) self::entry_row((int) $args['entry_id']))['status'];
        if (! in_array($current, self::WRITABLE_STATUSES, true)) {
            return [
                'code'    => 'entry_status_locked',
                'message' => sprintf('Everest Forms entry %d is %s; only publish and spam entries change status here. Restore or approve it in Everest Forms > Entries.', (int) $args['entry_id'], $current),
                'data'    => [ 'entry_id' => (int) $args['entry_id'], 'status' => $current ],
            ];
        }
        return null;
    }

    /** The evf_entries columns update-entry-status writes. */
    private static function status_set(array $args): array
    {
        $set = [];
        if (isset($args['status'])) {
            $set['status'] = (string) $args['status'];
        }
        if (isset($args['starred'])) {
            $set['starred'] = $args['starred'] ? 1 : 0;
        }
        if (isset($args['viewed'])) {
            $set['viewed'] = $args['viewed'] ? 1 : 0;
        }
        return $set;
    }

    /** A db_rows before-image of the one evf_entries row. */
    private static function status_snapshot(array $args): ?array
    {
        $table = Database_Guard::valid_table(self::table('entries'));
        if (is_wp_error($table) || null === self::entry_row((int) $args['entry_id'])) {
            return null;
        }
        $where  = [ 'entry_id' => (int) $args['entry_id'] ];
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
                'primary_key' => [ 'entry_id' ],
                'where'       => $where,
                'set'         => self::status_set($args),
                'rows'        => $before,
            ],
        ];
    }

    /** Drop Everest Forms' own object cache of an entry, the keys entry_save() drops. */
    private static function flush_entry_cache(int $entry_id, int $form_id): void
    {
        wp_cache_delete($entry_id, 'evf-entry');
        wp_cache_delete($entry_id, 'evf-entrymeta');
        wp_cache_delete($form_id, 'evf-entries-ids');
        wp_cache_delete($form_id, 'evf-last-entries-count');
        wp_cache_delete($form_id, 'evf-search-entries');
    }

    /** @return array<string, mixed> */
    private static function update_status(array $args): array
    {
        global $wpdb;
        $id       = (int) $args['entry_id'];
        $row      = (array) self::entry_row($id);
        $previous = self::entry_summary($row);
        if (false === $wpdb->update(self::table('entries'), self::status_set($args), [ 'entry_id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update Everest Forms entry %d: %s', $id, $wpdb->last_error));
        }
        Database_Guard::invalidate_caches(self::table('entries'), [ 'where' => [ 'entry_id' => $id ] ]);
        self::flush_entry_cache($id, (int) $row['form_id']);

        return [ 'entry' => self::entry_summary((array) self::entry_row($id)), 'previous' => array_intersect_key($previous, [ 'status' => 1, 'starred' => 1, 'viewed' => 1 ]) ];
    }

    /** @return array{message: string, data: array} */
    private static function delete_preview(int $entry_id): array
    {
        global $wpdb;
        $row    = (array) self::entry_row($entry_id);
        $values = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE entry_id = %d', self::table('entrymeta'), $entry_id));
        $form   = self::form_post((int) $row['form_id']);

        return [
            'message' => sprintf(
                'It permanently deletes entry %d of Everest Forms form "%s" (%d) with %d stored values. Undo: rollback-session.',
                $entry_id,
                null === $form ? '' : (string) $form->post_title,
                (int) $row['form_id'],
                $values
            ),
            'data'    => [ 'entry_id' => $entry_id, 'form_id' => (int) $row['form_id'], 'values' => $values ],
        ];
    }

    /**
     * Delete an entry the way EVF_Admin_Entries::remove_entry() does (the
     * entry row and its meta rows), each table's rows under its own db_rows
     * before-image in the call's session. Files stay.
     *
     * @return array{result: array, operation_ids: string[]}
     */
    private static function delete_entry(int $entry_id, array $args, array $context): array
    {
        $summary = self::entry_summary((array) self::entry_row($entry_id));
        $ids     = [];
        foreach ([ [ 'entrymeta', 'meta_id' ], [ 'entries', 'entry_id' ] ] as [$name, $primary]) {
            $table = Database_Guard::valid_table(self::table($name));
            if (is_wp_error($table)) {
                throw new Operation_Refused('everest_forms_tables_missing', sprintf('Everest Forms\' table %s is missing.', self::table($name)));
            }
            $where  = [ 'entry_id' => $entry_id ];
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
                        'primary_key' => [ $primary ],
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
        self::flush_entry_cache($entry_id, $summary['form_id']);

        return [ 'result' => [ 'deleted' => $summary ], 'operation_ids' => $ids ];
    }
}
