<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Tools\Context\Get_Rendered_Html;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Query Monitor page profile (issue #703): get-query-monitor-page-profile,
 * a free read-only op on the theme dispatcher pair (through
 * Ops_Status_Packs), so it adds no top-level tool.
 *
 * Query Monitor keeps nothing between requests: it collects while a page is
 * built and prints the result into that page for a user who may view it.
 * So the op loads the page itself, the way Get_Rendered_Html does (this
 * site's own host and port only, a post_id, a path or a full URL, no
 * cookies, redirects not followed), as a logged-out visitor, with a one-time
 * token in the query string (PARAM):
 *
 *  - issue_token() stores a random token's hash as a short-lived transient
 *    in the 'pending' state, under the calling request;
 *  - in the profiled request, start_profile() (on init, before Query
 *    Monitor's own dispatchers decide at init 10 whether to keep
 *    collecting) moves a pending token to 'running', so it works once, and
 *    only then grants view_query_monitor for that request (Query Monitor
 *    stops collecting for a visitor who may not view it) and turns every
 *    Query Monitor dispatcher off (qm/dispatch/<id>), so its panel, headers
 *    and envelopes stay out of the page;
 *  - at shutdown, finish_profile() has Query Monitor process its
 *    collectors and stores a summary under the token ('done');
 *  - the calling request reads the summary and deletes the transient.
 *
 * The summary is built from allowlisted fields of the overview, db_queries,
 * http, php_errors and hooks collectors (Query Monitor 4.1.1, with the
 * array shapes of 3.x read the same way): page time and memory, query count
 * and time, the slowest queries with their caller, duplicates and errors,
 * outgoing HTTP calls with status and time, PHP errors with file and line,
 * and the hooks with the most callbacks. A profile holds many rows at once,
 * so SQL string literals and URL query values are masked
 * (src/Privacy/Masking.php) and the answer says masked:true.
 *
 * A page answered by a page cache never runs PHP, so no profile is stored:
 * the op then says profile_not_captured. Presence is filterable
 * (wpmcp_query_monitor_active, default QM_VERSION) and so is the
 * collectors' data (wpmcp_query_monitor_collector_data, a test seam).
 */
final class Query_Monitor_Status
{
    public const PARAM = 'wpmcp_qm_profile';

    private const TRANSIENT = 'wpmcp_qm_profile_';

    private const TTL = 120;

    private const TIMEOUT = 20;

    /** Query Monitor's own slow query threshold, when QM_DB_EXPENSIVE is not set. */
    private const SLOW_SECONDS = 0.05;

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    private const MAX_SQL = 1000;

    /** Query Monitor's dispatcher ids (4.1.1). */
    private const DISPATCHERS = [ 'html', 'ajax', 'redirect', 'rest', 'rest_envelope', 'wp_die' ];

    /** The token hash this request is profiling under, or null. */
    private static ?string $profiling = null;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'query_monitor_inactive', 'Query Monitor');

        $op             = Ops_Status_Packs::read_op(
            'Query Monitor profile of one page of this site, loaded as a logged-out visitor: page time and memory, query count and time, slowest queries with caller, duplicate and failed queries, outgoing HTTP calls, PHP errors, busiest hooks. post_id, a path or a URL on this site (default the home page); limit (default 10, max 50). SQL literals and URL query values MASKED',
            $requires,
            static fn (array $args): array => self::profile($args),
            [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'url'     => [ 'type' => 'string', 'minLength' => 1 ],
                'limit'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT ],
            ]
        );
        $op['objects']  = [ 'post_id' => 'post' ];
        $op['validate'] = static fn (array $args): ?array => self::target_refusal($args);

        return [ 'get-query-monitor-page-profile' => $op ];
    }

    /** Whether Query Monitor is loaded, filterable through wpmcp_query_monitor_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_query_monitor_active', defined('QM_VERSION'));
    }

    /**
     * Hooks the profiled request's side. Cheap on every other request: it
     * only looks for the token parameter.
     */
    public static function register(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a one-time token checked against a stored hash in start_profile(), not a form.
        if (isset($_GET[ self::PARAM ])) {
            add_action('init', [ self::class, 'start_profile' ], 1);
        }
    }

    // ------------------------------------------------------------------
    // The calling request
    // ------------------------------------------------------------------

    /** @return array{code:string,message:string,data?:array}|null */
    private static function target_refusal(array $args): ?array
    {
        try {
            ( new Get_Rendered_Html() )->target($args);
        } catch (\InvalidArgumentException $e) {
            return [ 'code' => 'invalid_url', 'message' => $e->getMessage(), 'data' => [] ];
        }
        return null;
    }

    /** A new one-time token, stored as 'pending'. */
    public static function issue_token(): string
    {
        $token = wp_generate_password(32, false, false);
        set_transient(self::TRANSIENT . self::hash($token), [ 'state' => 'pending' ], self::TTL);
        return $token;
    }

    /** @return array<string,mixed> */
    private static function profile(array $args): array
    {
        $url   = ( new Get_Rendered_Html() )->target($args);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $token = self::issue_token();
        $key   = self::TRANSIENT . self::hash($token);

        try {
            $response = wp_safe_remote_get(add_query_arg(self::PARAM, rawurlencode($token), $url), [
                'timeout'     => self::TIMEOUT,
                'redirection' => 0,
                'cookies'     => [],
                'user-agent'  => 'WPMCP-Query-Monitor-Profile/1.0',
                'headers'     => [ 'Cache-Control' => 'no-cache' ],
                'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
            ]);
            if (is_wp_error($response)) {
                throw new Operation_Error('page_fetch_failed', esc_html(sprintf('The page could not be loaded: %s', $response->get_error_message())), [ 'url' => $url ]);
            }
            $stored = self::read_stored($key);
        } finally {
            delete_transient($key);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (! is_array($stored) || 'done' !== ($stored['state'] ?? '') || ! is_array($stored['profile'] ?? null)) {
            $data = [ 'url' => $url, 'status' => $status ];
            throw new Operation_Error(
                'profile_not_captured',
                esc_html(sprintf('The page answered HTTP %d but Query Monitor did not profile it. A page cache or CDN probably served it without running WordPress; exclude the URL from the cache or purge it, then try again.', $status)),
                $data // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data, not output.
            );
        }

        return [
            'url'         => $url,
            'status'      => $status,
            'profiled_as' => 'logged_out_visitor',
        ] + self::trim($stored['profile'], $limit) + [ 'masked' => true ];
    }

    /**
     * The stored entry, read past this request's own object cache (the
     * profiled request wrote it), waiting briefly for a shutdown that is
     * still running.
     *
     * @return mixed
     */
    private static function read_stored(string $key)
    {
        $stored = null;
        for ($try = 0; $try < 3; $try++) {
            if (! wp_using_ext_object_cache()) {
                wp_cache_delete('_transient_' . $key, 'options');
                wp_cache_delete('_transient_timeout_' . $key, 'options');
                wp_cache_delete('notoptions', 'options');
            }
            $stored = get_transient($key);
            if (! is_array($stored) || 'running' !== ($stored['state'] ?? '')) {
                return $stored;
            }
            usleep(200000);
        }
        return $stored;
    }

    /**
     * The stored summary cut to $limit rows per list.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private static function trim(array $profile, int $limit): array
    {
        if (isset($profile['database']['slowest']) && is_array($profile['database']['slowest'])) {
            $profile['database']['slowest'] = array_slice($profile['database']['slowest'], 0, $limit);
        }
        return $profile;
    }

    // ------------------------------------------------------------------
    // The profiled request
    // ------------------------------------------------------------------

    /** On init: a pending token turns into a profile of this request. */
    public static function start_profile(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential, checked against a stored hash.
        $token = isset($_GET[ self::PARAM ]) && is_string($_GET[ self::PARAM ]) ? sanitize_text_field(wp_unslash($_GET[ self::PARAM ])) : '';
        if ('' === $token || null !== self::$profiling) {
            return;
        }
        $hash   = self::hash($token);
        $stored = get_transient(self::TRANSIENT . $hash);
        if (! is_array($stored) || 'pending' !== ($stored['state'] ?? '')) {
            return;
        }
        set_transient(self::TRANSIENT . $hash, [ 'state' => 'running' ], self::TTL);
        self::$profiling = $hash;

        add_filter('user_has_cap', [ self::class, 'grant_view' ], 10, 3);
        foreach (self::DISPATCHERS as $id) {
            add_filter("qm/dispatch/{$id}", '__return_false', PHP_INT_MAX); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Query Monitor's own hook names.
        }
        add_action('shutdown', [ self::class, 'finish_profile' ], PHP_INT_MAX - 10);
    }

    /** Whether this request is being profiled. */
    public static function profiling(): bool
    {
        return null !== self::$profiling;
    }

    /**
     * view_query_monitor for this request only, nothing else.
     *
     * @param array<string,bool> $allcaps
     * @param string[]           $caps
     * @return array<string,bool>
     */
    public static function grant_view(array $allcaps, array $caps, array $args): array
    {
        if (in_array('view_query_monitor', $caps, true)) {
            $allcaps['view_query_monitor'] = true;
        }
        return $allcaps;
    }

    /** At shutdown: store the summary under the token. */
    public static function finish_profile(): void
    {
        if (null === self::$profiling) {
            return;
        }
        $hash = self::$profiling;
        try {
            $profile = self::summarise();
        } catch (\Throwable $e) {
            $profile = null;
        }
        self::end_profile();
        if (is_array($profile)) {
            set_transient(self::TRANSIENT . $hash, [ 'state' => 'done', 'profile' => $profile ], self::TTL);
        }
    }

    /** Removes the profiled request's filters (also a test reset). */
    public static function end_profile(): void
    {
        self::$profiling = null;
        remove_filter('user_has_cap', [ self::class, 'grant_view' ], 10);
        foreach (self::DISPATCHERS as $id) {
            remove_filter("qm/dispatch/{$id}", '__return_false', PHP_INT_MAX); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Query Monitor's own hook names.
        }
        remove_action('shutdown', [ self::class, 'finish_profile' ], PHP_INT_MAX - 10);
    }

    /** @return array<string,mixed> */
    private static function summarise(): array
    {
        if (class_exists('QM_Collectors')) {
            \QM_Collectors::init()->process();
        }

        return [
            'query_monitor_version' => defined('QM_VERSION') ? (string) QM_VERSION : null,
            'overview'              => self::overview(self::data('overview')),
            'database'              => self::database(self::data('db_queries')),
            'http'                  => self::http(self::data('http')),
            'php_errors'            => self::php_errors(self::data('php_errors')),
            'hooks'                 => self::hooks(self::data('hooks')),
        ];
    }

    /** @return mixed one collector's data object (or array) */
    private static function data(string $id)
    {
        $data = null;
        if (class_exists('QM_Collectors')) {
            $collector = \QM_Collectors::get($id);
            if (is_object($collector) && method_exists($collector, 'get_data')) {
                $data = $collector->get_data();
            }
        }
        return apply_filters('wpmcp_query_monitor_collector_data', $data, $id);
    }

    /** @return array<string,mixed> */
    private static function overview($data): array
    {
        $memory = self::field($data, 'memory');
        $limit  = self::field($data, 'memory_limit');
        $time   = self::field($data, 'time_taken');

        return [
            'time_seconds'       => is_numeric($time) ? round((float) $time, 4) : null,
            'time_limit_seconds' => is_numeric(self::field($data, 'time_limit')) ? (int) self::field($data, 'time_limit') : null,
            'memory_bytes'       => is_numeric($memory) ? (int) $memory : null,
            'memory_limit_bytes' => is_numeric($limit) ? (int) $limit : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function database($data): array
    {
        $rows      = self::field($data, 'rows');
        $rows      = is_array($rows) ? array_values($rows) : [];
        $threshold = defined('QM_DB_EXPENSIVE') ? (float) QM_DB_EXPENSIVE : self::SLOW_SECONDS;

        $time       = 0.0;
        $slow       = 0;
        $errors     = [];
        $seen       = [];
        $components = [];
        $queries    = [];
        foreach ($rows as $row) {
            $sql   = (string) self::field($row, 'sql');
            $ltime = (float) self::field($row, 'ltime');
            $time += $ltime;
            if ($ltime >= $threshold) {
                $slow++;
            }
            $component = self::component($row);
            $caller    = self::caller($row);
            $result    = self::field($row, 'result');
            if (is_wp_error($result)) {
                $errors[] = [ 'sql' => self::mask_sql($sql), 'error' => self::mask_sql($result->get_error_message()), 'caller' => $caller ];
            }
            $seen[ $sql ] = ($seen[ $sql ] ?? 0) + 1;
            $name         = $component ?? 'unknown';
            $components[ $name ] = [
                'component'    => $name,
                'queries'      => ($components[ $name ]['queries'] ?? 0) + 1,
                'time_seconds' => ($components[ $name ]['time_seconds'] ?? 0.0) + $ltime,
            ];
            $queries[] = [
                'sql'          => self::mask_sql($sql),
                'time_seconds' => round($ltime, 4),
                'caller'       => $caller,
                'component'    => $component,
            ];
        }

        usort($queries, static fn (array $a, array $b): int => $b['time_seconds'] <=> $a['time_seconds']);
        usort($components, static fn (array $a, array $b): int => $b['time_seconds'] <=> $a['time_seconds']);
        $dupes = array_filter($seen, static fn (int $n): bool => $n > 1);
        arsort($dupes);

        $total = self::field($data, 'total_qs');
        $out   = [
            'queries'                => is_numeric($total) ? (int) $total : count($rows),
            'time_seconds'           => round($time, 4),
            'slow_threshold_seconds' => $threshold,
            'slow_queries'           => $slow,
            'slowest'                => array_slice($queries, 0, self::MAX_LIMIT),
            'by_component'           => array_map(
                static fn (array $c): array => [ 'component' => $c['component'], 'queries' => $c['queries'], 'time_seconds' => round($c['time_seconds'], 4) ],
                array_slice($components, 0, 10)
            ),
            'duplicates'             => [
                'total'   => count($dupes),
                'queries' => array_map(
                    static fn ($sql, int $count): array => [ 'sql' => self::mask_sql((string) $sql), 'count' => $count ],
                    array_keys(array_slice($dupes, 0, 5, true)),
                    array_values(array_slice($dupes, 0, 5, true))
                ),
            ],
            'errors'                 => [ 'total' => count($errors), 'errors' => array_slice($errors, 0, 10) ],
        ];
        $reason = self::field($data, 'not_logged_reason');
        if (is_string($reason) && '' !== $reason) {
            $out['not_logged_reason'] = $reason;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function http($data): array
    {
        $calls    = self::field($data, 'http');
        $calls    = is_array($calls) ? array_values($calls) : [];
        $requests = [];
        $time     = 0.0;
        foreach ($calls as $call) {
            $ltime  = (float) self::field($call, 'ltime');
            $time  += $ltime;
            $args   = self::field($call, 'args');
            $result = self::field($call, 'result');
            $entry  = [
                'method'       => strtoupper((string) (self::field($args, 'method') ?? 'GET')),
                'url'          => self::mask_url((string) self::field($call, 'url')),
                'time_seconds' => round($ltime, 4),
                'component'    => self::component($call),
            ];
            if (is_wp_error($result)) {
                $entry['error'] = $result->get_error_message();
            } else {
                $code            = self::field($result, 'code');
                $entry['status'] = is_numeric($code) ? (int) $code : null;
            }
            $requests[] = $entry;
        }
        return [
            'calls'        => count($calls),
            'time_seconds' => round($time, 4),
            'requests'     => array_slice($requests, 0, 20),
        ];
    }

    /** @return array<string,mixed> */
    private static function php_errors($data): array
    {
        $errors = [];
        foreach (self::flatten_errors(self::field($data, 'errors')) as $error) {
            $file  = self::field($error, 'file');
            $line  = self::field($error, 'line');
            $trace = self::field($error, 'trace');
            if (null === $file && is_object($trace) && method_exists($trace, 'get_callsite')) {
                $site = $trace->get_callsite();
                $file = self::field($site, 'file');
                $line = self::field($site, 'line');
            }
            $errors[] = [
                'level'     => (string) self::field($error, 'level'),
                'message'   => (string) self::field($error, 'message'),
                'file'      => is_string($file) ? self::relative_path($file) : null,
                'line'      => is_numeric($line) ? (int) $line : null,
                'count'     => (int) (self::field($error, 'count') ?? 1),
                'component' => self::component($error),
            ];
        }
        return [ 'total' => count($errors), 'errors' => array_slice($errors, 0, 20) ];
    }

    /**
     * Error entries from Query Monitor 4 (key => error) or 3 (level => key => error).
     *
     * @return array<int,mixed>
     */
    private static function flatten_errors($errors): array
    {
        if (! is_array($errors)) {
            return [];
        }
        $out = [];
        foreach ($errors as $item) {
            if (is_array($item) && ! isset($item['message'])) {
                foreach ($item as $error) {
                    $out[] = $error;
                }
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function hooks($data): array
    {
        $hooks = self::field($data, 'hooks');
        $hooks = is_array($hooks) ? $hooks : [];
        $rows  = [];
        foreach ($hooks as $hook) {
            $actions = self::field($hook, 'actions');
            $rows[]  = [ 'hook' => (string) self::field($hook, 'name'), 'callbacks' => is_array($actions) ? count($actions) : 0 ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['callbacks'] <=> $a['callbacks']);
        return [ 'total' => count($rows), 'busiest' => array_slice($rows, 0, 10) ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A field of an array, an ArrayAccess object or a plain object, or null. */
    private static function field($thing, string $key)
    {
        if (is_array($thing)) {
            return $thing[ $key ] ?? null;
        }
        if ($thing instanceof \ArrayAccess && $thing->offsetExists($key)) {
            return $thing[ $key ];
        }
        if (is_object($thing) && isset($thing->{$key})) {
            return $thing->{$key};
        }
        return null;
    }

    /** The plugin, theme or core part a row is blamed on, or null. */
    private static function component($row): ?string
    {
        $component = self::field($row, 'component');
        if (null === $component) {
            $trace = self::field($row, 'trace');
            if (is_object($trace) && method_exists($trace, 'get_component')) {
                $component = $trace->get_component();
            }
        }
        if (is_object($component)) {
            $component = method_exists($component, 'get_name') ? $component->get_name() : self::field($component, 'name');
        }
        return is_string($component) && '' !== $component ? $component : null;
    }

    /** The function that ran a query or call, or null. */
    private static function caller($row): ?string
    {
        $caller = self::field($row, 'caller');
        if (null === $caller) {
            $trace = self::field($row, 'trace');
            if (is_object($trace) && method_exists($trace, 'get_caller')) {
                $frame  = $trace->get_caller();
                $caller = self::field($frame, 'display') ?? self::field($frame, 'id') ?? self::field($frame, 'function');
            }
        }
        if (null === $caller) {
            $stack  = self::field($row, 'stack');
            $caller = is_array($stack) && [] !== $stack ? end($stack) : null;
        }
        return is_string($caller) && '' !== $caller ? $caller : null;
    }

    /** SQL with every quoted string literal masked, cut to MAX_SQL bytes. */
    private static function mask_sql(string $sql): string
    {
        $masked = (string) preg_replace_callback(
            '/([\'"])((?:\\\\.|(?!\1).)*)\1/s',
            static fn (array $m): string => $m[1] . ('' === $m[2] ? '' : (string) Masking::value(stripslashes($m[2]))) . $m[1],
            $sql
        );
        return strlen($masked) > self::MAX_SQL ? substr($masked, 0, self::MAX_SQL) . '...' : $masked;
    }

    /** A URL with every query value masked. */
    private static function mask_url(string $url): string
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || ! isset($parts['query'])) {
            return $url;
        }
        $pairs = [];
        foreach (explode('&', (string) $parts['query']) as $pair) {
            [ $name, $value ] = array_pad(explode('=', $pair, 2), 2, null);
            $pairs[]          = null === $value ? $name : $name . '=' . Masking::value(urldecode($value));
        }
        $base = strstr($url, '?', true);
        return (false === $base ? $url : $base) . '?' . implode('&', $pairs);
    }

    /** A path relative to the WordPress root when it is inside it. */
    private static function relative_path(string $file): string
    {
        $root = wp_normalize_path(ABSPATH);
        $file = wp_normalize_path($file);
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('nonce'));
    }
}
