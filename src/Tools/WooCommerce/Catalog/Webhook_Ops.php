<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Webhook_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Webhook create, update, pause and delete behind the webhooks.* write ops
 * (issue #292), in-process through WC_Webhook rather than the wc/v3
 * webhooks route, so every param is validated here first and unknown params
 * are refused rather than ignored.
 *
 *  - The signing secret is write-only: a caller may set one (or one is
 *    generated), and every response masks it as [redacted].
 *  - The delivery URL must be https, carry no credentials, use the default
 *    port and pass the SSRF guard: core's wp_http_validate_url() (the check
 *    WooCommerce's own deliveries run through wp_safe_remote_request()), plus
 *    a refusal of any private, loopback, link-local or reserved address the
 *    host resolves to, which also covers cloud metadata endpoints that core
 *    lets through.
 *  - A webhook always delivers as the user who made it. user_id is refused,
 *    because the payload is built with that user's permissions.
 *  - Topics are the resource.event topics WooCommerce validates. Custom
 *    action.* topics are refused: they forward arbitrary hook arguments to
 *    an outside URL.
 *
 * Woo_Write wraps update, pause and delete in Safe_Mutation with a raw-row
 * snapshot (Wc_Webhook_Snapshot::TYPE); a create records a creation row
 * itself once the webhook exists, and its rollback deletes the webhook.
 */
final class Webhook_Ops
{
    public const MASK = '[redacted]';

    private const KEYS = [
        'webhook_create' => ['name', 'topic', 'delivery_url', 'status', 'secret'],
        'webhook_update' => ['id', 'name', 'topic', 'delivery_url', 'status', 'secret'],
        'webhook_pause'  => ['id'],
        'webhook_delete' => ['id'],
    ];

    /** Params that are refused outright, with the reason. */
    private const FORBIDDEN = [
        'user_id' => 'A webhook always delivers as the user who creates it: its payloads are built with that user\'s permissions.',
    ];

    /**
     * Validate and normalize the params of one webhook write. Returns a
     * structured error, or ['body' => normalized plan]. Writes nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params): array
    {
        try {
            $body = self::validate($handler, $params);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return [ 'body' => $body ];
    }

    /**
     * Create the webhook and record its creation row.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        $webhook = new \WC_Webhook();
        $webhook->set_name($plan['name'] ?? '');
        $webhook->set_topic($plan['topic']);
        $webhook->set_delivery_url($plan['delivery_url']);
        $webhook->set_secret($plan['secret'] ?? wp_generate_password(50, true, true));
        $webhook->set_status($plan['status'] ?? 'active');
        $webhook->set_user_id(get_current_user_id());
        $webhook->set_api_version('wp_api_v3');
        $id = (int) $webhook->save();
        if ($id <= 0) {
            return Op_Guard::error('webhook_create_failed', 'WooCommerce did not create the webhook.');
        }

        // The creation args are hashed into the ledger; the secret is not part of them.
        $operation_id = Wc_Webhook_Snapshot::record_creation($id, 'woo-write', [ 'op' => $op, 'params' => self::without_secret($plan) ], $session_id);

        return [ 'status' => 201, 'body' => self::view($id), 'operation_id' => $operation_id ];
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the raw-row
     * snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        $webhook = wc_get_webhook($id);
        if (! $webhook instanceof \WC_Webhook) {
            throw new \RuntimeException('The webhook disappeared before it could be changed.');
        }

        if ('webhook_delete' === $handler) {
            $view = self::view($id);
            $webhook->delete(true);
            return [ 'status' => 200, 'body' => [ 'deleted' => true, 'previous' => $view ] ];
        }

        if ('webhook_pause' === $handler) {
            $plan = [ 'status' => 'paused' ];
        }
        if (array_key_exists('name', $plan)) {
            $webhook->set_name($plan['name']);
        }
        if (array_key_exists('topic', $plan)) {
            $webhook->set_topic($plan['topic']);
        }
        if (array_key_exists('delivery_url', $plan)) {
            $webhook->set_delivery_url($plan['delivery_url']);
        }
        if (array_key_exists('secret', $plan)) {
            $webhook->set_secret($plan['secret']);
        }
        if (array_key_exists('status', $plan)) {
            $webhook->set_status($plan['status']);
        }
        $webhook->save();

        return [ 'status' => 200, 'body' => self::view($id) ];
    }

    /**
     * The webhook as a response shows it: every field but the secret, which
     * is masked.
     *
     * @return array<string, mixed>
     */
    public static function view(int $id): array
    {
        $row = Wc_Webhook_Snapshot::row($id);
        if (null === $row) {
            return [];
        }
        $webhook = wc_get_webhook($id);
        return [
            'id'            => (int) $row['webhook_id'],
            'name'          => (string) $row['name'],
            'status'        => (string) $row['status'],
            'topic'         => (string) $row['topic'],
            'resource'      => $webhook ? $webhook->get_resource() : '',
            'event'         => $webhook ? $webhook->get_event() : '',
            'delivery_url'  => (string) $row['delivery_url'],
            'secret'        => '' === (string) $row['secret'] ? '' : self::MASK,
            'user_id'       => (int) $row['user_id'],
            'api_version'   => $webhook ? $webhook->get_api_version() : '',
            'failure_count' => (int) $row['failure_count'],
            'date_created'  => (string) $row['date_created_gmt'],
            'date_modified' => (string) $row['date_modified_gmt'],
        ];
    }

    // ------------------------------------------------------------ validation

    /** @return array<string, mixed> */
    private static function validate(string $handler, array $params): array
    {
        if (! isset(self::KEYS[ $handler ])) {
            self::refuse('invalid_params', 'Unknown webhook handler.');
        }
        foreach (self::FORBIDDEN as $key => $why) {
            if (array_key_exists($key, $params)) {
                self::refuse('forbidden_param', "\"{$key}\" is refused. {$why}", [ 'param' => $key ]);
            }
        }
        self::refuse_unknown($params, self::KEYS[ $handler ]);

        $plan = [];
        if ('webhook_create' !== $handler) {
            $id = $params['id'] ?? null;
            $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
            if ($id <= 0 || null === Wc_Webhook_Snapshot::row($id)) {
                self::refuse('unknown_webhook', 'No webhook has that id. List webhooks with webhooks.list.');
            }
            if ('webhook_update' !== $handler) {
                return [ 'id' => $id ];
            }
            $plan['id'] = $id;
        }

        if ('webhook_create' === $handler) {
            foreach (['topic', 'delivery_url'] as $required) {
                if (! array_key_exists($required, $params)) {
                    self::refuse('invalid_params', "webhooks.create needs {$required}.");
                }
            }
        }

        if (array_key_exists('name', $params)) {
            if (! is_string($params['name']) || strlen($params['name']) > 200) {
                self::refuse('invalid_params', 'name must be a string of up to 200 characters.');
            }
            $plan['name'] = sanitize_text_field($params['name']);
        }
        if (array_key_exists('topic', $params)) {
            $plan['topic'] = self::topic($params['topic']);
        }
        if (array_key_exists('delivery_url', $params)) {
            $plan['delivery_url'] = self::delivery_url($params['delivery_url']);
        }
        if (array_key_exists('status', $params)) {
            if (! is_string($params['status']) || ! wc_is_webhook_valid_status($params['status'])) {
                $allowed = array_keys(wc_get_webhook_statuses());
                self::refuse('invalid_params', 'status must be one of: ' . implode(', ', $allowed) . '.', [ 'allowed' => $allowed ]);
            }
            $plan['status'] = $params['status'];
        }
        if (array_key_exists('secret', $params)) {
            if (! is_string($params['secret']) || strlen($params['secret']) < 8 || strlen($params['secret']) > 200) {
                self::refuse('invalid_params', 'secret must be a string of 8 to 200 characters.');
            }
            $plan['secret'] = $params['secret'];
        }

        if ('webhook_update' === $handler && [] === array_diff(array_keys($plan), ['id'])) {
            self::refuse('invalid_params', 'webhooks.update needs at least one change: name, topic, delivery_url, status or secret.');
        }
        return $plan;
    }

    private static function topic($value): string
    {
        $topic = is_string($value) ? trim($value) : '';
        if (str_starts_with($topic, 'action.')) {
            self::refuse('invalid_params', 'Custom action.* topics are refused: they forward arbitrary hook arguments to an outside URL. Use a resource.event topic such as order.created.');
        }
        if ('' === $topic || ! wc_is_webhook_valid_topic($topic)) {
            self::refuse('invalid_params', "\"{$topic}\" is not a valid webhook topic. Use resource.event, for example order.created, product.updated or customer.deleted.");
        }
        return $topic;
    }

    /**
     * The SSRF guard for a delivery URL. Every refusal names the rule, and
     * nothing is fetched here: resolution is a DNS lookup only.
     */
    private static function delivery_url($value): string
    {
        $url   = is_string($value) ? trim($value) : '';
        $parts = '' === $url ? false : wp_parse_url($url);
        $fail  = static function (string $why) use ($url): void {
            self::refuse('invalid_delivery_url', "The delivery URL was refused: {$why}", [ 'delivery_url' => $url ]);
        };

        if (! is_array($parts) || empty($parts['host'])) {
            $fail('it could not be parsed as a URL.');
        }
        if ('https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            $fail('only https delivery URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('it must not embed credentials.');
        }
        if (isset($parts['port']) && 443 !== (int) $parts['port']) {
            $fail('it must use the default https port.');
        }
        if (strlen($url) > 2000 || esc_url_raw($url, ['https']) !== $url) {
            $fail('it is not a clean https URL.');
        }
        if (false === wp_http_validate_url($url)) {
            $fail('its host does not resolve to a public address.');
        }

        $host = trim(strtolower((string) $parts['host']), '[].');
        $ips  = filter_var($host, FILTER_VALIDATE_IP) ? [ $host ] : (array) gethostbynamel($host);
        if ([] === $ips) {
            $fail('its host does not resolve.');
        }
        foreach ($ips as $ip) {
            if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail('its host resolves to a private, loopback, link-local or reserved address.');
            }
        }

        return $url;
    }

    /** @param array<string, mixed> $plan */
    private static function without_secret(array $plan): array
    {
        unset($plan['secret']);
        return $plan;
    }

    /**
     * Stop validation with a structured refusal, caught in prepare().
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in prepare() and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
