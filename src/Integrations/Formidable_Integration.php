<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Formidable Forms integration (wpmcp/formidable-read / -write pair), part of
 * the forms adapter pack (issue #66, pro tier). Delegates to Formidable's own
 * models (verified against Formidable 6.x): FrmForm for forms, FrmField for
 * fields, FrmFormAction for email notifications (Formidable models them as
 * "email" form actions), and FrmEntry for entries.
 *
 * Entries are user data, so the entry operations sit behind manage_options on
 * top of the pair's own capability.
 *
 * Issue #674: the email actions (notifications) and the on_submit actions
 * (confirmations) are editable, off by default, each write snapshotting the
 * action post.
 *
 * Issue #675: delete-entry removes the entry's rows directly in one session
 * of db_rows snapshots, so rollback-session restores them.
 *
 * No entry-status write: Formidable has no spam or trash state for an entry
 * (an entry is either a draft or submitted, and a deleted entry is gone), so
 * there is nothing for update-entry-status to move between. The write half is
 * registered with no operations so the surface stays stable if that changes.
 */
class Formidable_Integration extends Forms_Integration
{
    public function integration(): string
    {
        return 'formidable';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return class_exists('FrmForm') && class_exists('FrmEntry');
    }

    protected function summary(): string
    {
        return 'Formidable Forms (forms, fields, notifications, and entries)';
    }

    private static function shape_entry(object $entry, bool $with_values): array
    {
        $out = [
            'id'         => (int) ($entry->id ?? 0),
            'item_key'   => (string) ($entry->item_key ?? ''),
            'form_id'    => (int) ($entry->form_id ?? 0),
            'is_draft'   => ! empty($entry->is_draft),
            'created_at' => (string) ($entry->created_at ?? ''),
        ];
        if ($with_values) {
            $out['values'] = is_array($entry->metas ?? null) ? $entry->metas : [];
            $out['ip']     = (string) ($entry->ip ?? '');
        }
        return $out;
    }

    protected function operations(): array
    {
        return $this->read_operations() + self::config_operations();
    }

    protected function read_operations(): array
    {
        $form_only = [
            'type'       => 'object',
            'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'form_id' ],
        ];

        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Formidable forms with id, name, and form key',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = \FrmForm::getAll();
                    $out   = [];
                    foreach ((array) $forms as $form) {
                        $out[] = [
                            'id'   => (int) ($form->id ?? 0),
                            'name' => (string) ($form->name ?? ''),
                            'key'  => (string) ($form->form_key ?? ''),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Formidable form (name, key, description, options)',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form = \FrmForm::getOne((int) $args['form_id']);
                    return [ 'form' => empty($form) ? null : $form ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: id, key, type, label, whether it is required, and options for choice fields',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (empty(\FrmForm::getOne($form_id))) {
                        return [ 'form_id' => $form_id, 'fields' => null ];
                    }
                    $fields = [];
                    foreach ((array) \FrmField::get_all_for_form($form_id) as $field) {
                        $options  = is_array($field->options ?? null) ? $field->options : [];
                        $fields[] = [
                            'id'       => (int) ($field->id ?? 0),
                            'key'      => (string) ($field->field_key ?? ''),
                            'type'     => (string) ($field->type ?? ''),
                            'label'    => (string) ($field->name ?? ''),
                            'required' => ! empty($field->required),
                            'options'  => array_values(array_map(static fn ($o) => (string) (is_array($o) ? ($o['label'] ?? ($o['value'] ?? '')) : $o), $options)),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications (Formidable "email" form actions): id, name, whether it is active, recipient, sender, subject, and message template',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (empty(\FrmForm::getOne($form_id))) {
                        return [ 'form_id' => $form_id, 'notifications' => null ];
                    }
                    $out = [];
                    foreach ((array) \FrmFormAction::get_action_for_form($form_id, 'email') as $action) {
                        $settings = is_array($action->post_content ?? null) ? $action->post_content : [];
                        $out[]    = [
                            'id'      => (int) ($action->ID ?? 0),
                            'name'    => (string) ($action->post_title ?? ''),
                            'active'  => 'publish' === ($action->post_status ?? ''),
                            'to'      => (string) ($settings['email_to'] ?? ''),
                            'from'    => (string) ($settings['from'] ?? ''),
                            'subject' => (string) ($settings['email_subject'] ?? ''),
                            'message' => (string) ($settings['email_message'] ?? ''),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a form\'s entries (submissions), newest first, with paging (page_size default 20, max 100, plus offset). Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id                = (int) $args['form_id'];
                    [ $page_size, $offset ] = self::page_window($args);
                    $entries                = \FrmEntry::getAll(
                        [ 'it.form_id' => $form_id ],
                        ' ORDER BY it.created_at DESC, it.id DESC',
                        sprintf(' LIMIT %d,%d', $offset, $page_size)
                    );
                    $out = [];
                    foreach ((array) $entries as $entry) {
                        if (is_object($entry)) {
                            $out[] = self::shape_entry($entry, false);
                        }
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $out,
                        'total'   => (int) \FrmEntry::getRecordCount($form_id),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Read a single entry with its field values by entry id. Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \FrmEntry::getOne((int) $args['entry_id'], true);
                    return [ 'entry' => is_object($entry) ? self::shape_entry($entry, true) : null ];
                },
            ],
            'delete-entry' => [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'objects'           => [ 'entry_id' => 'entry' ],
                'capability'        => self::ENTRY_CAPABILITY,
                'description'       => 'Permanently delete one Formidable entry with its values and, for a repeater or embedded form, its child entries; refused with entry_has_payments when it has a payment or subscription record (the rows of frm_items and frm_item_metas, removed directly: Formidable\'s own delete hooks do not run and uploaded files stay on disk). Off by default (opt in with wpmcp_enable_forms_write); needs confirm:true and manage_options. Reversible: every row is snapshotted first and rollback-session puts them back. ' . self::NOT_ERASURE,
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'validate'          => static fn (array $args): ?array => is_object(\FrmEntry::getOne((int) $args['entry_id']))
                    ? self::payment_refusal((int) $args['entry_id'], [ [ 'frm_payments', 'item_id' ], [ 'frm_subscriptions', 'item_id' ] ])
                    : [ 'code' => 'entry_not_found', 'message' => 'Formidable has no entry with that id.', 'data' => [ 'entry_id' => (int) $args['entry_id'] ] ],
                'confirm_preview'   => static function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $entry    = \FrmEntry::getOne($entry_id);
                    $children = self::child_entry_ids($entry_id);
                    $values   = self::count_rows('frm_item_metas', 'item_id', $entry_id);
                    return self::delete_entry_preview(
                        sprintf('Formidable entry %d of form %d', $entry_id, (int) ($entry->form_id ?? 0)),
                        $entry_id,
                        $values,
                        'rollback-session',
                        [ 'form_id' => (int) ($entry->form_id ?? 0), 'date' => (string) ($entry->created_at ?? ''), 'child_entries' => count($children) ],
                        true,
                        sprintf(', %d child entries', count($children))
                    );
                },
                'handler'           => static function (array $args, array $context): array {
                    $entry_id = (int) $args['entry_id'];
                    $steps    = [];
                    foreach (array_merge(self::child_entry_ids($entry_id), [ $entry_id ]) as $item) {
                        $steps[] = [ 'table' => 'frm_item_metas', 'primary_key' => [ 'id' ], 'where' => [ 'item_id' => $item ], 'optional' => true ];
                        $steps[] = [ 'table' => 'frm_items', 'primary_key' => [ 'id' ], 'where' => [ 'id' => $item ] ];
                    }
                    $done = self::delete_rows_in_session($steps, $args, $context);
                    if (class_exists('FrmEntry') && method_exists('FrmEntry', 'clear_cache')) {
                        \FrmEntry::clear_cache();
                    }
                    return [
                        'result'        => [ 'entry_id' => $entry_id, 'deleted' => ($done['result']['frm_items'] ?? 0) > 0, 'rows' => $done['result'] ],
                        'operation_ids' => $done['operation_ids'],
                    ];
                },
            ] + self::write_flags(),
        ];
    }

    /**
     * Ids of the child entries of an entry (repeater and embedded forms keep
     * theirs in frm_items.parent_item_id; the column exists on Formidable
     * installs that have those, and the lookup answers [] where it does not).
     *
     * @return int[]
     */
    private static function child_entry_ids(int $entry_id): array
    {
        global $wpdb;

        $table = Database_Guard::valid_table($wpdb->prefix . 'frm_items');
        if (is_wp_error($table)) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a schema probe of the host plugin's own table.
        $has = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'parent_item_id'));
        if (! $has) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the child entry ids of the host plugin's own table fresh, so the delete covers every row.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE parent_item_id = %d ORDER BY id', $table, $entry_id)));
    }

    // ------------------------------------------------------------------
    // Notification and confirmation edits (issue #674)
    // ------------------------------------------------------------------

    private const ACTION_POST_TYPE = 'frm_form_actions';

    /** @return array<string, mixed>|null a refusal when no Formidable form has that id */
    private static function form_refusal(int $form_id): ?array
    {
        if (! empty(\FrmForm::getOne($form_id))) {
            return null;
        }

        return [ 'code' => 'form_not_found', 'message' => 'No Formidable form has that id.', 'data' => [ 'form_id' => $form_id ] ];
    }

    /**
     * One action post of a form, as stored: its post and decoded settings.
     * Null when the id is not an action of that type on that form.
     *
     * @return array{post: \WP_Post, settings: array<string, mixed>}|null
     */
    private static function action_of(int $form_id, int $action_id, string $type): ?array
    {
        $post = get_post($action_id);
        if (! $post instanceof \WP_Post || self::ACTION_POST_TYPE !== $post->post_type || (int) $post->menu_order !== $form_id || sanitize_title($post->post_excerpt) !== $type) {
            return null;
        }
        $settings = json_decode((string) $post->post_content, true);

        return [ 'post' => $post, 'settings' => is_array($settings) ? $settings : [] ];
    }

    /** Save an action through Formidable's own save (FrmDb::save_settings) when it is loaded. */
    private static function save_action(\WP_Post $post, array $settings, ?string $status = null, ?string $title = null): void
    {
        $record = [
            'ID'           => $post->ID,
            'post_type'    => $post->post_type,
            'post_title'   => $title ?? $post->post_title,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => $status ?? $post->post_status,
            'menu_order'   => $post->menu_order,
            'post_content' => $settings,
            // FrmDb::save_settings() updates through wp_insert_post(), which
            // stamps an update without a date with the current time. The
            // creation date is what rollback recognises the post by, so a
            // changed one made every undo fail (issue #984).
            'post_date'     => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
        ];
        if (class_exists('FrmDb') && method_exists('FrmDb', 'save_settings')) {
            $result = \FrmDb::save_settings($record, 'frm_actions');
        } else {
            $record['post_content'] = wp_slash(wp_json_encode($settings));
            $result                 = \WPMCP\Safety\Save_Filters::update_post($record, true);
        }
        if (is_wp_error($result) || ! $result) {
            throw new Operation_Error('update_failed', 'Formidable did not save the action.', [ 'action_id' => (int) $post->ID ]);
        }
        clean_post_cache($post->ID);
        if (class_exists('FrmFormAction') && method_exists('FrmFormAction', 'clear_cache')) {
            \FrmFormAction::clear_cache();
        }
    }

    /** @return array<string, mixed> */
    private static function email_view(\WP_Post $post, array $s): array
    {
        return [
            'enabled'  => 'publish' === $post->post_status,
            'name'     => (string) $post->post_title,
            'to'       => (string) ($s['email_to'] ?? ''),
            'cc'       => (string) ($s['cc'] ?? ''),
            'bcc'      => (string) ($s['bcc'] ?? ''),
            'from'     => (string) ($s['from'] ?? ''),
            'reply_to' => (string) ($s['reply_to'] ?? ''),
            'subject'  => (string) ($s['email_subject'] ?? ''),
            'message'  => (string) ($s['email_message'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private static function confirmation_view(\WP_Post $post, array $s): array
    {
        $type = (string) ($s['success_action'] ?? 'message');

        return [
            'id'       => (int) $post->ID,
            'name'     => (string) $post->post_title,
            'enabled'  => 'publish' === $post->post_status,
            'type'     => '' === $type ? 'message' : $type,
            'message'  => (string) ($s['success_msg'] ?? ''),
            'page'     => (string) ($s['success_page_id'] ?? ''),
            'redirect' => (string) ($s['success_url'] ?? ''),
        ];
    }

    /**
     * Plan an email action edit.
     *
     * @return array{0: ?array, 1: ?array} [refusal, plan]
     */
    private static function email_plan(array $args, bool $with_recipients = false): array
    {
        $form_id = (int) $args['form_id'];
        if (null !== ($refusal = self::form_refusal($form_id))) {
            return [ $refusal, null ];
        }
        $action = self::action_of($form_id, (int) $args['notification_id'], 'email');
        if (null === $action) {
            return [ [ 'code' => 'notification_not_found', 'message' => 'This form has no email action with that id (see list-notifications).', 'data' => [ 'form_id' => $form_id, 'notification_id' => (int) $args['notification_id'] ] ], null ];
        }
        $before   = self::email_view($action['post'], $action['settings']);
        $settings = $action['settings'];
        $map      = [ 'from' => 'from', 'reply_to' => 'reply_to', 'subject' => 'email_subject', 'message' => 'email_message' ];
        foreach ($map as $arg => $key) {
            if (isset($args[ $arg ])) {
                $settings[ $key ] = trim((string) $args[ $arg ]);
            }
        }
        // Who an email goes to is changed only by set-notification-recipients
        // (confirm, validation, domain filter, preview), never by the plain edit.
        if ($with_recipients) {
            foreach ([ 'to' => 'email_to', 'cc' => 'cc', 'bcc' => 'bcc' ] as $arg => $key) {
                if (isset($args[ $arg ])) {
                    $settings[ $key ] = trim((string) $args[ $arg ]);
                }
            }
        }
        $status = isset($args['enabled']) ? ($args['enabled'] ? 'publish' : 'draft') : $action['post']->post_status;
        $title  = isset($args['name']) ? (string) $args['name'] : $action['post']->post_title;
        $shadow = clone $action['post'];
        $shadow->post_status = $status;
        $shadow->post_title  = $title;

        return [ null, [ 'form_id' => $form_id, 'post' => $action['post'], 'settings' => $settings, 'status' => $status, 'title' => $title, 'before' => $before, 'after' => self::email_view($shadow, $settings) ] ];
    }

    /**
     * Plan an on_submit action edit.
     *
     * @return array{0: ?array, 1: ?array} [refusal, plan]
     */
    private static function confirmation_plan(array $args): array
    {
        $form_id = (int) $args['form_id'];
        if (null !== ($refusal = self::form_refusal($form_id))) {
            return [ $refusal, null ];
        }
        $action = self::action_of($form_id, (int) $args['confirmation_id'], 'on_submit');
        if (null === $action) {
            return [ [ 'code' => 'confirmation_not_found', 'message' => 'This form has no confirmation action with that id (see list-confirmations). A confirmation kept only in the form\'s own options cannot be edited here.', 'data' => [ 'form_id' => $form_id, 'confirmation_id' => (int) $args['confirmation_id'] ] ], null ];
        }
        $before   = self::confirmation_view($action['post'], $action['settings']);
        $settings = $action['settings'];
        if (isset($args['type'])) {
            $settings['success_action'] = (string) $args['type'];
        }
        if (isset($args['message'])) {
            $settings['success_msg'] = wp_kses_post((string) $args['message']);
        }
        if (isset($args['page'])) {
            $settings['success_page_id'] = (string) (int) $args['page'];
        }
        if (isset($args['redirect']) && (string) $args['redirect'] !== $before['redirect']) {
            if (! preg_match('#^https?://[^\s]+$#i', (string) $args['redirect'])) {
                return [ [ 'code' => 'invalid_confirmation', 'message' => 'A redirect confirmation needs an http or https url.', 'data' => [ 'form_id' => $form_id, 'confirmation_id' => (int) $args['confirmation_id'] ] ], null ];
            }
            $raw_redirect = (string) $args['redirect'];
            $redirect_problem = self::redirect_tag_problem($raw_redirect);
            $has_tags = (bool) preg_match('/[{}\[\]]/', $raw_redirect);
            if (null === $redirect_problem && ! $has_tags && esc_url_raw($raw_redirect) !== $raw_redirect) {
                $redirect_problem = 'The redirect url contains characters WordPress would strip, so it would be saved altered. Use a plain url.';
            }
            if (null !== $redirect_problem) {
                return [ [ 'code' => 'invalid_confirmation', 'message' => $redirect_problem, 'data' => [ 'form_id' => $form_id, 'confirmation_id' => (int) $args['confirmation_id'] ] ], null ];
            }
            $settings['success_url'] = $has_tags ? $raw_redirect : esc_url_raw($raw_redirect);
        }
        $status = isset($args['enabled']) ? ($args['enabled'] ? 'publish' : 'draft') : $action['post']->post_status;
        $title  = isset($args['name']) ? (string) $args['name'] : $action['post']->post_title;
        $shadow = clone $action['post'];
        $shadow->post_status = $status;
        $shadow->post_title  = $title;
        $after   = self::confirmation_view($shadow, $settings);
        $problem = self::confirmation_problem([ 'type' => $after['type'], 'message' => $after['message'], 'page' => $after['page'], 'url' => $after['redirect'], 'url_unchanged' => 'redirect' === $before['type'] && $after['redirect'] === $before['redirect'] ]);
        if (null !== $problem) {
            return [ [ 'code' => 'invalid_confirmation', 'message' => $problem, 'data' => [ 'form_id' => $form_id, 'confirmation_id' => (int) $args['confirmation_id'] ] ], null ];
        }

        return [ null, [ 'form_id' => $form_id, 'post' => $action['post'], 'settings' => $settings, 'status' => $status, 'title' => $title, 'before' => $before, 'after' => $after ] ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function config_operations(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $write = [
            'capability' => 'manage_options',
            'snapshot'   => static function (array $args): ?array {
                $action_id = (int) ($args['notification_id'] ?? $args['confirmation_id'] ?? 0);
                $post      = get_post($action_id);
                return $post instanceof \WP_Post && self::ACTION_POST_TYPE === $post->post_type && (int) $post->menu_order === (int) $args['form_id']
                    ? [ 'object_type' => 'post', 'object_id' => $action_id ]
                    : null;
            },
        ] + self::write_flags();

        return [
            'list-confirmations'          => [
                'mode'         => 'read',
                'description'  => 'List one form\'s confirmations (Formidable "on_submit" form actions): id, name, enabled, type (message, page, redirect), message, page id and redirect url. A form that still keeps its confirmation in its own options is reported as legacy, read-only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ],
                'handler'      => static function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    $form    = \FrmForm::getOne($form_id);
                    if (empty($form)) {
                        return [ 'form_id' => $form_id, 'confirmations' => null ];
                    }
                    $out = [];
                    foreach ((array) \FrmFormAction::get_action_for_form($form_id, 'on_submit') as $action) {
                        $post = get_post((int) ($action->ID ?? 0));
                        if ($post instanceof \WP_Post) {
                            $out[] = self::confirmation_view($post, (array) (self::action_of($form_id, (int) $post->ID, 'on_submit')['settings'] ?? []));
                        }
                    }
                    $options = is_object($form) ? (array) ($form->options ?? []) : [];
                    if ([] === $out && ! empty($options['success_msg'])) {
                        $out[] = [ 'id' => null, 'legacy' => true, 'name' => 'Form options', 'enabled' => true, 'type' => (string) ($options['success_action'] ?? 'message'), 'message' => (string) $options['success_msg'], 'page' => (string) ($options['success_page_id'] ?? ''), 'redirect' => (string) ($options['success_url'] ?? '') ];
                    }
                    return [ 'form_id' => $form_id, 'confirmations' => $out ];
                },
            ],
            'update-notification'         => $write + [
                'mode'         => 'write',
                'description'  => 'Edit one email action (notification) of a form: enabled, name, subject, message, from and reply_to. Recipients are changed with set-notification-recipients. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after of every changed field. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $id,
                        'enabled'         => [ 'type' => 'boolean' ],
                        'name'            => [ 'type' => 'string', 'maxLength' => 200 ],
                        'subject'         => [ 'type' => 'string', 'maxLength' => 998 ],
                        'message'         => [ 'type' => 'string', 'maxLength' => 100000 ],
                        'from'            => [ 'type' => 'string', 'maxLength' => 320 ],
                        'reply_to'        => [ 'type' => 'string', 'maxLength' => 320 ],
                    ],
                    'required'             => [ 'form_id', 'notification_id' ],
                    'minProperties'        => 3,
                    'additionalProperties' => false,
                ],
                'validate'     => static function (array $args): ?array {
                    [ $refusal ] = self::email_plan($args);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $bad = static fn (string $message): array => [ 'code' => 'invalid_notification', 'message' => $message, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                    foreach ([ 'subject', 'message' ] as $field) {
                        if (isset($args[ $field ]) && '' === trim((string) $args[ $field ])) {
                            return $bad(sprintf('%s must not be empty.', ucfirst($field)));
                        }
                    }
                    foreach ([ 'name' => 'Name', 'subject' => 'Subject' ] as $field => $label) {
                        if (isset($args[ $field ]) && null !== ($problem = self::header_text_problem((string) $args[ $field ], $label))) {
                            return $bad($problem);
                        }
                    }
                    foreach ([ 'from' => 'From', 'reply_to' => 'Reply-to' ] as $field => $label) {
                        if (isset($args[ $field ])) {
                            $problem = self::address_problem((string) $args[ $field ], $label, 'reply_to' === $field, true);
                            if (null !== $problem) {
                                return $bad($problem);
                            }
                        }
                    }
                    return null;
                },
                'handler'      => static function (array $args): array {
                    [ , $plan ] = self::email_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_action($plan['post'], $plan['settings'], $plan['status'], $plan['title']);
                    }
                    return [ 'form_id' => $plan['form_id'], 'notification_id' => (int) $plan['post']->ID, 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
            'set-notification-recipients' => $write + [
                'mode'            => 'destructive',
                'description'     => 'Change who an email action is sent to: to, cc and bcc (addresses or field shortcodes such as [admin_email]; an empty cc or bcc clears it). This redirects personal data, so it needs confirm:true, and the confirmation shows the recipients before and after. Off by default (opt in with wpmcp_enable_forms_write). Undo: rollback-operation',
                'input_schema'    => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $id,
                        'to'              => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'cc'              => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'bcc'             => [ 'type' => 'string', 'maxLength' => 2000 ],
                    ],
                    'required'      => [ 'form_id', 'notification_id' ],
                    'minProperties' => 3,
                ],
                'validate'        => static function (array $args): ?array {
                    [ $refusal ] = self::email_plan($args, true);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    foreach ([ 'to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc' ] as $field => $label) {
                        if (isset($args[ $field ])) {
                            $problem = self::address_problem((string) $args[ $field ], $label, 'to' !== $field);
                            if (null !== $problem) {
                                return [ 'code' => 'invalid_recipients', 'message' => $problem, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                            }
                        }
                    }
                    return null;
                },
                'confirm_preview' => static function (array $args): array {
                    [ , $plan ] = self::email_plan($args, true);
                    $pick       = static fn (array $v): array => array_intersect_key($v, [ 'to' => 1, 'cc' => 1, 'bcc' => 1 ]);
                    return [
                        'message' => sprintf('It changes who email action %d of form %d is sent to. Undo: rollback-operation.', (int) $plan['post']->ID, $plan['form_id']),
                        'data'    => [ 'form_id' => $plan['form_id'], 'before' => $pick($plan['before']), 'after' => $pick($plan['after']) ],
                    ];
                },
                'handler'         => static function (array $args): array {
                    [ , $plan ] = self::email_plan($args, true);
                    $pick       = static fn (array $v): array => array_intersect_key($v, [ 'to' => 1, 'cc' => 1, 'bcc' => 1 ]);
                    $changed    = $pick($plan['before']) !== $pick($plan['after']);
                    if ($changed) {
                        self::save_action($plan['post'], $plan['settings']);
                    }
                    return [ 'form_id' => $plan['form_id'], 'notification_id' => (int) $plan['post']->ID, 'changed' => $changed, 'recipient_changed' => $changed, 'before' => $pick($plan['before']), 'after' => $pick($plan['after']) ];
                },
            ],
            'update-confirmation'         => $write + [
                'mode'         => 'destructive',
                'description'  => 'Edit one confirmation (on_submit action) of a form (needs confirm:true; the confirmation shows the confirmation before and after, since a redirect sends visitors, and smart tag values, to another page): enabled, name, type (message, page, redirect), message (post HTML only), page (id of a published page) and redirect (an http or https url). The result must be coherent for its type. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'confirmation_id' => $id,
                        'enabled'         => [ 'type' => 'boolean' ],
                        'name'            => [ 'type' => 'string', 'maxLength' => 200 ],
                        'type'            => [ 'type' => 'string', 'enum' => [ 'message', 'page', 'redirect' ] ],
                        'message'         => [ 'type' => 'string', 'maxLength' => 100000 ],
                        'page'            => $id,
                        'redirect'        => [ 'type' => 'string', 'maxLength' => 2000 ],
                    ],
                    'required'             => [ 'form_id', 'confirmation_id' ],
                    'minProperties'        => 3,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::confirmation_plan($args)[0],
                'confirm_preview' => static function (array $args): array {
                    [ , $plan ] = self::confirmation_plan($args);
                    return [
                        'message' => sprintf('It changes confirmation %d of form %d. Undo: rollback-operation.', (int) $plan['post']->ID, $plan['form_id']),
                        'data'    => [ 'form_id' => $plan['form_id'], 'before' => $plan['before'], 'after' => $plan['after'] ],
                    ];
                },
                'handler'      => static function (array $args): array {
                    [ , $plan ] = self::confirmation_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_action($plan['post'], $plan['settings'], $plan['status'], $plan['title']);
                    }
                    return [ 'form_id' => $plan['form_id'], 'confirmation_id' => (int) $plan['post']->ID, 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
        ];
    }
}
