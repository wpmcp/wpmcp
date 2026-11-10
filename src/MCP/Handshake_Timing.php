<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A Server-Timing header on every response of the MCP route (issue #733).
 *
 * Clients allow the handshake about 5 seconds: Claude Desktop skips its
 * connector checks past that, and Claude Code's version negotiation probe
 * times out at 5000ms. On a site behind a tunnel nobody could tell how much
 * of a slow handshake was the server and how much the network. The header
 * says it, readable with `curl -i` or in the client's network log:
 *
 *  - app: milliseconds from the start of the PHP request (REQUEST_TIME_FLOAT)
 *    to the response, so WordPress and every plugin booting are included;
 *  - abilities: milliseconds spent registering abilities
 *    (wp_abilities_api_init, every plugin's, not only WP MCP's), present when
 *    this request ran the registration.
 *
 * Durations only: no names, versions or paths, so it tells a caller nothing
 * the response time does not already show.
 */
final class Handshake_Timing
{
    private static ?float $abilities_started = null;

    private static ?float $abilities_ms = null;

    public static function register(): void
    {
        add_action('wp_abilities_api_init', [self::class, 'abilities_start'], PHP_INT_MIN);
        add_action('wp_abilities_api_init', [self::class, 'abilities_end'], PHP_INT_MAX);
        add_filter('rest_post_dispatch', [self::class, 'add_header'], 10, 3);
    }

    public static function abilities_start(): void
    {
        self::$abilities_started = microtime(true);
    }

    public static function abilities_end(): void
    {
        if (null !== self::$abilities_started) {
            self::$abilities_ms      = (microtime(true) - self::$abilities_started) * 1000;
            self::$abilities_started = null;
        }
    }

    /**
     * rest_post_dispatch on the MCP route only.
     *
     * @param mixed $response
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function add_header($response, $server = null, $request = null)
    {
        if (
            ! $response instanceof \WP_HTTP_Response
            || ! $request instanceof \WP_REST_Request
            || '/' . Server::NAMESPACE . '/' . Server::SERVER_ID !== $request->get_route()
        ) {
            return $response;
        }

        $response->header('Server-Timing', self::header_value(microtime(true)));

        return $response;
    }

    /** The header value at time $now (seconds, as microtime(true)). */
    public static function header_value(float $now): string
    {
        $start   = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : $now;
        $metrics = [];
        if (null !== self::$abilities_ms) {
            $metrics[] = sprintf('abilities;dur=%.1f', self::$abilities_ms);
        }
        $metrics[] = sprintf('app;dur=%.1f', max(0.0, ($now - $start) * 1000));

        return implode(', ', $metrics);
    }

    public static function reset_for_tests(): void
    {
        self::$abilities_started = null;
        self::$abilities_ms      = null;
    }
}
