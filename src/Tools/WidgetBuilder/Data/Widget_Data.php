<?php

namespace WPMCP\Tools\WidgetBuilder\Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Data sources for the custom widget builder's data controls (issue #296).
 *
 * A data control's {{name}} placeholder renders markup built here from a
 * data source instead of a static setting. The dynamic widget
 * (Widget_Renderer) and the compiled class (Widget_Compiler) both call
 * render(), so the two forms of one spec read the same data the same way.
 * render() is the single, output-ready path: every value is escaped here
 * (esc_html for text, esc_url for links, wp_kses_post around core's menu
 * markup), and a query that fails validation, a missing plugin or any error
 * renders an empty string rather than a warning on the page.
 *
 * The query is data in the spec, validated by validate_query() on write and
 * again on every render, because a stored spec may predate these rules.
 */
class Widget_Data
{
    public const MAX_ITEMS     = 50;
    public const DEFAULT_COUNT = 5;
    private const MAX_TERMS    = 20;

    /** Query keys each data control accepts. */
    public const QUERY_KEYS = [
        'query_posts'    => ['post_type', 'taxonomy', 'terms', 'count', 'orderby', 'order'],
        'query_products' => ['category', 'count', 'orderby', 'order'],
        'query_terms'    => ['taxonomy', 'count', 'orderby', 'order', 'hide_empty'],
        'menu'           => [],
        'breadcrumbs'    => ['home'],
        'cart'           => [],
        'remote_json'    => ['url', 'path', 'field', 'count', 'cache'],
    ];

    /** orderby values per source; nothing else reaches a query. */
    public const ORDERBY = [
        'query_posts'    => ['date', 'title', 'modified', 'menu_order', 'rand', 'comment_count'],
        'query_products' => ['date', 'title', 'price', 'popularity', 'rating', 'menu_order', 'rand'],
        'query_terms'    => ['name', 'slug', 'count', 'term_order', 'term_id'],
    ];

    /**
     * Why a data control's query is invalid, or null when it is valid.
     *
     * @param mixed $query
     * @return array{0:string,1:string}|null [field suffix, message].
     */
    public static function validate_query(string $type, $query): ?array
    {
        if (! isset(self::QUERY_KEYS[ $type ])) {
            return ['', 'Not a data control.'];
        }
        $query = $query ?? [];
        if (! is_array($query)) {
            return ['', 'The query must be an object.'];
        }

        foreach ($query as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::QUERY_KEYS[ $type ], true)) {
                $keys = self::QUERY_KEYS[ $type ];
                return ['.' . (is_scalar($key) ? (string) $key : '?'), sprintf(
                    'Unknown query key; %s accepts %s.',
                    $type,
                    [] === $keys ? 'none' : implode(', ', $keys)
                )];
            }
            $problem = self::invalid_value($type, $key, $value);
            if (null !== $problem) {
                return ['.' . $key, $problem];
            }
        }

        if (isset($query['terms']) && ! isset($query['taxonomy'])) {
            return ['.terms', 'terms needs a taxonomy.'];
        }
        if ('remote_json' === $type && ! isset($query['url'])) {
            return ['.url', 'remote_json needs an https url.'];
        }
        return null;
    }

    /** @param mixed $value */
    private static function invalid_value(string $type, string $key, $value): ?string
    {
        switch ($key) {
            case 'post_type':
            case 'taxonomy':
                return is_string($value) && 1 === preg_match('/^[a-z0-9_\-]{1,32}$/', $value) ? null : 'Use a registered name (a-z, 0-9, "_", "-").';
            case 'terms':
            case 'category':
                if (! is_array($value) || count($value) > self::MAX_TERMS) {
                    return sprintf('Use a list of at most %d term slugs.', self::MAX_TERMS);
                }
                foreach ($value as $slug) {
                    if (! is_string($slug) || 1 !== preg_match('/^[A-Za-z0-9_\-%]{1,200}$/', $slug)) {
                        return 'Each term must be a slug.';
                    }
                }
                return null;
            case 'count':
                return is_int($value) && $value >= 1 && $value <= self::MAX_ITEMS ? null : sprintf('Use a whole number from 1 to %d.', self::MAX_ITEMS);
            case 'orderby':
                return is_string($value) && in_array($value, self::ORDERBY[ $type ] ?? [], true) ? null : sprintf('Use one of: %s.', implode(', ', self::ORDERBY[ $type ] ?? []));
            case 'order':
                return is_string($value) && in_array(strtoupper($value), ['ASC', 'DESC'], true) ? null : 'Use ASC or DESC.';
            case 'hide_empty':
                return is_bool($value) ? null : 'Use true or false.';
            case 'home':
                return is_string($value) && strlen($value) <= 200 ? null : 'Use a short label.';
            case 'url':
                return self::invalid_url($value);
            case 'path':
                return is_string($value) && strlen($value) <= 200 && 1 === preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $value) ? null : 'Use dot-separated keys, e.g. data.items.';
            case 'field':
                return is_string($value) && 1 === preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) ? null : 'Use a single key name.';
            case 'cache':
                return is_int($value) && $value >= 60 && $value <= DAY_IN_SECONDS ? null : sprintf('Use seconds from 60 to %d.', DAY_IN_SECONDS);
        }
        return 'Unknown query key.';
    }

    /**
     * URL shape only. The host allowlist and the private-address check run
     * when the data is fetched, because the allowlist is a filter that can
     * change after the spec is written.
     *
     * @param mixed $value
     */
    private static function invalid_url($value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return 'Use an https URL.';
        }
        $parts = wp_parse_url($value);
        if (! is_array($parts) || empty($parts['host']) || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            return 'Use an https URL.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not embed credentials.';
        }
        if (isset($parts['port']) && 443 !== (int) $parts['port']) {
            return 'The URL must use the default https port.';
        }
        return null;
    }

    /**
     * Output-ready markup for one data control. $value is the control's
     * current setting (the item count for the query sources, the menu for
     * menu); everything else comes from the validated query.
     *
     * @param mixed $query
     */
    public static function render(string $type, $query, string $value): string
    {
        $query = is_array($query) ? $query : [];
        if (null !== self::validate_query($type, $query)) {
            return '';
        }

        try {
            switch ($type) {
                case 'query_posts':
                    return self::posts($query, self::count($query, $value));
                case 'query_products':
                    return self::products($query, self::count($query, $value));
                case 'query_terms':
                    return self::terms($query, self::count($query, $value));
                case 'menu':
                    return self::menu($value);
                case 'breadcrumbs':
                    return self::breadcrumbs($query);
                case 'cart':
                    return self::cart();
                case 'remote_json':
                    return self::remote_json($query);
            }
        } catch (\Throwable $e) {
            // A data source must never take the page down with it.
            return '';
        }
        return '';
    }

    /** The setting when it is a positive number, else query.count, else the default; capped. */
    private static function count(array $query, string $value): int
    {
        $count = ctype_digit(trim($value)) && (int) $value > 0 ? (int) $value : (int) ($query['count'] ?? self::DEFAULT_COUNT);
        return max(1, min(self::MAX_ITEMS, $count));
    }

    private static function order(array $query): string
    {
        return strtoupper((string) ($query['order'] ?? 'DESC'));
    }

    /** @param array<int,array{0:string,1:string,2?:string}> $items [url, text, extra html] */
    private static function list_markup(string $class, array $items): string
    {
        if ([] === $items) {
            return '';
        }
        $out = '<ul class="wpmcp-data ' . esc_attr($class) . '">';
        foreach ($items as $item) {
            $text = '' !== $item[0]
                ? '<a href="' . esc_url($item[0]) . '">' . esc_html($item[1]) . '</a>'
                : esc_html($item[1]);
            $out .= '<li>' . $text . ($item[2] ?? '') . '</li>';
        }
        return $out . '</ul>';
    }

    private static function posts(array $query, int $count): string
    {
        $post_type = (string) ($query['post_type'] ?? 'post');
        if (! post_type_exists($post_type) || ! is_post_type_viewable($post_type)) {
            return '';
        }
        $args = [
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'has_password'        => false,
            'posts_per_page'      => $count,
            'orderby'             => (string) ($query['orderby'] ?? 'date'),
            'order'               => self::order($query),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];
        if (isset($query['taxonomy'])) {
            $taxonomy = (string) $query['taxonomy'];
            if (! taxonomy_exists($taxonomy) || ! is_taxonomy_viewable($taxonomy)) {
                return '';
            }
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by posts_per_page <= 50.
                isset($query['terms'])
                    ? ['taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array_values((array) $query['terms'])]
                    : ['taxonomy' => $taxonomy, 'operator' => 'EXISTS'],
            ];
        }

        $items = [];
        foreach ((new \WP_Query($args))->posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = [(string) get_permalink($post), (string) $post->post_title];
            }
        }
        return self::list_markup('wpmcp-posts', $items);
    }

    private static function products(array $query, int $count): string
    {
        if (! function_exists('wc_get_product') || ! post_type_exists('product')) {
            return '';
        }
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => $count,
            'order'          => self::order($query),
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ];
        $orderby = (string) ($query['orderby'] ?? 'date');
        $meta    = ['price' => '_price', 'popularity' => 'total_sales', 'rating' => '_wc_average_rating'];
        if (isset($meta[ $orderby ])) {
            $args['orderby']  = 'meta_value_num';
            $args['meta_key'] = $meta[ $orderby ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce's own sort keys, bounded by posts_per_page <= 50.
        } else {
            $args['orderby'] = $orderby;
        }
        $tax = [];
        if (isset($query['category'])) {
            $tax[] = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array_values((array) $query['category'])];
        }
        if (function_exists('wc_get_product_visibility_term_ids')) {
            $hidden = (int) (wc_get_product_visibility_term_ids()['exclude-from-catalog'] ?? 0);
            if ($hidden > 0) {
                $tax[] = ['taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => [$hidden], 'operator' => 'NOT IN'];
            }
        }
        if ([] !== $tax) {
            $args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by posts_per_page <= 50.
        }

        $items = [];
        foreach ((new \WP_Query($args))->posts as $id) {
            $product = wc_get_product((int) $id);
            if (! $product) {
                continue;
            }
            $price   = self::plain((string) $product->get_price_html());
            $items[] = [
                (string) $product->get_permalink(),
                (string) $product->get_name(),
                '' !== $price ? ' <span class="wpmcp-price">' . esc_html($price) . '</span>' : '',
            ];
        }
        return self::list_markup('wpmcp-products', $items);
    }

    private static function terms(array $query, int $count): string
    {
        $taxonomy = (string) ($query['taxonomy'] ?? 'category');
        if (! taxonomy_exists($taxonomy) || ! is_taxonomy_viewable($taxonomy)) {
            return '';
        }
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'number'     => $count,
            'orderby'    => (string) ($query['orderby'] ?? 'name'),
            'order'      => strtoupper((string) ($query['order'] ?? 'ASC')),
            'hide_empty' => (bool) ($query['hide_empty'] ?? true),
        ]);
        if (! is_array($terms)) {
            return '';
        }
        $items = [];
        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }
            $link    = get_term_link($term);
            $items[] = [is_wp_error($link) ? '' : (string) $link, (string) $term->name];
        }
        return self::list_markup('wpmcp-terms', $items);
    }

    /** $value names the menu: a theme location, or a menu slug, name or id. */
    private static function menu(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        $args = [
            'echo'            => false,
            'fallback_cb'     => false,
            'container'       => 'nav',
            'container_class' => 'wpmcp-data wpmcp-menu',
            'menu_class'      => 'menu',
            // No id on the <ul>: wp_nav_menu() would suffix a counter to it
            // on every repeat render of one menu.
            'items_wrap'      => '<ul class="%2$s">%3$s</ul>',
        ];
        if (isset(get_nav_menu_locations()[ $value ])) {
            $args['theme_location'] = $value;
        } else {
            $menu = wp_get_nav_menu_object(ctype_digit($value) ? (int) $value : $value);
            if (! $menu) {
                return '';
            }
            $args['menu'] = $menu;
        }
        // Item ids too: core hands each id out once per page, so a second
        // render of the same menu would come out different from the first.
        add_filter('nav_menu_item_id', '__return_empty_string', PHP_INT_MAX);
        try {
            $html = (string) wp_nav_menu($args);
        } finally {
            remove_filter('nav_menu_item_id', '__return_empty_string', PHP_INT_MAX);
        }
        return wp_kses_post($html);
    }

    private static function breadcrumbs(array $query): string
    {
        $home  = (string) ($query['home'] ?? __('Home', 'wpmcp'));
        $trail = [];
        $current = '';

        if (is_front_page()) {
            $current = $home;
        } else {
            $trail[] = [home_url('/'), $home];
            $object  = get_queried_object();
            if (is_singular() && $object instanceof \WP_Post) {
                foreach (array_reverse(get_post_ancestors($object)) as $ancestor) {
                    $trail[] = [(string) get_permalink($ancestor), (string) get_post_field('post_title', $ancestor)];
                }
                if ('post' === $object->post_type) {
                    $category = get_the_category($object->ID)[0] ?? null;
                    if ($category instanceof \WP_Term) {
                        $trail = array_merge($trail, self::term_trail($category, true));
                    }
                } elseif (! is_post_type_hierarchical($object->post_type)) {
                    $archive = get_post_type_archive_link($object->post_type);
                    $type    = get_post_type_object($object->post_type);
                    if ($archive && $type) {
                        $trail[] = [(string) $archive, (string) $type->labels->name];
                    }
                }
                $current = (string) $object->post_title;
            } elseif ($object instanceof \WP_Term) {
                $trail   = array_merge($trail, self::term_trail($object, false));
                $current = (string) $object->name;
            } elseif (is_post_type_archive()) {
                $current = (string) post_type_archive_title('', false);
            } elseif (is_search()) {
                /* translators: %s: the search query. */
                $current = sprintf(__('Search: %s', 'wpmcp'), get_search_query(false));
            } elseif (is_404()) {
                $current = __('Not found', 'wpmcp');
            } elseif (is_home() && $object instanceof \WP_Post) {
                $current = (string) $object->post_title;
            } elseif (is_archive()) {
                $current = self::plain((string) get_the_archive_title());
            }
        }

        $out = '<nav class="wpmcp-data wpmcp-breadcrumbs" aria-label="Breadcrumb"><ol>';
        foreach ($trail as [$url, $text]) {
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($text) . '</a></li>';
        }
        if ('' !== $current) {
            $out .= '<li aria-current="page">' . esc_html($current) . '</li>';
        }
        return $out . '</ol></nav>';
    }

    /** @return array<int,array{0:string,1:string}> the term's ancestors, and the term itself when $self. */
    private static function term_trail(\WP_Term $term, bool $self): array
    {
        $trail = [];
        foreach (array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) as $ancestor_id) {
            $ancestor = get_term((int) $ancestor_id, $term->taxonomy);
            if ($ancestor instanceof \WP_Term) {
                $link    = get_term_link($ancestor);
                $trail[] = [is_wp_error($link) ? '' : (string) $link, (string) $ancestor->name];
            }
        }
        if ($self) {
            $link    = get_term_link($term);
            $trail[] = [is_wp_error($link) ? '' : (string) $link, (string) $term->name];
        }
        return $trail;
    }

    private static function cart(): string
    {
        if (! function_exists('WC') || ! function_exists('wc_price')) {
            return '';
        }
        $cart  = WC()->cart ?? null;
        $count = $cart ? (int) $cart->get_cart_contents_count() : 0;
        $total = self::plain($cart ? (string) $cart->get_cart_total() : (string) wc_price(0));

        return '<span class="wpmcp-data wpmcp-cart"><span class="wpmcp-cart-count">' . esc_html((string) $count)
            . '</span> <span class="wpmcp-cart-total">' . esc_html($total) . '</span></span>';
    }

    private static function remote_json(array $query): string
    {
        $data = Remote_Json::get((string) $query['url'], (int) ($query['cache'] ?? Remote_Json::DEFAULT_TTL));
        if (isset($query['path'])) {
            foreach (explode('.', (string) $query['path']) as $segment) {
                if (! is_array($data) || ! array_key_exists($segment, $data)) {
                    return '';
                }
                $data = $data[ $segment ];
            }
        }

        if (is_scalar($data)) {
            return esc_html(self::scalar($data));
        }
        if (! is_array($data) || ! array_is_list($data)) {
            return '';
        }

        $limit = (int) ($query['count'] ?? 10);
        $field = isset($query['field']) ? (string) $query['field'] : null;
        $items = [];
        foreach ($data as $item) {
            if (null !== $field) {
                $item = is_array($item) ? ($item[ $field ] ?? null) : null;
            }
            if (! is_scalar($item)) {
                continue;
            }
            $items[] = ['', self::scalar($item)];
            if (count($items) >= $limit) {
                break;
            }
        }
        return self::list_markup('wpmcp-json', $items);
    }

    /** @param bool|int|float|string $value */
    private static function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /** Markup reduced to plain text (prices, archive titles), entities decoded so esc_html encodes once. */
    private static function plain(string $html): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
    }
}
