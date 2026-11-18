<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit Cart for WooCommerce ops behind the cart.* rows (issue #832),
 * run in-process against the plugin's own storage:
 *
 *  - every drawer, coupon, upsell, reward and style setting lives in one
 *    option, fkcart_settings, which holds only what the site changed and is
 *    read over the plugin's built-in defaults (FKCart\Includes\Data). The
 *    defaults below are recorded from the free plugin (1.9.1.3);
 *  - the drawer's upsells are each cart item's own WooCommerce upsells or
 *    cross-sells (the upsell_type setting picks which), which the plugin's
 *    screen edits on the product, so add and remove go through the
 *    WooCommerce product CRUD.
 *
 * cart.update-settings merges only the named keys into the stored option,
 * refusing a key the plugin does not know and a value of the wrong shape,
 * then fires the plugin's own fkcart_settings_saved action. Every write is
 * snapshotted by woo-write first (an option snapshot of fkcart_settings, or
 * the product's post snapshot), so rollback-operation undoes it exactly.
 */
final class Cart_Ops
{
    public const OPTION = 'fkcart_settings';

    private const MAX_PER_PAGE     = 50;
    private const DEFAULT_PER_PAGE = 20;

    /** The product meta each upsell type is stored in. */
    private const TYPES = [ 'upsells' => '_upsell_ids', 'cross_sells' => '_crosssell_ids' ];

    /**
     * The plugin's defaults (FKCart\Includes\Data::get_default_settings(),
     * 1.9.1.3), by key: what the cart shows for a key the site never saved,
     * and the shape a write to that key must have.
     */
    private const DEFAULTS = [
        'enable_cart'                              => 0,
        'cart_display'                             => 'entire',
        'cart_icon_position'                       => 'bottom-right',
        'cart_style'                               => 'side-cart',
        'cart_icon_style'                          => 'style1',
        'hide_empty_cart'                          => false,
        'ajax_add_to_cart'                         => true,
        'enable_auto_open_cart'                    => true,
        'cart_heading'                             => 'Review Your Cart',
        'default_font'                             => '',
        'enable_coupon_box'                        => true,
        'coupon_display'                           => 'minimized',
        'coupon_placeholder_text'                  => 'Coupon Code',
        'coupon_heading'                           => 'Got a discount code?',
        'coupon_button_text'                       => 'Apply',
        'show_sub_total'                           => true,
        'you_save'                                 => true,
        'saving_text'                              => 'Save {{saving_percentage}}',
        'shipping_tax_calculation_text'            => 'Shipping & taxes may be re-calculated at checkout',
        'reset_to_variations'                      => 'Clear',
        'show_button_lock_icon'                    => true,
        'show_button_price'                        => false,
        'show_shop_continue_link'                  => true,
        'continue_shopping_text'                   => 'Continue Shopping',
        'checkout_button_text'                     => 'Checkout',
        'zero_state_title'                         => 'Your Cart is Empty',
        'zero_state_description'                   => 'Fill your cart with amazing items',
        'zero_state_btn_text'                      => 'Shop Now',
        'zero_state_link_behaviour'                => 'close_cart',
        'enable_upsells'                           => true,
        'upsell_style'                             => 'style3',
        'upsell_type'                              => 'both',
        'upsell_heading'                           => 'Frequently Bought Together',
        'upsell_max_count'                         => 5,
        'show_default_upsell'                      => false,
        'reward'                                   => [],
        'reward_title'                             => 'Congrats! You have unlocked all the rewards.',
        'reward_calculation_based'                 => 'subtotal',
        'calculation_on_cart_subtotal'             => true,
        'reward_progress_bar_style'                => 'classic',
        'reward_progress_bar_icon_color'           => '#353030',
        'reward_progress_bar_active_icon_color'    => '#ffffff',
        'reward_progress_bar_bg_color_active_icon' => '#2DA815',
        'reward_progress_bar_active_color'         => '#2DA815',
        'reward_progress_bar_enable_animation'     => 'true',
        'css_desktop_width'                        => 420,
        'css_mobile_width'                         => 100,
        'css_bg_color'                             => '#ffffff',
        'css_border_color'                         => '#DEDFEA',
        'css_accent_color'                         => '#0170b9',
        'css_button_bg_color'                      => '#0170b9',
        'css_button_text_color'                    => '#ffffff',
        'css_primary_text_color'                   => '#353030',
        'css_secondary_text_color'                 => '#82838E',
        'strike_through_price_color'               => '#E15334',
        'saving_text_price_color'                  => '#5BA238',
        'coupon_text_price_color'                  => '#5BA238',
        'css_upsell_bg_color'                      => '#f9f9ff',
        'css_animation_speed'                      => 400,
        'css_border_radius'                        => 8,
        'css_progressbar_active_color'             => '#0170b9',
        'css_icon_color'                           => '#353030',
        'css_icon_bg_color'                        => '#ffffff',
        'css_icon_count_bg_color'                  => '#cf2e2e',
        'css_icon_count_color'                     => '#ffffff',
        'icon_type'                                => 'style1',
        'floating_icon'                            => 'cart_1',
        'checkout_button_icon'                     => 'cart_1',
        'floating_icon_size'                       => '36',
        'css_floating_icon_border_radius'          => '50',
        'enable_menu'                              => false,
        'cart_icon'                                => 'cart_1',
        'display_menu_product_count'               => true,
        'display_menu_total'                       => true,
        'cart_append_menu'                         => '',
        'continue_shopping_behaviour'              => 'close_cart',
        'cart_menu_icon_size'                      => '35',
        'cart_menu_text_size'                      => '16',
        'smart_buttons'                            => false,
        'enable_strike_through_discounted_price'   => false,
        'enable_special_addon'                     => false,
        'preselect_special_addon'                  => true,
        'special_addon_selection_type'             => 'toggle',
        'special_addon_product'                    => [],
        'special_addon_heading'                    => 'Shipping protection',
        'special_addon_desc'                       => 'Protect your order from damage or theft during shipping.',
        'enable_special_addon_image'               => true,
        'special_addon_image_type'                 => 'product',
        'special_addon_custom_image'               => '',
        'special_addon_image_size'                 => '48',
        'special_addon_toggle_color'               => '#2DA815',
        'special_addon_bg_color'                   => '#f9f9ff',
        'special_addon_heading_color'              => '#353030',
    ];

    /** Groups of the settings read, by key prefix; the first match wins. */
    private const GROUPS = [
        'upsell'              => 'upsells',
        'show_default_upsell' => 'upsells',
        'css_upsell'          => 'upsells',
        'reward'              => 'rewards',
        'calculation_on_cart' => 'rewards',
        'coupon'              => 'coupon',
        'enable_coupon'       => 'coupon',
        'special_addon'       => 'special_addon',
        'enable_special'      => 'special_addon',
        'preselect_special'   => 'special_addon',
        'css_'                => 'style',
        'icon_type'           => 'style',
        'floating_icon'       => 'style',
        'checkout_button_icon' => 'style',
        'strike_through'      => 'style',
        'saving_text_price'   => 'style',
        'default_font'        => 'style',
        'enable_menu'         => 'menu',
        'cart_icon_position'  => 'drawer',
        'cart_icon_style'     => 'drawer',
        'cart_icon'           => 'menu',
        'display_menu'        => 'menu',
        'cart_append_menu'    => 'menu',
        'cart_menu'           => 'menu',
        'language'            => 'translations',
    ];

    /** @var bool|null Test seam for the plugin's presence; null asks the site. */
    private static $active_for_tests = null;

    /** Whether FunnelKit Cart is loaded (its version constant, as the issue names). */
    public static function is_active(): bool
    {
        if (null !== self::$active_for_tests) {
            return self::$active_for_tests;
        }
        return defined('FKCART_VERSION');
    }

    /** Test seam: force the plugin present (true), absent (false) or real (null). */
    public static function set_active_for_tests(?bool $active): void
    {
        self::$active_for_tests = $active;
    }

    // ------------------------------------------------------------- reads

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            switch ($handler) {
                case 'fkcart_settings':
                    self::refuse_unknown($params, [], 'cart.settings');
                    return [ 'status' => 200, 'body' => self::settings_view() ];
                case 'fkcart_upsells':
                    return [ 'status' => 200, 'body' => self::upsells($params) ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No cart read named \"{$handler}\".");
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string, mixed> */
    private static function settings_view(): array
    {
        $stored   = self::stored();
        $settings = array_merge(self::DEFAULTS, $stored);
        $view     = [
            'enabled'    => true === self::truthy($settings['enable_cart']),
            'version'    => defined('FKCART_VERSION') ? (string) constant('FKCART_VERSION') : null,
            // Upsells and rewards render only with the plugin's paid add-on.
            'pro_active' => class_exists('\FKCart\Pro\Upsells') || class_exists('\FKCart\Pro\Rewards'),
            'drawer'     => [],
            'coupon'     => [],
            'upsells'    => [],
            'rewards'    => [],
            'special_addon' => [],
            'menu'       => [],
            'style'      => [],
        ];
        foreach ($settings as $key => $value) {
            $view[ self::group((string) $key) ][ $key ] = $value;
        }
        $view['customized'] = array_values(array_map('strval', array_keys($stored)));
        return $view;
    }

    private static function group(string $key): string
    {
        foreach (self::GROUPS as $prefix => $group) {
            if (str_starts_with($key, $prefix)) {
                return $group;
            }
        }
        return 'drawer';
    }

    /** @return array<string, mixed> */
    private static function upsells(array $params): array
    {
        self::refuse_unknown($params, [ 'page', 'per_page', 'search', 'all' ], 'cart.upsells');
        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));
        $all      = true === ($params['all'] ?? false);

        $query = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if (isset($params['search']) && is_scalar($params['search']) && '' !== (string) $params['search']) {
            $query['s'] = sanitize_text_field((string) $params['search']);
        }
        if (! $all) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the plugin's own upsell storage; bounded by per_page.
            $query['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => '_upsell_ids', 'value' => [ '', 'a:0:{}' ], 'compare' => 'NOT IN' ],
                [ 'key' => '_crosssell_ids', 'value' => [ '', 'a:0:{}' ], 'compare' => 'NOT IN' ],
            ];
        }
        $found = new \WP_Query($query);

        $products = [];
        foreach ($found->posts as $id) {
            $product = wc_get_product((int) $id);
            if (! $product instanceof \WC_Product) {
                continue;
            }
            $products[] = [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'upsells'     => self::named($product->get_upsell_ids()),
                'cross_sells' => self::named($product->get_cross_sell_ids()),
            ];
        }

        $settings = array_merge(self::DEFAULTS, self::stored());
        return [
            'enable_upsells'   => true === self::truthy($settings['enable_upsells']),
            'upsell_type'      => $settings['upsell_type'],
            'upsell_max_count' => $settings['upsell_max_count'],
            'products'         => $products,
            'total'            => (int) $found->found_posts,
            'page'             => $page,
            'per_page'         => $per_page,
        ];
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, name: string}>
     */
    private static function named(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product instanceof \WC_Product) {
                $out[] = [ 'id' => $product->get_id(), 'name' => $product->get_name() ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ writes

    /**
     * Validate a write before anything is snapshotted or changed.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        unset($confirm);
        try {
            switch ($handler) {
                case 'fkcart_update_settings':
                    return [ 'body' => [ 'settings' => self::prepare_settings($params) ] ];
                case 'fkcart_add_upsell':
                    return [ 'body' => self::prepare_upsell($params, 'add') ];
                case 'fkcart_remove_upsell':
                    return [ 'body' => self::prepare_upsell($params, 'remove') ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No cart write named \"{$handler}\".");
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the snapshot.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        if ('fkcart_update_settings' === $handler) {
            $changes = (array) $plan['settings'];
            $data    = array_merge(self::stored(), $changes);
            update_option(self::OPTION, $data, false);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing FunnelKit Cart's own action, as its settings save does after writing the option; wpmcp adds no callback to it.
            do_action('fkcart_settings_saved', $data);
            return [ 'status' => 200, 'body' => [ 'changed' => array_keys($changes), 'settings' => $changes ] ];
        }

        $product = wc_get_product($id);
        if (! $product instanceof \WC_Product) {
            return [ 'status' => 404, 'body' => [ 'code' => 'not_found', 'message' => "Product {$id} is gone." ] ];
        }
        $current = self::ids_of($product, (string) $plan['type']);
        $next    = 'add' === $plan['action']
            ? array_values(array_unique(array_merge($current, $plan['ids'])))
            : array_values(array_diff($current, $plan['ids']));
        if ('upsells' === $plan['type']) {
            $product->set_upsell_ids($next);
        } else {
            $product->set_cross_sell_ids($next);
        }
        $product->save();

        $changed = 'add' === $plan['action']
            ? [ 'added' => array_values(array_diff($next, $current)) ]
            : [ 'removed' => array_values(array_intersect($current, $plan['ids'])) ];

        return [ 'status' => 200, 'body' => [ 'product_id' => $id, 'type' => $plan['type'] ] + $changed + [ $plan['type'] => self::named($next) ] ];
    }

    /** @return int[] */
    private static function ids_of(\WC_Product $product, string $type): array
    {
        $ids = 'upsells' === $type ? $product->get_upsell_ids() : $product->get_cross_sell_ids();
        return array_map('intval', $ids);
    }

    /**
     * The changes cart.update-settings may make: known keys only, each value
     * in the shape the plugin stores for that key.
     *
     * @return array<string, mixed>
     */
    private static function prepare_settings(array $params): array
    {
        self::refuse_unknown($params, [ 'settings' ], 'cart.update-settings');
        $settings = $params['settings'] ?? null;
        if (! is_array($settings) || [] === $settings || array_is_list($settings)) {
            self::refuse('invalid_params', 'settings must be a non-empty object of setting keys and values. Read cart.settings for the keys.');
        }

        $stored  = self::stored();
        $unknown = [];
        $changes = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (! array_key_exists($key, self::DEFAULTS) && ! array_key_exists($key, $stored)) {
                $unknown[] = $key;
                continue;
            }
            $shape           = array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[ $key ] : $stored[ $key ];
            $changes[ $key ] = self::coerce($key, $value, $shape);
        }
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'FunnelKit Cart has no setting named ' . implode(', ', $unknown) . '. Read cart.settings for the keys.',
                [ 'unknown' => $unknown ]
            );
        }
        return $changes;
    }

    /**
     * One value in the shape of the key's default (or stored value).
     *
     * @param mixed $value
     * @param mixed $shape
     * @return mixed
     */
    private static function coerce(string $key, $value, $shape)
    {
        if (is_array($shape)) {
            if (! is_array($value)) {
                self::refuse('invalid_params', "Setting {$key} takes a list or object.", [ 'key' => $key ]);
            }
            return self::clean_tree($key, $value, 0);
        }
        if (! is_scalar($value) && null !== $value) {
            self::refuse('invalid_params', "Setting {$key} takes a single value.", [ 'key' => $key ]);
        }
        if (is_bool($shape)) {
            $bool = self::truthy($value);
            if (null === $bool) {
                self::refuse('invalid_params', "Setting {$key} takes true or false.", [ 'key' => $key ]);
            }
            return $bool;
        }
        if (is_int($shape)) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                self::refuse('invalid_params', "Setting {$key} takes a whole number.", [ 'key' => $key ]);
            }
            return (int) $value;
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        if (str_ends_with($key, '_color')) {
            $color = sanitize_hex_color($text);
            if (null === $color || '' === $color) {
                self::refuse('invalid_params', "Setting {$key} takes a hex colour such as #0170b9.", [ 'key' => $key ]);
            }
            return $color;
        }
        return sanitize_text_field($text);
    }

    /**
     * Rows such as rewards or translations: nested lists and objects of
     * plain values, text sanitized, at most three levels deep.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function clean_tree(string $key, array $value, int $depth): array
    {
        if ($depth > 2) {
            self::refuse('invalid_params', "Setting {$key} is nested too deeply.", [ 'key' => $key ]);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $k = is_int($k) ? $k : sanitize_key((string) $k);
            if (is_array($v)) {
                $out[ $k ] = self::clean_tree($key, $v, $depth + 1);
            } elseif (is_string($v)) {
                $out[ $k ] = sanitize_text_field($v);
            } elseif (is_scalar($v) || null === $v) {
                $out[ $k ] = $v;
            } else {
                self::refuse('invalid_params', "Setting {$key} holds a value that is not plain data.", [ 'key' => $key ]);
            }
        }
        return $out;
    }

    /**
     * @return array{action: string, type: string, ids: int[]}
     */
    private static function prepare_upsell(array $params, string $action): array
    {
        $op = 'add' === $action ? 'cart.add-upsell' : 'cart.remove-upsell';
        self::refuse_unknown($params, [ 'product_id', 'product_ids', 'type' ], $op);

        $type = (string) ($params['type'] ?? 'upsells');
        if (! isset(self::TYPES[ $type ])) {
            self::refuse('invalid_params', 'type must be upsells or cross_sells.');
        }
        $target = self::positive($params['product_id'] ?? null, 'product_id');
        $product = wc_get_product($target);
        if (! $product instanceof \WC_Product || 'product' !== get_post_type($target)) {
            self::refuse('invalid_params', "Product {$target} is not a WooCommerce product.");
        }

        $given = $params['product_ids'] ?? null;
        if (! is_array($given) || [] === $given) {
            self::refuse('invalid_params', 'product_ids must be a non-empty list of product ids.');
        }
        $ids = [];
        foreach ($given as $id) {
            $ids[] = self::positive($id, 'product_ids');
        }
        $ids = array_values(array_unique($ids));

        if ('add' === $action) {
            foreach ($ids as $id) {
                if ($id === $target) {
                    self::refuse('invalid_params', 'A product cannot be its own upsell.');
                }
                if ('product' !== get_post_type($id)) {
                    self::refuse('invalid_params', "Product {$id} is not a WooCommerce product.");
                }
            }
        } else {
            $missing = array_values(array_diff($ids, self::ids_of($product, $type)));
            if ([] !== $missing) {
                self::refuse(
                    'invalid_params',
                    'Not in this product\'s ' . $type . ': ' . implode(', ', $missing) . '. Read cart.upsells for what it has.',
                    [ 'missing' => $missing ]
                );
            }
        }

        return [ 'action' => $action, 'type' => $type, 'ids' => $ids ];
    }

    // ----------------------------------------------------------- helpers

    /**
     * The plugin's own reading of a flag: true, 1, "1" and "true" are on.
     * Null for a value that is neither.
     *
     * @param mixed $value
     */
    private static function truthy($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [ 1, '1', 'true', 'yes', 'on' ], true)) {
            return true;
        }
        if (in_array($value, [ 0, '0', 'false', 'no', 'off', '' ], true)) {
            return false;
        }
        return null;
    }

    /** @param mixed $value */
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

    /** @param string[] $allowed */
    private static function refuse_unknown(array $given, array $allowed, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s) for ' . $where . ': ' . implode(', ', $unknown) . '. Accepted: ' . ([] === $allowed ? 'none' : implode(', ', $allowed)) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
