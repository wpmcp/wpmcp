<?php

namespace WPMCP\Integrations;

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
 * rollback-operation. Entry deletion is deliberately not offered.
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
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (active/spam/trash). Requires manage_options because entries are user data',
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
                        'entries' => is_wp_error($entries) ? [] : (array) $entries,
                        'total'   => (int) $total,
                        'paging'  => $paging,
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
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
        ];
    }
}
