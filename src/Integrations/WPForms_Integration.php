<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPForms integration (wpmcp/wpforms-read / -write pair), part of the forms
 * adapter pack (issue #66, pro tier). Delegates to WPForms' own registry
 * objects (verified against WPForms 1.9): wpforms()->form for forms, fields
 * and notification settings, and wpforms()->obj('entry'), the entry handler,
 * for submissions.
 *
 * WPForms Lite stores no entries, so the entry operations declare a per-op
 * 'requires' check on the entry handler: on a Lite site they answer with the
 * dispatcher's own top-level wpforms_entries_unavailable error while the form
 * operations keep working. Entry reads and the status write sit behind
 * manage_options because entries are user data, and every entry call passes
 * 'cap' => false to the handler so WPForms' own per-form capability check does
 * not quietly narrow a call wpmcp has already authorized; manage_options is
 * the stricter gate of the two.
 *
 * update-entry-status moves an entry between active, spam and trash through
 * the handler's own update(). Entries live in WPForms' wpforms_entries table,
 * so the write is snapshotted as a db_rows before-image of exactly that row
 * and restorable with rollback-operation. Entry deletion is deliberately not
 * offered.
 */
class WPForms_Integration extends Forms_Integration
{
    /** status arg => WPForms entry status column value. */
    private const STATUSES = [
        'active' => '',
        'spam'   => 'spam',
        'trash'  => 'trash',
    ];

    public function integration(): string
    {
        return 'wpforms';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return function_exists('wpforms') && is_object(wpforms()->form ?? null);
    }

    protected function summary(): string
    {
        return 'WPForms (forms, fields, notifications, and, with WPForms entry storage, entries and entry status)';
    }

    /** The decoded form data (fields, settings) of a WPForms form post. */
    private static function form_data(\WP_Post $post): array
    {
        $data = function_exists('wpforms_decode') ? wpforms_decode($post->post_content) : json_decode((string) $post->post_content, true);
        return is_array($data) ? $data : [];
    }

    private static function decode(\WP_Post $post): array
    {
        $data = self::form_data($post);
        return [
            'id'     => (int) $post->ID,
            'title'  => (string) $post->post_title,
            'fields' => is_array($data['fields'] ?? null) ? array_values($data['fields']) : [],
        ];
    }

    /** @return \WP_Post|null */
    private static function form_post(int $form_id)
    {
        $post = wpforms()->form->get($form_id);
        return $post instanceof \WP_Post ? $post : null;
    }

    /** The WPForms entry handler, or null on a site without entry storage. */
    private static function entries()
    {
        $handler = function_exists('wpforms') && method_exists(wpforms(), 'obj') ? wpforms()->obj('entry') : null;
        return is_object($handler) && method_exists($handler, 'get_entries') ? $handler : null;
    }

    /** @return true|array<string, string> */
    private static function requires_entries()
    {
        if (null !== self::entries()) {
            return true;
        }
        return [
            'code'    => 'wpforms_entries_unavailable',
            'message' => 'This site\'s WPForms does not store entries (entry storage is not part of WPForms Lite), so there are no submissions to read or update.',
        ];
    }

    private static function status_name(string $raw): string
    {
        $name = array_search($raw, self::STATUSES, true);
        return false === $name ? $raw : (string) $name;
    }

    private static function shape_entry(object $entry, bool $with_fields): array
    {
        $out = [
            'id'      => (int) ($entry->entry_id ?? 0),
            'form_id' => (int) ($entry->form_id ?? 0),
            'status'  => self::status_name((string) ($entry->status ?? '')),
            'starred' => ! empty($entry->starred),
            'viewed'  => ! empty($entry->viewed),
            'date'    => (string) ($entry->date ?? ''),
        ];
        if ($with_fields) {
            $fields            = json_decode((string) ($entry->fields ?? ''), true);
            $out['fields']     = is_array($fields) ? $fields : [];
            $out['ip_address'] = (string) ($entry->ip_address ?? '');
            $out['user_agent'] = (string) ($entry->user_agent ?? '');
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
                'description'  => 'List WPForms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = wpforms()->form->get('', [ 'post_status' => 'publish' ]);
                    $out   = [];
                    foreach ((array) $forms as $post) {
                        if ($post instanceof \WP_Post) {
                            $out[] = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title ];
                        }
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one WPForms form with its decoded field definitions',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $post = self::form_post((int) $args['form_id']);
                    return [ 'form' => null === $post ? null : self::decode($post) ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: id, type, label, whether it is required, and choices for choice fields',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $post = self::form_post((int) $args['form_id']);
                    if (null === $post) {
                        return [ 'form_id' => (int) $args['form_id'], 'fields' => null ];
                    }
                    $fields = [];
                    foreach (self::decode($post)['fields'] as $field) {
                        $field    = (array) $field;
                        $choices  = is_array($field['choices'] ?? null) ? $field['choices'] : [];
                        $fields[] = [
                            'id'       => (string) ($field['id'] ?? ''),
                            'type'     => (string) ($field['type'] ?? ''),
                            'label'    => (string) ($field['label'] ?? ''),
                            'required' => ! empty($field['required']),
                            'choices'  => array_values(array_map(static fn ($c) => (string) (is_array($c) ? ($c['label'] ?? '') : $c), $choices)),
                        ];
                    }
                    return [ 'form_id' => (int) $post->ID, 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications: id, name, whether it is enabled, recipient, sender, reply-to, subject, and message template, plus whether notifications are switched on for the form at all',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $post = self::form_post((int) $args['form_id']);
                    if (null === $post) {
                        return [ 'form_id' => (int) $args['form_id'], 'notifications' => null ];
                    }
                    $settings = (array) (self::form_data($post)['settings'] ?? []);
                    $out      = [];
                    foreach ((array) ($settings['notifications'] ?? []) as $id => $n) {
                        $n     = (array) $n;
                        $out[] = [
                            'id'             => (string) $id,
                            'name'           => (string) ($n['notification_name'] ?? ''),
                            // WPForms treats a notification without the flag as
                            // enabled; only an explicit 0 turns one off.
                            'enabled'        => ! isset($n['enable']) || 0 !== (int) $n['enable'],
                            'email'          => (string) ($n['email'] ?? ''),
                            'sender_name'    => (string) ($n['sender_name'] ?? ''),
                            'sender_address' => (string) ($n['sender_address'] ?? ''),
                            'replyto'        => (string) ($n['replyto'] ?? ''),
                            'subject'        => (string) ($n['subject'] ?? ''),
                            'message'        => (string) ($n['message'] ?? ''),
                        ];
                    }
                    return [
                        'form_id'               => (int) $post->ID,
                        'notifications_enabled' => ! empty($settings['notification_enable']),
                        'notifications'         => $out,
                    ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_entries(),
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (active, spam, trash). Needs WPForms entry storage and manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'  => [ 'type' => 'string', 'enum' => array_keys(self::STATUSES) ],
                    ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    [ $page_size, $offset ] = self::page_window($args);
                    $query                  = [
                        'form_id' => (int) $args['form_id'],
                        'orderby' => 'entry_id',
                        'order'   => 'DESC',
                    ];
                    if (isset($args['status'])) {
                        $query['status'] = self::STATUSES[ (string) $args['status'] ];
                    }
                    $rows = (array) self::entries()->get_entries($query + [ 'number' => $page_size, 'offset' => $offset ]);
                    $out  = [];
                    foreach ($rows as $row) {
                        if (is_object($row)) {
                            $out[] = self::shape_entry($row, false);
                        }
                    }
                    return [
                        'form_id' => (int) $args['form_id'],
                        'entries' => $out,
                        'total'   => (int) self::entries()->get_entries($query, true),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_entries(),
                'description'  => 'Read one entry in full: submitted field values, status, starred/viewed flags, date, IP address, and user agent. Needs WPForms entry storage and manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = self::entries()->get((int) $args['entry_id'], [ 'cap' => false ]);
                    return [ 'entry' => is_object($entry) ? self::shape_entry($entry, true) : null ];
                },
            ],
            'update-entry-status' => [
                'mode'         => 'write',
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_entries(),
                'description'  => 'Move one entry between active, spam, and trash through the WPForms entry handler. Snapshotted first (a before-image of the entry row) and restorable with rollback-operation. Needs WPForms entry storage and manage_options',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => array_keys(self::STATUSES) ],
                    ],
                    'required'   => [ 'entry_id', 'status' ],
                ],
                'handler'      => function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $entry    = self::entries()->get($entry_id, [ 'cap' => false ]);
                    if (! is_object($entry)) {
                        throw new Operation_Error('entry_not_found', 'WPForms has no entry with that id.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $previous = self::status_name((string) ($entry->status ?? ''));
                    $status   = (string) $args['status'];
                    if ($previous === $status) {
                        return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => false ];
                    }
                    $done = self::entries()->update($entry_id, [ 'status' => self::STATUSES[ $status ] ], '', '', [ 'cap' => false ]);
                    if (false === $done) {
                        throw new Operation_Error('update_failed', 'WPForms did not change the entry\'s status.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => true ];
                },
                'snapshot'     => static fn (array $args): ?array => self::row_snapshot('wpforms_entries', 'entry_id', (int) $args['entry_id'], [ 'status' => self::STATUSES[ (string) $args['status'] ] ?? '' ]),
            ],
        ];
    }
}
