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
 * offered (entries are a Pro table and no licensed copy was available to
 * verify a delete against).
 *
 * Issue #674: notifications and confirmations are editable (opt-in, one
 * snapshot of the form post per write, see config_operations()).
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
        return 'WPForms (forms, fields, notifications; entries with entry storage)';
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
        $ops = $this->read_and_entry_operations();

        return $ops + self::config_operations();
    }

    protected function read_and_entry_operations(): array
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
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one WPForms form with its decoded field definitions',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $post = self::form_post((int) $args['form_id']);
                    return [ 'form' => null === $post ? null : self::decode($post) ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
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
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
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
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_entries(),
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (spam or trash; without one every entry is listed, each with its status). Needs WPForms entry storage and manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        // Only the non-empty statuses filter: WPForms reads an
                        // empty status as "no filter", so "active" would
                        // silently list everything.
                        'status'  => [ 'type' => 'string', 'enum' => [ 'spam', 'trash' ] ],
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
                'objects'      => [ 'entry_id' => 'entry' ],
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
                'objects'      => [ 'entry_id' => 'entry' ],
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

    // ------------------------------------------------------------------
    // Notification and confirmation edits (issue #674)
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null a refusal when no WPForms form has that id */
    private static function form_refusal(int $form_id): ?array
    {
        if (null !== self::form_post($form_id)) {
            return null;
        }

        return [ 'code' => 'form_not_found', 'message' => 'No WPForms form has that id.', 'data' => [ 'form_id' => $form_id ] ];
    }

    /**
     * The confirmations WPForms would use: the stored ones, or, for a form
     * saved before confirmations existed, the single one built from the
     * legacy confirmation_* settings (what class-process.php does at submit
     * time).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function effective_confirmations(array $settings): array
    {
        $stored = $settings['confirmations'] ?? null;
        if (is_array($stored) && [] !== $stored) {
            $out = [];
            foreach ($stored as $id => $c) {
                $out[ (string) $id ] = (array) $c;
            }
            return $out;
        }

        return [ '1' => [
            'type'           => ! empty($settings['confirmation_type']) ? (string) $settings['confirmation_type'] : 'message',
            'message'        => ! empty($settings['confirmation_message']) ? (string) $settings['confirmation_message'] : 'Thanks for contacting us! We will be in touch with you shortly.',
            'message_scroll' => ! empty($settings['confirmation_message_scroll']) ? $settings['confirmation_message_scroll'] : 1,
            'page'           => ! empty($settings['confirmation_page']) ? (string) $settings['confirmation_page'] : '',
            'redirect'       => ! empty($settings['confirmation_redirect']) ? (string) $settings['confirmation_redirect'] : '',
        ] ];
    }

    /** @return array<string, mixed> one confirmation as list-confirmations reports it */
    private static function confirmation_view(string $id, array $c): array
    {
        return [
            'id'       => $id,
            'name'     => (string) ($c['name'] ?? ''),
            'type'     => '' === (string) ($c['type'] ?? '') ? 'message' : (string) $c['type'],
            'message'  => (string) ($c['message'] ?? ''),
            'page'     => (string) ($c['page'] ?? ''),
            'redirect' => (string) ($c['redirect'] ?? ''),
        ];
    }

    /** @return array<string, mixed> one notification as update-notification edits it */
    private static function notification_view(array $n): array
    {
        return [
            'enabled'        => ! isset($n['enable']) || 0 !== (int) $n['enable'],
            'name'           => (string) ($n['notification_name'] ?? ''),
            'to'             => (string) ($n['email'] ?? ''),
            'sender_name'    => (string) ($n['sender_name'] ?? ''),
            'sender_address' => (string) ($n['sender_address'] ?? ''),
            'replyto'        => (string) ($n['replyto'] ?? ''),
            'subject'        => (string) ($n['subject'] ?? ''),
            'message'        => (string) ($n['message'] ?? ''),
        ];
    }

    /** Save a changed form through wp_update_post, the way WPForms stores it (JSON, slashed). */
    private static function save_form_data(\WP_Post $post, array $data): void
    {
        $json   = function_exists('wpforms_encode') ? wpforms_encode($data) : wp_slash(wp_json_encode($data));
        $result = wp_update_post([ 'ID' => $post->ID, 'post_content' => $json ], true);
        if (is_wp_error($result) || ! $result) {
            throw new Operation_Error('update_failed', 'WPForms did not save the form.', [ 'form_id' => (int) $post->ID ]);
        }
        clean_post_cache($post->ID);
    }

    /**
     * Plan one notification edit: the form, its data, the notification as it
     * is and as it would be, or a refusal.
     *
     * @return array{0: ?array, 1: ?array}  [refusal, plan]
     */
    private static function notification_plan(array $args, bool $with_recipients = false): array
    {
        $post = self::form_post((int) $args['form_id']);
        if (null === $post) {
            return [ self::form_refusal((int) $args['form_id']), null ];
        }
        $data = self::form_data($post);
        $id   = (string) $args['notification_id'];
        $all  = (array) ($data['settings']['notifications'] ?? []);
        if (! isset($all[ $id ]) || ! is_array($all[ $id ])) {
            return [ [ 'code' => 'notification_not_found', 'message' => 'This form has no notification with that id (see list-notifications).', 'data' => [ 'form_id' => (int) $post->ID, 'notification_id' => $id ] ], null ];
        }
        $before = self::notification_view($all[ $id ]);
        $next   = $all[ $id ];
        $map    = [ 'name' => 'notification_name', 'sender_name' => 'sender_name', 'sender_address' => 'sender_address', 'replyto' => 'replyto', 'subject' => 'subject', 'message' => 'message' ];
        foreach ($map as $arg => $key) {
            if (isset($args[ $arg ])) {
                $next[ $key ] = (string) $args[ $arg ];
            }
        }
        if (isset($args['enabled'])) {
            $next['enable'] = $args['enabled'] ? '1' : '0';
        }
        // Who a notification goes to is changed only by set-notification-recipients
        // (confirm, validation, domain filter, preview), never by the plain edit.
        if ($with_recipients && isset($args['to'])) {
            $next['email'] = (string) $args['to'];
        }

        $new_data = $data;
        $new_data['settings']['notifications'][ $id ] = $next;

        return [ null, [ 'post' => $post, 'new_data' => $new_data, 'id' => $id, 'before' => $before, 'after' => self::notification_view($next) ] ];
    }

    /**
     * Plan one confirmation edit.
     *
     * @return array{0: ?array, 1: ?array}  [refusal, plan]
     */
    private static function confirmation_plan(array $args): array
    {
        $post = self::form_post((int) $args['form_id']);
        if (null === $post) {
            return [ self::form_refusal((int) $args['form_id']), null ];
        }
        $data = self::form_data($post);
        $all  = self::effective_confirmations((array) ($data['settings'] ?? []));
        $id   = (string) $args['confirmation_id'];
        if (! isset($all[ $id ])) {
            return [ [ 'code' => 'confirmation_not_found', 'message' => 'This form has no confirmation with that id (see list-confirmations).', 'data' => [ 'form_id' => (int) $post->ID, 'confirmation_id' => $id ] ], null ];
        }
        $before = self::confirmation_view($id, $all[ $id ]);
        $next   = $all[ $id ];
        if (isset($args['name'])) {
            $next['name'] = (string) $args['name'];
        }
        if (isset($args['type'])) {
            $next['type'] = (string) $args['type'];
        }
        if (isset($args['message'])) {
            $next['message'] = wp_kses_post((string) $args['message']);
        }
        if (isset($args['page'])) {
            $next['page'] = (string) (int) $args['page'];
        }
        if (isset($args['redirect'])) {
            // Judge what was given, before esc_url_raw() can turn "not a url" into one.
            if (! preg_match('#^https?://[^\s]+$#i', (string) $args['redirect'])) {
                return [ [ 'code' => 'invalid_confirmation', 'message' => 'A redirect confirmation needs an http or https url.', 'data' => [ 'form_id' => (int) $post->ID, 'confirmation_id' => $id ] ], null ];
            }
            $raw_redirect = (string) $args['redirect'];
            $redirect_problem = self::redirect_tag_problem($raw_redirect);
            $has_tags = (bool) preg_match('/[{}\[\]]/', $raw_redirect);
            if (null === $redirect_problem && ! $has_tags && esc_url_raw($raw_redirect) !== $raw_redirect) {
                $redirect_problem = 'The redirect url contains characters WordPress would strip, so it would be saved altered. Use a plain url.';
            }
            if (null !== $redirect_problem) {
                return [ [ 'code' => 'invalid_confirmation', 'message' => $redirect_problem, 'data' => [ 'form_id' => (int) $post->ID, 'confirmation_id' => $id ] ], null ];
            }
            $next['redirect'] = $has_tags ? $raw_redirect : esc_url_raw($raw_redirect);
        }
        $after   = self::confirmation_view($id, $next);
        $problem = self::confirmation_problem([ 'type' => $after['type'], 'message' => $after['message'], 'page' => $after['page'], 'url' => $after['redirect'] ]);
        if (null !== $problem) {
            return [ [ 'code' => 'invalid_confirmation', 'message' => $problem, 'data' => [ 'form_id' => (int) $post->ID, 'confirmation_id' => $id ] ], null ];
        }

        $new_data                                         = $data;
        $new_data['settings']                             = (array) ($data['settings'] ?? []);
        $new_data['settings']['confirmations']            = $all;
        $new_data['settings']['confirmations'][ $id ]     = $next;

        return [ null, [ 'post' => $post, 'new_data' => $new_data, 'id' => $id, 'before' => $before, 'after' => $after ] ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function config_operations(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $note  = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ];
        // WPForms registers its form post type with map_meta_cap off, so core's
        // edit_post is false even for an administrator: the writes sit behind
        // manage_options (WPForms' own bar) and declare no per-post object, and
        // the undo is a db_rows before-image of the form's posts row (which
        // Rollback_Service restores at manage_options) rather than a post
        // snapshot, whose restore would ask core for edit_post.
        $write = [
            'capability' => 'manage_options',
            'snapshot'   => static function (array $args): ?array {
                $plan = isset($args['confirmation_id']) ? self::confirmation_plan($args)[1] : self::notification_plan($args, array_key_exists('to', $args))[1];
                return null === $plan ? null : self::row_snapshot('posts', 'ID', (int) $plan['post']->ID, [ 'post_content' => wp_json_encode($plan['new_data']) ]);
            },
        ] + self::write_flags();

        return [
            'list-confirmations'          => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'List one form\'s confirmations (what a visitor sees after submitting): id, name, type (message, page, redirect), message, page id and redirect url. A form saved before confirmations existed reports its single legacy one as id 1',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ],
                'handler'      => static function (array $args): array {
                    $post = self::form_post((int) $args['form_id']);
                    if (null === $post) {
                        return [ 'form_id' => (int) $args['form_id'], 'confirmations' => null ];
                    }
                    $out = [];
                    foreach (self::effective_confirmations((array) (self::form_data($post)['settings'] ?? [])) as $cid => $c) {
                        $out[] = self::confirmation_view((string) $cid, $c);
                    }
                    return [ 'form_id' => (int) $post->ID, 'confirmations' => $out ];
                },
            ],
            'update-notification'         => $write + [
                'mode'         => 'write',
                'description'  => 'Edit one notification of a form: enabled, name, subject, message, sender_name, sender_address and replyto. The recipient is changed with set-notification-recipients. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after of every changed field. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $note,
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
                    foreach ([ 'sender_address' => 'Sender address', 'replyto' => 'Reply-to' ] as $field => $label) {
                        if (isset($args[ $field ])) {
                            $problem = self::address_problem((string) $args[ $field ], $label, 'replyto' === $field, true);
                            if (null !== $problem) {
                                return $bad($problem);
                            }
                        }
                    }
                    return null;
                },
                'handler'      => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_form_data($plan['post'], $plan['new_data']);
                    }
                    return [ 'form_id' => (int) $plan['post']->ID, 'notification_id' => $plan['id'], 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
            'set-notification-recipients' => $write + [
                'mode'            => 'destructive',
                'description'     => 'Change who a notification is sent to (to: one or more addresses or smart tags such as {admin_email}). This redirects personal data, so it needs confirm:true, and the confirmation shows the recipient before and after. Off by default (opt in with wpmcp_enable_forms_write). Undo: rollback-operation',
                'input_schema'    => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => $id, 'notification_id' => $note, 'to' => [ 'type' => 'string', 'maxLength' => 2000 ] ],
                    'required'   => [ 'form_id', 'notification_id', 'to' ],
                ],
                'validate'        => static function (array $args): ?array {
                    [ $refusal ] = self::notification_plan($args, true);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $problem = self::address_problem((string) $args['to'], 'To', false);
                    return null === $problem ? null : [ 'code' => 'invalid_recipients', 'message' => $problem, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                },
                'confirm_preview' => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args, true);
                    return [
                        'message' => sprintf('It changes who notification %s of form %d is sent to. Undo: rollback-operation.', $plan['id'], (int) $plan['post']->ID),
                        'data'    => [ 'form_id' => (int) $plan['post']->ID, 'before' => [ 'to' => $plan['before']['to'] ], 'after' => [ 'to' => $plan['after']['to'] ] ],
                    ];
                },
                'handler'         => static function (array $args): array {
                    [ , $plan ] = self::notification_plan($args, true);
                    $changed    = $plan['before']['to'] !== $plan['after']['to'];
                    if ($changed) {
                        self::save_form_data($plan['post'], $plan['new_data']);
                    }
                    return [
                        'form_id'           => (int) $plan['post']->ID,
                        'notification_id'   => $plan['id'],
                        'changed'           => $changed,
                        'recipient_changed' => $changed,
                        'before'            => [ 'to' => $plan['before']['to'] ],
                        'after'             => [ 'to' => $plan['after']['to'] ],
                    ];
                },
            ],
            'update-confirmation'         => $write + [
                'mode'         => 'destructive',
                'description'  => 'Edit one confirmation of a form (needs confirm:true; the confirmation shows the confirmation before and after, since a redirect sends visitors, and smart tag values, to another page): name, type (message, page, redirect), message (post HTML only), page (id of a published page) and redirect (an http or https url). The result must be coherent for its type. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'confirmation_id' => $note,
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
                        'message' => sprintf('It changes confirmation %s of form %d. Undo: rollback-operation.', $plan['id'], (int) $plan['post']->ID),
                        'data'    => [ 'form_id' => (int) $plan['post']->ID, 'before' => $plan['before'], 'after' => $plan['after'] ],
                    ];
                },
                'handler'      => static function (array $args): array {
                    [ , $plan ] = self::confirmation_plan($args);
                    $changes    = self::diff_fields($plan['before'], $plan['after']);
                    if ([] !== $changes) {
                        self::save_form_data($plan['post'], $plan['new_data']);
                    }
                    return [ 'form_id' => (int) $plan['post']->ID, 'confirmation_id' => $plan['id'], 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
        ];
    }
}
