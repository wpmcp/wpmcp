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
}
