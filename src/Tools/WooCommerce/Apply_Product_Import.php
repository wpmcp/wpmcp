<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Media_Import_Snapshot;
use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * apply-product-import: write a plan that plan-product-import produced.
 *
 * The plan is rebuilt from the same rows and compared with the approved
 * plan_hash first; any difference (a matched product edited, a SKU taken, a
 * category removed, the rows themselves changed) refuses the call before
 * anything is written. A plan with error rows is refused too, and so is one
 * that writes more products than the confirm threshold without confirm:true.
 *
 * Writes go through the existing paths so they get the same validation and
 * the same undo points:
 *  - product fields through update-product (a 'post' snapshot), and
 *    categories, attributes and images through one more Safe_Mutation of
 *    the same post;
 *  - variations through update-variation and create-variation;
 *  - image URLs through Remote_Image_Guard::sideload(), the guarded path
 *    import-stock-image uses, each recorded as a media_import;
 *  - each created product or variation recorded as a wc_product_create.
 * Everything shares one fresh session_id, so rollback-session undoes the
 * whole import. Pruning is held for the duration so an import larger than
 * the history limit cannot drop its own first undo points.
 *
 * A row that fails part way rolls back the whole session and the call
 * reports the failure: an import is all or nothing.
 */
class Apply_Product_Import
{
    public function handle(array $args): array
    {
        $given = strtolower(trim((string) ($args['plan_hash'] ?? '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $given)) {
            throw new \InvalidArgumentException('plan_hash (from plan-product-import) is required.');
        }

        $plan   = Product_Import_Plan::build($args);
        $public = $plan['public'];

        if (! hash_equals($public['plan_hash'], $given)) {
            throw new \RuntimeException(
                'The plan changed since it was approved (the store or the rows differ). Nothing was written; run plan-product-import again and review the new plan.'
            );
        }
        if ($public['summary']['error'] > 0) {
            throw new \InvalidArgumentException(
                'The plan has ' . (int) $public['summary']['error'] . ' error row(s); fix or remove them and plan again. Nothing was written.'
            );
        }
        if ($public['confirm_required'] && true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException(
                'This import writes ' . (int) $public['writes'] . ' products, above the confirmation threshold of '
                . (int) Product_Import_Plan::confirm_threshold() . '. Pass confirm:true to proceed.'
            );
        }

        if (0 === $public['writes']) {
            return [
                'session_id'  => null,
                'created'     => 0,
                'updated'     => 0,
                'skipped'     => $public['summary']['skip'],
                'undo_points' => 0,
                'rows'        => $this->row_results($public['rows'], []),
                'warnings'    => [],
            ];
        }

        $this->assert_permitted($plan['ops'], $args);

        $session_id = wp_generate_uuid4();
        $ids        = Snapshot_Store::hold_pruning(fn () => $this->run($plan['ops'], $public['rows'], $session_id));

        $undo_points = count(Snapshot_Store::index_by_session($session_id, 1000000));
        $limit       = Snapshot_Store::history_limit();
        $warnings    = [];
        if ($undo_points > $limit) {
            $warnings[] = 'This import wrote ' . $undo_points . ' undo points, more than the site keeps (' . $limit
                . '). The next change on the site starts pruning the oldest of them, so roll back now if needed, or raise the wpmcp_snapshot_history_limit filter before large imports.';
        }

        return [
            'session_id'  => $session_id,
            'created'     => $public['summary']['create'],
            'updated'     => $public['summary']['update'],
            'skipped'     => $public['summary']['skip'],
            'undo_points' => $undo_points,
            'undo'        => 'rollback-session with this session_id undoes the whole import.',
            'rows'        => $this->row_results($public['rows'], $ids),
            'warnings'    => $warnings,
        ];
    }

    /** @return array<int, int> row index => product id written */
    private function run(array $ops, array $rows, string $session_id): array
    {
        $fetched = [];
        $ids     = [];
        foreach ($ops as $index => $op) {
            if (! in_array($op['action'], ['create', 'update'], true)) {
                continue;
            }
            try {
                $ids[ $index ] = 'create' === $op['action']
                    ? $this->create($op, $session_id, $fetched)
                    : $this->update($op, $session_id, $fetched);
            } catch (\Throwable $e) {
                Rollback_Service::restore_session($session_id);
                $leftovers = Rollback_Service::take_warnings();
                throw new \RuntimeException(esc_html(sprintf(
                    'Row %d (SKU %s) failed: %s Everything this call wrote was rolled back%s.',
                    (int) $index,
                    (string) ($rows[ $index ]['sku'] ?? ''),
                    rtrim($e->getMessage(), '.') . '.',
                    [] !== $leftovers ? ' (with warnings: ' . implode(' ', $leftovers) . ')' : ''
                )));
            }
        }
        return $ids;
    }

    private function create(array $op, string $session_id, array &$fetched): int
    {
        $desired = $op['desired'];
        $images  = isset($desired['images']) ? $this->image_ids($desired['images'], $session_id, $fetched) : null;

        $product = 'variable' === $op['type'] ? new \WC_Product_Variable() : new \WC_Product_Simple();
        $product->set_name((string) $desired['name']);
        $product->set_sku((string) $op['sku']);
        $product->set_status((string) $desired['status']);
        if (array_key_exists('regular_price', $desired)) {
            $product->set_regular_price((string) $desired['regular_price']);
        }
        if (array_key_exists('sale_price', $desired)) {
            $product->set_sale_price((string) $desired['sale_price']);
        }
        if (array_key_exists('stock', $desired)) {
            $product->set_manage_stock(null !== $desired['stock']);
            if (null !== $desired['stock']) {
                $product->set_stock_quantity((int) $desired['stock']);
            }
        }
        $this->apply_lists($product, $desired, $images);

        $id = (int) $product->save();
        if ($id <= 0) {
            throw new \RuntimeException('Could not create the product.');
        }
        Product_Create_Snapshot::record($id, $desired, $session_id);

        // The parent's creation record removes every variation under it, so
        // the variations need no records of their own.
        foreach ($op['variations'] as $variation) {
            (new Create_Variation())->handle($this->variation_args(['product_id' => $id, 'attributes' => $variation['attributes']], $variation));
        }

        return $id;
    }

    private function update(array $op, string $session_id, array &$fetched): int
    {
        $id = (int) $op['id'];

        if ([] !== $op['scalar']) {
            $args = ['id' => $id, 'session_id' => $session_id];
            foreach (['name', 'status', 'regular_price', 'sale_price'] as $field) {
                if (array_key_exists($field, $op['scalar'])) {
                    $args[ $field ] = $op['scalar'][ $field ];
                }
            }
            if (array_key_exists('stock', $op['scalar'])) {
                $args['manage_stock'] = null !== $op['scalar']['stock'];
                if (null !== $op['scalar']['stock']) {
                    $args['stock_quantity'] = (int) $op['scalar']['stock'];
                }
            }
            (new Update_Product())->handle($args);
        }

        if ([] !== $op['lists']) {
            $images  = isset($op['lists']['images']) ? $this->image_ids($op['lists']['images'], $session_id, $fetched) : null;
            $product = wc_get_product($id);
            if (! $product) {
                throw new \RuntimeException('Product not found.');
            }
            Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $id,
                    'session_id'  => $session_id,
                    'tool_name'   => 'apply-product-import',
                    'args'        => $op['lists'],
                ],
                function () use ($product, $op, $images): void {
                    $this->apply_lists($product, $op['lists'], $images);
                    if (! $product->save()) {
                        throw new \RuntimeException('Could not update the product.');
                    }
                }
            );
        }

        foreach ($op['variations'] as $variation) {
            if ('create' === $variation['action']) {
                $created = (new Create_Variation())->handle(
                    $this->variation_args(['product_id' => $id, 'attributes' => $variation['attributes']], $variation['desired'])
                );
                Product_Create_Snapshot::record((int) $created['id'], $variation['desired'], $session_id);
            } elseif ('update' === $variation['action']) {
                (new Update_Variation())->handle(
                    $this->variation_args(['id' => (int) $variation['id'], 'session_id' => $session_id], $variation['desired'])
                );
            }
        }

        return $id;
    }

    /** Categories, attributes and images, each only when the plan sets it. */
    private function apply_lists(\WC_Product $product, array $fields, ?array $images): void
    {
        if (array_key_exists('categories', $fields)) {
            $product->set_category_ids(array_map('intval', $fields['categories']));
        }
        if (array_key_exists('attributes', $fields)) {
            $product->set_attributes($this->attribute_objects($fields['attributes']));
        }
        if (null !== $images) {
            $product->set_image_id($images[0] ?? 0);
            $product->set_gallery_image_ids(array_slice($images, 1));
        }
    }

    /** @return \WC_Product_Attribute[] */
    private function attribute_objects(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $position => $attribute) {
            $object = new \WC_Product_Attribute();
            if ($attribute['taxonomy']) {
                $ids = [];
                foreach ($attribute['options'] as $slug) {
                    $term = get_term_by('slug', (string) $slug, (string) $attribute['name']);
                    if (! $term instanceof \WP_Term) {
                        throw new \RuntimeException('Attribute term "' . esc_html((string) $slug) . '" no longer exists.');
                    }
                    $ids[] = (int) $term->term_id;
                }
                $object->set_id((int) wc_attribute_taxonomy_id_by_name((string) $attribute['name']));
                $object->set_name((string) $attribute['name']);
                $object->set_options($ids);
            } else {
                $object->set_id(0);
                $object->set_name((string) $attribute['name']);
                $object->set_options(array_map('strval', $attribute['options']));
            }
            $object->set_position((int) $position);
            $object->set_visible((bool) $attribute['visible']);
            $object->set_variation((bool) $attribute['variation']);
            $out[] = $object;
        }
        return $out;
    }

    /** The planner's variation fields in create-variation / update-variation argument form. */
    private function variation_args(array $base, array $fields): array
    {
        foreach (['sku', 'regular_price', 'sale_price', 'status'] as $field) {
            if (array_key_exists($field, $fields)) {
                $base[ $field ] = $fields[ $field ];
            }
        }
        if (array_key_exists('stock', $fields)) {
            $base['manage_stock'] = null !== $fields['stock'];
            if (null !== $fields['stock']) {
                $base['stock_quantity'] = (int) $fields['stock'];
            }
        }
        return $base;
    }

    /**
     * Resolve planned images to attachment ids, fetching each new URL once
     * per call through the guarded sideload and recording it as a
     * media_import in the session so rollback removes it.
     *
     * @return int[]
     */
    private function image_ids(array $images, string $session_id, array &$fetched): array
    {
        $ids = [];
        foreach ($images as $image) {
            if (isset($image['id'])) {
                $ids[] = (int) $image['id'];
                continue;
            }
            $url = (string) $image['url'];
            if (! isset($fetched[ $url ])) {
                $media_id = Remote_Image_Guard::sideload($url, 0, 'product-image');
                update_post_meta($media_id, Product_Import_Plan::SOURCE_META, esc_url_raw($url));
                Media_Import_Snapshot::record('apply-product-import', $media_id, ['url' => $url], $session_id);
                $fetched[ $url ] = $media_id;
            }
            $ids[] = $fetched[ $url ];
        }
        return $ids;
    }

    /**
     * Re-check, with an audit row each, the single-item abilities this
     * import writes through, so a site that switched one off has switched
     * it off here too.
     */
    private function assert_permitted(array $ops, array $input): void
    {
        $needed = [];
        foreach ($ops as $op) {
            if ('create' === $op['action']) {
                $needed['wpmcp/create-product'] = true;
                if ([] !== $op['variations']) {
                    $needed['wpmcp/create-variation'] = true;
                }
            } elseif ('update' === $op['action']) {
                if ([] !== $op['scalar'] || [] !== $op['lists']) {
                    $needed['wpmcp/update-product'] = true;
                }
                foreach ($op['variations'] as $variation) {
                    if ('create' === $variation['action']) {
                        $needed['wpmcp/create-variation'] = true;
                    } elseif ('update' === $variation['action']) {
                        $needed['wpmcp/update-variation'] = true;
                    }
                }
            }
        }

        $registrar = Plugin::instance()->registrar();
        foreach (array_keys($needed) as $name) {
            $ability = $registrar->get($name);
            if (null === $ability || ! $registrar->is_permitted($ability, $input)) {
                throw new \RuntimeException(esc_html($name) . ' is not permitted for this user on this site. Nothing was written.');
            }
        }
    }

    private function row_results(array $rows, array $ids): array
    {
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = [
                'row'    => $row['row'],
                'sku'    => $row['sku'],
                'action' => $row['action'],
                'id'     => $ids[ $index ] ?? ($row['id'] ?? null),
            ];
        }
        return $out;
    }
}
