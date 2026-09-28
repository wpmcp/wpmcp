<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The two MCP protocol revisions WP MCP serves, and what differs between
 * them on the wire (issue #386).
 *
 * 2025-11-25 is session based: initialize negotiates the revision once and
 * every later request rides the session. 2026-07-28 drops initialize: the
 * client calls the sessionless server/discover, then carries the revision
 * and its capabilities in params._meta on every request (and, over HTTP,
 * in the MCP-Protocol-Version / Mcp-Method / Mcp-Name headers). Results
 * gain resultType, list and read results gain cache hints, and the server
 * identifies itself in result _meta instead of in an initialize result.
 *
 * The WordPress MCP adapter implements 2026-07-28 from 0.7.0 on. The copy
 * WP MCP bundles (0.6.x) predates it, so this class is the one place that
 * knows the revision's rules; the stdio transport and, when the loaded
 * adapter cannot, the HTTP route both answer 2026-07-28 through it.
 */
final class Protocol_Revision
{
    public const MODERN = '2026-07-28';
    public const LEGACY = '2025-11-25';

    public const META_PROTOCOL_VERSION    = 'io.modelcontextprotocol/protocolVersion';
    public const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const META_SERVER_INFO         = 'io.modelcontextprotocol/serverInfo';

    public const INVALID_REQUEST     = -32600;
    public const METHOD_NOT_FOUND    = -32601;
    public const INVALID_PARAMS      = -32602;
    public const RESOURCE_NOT_FOUND  = -32002;
    public const HEADER_MISMATCH     = -32020;
    public const UNSUPPORTED_VERSION = -32022;

    /** Methods a 2026-07-28 request may call. initialize and ping are 2025 only. */
    public const MODERN_METHODS = [
        'server/discover',
        'tools/list',
        'tools/call',
        'prompts/list',
        'prompts/get',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];

    /** Results that carry the ttlMs / cacheScope cache hints. */
    private const CACHEABLE = [
        'server/discover',
        'tools/list',
        'prompts/list',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];

    /** The adapter class that owns 2025 initialize negotiation. */
    private const NEGOTIATOR = '\\WP\\MCP\\Core\\McpVersionNegotiator';

    /**
     * Schema-backed revisions, newest first: what server/discover and the
     * unsupported-version error advertise.
     *
     * @return string[]
     */
    public static function supported_versions(): array
    {
        return [ self::MODERN, self::LEGACY ];
    }

    /**
     * Every identifier the endpoint accepts, for discovery documents: the
     * schema-backed revisions plus the legacy identifiers the loaded
     * adapter negotiates through initialize (0.6.x lists them as supported,
     * 0.7.0 separately as legacy).
     *
     * @return string[]
     */
    public static function advertised_versions(): array
    {
        $versions = self::supported_versions();

        if (class_exists(self::NEGOTIATOR)) {
            $negotiator = self::NEGOTIATOR;
            foreach (['SUPPORTED_PROTOCOL_VERSIONS', 'LEGACY_PROTOCOL_VERSIONS'] as $list) {
                if (defined($negotiator . '::' . $list)) {
                    $versions = array_merge($versions, (array) constant($negotiator . '::' . $list));
                }
            }
        }

        return array_values(array_unique(array_map('strval', $versions)));
    }

    /**
     * The revision a request names in params._meta, or null when it names
     * none (every 2025 request after initialize).
     *
     * @param array<string,mixed> $params Request params.
     */
    public static function requested(array $params): ?string
    {
        $meta = $params['_meta'] ?? null;
        if (is_object($meta)) {
            $meta = (array) $meta;
        }
        if (! is_array($meta) || ! isset($meta[ self::META_PROTOCOL_VERSION ])) {
            return null;
        }

        return is_string($meta[ self::META_PROTOCOL_VERSION ]) ? $meta[ self::META_PROTOCOL_VERSION ] : '';
    }

    /**
     * Whether a request names 2026-07-28 client capabilities, which that
     * revision requires on every request.
     *
     * @param array<string,mixed> $params Request params.
     */
    public static function has_client_capabilities(array $params): bool
    {
        $meta = $params['_meta'] ?? null;
        if (is_object($meta)) {
            $meta = (array) $meta;
        }
        if (! is_array($meta) || ! array_key_exists(self::META_CLIENT_CAPABILITIES, $meta)) {
            return false;
        }

        // Stdio decodes JSON objects to arrays, HTTP may hand over stdClass.
        $capabilities = $meta[ self::META_CLIENT_CAPABILITIES ];
        return is_object($capabilities) || (is_array($capabilities) && ([] === $capabilities || ! array_is_list($capabilities)));
    }

    /**
     * Whether a revision named on a request can be served at all: the
     * modern one, or anything the 2025 lifecycle negotiates.
     */
    public static function is_servable(string $version): bool
    {
        if (self::MODERN === $version || self::LEGACY === $version) {
            return true;
        }

        return self::negotiate_initialize($version) === $version;
    }

    /**
     * The 2025 initialize negotiation: echo a revision the lifecycle serves,
     * otherwise counter-propose 2025-11-25. A client proposing 2026-07-28
     * here gets 2025-11-25 too, because 2026-07-28 has no initialize.
     *
     * Delegates to the loaded adapter's negotiator, which owns the list of
     * legacy identifiers the HTTP route accepts, so both transports agree.
     */
    public static function negotiate_initialize(string $requested): string
    {
        if (class_exists(self::NEGOTIATOR)) {
            $negotiator = self::NEGOTIATOR;
            $negotiated = (string) $negotiator::negotiate($requested);

            // 0.6.x lists only 2025 revisions, so this never fires there; it
            // keeps a future negotiator that does list 2026-07-28 from
            // opening an initialize-based 2026 session.
            return self::MODERN === $negotiated ? self::LEGACY : $negotiated;
        }

        return self::LEGACY;
    }

    /**
     * Whether the adapter serving the HTTP route implements 2026-07-28
     * itself (0.7.0 and later).
     */
    public static function adapter_speaks_modern(): bool
    {
        if (! class_exists(self::NEGOTIATOR)) {
            return false;
        }

        $negotiator = self::NEGOTIATOR;
        $supported  = defined($negotiator . '::SUPPORTED_PROTOCOL_VERSIONS')
            ? (array) constant($negotiator . '::SUPPORTED_PROTOCOL_VERSIONS')
            : [];

        return in_array(self::MODERN, $supported, true);
    }

    /**
     * The server/discover result, before the 2026 result fields are added.
     *
     * @param array<string,mixed> $capabilities The server capabilities.
     * @return array<string,mixed>
     */
    public static function discover_result(array $capabilities): array
    {
        $result = [
            'supportedVersions' => self::supported_versions(),
            'capabilities'      => $capabilities,
        ];

        // The same guidance the 2025 initialize handshake carries.
        $instructions = (new Handshake_Instructions())->build();
        if ('' !== $instructions) {
            $result['instructions'] = $instructions;
        }

        return $result;
    }

    /**
     * Adds the fields every 2026-07-28 result carries: resultType, the cache
     * hints on list and read results, and the server's identity in _meta.
     *
     * @param string              $method The request method.
     * @param array<string,mixed> $result The revision-neutral result.
     * @return array<string,mixed>
     */
    public static function complete(string $method, array $result): array
    {
        $result['resultType'] = 'complete';

        if (in_array($method, self::CACHEABLE, true)) {
            $result['ttlMs']      = 0;
            $result['cacheScope'] = 'private';
        }

        $meta = isset($result['_meta']) && is_array($result['_meta']) ? $result['_meta'] : [];
        $meta[ self::META_SERVER_INFO ] = [
            'name'    => Server::SERVER_NAME,
            'version' => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
        ];
        $result['_meta'] = $meta;

        return $result;
    }

    /**
     * The -32022 error for a revision this server does not serve.
     *
     * @param mixed $id Request id.
     * @return array<string,mixed>
     */
    public static function unsupported_version_error($id, string $requested): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => self::UNSUPPORTED_VERSION,
                'message' => sprintf('Unsupported protocol version: %s', $requested),
                'data'    => [
                    'requested' => $requested,
                    'supported' => self::supported_versions(),
                ],
            ],
        ];
    }
}
