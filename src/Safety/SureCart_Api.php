<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The bridge into SureCart's own models (issue #665). SureCart keeps its
 * store (products, orders, customers, coupons) in its cloud and reaches it
 * through \SureCart\Models\*, which call its API with the site's connection
 * token. Going through the models, not the raw request, keeps SureCart's own
 * side effects: a product update re-syncs its local sc_product post, and a
 * write clears SureCart's account cache.
 *
 * Every answer comes back as a plain array, or a \WP_Error as SureCart
 * returns it. Both the SureCart pack and SureCart_Snapshot (which puts a
 * record back on undo) call through here, so the test suite swaps in one
 * in-memory stand-in for both (use_for_tests()).
 */
final class SureCart_Api
{
    /** The resources the pack reads or writes, by SureCart model. */
    private const MODELS = [
        'products'  => 'SureCart\\Models\\Product',
        'orders'    => 'SureCart\\Models\\Order',
        'customers' => 'SureCart\\Models\\Customer',
        'coupons'   => 'SureCart\\Models\\Coupon',
    ];

    /** @var callable|null fn(string $resource, string $action, array $params): array|\WP_Error */
    private static $stub = null;

    /** Test seam: answer every call from $stub instead of SureCart. */
    public static function use_for_tests(?callable $stub): void
    {
        self::$stub = $stub;
    }

    /** Whether SureCart's models are loaded (or the test stand-in is set). */
    public static function loaded(): bool
    {
        return null !== self::$stub || class_exists(self::MODELS['products']);
    }

    /**
     * One call into SureCart.
     *
     * @param string               $resource products, orders, customers or coupons
     * @param string               $action   list, find, update, create or delete
     * @param array<string, mixed> $params   id, attributes, query, expand, page, per_page
     * @return array<string, mixed>|\WP_Error
     */
    public static function call(string $resource, string $action, array $params = [])
    {
        if (null !== self::$stub) {
            $out = (self::$stub)($resource, $action, $params);
            return is_wp_error($out) ? $out : self::plain($out);
        }
        $class = self::MODELS[ $resource ] ?? '';
        if ('' === $class || ! class_exists($class)) {
            return new \WP_Error('surecart_missing', 'SureCart is not loaded on this site.');
        }

        try {
            $out = self::run($class, $action, $params);
        } catch (\Throwable $e) {
            return new \WP_Error('surecart_error', $e->getMessage());
        }
        if (is_wp_error($out)) {
            return $out;
        }
        if (false === $out || null === $out) {
            return 'find' === $action ? new \WP_Error('not_found', 'SureCart found no such record.', [ 'status' => 404 ]) : new \WP_Error('surecart_error', 'SureCart did not complete the request.');
        }
        if ($out instanceof \WP_REST_Response) {
            return new \WP_Error('surecart_error', sprintf('SureCart answered HTTP %d.', $out->get_status()));
        }
        return self::plain($out);
    }

    /** @return mixed */
    private static function run(string $class, string $action, array $params)
    {
        $id     = (string) ($params['id'] ?? '');
        $expand = array_values(array_map('strval', (array) ($params['expand'] ?? [])));
        switch ($action) {
            case 'list':
                $query = $class::where((array) ($params['query'] ?? []));
                if ([] !== $expand) {
                    $query = $query->with($expand);
                }
                $list = $query->paginate([ 'page' => max(1, (int) ($params['page'] ?? 1)), 'per_page' => max(1, (int) ($params['per_page'] ?? 20)) ]);
                if (is_wp_error($list)) {
                    return $list;
                }
                return [ 'data' => $list->data ?? [], 'pagination' => $list->pagination ?? [] ];
            case 'find':
                $model = [] !== $expand ? $class::with($expand) : new $class();
                return $model->find($id);
            case 'update':
                $model = new $class([ 'id' => $id ]);
                return $model->update((array) ($params['attributes'] ?? []));
            case 'create':
                return ( new $class() )->create((array) ($params['attributes'] ?? []));
            case 'delete':
                return ( new $class() )->delete($id);
        }
        return new \WP_Error('surecart_error', sprintf('Unknown SureCart action "%s".', $action));
    }

    /**
     * A SureCart model, collection or stdClass as a plain array.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function plain($value): array
    {
        $decoded = json_decode((string) wp_json_encode($value), true);
        return is_array($decoded) ? $decoded : [];
    }
}
