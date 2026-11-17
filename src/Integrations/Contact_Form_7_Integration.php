<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Contact Form 7 integration (wpmcp/contactform7-read / -write pair),
 * delegating to CF7's own WPCF7_ContactForm model (verified against Contact
 * Form 7 5.x).
 *
 * Forms, markup, and mail templates come straight from CF7. Contact Form 7
 * itself does not store submissions (that is what Flamingo is for), so the
 * entry operations read Flamingo's Flamingo_Inbound_Message model through its
 * public API only (find/count/__construct/id; Flamingo exposes no
 * get_instance() and keeps $found_items private) and declare a per-op
 * 'requires' dependency so the dispatcher answers with its own top-level
 * flamingo_unavailable error when Flamingo is not active.
 *
 * Entries are user data (issue #66): every entry op sits behind an extra
 * edit_users capability on top of the pair's own capability, which is the cap
 * Flamingo itself maps every inbound-message capability to. edit_users rather
 * than manage_options is deliberate: under is_multisite() core's map_meta_cap
 * turns edit_users into manage_network_users (super admins only) while a site
 * administrator keeps manage_options, so manage_options would have let a site
 * admin read and delete submissions Flamingo's own UI denies them.
 *
 * Deletion is destructive (confirm:true), default-off until the site opts in
 * via wpmcp_enable_forms_write, and reversible: a Flamingo entry is an
 * ordinary flamingo_inbound post, so it is snapshotted (row, meta, terms)
 * before deletion and can be resurrected at its original id with
 * rollback-operation, exactly like the MetForm adapter's entry delete. Note
 * that reversibility and erasure are in tension and this adapter picks
 * reversibility: the snapshot holds a verbatim plaintext copy of the
 * submission until Snapshot_Store prunes it, so a delete is NOT a GDPR
 * erasure, and the op description says so in as many words. What the snapshot
 * is NOT is a way around the capability gate: Rollback_Service refuses to
 * restore a flamingo_inbound snapshot without edit_users, so the guard is not
 * one-way even though rollback-operation itself is an edit_posts ability.
 */
class Contact_Form_7_Integration extends Forms_Integration
{
    /**
     * Extra capability guarding submission (PII) operations: exactly the cap
     * Flamingo maps flamingo_edit_inbound_messages and
     * flamingo_delete_inbound_messages to, so wpmcp can never be a looser door
     * onto the same data than the host plugin's own UI. On multisite this is
     * strictly narrower than manage_options (see the class docblock).
     */
    public const ENTRY_CAPABILITY = 'edit_users';

    /** CF7 post meta holding the Flamingo channel binding for a form. */
    private const CHANNEL_META = '_flamingo';

    /** status arg => Flamingo post_status. */
    private const STATUSES = [
        'inbox' => 'publish',
        'spam'  => 'flamingo-spam',
        'trash' => 'trash',
    ];

    public function integration(): string
    {
        return 'contactform7';
    }

    public function is_available(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    protected function summary(): string
    {
        return 'Contact Form 7 (forms, markup, mail templates, Flamingo entries)';
    }

    private static function shape(\WPCF7_ContactForm $form): array
    {
        return [
            'id'    => (int) $form->id(),
            'title' => (string) $form->title(),
            'name'  => (string) $form->name(),
        ];
    }

    /**
     * Whether Flamingo (CF7's own submission store) is active. Filterable so a
     * site can force the entry ops off wholesale (a policy of "agents never
     * touch submissions" is cheaper to express here than op by op), and so the
     * suite can cover the Flamingo-absent path without unloading a class.
     */
    private static function has_flamingo(): bool
    {
        return (bool) apply_filters('wpmcp_contactform7_flamingo_active', class_exists('Flamingo_Inbound_Message'));
    }

    /**
     * Per-op dependency check for the entry ops: true when Flamingo is active,
     * otherwise the payload the dispatcher emits as a top-level error.
     *
     * @return true|array<string, string>
     */
    private static function requires_flamingo()
    {
        if (self::has_flamingo()) {
            return true;
        }
        return [
            'code'    => 'flamingo_unavailable',
            'message' => 'Contact Form 7 does not store submissions itself; entry operations need the Flamingo plugin, which is not active on this site.',
        ];
    }

    /** Flamingo's inbound-message post type, guarded by has_flamingo(). */
    private static function entry_post_type(): string
    {
        return (string) \Flamingo_Inbound_Message::post_type;
    }

    /**
     * Shape one Flamingo inbound message. Flamingo exposes the message id via
     * id() and the remaining fields as public properties, hydrated by the
     * constructor from the post's meta.
     *
     * @param \Flamingo_Inbound_Message $msg
     */
    private static function shape_entry($msg, bool $with_fields, bool $masked = false): array
    {
        $out = [
            'id'         => (int) $msg->id(),
            'subject'    => (string) ($msg->subject ?? ''),
            'from_name'  => (string) ($msg->from_name ?? ''),
            'from_email' => (string) ($msg->from_email ?? ''),
            'channel'    => (string) ($msg->channel ?? ''),
            'spam'       => (bool) ($msg->spam ?? false),
        ];
        if ($masked) {
            // A list shows many senders at once: name, email and the free-text
            // subject (which often holds either) are masked in full.
            $out = Masking::keys($out, [ 'subject', 'from_name', 'from_email' ]);
        }
        if ($with_fields) {
            $out['fields'] = is_array($msg->fields ?? null) ? $msg->fields : [];
            $out['meta']   = is_array($msg->meta ?? null) ? $msg->meta : [];
        }
        return $out;
    }

    /**
     * Resolve a CF7 form id to the Flamingo channel query args that scope a
     * listing to exactly that form.
     *
     * CF7 binds a form to its channel by TERM ID in the form's _flamingo post
     * meta, written on the form's first submission. The term id is the ONLY
     * trustworthy binding: the channel slug is seeded from the form's name()
     * but wp_insert_term/wp_unique_term_slug suffixes it on collision and
     * wpcf7_flamingo_update_channel re-slugs it on rename, so one form's
     * name() can be another form's channel slug. Falling back to the slug
     * would therefore be a way to hand back a DIFFERENT form's submissions to
     * a caller who asked for this one, and a form with entries but no meta is
     * precisely the case where that is most likely.
     *
     * So this fails closed and returns null whenever the term id is absent;
     * the caller turns that into a top-level error rather than an unscoped
     * query or an empty list that reads like "no submissions".
     */
    private static function channel_query(int $form_id): ?array
    {
        $form = \WPCF7_ContactForm::get_instance($form_id);
        if (! $form instanceof \WPCF7_ContactForm) {
            return null;
        }
        $meta       = get_post_meta($form_id, self::CHANNEL_META, true);
        $channel_id = is_array($meta) ? (int) ($meta['channel'] ?? 0) : 0;
        return $channel_id > 0 ? [ 'channel_id' => $channel_id ] : null;
    }

    protected function operations(): array
    {
        $ops = [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Contact Form 7 forms with id, title, and slug',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = \WPCF7_ContactForm::find();
                    $out   = [];
                    foreach ((array) $forms as $form) {
                        if ($form instanceof \WPCF7_ContactForm) {
                            $out[] = self::shape($form);
                        }
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one form: title, slug, the form markup, and the mail template',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    if (! $form instanceof \WPCF7_ContactForm) {
                        return [ 'form' => null ];
                    }
                    return [
                        'form' => self::shape($form) + [
                            'form_markup' => (string) $form->prop('form'),
                            'mail'        => $form->prop('mail'),
                        ],
                    ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'List one form\'s fields as Contact Form 7 parses them out of the form markup: name, type, whether it is required, and its options',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    if (! $form instanceof \WPCF7_ContactForm) {
                        return [ 'form_id' => (int) $args['form_id'], 'fields' => null ];
                    }
                    $fields = [];
                    foreach ((array) $form->scan_form_tags() as $tag) {
                        $name = (string) ($tag->name ?? '');
                        if ('' === $name) {
                            continue; // submit buttons and other unnamed tags carry no data
                        }
                        $type     = (string) ($tag->type ?? '');
                        $fields[] = [
                            'name'     => $name,
                            'type'     => (string) ($tag->basetype ?? rtrim($type, '*')),
                            'required' => '*' === substr($type, -1),
                            'options'  => array_values(array_map('strval', (array) ($tag->values ?? []))),
                        ];
                    }
                    return [ 'form_id' => (int) $form->id(), 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'List a form\'s mail notifications: the primary Mail template and the optional Mail (2) autoresponder, each with whether it is active, recipient, sender, subject, and body template',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    if (! $form instanceof \WPCF7_ContactForm) {
                        return [ 'form_id' => (int) $args['form_id'], 'notifications' => null ];
                    }
                    $out = [];
                    foreach ([ 'mail' => true, 'mail_2' => false ] as $key => $always_on) {
                        $mail = $form->prop($key);
                        if (! is_array($mail)) {
                            continue;
                        }
                        $out[] = [
                            'id'        => $key,
                            'active'    => $always_on || ! empty($mail['active']),
                            'recipient' => (string) ($mail['recipient'] ?? ''),
                            'sender'    => (string) ($mail['sender'] ?? ''),
                            'subject'   => (string) ($mail['subject'] ?? ''),
                            'body'      => (string) ($mail['body'] ?? ''),
                        ];
                    }
                    return [ 'form_id' => (int) $form->id(), 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_flamingo(),
                'description'  => 'List a form\'s Flamingo-stored submissions, newest first (sender name, sender email and subject are MASKED; get-entry returns one in full), with paging (page_size default 20, max 100, plus offset) and a status filter (inbox, the default, plus spam and trash, which Flamingo keeps out of the default listing). form_id is required and is resolved to the form\'s Flamingo channel term; a form whose channel binding is missing is refused rather than answered with an unscoped listing. Requires the Flamingo plugin and the edit_users capability because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'  => [ 'type' => 'string', 'enum' => [ 'inbox', 'spam', 'trash' ] ],
                    ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    [ $page_size, $offset ] = self::page_window($args);
                    $status                 = (string) ($args['status'] ?? 'inbox');

                    // form_id is REQUIRED, matching every other forms adapter:
                    // an unscoped listing would dump every form's submissions
                    // site-wide, which is not a thing an agent should be able
                    // to ask for by omission.
                    $scope = self::channel_query((int) $args['form_id']);
                    if (null === $scope) {
                        // Fail closed on the dispatcher's own error channel.
                        // An empty success envelope would be indistinguishable
                        // from "this form has no submissions".
                        throw new Operation_Error(
                            'unresolved_form_channel',
                            'That form has no resolvable Flamingo channel (the form does not exist, or it has never received a submission, so Contact Form 7 has not written its _flamingo channel binding yet). Refusing rather than returning another form\'s submissions or an unscoped listing.',
                            [ 'form_id' => (int) $args['form_id'] ]
                        );
                    }

                    // Flamingo's find() injects offset => 0 by default, and
                    // WP_Query prefers a numeric offset over paged when it
                    // builds the LIMIT clause, so paging MUST go through
                    // offset or every page returns the first one. post_status
                    // is passed explicitly because find() defaults to 'any'
                    // while count() defaults to 'publish', which would make
                    // the total disagree with the page.
                    $query = $scope + [
                        'posts_per_page' => $page_size,
                        'offset'         => $offset,
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                        'post_status'    => self::STATUSES[ $status ] ?? self::STATUSES['inbox'],
                    ];

                    $messages = (array) \Flamingo_Inbound_Message::find($query);
                    $out      = [];
                    foreach ($messages as $msg) {
                        if ($msg instanceof \Flamingo_Inbound_Message) {
                            $out[] = self::shape_entry($msg, false, true);
                        }
                    }

                    // The count MUST NOT carry the page window. Flamingo's
                    // count() forwards its args straight to WP_Query, and
                    // WP_Query::set_found_posts() returns early on an empty
                    // result set, so counting with the offset of a page past
                    // the last one reports total 0 instead of the real total.
                    // Dropping the window also stops the same tax+status query
                    // running twice per call.
                    $count_query = $query;
                    unset($count_query['offset'], $count_query['posts_per_page']);

                    return [
                        'entries' => $out,
                        'masked'  => true,
                        'total'   => (int) \Flamingo_Inbound_Message::count($count_query),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_flamingo(),
                'description'  => 'Read one Flamingo-stored submission in full: subject, sender, channel, submitted fields, and meta. Requires the Flamingo plugin and the edit_users capability (the cap Flamingo itself gates inbound messages behind) because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $post = get_post((int) $args['entry_id']);
                    // Flamingo's constructor does NOT validate the post type,
                    // so the check has to happen here or any post id would be
                    // hydrated into an empty-looking "entry".
                    if (! $post instanceof \WP_Post || self::entry_post_type() !== $post->post_type) {
                        return [ 'entry' => null ];
                    }
                    return [ 'entry' => self::shape_entry(new \Flamingo_Inbound_Message($post), true) ];
                },
            ],
            'update-entry-status' => [
                'mode'         => 'write',
                'objects'      => [ 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_flamingo(),
                'description'  => 'Move one Flamingo-stored submission between the inbox and the trash, through Flamingo\'s own trash()/untrash() so it lands where Flamingo\'s screen expects it. Snapshotted first and restorable with rollback-operation. Spam flagging is deliberately not offered: Flamingo reports spam to Akismet, which would send the submission to a third-party service. Requires the Flamingo plugin and the edit_users capability',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'inbox', 'trash' ] ],
                    ],
                    'required'   => [ 'entry_id', 'status' ],
                ],
                'handler'      => function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $post     = get_post($entry_id);
                    if (! $post instanceof \WP_Post || self::entry_post_type() !== $post->post_type) {
                        throw new Operation_Error('entry_not_found', 'No Flamingo submission has that id.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $previous = 'trash' === $post->post_status ? 'trash' : 'inbox';
                    $status   = (string) $args['status'];
                    if ($previous === $status) {
                        return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => false ];
                    }
                    if ('trash' === $status && ! EMPTY_TRASH_DAYS) {
                        // With the trash disabled, trashing IS permanent
                        // deletion (Flamingo's trash() calls delete()). That
                        // is delete-entry's job, behind its own opt-in and
                        // confirm, not something a status change may do.
                        throw new Operation_Error('trash_disabled', 'This site has the trash disabled (EMPTY_TRASH_DAYS is 0), so trashing would permanently delete the submission. Use delete-entry instead.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $message = new \Flamingo_Inbound_Message($post);
                    $done    = 'trash' === $status ? $message->trash() : $message->untrash();
                    if (! $done) {
                        throw new Operation_Error('update_failed', 'Flamingo did not change the submission\'s status.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => true ];
                },
                'snapshot'     => static function (array $args): ?array {
                    $entry_id = (int) $args['entry_id'];
                    $post     = get_post($entry_id);
                    if (! $post instanceof \WP_Post || self::entry_post_type() !== $post->post_type) {
                        return null;
                    }
                    return [ 'object_type' => 'post', 'object_id' => $entry_id ];
                },
            ],
            'delete-entry' => [
                'mode'               => 'destructive',
                'objects'            => [ 'entry_id' => 'entry' ],
                'capability'         => self::ENTRY_CAPABILITY,
                'requires'           => static fn () => self::requires_flamingo(),
                'description'        => 'Delete one Flamingo-stored submission. Default-off (opt in with wpmcp_enable_forms_write), requires confirm:true, the Flamingo plugin, and the edit_users capability. Reversible: a Flamingo entry is a flamingo_inbound post, so it is snapshotted (row, postmeta, channel terms) before deletion and can be resurrected at its original id with rollback-operation using the returned operation_id. Deletion is therefore NOT erasure: the snapshot keeps a verbatim plaintext copy of the submission (name, email, remote IP, message body) until Snapshot_Store prunes it. Restoring it is held to the same edit_users bar as deleting it (rollback-operation is otherwise an edit_posts ability), but the plaintext copy still exists, so use a real erasure tool if the goal is to destroy the data',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'            => function (array $args): array {
                    $entry_id = (int) $args['entry_id'];
                    $post     = get_post($entry_id);
                    if (! $post instanceof \WP_Post || self::entry_post_type() !== $post->post_type) {
                        return [ 'entry_id' => $entry_id, 'deleted' => false, 'reason' => 'not_found' ];
                    }
                    return [
                        'entry_id' => $entry_id,
                        'deleted'  => (bool) wp_delete_post($entry_id, true),
                    ];
                },
                // Only name a snapshot target for an id that is actually a
                // Flamingo entry. The snapshot callable runs BEFORE the
                // handler's post-type guard, so returning a target
                // unconditionally would capture and persist a full copy of any
                // unrelated post (row, meta, terms) on a call the handler then
                // refuses as not_found, and a later rollback-operation on that
                // operation_id would silently revert that unrelated post.
                'snapshot'           => static function (array $args): ?array {
                    $entry_id = (int) $args['entry_id'];
                    $post     = get_post($entry_id);
                    if (! $post instanceof \WP_Post || self::entry_post_type() !== $post->post_type) {
                        return null;
                    }
                    return [ 'object_type' => 'post', 'object_id' => $entry_id ];
                },
            ] + self::write_flags(),
        ];

        return $ops + self::config_operations();
    }

    // ------------------------------------------------------------------
    // Mail and message edits (issue #674)
    // ------------------------------------------------------------------

    private const MAIL_KEYS = [ 'mail', 'mail_2' ];

    /** The form's post type, from CF7 itself when it is loaded. */
    private static function form_post_type(): string
    {
        return defined('WPCF7_ContactForm::post_type') ? (string) \WPCF7_ContactForm::post_type : 'wpcf7_contact_form';
    }

    /** @return array<string, mixed>|null a refusal when no such form exists */
    private static function form_refusal(int $form_id): ?array
    {
        $form = \WPCF7_ContactForm::get_instance($form_id);
        if ($form instanceof \WPCF7_ContactForm) {
            return null;
        }

        return [ 'code' => 'form_not_found', 'message' => 'No Contact Form 7 form has that id.', 'data' => [ 'form_id' => $form_id ] ];
    }

    /** The value of one header line ("Cc", "Bcc", "Reply-To") in CF7's additional_headers text. */
    private static function header_value(string $headers, string $name): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $headers) ?: [] as $line) {
            if (0 === stripos($line, $name . ':')) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return '';
    }

    /** additional_headers with one header set (or removed when the value is empty), the others kept. */
    private static function with_header(string $headers, string $name, string $value): string
    {
        $lines = [];
        $done  = false;
        foreach (preg_split('/\r\n|\r|\n/', $headers) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }
            if (0 === stripos($line, $name . ':')) {
                if (! $done && '' !== $value) {
                    $lines[] = $name . ': ' . $value;
                }
                $done = true;
                continue;
            }
            $lines[] = $line;
        }
        if (! $done && '' !== $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> the editable view of one mail template */
    private static function mail_view(array $mail): array
    {
        $headers = (string) ($mail['additional_headers'] ?? '');

        return [
            'to'       => (string) ($mail['recipient'] ?? ''),
            'cc'       => self::header_value($headers, 'Cc'),
            'bcc'      => self::header_value($headers, 'Bcc'),
            'reply_to' => self::header_value($headers, 'Reply-To'),
            'sender'   => (string) ($mail['sender'] ?? ''),
            'subject'  => (string) ($mail['subject'] ?? ''),
            'body'     => (string) ($mail['body'] ?? ''),
        ];
    }

    /** Save new mail properties through CF7's own model; throws when CF7 does not save. */
    private static function save_mail(\WPCF7_ContactForm $form, string $key, array $mail, int $form_id): void
    {
        $form->set_properties([ $key => $mail ]);
        if (! $form->save()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Error('update_failed', 'Contact Form 7 did not save the form.', [ 'form_id' => $form_id ]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function config_operations(): array
    {
        $write = [
            'capability' => 'manage_options',
            'objects'    => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
            'snapshot'   => static fn (array $args): ?array => self::post_snapshot((int) $args['form_id'], self::form_post_type()),
        ] + self::write_flags();
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $mail  = [ 'type' => 'string', 'enum' => self::MAIL_KEYS ];

        return [
            'list-messages'              => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'List a form\'s system messages (mail_sent_ok, validation_error and the rest) with the key of each, which update-messages takes',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ],
                'handler'      => static function (array $args): array {
                    $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    return [
                        'form_id'  => (int) $args['form_id'],
                        'messages' => $form instanceof \WPCF7_ContactForm ? array_map('strval', (array) $form->prop('messages')) : null,
                    ];
                },
            ],
            'update-notification'        => $write + [
                'mode'         => 'write',
                'description'  => 'Edit one mail template of a form (mail or mail_2): enabled (mail_2 only), subject, body, sender and reply_to. Recipients are changed with set-notification-recipients. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after of every changed field. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $mail,
                        'enabled'         => [ 'type' => 'boolean' ],
                        'subject'         => [ 'type' => 'string', 'maxLength' => 998 ],
                        'body'            => [ 'type' => 'string', 'maxLength' => 100000 ],
                        'sender'          => [ 'type' => 'string', 'maxLength' => 320 ],
                        'reply_to'        => [ 'type' => 'string', 'maxLength' => 320 ],
                    ],
                    'required'             => [ 'form_id', 'notification_id' ],
                    'minProperties'        => 3,
                    'additionalProperties' => false,
                ],
                'validate'     => static function (array $args): ?array {
                    $refusal = self::form_refusal((int) $args['form_id']);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $bad = static fn (string $message): array => [ 'code' => 'invalid_notification', 'message' => $message, 'data' => [ 'form_id' => (int) $args['form_id'] ] ];
                    if (isset($args['enabled']) && 'mail' === $args['notification_id']) {
                        return $bad('The primary Mail template cannot be switched off; only mail_2 can.');
                    }
                    foreach ([ 'subject', 'body' ] as $field) {
                        if (isset($args[ $field ]) && '' === trim((string) $args[ $field ])) {
                            return $bad(sprintf('%s must not be empty.', ucfirst($field)));
                        }
                    }
                    if (isset($args['subject']) && null !== ($problem = self::header_text_problem((string) $args['subject'], 'Subject'))) {
                        return $bad($problem);
                    }
                    foreach ([ 'sender' => 'Sender', 'reply_to' => 'Reply-to' ] as $field => $label) {
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
                    $form_id = (int) $args['form_id'];
                    $key     = (string) $args['notification_id'];
                    $form    = \WPCF7_ContactForm::get_instance($form_id);
                    $current = (array) $form->prop($key);
                    $before  = self::mail_view($current) + [ 'enabled' => 'mail' === $key || ! empty($current['active']) ];
                    $next    = $current;
                    if (isset($args['enabled'])) {
                        $next['active'] = (bool) $args['enabled'];
                    }
                    foreach ([ 'subject' => 'subject', 'body' => 'body', 'sender' => 'sender' ] as $field => $prop) {
                        if (isset($args[ $field ])) {
                            $next[ $prop ] = (string) $args[ $field ];
                        }
                    }
                    if (isset($args['reply_to'])) {
                        $next['additional_headers'] = self::with_header((string) ($current['additional_headers'] ?? ''), 'Reply-To', trim((string) $args['reply_to']));
                    }
                    $after   = self::mail_view($next) + [ 'enabled' => 'mail' === $key || ! empty($next['active']) ];
                    $changes = self::diff_fields($before, $after);
                    if ([] !== $changes) {
                        self::save_mail($form, $key, $next, $form_id);
                    }
                    return [ 'form_id' => $form_id, 'notification_id' => $key, 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
            'set-notification-recipients' => $write + [
                'mode'            => 'destructive',
                'description'     => 'Change who a mail template is sent to: to (the recipient), cc and bcc (kept in the template\'s additional headers; an empty string removes one). This redirects personal data, so it needs confirm:true, and the confirmation shows the recipients before and after. Off by default (opt in with wpmcp_enable_forms_write). Undo: rollback-operation',
                'input_schema'    => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'         => $id,
                        'notification_id' => $mail,
                        'to'              => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'cc'              => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'bcc'             => [ 'type' => 'string', 'maxLength' => 2000 ],
                    ],
                    'required'      => [ 'form_id', 'notification_id' ],
                    'minProperties' => 3,
                ],
                'validate'        => static function (array $args): ?array {
                    $refusal = self::form_refusal((int) $args['form_id']);
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
                    [ $before, $after ] = self::recipient_views($args);
                    return [
                        'message' => sprintf('It changes who mail template %s of form %d is sent to. Undo: rollback-operation.', (string) $args['notification_id'], (int) $args['form_id']),
                        'data'    => [ 'form_id' => (int) $args['form_id'], 'before' => $before, 'after' => $after ],
                    ];
                },
                'handler'         => static function (array $args): array {
                    $form_id            = (int) $args['form_id'];
                    $key                = (string) $args['notification_id'];
                    $form               = \WPCF7_ContactForm::get_instance($form_id);
                    $next               = self::recipient_mail($form, $args);
                    [ $before, $after ] = self::recipient_views($args);
                    if ($before !== $after) {
                        self::save_mail($form, $key, $next, $form_id);
                    }
                    return [ 'form_id' => $form_id, 'notification_id' => $key, 'changed' => $before !== $after, 'recipient_changed' => $before !== $after, 'before' => $before, 'after' => $after ];
                },
            ],
            'update-messages'            => $write + [
                'mode'         => 'write',
                'description'  => 'Set some of a form\'s system messages (mail_sent_ok, validation_error and the rest; keys from list-messages). Values are plain text up to 400 characters. Off by default (opt in with wpmcp_enable_forms_write). Returns before and after. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'  => $id,
                        'messages' => [ 'type' => 'object', 'minProperties' => 1, 'additionalProperties' => [ 'type' => 'string', 'maxLength' => 400 ] ],
                    ],
                    'required'   => [ 'form_id', 'messages' ],
                ],
                'validate'     => static function (array $args): ?array {
                    $refusal = self::form_refusal((int) $args['form_id']);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $form  = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    $known = function_exists('wpcf7_messages') ? array_keys((array) wpcf7_messages()) : array_keys((array) $form->prop('messages'));
                    foreach (array_keys((array) $args['messages']) as $key) {
                        if (! in_array((string) $key, $known, true)) {
                            return [ 'code' => 'unknown_message', 'message' => sprintf('"%s" is not a Contact Form 7 message key.', (string) $key), 'data' => [ 'known' => $known ] ];
                        }
                    }
                    return null;
                },
                'handler'      => static function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    $form    = \WPCF7_ContactForm::get_instance($form_id);
                    $current = array_map('strval', (array) $form->prop('messages'));
                    $next    = $current;
                    foreach ((array) $args['messages'] as $key => $text) {
                        $next[ (string) $key ] = trim(wp_strip_all_tags((string) $text));
                    }
                    $changes = self::diff_fields($current, $next);
                    if ([] !== $changes) {
                        $form->set_properties([ 'messages' => $next ]);
                        if (! $form->save()) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                            throw new Operation_Error('update_failed', 'Contact Form 7 did not save the form.', [ 'form_id' => $form_id ]);
                        }
                    }
                    return [ 'form_id' => $form_id, 'changed' => [] !== $changes, 'changes' => $changes ];
                },
            ],
        ];
    }

    /**
     * The mail template as set-notification-recipients would leave it.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function recipient_mail(\WPCF7_ContactForm $form, array $args): array
    {
        $mail = (array) $form->prop((string) $args['notification_id']);
        if (isset($args['to'])) {
            $mail['recipient'] = trim((string) $args['to']);
        }
        $headers = (string) ($mail['additional_headers'] ?? '');
        foreach ([ 'cc' => 'Cc', 'bcc' => 'Bcc' ] as $field => $name) {
            if (isset($args[ $field ])) {
                $headers = self::with_header($headers, $name, trim((string) $args[ $field ]));
            }
        }
        $mail['additional_headers'] = $headers;

        return $mail;
    }

    /**
     * Recipients before and after, for the preview and the result.
     *
     * @param array<string, mixed> $args
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function recipient_views(array $args): array
    {
        $form   = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
        $pick   = static fn (array $mail): array => array_intersect_key(self::mail_view($mail), [ 'to' => 1, 'cc' => 1, 'bcc' => 1 ]);

        return [ $pick((array) $form->prop((string) $args['notification_id'])), $pick(self::recipient_mail($form, $args)) ];
    }
}
