<?php

namespace WPMCP\Integrations;

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
 * via wpmcp_integration_op_enabled, and reversible: a Flamingo entry is an
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
class Contact_Form_7_Integration extends Integration_Dispatcher
{
    /**
     * Extra capability guarding submission (PII) operations: exactly the cap
     * Flamingo maps flamingo_edit_inbound_messages and
     * flamingo_delete_inbound_messages to, so wpmcp can never be a looser door
     * onto the same data than the host plugin's own UI. On multisite this is
     * strictly narrower than manage_options (see the class docblock).
     */
    private const ENTRY_CAPABILITY = 'edit_users';

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
        return 'Contact Form 7 (forms, markup, mail templates, and Flamingo-stored entries)';
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
    private static function shape_entry($msg, bool $with_fields): array
    {
        $out = [
            'id'         => (int) $msg->id(),
            'subject'    => (string) ($msg->subject ?? ''),
            'from_name'  => (string) ($msg->from_name ?? ''),
            'from_email' => (string) ($msg->from_email ?? ''),
            'channel'    => (string) ($msg->channel ?? ''),
            'spam'       => (bool) ($msg->spam ?? false),
        ];
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
        return [
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
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_flamingo(),
                'description'  => 'List a form\'s Flamingo-stored submissions, newest first, with paging (page_size default 20, max 100, plus offset) and a status filter (inbox, the default, plus spam and trash, which Flamingo keeps out of the default listing). form_id is required and is resolved to the form\'s Flamingo channel term; a form whose channel binding is missing is refused rather than answered with an unscoped listing. Requires the Flamingo plugin and the edit_users capability because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'inbox', 'spam', 'trash' ] ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $offset    = max(0, (int) ($args['offset'] ?? 0));
                    $status    = (string) ($args['status'] ?? 'inbox');

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
                            $out[] = self::shape_entry($msg, false);
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
                        'total'   => (int) \Flamingo_Inbound_Message::count($count_query),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
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
            'delete-entry' => [
                'mode'               => 'destructive',
                'enabled_by_default' => false,
                'capability'         => self::ENTRY_CAPABILITY,
                'requires'           => static fn () => self::requires_flamingo(),
                'description'        => 'Delete one Flamingo-stored submission. Default-off (opt in via the wpmcp_integration_op_enabled filter), requires confirm:true, the Flamingo plugin, and the edit_users capability. Reversible: a Flamingo entry is a flamingo_inbound post, so it is snapshotted (row, postmeta, channel terms) before deletion and can be resurrected at its original id with rollback-operation using the returned operation_id. Deletion is therefore NOT erasure: the snapshot keeps a verbatim plaintext copy of the submission (name, email, remote IP, message body) until Snapshot_Store prunes it. Restoring it is held to the same edit_users bar as deleting it (rollback-operation is otherwise an edit_posts ability), but the plaintext copy still exists, so use a real erasure tool if the goal is to destroy the data',
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
            ],
        ];
    }
}
