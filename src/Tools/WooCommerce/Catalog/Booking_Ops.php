<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Booking_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Bookings ops behind the bookings.* rows (issue #659), run
 * in-process rather than through the plugin's wc-bookings/v1 routes, so
 * every response is a curated view and every write goes through the calls
 * the plugin's own screens make:
 *
 *  - reads come straight from Bookings' storage (Wc_Booking_Snapshot::row()
 *    and the product meta Bookings keeps), and availability from the
 *    plugin's own slots calculation;
 *  - create validates and prices a booking as the booking form does
 *    (wc_bookings_get_posted_data() and calculate_booking_cost(), which
 *    checks the block is bookable), then saves it as the manual booking
 *    screen does, without an order. It can email, so it needs confirm:true;
 *  - update-status moves a booking through WC_Booking::update_status(), so
 *    Bookings' emails, order sync and reminders run. It needs confirm:true;
 *  - cancel (destructive: off by default, confirm:true) does the same to
 *    cancelled. It is not recoverable: the cancellation emails go out and a
 *    calendar sync removes the event, though rollback restores the booking
 *    and its order's status;
 *  - set-availability-rules replaces a bookable product's own availability
 *    rules through the product.
 *
 * Every write is snapshotted (Safety\Wc_Booking_Snapshot), so
 * rollback-operation undoes it; a create's rollback deletes the booking.
 */
final class Booking_Ops
{
    private const MAX_PER_PAGE     = 50;
    private const DEFAULT_PER_PAGE = 20;

    /** Statuses update-status sets; cancelling is cancel's job. */
    private const SETTABLE_STATUSES = ['unpaid', 'pending-confirmation', 'confirmed', 'paid', 'complete'];

    /** Statuses a new booking may start in (the manual booking screen's, less cancelled and complete). */
    private const CREATE_STATUSES = ['confirmed', 'unpaid', 'pending-confirmation', 'paid'];

    /** Statuses Bookings lets a booking be cancelled from (get_wc_booking_statuses('cancel')). */
    private const CANCELLABLE_STATUSES = ['unpaid', 'pending-confirmation', 'confirmed', 'paid'];

    /** Cart states: a shopper's unplaced booking, not one to manage here. */
    private const CART_STATUSES = ['in-cart', 'was-in-cart'];

    /** Availability rule types Bookings' product screen saves, by the shape of their from/to. */
    private const RULE_TYPES = [
        'custom'           => 'date',
        'months'           => 'month',
        'weeks'            => 'week',
        'days'             => 'day',
        'time'             => 'time',
        'time:1'           => 'time',
        'time:2'           => 'time',
        'time:3'           => 'time',
        'time:4'           => 'time',
        'time:5'           => 'time',
        'time:6'           => 'time',
        'time:7'           => 'time',
        'time:range'       => 'time_dates',
        'custom:daterange' => 'time_dates',
    ];

    private const MAX_RULES = 100;

    /** The longest window bookings.availability calculates, in days. */
    private const MAX_AVAILABILITY_DAYS = 31;

    /** Bookable product meta shown by bookings.products, by the name shown. */
    private const PRODUCT_META = [
        'duration'                  => '_wc_booking_duration',
        'duration_unit'             => '_wc_booking_duration_unit',
        'duration_type'             => '_wc_booking_duration_type',
        'cost'                      => '_wc_booking_cost',
        'block_cost'                => '_wc_booking_block_cost',
        'qty'                       => '_wc_booking_qty',
        'requires_confirmation'     => '_wc_booking_requires_confirmation',
        'user_can_cancel'           => '_wc_booking_user_can_cancel',
        'has_persons'               => '_wc_booking_has_persons',
        'min_persons'               => '_wc_booking_min_persons_group',
        'max_persons'               => '_wc_booking_max_persons_group',
        'has_resources'             => '_wc_booking_has_resources',
        'default_date_availability' => '_wc_booking_default_date_availability',
    ];

    private const INT_FIELDS  = ['duration', 'qty', 'min_persons', 'max_persons'];
    private const BOOL_FIELDS = ['requires_confirmation', 'user_can_cancel', 'has_persons', 'has_resources'];

    /** Said on every write that can email: what a rollback cannot take back. */
    private const EMAIL_NOTE = 'Bookings may email the customer and the store about this booking; a rollback restores the booking but cannot unsend them.';

    // ------------------------------------------------------------- reads

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            switch ($handler) {
                case 'booking_products':
                    return [ 'status' => 200, 'body' => self::products($params) ];
                case 'booking_list':
                    return [ 'status' => 200, 'body' => self::list($params) ];
                case 'booking_get':
                    self::refuse_unknown($params, ['id'], 'bookings.get');
                    return [ 'status' => 200, 'body' => self::view(self::existing($params)) ];
                case 'booking_availability':
                    return [ 'status' => 200, 'body' => self::availability($params) ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No booking read named \"{$handler}\".");
    }

    /** @return array<string, mixed> */
    private static function products(array $params): array
    {
        self::refuse_unknown($params, ['page', 'per_page', 'search'], 'bookings.products');

        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));
        $query    = [
            'type'     => Wc_Booking_Snapshot::product_types(),
            'status'   => ['publish', 'private', 'draft', 'pending', 'future'],
            'limit'    => $per_page,
            'page'     => $page,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'paginate' => true,
        ];
        if (isset($params['search']) && is_string($params['search']) && '' !== trim($params['search'])) {
            $query['s'] = sanitize_text_field($params['search']);
        }
        $found = wc_get_products($query);

        $rows = [];
        foreach ($found->products as $product) {
            $rows[] = self::product_view($product);
        }

        return [ 'page' => $page, 'per_page' => $per_page, 'total' => (int) $found->total, 'products' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function list(array $params): array
    {
        self::refuse_unknown($params, ['status', 'product_id', 'customer_id', 'resource_id', 'from', 'to', 'order', 'page', 'per_page'], 'bookings.list');

        $known    = Wc_Booking_Snapshot::statuses();
        $statuses = array_values(array_diff($known, self::CART_STATUSES));
        if (array_key_exists('status', $params)) {
            $statuses = [];
            foreach ((array) $params['status'] as $status) {
                $status = is_string($status) ? trim($status) : '';
                if (! in_array($status, $known, true)) {
                    self::refuse('invalid_params', 'status must be one of: ' . implode(', ', $known) . '.', [ 'allowed' => $known ]);
                }
                $statuses[] = $status;
            }
        }

        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));
        $order    = $params['order'] ?? 'desc';
        if (! in_array($order, ['asc', 'desc'], true)) {
            self::refuse('invalid_params', 'order must be asc or desc.');
        }

        $meta = [];
        foreach ([ 'product_id' => '_booking_product_id', 'customer_id' => '_booking_customer_id', 'resource_id' => '_booking_resource_id' ] as $param => $key) {
            if (array_key_exists($param, $params)) {
                $meta[] = [ 'key' => $key, 'value' => (string) self::positive($params[ $param ], $param) ];
            }
        }
        // Bookings stores start as local 'YmdHis', so a fixed-width string
        // comparison orders and bounds it.
        if (array_key_exists('from', $params)) {
            $meta[] = [ 'key' => '_booking_start', 'value' => str_replace('-', '', self::day($params['from'], 'from')) . '000000', 'compare' => '>=' ];
        }
        if (array_key_exists('to', $params)) {
            $meta[] = [ 'key' => '_booking_start', 'value' => str_replace('-', '', self::day($params['to'], 'to')) . '235959', 'compare' => '<=' ];
        }

        $query = new \WP_Query([
            'post_type'           => Wc_Booking_Snapshot::POST_TYPE,
            'post_status'         => $statuses,
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'fields'              => 'ids',
            'meta_key'            => '_booking_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the order the screen lists bookings in.
            'orderby'             => [ 'meta_value' => strtoupper($order), 'ID' => strtoupper($order) ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- ordered by the stored start, as Bookings' own list is.
            'meta_query'          => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bookings keeps its fields in post meta.
            'ignore_sticky_posts' => true,
        ]);

        $rows = [];
        foreach ($query->posts as $id) {
            $row = Wc_Booking_Snapshot::row((int) $id);
            if (null !== $row) {
                $rows[] = self::summary($row);
            }
        }

        return [ 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts, 'bookings' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function availability(array $params): array
    {
        self::refuse_unknown($params, ['product_id', 'from', 'to', 'resource_id', 'hide_unavailable', 'page', 'per_page'], 'bookings.availability');
        $product = self::bookable($params['product_id'] ?? null);

        $from = array_key_exists('from', $params) ? self::day($params['from'], 'from') : wp_date('Y-m-d');
        $to   = array_key_exists('to', $params) ? self::day($params['to'], 'to') : gmdate('Y-m-d', (int) strtotime($from . ' +7 days'));
        $span = ((int) strtotime($to) - (int) strtotime($from)) / DAY_IN_SECONDS;
        if ($span < 1 || $span > self::MAX_AVAILABILITY_DAYS) {
            self::refuse('invalid_params', 'to must be after from, at most ' . self::MAX_AVAILABILITY_DAYS . ' days later.');
        }
        $hide = $params['hide_unavailable'] ?? false;
        if (! is_bool($hide)) {
            self::refuse('invalid_params', 'hide_unavailable must be true or false.');
        }
        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE * 2, self::positive($params['per_page'] ?? self::MAX_PER_PAGE, 'per_page'));

        $query = [
            'product_ids'      => (string) $product->get_id(),
            'min_date'         => $from,
            'max_date'         => $to,
            'hide_unavailable' => $hide ? 'true' : 'false',
            'page'             => $page,
            'limit'            => $per_page,
        ];
        if (array_key_exists('resource_id', $params)) {
            $query['resource_ids'] = (string) self::resource($product, $params['resource_id']);
        }
        $slots = Wc_Booking_Snapshot::slots($query);
        if (is_wp_error($slots)) {
            self::refuse('availability_failed', 'WooCommerce Bookings could not calculate availability: ' . wp_strip_all_tags($slots->get_error_message()));
        }

        $records = [];
        foreach ($slots['records'] as $slot) {
            $records[] = [
                'date'      => (string) ($slot['date'] ?? ''),
                'duration'  => (int) ($slot['duration'] ?? 0),
                'available' => (int) ($slot['available'] ?? 0),
                'booked'    => (int) ($slot['booked'] ?? 0),
            ];
        }

        return [
            'product_id'                => $product->get_id(),
            'from'                      => $from,
            'to'                        => $to,
            'timezone'                  => self::timezone(),
            'default_date_availability' => (string) get_post_meta($product->get_id(), '_wc_booking_default_date_availability', true),
            'rules'                     => self::rules_of($product->get_id()),
            'page'                      => $page,
            'per_page'                  => $per_page,
            'total'                     => (int) $slots['count'],
            'slots'                     => $records,
        ];
    }

    // ------------------------------------------------------------ writes

    /**
     * Validate one write. Returns a structured error, or ['body' => plan,
     * 'report' => what the response says beside the result]. Writes
     * nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        try {
            switch ($handler) {
                case 'booking_create':
                    return self::prepare_create($params, $confirm);
                case 'booking_status':
                    return self::prepare_status($params, $confirm);
                case 'booking_cancel':
                    return self::prepare_cancel($params);
                case 'booking_rules':
                    return self::prepare_rules($params);
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No booking write named \"{$handler}\".");
    }

    /**
     * Create the booking a validated bookings.create planned, and record its
     * creation row.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        $id = Wc_Booking_Snapshot::make((array) $plan['props'], (string) $plan['status']);
        if ($id <= 0 || null === Wc_Booking_Snapshot::row($id)) {
            return Op_Guard::error('booking_create_failed', 'WooCommerce Bookings did not create the booking. Nothing was created.');
        }
        $operation_id = Wc_Booking_Snapshot::record_creation($id, 'woo-write', [ 'op' => $op, 'params' => $plan ], $session_id);

        return [
            'status'       => 201,
            'body'         => self::view((array) Wc_Booking_Snapshot::row($id)),
            'operation_id' => $operation_id,
        ];
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the
     * snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        try {
            if ('booking_rules' === $handler) {
                Wc_Booking_Snapshot::set_rules($id, (array) $plan['rules']);
                return [ 'status' => 200, 'body' => [ 'product_id' => $id, 'rules' => self::rules_of($id) ] ];
            }
            if (! Wc_Booking_Snapshot::set_status($id, (string) $plan['status'])) {
                return [ 'status' => 409, 'body' => [ 'code' => 'booking_refused', 'message' => "WooCommerce Bookings did not change booking {$id}'s status." ] ];
            }
        } catch (\Throwable $e) {
            return [ 'status' => 409, 'body' => [ 'code' => 'booking_refused', 'message' => 'WooCommerce Bookings refused the change: ' . $e->getMessage() ] ];
        }

        $row = Wc_Booking_Snapshot::row($id);
        return [ 'status' => 200, 'body' => null === $row ? null : self::view($row) ];
    }

    /** @return array<string, mixed> */
    private static function prepare_create(array $params, bool $confirm): array
    {
        self::refuse_unknown($params, ['product_id', 'start', 'duration', 'resource_id', 'persons', 'customer_id', 'status', 'note'], 'bookings.create');
        $product = self::bookable($params['product_id'] ?? null);

        $raw = $params['start'] ?? null;
        if (! is_string($raw) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?$/', trim($raw), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            self::refuse('invalid_params', 'start must be "YYYY-MM-DD" or "YYYY-MM-DD HH:MM", in the store\'s time zone (' . self::timezone() . ').');
        }
        $posted = [
            'wc_bookings_field_start_date_year'  => $m[1],
            'wc_bookings_field_start_date_month' => $m[2],
            'wc_bookings_field_start_date_day'   => $m[3],
        ];
        if (isset($m[4]) && '' !== $m[4]) {
            if ((int) $m[4] > 23 || (int) $m[5] > 59) {
                self::refuse('invalid_params', 'start has an invalid time.');
            }
            $posted['wc_bookings_field_start_date_time'] = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}";
        }
        if (array_key_exists('duration', $params)) {
            $posted['wc_bookings_field_duration'] = self::positive($params['duration'], 'duration');
        }
        if (array_key_exists('resource_id', $params)) {
            $posted['wc_bookings_field_resource'] = self::resource($product, $params['resource_id']);
        }
        if (array_key_exists('persons', $params)) {
            $persons = $params['persons'];
            if (is_array($persons)) {
                if ([] === $persons) {
                    self::refuse('invalid_params', 'persons must be a number, or an object of person type id to count.');
                }
                foreach ($persons as $type_id => $count) {
                    $posted[ 'wc_bookings_field_persons_' . self::positive((string) $type_id, 'persons type id') ] = self::positive($count, 'persons count');
                }
            } else {
                $posted['wc_bookings_field_persons'] = self::positive($persons, 'persons');
            }
        }

        $customer = 0;
        if (array_key_exists('customer_id', $params)) {
            $customer = self::positive($params['customer_id'], 'customer_id');
            if (false === get_userdata($customer)) {
                self::refuse('invalid_params', "No user has id {$customer}.");
            }
        }
        $status = $params['status'] ?? 'confirmed';
        if (! is_string($status) || ! in_array($status, self::CREATE_STATUSES, true)) {
            self::refuse('invalid_params', 'status must be one of: ' . implode(', ', self::CREATE_STATUSES) . '.', [ 'allowed' => self::CREATE_STATUSES ]);
        }
        $note = self::note($params);

        $planned = Wc_Booking_Snapshot::plan($product, $posted);
        if (is_wp_error($planned)) {
            self::refuse('not_bookable', 'WooCommerce Bookings refused this booking: ' . wp_strip_all_tags($planned->get_error_message()), [ 'product_id' => $product->get_id() ]);
        }
        $data = $planned['data'];

        if (! $confirm) {
            self::refuse(
                'confirmation_required',
                "Booking product {$product->get_id()} needs confirm:true. " . self::EMAIL_NOTE,
                [ 'start' => self::local((int) $data['_start_date']), 'end' => self::local((int) $data['_end_date']), 'cost' => wc_format_decimal($planned['cost'], wc_get_price_decimals()) ]
            );
        }

        $props = [
            'customer_id'   => $customer,
            'product_id'    => $product->get_id(),
            'resource_id'   => $data['_resource_id'] ?? '',
            'person_counts' => (array) ($data['_persons'] ?? []),
            'cost'          => wc_format_decimal($planned['cost'], wc_get_price_decimals()),
            'start'         => (int) $data['_start_date'],
            'end'           => (int) $data['_end_date'],
            'all_day'       => ! empty($data['_all_day']),
        ];
        if ('' !== $note) {
            $props['note'] = $note;
        }

        return [
            'body'   => [ 'props' => $props, 'status' => $status ],
            'report' => [ 'side_effects' => self::EMAIL_NOTE ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_status(array $params, bool $confirm): array
    {
        self::refuse_unknown($params, ['id', 'status'], 'bookings.update-status');
        $row     = self::existing($params);
        $current = $row['status'];

        $status = is_string($params['status'] ?? null) ? trim($params['status']) : '';
        if (! in_array($status, self::SETTABLE_STATUSES, true)) {
            self::refuse(
                'invalid_params',
                'status must be one of: ' . implode(', ', self::SETTABLE_STATUSES) . '. Cancel a booking with bookings.cancel.',
                [ 'allowed' => self::SETTABLE_STATUSES ]
            );
        }
        if (in_array($current, array_merge(self::CART_STATUSES, ['trash']), true)) {
            self::refuse('status_not_allowed', "Booking {$row['id']} is {$current}, not a placed booking; its status is not changed here.", [ 'current' => $current ]);
        }
        if ($current === $status) {
            self::refuse('invalid_params', "Booking {$row['id']} is already {$status}.");
        }
        if (! $confirm) {
            self::refuse(
                'confirmation_required',
                "Changing booking {$row['id']} from {$current} to {$status} needs confirm:true. " . self::EMAIL_NOTE,
                [ 'current' => $current, 'requested' => $status ]
            );
        }

        return [
            'body'   => [ 'id' => $row['id'], 'status' => $status ],
            'report' => [ 'side_effects' => self::EMAIL_NOTE . self::order_note($row) ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_cancel(array $params): array
    {
        self::refuse_unknown($params, ['id'], 'bookings.cancel');
        $row     = self::existing($params);
        $current = $row['status'];
        if (! in_array($current, self::CANCELLABLE_STATUSES, true)) {
            self::refuse(
                'status_not_allowed',
                "WooCommerce Bookings does not cancel a booking that is {$current}; only " . implode(', ', self::CANCELLABLE_STATUSES) . ' bookings can be cancelled.',
                [ 'current' => $current ]
            );
        }

        return [
            'body'   => [ 'id' => $row['id'], 'status' => 'cancelled' ],
            'report' => [
                'side_effects' => self::EMAIL_NOTE . ' A connected calendar removes the event.' . self::order_note($row),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_rules(array $params): array
    {
        self::refuse_unknown($params, ['product_id', 'rules'], 'bookings.set-availability-rules');
        $product = self::bookable($params['product_id'] ?? null);

        $given = $params['rules'] ?? null;
        if (! is_array($given) || ! array_is_list($given)) {
            self::refuse('invalid_params', 'rules must be a list of availability rules ([] clears them).');
        }
        if (count($given) > self::MAX_RULES) {
            self::refuse('invalid_params', 'At most ' . self::MAX_RULES . ' rules.');
        }
        $rules = [];
        foreach ($given as $i => $rule) {
            $rules[] = self::rule($rule, (int) $i);
        }

        return [
            'body'   => [ 'product_id' => $product->get_id(), 'rules' => $rules ],
            'report' => [ 'previous_rules' => self::rules_of($product->get_id()) ],
        ];
    }

    /**
     * One availability rule in the shape Bookings' product screen saves it
     * (WC_Bookings_Admin::get_posted_availability()).
     *
     * @return array<string, mixed>
     */
    private static function rule($rule, int $i): array
    {
        $where = "rules[{$i}]";
        if (! is_array($rule)) {
            self::refuse('invalid_params', "{$where} must be an object.");
        }
        self::refuse_unknown($rule, ['type', 'bookable', 'priority', 'from', 'to', 'from_date', 'to_date'], $where);

        $type = $rule['type'] ?? null;
        if (! is_string($type) || ! isset(self::RULE_TYPES[ $type ])) {
            self::refuse('invalid_params', "{$where}.type must be one of: " . implode(', ', array_keys(self::RULE_TYPES)) . '.');
        }
        $bookable = $rule['bookable'] ?? null;
        if (is_bool($bookable)) {
            $bookable = $bookable ? 'yes' : 'no';
        }
        if (! in_array($bookable, ['yes', 'no'], true)) {
            self::refuse('invalid_params', "{$where}.bookable must be yes or no.");
        }
        $priority = $rule['priority'] ?? 10;
        if (! is_int($priority) || $priority < 1 || $priority > 1000) {
            self::refuse('invalid_params', "{$where}.priority must be an integer from 1 to 1000 (lower wins).");
        }

        $out   = [ 'type' => $type, 'bookable' => $bookable, 'priority' => $priority ];
        $shape = self::RULE_TYPES[ $type ];
        $range = [ 'month' => [1, 12], 'week' => [1, 53], 'day' => [1, 7] ];
        foreach (['from', 'to'] as $end) {
            $value = $rule[ $end ] ?? null;
            if (isset($range[ $shape ])) {
                [ $lo, $hi ] = $range[ $shape ];
                $n           = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
                if ($n < $lo || $n > $hi) {
                    $what = 'day' === $shape ? ' (1 is Monday, 7 is Sunday)' : '';
                    self::refuse('invalid_params', "{$where}.{$end} must be a {$shape} number from {$lo} to {$hi}{$what}.");
                }
                $out[ $end ] = (string) $n;
            } elseif ('date' === $shape) {
                $out[ $end ] = self::day($value, "{$where}.{$end}");
            } else {
                if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    self::refuse('invalid_params', "{$where}.{$end} must be a time, \"HH:MM\".");
                }
                $out[ $end ] = $value;
            }
        }
        if ('date' === $shape && $out['from'] > $out['to']) {
            self::refuse('invalid_params', "{$where}.from must not be after {$where}.to.");
        }
        if ('time_dates' === $shape) {
            $out['from_date'] = self::day($rule['from_date'] ?? null, "{$where}.from_date");
            $out['to_date']   = self::day($rule['to_date'] ?? null, "{$where}.to_date");
            if ($out['from_date'] > $out['to_date']) {
                self::refuse('invalid_params', "{$where}.from_date must not be after {$where}.to_date.");
            }
        } elseif (isset($rule['from_date']) || isset($rule['to_date'])) {
            self::refuse('invalid_params', "{$where}: from_date and to_date belong to time:range and custom:daterange rules only.");
        }
        return $out;
    }

    // ------------------------------------------------------------- views

    /** @return array<string, mixed> */
    private static function summary(array $row): array
    {
        $meta = $row['meta'];
        return [
            'id'          => $row['id'],
            'status'      => $row['status'],
            'product_id'  => (int) $meta['_booking_product_id'],
            'resource_id' => (int) $meta['_booking_resource_id'],
            'customer_id' => (int) $meta['_booking_customer_id'],
            'order_id'    => $row['order_id'],
            'start'       => self::stored_time($meta['_booking_start']),
            'end'         => self::stored_time($meta['_booking_end']),
            'all_day'     => (bool) $meta['_booking_all_day'],
            'cost'        => (string) $meta['_booking_cost'],
        ];
    }

    /** @return array<string, mixed> */
    private static function view(array $row): array
    {
        $meta    = $row['meta'];
        $product = wc_get_product((int) $meta['_booking_product_id']);
        $persons = [];
        foreach ((array) ($meta['_booking_persons'] ?: []) as $type_id => $count) {
            $persons[ (string) $type_id ] = (int) $count;
        }
        $cancelled = (int) $meta['_booking_date_cancelled'];

        return self::summary($row) + [
            'product_name'      => $product ? $product->get_name() : null,
            'order_item_id'     => (int) $meta['_booking_order_item_id'],
            'persons'           => (object) $persons,
            'note'              => (string) $meta['_booking_note'],
            'attendance_status' => (string) $meta['_booking_attendance_status'],
            'local_timezone'    => (string) $meta['_local_timezone'],
            'timezone'          => self::timezone(),
            'date_created_gmt'  => $row['date_created'],
            'date_cancelled'    => $cancelled > 0 ? gmdate('Y-m-d H:i:s', $cancelled) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function product_view(\WC_Product $product): array
    {
        $out = [
            'id'     => $product->get_id(),
            'name'   => $product->get_name(),
            'status' => $product->get_status(),
        ];
        foreach (self::PRODUCT_META as $name => $key) {
            // Bookings registers these as internal meta keys, which
            // get_meta() hides, so they are read as stored.
            $value = get_post_meta($product->get_id(), $key, true);
            if (in_array($name, self::INT_FIELDS, true)) {
                $value = (int) $value;
            } elseif (in_array($name, self::BOOL_FIELDS, true)) {
                $value = wc_string_to_bool($value);
            } else {
                $value = (string) $value;
            }
            $out[ $name ] = $value;
        }
        $out['resource_ids'] = Wc_Booking_Snapshot::resource_ids($product);
        $out['rules']        = count(self::rules_of($product->get_id()));
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rules_of(int $product_id): array
    {
        $rules = get_post_meta($product_id, Wc_Booking_Snapshot::RULES_META, true);
        return is_array($rules) ? array_values($rules) : [];
    }

    private static function order_note(array $row): string
    {
        return $row['order_id'] > 0
            ? " Bookings may move order {$row['order_id']}'s status with it (a rollback puts that status back, which WooCommerce may email about)."
            : '';
    }

    /** A stored 'YmdHis' local time as 'Y-m-d H:i:s', or null. */
    private static function stored_time($value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /** A Bookings timestamp (local wall-clock time) as 'Y-m-d H:i:s'. */
    private static function local(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function timezone(): string
    {
        return function_exists('wc_booking_get_timezone_string') ? (string) wc_booking_get_timezone_string() : wp_timezone_string();
    }

    // -------------------------------------------------------- validation

    /** @return array{id: int, status: string, order_id: int, author: int, date_created: string, meta: array<string, mixed>} */
    private static function existing(array $params): array
    {
        $raw = $params['id'] ?? null;
        $id  = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        if ($id <= 0) {
            self::refuse('invalid_params', 'id must be a positive integer booking id.');
        }
        $row = Wc_Booking_Snapshot::row($id);
        if (null === $row) {
            self::refuse('unknown_booking', "No booking has id {$id}. List bookings with bookings.list.", [ 'id' => $id ]);
        }
        return $row;
    }

    private static function bookable($raw): \WC_Product
    {
        $id = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        if ($id <= 0) {
            self::refuse('invalid_params', 'product_id must be a positive integer product id.');
        }
        $product = Wc_Booking_Snapshot::product($id);
        if (null === $product) {
            self::refuse('not_a_bookable_product', "Product {$id} is not a bookable product. List them with bookings.products.", [ 'product_id' => $id ]);
        }
        return $product;
    }

    private static function resource(\WC_Product $product, $raw): int
    {
        $id      = self::positive($raw, 'resource_id');
        $offered = Wc_Booking_Snapshot::resource_ids($product);
        if (! in_array($id, $offered, true)) {
            self::refuse('invalid_params', "Product {$product->get_id()} has no resource {$id}.", [ 'resource_ids' => $offered ]);
        }
        return $id;
    }

    private static function day($value, string $name): string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            self::refuse('invalid_params', "{$name} must be a date, \"YYYY-MM-DD\".");
        }
        return $value;
    }

    private static function note(array $params): string
    {
        $note = $params['note'] ?? '';
        if (! is_string($note)) {
            self::refuse('invalid_params', 'note must be a string.');
        }
        return sanitize_textarea_field($note);
    }

    private static function positive($value, string $name): int
    {
        $n = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($n < 1) {
            self::refuse('invalid_params', "{$name} must be a positive integer.");
        }
        return $n;
    }

    /** @param array<string, mixed> $data */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s) for ' . $where . ': ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
