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
 * entry operations delegate to Flamingo's Flamingo_Inbound_Message model
 * (verified against Flamingo 2.x) and answer with a structured
 * flamingo_unavailable error when Flamingo is not active. Entries are user
 * data (issue #66): reads sit behind an extra edit_others_posts capability on
 * top of the pair's own capability, deletion is destructive (confirm:true),
 * default-off until the site opts in via wpmcp_integration_op_enabled, and
 * honestly flagged recoverable:false because a hard-deleted inbound message
 * cannot be snapshotted back through rollback-operation.
 */
class Contact_Form_7_Integration extends Integration_Dispatcher
{
    /** Extra capability guarding submission (PII) operations. */
    private const ENTRY_CAPABILITY = 'edit_others_posts';

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

    private function flamingo_unavailable(): array
    {
        return [
            'error' => [
                'code'    => 'flamingo_unavailable',
                'message' => 'Contact Form 7 does not store submissions itself; entry operations need the Flamingo plugin, which is not active on this site.',
                'data'    => [],
            ],
        ];
    }

    /**
     * Shape one Flamingo inbound message. Flamingo 1.8+ exposes id() as a
     * method; the remaining fields are public properties on every 2.x
     * release the integration targets.
     *
     * @param object $msg Flamingo_Inbound_Message instance.
     */
    private static function shape_entry($msg, bool $with_fields): array
    {
        $out = [
            'id'         => (int) (method_exists($msg, 'id') ? $msg->id() : ($msg->id ?? 0)),
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
                'description'  => 'List Flamingo-stored submissions, newest first, with paging (page_size default 20, max 100); optionally scoped to one form via form_id (matched to the Flamingo channel by form slug). Requires the Flamingo plugin and the edit_others_posts capability because submissions are user data',
                'capability'   => self::ENTRY_CAPABILITY,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    if (! self::has_flamingo()) {
                        return $this->flamingo_unavailable();
                    }
                    $query = [
                        'posts_per_page' => (int) ($args['page_size'] ?? 20),
                        'paged'          => (int) ($args['page'] ?? 1),
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ];
                    if (! empty($args['form_id'])) {
                        $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                        if (! $form instanceof \WPCF7_ContactForm) {
                            return [ 'entries' => [], 'total' => 0 ];
                        }
                        $query['channel'] = (string) $form->name();
                    }
                    $messages = (array) \Flamingo_Inbound_Message::find($query);
                    $out      = [];
                    foreach ($messages as $msg) {
                        if ($msg instanceof \Flamingo_Inbound_Message) {
                            $out[] = self::shape_entry($msg, false);
                        }
                    }
                    $total = property_exists('Flamingo_Inbound_Message', 'found_items')
                        ? (int) \Flamingo_Inbound_Message::$found_items
                        : count($out);
                    return [ 'entries' => $out, 'total' => $total ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read one Flamingo-stored submission in full: subject, sender, channel, submitted fields, and meta. Requires the Flamingo plugin and the edit_others_posts capability because submissions are user data',
                'capability'   => self::ENTRY_CAPABILITY,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    if (! self::has_flamingo()) {
                        return $this->flamingo_unavailable();
                    }
                    $msg = \Flamingo_Inbound_Message::get_instance((int) $args['entry_id']);
                    if (! $msg instanceof \Flamingo_Inbound_Message) {
                        return [ 'entry' => null ];
                    }
                    return [ 'entry' => self::shape_entry($msg, true) ];
                },
            ],
            'delete-entry' => [
                'mode'               => 'destructive',
                'enabled_by_default' => false,
                'capability'         => self::ENTRY_CAPABILITY,
                'description'        => 'Permanently delete one Flamingo-stored submission. Default-off (opt in via the wpmcp_integration_op_enabled filter), requires confirm:true and the edit_others_posts capability, and is NOT reversible: the message is hard-deleted, so the response carries recoverable:false and rollback-operation cannot bring it back',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'            => function (array $args): array {
                    if (! self::has_flamingo()) {
                        return $this->flamingo_unavailable();
                    }
                    $entry_id = (int) $args['entry_id'];
                    $post     = get_post($entry_id);
                    if (! $post instanceof \WP_Post || 'flamingo_inbound' !== $post->post_type) {
                        return [ 'deleted' => false, 'entry_id' => $entry_id ];
                    }
                    $deleted = wp_delete_post($entry_id, true);
                    return [ 'deleted' => (bool) $deleted, 'entry_id' => $entry_id ];
                },
            ],
        ];
    }
}
