<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Plugin;
use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The shared, read-only planner behind plan-product-import and
 * apply-product-import.
 *
 * Every row is validated, matched to an existing product by SKU, and turned
 * into one of: create, update (with a field-level diff), skip (with a
 * reason) or error (with every problem found). Nothing is written: category
 * and attribute terms must already exist, image URLs are checked against
 * Remote_Image_Guard's pre-request rules but never fetched, and permission
 * is asked with Registrar::would_permit(), which writes no audit row.
 *
 * The plan hash covers the normalized rows, the resulting plan, and the
 * current state of every matched product (its fields, its variations and
 * its post_modified_gmt). apply-product-import recomputes the plan and
 * refuses when the hash differs, so what is written is exactly what was
 * approved against the store as it was.
 *
 * Update semantics are partial: only fields present in a row are compared
 * and written. categories, attributes and images replace the product's
 * whole list; variations are matched by SKU, then by attribute combination,
 * and existing variations the row does not mention are left alone.
 */
class Product_Import_Plan
{
    public const DEFAULT_MAX_ROWS = 100;

    public const DEFAULT_CONFIRM_THRESHOLD = 20;

    public const MAX_IMAGES = 20;

    public const MAX_VARIATIONS = 100;

    /** Postmeta naming the URL an imported image came from, so a re-run reuses it. */
    public const SOURCE_META = '_wpmcp_import_source_url';

    public const MODES = ['upsert', 'create', 'update'];

    private const ROW_KEYS = ['sku', 'name', 'type', 'regular_price', 'sale_price', 'stock', 'categories', 'attributes', 'variations', 'images', 'status'];

    private const VARIATION_KEYS = ['sku', 'attributes', 'regular_price', 'sale_price', 'stock', 'status'];

    private const PRODUCT_STATUSES = ['publish', 'draft', 'pending', 'private'];

    private const VARIATION_STATUSES = ['publish', 'private'];

    /** Scalar product fields, in diff order. */
    private const SCALAR_FIELDS = ['name', 'status', 'regular_price', 'sale_price', 'stock'];

    /** List fields, in diff order. */
    private const LIST_FIELDS = ['categories', 'attributes', 'images'];

    public static function max_rows(): int
    {
        return max(1, (int) apply_filters('wpmcp_product_import_max_rows', self::DEFAULT_MAX_ROWS));
    }

    public static function confirm_threshold(): int
    {
        return max(0, (int) apply_filters('wpmcp_product_import_confirm_threshold', self::DEFAULT_CONFIRM_THRESHOLD));
    }

    /**
     * Build the plan for $args ('rows', optional 'mode').
     *
     * @return array{public: array, ops: array} 'public' is the tool reply;
     *         'ops' is the per-row write list apply-product-import executes.
     */
    public static function build(array $args): array
    {
        if (! function_exists('wc_get_product')) {
            throw new \RuntimeException('WooCommerce is not active.');
        }

        $rows = $args['rows'] ?? null;
        if (! is_array($rows) || [] === $rows || ! array_is_list($rows)) {
            throw new \InvalidArgumentException('rows must be a non-empty array of product rows.');
        }
        $max = self::max_rows();
        if (count($rows) > $max) {
            throw new \InvalidArgumentException(
                'At most ' . (int) $max . ' rows per call (filter wpmcp_product_import_max_rows); split the import.'
            );
        }

        $mode = sanitize_key((string) ($args['mode'] ?? 'upsert'));
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be one of: ' . esc_html(implode(', ', self::MODES)) . '.');
        }

        $seen    = [];
        $public  = [];
        $ops     = [];
        $states  = [];
        $summary = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($rows as $index => $raw) {
            $entry = self::plan_row((int) $index, $raw, $mode, $seen);
            $public[] = $entry['public'];
            $ops[]    = $entry['op'];
            if (null !== $entry['state']) {
                $states[ (int) $index ] = $entry['state'];
            }
            $summary[ $entry['public']['action'] ]++;
        }

        $writes = $summary['create'] + $summary['update'];
        $hash   = hash('sha256', (string) wp_json_encode([
            'v'      => 1,
            'mode'   => $mode,
            'rows'   => array_map(static fn ($op) => $op['desired'] ?? null, $ops),
            'plan'   => $public,
            'states' => $states,
        ]));

        return [
            'public' => [
                'plan_hash'        => $hash,
                'mode'             => $mode,
                'summary'          => $summary,
                'writes'           => $writes,
                'confirm_required' => $writes > self::confirm_threshold(),
                'rows'             => $public,
            ],
            'ops'    => $ops,
        ];
    }

    /** @return array{public: array, op: array, state: ?array} */
    private static function plan_row(int $index, $raw, string $mode, array &$seen): array
    {
        $errors = [];
        $sku    = '';

        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return self::error_entry($index, '', ['Each row must be an object with at least a sku.']);
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::ROW_KEYS, true)) {
                $errors[] = 'Unknown field "' . $key . '". Allowed: ' . implode(', ', self::ROW_KEYS) . '.';
            }
        }

        if (isset($raw['sku']) && is_scalar($raw['sku'])) {
            $sku = sanitize_text_field(trim((string) $raw['sku']));
        }
        if ('' === $sku) {
            return self::error_entry($index, '', array_merge(['sku is required.'], $errors));
        }
        $sku_key = strtolower($sku);
        if (isset($seen[ $sku_key ])) {
            return self::error_entry($index, $sku, ['SKU "' . $sku . '" appears more than once in rows (first at row ' . $seen[ $sku_key ] . ').']);
        }
        $seen[ $sku_key ] = $index;

        $existing = null;
        $found    = (int) wc_get_product_id_by_sku($sku);
        if ($found > 0) {
            $product = wc_get_product($found);
            if ($product instanceof \WC_Product_Variation) {
                return self::error_entry($index, $sku, [
                    'SKU "' . $sku . '" belongs to a variation of product ' . $product->get_parent_id() . '; list it under that product\'s variations.',
                ]);
            }
            if ($product && ! in_array($product->get_type(), ['simple', 'variable'], true)) {
                return self::error_entry($index, $sku, [
                    'Product ' . $found . ' is a ' . $product->get_type() . ' product; only simple and variable products can be imported.',
                ]);
            }
            $existing = $product ?: null;
        }

        if (null !== $existing && 'create' === $mode) {
            return self::skip_entry($index, $sku, $existing->get_id(), 'A product with this SKU exists (mode create).', self::current_state($existing));
        }
        if (null === $existing && 'update' === $mode) {
            return self::skip_entry($index, $sku, null, 'No product has this SKU (mode update).', null);
        }

        $type = null !== $existing ? $existing->get_type() : 'simple';
        if (array_key_exists('type', $raw)) {
            $given = sanitize_key((string) $raw['type']);
            if (! in_array($given, ['simple', 'variable'], true)) {
                $errors[] = 'type must be simple or variable.';
            } elseif (null !== $existing && $given !== $type) {
                $errors[] = 'Product ' . $existing->get_id() . ' is ' . $type . '; changing a product\'s type is not supported.';
            } else {
                $type = $given;
            }
        }

        $current = null !== $existing ? self::current_state($existing) : null;
        $desired = self::desired_fields($raw, $type, $current, $errors);

        if (array_key_exists('variations', $raw)) {
            if ('variable' !== $type) {
                $errors[] = 'variations are only accepted on a variable product.';
            } else {
                $attributes           = $desired['attributes'] ?? ($current['attributes'] ?? []);
                $desired['variations'] = self::desired_variations($raw['variations'], $attributes, $errors);
            }
        }

        if (null === $existing) {
            if (! array_key_exists('name', $desired)) {
                $errors[] = 'name is required to create a product.';
            }
            if ('variable' === $type && ! self::has_variation_attribute($desired['attributes'] ?? [])) {
                $errors[] = 'A variable product needs at least one attribute with variation:true.';
            }
        }

        if ([] !== $errors) {
            return self::error_entry($index, $sku, $errors);
        }

        return null === $existing
            ? self::create_entry($index, $sku, $type, $desired, $raw)
            : self::update_entry($index, $sku, $existing, $current, $desired, $raw);
    }

    /** Validate and normalize the product-level fields present in the row. */
    private static function desired_fields(array $raw, string $type, ?array $current, array &$errors): array
    {
        $desired = [];

        if (array_key_exists('name', $raw)) {
            $name = is_scalar($raw['name']) ? trim(wp_strip_all_tags((string) $raw['name'])) : '';
            if ('' === $name) {
                $errors[] = 'name must be a non-empty string.';
            } else {
                $desired['name'] = $name;
            }
        }

        if (array_key_exists('status', $raw)) {
            $status = sanitize_key((string) (is_scalar($raw['status']) ? $raw['status'] : ''));
            if (! in_array($status, self::PRODUCT_STATUSES, true)) {
                $errors[] = 'status must be one of: ' . implode(', ', self::PRODUCT_STATUSES) . '.';
            } else {
                $desired['status'] = $status;
            }
        }

        $has_money = array_intersect(['regular_price', 'sale_price', 'stock'], array_keys($raw));
        if ('variable' === $type && [] !== $has_money) {
            $errors[] = 'A variable product\'s prices and stock are set on its variations, not on the product.';
        } else {
            self::money_fields($raw, $current, $desired, $errors, '');
        }

        if (array_key_exists('categories', $raw)) {
            $ids = self::category_ids($raw['categories'], $errors);
            if (null !== $ids) {
                $desired['categories'] = $ids;
            }
        }

        if (array_key_exists('attributes', $raw)) {
            $attributes = self::attributes($raw['attributes'], $type, $errors);
            if (null !== $attributes) {
                $desired['attributes'] = $attributes;
            }
        }

        if (array_key_exists('images', $raw)) {
            $images = self::images($raw['images'], $errors);
            if (null !== $images) {
                $desired['images'] = $images;
            }
        }

        return $desired;
    }

    /**
     * regular_price, sale_price and stock, shared by products and variations.
     * $current supplies the other price when a row sets only one of them, so
     * a sale price is always checked against the regular price it will sit
     * next to.
     */
    private static function money_fields(array $raw, ?array $current, array &$desired, array &$errors, string $prefix): void
    {
        foreach (['regular_price', 'sale_price'] as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            $value = self::price($raw[ $field ], 'sale_price' === $field);
            if (null === $value) {
                $errors[] = $prefix . $field . ' must be a non-negative number' . ('sale_price' === $field ? ' or "" for no sale' : '') . '.';
            } else {
                $desired[ $field ] = $value;
            }
        }

        if (array_key_exists('regular_price', $desired) || array_key_exists('sale_price', $desired)) {
            $regular = $desired['regular_price'] ?? (string) ($current['regular_price'] ?? '');
            $sale    = $desired['sale_price'] ?? (string) ($current['sale_price'] ?? '');
            if ('' !== $regular && '' !== $sale && (float) $sale >= (float) $regular) {
                $errors[] = $prefix . 'sale_price (' . $sale . ') must be below regular_price (' . $regular . ').';
            }
        }

        if (array_key_exists('stock', $raw)) {
            $stock = $raw['stock'];
            if (null === $stock) {
                $desired['stock'] = null;
            } elseif (is_int($stock) || (is_string($stock) && preg_match('/^-?\d+$/', trim($stock)))) {
                $desired['stock'] = (int) $stock;
            } else {
                $errors[] = $prefix . 'stock must be an integer quantity, or null to stop tracking stock.';
            }
        }
    }

    /** A price as the string WooCommerce stores, or null when invalid. */
    private static function price($value, bool $allow_empty): ?string
    {
        if (null === $value || '' === $value) {
            return $allow_empty ? '' : null;
        }
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        return $value;
    }

    /** @return int[]|null sorted term ids, or null after recording an error. */
    private static function category_ids($given, array &$errors): ?array
    {
        if (! is_array($given) || ! array_is_list($given)) {
            $errors[] = 'categories must be an array of category names, slugs or ids.';
            return null;
        }
        $ids = [];
        foreach ($given as $category) {
            $term = null;
            if (is_int($category) || (is_string($category) && ctype_digit($category))) {
                $term = get_term((int) $category, 'product_cat');
            } elseif (is_string($category) && '' !== trim($category)) {
                $label = trim($category);
                $term  = get_term_by('slug', sanitize_title($label), 'product_cat');
                if (! $term) {
                    $term = get_term_by('name', $label, 'product_cat');
                }
            }
            if (! $term instanceof \WP_Term) {
                $errors[] = 'Unknown product category "' . (is_scalar($category) ? (string) $category : '?') . '"; create it first.';
                continue;
            }
            $ids[] = (int) $term->term_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Normalize attribute rows. A name that matches a global attribute
     * (Products > Attributes) is a taxonomy attribute, and its options must
     * be existing terms; anything else is a custom attribute on the product.
     *
     * @return array[]|null
     */
    private static function attributes($given, string $type, array &$errors): ?array
    {
        if (! is_array($given) || ! array_is_list($given)) {
            $errors[] = 'attributes must be an array of {name, options, visible?, variation?}.';
            return null;
        }

        $out  = [];
        $keys = [];
        foreach ($given as $i => $attribute) {
            $name = is_array($attribute) && isset($attribute['name']) && is_scalar($attribute['name']) ? trim((string) $attribute['name']) : '';
            $opts = is_array($attribute) ? ($attribute['options'] ?? null) : null;
            if ('' === $name || ! is_array($opts) || [] === $opts) {
                $errors[] = 'attributes[' . $i . '] needs a name and a non-empty options array.';
                continue;
            }

            $taxonomy = self::attribute_taxonomy($name);
            $options  = [];
            foreach ($opts as $option) {
                $option = is_scalar($option) ? trim((string) $option) : '';
                if ('' === $option) {
                    $errors[] = 'Attribute "' . $name . '" has an empty option.';
                    continue;
                }
                if (null !== $taxonomy) {
                    $term = get_term_by('slug', sanitize_title($option), $taxonomy) ?: get_term_by('name', $option, $taxonomy);
                    if (! $term instanceof \WP_Term) {
                        $errors[] = 'Attribute "' . $name . '" has no term "' . $option . '"; add the term first.';
                        continue;
                    }
                    $option = (string) $term->slug;
                } elseif (str_contains($option, '|')) {
                    $errors[] = 'Attribute option "' . $option . '" must not contain "|".';
                    continue;
                }
                $options[] = $option;
            }

            // WooCommerce keeps a global attribute's terms in term order,
            // not in the order given, so they compare as a sorted set.
            $options = array_values(array_unique($options));
            if (null !== $taxonomy) {
                sort($options);
            }

            $key = $taxonomy ?? sanitize_title($name);
            if (isset($keys[ $key ])) {
                $errors[] = 'Attribute "' . $name . '" is listed more than once.';
                continue;
            }
            $keys[ $key ] = true;

            $out[] = [
                'name'      => $taxonomy ?? $name,
                'taxonomy'  => null !== $taxonomy,
                'options'   => $options,
                'visible'   => array_key_exists('visible', $attribute) ? (bool) $attribute['visible'] : true,
                'variation' => array_key_exists('variation', $attribute) ? (bool) $attribute['variation'] : 'variable' === $type,
            ];
        }

        return $out;
    }

    /** The registered pa_* taxonomy for a global attribute name, or null. */
    private static function attribute_taxonomy(string $name): ?string
    {
        $slug = strtolower($name);
        if (str_starts_with($slug, 'pa_')) {
            $slug = substr($slug, 3);
        }
        $taxonomy = wc_attribute_taxonomy_name(wc_sanitize_taxonomy_name($slug));
        return taxonomy_exists($taxonomy) ? $taxonomy : null;
    }

    /** The key WooCommerce stores a variation's value for this attribute under. */
    private static function attribute_key(array $attribute): string
    {
        return $attribute['taxonomy'] ? (string) $attribute['name'] : sanitize_title((string) $attribute['name']);
    }

    private static function has_variation_attribute(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if ($attribute['variation']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Images as [{id}] (an existing attachment, or a URL this importer
     * already fetched once) or [{url}] (to be fetched on apply). The first
     * image becomes the product image, the rest its gallery.
     *
     * @return array[]|null
     */
    private static function images($given, array &$errors): ?array
    {
        if (! is_array($given) || ! array_is_list($given)) {
            $errors[] = 'images must be an array of media ids or https URLs.';
            return null;
        }
        if (count($given) > self::MAX_IMAGES) {
            $errors[] = 'At most ' . self::MAX_IMAGES . ' images per product.';
            return null;
        }

        $out  = [];
        $seen = [];
        foreach ($given as $image) {
            if (is_int($image) || (is_string($image) && ctype_digit($image))) {
                $id = (int) $image;
                if (! wp_attachment_is_image($id)) {
                    $errors[] = 'Media ' . $id . ' is not an image in the Media Library.';
                    continue;
                }
                $entry = ['id' => $id];
            } elseif (is_string($image) && '' !== trim($image)) {
                $url = trim($image);
                try {
                    Remote_Image_Guard::validate_url($url);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = 'Image "' . $url . '": ' . $e->getMessage();
                    continue;
                }
                $known = self::imported_attachment($url);
                $entry = null !== $known ? ['id' => $known] : ['url' => $url];
            } else {
                $errors[] = 'Each image must be a media id or an https URL.';
                continue;
            }

            $key = isset($entry['id']) ? 'id:' . $entry['id'] : 'url:' . $entry['url'];
            if (isset($seen[ $key ])) {
                $errors[] = 'An image is listed more than once.';
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $entry;
        }
        return $out;
    }

    /** An image an earlier import already fetched from this URL, if it still exists. */
    public static function imported_attachment(string $url): ?int
    {
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed meta_key lookup per imported image URL.
            'meta_query'     => [['key' => self::SOURCE_META, 'value' => $url]],
        ]);
        return [] !== $ids ? (int) $ids[0] : null;
    }

    /**
     * Normalize the variation rows of one product against the attributes it
     * will have. Attributes left out mean "any", as in create-variation.
     *
     * @return array[]
     */
    private static function desired_variations($given, array $attributes, array &$errors): array
    {
        if (! is_array($given) || ! array_is_list($given)) {
            $errors[] = 'variations must be an array of {attributes, sku?, regular_price?, sale_price?, stock?, status?}.';
            return [];
        }
        if (count($given) > self::MAX_VARIATIONS) {
            $errors[] = 'At most ' . self::MAX_VARIATIONS . ' variations per product.';
            return [];
        }

        $allowed = [];
        foreach ($attributes as $attribute) {
            if ($attribute['variation']) {
                $allowed[ self::attribute_key($attribute) ] = $attribute;
            }
        }

        $out    = [];
        $combos = [];
        $skus   = [];
        foreach ($given as $i => $variation) {
            $prefix = 'variations[' . $i . '].';
            if (! is_array($variation) || ($variation !== [] && array_is_list($variation))) {
                $errors[] = $prefix . ' must be an object.';
                continue;
            }
            foreach (array_keys($variation) as $key) {
                if (! in_array($key, self::VARIATION_KEYS, true)) {
                    $errors[] = $prefix . ': unknown field "' . $key . '". Allowed: ' . implode(', ', self::VARIATION_KEYS) . '.';
                }
            }

            $desired = [];
            if (array_key_exists('sku', $variation)) {
                $sku = is_scalar($variation['sku']) ? sanitize_text_field(trim((string) $variation['sku'])) : '';
                if ('' === $sku) {
                    $errors[] = $prefix . 'sku must be a non-empty string.';
                } elseif (isset($skus[ strtolower($sku) ])) {
                    $errors[] = $prefix . 'sku "' . $sku . '" is used by another variation in this row.';
                } else {
                    $skus[ strtolower($sku) ] = true;
                    $desired['sku']            = $sku;
                }
            }

            $map = self::variation_attributes($variation['attributes'] ?? null, $allowed, $prefix, $errors);
            if (null !== $map) {
                $combo = (string) wp_json_encode($map);
                if (isset($combos[ $combo ])) {
                    $errors[] = $prefix . 'attributes repeat an earlier variation\'s combination.';
                }
                $combos[ $combo ]      = true;
                $desired['attributes'] = $map;
            }

            if (array_key_exists('status', $variation)) {
                $status = sanitize_key((string) (is_scalar($variation['status']) ? $variation['status'] : ''));
                if (! in_array($status, self::VARIATION_STATUSES, true)) {
                    $errors[] = $prefix . 'status must be publish or private.';
                } else {
                    $desired['status'] = $status;
                }
            }

            self::money_fields($variation, null, $desired, $errors, $prefix);
            $out[] = $desired;
        }
        return $out;
    }

    /**
     * @param array<string, array> $allowed attribute key => normalized attribute
     * @return array<string, string>|null key => option ('' = any), ksorted
     */
    private static function variation_attributes($given, array $allowed, string $prefix, array &$errors): ?array
    {
        if ([] === $allowed) {
            $errors[] = $prefix . 'the product has no attribute with variation:true.';
            return null;
        }
        if (! is_array($given) || [] === $given || array_is_list($given)) {
            $errors[] = $prefix . 'attributes must be an object of attribute => option, e.g. {"Size": "M"}.';
            return null;
        }

        $map = array_fill_keys(array_keys($allowed), '');
        foreach ($given as $raw_key => $raw_value) {
            $key = null;
            $candidate = strtolower(trim((string) $raw_key));
            foreach ([$candidate, sanitize_title($candidate), 'pa_' . sanitize_title($candidate)] as $try) {
                if (isset($allowed[ $try ])) {
                    $key = $try;
                    break;
                }
            }
            if (null === $key) {
                $errors[] = $prefix . 'unknown variation attribute "' . $raw_key . '". Variation attributes: ' . implode(', ', array_keys($allowed)) . '.';
                continue;
            }
            $value = is_scalar($raw_value) ? trim((string) $raw_value) : '';
            if ('' !== $value) {
                $match = null;
                foreach ($allowed[ $key ]['options'] as $option) {
                    if (0 === strcasecmp((string) $option, $value) || ($allowed[ $key ]['taxonomy'] && sanitize_title($value) === $option)) {
                        $match = (string) $option;
                        break;
                    }
                }
                if (null === $match) {
                    $errors[] = $prefix . 'attribute "' . $raw_key . '" has no option "' . $value . '".';
                    continue;
                }
                $value = $match;
            }
            $map[ $key ] = $value;
        }
        ksort($map);
        return $map;
    }

    /**
     * The fields of an existing product an import can change, plus its
     * post_modified_gmt, as the plan hash sees them.
     */
    public static function current_state(\WC_Product $product): array
    {
        $children = [];
        if ($product->is_type('variable')) {
            $ids = get_posts([
                'post_type'      => 'product_variation',
                'post_parent'    => $product->get_id(),
                'post_status'    => ['publish', 'private'],
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            foreach ($ids as $child_id) {
                $child = wc_get_product((int) $child_id);
                if ($child instanceof \WC_Product_Variation) {
                    $children[] = self::variation_state($child);
                }
            }
        }

        $images = [];
        if ($product->get_image_id()) {
            $images[] = (int) $product->get_image_id();
        }
        foreach ($product->get_gallery_image_ids() as $id) {
            $images[] = (int) $id;
        }
        $categories = array_map('intval', $product->get_category_ids());
        sort($categories);

        return [
            'id'            => $product->get_id(),
            'type'          => $product->get_type(),
            'name'          => $product->get_name(),
            'status'        => $product->get_status(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price'    => (string) $product->get_sale_price(),
            'stock'         => true === $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
            'categories'    => $categories,
            'attributes'    => self::attributes_state($product),
            'images'        => $images,
            'variations'    => $children,
            'modified'      => (string) get_post_field('post_modified_gmt', $product->get_id()),
        ];
    }

    private static function variation_state(\WC_Product_Variation $variation): array
    {
        $attributes = array_map('strval', $variation->get_attributes());
        ksort($attributes);
        return [
            'id'            => $variation->get_id(),
            'sku'           => (string) $variation->get_sku('edit'),
            'attributes'    => $attributes,
            'regular_price' => (string) $variation->get_regular_price(),
            'sale_price'    => (string) $variation->get_sale_price(),
            'stock'         => true === $variation->get_manage_stock('edit') ? (int) $variation->get_stock_quantity() : null,
            'status'        => $variation->get_status(),
            'modified'      => (string) get_post_field('post_modified_gmt', $variation->get_id()),
        ];
    }

    /** A product's attributes in the planner's normalized shape. */
    private static function attributes_state(\WC_Product $product): array
    {
        $out = [];
        foreach ($product->get_attributes() as $attribute) {
            if (! $attribute instanceof \WC_Product_Attribute) {
                continue;
            }
            $options = [];
            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_options() as $term_id) {
                    $term = get_term((int) $term_id, $attribute->get_name());
                    if ($term instanceof \WP_Term) {
                        $options[] = (string) $term->slug;
                    }
                }
                sort($options);
            } else {
                $options = array_map('strval', $attribute->get_options());
            }
            $out[] = [
                'name'      => $attribute->get_name(),
                'taxonomy'  => $attribute->is_taxonomy(),
                'options'   => $options,
                'visible'   => (bool) $attribute->get_visible(),
                'variation' => (bool) $attribute->get_variation(),
            ];
        }
        return $out;
    }

    private static function create_entry(int $index, string $sku, string $type, array $desired, array $raw): array
    {
        $variations = $desired['variations'] ?? [];
        unset($desired['variations']);
        $desired += ['status' => 'publish'];

        $errors = self::denials(
            array_merge(['wpmcp/create-product'], [] !== $variations ? ['wpmcp/create-variation'] : []),
            $raw
        );
        if ([] !== $errors) {
            return self::error_entry($index, $sku, $errors);
        }

        $public = ['row' => $index, 'sku' => $sku, 'action' => 'create', 'type' => $type, 'fields' => self::display($desired)];
        if ([] !== $variations) {
            $public['variations'] = array_map(static fn ($v) => ['action' => 'create'] + $v, $variations);
        }

        return [
            'public' => $public,
            'op'     => [
                'action'     => 'create',
                'sku'        => $sku,
                'type'       => $type,
                'desired'    => $desired,
                'variations' => $variations,
            ],
            'state'  => null,
        ];
    }

    private static function update_entry(int $index, string $sku, \WC_Product $existing, array $current, array $desired, array $raw): array
    {
        $changes = [];
        foreach (array_merge(self::SCALAR_FIELDS, self::LIST_FIELDS) as $field) {
            if (! array_key_exists($field, $desired) || self::same($field, $current[ $field ], $desired[ $field ])) {
                continue;
            }
            $changes[ $field ] = ['from' => self::display_value($field, $current[ $field ]), 'to' => self::display_value($field, $desired[ $field ])];
        }

        $variation_ops = [];
        $errors        = [];
        if (array_key_exists('variations', $desired)) {
            $variation_ops = self::match_variations($existing->get_id(), $current['variations'], $desired['variations'], $errors);
        }
        if ([] !== $errors) {
            return self::error_entry($index, $sku, $errors, $current);
        }

        $abilities = [] !== $changes ? ['wpmcp/update-product'] : [];
        foreach ($variation_ops as $op) {
            if ('create' === $op['action']) {
                $abilities[] = 'wpmcp/create-variation';
            } elseif ('update' === $op['action']) {
                $abilities[] = 'wpmcp/update-variation';
            }
        }
        $abilities = array_values(array_unique($abilities));

        if ([] === $abilities) {
            return self::skip_entry($index, $sku, $existing->get_id(), 'The product already matches this row (no changes).', $current);
        }

        $errors = self::denials($abilities, $raw);
        if ([] !== $errors) {
            return self::error_entry($index, $sku, $errors, $current);
        }

        $public = ['row' => $index, 'sku' => $sku, 'action' => 'update', 'id' => $existing->get_id(), 'changes' => $changes];
        if (array_key_exists('variations', $desired)) {
            $public['variations'] = array_map(
                static function ($op) {
                    $row = ['action' => $op['action'], 'attributes' => $op['attributes']];
                    if (isset($op['id'])) {
                        $row['id'] = $op['id'];
                    }
                    if ('create' === $op['action']) {
                        $row += $op['desired'];
                    } elseif ('update' === $op['action']) {
                        $row['changes'] = $op['changes'];
                    }
                    return $row;
                },
                $variation_ops
            );
        }

        $scalar = array_intersect_key($desired, array_flip(self::SCALAR_FIELDS));
        $lists  = array_intersect_key($desired, array_flip(self::LIST_FIELDS));

        return [
            'public' => $public,
            'op'     => [
                'action'     => 'update',
                'id'         => $existing->get_id(),
                'sku'        => $sku,
                'desired'    => $desired,
                'scalar'     => array_intersect_key($scalar, $changes),
                'lists'      => array_intersect_key($lists, $changes),
                'variations' => $variation_ops,
            ],
            'state'  => $current,
        ];
    }

    /**
     * Pair each desired variation with an existing one (by SKU, then by
     * attribute combination) and diff it, or mark it for creation.
     */
    private static function match_variations(int $parent_id, array $children, array $wanted, array &$errors): array
    {
        $taken = [];
        $ops   = [];
        foreach ($wanted as $i => $desired) {
            $match = null;
            if (isset($desired['sku'])) {
                foreach ($children as $child) {
                    if (0 === strcasecmp($child['sku'], $desired['sku'])) {
                        $match = $child;
                        break;
                    }
                }
                if (null === $match) {
                    $owner = (int) wc_get_product_id_by_sku($desired['sku']);
                    if ($owner > 0) {
                        $errors[] = 'variations[' . $i . '].sku "' . $desired['sku'] . '" is already used by product ' . $owner . '.';
                        continue;
                    }
                }
            }
            if (null === $match) {
                foreach ($children as $child) {
                    $have = array_merge(array_fill_keys(array_keys($desired['attributes']), ''), $child['attributes']);
                    ksort($have);
                    if ($have === $desired['attributes']) {
                        $match = $child;
                        break;
                    }
                }
            }

            if (null === $match) {
                $ops[] = ['action' => 'create', 'attributes' => $desired['attributes'], 'desired' => array_diff_key($desired, ['attributes' => true]), 'parent' => $parent_id];
                continue;
            }
            if (isset($taken[ $match['id'] ])) {
                $errors[] = 'variations[' . $i . '] matches variation ' . $match['id'] . ', which an earlier variation row already matched.';
                continue;
            }
            $taken[ $match['id'] ] = true;

            if (isset($desired['regular_price']) || isset($desired['sale_price'])) {
                $regular = $desired['regular_price'] ?? $match['regular_price'];
                $sale    = $desired['sale_price'] ?? $match['sale_price'];
                if ('' !== $regular && '' !== $sale && (float) $sale >= (float) $regular) {
                    $errors[] = 'variations[' . $i . '].sale_price (' . $sale . ') must be below regular_price (' . $regular . ').';
                    continue;
                }
            }

            $changes = [];
            foreach (['sku', 'regular_price', 'sale_price', 'stock', 'status'] as $field) {
                if (array_key_exists($field, $desired) && ! self::same($field, $match[ $field ], $desired[ $field ])) {
                    $changes[ $field ] = ['from' => $match[ $field ], 'to' => $desired[ $field ]];
                }
            }
            $ops[] = [
                'action'     => [] === $changes ? 'skip' : 'update',
                'id'         => $match['id'],
                'attributes' => $desired['attributes'],
                'changes'    => $changes,
                'desired'    => array_intersect_key($desired, $changes),
            ];
        }
        return $ops;
    }

    /** Whether a current and a desired value are the same for $field. */
    private static function same(string $field, $current, $desired): bool
    {
        if (in_array($field, ['regular_price', 'sale_price'], true)) {
            if ('' === (string) $current || '' === (string) $desired) {
                return (string) $current === (string) $desired;
            }
            return abs((float) $current - (float) $desired) < 0.000001;
        }
        if ('images' === $field) {
            $ids = [];
            foreach ($desired as $image) {
                if (! isset($image['id'])) {
                    return false;
                }
                $ids[] = (int) $image['id'];
            }
            return $ids === $current;
        }
        if ('sku' === $field) {
            return 0 === strcasecmp((string) $current, (string) $desired);
        }
        return $current === $desired;
    }

    private static function display(array $desired): array
    {
        $out = [];
        foreach ($desired as $field => $value) {
            $out[ $field ] = self::display_value($field, $value);
        }
        return $out;
    }

    /** Category ids shown as names and images as ids or URLs; the rest as is. */
    private static function display_value(string $field, $value)
    {
        if ('categories' === $field) {
            return array_map(
                static function ($id) {
                    $term = get_term((int) $id, 'product_cat');
                    return $term instanceof \WP_Term ? $term->name : (int) $id;
                },
                (array) $value
            );
        }
        if ('images' === $field) {
            return array_map(static fn ($image) => is_array($image) ? ($image['id'] ?? $image['url']) : $image, (array) $value);
        }
        return $value;
    }

    /** @return string[] one message per ability the caller may not run. */
    private static function denials(array $abilities, array $input): array
    {
        $registrar = Plugin::instance()->registrar();
        $errors    = [];
        foreach ($abilities as $name) {
            $ability = $registrar->get($name);
            if (null === $ability || ! $registrar->would_permit($ability, $input)) {
                $errors[] = $name . ' is not permitted for this user on this site.';
            }
        }
        return $errors;
    }

    private static function error_entry(int $index, string $sku, array $errors, ?array $state = null): array
    {
        return [
            'public' => ['row' => $index, 'sku' => $sku, 'action' => 'error', 'errors' => array_values(array_unique($errors))],
            'op'     => ['action' => 'error'],
            'state'  => $state,
        ];
    }

    private static function skip_entry(int $index, string $sku, ?int $id, string $reason, ?array $state): array
    {
        $public = ['row' => $index, 'sku' => $sku, 'action' => 'skip', 'reason' => $reason];
        if (null !== $id) {
            $public['id'] = $id;
        }
        return ['public' => $public, 'op' => ['action' => 'skip', 'id' => $id], 'state' => $state];
    }
}
