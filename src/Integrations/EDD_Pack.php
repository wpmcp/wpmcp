<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Easy Digital Downloads (issue #664) on the plugin-data pair, free tier, so
 * it adds no top-level tools. Ops are prefixed edd- because the pair is
 * shared.
 *
 * EDD 3.x (3.7.3 read for this) keeps a product as a `download` post
 * (capability_type product, map_meta_cap) with its settings in post meta:
 * edd_price (a string with two decimals, edd_sanitize_amount()), edd_sku,
 * _variable_pricing and edd_variable_prices (index => index, name, amount),
 * edd_download_files, _edd_download_limit, and the counters
 * _edd_download_sales and _edd_download_earnings. Orders, their items,
 * customers and discounts live in BerlinDB tables of its own
 * ({prefix}edd_orders, edd_order_items, edd_customers, edd_adjustments with
 * type 'discount', and edd_adjustmentmeta for a discount's product
 * requirements). A refund is an order row of type 'refund' whose parent is
 * the sale, with a negative total.
 *
 * Reads: list-downloads and get-download (drafts and private downloads kept
 * to what the caller may read, Readable_Posts; file names only, never the
 * file URLs), list-orders (buyer email masked, src/Privacy/Masking.php),
 * get-order (one order in full, with its items and refunds), list-customers
 * (name and email masked), list-discounts and sales-summary (gross, refunds
 * and net per currency, live mode by default). The payment key and the
 * buyer's IP are never returned.
 *
 * Writes (off until a site opts in with wpmcp_enable_edd_write, since a
 * discount changes what buyers pay and a download is a product on sale):
 *  - create-download creates a draft download recorded for rollback
 *    (Post_Creation_Snapshot: the undo moves it to the trash);
 *    update-download runs under a post snapshot (fields and every meta key).
 *    A single price is refused on a download with variable pricing, where
 *    EDD ignores it.
 *  - create-discount and update-discount write the edd_adjustments row the
 *    way EDD's discount screen saves it (type discount, scope global, code
 *    unique, a percent at most 100, dates in UTC), as a 'plugin_table_rows'
 *    row image (kind edd, suffix adjustments; a create reserves its id
 *    first, so the undo removes it). BerlinDB caches query results, so
 *    every write and every restore retires EDD's cached lookups for the
 *    table (Plugin_Table_Rows_Snapshot::purge_edd_cache()).
 * Nothing here moves money or mails a buyer: there is no order status
 * change, refund or resend (a follow-up of issue #664).
 *
 * Every op takes manage_options. Presence is filterable (wpmcp_edd_active,
 * default: EDD_VERSION is defined or Easy_Digital_Downloads is loaded).
 */
final class EDD_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes EDD's own tables live; its BerlinDB caches are retired after each write.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_edd_write';

    public const DOWNLOAD_TYPE = 'download';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'easy-digital-downloads/easy-digital-downloads.php', 'easy-digital-downloads-pro/easy-digital-downloads.php' ];

    /** EDD's order statuses (edd_get_payment_statuses()). */
    private const ORDER_STATUSES = [ 'pending', 'processing', 'complete', 'refunded', 'partially_refunded', 'revoked', 'failed', 'abandoned', 'on_hold' ];

    /** Statuses that count toward gross sales (edd_get_gross_order_statuses()). */
    private const GROSS_STATUSES = [ 'complete', 'refunded', 'partially_refunded', 'revoked', 'on_hold' ];

    private const DISCOUNT_STATUSES = [ 'active', 'inactive', 'expired', 'archived' ];

    private const POST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    private const TABLES = [ 'orders', 'order_items', 'customers', 'adjustments', 'adjustmentmeta' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_edd_active', defined('EDD_VERSION') || class_exists('Easy_Digital_Downloads', false));
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'edd_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Easy Digital Downloads is %s on this site. Install and activate Easy Digital Downloads (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'easy-digital-downloads', 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array} */
    public static function tables_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach (self::TABLES as $name) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                return [
                    'code'    => 'edd_tables_missing',
                    'message' => sprintf('Easy Digital Downloads\' table %s does not exist on this site. These ops need EDD 3.0 or later with its database upgrades complete (Downloads > Tools > Upgrades in wp-admin), then try again.', self::table($name)),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging  = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $date    = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$' ];
        $money   = [ 'type' => 'number', 'minimum' => 0 ];
        $posts   = [ 'capability' => self::CAPABILITY, 'requires' => static fn () => self::presence() ];
        $tables  = [ 'capability' => self::CAPABILITY, 'requires' => static fn () => self::tables_presence() ];
        $opt_in  = [ 'mode' => 'write', 'enabled_by_default' => (bool) apply_filters('wpmcp_enable_edd_write', false), 'opt_in_filter' => self::OPT_IN_FILTER ];
        $own     = static fn (string $access): array => [ 'download_id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $dl      = [
            'title'   => [ 'type' => 'string', 'minLength' => 1 ],
            'content' => [ 'type' => 'string' ],
            'excerpt' => [ 'type' => 'string' ],
            'status'  => [ 'type' => 'string', 'enum' => self::POST_STATUSES ],
            'price'   => $money,
            'sku'     => [ 'type' => 'string', 'maxLength' => 200 ],
        ];
        $disc    = [
            'code'              => [ 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,50}$' ],
            'name'              => [ 'type' => 'string', 'maxLength' => 200 ],
            'status'            => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ] ],
            'amount_type'       => [ 'type' => 'string', 'enum' => [ 'percent', 'flat' ] ],
            'amount'            => [ 'type' => 'number', 'exclusiveMinimum' => 0 ],
            'description'       => [ 'type' => 'string' ],
            'max_uses'          => [ 'type' => 'integer', 'minimum' => 0 ],
            'once_per_customer' => [ 'type' => 'boolean' ],
            'min_charge_amount' => $money,
            'start_date'        => $date,
            'end_date'          => $date,
        ];
        $row     = static fn (int $id): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('edd', 'adjustments', [ $id ]),
        ];

        return [
            'edd-list-downloads'  => $posts + [
                'mode'         => 'read',
                'description'  => 'List Easy Digital Downloads products newest first: id, title, status, price or variable prices, sku, sales, earnings, date. search matches the title; status filters; page, per_page (max 100)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'status' => $dl['status'] ] + $paging ],
                'handler'      => static fn (array $args): array => self::list_downloads($args),
            ],
            'edd-get-download'    => $posts + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => 'One EDD download: the list fields plus content, excerpt, file names (never URLs), download limit, categories and tags',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'download_id' => $id ], 'required' => [ 'download_id' ] ],
                'validate'     => static fn (array $args): ?array => self::download_refusal((int) $args['download_id'], false),
                'handler'      => static fn (array $args): array => [ 'download' => self::download_view((int) $args['download_id'], true) ],
            ],
            'edd-list-orders'     => $tables + [
                'mode'         => 'read',
                'description'  => 'List EDD orders newest first: id, number, status, mode, customer id, email MASKED, currency, subtotal, discount, tax, total, gateway, dates. type sale (default) or refund; status, mode (live, test), customer_id, from and to (YYYY-MM-DD, UTC); page, per_page',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'        => [ 'type' => 'string', 'enum' => [ 'sale', 'refund' ] ],
                        'status'      => [ 'type' => 'string', 'enum' => self::ORDER_STATUSES ],
                        'mode'        => [ 'type' => 'string', 'enum' => [ 'live', 'test' ] ],
                        'customer_id' => $id,
                        'from'        => $date,
                        'to'          => $date,
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_orders($args),
            ],
            'edd-get-order'       => $tables + [
                'mode'         => 'read',
                'description'  => 'One EDD order in full: email and customer name, totals, gateway, dates, items (product, price option, quantity, amounts, status) and refunds. Read-only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'order_id' => $id ], 'required' => [ 'order_id' ] ],
                'validate'     => static fn (array $args): ?array => self::order_refusal((int) $args['order_id']),
                'handler'      => static fn (array $args): array => self::get_order((int) $args['order_id']),
            ],
            'edd-list-customers'  => $tables + [
                'mode'         => 'read',
                'description'  => 'List EDD customers newest first: id, user id, name and email MASKED, status, purchase count and value, date. search matches name or email; page, per_page',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ] ] + $paging ],
                'handler'      => static fn (array $args): array => self::list_customers($args),
            ],
            'edd-list-discounts'  => $tables + [
                'mode'         => 'read',
                'description'  => 'List EDD discount codes newest first: id, name, code, status, percent or flat amount, uses and limit, once per customer, minimum, dates, product requirements and exclusions. status (active, inactive, expired, archived), search over code and name; page, per_page',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'enum' => self::DISCOUNT_STATUSES ], 'search' => [ 'type' => 'string' ] ] + $paging ],
                'handler'      => static fn (array $args): array => self::list_discounts($args),
            ],
            'edd-sales-summary'   => $tables + [
                'mode'         => 'read',
                'description'  => 'EDD sales per currency: order count, gross (sales in gross statuses), refunds and net, plus sale counts per status. from and to (YYYY-MM-DD, UTC, on the order date); mode live (default) or test',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'from' => $date, 'to' => $date, 'mode' => [ 'type' => 'string', 'enum' => [ 'live', 'test' ] ] ] ],
                'handler'      => static fn (array $args): array => self::sales_summary($args),
            ],
            'edd-create-download' => $posts + $opt_in + [
                'self_snapshotting' => true,
                'description'       => 'Create an EDD download (status defaults to draft): title, content, excerpt, price, sku. Undo: rollback-session trashes it',
                'input_schema'      => [ 'type' => 'object', 'properties' => $dl, 'required' => [ 'title' ] ],
                'validate'          => static fn (array $args): ?array => self::create_download_refusal((string) ($args['status'] ?? 'draft')),
                'handler'           => static fn (array $args, array $context): array => self::create_download($args, $context),
            ],
            'edd-update-download' => $posts + $opt_in + [
                'objects'      => $own('write'),
                'description'  => 'Change an EDD download\'s title, content, excerpt, status, price or sku; only the args given change. A price on a download with variable pricing is refused. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'download_id' => $id ] + $dl, 'required' => [ 'download_id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_download_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['download_id'] ],
                'handler'      => static fn (array $args): array => self::update_download($args),
            ],
            'edd-create-discount' => $tables + $opt_in + [
                'description'  => 'Create an EDD discount code: code (letters, digits, - and _, unique), amount (percent at most 100, or flat), amount_type (percent default), name (defaults to the code), status (active default), description, max_uses, once_per_customer, min_charge_amount, start_date and end_date (UTC; a bare end date runs to 23:59:59). Applies to the whole cart. Undo: rollback-operation removes it',
                'input_schema' => [ 'type' => 'object', 'properties' => $disc, 'required' => [ 'code', 'amount' ] ],
                'validate'     => static fn (array $args): ?array => self::discount_refusal($args, null),
                'snapshot'     => static fn (array $args): array => $row(self::next_discount_id()),
                'handler'      => static fn (array $args, array $context): array => self::create_discount($args, $context),
            ],
            'edd-update-discount' => $tables + $opt_in + [
                'description'  => 'Change an EDD discount code; only the args given change (code, name, status, amount, amount_type, description, max_uses, once_per_customer, min_charge_amount, dates). The use count is EDD\'s own. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'discount_id' => $id ] + $disc, 'required' => [ 'discount_id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_discount_refusal($args),
                'snapshot'     => static fn (array $args): array => $row((int) $args['discount_id']),
                'handler'      => static fn (array $args): array => self::update_discount($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array{0: int, 1: int, 2: int} [page, per_page, offset] */
    private static function paging(array $args): array
    {
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        return [ $page, $per_page, ($page - 1) * $per_page ];
    }

    /** A YYYY-MM-DD bound as a full UTC datetime: the start of the day, or with $end its last second. */
    private static function bound(string $date, bool $end): string
    {
        return 10 === strlen($date) ? $date . ($end ? ' 23:59:59' : ' 00:00:00') : $date;
    }

    private static function money($value): float
    {
        return round((float) $value, 2);
    }

    /** A price the way edd_sanitize_amount() stores it: two decimals, a period, no thousands separator. */
    private static function amount_string(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Downloads
    // ------------------------------------------------------------------

    /** Null when $id is a download (not trashed) the user may read, or with $write edit. */
    private static function download_refusal(int $id, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::DOWNLOAD_TYPE !== $post->post_type) {
            return self::refusal('download_not_found', sprintf('%d is not an Easy Digital Downloads download.', $id), [ 'download_id' => $id ]);
        }
        if ('trash' === $post->post_status) {
            return self::refusal('download_not_found', sprintf('Download %d ("%s") is in the Trash; restore it first (restore-post, or Downloads > Trash in wp-admin), then try again.', $id, (string) $post->post_title), [ 'download_id' => $id, 'status' => 'trash' ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s download %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'download_id' => $id ]);
        }
        return null;
    }

    private static function create_download_refusal(string $status): ?array
    {
        $object = get_post_type_object(self::DOWNLOAD_TYPE);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', 'Easy Digital Downloads has not registered its download post type.');
        }
        $caps = [ $object->cap->create_posts ];
        if (in_array($status, [ 'publish', 'private', 'future' ], true)) {
            $caps[] = $object->cap->publish_posts;
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the "%s" capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    private static function update_download_refusal(array $args): ?array
    {
        $id = (int) $args['download_id'];
        $no = self::download_refusal($id, true);
        if (null !== $no) {
            return $no;
        }
        if (in_array($args['status'] ?? '', [ 'publish', 'private', 'future' ], true) && 'publish' !== get_post_status($id)) {
            $object = get_post_type_object(self::DOWNLOAD_TYPE);
            if ($object instanceof \WP_Post_Type && ! current_user_can($object->cap->publish_posts)) {
                return self::refusal('operation_denied', sprintf('Publishing needs the "%s" capability.', $object->cap->publish_posts), [ 'reason' => 'capability' ]);
            }
        }
        if (array_key_exists('price', $args) && self::variable($id)) {
            return self::refusal('variable_pricing', sprintf('Download %d uses variable pricing, so EDD ignores its single price. Change the price options in wp-admin (Downloads > Edit > Pricing).', $id), [ 'download_id' => $id ]);
        }
        return null;
    }

    private static function variable(int $id): bool
    {
        $flag = get_post_meta($id, '_variable_pricing', true);
        return '' !== (string) $flag && '0' !== (string) $flag;
    }

    /** @return array<string, mixed> */
    private static function download_view(int $id, bool $full): array
    {
        $post     = get_post($id);
        $variable = self::variable($id);
        $prices   = [];
        foreach ((array) get_post_meta($id, 'edd_variable_prices', true) as $index => $price) {
            if (is_array($price)) {
                $prices[] = [ 'index' => (int) ($price['index'] ?? $index), 'name' => (string) ($price['name'] ?? ''), 'amount' => self::money($price['amount'] ?? 0) ];
            }
        }
        $view = [
            'id'               => $id,
            'title'            => $post instanceof \WP_Post ? (string) $post->post_title : '',
            'status'           => $post instanceof \WP_Post ? (string) $post->post_status : '',
            'price'            => $variable ? null : self::money(get_post_meta($id, 'edd_price', true)),
            'variable_pricing' => $variable,
            'prices'           => $variable ? $prices : [],
            'sku'              => (string) get_post_meta($id, 'edd_sku', true),
            'sales'            => (int) get_post_meta($id, '_edd_download_sales', true),
            'earnings'         => self::money(get_post_meta($id, '_edd_download_earnings', true)),
            'date'             => $post instanceof \WP_Post ? (string) $post->post_date_gmt : '',
            'link'             => (string) get_permalink($id),
        ];
        if (! $full || ! $post instanceof \WP_Post) {
            return $view;
        }
        $files = [];
        foreach ((array) get_post_meta($id, 'edd_download_files', true) as $file) {
            if (is_array($file)) {
                $files[] = (string) ($file['name'] ?? basename((string) wp_parse_url((string) ($file['file'] ?? ''), PHP_URL_PATH)));
            }
        }
        $terms = static function (string $taxonomy) use ($id): array {
            $names = taxonomy_exists($taxonomy) ? wp_get_post_terms($id, $taxonomy, [ 'fields' => 'names' ]) : [];
            return is_array($names) ? array_values(array_map('strval', $names)) : [];
        };
        return $view + [
            'content'        => (string) $post->post_content,
            'excerpt'        => (string) $post->post_excerpt,
            'files'          => $files,
            'download_limit' => (int) get_post_meta($id, '_edd_download_limit', true),
            'categories'     => $terms('download_category'),
            'tags'           => $terms('download_tag'),
        ];
    }

    private static function list_downloads(array $args): array
    {
        [$page, $per_page] = self::paging($args);
        $query = [
            'post_type'      => self::DOWNLOAD_TYPE,
            'post_status'    => isset($args['status']) ? (string) $args['status'] : self::POST_STATUSES,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $found = Readable_Posts::query($query);
        $rows  = [];
        foreach ($found->posts as $post) {
            $rows[] = self::download_view((int) $post->ID, false);
        }
        return [ 'downloads' => $rows, 'total' => (int) $found->found_posts, 'page' => $page, 'per_page' => $per_page ];
    }

    /** Write a download's own settings from $args, the way EDD's save stores them. */
    private static function write_download_meta(int $id, array $args): void
    {
        if (array_key_exists('price', $args)) {
            update_post_meta($id, 'edd_price', self::amount_string((float) $args['price']));
        }
        if (array_key_exists('sku', $args)) {
            update_post_meta($id, 'edd_sku', wp_slash(sanitize_text_field((string) $args['sku'])));
        }
    }

    private static function create_download(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'    => self::DOWNLOAD_TYPE,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', 'Could not create the download.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::write_download_meta($id, $args + [ 'price' => 0 ]);
        return [ 'result' => [ 'download' => self::download_view($id, true) ], 'operation_ids' => [ $op ] ];
    }

    /** Edit one download (the dispatcher snapshots the post, meta included, first). */
    private static function update_download(array $args): array
    {
        $id     = (int) $args['download_id'];
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::write_download_meta($id, $args);
        clean_post_cache($id);
        return [ 'download' => self::download_view($id, true) ];
    }

    // ------------------------------------------------------------------
    // Orders, customers, summary
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function order_view(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'order_number'   => '' !== (string) $r['order_number'] ? (string) $r['order_number'] : (string) $r['id'],
            'parent'         => (int) $r['parent'],
            'type'           => (string) $r['type'],
            'status'         => (string) $r['status'],
            'mode'           => (string) $r['mode'],
            'customer_id'    => (int) $r['customer_id'],
            'user_id'        => (int) $r['user_id'],
            'email'          => (string) $r['email'],
            'currency'       => (string) $r['currency'],
            'subtotal'       => self::money($r['subtotal']),
            'discount'       => self::money($r['discount']),
            'tax'            => self::money($r['tax']),
            'total'          => self::money($r['total']),
            'gateway'        => (string) $r['gateway'],
            'date_created'   => $r['date_created'],
            'date_completed' => $r['date_completed'],
        ];
    }

    private static function list_orders(array $args): array
    {
        global $wpdb;
        [$page, $per_page, $offset] = self::paging($args);
        $where = $wpdb->prepare(' AND type = %s', (string) ($args['type'] ?? 'sale'));
        foreach ([ 'status' => 'status', 'mode' => 'mode' ] as $arg => $column) {
            if (isset($args[ $arg ])) {
                $where .= $wpdb->prepare(' AND %i = %s', $column, (string) $args[ $arg ]);
            }
        }
        if (isset($args['customer_id'])) {
            $where .= $wpdb->prepare(' AND customer_id = %d', (int) $args['customer_id']);
        }
        if (isset($args['from'])) {
            $where .= $wpdb->prepare(' AND date_created >= %s', self::bound((string) $args['from'], false));
        }
        if (isset($args['to'])) {
            $where .= $wpdb->prepare(' AND date_created <= %s', self::bound((string) $args['to'], true));
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE 1=1{$where}", self::table('orders')));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT id, parent, order_number, status, type, user_id, customer_id, email, gateway, mode, currency, subtotal, discount, tax, total, date_created, date_completed FROM %i WHERE 1=1{$where} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", self::table('orders'), $per_page, $offset), ARRAY_A);
        $orders = [];
        foreach ($rows as $r) {
            $orders[] = Masking::keys(self::order_view($r), [ 'email' ]);
        }
        return [ 'orders' => $orders, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'masked' => true ];
    }

    /** @return array<string, mixed>|null */
    private static function order_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, parent, order_number, status, type, user_id, customer_id, email, gateway, mode, currency, subtotal, discount, tax, total, date_created, date_completed FROM %i WHERE id = %d', self::table('orders'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function order_refusal(int $id): ?array
    {
        return null !== self::order_row($id) ? null : self::refusal('order_not_found', sprintf('Easy Digital Downloads has no order %d.', $id), [ 'order_id' => $id ]);
    }

    private static function get_order(int $id): array
    {
        global $wpdb;
        $row   = (array) self::order_row($id);
        $order = self::order_view($row);
        $order['customer_name'] = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM %i WHERE id = %d', self::table('customers'), (int) $row['customer_id']));
        $order['items']         = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY cart_index ASC, id ASC', self::table('order_items'), $id), ARRAY_A) as $item) {
            $order['items'][] = [
                'id'           => (int) $item['id'],
                'product_id'   => (int) $item['product_id'],
                'product_name' => (string) $item['product_name'],
                'price_id'     => null === $item['price_id'] ? null : (int) $item['price_id'],
                'status'       => (string) $item['status'],
                'quantity'     => (int) $item['quantity'],
                'amount'       => self::money($item['amount']),
                'subtotal'     => self::money($item['subtotal']),
                'discount'     => self::money($item['discount']),
                'tax'          => self::money($item['tax']),
                'total'        => self::money($item['total']),
            ];
        }
        $order['refunds'] = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, order_number, status, total, date_created FROM %i WHERE parent = %d AND type = 'refund' ORDER BY id ASC", self::table('orders'), $id), ARRAY_A) as $refund) {
            $order['refunds'][] = [ 'id' => (int) $refund['id'], 'order_number' => (string) $refund['order_number'], 'status' => (string) $refund['status'], 'total' => self::money($refund['total']), 'date_created' => $refund['date_created'] ];
        }
        return [ 'order' => $order ];
    }

    private static function list_customers(array $args): array
    {
        global $wpdb;
        [$page, $per_page, $offset] = self::paging($args);
        $where = '';
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like   = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where .= $wpdb->prepare(' AND (name LIKE %s OR email LIKE %s)', $like, $like);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE 1=1{$where}", self::table('customers')));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT id, user_id, email, name, status, purchase_value, purchase_count, date_created FROM %i WHERE 1=1{$where} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", self::table('customers'), $per_page, $offset), ARRAY_A);
        $customers = [];
        foreach ($rows as $r) {
            $customers[] = Masking::keys([
                'id'             => (int) $r['id'],
                'user_id'        => (int) $r['user_id'],
                'name'           => (string) $r['name'],
                'email'          => (string) $r['email'],
                'status'         => (string) $r['status'],
                'purchase_count' => (int) $r['purchase_count'],
                'purchase_value' => self::money($r['purchase_value']),
                'date_created'   => $r['date_created'],
            ], [ 'name', 'email' ]);
        }
        return [ 'customers' => $customers, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'masked' => true ];
    }

    private static function sales_summary(array $args): array
    {
        global $wpdb;
        $mode  = (string) ($args['mode'] ?? 'live');
        $where = $wpdb->prepare(' AND mode = %s', $mode);
        if (isset($args['from'])) {
            $where .= $wpdb->prepare(' AND date_created >= %s', self::bound((string) $args['from'], false));
        }
        if (isset($args['to'])) {
            $where .= $wpdb->prepare(' AND date_created <= %s', self::bound((string) $args['to'], true));
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT currency, type, status, COUNT(*) AS n, SUM(total) AS amount FROM %i WHERE 1=1{$where} GROUP BY currency, type, status", self::table('orders')), ARRAY_A);

        $currencies = [];
        $by_status  = [];
        foreach ($rows as $r) {
            $currency = '' !== (string) $r['currency'] ? (string) $r['currency'] : 'unknown';
            $sum      = &$currencies[ $currency ];
            $sum    ??= [ 'orders' => 0, 'gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0 ];
            if ('refund' === $r['type']) {
                if ('complete' === $r['status']) {
                    $sum['refunds'] += abs((float) $r['amount']);
                }
            } else {
                $by_status[ (string) $r['status'] ] = ($by_status[ (string) $r['status'] ] ?? 0) + (int) $r['n'];
                if (in_array($r['status'], self::GROSS_STATUSES, true)) {
                    $sum['orders'] += (int) $r['n'];
                    $sum['gross']  += (float) $r['amount'];
                }
            }
            unset($sum);
        }
        foreach ($currencies as $currency => $sum) {
            $currencies[ $currency ] = [
                'orders'  => $sum['orders'],
                'gross'   => self::money($sum['gross']),
                'refunds' => self::money($sum['refunds']),
                'net'     => self::money($sum['gross'] - $sum['refunds']),
            ];
        }
        ksort($by_status);
        return [
            'mode'          => $mode,
            'from'          => $args['from'] ?? null,
            'to'            => $args['to'] ?? null,
            'currencies'    => $currencies,
            'sales_by_status' => $by_status,
            'gross_statuses'  => self::GROSS_STATUSES,
        ];
    }

    // ------------------------------------------------------------------
    // Discounts
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function discount_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d AND type = 'discount'", self::table('adjustments'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private static function discount_view(array $r): array
    {
        global $wpdb;
        $meta = (array) $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE edd_adjustment_id = %d', self::table('adjustmentmeta'), (int) $r['id']), ARRAY_A);
        $ids  = static fn (string $key): array => array_values(array_map(static fn (array $m): int => (int) $m['meta_value'], array_filter($meta, static fn (array $m): bool => $key === $m['meta_key'])));
        return [
            'id'                   => (int) $r['id'],
            'name'                 => (string) $r['name'],
            'code'                 => (string) $r['code'],
            'status'               => (string) $r['status'],
            'amount_type'          => (string) $r['amount_type'],
            'amount'               => self::money($r['amount']),
            'description'          => (string) $r['description'],
            'use_count'            => (int) $r['use_count'],
            'max_uses'             => (int) $r['max_uses'],
            'once_per_customer'    => (bool) (int) $r['once_per_customer'],
            'min_charge_amount'    => self::money($r['min_charge_amount']),
            'scope'                => (string) $r['scope'],
            'start_date'           => $r['start_date'],
            'end_date'             => $r['end_date'],
            'product_requirements' => $ids('product_requirement'),
            'excluded_products'    => $ids('excluded_product'),
            'date_created'         => $r['date_created'],
        ];
    }

    private static function list_discounts(array $args): array
    {
        global $wpdb;
        [$page, $per_page, $offset] = self::paging($args);
        $where = " AND type = 'discount'";
        if (isset($args['status'])) {
            $where .= $wpdb->prepare(' AND status = %s', (string) $args['status']);
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like   = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where .= $wpdb->prepare(' AND (code LIKE %s OR name LIKE %s)', $like, $like);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE 1=1{$where}", self::table('adjustments')));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE 1=1{$where} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", self::table('adjustments'), $per_page, $offset), ARRAY_A);
        return [ 'discounts' => array_map([ self::class, 'discount_view' ], $rows), 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /** The checks EDD's discount screen makes, on the discount as it will be after the write ($current for an update). */
    private static function discount_refusal(array $args, ?array $current): ?array
    {
        if (isset($args['code'])) {
            global $wpdb;
            $taken = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE type = 'discount' AND code = %s AND id <> %d LIMIT 1", self::table('adjustments'), (string) $args['code'], (int) ($current['id'] ?? 0)));
            if ($taken > 0) {
                return self::refusal('discount_code_taken', sprintf('Discount %d already uses the code %s; pick another code.', $taken, (string) $args['code']), [ 'discount_id' => $taken ]);
            }
        }
        $type   = (string) ($args['amount_type'] ?? $current['amount_type'] ?? 'percent');
        $amount = (float) ($args['amount'] ?? $current['amount'] ?? 0);
        if ('percent' === $type && $amount > 100) {
            return self::refusal('invalid_discount', 'A percent discount can be at most 100.', [ 'field' => 'amount' ]);
        }
        $start = array_key_exists('start_date', $args) ? self::bound((string) $args['start_date'], false) : ($current['start_date'] ?? null);
        $end   = array_key_exists('end_date', $args) ? self::bound((string) $args['end_date'], true) : ($current['end_date'] ?? null);
        if (null !== $start && null !== $end && '' !== (string) $start && '' !== (string) $end && strcmp((string) $end, (string) $start) < 0) {
            return self::refusal('invalid_discount', 'end_date is before start_date.', [ 'field' => 'end_date' ]);
        }
        return null;
    }

    private static function update_discount_refusal(array $args): ?array
    {
        $id  = (int) $args['discount_id'];
        $row = self::discount_row($id);
        if (null === $row) {
            return self::refusal('discount_not_found', sprintf('Easy Digital Downloads has no discount %d.', $id), [ 'discount_id' => $id ]);
        }
        return self::discount_refusal($args, $row);
    }

    /** The columns a discount write sets from $args. */
    private static function discount_columns(array $args): array
    {
        $set = [];
        foreach ([ 'code', 'name', 'status', 'amount_type' ] as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = sanitize_text_field((string) $args[ $key ]);
            }
        }
        if (array_key_exists('description', $args)) {
            $set['description'] = sanitize_textarea_field((string) $args['description']);
        }
        foreach ([ 'amount', 'min_charge_amount' ] as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = self::amount_string((float) $args[ $key ]);
            }
        }
        if (array_key_exists('max_uses', $args)) {
            $set['max_uses'] = (int) $args['max_uses'];
        }
        if (array_key_exists('once_per_customer', $args)) {
            $set['once_per_customer'] = $args['once_per_customer'] ? 1 : 0;
        }
        if (array_key_exists('start_date', $args)) {
            $set['start_date'] = self::bound((string) $args['start_date'], false);
        }
        if (array_key_exists('end_date', $args)) {
            $set['end_date'] = self::bound((string) $args['end_date'], true);
        }
        return $set;
    }

    private static function next_discount_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::table('adjustments')));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table('adjustments')), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    private static function create_discount(array $args, array $context): array
    {
        global $wpdb;
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $now    = current_time('mysql', true);
        $row    = [
            'id'                => $id,
            'name'              => (string) $args['code'],
            'status'            => 'active',
            'type'              => 'discount',
            'scope'             => 'global',
            'amount_type'       => 'percent',
            'description'       => '',
            'max_uses'          => 0,
            'once_per_customer' => 0,
            'min_charge_amount' => '0.00',
            'date_created'      => $now,
            'date_modified'     => $now,
            'uuid'              => 'urn:uuid:' . wp_generate_uuid4(),
        ];
        $row = array_merge($row, self::discount_columns($args));
        if ('' === trim((string) $row['name'])) {
            $row['name'] = (string) $args['code'];
        }
        if (false === $wpdb->insert(self::table('adjustments'), $row)) {
            // Nothing was written, but the snapshot is persisted; rolling it
            // back would remove whatever row now holds that id, so it is voided.
            if ('' !== (string) ($context['operation_id'] ?? '')) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            throw new Operation_Refused('insert_failed', 'Could not create the discount: ' . $wpdb->last_error);
        }
        Plugin_Table_Rows_Snapshot::purge_edd_cache('adjustments', [ $id ]);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own hook, fired by edd_add_discount(), so its extensions see the write.
        do_action('edd_post_insert_discount', $row, $id);
        return [ 'discount' => self::discount_view((array) self::discount_row($id)) ];
    }

    private static function update_discount(array $args): array
    {
        global $wpdb;
        $id  = (int) $args['discount_id'];
        $set = self::discount_columns($args) + [ 'date_modified' => current_time('mysql', true) ];
        if (false === $wpdb->update(self::table('adjustments'), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', 'Could not update the discount: ' . $wpdb->last_error);
        }
        Plugin_Table_Rows_Snapshot::purge_edd_cache('adjustments', [ $id ]);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own hook, fired by edd_update_discount(), so its extensions see the write.
        do_action('edd_post_update_discount', $set, $id);
        return [ 'discount' => self::discount_view((array) self::discount_row($id)) ];
    }
}
