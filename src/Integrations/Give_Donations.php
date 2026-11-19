<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GiveWP donations, donors, campaigns and form writes on the give pair
 * (issue #680). Give_Integration wires these into its operation catalog.
 *
 * Storage, verified against GiveWP 4.19 (wordpress.org):
 *  - a donation is a give_payment post; its status is the post status
 *    (publish is "complete") and its details are _give_payment_* and
 *    _give_donor_billing_* meta. GiveWP keeps that meta in its own
 *    give_donationmeta table and routes the post meta API there through
 *    the get/update_post_meta filters, so the meta API reads it either way;
 *    the one aggregate query below picks the table $wpdb->donationmeta
 *    names (wp_postmeta when GiveWP has not set it);
 *  - donors are rows of {prefix}give_donors, with first and last name,
 *    company and additional emails in {prefix}give_donormeta;
 *  - campaigns (GiveWP 4) are rows of {prefix}give_campaigns, their forms
 *    listed in {prefix}give_campaign_forms, and each donation names its
 *    campaign in _give_campaign_id.
 *
 * Privacy: list reads mask every donor name and email through
 * Masking (masked:true); a single donation or donor read returns them in
 * full, and every donation, donor and campaign read takes manage_options.
 * The purchase key, donor IP, donor token and verify key are never returned.
 *
 * Writes: update-form edits a donation form's title, status, set amount and
 * goal, snapshotted as the form post (meta included), so rollback-operation
 * puts it back exactly. update-donation-status changes a donation's status
 * through GiveWP's own give_update_payment_status(), so its totals, donor
 * stats and emails follow; because those emails and recalculated totals
 * cannot be taken back, it needs confirm:true and reports recoverable:false,
 * although the donation record itself is snapshotted first.
 */
final class Give_Donations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads GiveWP's own donor and campaign tables live; it has no cached read API for them.

    public const DONATION_TYPE = 'give_payment';

    public const FORM_TYPE = 'give_forms';

    /** Neutral status name => GiveWP's post status. */
    public const STATUSES = [
        'complete'    => 'publish',
        'pending'     => 'pending',
        'processing'  => 'processing',
        'refunded'    => 'refunded',
        'failed'      => 'failed',
        'cancelled'   => 'cancelled',
        'abandoned'   => 'abandoned',
        'revoked'     => 'revoked',
        'preapproval' => 'preapproval',
        'renewal'     => 'give_subscription',
    ];

    /** Statuses a status change may set: every one but a recurring renewal, which GiveWP Recurring owns. */
    public const WRITABLE_STATUSES = [ 'complete', 'pending', 'processing', 'refunded', 'failed', 'cancelled', 'abandoned', 'revoked', 'preapproval' ];

    private const MAX_PAGE_SIZE = 100;

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function neutral_status(string $status): string
    {
        $found = array_search($status, self::STATUSES, true);
        return false === $found ? $status : (string) $found;
    }

    private static function meta(int $id, string $key): string
    {
        return (string) get_post_meta($id, $key, true);
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $columns  = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }

    /** The donation meta table and its id column: GiveWP's own, or wp_postmeta. */
    private static function donation_meta_table(): array
    {
        global $wpdb;
        $table = isset($wpdb->donationmeta) && is_string($wpdb->donationmeta) ? $wpdb->donationmeta : '';
        if ('' === $table) {
            return [ $wpdb->postmeta, 'post_id' ];
        }
        return [ $table, str_ends_with($table, 'paymentmeta') ? 'payment_id' : 'donation_id' ];
    }

    private static function page_args(array $args): array
    {
        $size = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        return [ $size, $page ];
    }

    /** JSON schema fragments the ops share. */
    public static function paging(): array
    {
        return [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
        ];
    }

    public static function date_schema(): array
    {
        return [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
    }

    // ------------------------------------------------------------------
    // Donations
    // ------------------------------------------------------------------

    public static function donation_refusal(int $id): ?array
    {
        $post = get_post($id);
        if ($post && self::DONATION_TYPE === $post->post_type) {
            return null;
        }
        return self::refusal('donation_not_found', sprintf('GiveWP has no donation %d.', $id), [ 'donation_id' => $id ]);
    }

    /** One donation, list shape (donor masked) or full. */
    private static function donation_shape(\WP_Post $post, bool $full): array
    {
        $id    = (int) $post->ID;
        $first = self::meta($id, '_give_donor_billing_first_name');
        $last  = self::meta($id, '_give_donor_billing_last_name');
        $name  = trim($first . ' ' . $last);
        $email = self::meta($id, '_give_payment_donor_email');
        $row   = [
            'id'          => $id,
            'status'      => self::neutral_status((string) $post->post_status),
            'date'        => (string) $post->post_date,
            'date_gmt'    => (string) $post->post_date_gmt,
            'amount'      => self::meta($id, '_give_payment_total'),
            'currency'    => self::meta($id, '_give_payment_currency'),
            'form_id'     => (int) self::meta($id, '_give_payment_form_id'),
            'form_title'  => self::meta($id, '_give_payment_form_title'),
            'campaign_id' => (int) self::meta($id, '_give_campaign_id'),
            'donor_id'    => (int) self::meta($id, '_give_payment_donor_id'),
            'donor_name'  => $full ? $name : Masking::value($name),
            'donor_email' => $full ? $email : Masking::email($email),
            'gateway'     => self::meta($id, '_give_payment_gateway'),
            'mode'        => self::meta($id, '_give_payment_mode'),
        ];
        if (! $full) {
            return $row;
        }
        return $row + [
            'first_name'     => $first,
            'last_name'      => $last,
            'company'        => self::meta($id, '_give_donation_company'),
            'phone'          => self::meta($id, '_give_payment_donor_phone'),
            'comment'        => self::meta($id, '_give_donation_comment'),
            'anonymous'      => '1' === self::meta($id, '_give_anonymous_donation'),
            'transaction_id' => self::meta($id, '_give_payment_transaction_id'),
            'billing'        => [
                'address1' => self::meta($id, '_give_donor_billing_address1'),
                'address2' => self::meta($id, '_give_donor_billing_address2'),
                'city'     => self::meta($id, '_give_donor_billing_city'),
                'state'    => self::meta($id, '_give_donor_billing_state'),
                'zip'      => self::meta($id, '_give_donor_billing_zip'),
                'country'  => self::meta($id, '_give_donor_billing_country'),
            ],
        ];
    }

    /** @return array{0: \WP_Post[], 1: int} */
    private static function query_donations(array $args, int $size, int $page): array
    {
        $meta = [];
        foreach ([ 'form_id' => '_give_payment_form_id', 'donor_id' => '_give_payment_donor_id', 'campaign_id' => '_give_campaign_id' ] as $arg => $key) {
            if (! empty($args[ $arg ])) {
                $meta[] = [ 'key' => $key, 'value' => (string) (int) $args[ $arg ] ];
            }
        }
        if (! empty($args['mode'])) {
            $meta[] = [ 'key' => '_give_payment_mode', 'value' => (string) $args['mode'] ];
        }
        $date = [];
        if (! empty($args['from'])) {
            $date['after'] = (string) $args['from'] . ' 00:00:00';
        }
        if (! empty($args['to'])) {
            $date['before'] = (string) $args['to'] . ' 23:59:59';
        }
        $query = [
            'post_type'           => self::DONATION_TYPE,
            'post_status'         => isset($args['status']) ? [ self::STATUSES[ (string) $args['status'] ] ] : array_values(self::STATUSES),
            'posts_per_page'      => $size,
            'paged'               => $page,
            'orderby'             => 'date ID',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
        ];
        if ([] !== $meta) {
            $query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering donations by form, donor or campaign, as GiveWP's own donations screen does.
        }
        if ([] !== $date) {
            $query['date_query'] = [ $date + [ 'inclusive' => true ] ];
        }
        $q = new \WP_Query($query);
        return [ $q->posts, (int) $q->found_posts ];
    }

    public static function list_donations(array $args): array
    {
        [$size, $page]    = self::page_args($args);
        [$posts, $total]  = self::query_donations($args, $size, $page);
        return [
            'donations' => array_map(static fn (\WP_Post $p): array => self::donation_shape($p, false), $posts),
            'total'     => $total,
            'page'      => $page,
            'page_size' => $size,
            'masked'    => true,
        ];
    }

    public static function get_donation(int $id): array
    {
        return [ 'donation' => self::donation_shape(get_post($id), true) ];
    }

    /**
     * Donation counts and amounts by status and currency, trash left out.
     * Optional from/to (YYYY-MM-DD, site time), form_id, campaign_id, mode.
     */
    public static function summary(array $args): array
    {
        global $wpdb;
        [$meta, $col] = self::donation_meta_table();

        $joins  = 'LEFT JOIN %i a ON a.%i = p.ID AND a.meta_key = %s LEFT JOIN %i c ON c.%i = p.ID AND c.meta_key = %s';
        $params = [ $wpdb->posts, $meta, $col, '_give_payment_total', $meta, $col, '_give_payment_currency' ];
        $where  = 'p.post_type = %s AND p.post_status <> %s';
        $wparam = [ self::DONATION_TYPE, 'trash' ];
        $n      = 0;
        foreach ([ 'form_id' => '_give_payment_form_id', 'campaign_id' => '_give_campaign_id', 'mode' => '_give_payment_mode' ] as $arg => $key) {
            if (empty($args[ $arg ])) {
                continue;
            }
            $alias   = 'f' . (++$n);
            $joins  .= " INNER JOIN %i {$alias} ON {$alias}.%i = p.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value = %s";
            $params  = array_merge($params, [ $meta, $col, $key, 'mode' === $arg ? (string) $args[ $arg ] : (string) (int) $args[ $arg ] ]);
        }
        if (! empty($args['from'])) {
            $where   .= ' AND p.post_date >= %s';
            $wparam[] = (string) $args['from'] . ' 00:00:00';
        }
        if (! empty($args['to'])) {
            $where   .= ' AND p.post_date <= %s';
            $wparam[] = (string) $args['to'] . ' 23:59:59';
        }

        $sql = "SELECT p.post_status AS status, COALESCE(c.meta_value, '') AS currency, COUNT(*) AS n, SUM(CAST(a.meta_value AS DECIMAL(20,6))) AS total FROM %i p {$joins} WHERE {$where} GROUP BY p.post_status, currency";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is built from literal fragments and %i/%s placeholders only.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, $wparam)), ARRAY_A);

        $by    = [];
        $count = 0;
        foreach ($rows as $row) {
            $status = self::neutral_status((string) $row['status']);
            $by[ $status ][ (string) $row['currency'] ] = [ 'count' => (int) $row['n'], 'amount' => round((float) $row['total'], 6) ];
            $count += (int) $row['n'];
        }
        ksort($by);
        return [ 'count' => $count, 'by_status' => $by ];
    }

    // ------------------------------------------------------------------
    // Donors
    // ------------------------------------------------------------------

    private static function donors_table(): string
    {
        return self::table('give_donors');
    }

    public static function donors_refusal(): ?array
    {
        return self::table_exists(self::donors_table())
            ? null
            : self::refusal('give_table_missing', 'GiveWP\'s donors table is missing; open GiveWP once so it can create its tables.', [ 'table' => self::donors_table() ]);
    }

    /** @return array<string, mixed>|null */
    private static function donor_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::donors_table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function donor_refusal(int $id): ?array
    {
        $missing = self::donors_refusal();
        if (null !== $missing) {
            return $missing;
        }
        return null !== self::donor_row($id) ? null : self::refusal('donor_not_found', sprintf('GiveWP has no donor %d.', $id), [ 'donor_id' => $id ]);
    }

    private static function donor_shape(array $row, bool $full): array
    {
        $name  = (string) $row['name'];
        $email = (string) $row['email'];
        return [
            'id'             => (int) $row['id'],
            'user_id'        => (int) $row['user_id'],
            'name'           => $full ? $name : Masking::value($name),
            'email'          => $full ? $email : Masking::email($email),
            'total_donated'  => (string) $row['purchase_value'],
            'donation_count' => (int) $row['purchase_count'],
            'date_created'   => (string) $row['date_created'],
        ];
    }

    public static function list_donors(array $args): array
    {
        global $wpdb;
        [$size, $page] = self::page_args($args);
        $table         = self::donors_table();
        $where         = '1=1';
        $params        = [];
        if (isset($args['search']) && '' !== (string) $args['search']) {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where    = '(name LIKE %s OR email LIKE %s)';
            $params   = [ $like, $like ];
        }
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is a literal fragment with %s placeholders only, its values spread in.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $table, ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $table, ...array_merge($params, [ $size, ($page - 1) * $size ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'donors'    => array_map(static fn (array $r): array => self::donor_shape($r, false), $rows),
            'total'     => $total,
            'page'      => $page,
            'page_size' => $size,
            'masked'    => true,
        ];
    }

    public static function get_donor(int $id): array
    {
        global $wpdb;
        $row   = (array) self::donor_row($id);
        $donor = self::donor_shape($row, true);

        $meta_table = self::table('give_donormeta');
        $meta       = [];
        if (self::table_exists($meta_table)) {
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE donor_id = %d ORDER BY meta_id ASC', $meta_table, $id), ARRAY_A);
            foreach ($rows as $m) {
                $meta[ (string) $m['meta_key'] ][] = (string) $m['meta_value'];
            }
        }
        [$posts] = self::query_donations([ 'donor_id' => $id ], 10, 1);

        return [
            'donor' => $donor + [
                'first_name'        => (string) ($meta['_give_donor_first_name'][0] ?? ''),
                'last_name'         => (string) ($meta['_give_donor_last_name'][0] ?? ''),
                'company'           => (string) ($meta['_give_donor_company'][0] ?? ''),
                'additional_emails' => array_values($meta['additional_email'] ?? []),
                'recent_donations'  => array_map(static fn (\WP_Post $p): array => [
                    'id'       => (int) $p->ID,
                    'status'   => self::neutral_status((string) $p->post_status),
                    'date'     => (string) $p->post_date,
                    'amount'   => self::meta((int) $p->ID, '_give_payment_total'),
                    'currency' => self::meta((int) $p->ID, '_give_payment_currency'),
                    'form_id'  => (int) self::meta((int) $p->ID, '_give_payment_form_id'),
                ], $posts),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------

    public static function list_campaigns(array $args): array
    {
        global $wpdb;
        [$size, $page] = self::page_args($args);
        $table         = self::table('give_campaigns');
        if (! self::table_exists($table)) {
            return [ 'campaigns' => [], 'total' => 0, 'supported' => false, 'note' => 'Campaigns need GiveWP 4 or later.' ];
        }

        $where  = '1=1';
        $params = [];
        if (isset($args['status']) && '' !== (string) $args['status']) {
            $where  = 'status = %s';
            $params = [ (string) $args['status'] ];
        }
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is a literal fragment with %s placeholders only, its values spread in.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $table, ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $table, ...array_merge($params, [ $size, ($page - 1) * $size ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $ids    = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $forms  = self::campaign_forms($ids);
        $raised = self::campaign_raised($ids);

        $out = [];
        foreach ($rows as $r) {
            $id    = (int) $r['id'];
            $out[] = [
                'id'                => $id,
                'title'             => (string) $r['campaign_title'],
                'status'            => (string) $r['status'],
                'type'              => (string) $r['campaign_type'],
                'short_description' => (string) $r['short_desc'],
                'goal'              => (int) $r['campaign_goal'],
                'goal_type'         => (string) $r['goal_type'],
                'start_date'        => $r['start_date'],
                'end_date'          => $r['end_date'],
                'page_id'           => (int) ($r['campaign_page_id'] ?? 0),
                'default_form_id'   => (int) $r['form_id'],
                'form_ids'          => $forms[ $id ] ?? [],
                'raised'            => $raised[ $id ]['amount'] ?? 0.0,
                'donations'         => $raised[ $id ]['count'] ?? 0,
            ];
        }
        return [ 'campaigns' => $out, 'total' => $total, 'page' => $page, 'page_size' => $size ];
    }

    /** @param int[] $ids @return array<int, int[]> */
    private static function campaign_forms(array $ids): array
    {
        global $wpdb;
        $table = self::table('give_campaign_forms');
        if ([] === $ids || ! self::table_exists($table)) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT campaign_id, form_id FROM %i WHERE campaign_id IN ($in) ORDER BY form_id ASC", $table, ...$ids), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $out  = [];
        foreach ($rows as $r) {
            $out[ (int) $r['campaign_id'] ][] = (int) $r['form_id'];
        }
        return $out;
    }

    /**
     * Completed donations (and recurring renewals) per campaign, test-mode
     * donations left out, as GiveWP counts a campaign's revenue.
     *
     * @param int[] $ids
     * @return array<int, array{count: int, amount: float}>
     */
    private static function campaign_raised(array $ids): array
    {
        global $wpdb;
        if ([] === $ids) {
            return [];
        }
        [$meta, $col] = self::donation_meta_table();
        $in  = implode(',', array_fill(0, count($ids), '%s'));
        $sql = "SELECT k.meta_value AS campaign, COUNT(*) AS n, SUM(CAST(a.meta_value AS DECIMAL(20,6))) AS total
            FROM %i p
            INNER JOIN %i k ON k.%i = p.ID AND k.meta_key = %s
            LEFT JOIN %i a ON a.%i = p.ID AND a.meta_key = %s
            LEFT JOIN %i m ON m.%i = p.ID AND m.meta_key = %s
            WHERE p.post_type = %s AND p.post_status IN (%s, %s) AND (m.meta_value IS NULL OR m.meta_value <> %s) AND k.meta_value IN ($in)
            GROUP BY k.meta_value";
        $params = [ $wpdb->posts, $meta, $col, '_give_campaign_id', $meta, $col, '_give_payment_total', $meta, $col, '_give_payment_mode', self::DONATION_TYPE, 'publish', 'give_subscription', 'test' ];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only literal SQL and %i/%s placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, array_map('strval', $ids))), ARRAY_A);
        $out  = [];
        foreach ($rows as $r) {
            $out[ (int) $r['campaign'] ] = [ 'count' => (int) $r['n'], 'amount' => round((float) $r['total'], 6) ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Form writes
    // ------------------------------------------------------------------

    private static function is_visual_builder_form(int $id): bool
    {
        return '' !== (string) get_post_meta($id, 'formBuilderSettings', true);
    }

    public static function update_form_refusal(array $args): ?array
    {
        $id   = (int) $args['form_id'];
        $post = get_post($id);
        if (! $post || self::FORM_TYPE !== $post->post_type) {
            return self::refusal('form_not_found', sprintf('GiveWP has no donation form %d.', $id), [ 'form_id' => $id ]);
        }
        // A visual builder form keeps its title, amounts and goal in its own
        // formBuilderSettings, which the post fields and meta do not reach.
        $settings = array_intersect([ 'title', 'price', 'goal', 'goal_enabled' ], array_keys($args));
        if ([] !== $settings && self::is_visual_builder_form($id)) {
            return self::refusal('visual_builder_form', 'This form is built with the GiveWP visual form builder, which keeps its title, amounts and goal in its own settings; change them in the form builder. Its status can change here.', [ 'form_id' => $id, 'fields' => array_values($settings) ]);
        }
        if (array_key_exists('price', $args) && 'set' !== (string) get_post_meta($id, '_give_price_option', true)) {
            return self::refusal('multi_level_form', 'This form offers several donation levels, so it has no single amount to set; edit its levels in GiveWP.', [ 'form_id' => $id ]);
        }
        return null;
    }

    private static function amount_for_db($value): string
    {
        if (function_exists('give_sanitize_amount_for_db')) {
            return (string) give_sanitize_amount_for_db((string) $value);
        }
        return number_format((float) $value, 6, '.', '');
    }

    /** @param callable(int): array $read */
    public static function update_form(array $args, callable $read): array
    {
        $id      = (int) $args['form_id'];
        $columns = [];
        if (array_key_exists('title', $args)) {
            $columns['post_title'] = (string) $args['title'];
        }
        if (array_key_exists('status', $args)) {
            $columns['post_status'] = (string) $args['status'];
        }
        if ([] !== $columns) {
            $result = Save_Filters::update_post(wp_slash([ 'ID' => $id ] + $columns), true);
            if (is_wp_error($result)) {
                throw new Operation_Refused('form_update_failed', $result->get_error_message(), [ 'form_id' => $id ]);
            }
        }
        if (array_key_exists('price', $args)) {
            update_post_meta($id, '_give_set_price', self::amount_for_db($args['price']));
        }
        if (array_key_exists('goal_enabled', $args)) {
            update_post_meta($id, '_give_goal_option', $args['goal_enabled'] ? 'enabled' : 'disabled');
        }
        if (array_key_exists('goal', $args)) {
            update_post_meta($id, '_give_set_goal', self::amount_for_db($args['goal']));
        }
        clean_post_cache($id);
        return $read($id);
    }

    // ------------------------------------------------------------------
    // Donation status
    // ------------------------------------------------------------------

    public static function status_refusal(array $args): ?array
    {
        $id      = (int) $args['donation_id'];
        $missing = self::donation_refusal($id);
        if (null !== $missing) {
            return $missing;
        }
        $current = (string) get_post_status($id);
        if (self::STATUSES[ (string) $args['status'] ] === $current) {
            return self::refusal('status_unchanged', sprintf('Donation %d is already %s.', $id, (string) $args['status']), [ 'donation_id' => $id ]);
        }
        if ('give_subscription' === $current) {
            return self::refusal('renewal_donation', 'This donation is a recurring renewal, managed by its subscription; change the subscription instead.', [ 'donation_id' => $id ]);
        }
        return null;
    }

    public static function update_status(array $args): array
    {
        $id       = (int) $args['donation_id'];
        $previous = (string) get_post_status($id);
        $target   = self::STATUSES[ (string) $args['status'] ];

        if (function_exists('give_update_payment_status')) {
            // GiveWP's own path: its totals, donor stats and emails follow.
            $ok = (bool) give_update_payment_status($id, $target);
        } else {
            $ok = ! is_wp_error(Save_Filters::set_post_status($id, $target, true));
        }
        clean_post_cache($id);
        if (! $ok || $target !== get_post_status($id)) {
            throw new Operation_Refused('status_update_failed', sprintf('GiveWP did not change donation %d to %s.', $id, (string) $args['status']), [ 'donation_id' => $id ]);
        }

        return [
            'donation_id'     => $id,
            'previous_status' => self::neutral_status($previous),
            'status'          => (string) $args['status'],
            'note'            => 'GiveWP recalculated its totals and may have emailed the donor or the site; rollback-operation puts the donation record back but cannot take those back.',
        ];
    }
}
