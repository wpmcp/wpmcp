<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which door the current ability call came in by (issue #412), recorded as
 * the `source` of every governance audit row:
 *
 *  - mcp:    wpmcp's own endpoint (the HTTP route, the stdio transport, or
 *            another wpmcp REST route such as the in-admin chat);
 *  - rest:   core's abilities REST routes (/wp-abilities/v1/...);
 *  - server: any other REST route, e.g. another MCP server on the site;
 *  - cli:    WP-CLI outside the stdio transport;
 *  - php:    anything else, i.e. in-process PHP from another plugin, cron
 *            or an admin screen.
 *
 * A stack rather than a flag, keyed on the INNERMOST entry: a wpmcp tool
 * that dispatches a REST request to core's run route (call-rest) reports
 * that request as rest, so the site-wide layer still sees it.
 */
class Call_Source
{
    public const MCP    = 'mcp';
    public const REST   = 'rest';
    public const SERVER = 'server';
    public const CLI    = 'cli';
    public const PHP    = 'php';

    /** @var string[] */
    private static array $stack = [];

    public static function register(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'enter_rest'], PHP_INT_MIN, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'leave_rest'], PHP_INT_MAX, 3);
    }

    /**
     * rest_request_before_callbacks: push the route's source. Core fires the
     * after filter for every before filter, so the stack stays balanced.
     *
     * @param mixed $response Passed through untouched.
     * @param mixed $handler  Unused.
     * @param mixed $request  The WP_REST_Request being served.
     * @return mixed
     */
    public static function enter_rest($response, $handler = null, $request = null)
    {
        unset($handler);
        $route = $request instanceof \WP_REST_Request ? (string) $request->get_route() : '';
        self::enter(self::classify_route($route));

        return $response;
    }

    /**
     * @param mixed $response Passed through untouched.
     * @return mixed
     */
    public static function leave_rest($response)
    {
        self::leave();

        return $response;
    }

    /** Enter a source for the duration of a call; pair with leave(). */
    public static function enter(string $source): void
    {
        self::$stack[] = $source;
    }

    public static function leave(): void
    {
        array_pop(self::$stack);
    }

    public static function current(): string
    {
        if ([] !== self::$stack) {
            return (string) end(self::$stack);
        }

        return defined('WP_CLI') && WP_CLI ? self::CLI : self::PHP;
    }

    public static function classify_route(string $route): string
    {
        $route = '/' . ltrim(strtolower($route), '/');

        if (str_starts_with($route, '/mcp/wpmcp-server') || str_starts_with($route, '/wpmcp/')) {
            return self::MCP;
        }
        if (str_starts_with($route, '/wp-abilities/')) {
            return self::REST;
        }

        return self::SERVER;
    }
}
