<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Forms integration (wpmcp/fluentforms-read / -write pair), part of the
 * forms adapter pack (issue #66, pro tier). Delegates to Fluent Forms' own
 * query builder, wpFluent(), against the tables Fluent Forms documents and
 * queries itself (verified against Fluent Forms 6.x): fluentform_forms for
 * forms and fields, fluentform_form_meta rows with meta_key "notifications"
 * for email notifications (exactly what FormProperties::emailNotifications()
 * reads), and fluentform_submissions for entries.
 *
 * Entries are user data, so the entry operations sit behind manage_options on
 * top of the pair's own capability. The default listing leaves trashed
 * entries out, as Fluent Forms' own entries screen does.
 *
 * update-entry-status writes the submission's status column (unread, read,
 * spam, trashed) and then fires fluentform/after_submission_status_update, the
 * action Fluent Forms' own SubmissionService::updateStatus() fires, so any
 * add-on listening for status changes still hears about it. Submissions live
 * in Fluent Forms' own table, so the write is snapshotted as a db_rows
 * before-image of exactly that row and restorable with rollback-operation.
 * Entry deletion is deliberately not offered.
 */
class Fluent_Forms_Integration extends Forms_Integration
{
    /** Fluent Forms' entry statuses (Helper::getEntryStatuses() plus spam and trashed). */
    private const STATUSES = [ 'unread', 'read', 'spam', 'trashed' ];

    public function integration(): string
    {
        return 'fluentforms';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return function_exists('wpFluent');
    }

    protected function summary(): string
    {
        return 'Fluent Forms (forms, fields, notifications, entries, and entry status)';
    }

    /** Read a column off a builder row, which is an object live and may be an array in a double. */
    private static function col($row, string $key)
    {
        return is_object($row) ? ($row->{$key} ?? null) : ($row[ $key ] ?? null);
    }

    private static function decode_fields($row): array
    {
        $data   = json_decode((string) self::col($row, 'form_fields'), true);
        $fields = [];
        if (is_array($data['fields'] ?? null)) {
            foreach ($data['fields'] as $field) {
                $fields[] = [
                    'name'     => (string) ($field['attributes']['name'] ?? ($field['uniqElKey'] ?? '')),
                    'element'  => (string) ($field['element'] ?? ''),
                    'label'    => (string) ($field['settings']['label'] ?? ''),
                    'required' => ! empty($field['settings']['validation_rules']['required']['value']),
                ];
            }
        }
        return $fields;
    }

    private static function form_row(int $form_id)
    {
        return wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();
    }

    private static function submission(int $entry_id)
    {
        return wpFluent()->table('fluentform_submissions')->where('id', $entry_id)->first();
    }

    private static function shape_entry($row, bool $with_response): array
    {
        $out = [
            'id'            => (int) self::col($row, 'id'),
            'form_id'       => (int) self::col($row, 'form_id'),
            'serial_number' => (int) self::col($row, 'serial_number'),
            'status'        => (string) self::col($row, 'status'),
            'created_at'    => (string) self::col($row, 'created_at'),
        ];
        if ($with_response) {
            $response        = json_decode((string) self::col($row, 'response'), true);
            $out['response'] = is_array($response) ? $response : [];
            $out['ip']       = (string) self::col($row, 'ip');
        }
        return $out;
    }

    protected function operations(): array
    {
        $form_only = [
            'type'       => 'object',
            'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'form_id' ],
        ];

        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Fluent Forms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $rows = wpFluent()->table('fluentform_forms')->get();
                    $out  = [];
                    foreach ((array) $rows as $row) {
                        $out[] = [
                            'id'    => (int) self::col($row, 'id'),
                            'title' => (string) self::col($row, 'title'),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Fluent Forms form with its decoded field definitions',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $row = self::form_row((int) $args['form_id']);
                    if (! $row) {
                        return [ 'form' => null ];
                    }
                    return [ 'form' => [
                        'id'     => (int) self::col($row, 'id'),
                        'title'  => (string) self::col($row, 'title'),
                        'fields' => self::decode_fields($row),
                    ] ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: input name, element type, label, and whether it is required',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $row = self::form_row((int) $args['form_id']);
                    return [ 'form_id' => (int) $args['form_id'], 'fields' => $row ? self::decode_fields($row) : null ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications: id, name, whether it is enabled, who it is sent to, subject, and message template',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (! self::form_row($form_id)) {
                        return [ 'form_id' => $form_id, 'notifications' => null ];
                    }
                    $rows = wpFluent()->table('fluentform_form_meta')
                        ->where('form_id', $form_id)
                        ->where('meta_key', 'notifications')
                        ->get();
                    $out = [];
                    foreach ((array) $rows as $row) {
                        $value   = json_decode((string) self::col($row, 'value'), true);
                        $value   = is_array($value) ? $value : [];
                        $send_to = (array) ($value['sendTo'] ?? []);
                        $out[]   = [
                            'id'      => (int) self::col($row, 'id'),
                            'name'    => (string) ($value['name'] ?? ''),
                            'enabled' => ! empty($value['enabled']),
                            'send_to' => [
                                'type'  => (string) ($send_to['type'] ?? ''),
                                'email' => (string) ($send_to['email'] ?? ''),
                                'field' => (string) ($send_to['field'] ?? ''),
                            ],
                            'subject' => (string) ($value['subject'] ?? ''),
                            'message' => (string) ($value['message'] ?? ''),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (unread, read, spam, trashed; without one, trashed entries are left out like Fluent Forms\' own screen does). Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'  => [ 'type' => 'string', 'enum' => self::STATUSES ],
                    ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id                = (int) $args['form_id'];
                    [ $page_size, $offset ] = self::page_window($args);
                    $scoped                 = static function () use ($form_id, $args) {
                        $q = wpFluent()->table('fluentform_submissions')->where('form_id', $form_id);
                        return isset($args['status'])
                            ? $q->where('status', (string) $args['status'])
                            : $q->where('status', '!=', 'trashed');
                    };
                    $rows = $scoped()->orderBy('id', 'DESC')->offset($offset)->limit($page_size)->get();
                    $out  = [];
                    foreach ((array) $rows as $row) {
                        $out[] = self::shape_entry($row, false);
                    }
                    return [ 'form_id' => $form_id, 'entries' => $out, 'total' => (int) $scoped()->count() ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Read one entry in full: form, serial number, status, date, submitted response, and IP address. Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $row = self::submission((int) $args['entry_id']);
                    return [ 'entry' => $row ? self::shape_entry($row, true) : null ];
                },
            ],
            'update-entry-status' => [
                'mode'         => 'write',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Set one entry\'s status to unread, read, spam, or trashed, and fire Fluent Forms\' own after-status-update action. Snapshotted first (a before-image of the submission row) and restorable with rollback-operation. Requires manage_options',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                    ],
                    'required'   => [ 'entry_id', 'status' ],
                ],
                'handler'      => function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $row      = self::submission($entry_id);
                    if (! $row) {
                        throw new Operation_Error('entry_not_found', 'Fluent Forms has no entry with that id.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $previous = (string) self::col($row, 'status');
                    $status   = (string) $args['status'];
                    if ($previous === $status) {
                        return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => false ];
                    }
                    wpFluent()->table('fluentform_submissions')->where('id', $entry_id)->update([ 'status' => $status ]);
                    do_action('fluentform/after_submission_status_update', $entry_id, $status); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own hook, fired so its listeners see a status change made outside its UI.
                    return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => true ];
                },
                'snapshot'     => static fn (array $args): ?array => self::row_snapshot('fluentform_submissions', 'id', (int) $args['entry_id'], [ 'status' => (string) $args['status'] ]),
            ],
        ];
    }
}
