<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ninja Forms integration (wpmcp/ninjaforms-read / -write pair), part of the
 * forms adapter pack (issue #66, pro tier). Delegates to Ninja Forms' own
 * model factory, Ninja_Forms()->form() (verified against Ninja Forms 3.x):
 * get_forms(), get_form(), get_fields(), get_actions() and get_sub().
 *
 * Ninja Forms stores each submission as an nf_sub post whose _form_id meta
 * names its form. The factory's own get_subs() loads every submission of a
 * form with no paging, so list-entries pages the nf_sub posts through
 * WP_Query on exactly that documented storage and hands each id back to
 * get_sub() for the values, instead of pulling a whole form's history into
 * memory to return twenty rows.
 *
 * Email notifications are Ninja Forms "email" actions. Entries are user data,
 * so the entry operations sit behind manage_options on top of the pair's own
 * capability. update-entry-status moves a submission between active and the
 * trash, the only two states Ninja Forms gives one; because a submission is a
 * post, the write is snapshotted (row, meta, terms) and restorable with
 * rollback-operation. Entry deletion is deliberately not offered.
 */
class Ninja_Forms_Integration extends Forms_Integration
{
    /** Ninja Forms' submission post type. */
    private const SUB_POST_TYPE = 'nf_sub';

    /** status arg => nf_sub post_status. */
    private const STATUSES = [
        'active' => 'publish',
        'trash'  => 'trash',
    ];

    public function integration(): string
    {
        return 'ninjaforms';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return function_exists('Ninja_Forms');
    }

    protected function summary(): string
    {
        return 'Ninja Forms (forms, fields, notifications, submissions)';
    }

    /** The form model, or null when Ninja Forms has no such form. */
    private static function form_model(int $form_id)
    {
        $model = Ninja_Forms()->form($form_id)->get_form();
        return $model ? $model : null;
    }

    /** @return \WP_Post|null the nf_sub post, or null for any other id. */
    private static function sub_post(int $entry_id): ?\WP_Post
    {
        $post = get_post($entry_id);
        return $post instanceof \WP_Post && self::SUB_POST_TYPE === $post->post_type ? $post : null;
    }

    private static function status_name(string $post_status): string
    {
        return 'trash' === $post_status ? 'trash' : 'active';
    }

    private static function shape_sub(int $form_id, int $sub_id, bool $with_values): array
    {
        $sub = Ninja_Forms()->form($form_id)->get_sub($sub_id);
        $out = [
            'id'      => (int) $sub->get_id(),
            'form_id' => (int) $sub->get_form_id(),
            'seq_num' => (int) $sub->get_seq_num(),
            'status'  => self::status_name((string) $sub->get_status()),
            'date'    => (string) $sub->get_sub_date('Y-m-d H:i:s'),
        ];
        if ($with_values) {
            $values        = $sub->get_field_values();
            $out['values'] = is_array($values) ? $values : [];
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
                'description'  => 'List Ninja Forms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $out = [];
                    foreach ((array) Ninja_Forms()->form()->get_forms() as $form) {
                        $out[] = [
                            'id'    => (int) $form->get_id(),
                            'title' => (string) $form->get_setting('title'),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Ninja Forms form with its field definitions (id, type, label)',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form  = Ninja_Forms()->form((int) $args['form_id']);
                    $model = $form->get_form();
                    if (! $model) {
                        return [ 'form' => null ];
                    }
                    $fields = [];
                    foreach ((array) $form->get_fields() as $field) {
                        $fields[] = [
                            'id'    => (int) $field->get_id(),
                            'type'  => (string) $field->get_setting('type'),
                            'label' => (string) $field->get_setting('label'),
                        ];
                    }
                    return [ 'form' => [
                        'id'     => (int) $model->get_id(),
                        'title'  => (string) $model->get_setting('title'),
                        'fields' => $fields,
                    ] ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: id, key, type, label, and whether it is required',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (null === self::form_model($form_id)) {
                        return [ 'form_id' => $form_id, 'fields' => null ];
                    }
                    $fields = [];
                    foreach ((array) Ninja_Forms()->form($form_id)->get_fields() as $field) {
                        $fields[] = [
                            'id'       => (int) $field->get_id(),
                            'key'      => (string) $field->get_setting('key'),
                            'type'     => (string) $field->get_setting('type'),
                            'label'    => (string) $field->get_setting('label'),
                            'required' => ! empty($field->get_setting('required')),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications (Ninja Forms "email" actions): id, label, whether it is active, recipient, subject, and message template',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (null === self::form_model($form_id)) {
                        return [ 'form_id' => $form_id, 'notifications' => null ];
                    }
                    $out = [];
                    foreach ((array) Ninja_Forms()->form($form_id)->get_actions() as $action) {
                        if ('email' !== (string) $action->get_setting('type')) {
                            continue;
                        }
                        $active = $action->get_setting('active');
                        $out[]  = [
                            'id'      => (int) $action->get_id(),
                            'label'   => (string) $action->get_setting('label'),
                            // Ninja Forms stores active as "1"/"0" or a bool,
                            // and treats an unset flag as on.
                            'active'  => '' === $active || null === $active || ! in_array($active, [ false, 0, '0' ], true),
                            'to'      => (string) $action->get_setting('to'),
                            'subject' => (string) $action->get_setting('email_subject'),
                            'message' => (string) $action->get_setting('email_message'),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a form\'s submissions, newest first, with paging (page_size default 20, max 100, plus offset) and an optional status filter (active, the default, or trash). Requires manage_options because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'  => [ 'type' => 'string', 'enum' => array_keys(self::STATUSES) ],
                    ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id                = (int) $args['form_id'];
                    [ $page_size, $offset ] = self::page_window($args);
                    $query                  = new \WP_Query([
                        'post_type'      => self::SUB_POST_TYPE,
                        'post_status'    => self::STATUSES[ (string) ($args['status'] ?? 'active') ],
                        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Ninja Forms' own storage contract: a submission's form is its _form_id meta. Single-key equality, bounded by posts_per_page paging.
                        'meta_query'     => [ [ 'key' => '_form_id', 'value' => $form_id ] ],
                        'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
                        'posts_per_page' => $page_size,
                        'offset'         => $offset,
                        'fields'         => 'ids',
                    ]);
                    $out = [];
                    foreach ($query->posts as $sub_id) {
                        $out[] = self::shape_sub($form_id, (int) $sub_id, false);
                    }
                    return [ 'form_id' => $form_id, 'entries' => $out, 'total' => (int) $query->found_posts ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Read one submission in full: its form, sequence number, status, date, and submitted field values. Requires manage_options because submissions are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $post = self::sub_post((int) $args['entry_id']);
                    if (null === $post) {
                        return [ 'entry' => null ];
                    }
                    $form_id = (int) get_post_meta($post->ID, '_form_id', true);
                    return [ 'entry' => self::shape_sub($form_id, (int) $post->ID, true) ];
                },
            ],
            'update-entry-status' => [
                'mode'         => 'write',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Move one submission between active and the trash. Snapshotted first (a submission is a post) and restorable with rollback-operation. Refused when the site has the trash disabled, because trashing would then be permanent deletion. Requires manage_options',
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
                    $post     = self::sub_post($entry_id);
                    if (null === $post) {
                        throw new Operation_Error('entry_not_found', 'Ninja Forms has no submission with that id.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    $previous = self::status_name($post->post_status);
                    $status   = (string) $args['status'];
                    if ($previous === $status) {
                        return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => false ];
                    }
                    if ('trash' === $status) {
                        if (! EMPTY_TRASH_DAYS) {
                            throw new Operation_Error('trash_disabled', 'This site has the trash disabled (EMPTY_TRASH_DAYS is 0), so trashing would permanently delete the submission.', [ 'entry_id' => (int) $args['entry_id'] ]);
                        }
                        $done = Save_Filters::trash_post($entry_id);
                    } else {
                        // Put the submission back in the state it was trashed
                        // from rather than core's default of draft, which
                        // Ninja Forms' submissions screen would not list.
                        $restore = static fn () => 'publish';
                        add_filter('wp_untrash_post_status', $restore);
                        try {
                            $done = Save_Filters::untrash_post($entry_id);
                        } finally {
                            remove_filter('wp_untrash_post_status', $restore);
                        }
                    }
                    if (! $done) {
                        throw new Operation_Error('update_failed', 'The submission\'s status did not change.', [ 'entry_id' => (int) $args['entry_id'] ]);
                    }
                    return [ 'entry_id' => $entry_id, 'status' => $status, 'previous_status' => $previous, 'changed' => true ];
                },
                'snapshot'     => static function (array $args): ?array {
                    $entry_id = (int) $args['entry_id'];
                    return null === self::sub_post($entry_id) ? null : [ 'object_type' => 'post', 'object_id' => $entry_id ];
                },
            ],
        ];
    }
}
