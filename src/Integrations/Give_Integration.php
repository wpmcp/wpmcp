<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

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
 * Issue #680 adds donations, donors, campaigns and a summary (reads, every
 * one behind manage_options, lists masked), plus update-form and
 * update-donation-status (Give_Donations). Writes are off until the site
 * opts in with the wpmcp_enable_give_write filter.
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

    public const OPT_IN_FILTER = 'wpmcp_enable_give_write';

    /** The capability every donation, donor and campaign op takes. */
    private const DATA_CAPABILITY = 'manage_options';

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
        return 'GiveWP (donation forms, donations, donors, campaigns)';
    }

    public function host_plugin(): array
    {
        return [ 'name' => 'GiveWP', 'several' => false ];
    }

    private function form_read(int $id): array
    {
        $post = get_post($id);
        return [
            'form' => [
                'id'     => (int) $post->ID,
                'title'  => (string) $post->post_title,
                'status' => (string) $post->post_status,
                'config' => self::config((int) $post->ID),
            ],
        ];
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
                    $q = Readable_Posts::query([
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
                    return $this->form_read((int) $post->ID);
                },
            ],
        ] + $this->donation_operations();
    }

    /** Issue #680: donations, donors, campaigns, the summary and the two writes. */
    private function donation_operations(): array
    {
        // The literal name, not OPT_IN_FILTER, so the hook stays greppable.
        $enabled  = (bool) apply_filters('wpmcp_enable_give_write', false);
        $paging   = Give_Donations::paging();
        $date     = Give_Donations::date_schema();
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $statuses = array_keys(Give_Donations::STATUSES);
        $filters  = [
            'form_id'     => $id,
            'campaign_id' => $id,
            'mode'        => [ 'type' => 'string', 'enum' => [ 'live', 'test' ] ],
            'from'        => $date,
            'to'          => $date,
        ];
        $data     = [ 'mode' => 'read', 'capability' => self::DATA_CAPABILITY ];
        $write    = [ 'enabled_by_default' => $enabled, 'opt_in_filter' => self::OPT_IN_FILTER ];
        $form_obj = [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ];

        return [
            'list-donations'         => $data + [
                'description'  => 'List GiveWP donations, newest first: id, status, date, amount, currency, form, campaign, donor id, gateway, mode; donor name and email MASKED. Filter by status, form_id, donor_id, campaign_id, mode (live or test), from and to (YYYY-MM-DD)',
                'objects'      => $form_obj,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => $statuses ],
                        'donor_id' => $id,
                    ] + $filters + $paging,
                ],
                'handler'      => static fn (array $args): array => Give_Donations::list_donations($args),
            ],
            'get-donation'           => $data + [
                'description'  => 'Read one GiveWP donation in full: amount, status, form, campaign, donor name, email, phone, company, billing address, comment, anonymous flag, gateway, transaction id and mode',
                'objects'      => [ 'donation_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'donation_id' => $id ], 'required' => [ 'donation_id' ] ],
                'validate'     => static fn (array $args): ?array => Give_Donations::donation_refusal((int) $args['donation_id']),
                'handler'      => static fn (array $args): array => Give_Donations::get_donation((int) $args['donation_id']),
            ],
            'list-donors'            => $data + [
                'description'  => 'List GiveWP donors, newest first: id, user id, total donated, donation count, created date; name and email MASKED. search matches name or email',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string', 'maxLength' => 200 ] ] + $paging ],
                'validate'     => static fn (): ?array => Give_Donations::donors_refusal(),
                'handler'      => static fn (array $args): array => Give_Donations::list_donors($args),
            ],
            'get-donor'              => $data + [
                'description'  => 'Read one GiveWP donor in full: name, email, additional emails, first and last name, company, totals and the 10 latest donations',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'donor_id' => $id ], 'required' => [ 'donor_id' ] ],
                'validate'     => static fn (array $args): ?array => Give_Donations::donor_refusal((int) $args['donor_id']),
                'handler'      => static fn (array $args): array => Give_Donations::get_donor((int) $args['donor_id']),
            ],
            'list-campaigns'         => $data + [
                'description'  => 'List GiveWP campaigns (GiveWP 4+): title, status, goal and goal type, dates, page, default form and every form, and raised amount and count from completed live donations. Filter by status',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'maxLength' => 20 ] ] + $paging ],
                'handler'      => static fn (array $args): array => Give_Donations::list_campaigns($args),
            ],
            'donation-summary'       => $data + [
                'description'  => 'Count and total GiveWP donations by status and currency (trash left out). Filter by form_id, campaign_id, mode (live or test), from and to (YYYY-MM-DD, site time)',
                'objects'      => $form_obj,
                'input_schema' => [ 'type' => 'object', 'properties' => $filters ],
                'handler'      => static fn (array $args): array => Give_Donations::summary($args),
            ],
            'update-form'            => $write + [
                'mode'         => 'write',
                'description'  => 'Change a GiveWP donation form: title, status (publish, draft, pending, private), price (the set amount, single-amount forms only), goal_enabled, goal. Visual form builder forms take status only. Snapshotted; undo: rollback-operation',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'form_id'      => $id,
                        'title'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'status'       => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ] ],
                        'price'        => [ 'type' => 'number', 'minimum' => 0 ],
                        'goal_enabled' => [ 'type' => 'boolean' ],
                        'goal'         => [ 'type' => 'number', 'minimum' => 0 ],
                    ],
                    'required'      => [ 'form_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => Give_Donations::update_form_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['form_id'] ],
                'handler'      => fn (array $args): array => Give_Donations::update_form($args, fn (int $form): array => $this->form_read($form)),
            ],
            'update-donation-status' => $write + [
                'mode'         => 'destructive',
                'capability'   => self::DATA_CAPABILITY,
                'undoable'     => false,
                'description'  => 'Change a GiveWP donation\'s status (complete, pending, processing, refunded, failed, cancelled, abandoned, revoked, preapproval) through GiveWP, which recalculates totals and may email the donor; no gateway refund is made. Needs confirm:true; the record is snapshotted but the emails and totals are not undoable',
                'objects'      => [ 'donation_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'donation_id' => $id,
                        'status'      => [ 'type' => 'string', 'enum' => Give_Donations::WRITABLE_STATUSES ],
                    ],
                    'required'   => [ 'donation_id', 'status' ],
                ],
                'validate'     => static fn (array $args): ?array => Give_Donations::status_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['donation_id'] ],
                'handler'      => static fn (array $args): array => Give_Donations::update_status($args),
            ],
        ];
    }
}
