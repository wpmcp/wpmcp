<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add a variation to a variable product through WC_Product_Variation's
 * setters and save().
 *
 * Creation is exempt from Safe_Mutation for the same reason create-product
 * is: there is no prior state to capture. The reply names delete-variation
 * as the undo.
 *
 * Validation runs before anything is written:
 *  - the parent must be a variable product;
 *  - every attribute key must be one of the parent's variation attributes
 *    (a taxonomy attribute may be given as "color" or "pa_color"), and its
 *    value must be one of that attribute's options (a term slug for a
 *    taxonomy attribute) or "" for "any". Attributes left out also mean
 *    "any", which is how WooCommerce itself treats them. WooCommerce would
 *    otherwise store an unknown key or value as-is, producing a variation no
 *    shopper can ever select;
 *  - prices, sku, status and stock go through Update_Variation's own rules,
 *    so create and update refuse exactly the same inputs.
 */
class Create_Variation
{
    public function handle(array $args): array
    {
        $parent_id = (int) ($args['product_id'] ?? 0);
        if ($parent_id <= 0) {
            throw new \InvalidArgumentException('A product_id (the variable parent) is required.');
        }
        $parent = wc_get_product($parent_id);
        if (! $parent) {
            throw new \RuntimeException('Product not found.');
        }
        if (! $parent->is_type('variable')) {
            throw new \InvalidArgumentException(
                'Product ' . (int) $parent_id . ' is type "' . esc_html($parent->get_type()) . '", not a variable product.'
            );
        }

        $attributes = $this->resolve_attributes($parent, $args['attributes'] ?? []);

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parent_id);

        $updater = new Update_Variation();
        $updater->validate($variation, $args);

        $variation->set_attributes($attributes);
        if (array_key_exists('description', $args)) {
            $variation->set_description(wp_kses_post((string) $args['description']));
        }
        $updater->apply_changes($variation, $args);

        $id = $variation->save();
        if (! $id) {
            throw new \RuntimeException('Could not create the variation.');
        }

        // Re-sync now rather than at shutdown so the parent's price range and
        // stock status in any follow-up read in this request are current.
        \WC_Product_Variable::sync($parent_id);

        $fresh = wc_get_product($id);

        return array_merge(
            $fresh instanceof \WC_Product_Variation ? Variation_View::summary($fresh) : ['id' => $id],
            ['recoverable' => false, 'undo' => 'delete-variation']
        );
    }

    /**
     * Map the caller's attributes onto the parent's variation attributes.
     *
     * @return array<string, string> attribute key => option value ('' = any)
     */
    private function resolve_attributes(\WC_Product $parent, $given): array
    {
        if (! is_array($given)) {
            throw new \InvalidArgumentException('attributes must be an object of attribute => option, e.g. {"size": "large"}.');
        }

        $allowed = [];
        foreach ($parent->get_attributes() as $key => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute && $attribute->get_variation()) {
                $allowed[ (string) $key ] = $attribute;
            }
        }
        if ([] === $allowed) {
            throw new \InvalidArgumentException('The parent product has no attributes marked "used for variations".');
        }

        $out = [];
        foreach ($given as $raw_key => $raw_value) {
            $key = $this->match_key((string) $raw_key, $allowed);
            if (null === $key) {
                throw new \InvalidArgumentException(
                    'Unknown variation attribute "' . esc_html((string) $raw_key) . '". The parent\'s variation attributes are: '
                    . esc_html(implode(', ', array_keys($allowed))) . '.'
                );
            }
            if (! is_scalar($raw_value)) {
                throw new \InvalidArgumentException('Attribute values must be strings.');
            }
            $value = trim((string) $raw_value);
            if ('' !== $value) {
                $options = $this->options($allowed[ $key ]);
                $match   = null;
                foreach ($options as $option) {
                    if (0 === strcasecmp($option, $value)) {
                        $match = $option;
                        break;
                    }
                }
                if (null === $match) {
                    throw new \InvalidArgumentException(
                        'Attribute "' . esc_html($key) . '" has no option "' . esc_html($value) . '". Options: '
                        . esc_html(implode(', ', $options)) . ' (or "" for any).'
                    );
                }
                $value = $match;
            }
            $out[ $key ] = $value;
        }

        return $out;
    }

    /** @param array<string, \WC_Product_Attribute> $allowed */
    private function match_key(string $key, array $allowed): ?string
    {
        $key = strtolower(trim($key));
        if (str_starts_with($key, 'attribute_')) {
            $key = substr($key, 10);
        }
        foreach ([$key, sanitize_title($key), 'pa_' . sanitize_title($key)] as $candidate) {
            if (isset($allowed[ $candidate ])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The values a variation may store for this attribute: term slugs for a
     * taxonomy attribute, the option text for a custom one. Term ids are
     * resolved read-only (WC_Product_Attribute::get_slugs() can insert
     * missing terms, which a validation step must never do).
     *
     * @return string[]
     */
    private function options(\WC_Product_Attribute $attribute): array
    {
        if (! $attribute->is_taxonomy()) {
            return array_map('strval', $attribute->get_options());
        }
        $slugs = [];
        foreach ($attribute->get_options() as $option) {
            $term = is_int($option)
                ? get_term_by('id', $option, $attribute->get_name())
                : get_term_by('name', (string) $option, $attribute->get_name());
            if ($term && ! is_wp_error($term)) {
                $slugs[] = (string) $term->slug;
            }
        }
        return $slugs;
    }
}
