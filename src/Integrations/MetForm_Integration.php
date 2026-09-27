<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MetForm integration exposed as a wpmcp/metform-read / wpmcp/metform-write
 * dispatcher pair (issue #136 forms breadth).
 *
 * MetForm is an Elementor-based builder (verified against MetForm 4.x): forms
 * are the `metform-form` custom post type whose field layout lives in the
 * form's `_elementor_data`, and each submission is a `metform-entry` post
 * carrying `metform_entries__form_id` and a `metform_entries__form_data`
 * { field name => value } map. Both are ordinary WordPress objects, so the
 * integration reads them with core post APIs instead of touching MetForm's
 * internals.
 *
 * Because a MetForm entry IS a post, delete-entry is the one entry deletion in
 * this batch that is genuinely reversible: it declares a snapshot target of
 * ('post', entry_id), so the dispatcher captures the full entry row plus its
 * postmeta through Safe_Mutation BEFORE wp_delete_post() force-deletes it. The
 * response carries an operation_id and recoverable:true, and
 * rollback-operation resurrects the submission at its original ID with its
 * values intact. It is still mode=destructive, so confirm:true is required, it
 * is evaluated through op-granular governance under the synthetic
 * wpmcp/metform-delete-entry name, and it demands manage_options on top of the
 * pair's own capability because a submission is personal data.
 */
class MetForm_Integration extends Integration_Dispatcher
{
    private const FORM_CPT  = 'metform-form';
    private const ENTRY_CPT = 'metform-entry';

    private const META_FORM_ID = 'metform_entries__form_id';
    private const META_VALUES  = 'metform_entries__form_data';
    private const META_TOTAL   = 'metform_form__form_total_entries';

    public function integration(): string
    {
        return 'metform';
    }

    public function is_available(): bool
    {
        return defined('METFORM_VERSION') || post_type_exists(self::FORM_CPT);
    }

    protected function summary(): string
    {
        return 'MetForm (forms, their Elementor field definitions, and entries)';
    }

    /** The shortcode that embeds a MetForm form on a page. */
    private static function shortcode(int $form_id): string
    {
        return sprintf('[metform form_id="%d"]', $form_id);
    }

    /** The { field name => value } map stored on an entry post. */
    private static function values(int $entry_id): array
    {
        $data = get_post_meta($entry_id, self::META_VALUES, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Walk a decoded _elementor_data tree and collect every MetForm field
     * widget. MetForm marks its inputs with settings.mf_input_name, and the
     * widgetType is the field type prefixed with "mf-". Recursion is needed
     * because Elementor nests widgets inside sections and columns.
     */
    private static function collect_fields(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            if (! empty($settings['mf_input_name'])) {
                $widget = (string) ($node['widgetType'] ?? '');
                $out[]  = [
                    'name'     => (string) $settings['mf_input_name'],
                    'label'    => (string) ($settings['mf_input_label'] ?? ''),
                    'type'     => (string) ($settings['mf_input_type'] ?? preg_replace('/^mf-/', '', $widget)),
                    'required' => in_array($settings['mf_input_required'] ?? '', [ 'yes', 'true', '1', 1, true ], true),
                ];
            }
            if (! empty($node['elements']) && is_array($node['elements'])) {
                self::collect_fields($node['elements'], $out);
            }
        }
    }

    /** Entry ids belonging to a form, newest first, honouring paging. */
    private static function entry_query(int $form_id, int $page_size, int $offset): \WP_Query
    {
        return new \WP_Query([
            'post_type'      => self::ENTRY_CPT,
            'post_status'    => 'any',
            'posts_per_page' => $page_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single-key equality lookup on the entry CPT, bounded by posts_per_page paging.
            'meta_query'     => [
                [ 'key' => self::META_FORM_ID, 'value' => $form_id ],
            ],
        ]);
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List MetForm forms with id, title, status, entry count, and the shortcode that embeds each form',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $query = new \WP_Query([
                        'post_type'      => self::FORM_CPT,
                        'post_status'    => 'any',
                        'posts_per_page' => (int) ($args['page_size'] ?? 20),
                        'paged'          => (int) ($args['page'] ?? 1),
                        'orderby'        => 'ID',
                        'order'          => 'DESC',
                    ]);
                    $forms = [];
                    foreach ($query->posts as $post) {
                        $id    = (int) $post->ID;
                        $total = get_post_meta($id, self::META_TOTAL, true);
                        $forms[] = [
                            'id'          => $id,
                            'title'       => (string) $post->post_title,
                            'status'      => (string) $post->post_status,
                            'entry_count' => '' !== $total && null !== $total
                                ? (int) $total
                                : (int) self::entry_query($id, 1, 0)->found_posts,
                            'shortcode'   => self::shortcode($id),
                        ];
                    }
                    return [ 'forms' => $forms, 'total' => (int) $query->found_posts ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one MetForm form: title, embed shortcode, and its fields (name, label, type, required) parsed from the form\'s Elementor layout',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $id   = (int) $args['form_id'];
                    $post = get_post($id);
                    if (! $post || self::FORM_CPT !== $post->post_type) {
                        return [ 'form' => null ];
                    }
                    $data = get_post_meta($id, '_elementor_data', true);
                    $tree = is_string($data) ? json_decode($data, true) : $data;
                    $fields = [];
                    self::collect_fields(is_array($tree) ? $tree : [], $fields);
                    return [ 'form' => [
                        'id'        => $id,
                        'title'     => (string) $post->post_title,
                        'status'    => (string) $post->post_status,
                        'shortcode' => self::shortcode($id),
                        'fields'    => $fields,
                    ] ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'description'  => 'List a MetForm form\'s entries, newest first, with paging (page_size default 20, max 100) and their stored field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id   = (int) $args['form_id'];
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $offset    = (int) ($args['offset'] ?? 0);
                    $query     = self::entry_query($form_id, $page_size, $offset);
                    $entries   = [];
                    foreach ($query->posts as $post) {
                        $entries[] = [
                            'id'         => (int) $post->ID,
                            'form_id'    => $form_id,
                            'created_at' => (string) $post->post_date,
                            'values'     => self::values((int) $post->ID),
                        ];
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $entries,
                        'total'   => (int) $query->found_posts,
                        'paging'  => [ 'offset' => $offset, 'page_size' => $page_size ],
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read one MetForm entry by entry_id, with its source form, timestamp, and stored field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $id   = (int) $args['entry_id'];
                    $post = get_post($id);
                    if (! $post || self::ENTRY_CPT !== $post->post_type) {
                        return [ 'entry' => null ];
                    }
                    return [ 'entry' => [
                        'id'         => $id,
                        'form_id'    => (int) get_post_meta($id, self::META_FORM_ID, true),
                        'created_at' => (string) $post->post_date,
                        'values'     => self::values($id),
                    ] ];
                },
            ],
            'delete-entry' => [
                'mode'         => 'destructive',
                'capability'   => 'manage_options',
                'description'  => 'Delete one MetForm entry. Requires confirm:true. Reversible: a MetForm entry is a metform-entry post, so it is snapshotted (row plus postmeta) before deletion and can be resurrected at its original id with rollback-operation using the returned operation_id',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $id   = (int) $args['entry_id'];
                    $post = get_post($id);
                    if (! $post || self::ENTRY_CPT !== $post->post_type) {
                        return [ 'entry_id' => $id, 'deleted' => false, 'reason' => 'not_found' ];
                    }
                    return [
                        'entry_id' => $id,
                        'deleted'  => (bool) wp_delete_post($id, true),
                    ];
                },
                'snapshot'     => fn (array $args) => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['entry_id'],
                ],
            ],
        ];
    }
}
