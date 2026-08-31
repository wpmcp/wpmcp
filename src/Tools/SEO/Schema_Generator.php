<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds schema.org JSON-LD structures from a post's own data (title,
 * excerpt, dates, author, featured image, permalink) plus, for the commerce
 * and place types, the WooCommerce product record and the site profile
 * option. Pure data assembly: no postmeta writes, no output buffering, no
 * theme hooks. The tool layer decides exposure and tiering; this class only
 * knows how to shape the graph.
 *
 * Every string that lands in the graph comes from the raw post record rather
 * than the display helpers: get_the_title() runs the `the_title` filter,
 * which prepends "Private: " / "Protected: " and lets any theme filter inject
 * markup into what is meant to be machine-read output.
 *
 * TODO(#67): merge-awareness: when the active SEO plugin already emits a
 * graph (Yoast/RankMath both do), report that in the proposal so the agent
 * does not double-emit conflicting JSON-LD.
 */
class Schema_Generator
{
    public const SUPPORTED_TYPES = ['Article', 'WebPage', 'LocalBusiness', 'Product'];

    /**
     * Option holding the site's business profile for LocalBusiness output.
     * Absent keys are simply omitted from the graph rather than guessed.
     *
     * Recognised keys: name, url, telephone, priceRange, openingHours (a
     * string or a list of strings), street_address, locality, region,
     * postal_code, country.
     *
     * Nothing in this plugin writes the option yet: it is the agent-owned
     * surface a site operator or an agent sets through the generic option
     * tools, and where it is silent the LocalBusiness branch falls back to
     * the site name/URL and the WooCommerce store settings. A dedicated
     * read/write tool for it is tracked on issue #67.
     */
    public const SITE_PROFILE_OPTION = 'wpmcp_site_profile';

    /**
     * Build the JSON-LD array for one post and schema type.
     *
     * @throws \InvalidArgumentException on an unknown type or missing post.
     */
    public static function generate(int $post_id, string $type): array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException('Post not found: ' . (int) $post_id);
        }

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported schema type: ' . esc_html($type) . '. Supported: '
                . esc_html(implode(', ', self::SUPPORTED_TYPES))
            );
        }

        switch ($type) {
            case 'Article':
                return self::article($post);
            case 'LocalBusiness':
                return self::local_business($post);
            case 'Product':
                return self::product($post);
            default:
                return self::web_page($post);
        }
    }

    private static function article(\WP_Post $post): array
    {
        $out = array_merge(self::common($post, 'Article'), [
            'headline'  => (string) $post->post_title,
            'publisher' => self::organization(),
        ]);

        $out = self::with_dates($out, $post);

        $author = get_userdata((int) $post->post_author);
        $out['author'] = $author instanceof \WP_User
            ? ['@type' => 'Person', 'name' => $author->display_name]
            : self::organization();

        return $out;
    }

    private static function web_page(\WP_Post $post): array
    {
        return self::with_dates(
            array_merge(self::common($post, 'WebPage'), [
                'name'      => (string) $post->post_title,
                'publisher' => self::organization(),
            ]),
            $post
        );
    }

    /**
     * LocalBusiness describes the business, not the page it is rendered on.
     * The post only supplies `mainEntityOfPage`: taking `name` and `url` from
     * the post record would emit `"name": "About"` for an About page, which
     * describes a document rather than a business.
     *
     * Facts the post record cannot carry (address, phone, opening hours) come
     * from the site profile option an agent fills in, and fall back to the
     * WooCommerce store settings when the profile is empty and the store is
     * configured, so the branch is reachable on a stock commerce site with no
     * profile written yet. Anything neither source supplies is omitted: a
     * partial LocalBusiness is still valid schema.org, an invented address is
     * not.
     */
    private static function local_business(\WP_Post $post): array
    {
        $profile = self::site_profile();

        $out = self::common($post, 'LocalBusiness');

        $name       = self::profile_string($profile, 'name');
        $out['name'] = '' !== $name ? $name : (string) get_bloginfo('name');

        $url        = self::profile_string($profile, 'url');
        $out['url'] = '' !== $url ? $url : (string) home_url('/');

        $store = self::woocommerce_store_fallbacks();

        foreach (['telephone', 'priceRange'] as $key) {
            $value = self::profile_string($profile, $key);
            if ('' === $value) {
                $value = (string) ($store[$key] ?? '');
            }
            if ('' !== $value) {
                $out[$key] = $value;
            }
        }

        $hours = self::opening_hours($profile);
        if ([] !== $hours) {
            $out['openingHours'] = $hours;
        }

        $address_map = [
            'street_address' => 'streetAddress',
            'locality'       => 'addressLocality',
            'region'         => 'addressRegion',
            'postal_code'    => 'postalCode',
            'country'        => 'addressCountry',
        ];
        $address = [];
        foreach ($address_map as $source => $target) {
            $value = self::profile_string($profile, $source);
            if ('' === $value) {
                $value = (string) ($store[$target] ?? '');
            }
            if ('' !== $value) {
                $address[$target] = $value;
            }
        }
        if ([] !== $address) {
            $out['address'] = array_merge(['@type' => 'PostalAddress'], $address);
        }

        return $out;
    }

    /** The site profile option, always as an array. */
    private static function site_profile(): array
    {
        $profile = get_option(self::SITE_PROFILE_OPTION, []);

        return is_array($profile) ? $profile : [];
    }

    /**
     * One profile value as a trimmed string. The option is agent-written, so
     * a value can be anything: casting an array with (string) emits the
     * literal "Array" into the graph plus a PHP warning, so non-scalars are
     * treated as absent.
     */
    private static function profile_string(array $profile, string $key): string
    {
        $value = $profile[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * openingHours accepts either a single specification or a list of them
     * ("Mo-Fr 09:00-17:00"). A scalar profile value becomes a one-item list;
     * a list keeps only its scalar entries.
     */
    private static function opening_hours(array $profile): array
    {
        $value = $profile['openingHours'] ?? $profile['opening_hours'] ?? null;

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return '' === $value ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! is_scalar($entry)) {
                continue;
            }
            $entry = trim((string) $entry);
            if ('' !== $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Address and phone from the WooCommerce store settings, used only where
     * the site profile is silent. These are plain options, so no WooCommerce
     * classes are touched and nothing breaks when the plugin is absent: the
     * options simply do not exist and every key stays empty.
     */
    private static function woocommerce_store_fallbacks(): array
    {
        $street = trim((string) get_option('woocommerce_store_address', ''));
        $line_2 = trim((string) get_option('woocommerce_store_address_2', ''));
        if ('' !== $line_2) {
            $street = '' === $street ? $line_2 : $street . ', ' . $line_2;
        }

        // WooCommerce stores country and region as one "US:CA" pair.
        $country = trim((string) get_option('woocommerce_default_country', ''));
        $region  = '';
        if (str_contains($country, ':')) {
            [$country, $region] = array_pad(explode(':', $country, 2), 2, '');
        }

        return [
            'streetAddress'   => $street,
            'addressLocality' => trim((string) get_option('woocommerce_store_city', '')),
            'addressRegion'   => $region,
            'postalCode'      => trim((string) get_option('woocommerce_store_postcode', '')),
            'addressCountry'  => $country,
            'telephone'       => trim((string) get_option('woocommerce_store_phone', '')),
        ];
    }

    /**
     * Product output is WooCommerce-aware when the post is a WooCommerce
     * product and the plugin is active, and degrades to the plain post
     * fields otherwise so the type is still usable on a non-commerce site.
     */
    private static function product(\WP_Post $post): array
    {
        $out = array_merge(self::common($post, 'Product'), [
            'name' => (string) $post->post_title,
        ]);

        if (! function_exists('wc_get_product') || 'product' !== $post->post_type) {
            return $out;
        }

        $product = wc_get_product($post->ID);
        if (! is_object($product) || ! method_exists($product, 'get_price')) {
            return $out;
        }

        $sku = (string) $product->get_sku();
        if ('' !== $sku) {
            $out['sku'] = $sku;
        }

        $price = (string) $product->get_price();
        if ('' !== $price) {
            $out['offers'] = [
                '@type'         => 'Offer',
                'price'         => $price,
                'priceCurrency' => function_exists('get_woocommerce_currency')
                    ? (string) get_woocommerce_currency()
                    : 'USD',
                'availability'  => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'url'           => (string) get_permalink($post),
            ];
        }

        return $out;
    }

    /**
     * datePublished / dateModified, never as a boolean.
     *
     * Drafts, pending posts and auto-drafts store post_date_gmt as
     * '0000-00-00 00:00:00', so the GMT accessors return false. Emitting that
     * would put `"datePublished": false` in the graph for exactly the
     * pre-publish case this proposal tool exists to serve, so the local date
     * is the fallback and the key is dropped when even that is unavailable.
     */
    private static function with_dates(array $out, \WP_Post $post): array
    {
        foreach (['date' => 'datePublished', 'modified' => 'dateModified'] as $field => $key) {
            $value = self::iso_date($post, $field);
            if (null !== $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function iso_date(\WP_Post $post, string $field): ?string
    {
        $gmt = 'date' === $field
            ? get_post_time('c', true, $post)
            : get_post_modified_time('c', true, $post);

        if (is_string($gmt) && '' !== $gmt) {
            return $gmt;
        }

        $local = get_post_datetime($post, $field, 'local');

        return $local instanceof \DateTimeImmutable ? $local->format('c') : null;
    }

    /**
     * Fields shared by every supported type: context, type, url,
     * mainEntityOfPage, description (the plugin-curated SEO description when
     * usable, else the excerpt) and primary image. All of those are
     * Thing-level in schema.org, so they are valid on every supported type.
     *
     * `publisher` is deliberately not here: schema.org scopes it to
     * CreativeWork, so it belongs to Article and WebPage only. Attaching it
     * to LocalBusiness or Product emits an out-of-domain property and makes
     * the graph invalid.
     */
    private static function common(\WP_Post $post, string $type): array
    {
        $permalink = (string) get_permalink($post);

        $out = [
            '@context'         => 'https://schema.org',
            '@type'            => $type,
            'url'              => $permalink,
            'mainEntityOfPage' => $permalink,
        ];

        $description = self::description($post);
        if ('' !== $description) {
            $out['description'] = $description;
        }

        $image = get_the_post_thumbnail_url($post, 'full');
        if (is_string($image) && '' !== $image) {
            $out['image'] = $image;
        }

        return $out;
    }

    /**
     * The SEO plugin's meta description wins over the raw excerpt because it
     * is the curated one, unless it is still an unrendered template string:
     * Yoast and RankMath store their defaults as '%%excerpt%%' and
     * '%sep% %sitename%', and emitting those literally is worse than the
     * excerpt they stand in for.
     */
    private static function description(\WP_Post $post): string
    {
        $description = '';
        if ('' !== SEO_Adapter::active_plugin()) {
            $description = (string) (SEO_Adapter::get_meta($post->ID)['description'] ?? '');
        }

        if ('' === $description || self::has_unrendered_variables($description)) {
            $description = (string) $post->post_excerpt;
        }

        return wp_strip_all_tags($description);
    }

    private static function has_unrendered_variables(string $value): bool
    {
        return str_contains($value, '%%') || 1 === preg_match('/%[a-z_]+%/i', $value);
    }

    private static function organization(): array
    {
        return [
            '@type' => 'Organization',
            'name'  => (string) get_bloginfo('name'),
            'url'   => (string) home_url('/'),
        ];
    }
}
