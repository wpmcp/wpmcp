<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read dispatcher for the deep WooCommerce operations catalog (issue #68).
 * Resolves a named op through Op_Catalog and dispatches it as an INTERNAL
 * REST request to the store's own wc/v3 route via Wc_Rest_Dispatch:
 * rest_do_request() with a WP_REST_Request, no HTTP loopback, running as the
 * current authenticated user so the endpoint's own permission_callback (and
 * therefore WooCommerce's store permission model) is the final gate.
 *
 * On top of that inherited check this class applies the wpmcp-layer gates
 * BEFORE dispatch:
 *
 *  1. read-only: the op must exist in the catalog AND its row must be a
 *     read-mode GET. Wc_Rest_Dispatch is GET-only by construction as well,
 *     so the invariant survives a caller that forgets to check;
 *  2. the gates shared with woo-write (Op_Guard): WooCommerce availability
 *     (a structured integration_unavailable error instead of a bare
 *     rest_no_route 404), op-level governance (a synthetic
 *     wpmcp/woo-{op} ability through the six-layer model), and the per-op
 *     capability (order, note and refund ops require edit_shop_orders and
 *     customer ops list_users, so this surface is never looser than the free
 *     tool covering the same data).
 *
 * Raw wc/v3 bodies are large: list ops get a conservative per_page default
 * and a hard cap, so a single call cannot flood a model context with a
 * hundred full order or customer records.
 */
class Woo_Read
{
    /** Conservative page size for list ops; raw wc/v3 records are large. */
    public const DEFAULT_PER_PAGE = 20;

    /** Hard ceiling on per_page, below wc/v3's own maximum of 100. */
    public const MAX_PER_PAGE = 50;

    /** @var Wc_Rest_Dispatch */
    private $dispatch;

    public function __construct(?Wc_Rest_Dispatch $dispatch = null)
    {
        $this->dispatch = $dispatch ?: new Wc_Rest_Dispatch();
    }

    public function handle(array $args): array
    {
        $op = (string) ($args['op'] ?? '');
        if ('' === $op) {
            throw new \InvalidArgumentException('An op is required. Call woo-ops for the catalog.');
        }

        $def = Op_Catalog::get($op);
        $this->assert_read_op($op, $def);

        $denied = Op_Guard::check($op, $def);
        if (null !== $denied) {
            return $denied;
        }

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
        [ $route, $query ] = Op_Catalog::resolve_route($op, $params);

        // Zone reads, review listing, reports, gateways and system status
        // (issue #292) run in-process: zones carry their locations and
        // methods in one call, reviews are filtered by rating and stripped of
        // reviewer emails, reports aggregate over the analytics tables or the
        // order store, and gateway settings and the status report are masked.
        $handler = self::handler_class($def);
        if (null !== $handler) {
            $out = $handler::read((string) $def['handler'], $params);
            if (isset($out['error'])) {
                return $out;
            }
            return [ 'op' => $op, 'route' => $route, 'status' => $out['status'], 'body' => $out['body'] ];
        }

        $out = $this->dispatch->get($route, $this->apply_query_defaults($def, $query));

        return [
            'op'     => $op,
            'route'  => $route,
            'status' => $out['status'],
            'body'   => self::redact($out['body'], $def['redact'] ?? []),
        ];
    }

    /**
     * The class an in-process read handler runs through, by its prefix.
     *
     * @return class-string<Shipping_Ops>|class-string<Review_Ops>|class-string<Report_Ops>|class-string<Gateway_Ops>|class-string<Status_Ops>|null
     */
    private static function handler_class(array $def): ?string
    {
        $handler = $def['handler'] ?? null;
        if (! is_string($handler)) {
            return null;
        }
        foreach ([ 'shipping_' => Shipping_Ops::class, 'review_' => Review_Ops::class, 'report_' => Report_Ops::class, 'gateway_' => Gateway_Ops::class, 'status_' => Status_Ops::class ] as $prefix => $class) {
            if (str_starts_with($handler, $prefix)) {
                return $class;
            }
        }
        return null;
    }

    /** Whether the host plugin is loaded, mirroring Integration_Dispatcher. */
    public static function is_available(): bool
    {
        return Op_Guard::is_available();
    }

    /**
     * This dispatcher refuses anything whose row is not a read-mode GET;
     * write and destructive rows belong to woo-write.
     *
     * @param array{method: string, mode?: string} $def
     */
    protected function assert_read_op(string $op, array $def): void
    {
        if ('GET' !== ($def['method'] ?? '') || 'read' !== ($def['mode'] ?? 'read')) {
            throw new \RuntimeException(esc_html("Op \"{$op}\" is not a read op and cannot be dispatched by woo-read."));
        }
    }

    /**
     * Collection ops get a bounded per_page; single-resource ops (the ones
     * with an {id}-style path param) take no paging params at all, so none
     * are injected there.
     *
     * @param array{path_params: string[]} $def
     * @param array<string, mixed>         $query
     * @return array<string, mixed>
     */
    protected function apply_query_defaults(array $def, array $query): array
    {
        if ($this->is_single_resource($def)) {
            return $query;
        }

        if (! isset($query['per_page'])) {
            $query['per_page'] = self::DEFAULT_PER_PAGE;
            return $query;
        }

        $per_page = (int) $query['per_page'];
        if ($per_page < 1) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        $query['per_page'] = min($per_page, self::MAX_PER_PAGE);

        return $query;
    }

    /** @param array{path_params: string[]} $def */
    private function is_single_resource(array $def): bool
    {
        return in_array('id', $def['path_params'] ?? [], true);
    }

    /**
     * Mask the row's redacted keys in a single record or in each record of
     * a list body.
     *
     * @param mixed    $body
     * @param string[] $keys
     * @return mixed
     */
    private static function redact($body, array $keys)
    {
        if ([] === $keys || ! is_array($body)) {
            return $body;
        }

        $mask = static function (array $record) use ($keys): array {
            foreach ($keys as $key) {
                if (array_key_exists($key, $record) && '' !== $record[ $key ] && null !== $record[ $key ]) {
                    $record[ $key ] = '[redacted]';
                }
            }
            return $record;
        };

        if (array_is_list($body)) {
            return array_map(static fn ($record) => is_array($record) ? $mask($record) : $record, $body);
        }
        return $mask($body);
    }
}
