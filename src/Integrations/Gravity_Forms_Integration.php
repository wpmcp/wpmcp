<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Forms integration exposed as a wpmcp/gravityforms-read /
 * wpmcp/gravityforms-write dispatcher pair, part of the forms adapter pack
 * (issue #66, pro tier).
 *
 * Every operation delegates to Gravity Forms' own public GFAPI (verified
 * against the documented Gravity Forms 2.9 API in includes/api.php):
 * get_forms(), get_form(), count_entries(), get_entries(), get_entry(),
 * get_notes() and update_entry_property(). Forms, fields and notifications
 * come from the form object; entries, notes and entry status from the entry
 * API.
 *
 * Entries are user data: every entry operation sits behind manage_options on
 * top of the pair's own capability. The one write, update-entry-status, moves
 * an entry between active, spam and trash through update_entry_property(),
 * which is how Gravity Forms' own entry screen does it. Gravity Forms keeps
 * entries in its own gf_entry table, so the write is snapshotted as a
 * db_rows before-image of exactly that row and restorable with
 * rollback-operation.
 *
 * delete-entry (issue #675) removes the entry's rows of gf_entry_meta,
 * gf_entry_notes and gf_entry directly, each table under its own db_rows
 * before-image in one session, so rollback-session puts the whole entry back
 * at its original id. It does not go through GFAPI::delete_entry(): that
 * also unlinks the entry's uploaded files, which no snapshot can restore.
 * Gravity Forms' gform_delete_entry action therefore does not fire. An entry
 * with a payment (a gf_addon_payment_transaction row, or a payment status or
 * transaction id on the entry) is refused, as in the other forms adapters.
 *
 * Issue #674: notifications and confirmations are editable (opt in with
 * wpmcp_enable_forms_write). Gravity Forms keeps each form's notifications
 * and confirmations as JSON columns of its gf_form_meta row, so every edit
 * writes one column through GFFormsModel::update_form_meta(), the call its
 * own notification toggle uses, under a db_rows before-image of that row.
 */
class Gravity_Forms_Integration extends Forms_Integration
{
    public function integration(): string
    {
        return 'gravityforms';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return class_exists('GFAPI');
    }

    protected function summary(): string
    {
        return 'Gravity Forms (forms, fields, notifications, entries, entry notes)';
    }

    /** Read one key off a GF field, which is a GF_Field (ArrayAccess) live and an array in a double. */
    private static function field_value($field, string $key)
    {
        if (is_array($field) || $field instanceof \ArrayAccess) {
            return $field[ $key ] ?? null;
        }
        return is_object($field) ? ($field->{$key} ?? null) : null;
    }

    /** @return array|null the form array, or null when Gravity Forms has no such form. */
    private static function form(int $form_id): ?array
    {
        $form = \GFAPI::get_form($form_id);
        return is_array($form) && [] !== $form ? $form : null;
    }

    /** Entry properties that carry no personal data and stay readable in a list. */
    private const LIST_READABLE_KEYS = [
        'id', 'form_id', 'post_id', 'date_created', 'date_updated', 'status', 'is_starred', 'is_read',
        'currency', 'payment_status', 'payment_date', 'payment_amount', 'payment_method',
        'transaction_id', 'transaction_type', 'is_fulfilled',
    ];

    /**
     * A list row with every field value and personal property masked. An
     * allowlist keeps the ids, dates, status and payment fields readable, so
     * a field a later Gravity Forms release adds is masked by default.
     *
     * @param mixed $entry
     * @return mixed
     */
    private static function mask_entry($entry)
    {
        if (! is_array($entry)) {
            return $entry;
        }
        foreach ($entry as $key => $value) {
            if (! in_array((string) $key, self::LIST_READABLE_KEYS, true)) {
                $entry[ $key ] = Masking::value($value);
            }
        }
        return $entry;
    }

    /** The Gravity Forms entry, or null when there is none with that id. */
    private static function entry(int $entry_id): ?array
    {
        $entry = \GFAPI::get_entry($entry_id);
        return is_array($entry) ? $entry : null;
    }

    /**
     * The delete-entry operation (issue #675), behind the forms opt-in.
     *
     * @return array<string, mixed>
     */
    private static function delete_entry_operation(): array
    {
        return [
            'mode'              => 'destructive',
            'self_snapshotting' => true,
            'objects'           => [ 'entry_id' => 'entry' ],
            'capability'        => self::ENTRY_CAPABILITY,
            'description'       => 'Permanently delete one Gravity Forms entry with its values and notes (the rows of gf_entry, gf_entry_meta and gf_entry_notes, removed directly: Gravity Forms\' gform_delete_entry action does not run, uploaded files stay on disk, and a post created from the entry stays). Refused with entry_has_payments when the entry has a payment. Off by default (opt in with wpmcp_enable_forms_write); needs confirm:true and manage_options. Reversible: every row is snapshotted first and rollback-session puts them back. ' . self::NOT_ERASURE,
            'input_schema'      => [
                'type'       => 'object',
                'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                'required'   => [ 'entry_id' ],
            ],
            'validate'          => static function (array $args): ?array {
                $entry_id = (int) $args['entry_id'];
                $entry    = self::entry($entry_id);
                if (null === $entry) {
                    return [ 'code' => 'entry_not_found', 'message' => 'Gravity Forms has no entry with that id.', 'data' => [ 'entry_id' => $entry_id ] ];
                }
                if ('' !== (string) ($entry['payment_status'] ?? '') || '' !== (string) ($entry['transaction_id'] ?? '')) {
                    return [
                        'code'    => 'entry_has_payments',
                        'message' => 'This entry has payment records (orders, transactions or subscriptions). Financial records are not deleted here.',
                        'data'    => [ 'entry_id' => $entry_id, 'table' => 'gf_entry' ],
                    ];
                }
                return self::payment_refusal($entry_id, [ [ 'gf_addon_payment_transaction', 'lead_id' ] ]);
            },
            'confirm_preview'   => static function (array $args): array {
                $entry_id = (int) $args['entry_id'];
                $entry    = (array) self::entry($entry_id);
                $form_id  = (int) ($entry['form_id'] ?? 0);
                return self::delete_entry_preview(
                    sprintf('Gravity Forms entry %d of form %d', $entry_id, $form_id),
                    $entry_id,
                    self::count_rows('gf_entry_meta', 'entry_id', $entry_id),
                    'rollback-session',
                    [
                        'form_id' => $form_id,
                        'date'    => (string) ($entry['date_created'] ?? ''),
                        'notes'   => self::count_rows('gf_entry_notes', 'entry_id', $entry_id),
                    ]
                );
            },
            'handler'           => static function (array $args, array $context): array {
                $entry_id = (int) $args['entry_id'];
                $done     = self::delete_rows_in_session([
                    [ 'table' => 'gf_entry_meta', 'primary_key' => [ 'id' ], 'where' => [ 'entry_id' => $entry_id ], 'optional' => true ],
                    [ 'table' => 'gf_entry_notes', 'primary_key' => [ 'id' ], 'where' => [ 'entry_id' => $entry_id ], 'optional' => true ],
                    [ 'table' => 'gf_entry', 'primary_key' => [ 'id' ], 'where' => [ 'id' => $entry_id ] ],
                ], $args, $context);
                return [
                    'result'        => [ 'entry_id' => $entry_id, 'deleted' => ($done['result']['gf_entry'] ?? 0) > 0, 'rows' => $done['result'] ],
                    'operation_ids' => $done['operation_ids'],
                ];
            },
        ] + self::write_flags();
    }

    protected function operations(): array
    {
        return $this->read_and_entry_operations() + self::config_operations();
    }

    /** @return array<string, array<string, mixed>> */
    private function read_and_entry_operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Gravity Forms forms with id, title, active state, entry count, and field count',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'active' => [ 'type' => 'boolean' ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $active = ! isset($args['active']) || (bool) $args['active'];
                    $forms  = \GFAPI::get_forms($active);
                    $out    = [];
                    foreach ((array) $forms as $form) {
                        $id    = (int) ($form['id'] ?? 0);
                        $count = \GFAPI::count_entries($id);
                        $out[] = [
                            'id'          => $id,
                            'title'       => (string) ($form['title'] ?? ''),
                            'is_active'   => ! empty($form['is_active']),
                            'date_created' => $form['date_created'] ?? null,
                            'field_count' => is_array($form['fields'] ?? null) ? count($form['fields']) : 0,
                            'entry_count' => is_wp_error($count) ? null : (int) $count,
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one form in full: fields (with type, label, id), settings, notifications, and confirmations',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \GFAPI::get_form((int) $args['form_id']);
                    return [ 'form' => empty($form) ? null : $form ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: id, type, label, whether it is required, and choices for choice fields',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = self::form((int) $args['form_id']);
                    if (null === $form) {
                        return [ 'form_id' => (int) $args['form_id'], 'fields' => null ];
                    }
                    $fields = [];
                    foreach ((array) ($form['fields'] ?? []) as $field) {
                        $choices  = self::field_value($field, 'choices');
                        $fields[] = [
                            'id'       => (string) self::field_value($field, 'id'),
                            'type'     => (string) self::field_value($field, 'type'),
                            'label'    => (string) self::field_value($field, 'label'),
                            'required' => (bool) self::field_value($field, 'isRequired'),
                            'choices'  => is_array($choices) ? array_values(array_map(static fn ($c) => (string) (is_array($c) ? ($c['value'] ?? ($c['text'] ?? '')) : $c), $choices)) : [],
                        ];
                    }
                    return [ 'form_id' => (int) $args['form_id'], 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications: id, name, triggering event, whether it is active, recipient, subject, and message template',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = self::form((int) $args['form_id']);
                    if (null === $form) {
                        return [ 'form_id' => (int) $args['form_id'], 'notifications' => null ];
                    }
                    $out = [];
                    foreach ((array) ($form['notifications'] ?? []) as $key => $n) {
                        $n     = (array) $n;
                        $out[] = [
                            'id'      => (string) ($n['id'] ?? $key),
                            'name'    => (string) ($n['name'] ?? ''),
                            'event'   => (string) ($n['event'] ?? 'form_submission'),
                            // Gravity Forms treats a notification without the
                            // flag as active; only an explicit false is off.
                            'active'  => ! array_key_exists('isActive', $n) || (bool) $n['isActive'],
                            // email: to is an address list; field: to is a field id; routing: rules decide.
                            'to_type' => (string) ($n['toType'] ?? 'email'),
                            'to'      => (string) ($n['to'] ?? ''),
                            'subject' => (string) ($n['subject'] ?? ''),
                            'message' => (string) ($n['message'] ?? ''),
                        ];
                    }
                    return [ 'form_id' => (int) $args['form_id'], 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (active/spam/trash). Field values, ip, user_agent, source_url and created_by are MASKED (get-entry returns one in full); ids, dates, status and payment fields stay readable. Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'  => [ 'type' => 'string', 'enum' => [ 'active', 'spam', 'trash' ] ],
                    ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id                = (int) $args['form_id'];
                    [ $page_size, $offset ] = self::page_window($args);
                    $search    = isset($args['status']) ? [ 'status' => (string) $args['status'] ] : [];
                    $paging    = [ 'offset' => $offset, 'page_size' => $page_size ];
                    $total     = 0;
                    $entries   = \GFAPI::get_entries($form_id, $search, null, $paging, $total);
                    return [
                        'form_id' => $form_id,
                        'entries' => is_wp_error($entries) ? [] : array_map([ self::class, 'mask_entry' ], array_values((array) $entries)),
                        'masked'  => true,
                        'total'   => (int) $total,
                        'paging'  => $paging,
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Read a single entry (all field values plus meta) by entry id. Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \GFAPI::get_entry((int) $args['entry_id']);
                    return [ 'entry' => is_wp_error($entry) ? null : $entry ];
                },
            ],
            'get-notes' => [
                'mode'         => 'read',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List the notes attached to an entry. Requires manage_options because notes are part of the entry',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $notes = \GFAPI::get_notes([ 'entry_id' => (int) $args['entry_id'] ]);
                    return [ 'entry_id' => (int) $args['entry_id'], 'notes' => is_array($notes) ? $notes : [] ];
                },
            ],
            'update-entry-status' => [
                'mode'         => 'write',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Move one entry between active, spam, and trash through GFAPI::update_entry_property(), the way Gravity Forms\' entry screen does. Snapshotted first (a before-image of the entry row) and restorable with rollback-operation. Requires manage_options',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'active', 'spam', 'trash' ] ],
                    ],
                    'required'   => [ 'entry_id', 'status' ],
                ],
                'handler'      => function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $entry    = \GFAPI::get_entry($entry_id);
                    if (is_wp_error($entry) || ! is_array($entry)) {
                        throw new Operation_Error('entry_not_found', 'Gravity Forms has no entry with that id.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $previous = (string) ($entry['status'] ?? 'active');
                    $status   = (string) $args['status'];
                    if ($previous === $status) {
                        return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => false ];
                    }
                    $done = \GFAPI::update_entry_property($entry_id, 'status', $status);
                    if (is_wp_error($done) || false === $done) {
                        throw new Operation_Error('update_failed', 'Gravity Forms did not change the entry\'s status.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => true ];
                },
                'snapshot'     => static fn (array $args): ?array => self::row_snapshot('gf_entry', 'id', (int) $args['entry_id'], [ 'status' => (string) $args['status'] ]),
            ],
            'delete-entry' => self::delete_entry_operation(),
        ];
    }

    // ------------------------------------------------------------------
    // Notification and confirmation edits (issue #674)
    // ------------------------------------------------------------------

    /**
     * The stored notifications and confirmations of one form, read raw from
     * its gf_form_meta row (not through get_form(), whose filters may add
     * items that must not be written back). Null when the form or its row is
     * missing.
     *
     * @return array{notifications: array<string, array<string, mixed>>, confirmations: array<string, array<string, mixed>>}|null
     */
    private static function stored_meta(int $form_id): ?array
    {
        global $wpdb;

        if ($form_id < 1 || ! class_exists('GFFormsModel') || null === self::form($form_id)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the raw columns Gravity Forms stores, read once per edit; the write goes through GFFormsModel::update_form_meta().
        $row = $wpdb->get_row($wpdb->prepare('SELECT notifications, confirmations FROM %i WHERE form_id = %d', \GFFormsModel::get_meta_table_name(), $form_id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ([ 'notifications', 'confirmations' ] as $column) {
            $raw     = (string) ($row[ $column ] ?? '');
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $decoded = maybe_unserialize($raw);
            }
            $items = [];
            foreach ((is_array($decoded) ? $decoded : []) as $key => $item) {
                if (is_array($item)) {
                    $items[ (string) $key ] = $item;
                }
            }
            $out[ $column ] = $items;
        }

        return $out;
    }

    /** @return array<string, mixed> a refusal for a form Gravity Forms does not have */
    private static function form_refusal(int $form_id): array
    {
        return [ 'code' => 'form_not_found', 'message' => 'No Gravity Forms form has that id.', 'data' => [ 'form_id' => $form_id ] ];
    }

    /** @return array<string, mixed> one notification as the edits report it */
    private static function notification_view(array $n): array
    {
        return [
            'enabled'        => ! array_key_exists('isActive', $n) || (bool) $n['isActive'],
            'name'           => (string) ($n['name'] ?? ''),
            'to_type'        => (string) ($n['toType'] ?? 'email'),
            'to'             => (string) ($n['to'] ?? ''),
            'bcc'            => (string) ($n['bcc'] ?? ''),
            'sender_name'    => (string) ($n['fromName'] ?? ''),
            'sender_address' => (string) ($n['from'] ?? ''),
            'replyto'        => (string) ($n['replyTo'] ?? ''),
            'subject'        => (string) ($n['subject'] ?? ''),
            'message'        => (string) ($n['message'] ?? ''),
        ];
    }

    /** @return array<string, mixed> one confirmation as list-confirmations reports it */
    private static function confirmation_view(string $id, array $c): array
    {
        return [
            'id'           => $id,
            'name'         => (string) ($c['name'] ?? ''),
            'type'         => '' === (string) ($c['type'] ?? '') ? 'message' : (string) $c['type'],
            'is_default'   => ! empty($c['isDefault']),
            'message'      => (string) ($c['message'] ?? ''),
            'page'         => (string) ($c['pageId'] ?? ''),
            'redirect'     => (string) ($c['url'] ?? ''),
            'query_string' => (string) ($c['queryString'] ?? ''),
        ];
    }

    /** Write one column of the form's gf_form_meta row the way Gravity Forms does. */
    private static function save_meta(int $form_id, string $column, array $items): void
    {
        $result = \GFFormsModel::update_form_meta($form_id, $items, $column);
        if (false === $result) {
            throw new Operation_Error('update_failed', 'Gravity Forms did not save the form.', [ 'form_id' => (int) $form_id ]);
        }
    }

    /**
     * Plan one notification edit: the notification as it is and as it would
     * be, or a refusal.
     *
     * @return array{0: ?array, 1: ?array}  [refusal, plan]
     */
    private static function notification_plan(array $args, bool $with_recipients = false): array
    {
        $form_id = (int) $args['form_id'];
        $meta    = self::stored_meta($form_id);
        if (null === $meta) {
            return [ self::form_refusal($form_id), null ];
        }
        $id  = (string) $args['notification_id'];
        $all = $meta['notifications'];
        if (! isset($all[ $id ])) {
            return [ [ 'code' => 'notification_not_found', 'message' => 'This form has no notification with that id (see list-notifications).', 'data' => [ 'form_id' => $form_id, 'notification_id' => $id ] ], null ];
        }
        $next = $all[ $id ];
        foreach ([ 'name' => 'name', 'sender_name' => 'fromName', 'sender_address' => 'from', 'replyto' => 'replyTo', 'subject' => 'subject', 'message' => 'message' ] as $arg => $key) {
            if (isset($args[ $arg ])) {
                $next[ $key ] = (string) $args[ $arg ];
            }
        }
        if (isset($args['message']) && ! current_user_can('unfiltered_html')) {
            $next['message'] = wp_kses_post((string) $args['message']);
        }
        if (isset($args['enabled'])) {
            $next['isActive'] = (bool) $args['enabled'];
        }
        // Who a notification goes to is changed only by set-notification-recipients
        // (confirm, validation, domain filter, preview), never by the plain edit.
        if ($with_recipients) {
            if (isset($args['to'])) {
                $next['toType'] = 'email';
                $next['to']     = (string) $args['to'];
            }
            if (isset($args['bcc'])) {
                $next['bcc'] = (string) $args['bcc'];
            }
        }
        $new_all        = $all;
        $new_all[ $id ] = $next;

        return [ null, [ 'form_id' => $form_id, 'id' => $id, 'items' => $new_all, 'before' => self::notification_view($all[ $id ]), 'after' => self::notification_view($next) ] ];
    }

    /**
     * Plan one confirmation edit.
     *
     * @return array{0: ?array, 1: ?array}  [refusal, plan]
     */
    private static function confirmation_plan(array $args): array
    {
        $form_id = (int) $args['form_id'];
        $meta    = self::stored_meta($form_id);
        if (null === $meta) {
            return [ self::form_refusal($form_id), null ];
        }
        $id  = (string) $args['confirmation_id'];
        $all = $meta['confirmations'];
        $bad = static fn (string $code, string $message): array => [ [ 'code' => $code, 'message' => $message, 'data' => [ 'form_id' => $form_id, 'confirmation_id' => $id ] ], null ];
        if (! isset($all[ $id ])) {
            return $bad('confirmation_not_found', 'This form has no confirmation with that id (see list-confirmations).');
        }
        $before = self::confirmation_view($id, $all[ $id ]);
        $next   = $all[ $id ];
        if (isset($args['name'])) {
            if (null !== ($problem = self::header_text_problem((string) $args['name'], 'Name'))) {
                return $bad('invalid_confirmation', $problem);
            }
            $next['name'] = (string) $args['name'];
        }
        if (isset($args['type'])) {
            $next['type'] = (string) $args['type'];
        }
        if (isset($args['message'])) {
            $next['message'] = wp_kses_post((string) $args['message']);
        }
        if (isset($args['page'])) {
            $next['pageId'] = (string) (int) $args['page'];
        }
        if (isset($args['redirect']) && (string) $args['redirect'] !== $before['redirect']) {
            // Judge what was given, before esc_url_raw() can turn "not a url" into one.
            $raw = (string) $args['redirect'];
            if (! preg_match('#^https?://[^\s]+$#i', $raw)) {
                return $bad('invalid_confirmation', 'A redirect confirmation needs an http or https url.');
            }
            $has_tags = (bool) preg_match('/[{}\[\]]/', $raw);
            $problem  = self::redirect_tag_problem($raw);
            if (null === $problem && ! $has_tags && esc_url_raw($raw) !== $raw) {
                $problem = 'The redirect url contains characters WordPress would strip, so it would be saved altered. Use a plain url.';
            }
            if (null !== $problem) {
                return $bad('invalid_confirmation', $problem);
            }
            $next['url'] = $has_tags ? $raw : esc_url_raw($raw);
        }
        $after     = self::confirmation_view($id, $next);
        $unchanged = 'redirect' === $before['type'] && $after['redirect'] === $before['redirect'];
        $problem   = self::confirmation_problem([ 'type' => $after['type'], 'message' => $after['message'], 'page' => $after['page'], 'url' => $after['redirect'], 'url_unchanged' => $unchanged ]);
        // Gravity Forms appends the stored query string, with its merge tags
        // filled, to a redirect url, so a new redirect target is judged with it.
        if (null === $problem && 'redirect' === $after['type'] && ! $unchanged && '' !== $after['query_string']) {
            $glue    = str_contains($after['redirect'], '?') ? '&' : '?';
            $problem = self::redirect_tag_problem($after['redirect'] . $glue . $after['query_string']);
        }
        if (null !== $problem) {
            return $bad('invalid_confirmation', $problem);
        }
        $new_all        = $all;
        $new_all[ $id ] = $next;

        return [ null, [ 'form_id' => $form_id, 'id' => $id, 'items' => $new_all, 'before' => $before, 'after' => $after ] ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function config_operations(): array
    {
        $id   = [ 'type' => 'integer', 'minimum' => 1 ];
        $item = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ];
        // Gravity Forms' forms are rows of its own tables, not posts: the writes
        // sit behind manage_options and are undone through a db_rows
        // before-image of the form's gf_form_meta row.
        $write = static function (string $column): array {
            return [
                'capability' => 'manage_options',
                'snapshot'   => static function (array $args) use ($column): ?array {
                    $plan = 'confirmations' === $column ? self::confirmation_plan($args)[1] : self::notification_plan($args, true)[1];
                    return null === $plan ? null : self::row_snapshot('gf_form_meta', 'form_id', (int) $plan['form_id'], [ $column => wp_json_encode($plan['items']) ]);
                },
            ] + self::write_flags();
        };

        return [
            'list-confirmations'          => [
                'mode'         => 'read',
                'description'  => 'List one form\'s confirmations (what a visitor sees after submitting): id, name, type (message, page, redirect), whether it is the default, message, page id, redirect url and query string',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ],
                'handler'      => static function (array $args): array {
                    $meta = self::stored_meta((int) $args['form_id']);
                    if (null === $meta) {
                        return [ 'form_id' => (int) $args['form_id'], 'confirmations' => null ];
                    }
                    $out = [];
                    foreach ($meta['confirmations'] as $cid => $c) {
                        $out[] = self::confirmation_view((string) $cid, $c);
                    }
                    return [ 'form_id' => (int) $args['form_id'], 'confirmations' => $out ];
                },
            ],
            'update-notification'         => $write('notifications') + [
                'mode'         => 'write',
                'description'  => 'Edit one notification of a form: enabled, name, subject, message, sender_name, sender_address and replyto. The recipients are changed with set-notification-recipients. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after of every changed field. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'form_id'         => $id,
                        'notification_id' => $item,
                        'enabled'         => [ 'type' => 'boolean' ],
                        'name'            => [ 'type' => 'string', 'maxLength' => 200 ],
                        'subject'         => [ 'type' => 'string', 'maxLength' => 998 ],
                        'message'         => [ 'type' => 'string', 'maxLength' => 100000 ],
                        'sender_name'     => [ 'type' => 'string', 'maxLength' => 200 ],
                        'sender_address'  => [ 'type' => 'string', 'maxLength' => 320 ],
                        'replyto'         => [ 'type' => 'string', 'maxLength' => 320 ],
                    ],
                    'required'             => [ 'form_id', 'notification_id' ],
                    'minProperties'        => 3,
                    'additionalProperties' => false,
                ],
                'validate'     => static function (array $args): ?array {
                    [ $refusal ] = self::notification_plan($args);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $bad = static fn (string $message): array => [ 'code' => 'invalid_notification', 'message' => $message, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                    foreach ([ 'subject', 'message' ] as $field) {
                        if (isset($args[ $field ]) && '' === trim((string) $args[ $field ])) {
                            return $bad(sprintf('%s must not be empty.', ucfirst($field)));
                        }
                    }
                    foreach ([ 'name' => 'Name', 'subject' => 'Subject', 'sender_name' => 'Sender name' ] as $field => $label) {
                        if (isset($args[ $field ]) && null !== ($problem = self::header_text_problem((string) $args[ $field ], $label))) {
                            return $bad($problem);
                        }
                    }
                    foreach ([ 'sender_address' => 'Sender address', 'replyto' => 'Reply-to' ] as $field => $label) {
                        if (isset($args[ $field ]) && null !== ($problem = self::address_problem((string) $args[ $field ], $label, 'replyto' === $field, true))) {
                            return $bad($problem);
                        }
                    }
                    return null;
                },
                'handler'      => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_meta($plan['form_id'], 'notifications', $plan['items']);
                    }
                    return [ 'form_id' => $plan['form_id'], 'notification_id' => $plan['id'], 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
            'set-notification-recipients' => $write('notifications') + [
                'mode'            => 'destructive',
                'description'     => 'Change who a notification is sent to: to (addresses or merge tags such as {admin_email}; sets the send-to type to email) and bcc (an empty bcc clears it). This redirects personal data, so it needs confirm:true, and the confirmation shows the recipients before and after. Off by default (opt in with wpmcp_enable_forms_write). Undo: rollback-operation',
                'input_schema'    => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $item,
                        'to'              => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'bcc'             => [ 'type' => 'string', 'maxLength' => 2000 ],
                    ],
                    'required'      => [ 'form_id', 'notification_id' ],
                    'minProperties' => 3,
                ],
                'validate'        => static function (array $args): ?array {
                    [ $refusal ] = self::notification_plan($args, true);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    foreach ([ 'to' => 'To', 'bcc' => 'Bcc' ] as $field => $label) {
                        if (isset($args[ $field ]) && null !== ($problem = self::address_problem((string) $args[ $field ], $label, 'bcc' === $field))) {
                            return [ 'code' => 'invalid_recipients', 'message' => $problem, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                        }
                    }
                    return null;
                },
                'confirm_preview' => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args, true);
                    return [
                        'message' => sprintf('It changes who notification %s of form %d is sent to. Undo: rollback-operation.', $plan['id'], $plan['form_id']),
                        'data'    => [ 'form_id' => $plan['form_id'], 'before' => self::recipients($plan['before']), 'after' => self::recipients($plan['after']) ],
                    ];
                },
                'handler'         => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args, true);
                    $before     = self::recipients($plan['before']);
                    $after      = self::recipients($plan['after']);
                    $changed    = $before !== $after;
                    if ($changed) {
                        self::save_meta($plan['form_id'], 'notifications', $plan['items']);
                    }
                    return [
                        'form_id'           => $plan['form_id'],
                        'notification_id'   => $plan['id'],
                        'changed'           => $changed,
                        'recipient_changed' => $changed,
                        'before'            => $before,
                        'after'             => $after,
                    ];
                },
            ],
            'update-confirmation'         => $write('confirmations') + [
                'mode'            => 'destructive',
                'description'     => 'Edit one confirmation of a form (needs confirm:true; the confirmation shows it before and after, since a redirect sends visitors, and merge tag values in its query string, to another page): name, type (message, page, redirect), message (post HTML only), page (id of a published page) and redirect (an http or https url). The result must be coherent for its type. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after. Undo: rollback-operation',
                'input_schema'    => [
                    'type'                 => 'object',
                    'properties'           => [
                        'form_id'         => $id,
                        'confirmation_id' => $item,
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
                'validate'        => static fn (array $args): ?array => self::confirmation_plan($args)[0],
                'confirm_preview' => static function (array $args): array {
                    [ , $plan ] = self::confirmation_plan($args);
                    return [
                        'message' => sprintf('It changes confirmation %s of form %d. Undo: rollback-operation.', $plan['id'], $plan['form_id']),
                        'data'    => [ 'form_id' => $plan['form_id'], 'before' => $plan['before'], 'after' => $plan['after'] ],
                    ];
                },
                'handler'         => static function (array $args): array {
                    [ , $plan ] = self::confirmation_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_meta($plan['form_id'], 'confirmations', $plan['items']);
                    }
                    return [ 'form_id' => $plan['form_id'], 'confirmation_id' => $plan['id'], 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
        ];
    }

    /** @return array{to_type: string, to: string, bcc: string} the recipient part of a notification view */
    private static function recipients(array $view): array
    {
        return [ 'to_type' => $view['to_type'], 'to' => $view['to'], 'bcc' => $view['bcc'] ];
    }
}
