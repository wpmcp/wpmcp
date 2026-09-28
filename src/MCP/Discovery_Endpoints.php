<?php

namespace WPMCP\MCP;

use WPMCP\Auth\Mcp_Resource;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Serves the public discovery documents built by Discovery_Documents
 * (issue #302):
 *
 *  - GET <mcp-endpoint>/server-card             MCP Server Card (SEP-2127)
 *  - GET /.well-known/ai-catalog.json           AI Catalog
 *  - GET /.well-known/agent-skills/index.json   Agent Skills discovery index
 *  - GET /.well-known/agent-skills/<slug>/SKILL.md
 *
 * Served on parse_request, like the OAuth metadata in Auth\Endpoints, and at
 * priority 1 so the card path under /wp-json/ is answered before
 * rest_api_loaded (priority 10) hands it to the REST server. That keeps the
 * headers ours: the REST stack would otherwise echo the caller's Origin with
 * credentials allowed, and Transport_Guard marks everything under /mcp/
 * no-store, while these documents are meant to be cached and read
 * cross-origin.
 *
 * Headers follow the Server Card discovery guidance: open CORS (the
 * documents are public and read-only), public caching with an ETag and
 * If-None-Match revalidation. max-age is five minutes rather than the hour
 * the guidance suggests, because a governance change must stop a skill
 * being advertised soon after an admin disables its tools.
 *
 * Methods other than GET, HEAD and OPTIONS fall through to WordPress.
 */
class Discovery_Endpoints
{
    public const MAX_AGE = 300;

    /** Test seam: when true, maybe_serve() returns the response instead of sending it and exiting. */
    private static bool $test_mode = false;

    public static function set_test_mode(bool $enabled): void
    {
        self::$test_mode = $enabled;
    }

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'maybe_serve'], 1);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}|null
     *         The response in test mode; null when the request is not for a
     *         discovery document (or, in production, after sending one).
     */
    public static function maybe_serve(): ?array
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $path = self::request_path();
        if (! self::is_discovery_path($path) || ! Discovery_Documents::is_enabled()) {
            return null;
        }

        if ('OPTIONS' === $method) {
            return self::send(204, self::cors_headers(), '');
        }

        [$content_type, $body] = self::document_for($path);
        if (null === $body) {
            return self::send(404, self::cors_headers() + [ 'Cache-Control' => 'no-store' ], '');
        }

        $etag    = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $headers = self::cors_headers() + [
            'Content-Type'           => $content_type,
            'Cache-Control'          => 'public, max-age=' . self::MAX_AGE,
            'ETag'                   => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (self::matches_if_none_match($etag)) {
            return self::send(304, $headers, '');
        }

        return self::send(200, $headers, 'HEAD' === $method ? '' : $body);
    }

    private static function is_discovery_path(string $path): bool
    {
        return self::card_path() === $path
            || Discovery_Documents::CATALOG_PATH === $path
            || str_starts_with($path, Discovery_Documents::SKILLS_BASE_PATH);
    }

    /**
     * @return array{0: string, 1: string|null} Content type and body; a null
     *                                          body means 404.
     */
    private static function document_for(string $path): array
    {
        $json = 'application/json';

        if (self::card_path() === $path) {
            return [ Discovery_Documents::SERVER_CARD_TYPE, self::encode(Discovery_Documents::server_card()) ];
        }
        if (Discovery_Documents::CATALOG_PATH === $path) {
            return [ 'application/ai-catalog+json', self::encode(Discovery_Documents::ai_catalog()) ];
        }
        if (Discovery_Documents::SKILLS_INDEX_PATH === $path) {
            return [ $json, self::encode(Discovery_Documents::skills_index()) ];
        }

        // /.well-known/agent-skills/<slug>/SKILL.md, where <slug> must be a
        // single literal name segment. Anything else, including dot
        // segments, is a 404 without touching the skill library.
        $rest = substr($path, strlen(Discovery_Documents::SKILLS_BASE_PATH));
        if (preg_match('#^([a-z0-9]+(?:-[a-z0-9]+)*)/SKILL\.md$#', $rest, $m)) {
            return [ 'text/markdown; charset=utf-8', Discovery_Documents::skill_markdown($m[1]) ];
        }

        return [ $json, null ];
    }

    /** @param array<string, mixed>|null $document */
    private static function encode(?array $document): ?string
    {
        if (null === $document) {
            return null;
        }

        $body = wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $body ? null : $body;
    }

    /** The card's path relative to the site home, e.g. /wp-json/mcp/wpmcp-server/server-card. */
    private static function card_path(): string
    {
        return Mcp_Resource::relative_path() . Discovery_Documents::SERVER_CARD_SUFFIX;
    }

    /** @return array<string, string> */
    private static function cors_headers(): array
    {
        return [
            'Access-Control-Allow-Origin'   => '*',
            'Access-Control-Allow-Methods'  => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers'  => 'Content-Type, If-None-Match',
            'Access-Control-Expose-Headers' => 'ETag',
        ];
    }

    private static function matches_if_none_match(string $etag): bool
    {
        if (! isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            return false;
        }

        $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']));
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ('*' === $candidate || $etag === $candidate || 'W/' . $etag === $candidate) {
                return true;
            }
        }

        return false;
    }

    /** The current request's path, with a subdirectory install's base path stripped. */
    private static function request_path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ('' === $uri) {
            return '';
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ('' !== $base) {
            if (! str_starts_with($path, $base . '/')) {
                return '';
            }
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function send(int $status, array $headers, string $body): ?array
    {
        if (self::$test_mode) {
            return [ 'status' => $status, 'headers' => $headers, 'body' => $body ];
        }

        Transport_Guard::suppress_error_display();
        status_header($status);
        if (! headers_sent()) {
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ('' !== $body) {
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON or Markdown document with its own Content-Type, not HTML.
        }
        exit;
    }
}
