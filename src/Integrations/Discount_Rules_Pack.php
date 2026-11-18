<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Discount Rules for WooCommerce (issue #901) on the plugin-data pair, free
 * tier, so it adds no top-level tools. Ops are prefixed discountrules-
 * because the pair is shared.
 *
 * The plugin (its v2 engine, WDR_VERSION) keeps every rule as one row of
 * its own {prefix}wdr_rules table (v2/App/Models/DBTable.php): title,
 * enabled, deleted (its soft delete), exclusive, priority (lowest applies
 * first), discount_type (wdr_simple_discount, wdr_cart_discount,
 * wdr_bulk_discount and the paid add-on's set, buy X get X/Y and free
 * shipping types), the JSON columns filters and conditions (objects keyed
 * from 1), additional (condition_relationship), one JSON adjustment column
 * per type (product_adjustments, cart_adjustments, bulk_adjustments, ...),
 * date_from and date_to (unix times of the store's local wall clock, as
 * Rule::formatDate() stores them), usage_limits and used_limits. The
 * storefront reads the table live on every request.
 *
 * Reads: discountrules-list-rules (type, status, priority, dates, usage and
 * the filter and condition types) and discountrules-get-rule (filters,
 * conditions and the adjustment decoded). Rules hold no customer data in
 * the free plugin, so nothing needs masking; a single read is behind
 * manage_woocommerce, the plugin's own menu capability.
 *
 * Writes change prices, so they are off until a site opts in with
 * wpmcp_enable_discount_rules_write, and:
 *  - create-rule writes a simple, cart or bulk rule (the free plugin's
 *    types) the way Rule::save() does, staged DISABLED at the end of the
 *    priority queue; enable-rule makes it live;
 *  - update-rule changes only the args given; a live (enabled) rule
 *    changes only with confirm_live:true;
 *  - enable-rule and disable-rule flip the enabled flag, as the plugin's
 *    own list toggle does;
 *  - a rule giving more than 100 percent off, or a negative amount, is
 *    refused on create and update, and a stored one is refused on enable.
 * Each write is snapshotted as a 'plugin_table_rows' row image (kind wdr,
 * suffix rules): an update or a flag flip goes back exactly, and a create,
 * whose id is reserved before the snapshot, is removed again.
 *
 * Presence is filterable (wpmcp_discount_rules_active, default:
 * WDR_VERSION is defined).
 */
final class Discount_Rules_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes the plugin's own rules table live; it has no cached read API for it.

    public const CAPABILITY = 'manage_woocommerce';

    public const OPT_IN_FILTER = 'wpmcp_enable_discount_rules_write';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'woo-discount-rules/woo-discount-rules.php' ];

    /** discount type => its adjustment column. */
    public const ADJUSTMENT_COLUMNS = [
        'wdr_simple_discount'      => 'product_adjustments',
        'wdr_cart_discount'        => 'cart_adjustments',
        'wdr_bulk_discount'        => 'bulk_adjustments',
        'wdr_set_discount'         => 'set_adjustments',
        'wdr_buy_x_get_x_discount' => 'buy_x_get_x_adjustments',
        'wdr_buy_x_get_y_discount' => 'buy_x_get_y_adjustments',
        'wdr_free_shipping'        => 'cart_adjustments',
    ];

    /** The types the free plugin creates, and the adjustment types each takes. */
    private const CREATABLE = [
        'wdr_simple_discount' => [ 'percentage', 'flat', 'fixed_price' ],
        'wdr_cart_discount'   => [ 'percentage', 'flat', 'flat_in_subtotal' ],
        'wdr_bulk_discount'   => [ 'percentage', 'flat', 'fixed_price' ],
    ];

    private const BULK_OPERATORS = [ 'product_cumulative', 'product', 'variation' ];

    private const DATE = '^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_discount_rules_active', defined('WDR_VERSION'));
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wdr_rules';
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        $url = admin_url('plugins.php');
        if (! self::active()) {
            $installed = false;
            foreach (self::PLUGIN_FILES as $file) {
                $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
            }
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf(
                    'Discount Rules for WooCommerce is %s on this site. Install and activate Discount Rules for WooCommerce (%s), then try again.',
                    $installed ? 'installed but not active' : 'not installed',
                    $url
                ),
                'data'    => [ 'plugin' => 'woo-discount-rules', 'plugins_url' => $url ],
            ];
        }
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table())) {
            return [
                'code'    => 'discount_rules_table_missing',
                'message' => sprintf('Discount Rules\' table %s does not exist on this site (the plugin\'s legacy v1 engine keeps no rules there). Switch Discount Rules to its current engine, or deactivate and reactivate it so it creates its table, then try again.', self::table()),
                'data'    => [ 'plugins_url' => $url ],
            ];
        }
        return true;
    }

    // ------------------------------------------------------------ catalog

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id         = [ 'type' => 'integer', 'minimum' => 1 ];
        $one        = [ 'type' => 'object', 'properties' => [ 'rule_id' => $id ], 'required' => [ 'rule_id' ], 'additionalProperties' => false ];
        $str        = [ 'type' => 'string', 'maxLength' => 255 ];
        $range      = [
            'type'                 => 'object',
            'properties'           => [ 'from' => [ 'type' => 'integer', 'minimum' => 0 ], 'to' => [ 'type' => 'integer', 'minimum' => 0 ], 'type' => $str, 'value' => [ 'type' => 'number' ], 'label' => $str ],
            'required'             => [ 'type', 'value' ],
            'additionalProperties' => false,
        ];
        $adjustment = [
            'type'                 => 'object',
            'properties'           => [
                'type'               => $str,
                'value'              => [ 'type' => 'number' ],
                'label'              => $str,
                'cart_label'         => $str,
                'apply_as_cart_rule' => [ 'type' => 'boolean' ],
                'operator'           => [ 'type' => 'string', 'enum' => self::BULK_OPERATORS ],
                'ranges'             => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $range ],
            ],
            'additionalProperties' => false,
        ];
        $fields     = [
            'title'                  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
            'adjustment'             => $adjustment,
            'filters'                => [
                'type'     => 'array',
                'maxItems' => 50,
                'items'    => [
                    'type'                 => 'object',
                    'properties'           => [
                        'type'   => [ 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,64}$' ],
                        'method' => [ 'type' => 'string', 'enum' => [ 'in_list', 'not_in_list' ] ],
                        'value'  => [ 'type' => 'array', 'maxItems' => 500, 'items' => [ 'type' => [ 'integer', 'string' ] ] ],
                    ],
                    'required'             => [ 'type' ],
                    'additionalProperties' => false,
                ],
            ],
            'conditions'             => [
                'type'     => 'array',
                'maxItems' => 50,
                'items'    => [
                    'type'                 => 'object',
                    'properties'           => [ 'type' => [ 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,64}$' ], 'options' => [ 'type' => 'object' ] ],
                    'required'             => [ 'type' ],
                    'additionalProperties' => false,
                ],
            ],
            'condition_relationship' => [ 'type' => 'string', 'enum' => [ 'and', 'or' ] ],
            'exclusive'              => [ 'type' => 'boolean' ],
            'date_from'              => [ 'type' => 'string', 'pattern' => '^$|' . self::DATE ],
            'date_to'                => [ 'type' => 'string', 'pattern' => '^$|' . self::DATE ],
            'usage_limits'           => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
        $base       = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write      = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_discount_rules_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $target     = static fn (array $args): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('wdr', 'rules', [ (int) $args['rule_id'] ]),
        ];

        return [
            'discountrules-list-rules'   => $base + [
                'mode'         => 'read',
                'description'  => 'List Discount Rules for WooCommerce rules by priority: id, title, discount_type, enabled, exclusive, dates (store time), usage, filter and condition types. status (enabled, disabled, deleted, any; default not deleted), discount_type, search, page_size (max 100), offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'        => [ 'type' => 'string', 'enum' => [ 'enabled', 'disabled', 'deleted', 'any' ] ],
                        'discount_type' => [ 'type' => 'string', 'maxLength' => 64 ],
                        'search'        => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page_size'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'        => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_rules($args),
            ],
            'discountrules-get-rule'     => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Discount Rules rule: filters, conditions, condition_relationship and its adjustment decoded',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::rule_refusal((int) $args['rule_id'], true),
                'handler'      => static fn (array $args): array => [ 'rule' => self::view((array) self::row((int) $args['rule_id']), true) ],
            ],
            'discountrules-create-rule'  => $write + [
                'description'  => 'Create a Discount Rules rule, staged DISABLED (enable-rule makes it live): title, discount_type (wdr_simple_discount, wdr_cart_discount, wdr_bulk_discount), adjustment ({type, value} or bulk {ranges:[{from,to,type,value}]}), filters (default all products), conditions, dates (YYYY-MM-DD HH:MM store time), usage_limits. Over 100 percent off is refused. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'discount_type' => [ 'type' => 'string', 'enum' => array_keys(self::CREATABLE) ] ] + $fields,
                    'required'             => [ 'title', 'discount_type', 'adjustment' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::fields_refusal($args, (string) $args['discount_type']),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('wdr', 'rules', [ self::next_id() ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'discountrules-update-rule'  => $write + [
                'description'  => 'Change a Discount Rules rule; only the args given change. A live (enabled) rule needs confirm_live:true. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'rule_id' => $id, 'confirm_live' => [ 'type' => 'boolean' ] ] + $fields,
                    'required'             => [ 'rule_id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'discountrules-enable-rule'  => $write + [
                'description'  => 'Make a Discount Rules rule live (enabled). A rule over 100 percent off is refused. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::enable_refusal((int) $args['rule_id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_enabled((int) $args['rule_id'], 1),
            ],
            'discountrules-disable-rule' => $write + [
                'description'  => 'Stop a Discount Rules rule (disabled); it is kept. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::rule_refusal((int) $args['rule_id'], false),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_enabled((int) $args['rule_id'], 0),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array<string, mixed>|null */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function rule_refusal(int $id, bool $deleted_ok): ?array
    {
        $row = self::row($id);
        if (null === $row || (! $deleted_ok && ! empty($row['deleted']))) {
            return self::refusal('rule_not_found', sprintf('Discount Rules has no rule %d%s.', $id, null === $row ? '' : ' (it is deleted)'), [ 'rule_id' => $id ]);
        }
        return null;
    }

    private static function update_refusal(array $args): ?array
    {
        $id      = (int) $args['rule_id'];
        $refused = self::rule_refusal($id, false);
        if (null !== $refused) {
            return $refused;
        }
        $row = (array) self::row($id);
        if (! empty($row['enabled']) && empty($args['confirm_live'])) {
            return self::refusal('rule_live', sprintf('Rule %d is live, so a change applies to the storefront at once. Pass confirm_live:true to change it anyway, or disable-rule first.', $id), [ 'rule_id' => $id ]);
        }
        $type = (string) $row['discount_type'];
        if (isset($args['adjustment']) && ! isset(self::CREATABLE[ $type ])) {
            return self::refusal('invalid_args', sprintf('Rule %d has the discount type "%s"; its adjustment is changed in the Discount Rules screen.', $id, $type), [ 'rule_id' => $id ]);
        }
        $dates = [
            'date_from' => array_key_exists('date_from', $args) ? (string) $args['date_from'] : self::format_date($row['date_from']),
            'date_to'   => array_key_exists('date_to', $args) ? (string) $args['date_to'] : self::format_date($row['date_to']),
        ];
        return self::fields_refusal(array_merge($args, $dates), $type);
    }

    private static function enable_refusal(int $id): ?array
    {
        $refused = self::rule_refusal($id, false);
        if (null !== $refused) {
            return $refused;
        }
        $row = (array) self::row($id);
        foreach (array_unique(array_values(self::ADJUSTMENT_COLUMNS)) as $column) {
            $bad = self::range_refusal(self::decode($row[ $column ] ?? null));
            if (null !== $bad) {
                return $bad;
            }
        }
        return null;
    }

    /** Args shared by create and update, checked against the rule's type. */
    private static function fields_refusal(array $args, string $type): ?array
    {
        if (isset($args['adjustment'])) {
            $adjustment = (array) $args['adjustment'];
            $allowed    = self::CREATABLE[ $type ] ?? [];
            if ('wdr_bulk_discount' === $type) {
                if (empty($adjustment['ranges']) || isset($adjustment['type']) || isset($adjustment['value'])) {
                    return self::refusal('invalid_args', 'A bulk rule takes adjustment.ranges ([{from, to, type, value}]), not a single type and value.');
                }
                foreach ((array) $adjustment['ranges'] as $range) {
                    if (! in_array((string) $range['type'], $allowed, true)) {
                        return self::refusal('invalid_args', sprintf('A bulk range type is one of %s.', implode(', ', $allowed)));
                    }
                }
            } else {
                if (! isset($adjustment['type'], $adjustment['value']) || isset($adjustment['ranges']) || isset($adjustment['operator'])) {
                    return self::refusal('invalid_args', 'This rule takes adjustment {type, value}.');
                }
                if (! in_array((string) $adjustment['type'], $allowed, true)) {
                    return self::refusal('invalid_args', sprintf('The adjustment type for "%s" is one of %s.', $type, implode(', ', $allowed)));
                }
            }
            $bad = self::range_refusal($adjustment);
            if (null !== $bad) {
                return $bad;
            }
        }
        $from = self::parse_date((string) ($args['date_from'] ?? ''));
        $to   = self::parse_date((string) ($args['date_to'] ?? ''));
        if (null !== $from && null !== $to && $to < $from) {
            return self::refusal('invalid_dates', 'date_to is before date_from.');
        }
        foreach ((array) ($args['conditions'] ?? []) as $condition) {
            $options = (array) ($condition['options'] ?? []);
            if ('cart_coupon' === ($condition['type'] ?? '') && 'custom_coupon' === ($options['operator'] ?? '') && '' !== trim((string) ($options['custom_value'] ?? ''))) {
                $code = trim((string) $options['custom_value']);
                if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code) > 0) {
                    return self::refusal('coupon_exists', sprintf('A WooCommerce coupon %s already exists, so the rule cannot create its own coupon by that code.', $code), [ 'code' => $code ]);
                }
            }
        }
        return null;
    }

    /**
     * Walk an adjustment and refuse a percentage over 100 or any negative
     * amount, at any depth (bulk ranges, buy X get Y free values).
     */
    private static function range_refusal($node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $pairs = [ [ 'type', 'value' ], [ 'free_type', 'free_value' ] ];
        foreach ($pairs as [ $type_key, $value_key ]) {
            if (! isset($node[ $value_key ]) || ! is_numeric($node[ $value_key ])) {
                continue;
            }
            $value = (float) $node[ $value_key ];
            if ($value < 0) {
                return self::refusal('discount_out_of_range', sprintf('A discount amount cannot be negative (%s).', (string) $node[ $value_key ]), [ 'value' => $value ]);
            }
            if ('percentage' === ($node[ $type_key ] ?? '') && $value > 100) {
                return self::refusal('discount_out_of_range', sprintf('A percentage discount cannot exceed 100 (%s).', (string) $node[ $value_key ]), [ 'value' => $value ]);
            }
        }
        foreach ($node as $child) {
            $bad = self::range_refusal($child);
            if (null !== $bad) {
                return $bad;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /** @return mixed */
    private static function decode($json)
    {
        if (! is_string($json) || '' === $json) {
            return null;
        }
        $out = json_decode($json, true);
        return JSON_ERROR_NONE === json_last_error() ? $out : null;
    }

    /** @return array<int, mixed> The plugin's objects keyed from 1, as a list. */
    private static function listed($json): array
    {
        $out = self::decode($json);
        return is_array($out) ? array_values($out) : [];
    }

    /** A stored unix time of the store's wall clock as YYYY-MM-DD HH:MM, or null. */
    private static function format_date($stamp): ?string
    {
        return null === $stamp || '' === (string) $stamp ? null : gmdate('Y-m-d H:i', (int) $stamp);
    }

    /** YYYY-MM-DD[ HH:MM] of the store's wall clock as the plugin stores it, or null for empty. */
    private static function parse_date(string $date): ?int
    {
        $date = trim($date);
        if ('' === $date) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', strlen($date) > 10 ? $date : $date . ' 00:00', new \DateTimeZone('UTC'));
        return false === $parsed ? null : $parsed->getTimestamp();
    }

    /** @return array<string, mixed> */
    private static function view(array $row, bool $full): array
    {
        $type = (string) ($row['discount_type'] ?? '');
        $out  = [
            'id'            => (int) $row['id'],
            'title'         => (string) $row['title'],
            'discount_type' => $type,
            'enabled'       => ! empty($row['enabled']),
            'deleted'       => ! empty($row['deleted']),
            'exclusive'     => ! empty($row['exclusive']),
            'priority'      => null === $row['priority'] ? null : (int) $row['priority'],
            'date_from'     => self::format_date($row['date_from']),
            'date_to'       => self::format_date($row['date_to']),
            'usage_limits'  => (int) $row['usage_limits'],
            'used_limits'   => (int) $row['used_limits'],
        ];
        $filters    = self::listed($row['filters']);
        $conditions = self::listed($row['conditions']);
        if (! $full) {
            $out['filter_types']    = array_values(array_map(static fn ($f): string => (string) ($f['type'] ?? ''), $filters));
            $out['condition_types'] = array_values(array_map(static fn ($c): string => (string) ($c['type'] ?? ''), $conditions));
            return $out;
        }
        $additional                    = self::decode($row['additional']);
        $out['filters']                = $filters;
        $out['conditions']             = $conditions;
        $out['condition_relationship'] = (string) ($additional['condition_relationship'] ?? 'and');
        $out['adjustment']             = self::decode($row[ self::ADJUSTMENT_COLUMNS[ $type ] ?? 'product_adjustments' ] ?? null);
        $out['used_coupons']           = self::listed($row['used_coupons']);
        $out['modified_on']            = (string) $row['modified_on'];
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_rules(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];
        switch ((string) ($args['status'] ?? '')) {
            case 'enabled':
                $where[] = 'deleted = 0 AND enabled = 1';
                break;
            case 'disabled':
                $where[] = 'deleted = 0 AND enabled = 0';
                break;
            case 'deleted':
                $where[] = 'deleted = 1';
                break;
            case 'any':
                break;
            default:
                $where[] = 'deleted = 0';
        }
        if (isset($args['discount_type']) && '' !== (string) $args['discount_type']) {
            $where[]  = 'discount_type = %s';
            $params[] = (string) $args['discount_type'];
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $where[]  = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
        }
        $sql    = [] === $where ? '' : ' WHERE ' . implode(' AND ', $where);
        $size   = (int) ($args['page_size'] ?? 20);
        $offset = (int) ($args['offset'] ?? 0);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql is built from literals and placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $sql, self::table(), ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i' . $sql . ' ORDER BY priority IS NULL, priority ASC, id ASC LIMIT %d OFFSET %d', self::table(), ...array_merge($params, [ $size, $offset ])), ARRAY_A);

        return [
            'rules'       => array_map(static fn (array $row): array => self::view($row, false), $rows),
            'total'       => $total,
            'next_offset' => $offset + count($rows) < $total ? $offset + count($rows) : null,
        ];
    }

    // ------------------------------------------------------------ writes

    /** The id the next insert takes: MAX(id) + 1 or the table's auto-increment, whichever is higher. */
    private static function next_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table()), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    /** A number as the plugin's form posts it. */
    private static function num($value): string
    {
        return is_numeric($value) ? (string) (0.0 === fmod((float) $value, 1.0) ? (int) $value : (float) $value) : '';
    }

    /** The adjustment column's JSON for a type, built the way the rule form posts it. */
    private static function adjustment_json(string $type, array $adjustment): string
    {
        if ('wdr_bulk_discount' === $type) {
            $ranges = [];
            foreach (array_values((array) $adjustment['ranges']) as $i => $range) {
                $ranges[ (string) ($i + 1) ] = [
                    'from'  => self::num($range['from'] ?? ''),
                    'to'    => self::num($range['to'] ?? ''),
                    'type'  => (string) $range['type'],
                    'value' => self::num($range['value']),
                    'label' => sanitize_text_field((string) ($range['label'] ?? '')),
                ];
            }
            return (string) wp_json_encode([
                'operator'   => (string) ($adjustment['operator'] ?? 'product_cumulative'),
                'ranges'     => $ranges,
                'cart_label' => sanitize_text_field((string) ($adjustment['cart_label'] ?? '')),
            ]);
        }
        if ('wdr_cart_discount' === $type) {
            return (string) wp_json_encode([
                'type'  => (string) $adjustment['type'],
                'value' => self::num($adjustment['value']),
                'label' => sanitize_text_field((string) ($adjustment['label'] ?? '')),
            ]);
        }
        return (string) wp_json_encode([
            'type'               => (string) $adjustment['type'],
            'value'              => self::num($adjustment['value']),
            'cart_label'         => sanitize_text_field((string) ($adjustment['cart_label'] ?? '')),
            'apply_as_cart_rule' => empty($adjustment['apply_as_cart_rule']) ? '0' : '1',
        ]);
    }

    /** Strings and lists of strings, sanitized, keys kept. */
    private static function clean($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $out[ is_int($key) ? $key : sanitize_key((string) $key) ] = self::clean($child);
            }
            return $out;
        }
        return is_bool($value) ? ($value ? '1' : '0') : sanitize_text_field((string) $value);
    }

    /** A list as the plugin's JSON object keyed from 1, or [] when empty. */
    private static function keyed(array $items): string
    {
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $out[ (string) ($i + 1) ] = self::clean($item);
        }
        return (string) wp_json_encode([] === $out ? [] : $out);
    }

    /** @return string[] The coupon codes the conditions name, as Rule::save() collects them. */
    private static function coupons(array $conditions): array
    {
        $codes = [];
        foreach ($conditions as $condition) {
            $options = (array) ($condition['options'] ?? []);
            if ('cart_coupon' !== ($condition['type'] ?? '')) {
                continue;
            }
            if ('custom_coupon' === ($options['operator'] ?? '') && '' !== trim((string) ($options['custom_value'] ?? ''))) {
                $codes[] = sanitize_text_field(trim((string) $options['custom_value']));
            } elseif (! empty($options['value'])) {
                $codes = array_merge($codes, array_map(static fn ($c): string => sanitize_text_field((string) $c), (array) $options['value']));
            }
        }
        return array_values(array_unique($codes));
    }

    /** The columns the given args set (not enabled, which only enable-rule and disable-rule change). */
    private static function columns_from(array $args, string $type, array $current = []): array
    {
        $set = [];
        if (array_key_exists('title', $args)) {
            $set['title'] = sanitize_text_field((string) $args['title']);
        }
        if (array_key_exists('exclusive', $args)) {
            $set['exclusive'] = $args['exclusive'] ? 1 : 0;
        }
        if (array_key_exists('usage_limits', $args)) {
            $set['usage_limits'] = (int) $args['usage_limits'];
        }
        foreach ([ 'date_from', 'date_to' ] as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = self::parse_date((string) $args[ $key ]);
            }
        }
        if (array_key_exists('filters', $args)) {
            $set['filters'] = self::keyed((array) $args['filters']);
        }
        if (array_key_exists('conditions', $args)) {
            $set['conditions']   = self::keyed((array) $args['conditions']);
            $set['used_coupons'] = (string) wp_json_encode(self::coupons((array) $args['conditions']));
        }
        if (array_key_exists('condition_relationship', $args)) {
            $additional = self::decode($current['additional'] ?? null);
            $additional = is_array($additional) ? $additional : [];

            $additional['condition_relationship'] = (string) $args['condition_relationship'];
            $set['additional']                    = (string) wp_json_encode($additional);
        }
        if (array_key_exists('adjustment', $args)) {
            $set[ self::ADJUSTMENT_COLUMNS[ $type ] ] = self::adjustment_json($type, (array) $args['adjustment']);
        }
        return $set;
    }

    /** Let the plugin rebuild its on-sale product list after a rule changed, as its own save does. */
    private static function after_write(int $id): void
    {
        $class = '\\Wdr\\App\\Controllers\\OnSaleShortCode';
        if (class_exists($class) && method_exists($class, 'updateOnsaleRebuildPageStatus')) {
            $class::updateOnsaleRebuildPageStatus($id);
        }
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        global $wpdb;
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $type   = (string) $args['discount_type'];
        $now    = current_time('mysql', true);
        $user   = get_current_user_id();
        $empty  = '[]';
        $row    = [
            'id'                        => $id,
            'enabled'                   => 0,
            'deleted'                   => 0,
            'exclusive'                 => 0,
            'priority'                  => 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(priority), 0) FROM %i WHERE deleted = 0', self::table())),
            'usage_limits'              => 0,
            'filters'                   => (string) wp_json_encode([ '1' => [ 'type' => 'all_products' ] ]),
            'conditions'                => $empty,
            'additional'                => (string) wp_json_encode([ 'condition_relationship' => 'and' ]),
            'product_adjustments'       => $empty,
            'cart_adjustments'          => $empty,
            'buy_x_get_x_adjustments'   => $empty,
            'buy_x_get_y_adjustments'   => $empty,
            'bulk_adjustments'          => $empty,
            'set_adjustments'           => $empty,
            'rule_language'             => $empty,
            'advanced_discount_message' => $empty,
            'discount_type'             => $type,
            'used_coupons'              => $empty,
            'created_by'                => $user,
            'created_on'                => $now,
            'modified_by'               => $user,
            'modified_on'               => $now,
        ];
        $row = array_merge($row, self::columns_from($args, $type));
        $row = array_intersect_key($row, array_flip(Plugin_Table_Rows_Snapshot::columns(self::table())));

        if (false === $wpdb->insert(self::table(), $row)) {
            // Nothing was written, but the snapshot is persisted. Left in place, rolling it
            // back would remove whatever row now holds that id, so it is voided.
            if ('' !== (string) ($context['operation_id'] ?? '')) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            throw new Operation_Refused('insert_failed', 'Could not create the rule: ' . $wpdb->last_error);
        }
        self::after_write($id);

        return [ 'rule' => self::view((array) self::row($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        global $wpdb;
        $id      = (int) $args['rule_id'];
        $current = (array) self::row($id);
        $set     = self::columns_from($args, (string) $current['discount_type'], $current);
        if ([] !== $set) {
            $set['modified_by'] = get_current_user_id();
            $set['modified_on'] = current_time('mysql', true);
            if (false === $wpdb->update(self::table(), $set, [ 'id' => $id ])) {
                throw new Operation_Refused('update_failed', sprintf('Could not update rule %d: %s', $id, $wpdb->last_error));
            }
            self::after_write($id);
        }

        return [ 'rule' => self::view((array) self::row($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function set_enabled(int $id, int $enabled): array
    {
        global $wpdb;
        if (false === $wpdb->update(self::table(), [ 'enabled' => $enabled ], [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not change rule %d: %s', $id, $wpdb->last_error));
        }
        self::after_write($id);

        return [ 'rule' => self::view((array) self::row($id), false) ];
    }
}
