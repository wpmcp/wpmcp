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
 * manage_options capability on top of the pair's own capability, matching
 * Flamingo, which maps all of its inbound-message caps to edit_users, i.e.
 * administrators only. Deletion is destructive (confirm:true), default-off
 * until the site opts in via wpmcp_integration_op_enabled, and reversible: a
 * Flamingo entry is an ordinary flamingo_inbound post, so it is snapshotted
 * (row, meta, terms) before deletion and can be resurrected at its original id
 * with rollback-operation, exactly like the MetForm adapter's entry delete.
 */
class Contact_Form_7_Integration extends Integration_Dispatcher
{
    /**
     * Extra capability guarding submission (PII) operations. Flamingo maps
     * flamingo_edit_inbound_messages / flamingo_delete_inbound_messages to
     * edit_users, so entry access is administrator-only in the host plugin;
     * manage_options is the same administrator-only bar and is what the
     * Forminator, MetForm, and SureForms adapters use for entry deletion.
     */
    private const ENTRY_CAPABILITY = 'manage_options';

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

    /** Whether Flamingo (CF7's own submission store) is active. */
    private static function has_flamingo(): bool
    {
        return class_exists('Flamingo_Inbound_Message');
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
     * listing to exactly that form. CF7 binds a form to its channel by TERM ID
     * in the form's _flamingo post meta, and the channel slug can diverge from
     * the form slug (wp_insert_term dedupe suffix, or the contact-form-7
     * fallback), so the term id is authoritative and the slug is only a
     * fallback. Returns null when the scope cannot be resolved: the caller
     * must then answer empty rather than run an unscoped query, which would
     * silently return every form's submissions.
     */
    private static function channel_query(int $form_id): ?array
    {
        $form = \WPCF7_ContactForm::get_instance($form_id);
        if (! $form instanceof \WPCF7_ContactForm) {
            return null;
        }
        $meta       = get_post_meta($form_id, self::CHANNEL_META, true);
        $channel_id = is_array($meta) ? (int) ($meta['channel'] ?? 0) : 0;
        if ($channel_id > 0) {
            return [ 'channel_id' => $channel_id ];
        }
        $slug = (string) $form->name();
        return '' === $slug ? null : [ 'channel' => $slug ];
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
                'description'  => 'List Flamingo-stored submissions, newest first, with paging (page_size default 20, max 100) and a status filter (inbox, the default, plus spam and trash, which Flamingo keeps out of the default listing). Optionally scoped to one form via form_id, resolved through the form\'s Flamingo channel term. Requires the Flamingo plugin and the manage_options capability because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'inbox', 'spam', 'trash' ] ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $page      = max(1, (int) ($args['page'] ?? 1));
                    $status    = (string) ($args['status'] ?? 'inbox');

                    // Flamingo's find() injects offset => 0 by default, and
                    // WP_Query prefers a numeric offset over paged when it
                    // builds the LIMIT clause, so paging MUST go through
                    // offset or every page returns the first one. post_status
                    // is passed explicitly because find() defaults to 'any'
                    // while count() defaults to 'publish', which would make
                    // the total disagree with the page.
                    $query = [
                        'posts_per_page' => $page_size,
                        'offset'         => ($page - 1) * $page_size,
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                        'post_status'    => self::STATUSES[ $status ] ?? self::STATUSES['inbox'],
                    ];

                    if (! empty($args['form_id'])) {
                        $scope = self::channel_query((int) $args['form_id']);
                        if (null === $scope) {
                            // Never fall through to an unscoped query: that
                            // would hand back every form's submissions to a
                            // caller who explicitly asked for one form.
                            return [ 'entries' => [], 'total' => 0, 'reason' => 'unresolved_form_channel' ];
                        }
                        $query += $scope;
                    }

                    $messages = (array) \Flamingo_Inbound_Message::find($query);
                    $out      = [];
                    foreach ($messages as $msg) {
                        if ($msg instanceof \Flamingo_Inbound_Message) {
                            $out[] = self::shape_entry($msg, false);
                        }
                    }

                    return [
                        'entries' => $out,
                        'total'   => (int) \Flamingo_Inbound_Message::count($query),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'requires'     => static fn () => self::requires_flamingo(),
                'description'  => 'Read one Flamingo-stored submission in full: subject, sender, channel, submitted fields, and meta. Requires the Flamingo plugin and the manage_options capability because submissions are user data',
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
                'description'        => 'Delete one Flamingo-stored submission. Default-off (opt in via the wpmcp_integration_op_enabled filter), requires confirm:true, the Flamingo plugin, and the manage_options capability. Reversible: a Flamingo entry is a flamingo_inbound post, so it is snapshotted (row, postmeta, channel terms) before deletion and can be resurrected at its original id with rollback-operation using the returned operation_id',
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
                'snapshot'           => fn (array $args) => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['entry_id'],
                ],
            ],
        ];
    }
}
