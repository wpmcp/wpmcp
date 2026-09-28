<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Tools\Media\Media_Import_Snapshot;
use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Brand-aware checks and in-process writes behind the brands.* catalog ops
 * (issue #293). WooCommerce registers brands as the hierarchical
 * product_brand taxonomy, with the brand image stored as the attachment id in
 * the 'thumbnail_id' term meta, and serves them from /wc/v3/products/brands
 * (a subclass of the product categories controller).
 *
 * Woo_Write runs the generic gates first and calls prepare() only for rows
 * that name this taxonomy, so everything here is additive:
 *  - create derives the slug BEFORE the write and refuses a taken one, the
 *    way create-term does, because the term snapshot is keyed by
 *    (taxonomy, slug) and has to match the slug the term actually gets;
 *  - update and delete need an id that is a brand, not merely any term;
 *  - images: {id} must be an image attachment the caller can read, {src}
 *    must pass the remote media guard's URL checks (it is fetched through
 *    that guard at execute time, never by the endpoint's own unguarded
 *    sideload), and alt / name are refused because the endpoint writes them
 *    onto the attachment post, outside the brand's snapshot;
 *  - assign / unassign run in-process rather than through PUT products/{id}:
 *    that endpoint ignores an empty brands list, so it cannot remove a
 *    product's last brand. They need edit_post on the product and the
 *    taxonomy's assign_terms capability, since no REST permission callback
 *    runs for them.
 */
final class Brand_Ops
{
    public const TAXONOMY = 'product_brand';

    /** Image keys a brand write may set; alt and name would edit the attachment itself. */
    private const IMAGE_KEYS = ['id', 'src'];

    /** Products (any status) filed under a brand. */
    public static function usage(int $term_id): int
    {
        $objects = get_objects_in_term([$term_id], self::TAXONOMY);
        return is_wp_error($objects) ? 0 : count((array) $objects);
    }

    /**
     * The brand a param names, or null when it is not a positive id of a
     * term in the brand taxonomy.
     *
     * @param mixed $value
     */
    public static function term($value): ?\WP_Term
    {
        $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($id <= 0) {
            return null;
        }
        $term = get_term($id, self::TAXONOMY);
        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * What the confirm refusal of a brand delete reports: how many products
     * would lose the brand.
     *
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    public static function confirm_context(array $params): array
    {
        $term = self::term($params['id'] ?? null);
        return null === $term ? [] : ['products_using' => self::usage((int) $term->term_id)];
    }

    /**
     * Brand checks for one op. Returns a structured error, or the body to
     * dispatch (possibly rewritten) plus anything to add to the result.
     *
     * @param array<string, mixed> $params the caller's params, path params included
     * @param array<string, mixed> $body   what remains after route resolution
     * @return array<string, mixed>
     */
    public static function prepare(string $op, array $params, array $body): array
    {
        $action = substr($op, (int) strrpos($op, '.') + 1);

        if (in_array($action, ['assign', 'unassign'], true)) {
            return self::prepare_assignment($op, $params, $body);
        }

        $report = [];
        if ('create' === $action) {
            $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
            if ('' === $name) {
                return Op_Guard::error('invalid_params', "Op \"{$op}\" requires a brand name.");
            }
            $slug = isset($body['slug']) && is_scalar($body['slug']) && '' !== (string) $body['slug']
                ? sanitize_title((string) $body['slug'])
                : sanitize_title($name);
            if ('' === $slug) {
                return Op_Guard::error('invalid_params', 'The brand name yields an empty slug; pass a slug.');
            }
            if (get_term_by('slug', $slug, self::TAXONOMY) instanceof \WP_Term) {
                return Op_Guard::error(
                    'brand_exists',
                    "A brand with slug \"{$slug}\" already exists. Change it with brands.update.",
                    [ 'slug' => $slug ]
                );
            }
            $body['slug'] = $slug;
        } else {
            $term = self::term($params['id'] ?? null);
            if (null === $term) {
                return Op_Guard::error('unknown_brand', 'No brand has that id. List brands with brands.list.');
            }
            if ('delete' === $action) {
                $report['products_using'] = self::usage((int) $term->term_id);
            }
        }

        if (isset($body['parent']) && 0 !== (int) $body['parent'] && null === self::term($body['parent'])) {
            return Op_Guard::error('unknown_brand', 'The parent must be an existing brand id.');
        }

        if (array_key_exists('image', $body)) {
            $image = self::image($body['image']);
            if (isset($image['error'])) {
                return $image;
            }
            $body['image'] = $image;
        }

        return [ 'body' => $body, 'report' => $report ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function prepare_assignment(string $op, array $params, array $body): array
    {
        $raw        = $params['product_id'] ?? null;
        $product_id = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        if ($product_id <= 0 || 'product' !== get_post_type($product_id)) {
            return Op_Guard::error('invalid_params', "Op \"{$op}\" needs product_id to be a product.");
        }

        $taxonomy = get_taxonomy(self::TAXONOMY);
        if (! current_user_can('edit_post', $product_id) || ! $taxonomy || ! current_user_can($taxonomy->cap->assign_terms)) {
            return Op_Guard::error(
                'operation_denied',
                "Op \"{$op}\" requires permission to edit this product and to assign brands.",
                [ 'reason' => 'capability' ]
            );
        }

        $given = $body['brands'] ?? null;
        if (! is_array($given) || [] === $given || ! array_is_list($given)) {
            return Op_Guard::error('invalid_params', "Op \"{$op}\" needs brands: a non-empty list of brand ids.");
        }

        $ids = [];
        foreach ($given as $value) {
            $term = self::term($value);
            if (null === $term) {
                return Op_Guard::error('unknown_brand', 'Every entry in brands must be an existing brand id.');
            }
            $ids[] = (int) $term->term_id;
        }

        return [ 'body' => [ 'brands' => array_values(array_unique($ids)) ], 'report' => [] ];
    }

    /**
     * Validate and normalize a brand image to ['id' => N] (0 clears the
     * image) or ['src' => url].
     *
     * @param mixed $image
     * @return array<string, mixed>
     */
    private static function image($image): array
    {
        if (! is_array($image)) {
            return self::image_error('image must be an object: {id} of a media image, or {src}.');
        }
        foreach (array_keys($image) as $key) {
            if (! in_array($key, self::IMAGE_KEYS, true)) {
                return self::image_error(
                    'image accepts only id or src. alt and name would edit the attachment itself, which the '
                    . 'brand snapshot does not cover; change them on the media item instead.'
                );
            }
        }

        $src = isset($image['src']) && is_string($image['src']) ? trim($image['src']) : '';
        $raw = $image['id'] ?? 0;
        $id  = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : -1;

        if ('' !== $src) {
            if (0 !== $id) {
                return self::image_error('Pass either image.id or image.src, not both.');
            }
            try {
                Remote_Image_Guard::validate_url($src);
            } catch (\InvalidArgumentException $e) {
                return self::image_error('image.src was refused by the remote media guard: ' . $e->getMessage());
            }
            return [ 'src' => $src ];
        }

        if ($id < 0) {
            return self::image_error('image.id must be a media id (0 removes the brand image).');
        }
        if ($id > 0 && (! wp_attachment_is_image($id) || ! current_user_can('read_post', $id))) {
            return self::image_error("Media {$id} is not an image in the Media Library that you can use.");
        }

        return [ 'id' => $id ];
    }

    /** @return array<string, mixed> */
    private static function image_error(string $message): array
    {
        return Op_Guard::error('invalid_image', $message);
    }

    /**
     * Fetch an image.src through the remote media guard and swap it for the
     * new attachment's id. The import is recorded as its own 'media_import'
     * operation in the same session, so rollback-session removes the file
     * too. Returns the updated body and that operation id (null when there
     * was nothing to fetch).
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function materialize_image(array $body, string $session_id): array
    {
        if (! isset($body['image']['src'])) {
            return [ $body, null ];
        }

        $src      = (string) $body['image']['src'];
        $media_id = Remote_Image_Guard::sideload($src, 0, 'brand-image');
        $op_id    = Media_Import_Snapshot::record('woo-write', $media_id, [ 'src' => $src ], $session_id);

        $body['image'] = [ 'id' => $media_id ];
        return [ $body, $op_id ];
    }

    /**
     * Add or remove brands on one product in-process. Already-snapshotted
     * by the caller.
     *
     * @param int[] $brand_ids
     * @return array{status: int, body: mixed}
     */
    public static function apply_assignment(string $action, int $product_id, array $brand_ids): array
    {
        $result = 'unassign' === $action
            ? wp_remove_object_terms($product_id, $brand_ids, self::TAXONOMY)
            : wp_set_object_terms($product_id, $brand_ids, self::TAXONOMY, true);

        if (is_wp_error($result)) {
            return [
                'status' => 500,
                'body'   => [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ],
            ];
        }

        clean_post_cache($product_id);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }

        $now = wp_get_object_terms($product_id, self::TAXONOMY, [ 'fields' => 'ids' ]);
        $now = is_wp_error($now) ? [] : array_map('intval', $now);
        sort($now);

        return [
            'status' => 200,
            'body'   => [ 'product_id' => $product_id, 'brands' => $now ],
        ];
    }
}
