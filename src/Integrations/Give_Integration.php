<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GiveWP (donations) read integration (wpmcp/give-read pair).
 *
 * Give stores each donation form as a `give_forms` custom post type, with its
 * configuration and running totals in underscore-prefixed `_give_*` postmeta
 * (_give_set_price, _give_price_option, _give_donation_levels, _give_set_goal,
 * _give_form_earnings, _give_form_sales; verified against Give source). Those
 * keys are protected meta the generic get-post tool hides, so this integration
 * surfaces them through a curated, Give-aware read: list donation forms and
 * read one form's pricing, goal, and totals.
 *
 * Read-only, and forms-only: individual donation records live in Give's own
 * tables/objects (not a Safe_Mutation snapshot target), so donation-record
 * access is deferred rather than shipped un-reversible. give_forms is an
 * ordinary CPT, so form create/edit is available via the content tools.
 */
class Give_Integration extends Integration_Dispatcher
{
    public const POST_TYPE = 'give_forms';

    /** Curated Give form meta, neutral name => meta key. */
    private const FORM_META = [
        'price'           => '_give_set_price',
        'price_option'    => '_give_price_option',
        'donation_levels' => '_give_donation_levels',
        'goal_option'     => '_give_goal_option',
        'goal'            => '_give_set_goal',
        'earnings'        => '_give_form_earnings',
        'sales'           => '_give_form_sales',
    ];

    public function integration(): string
    {
        return 'give';
    }

    public function is_available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    protected function summary(): string
    {
        return 'GiveWP (donation forms, pricing, goals, and totals)';
    }

    private static function config(int $post_id): array
    {
        $out = [];
        foreach (self::FORM_META as $field => $key) {
            $out[ $field ] = get_post_meta($post_id, $key, true);
        }
        return $out;
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List GiveWP donation forms with id, title, status, and running earnings and sales counts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'    => [ 'type' => 'string' ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    // Only the rows the caller may read (issue #461).
                    $q = self::readable_query([
                        'post_type'      => self::POST_TYPE,
                        'post_status'    => isset($args['status']) ? (string) $args['status'] : 'any',
                        'posts_per_page' => (int) ($args['page_size'] ?? 20),
                        'paged'          => (int) ($args['page'] ?? 1),
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ]);
                    $forms = [];
                    foreach ($q->posts as $post) {
                        $forms[] = [
                            'id'       => (int) $post->ID,
                            'title'    => (string) $post->post_title,
                            'status'   => (string) $post->post_status,
                            'earnings' => get_post_meta($post->ID, '_give_form_earnings', true),
                            'sales'    => get_post_meta($post->ID, '_give_form_sales', true),
                        ];
                    }
                    return [ 'forms' => $forms, 'total' => (int) $q->found_posts ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one donation form: title, status, and its curated configuration (price, price option, donation levels, goal option and amount, earnings, sales)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $post = get_post((int) $args['form_id']);
                    if (! $post || self::POST_TYPE !== $post->post_type) {
                        return [ 'form' => null ];
                    }
                    return [
                        'form' => [
                            'id'     => (int) $post->ID,
                            'title'  => (string) $post->post_title,
                            'status' => (string) $post->post_status,
                            'config' => self::config((int) $post->ID),
                        ],
                    ];
                },
            ],
        ];
    }
}
