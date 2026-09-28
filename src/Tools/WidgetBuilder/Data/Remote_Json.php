<?php

namespace WPMCP\Tools\WidgetBuilder\Data;

use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The remote JSON reader behind the remote_json data control (issue #296).
 *
 * Every fetch goes through Remote_Image_Guard, the same guard remote images
 * use: https only, no credentials, the default port, a host on an allowlist
 * ('wpmcp_remote_json_allowed_hosts', empty by default, so nothing is fetched
 * until a site owner opts a host in), and a host that is not and does not
 * resolve to a private or reserved address. The request itself is
 * wp_safe_remote_get with redirects off, a short timeout and a byte cap, and
 * the decoded result is cached in a transient. On an admin screen the cache
 * is the only source: loading wp-admin never triggers a remote request.
 */
class Remote_Json
{
    public const DEFAULT_MAX_BYTES = 262144;
    public const TIMEOUT           = 5;
    public const DEFAULT_TTL       = 300;
    public const FAILURE_TTL       = 60;

    /**
     * The decoded JSON document at $url, or null when it is refused,
     * unreachable, too large, not JSON, or not cached on an admin screen.
     *
     * @return mixed|null
     */
    public static function get(string $url, int $ttl = self::DEFAULT_TTL)
    {
        $key    = 'wpmcp_rjson_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached) && array_key_exists('ok', $cached)) {
            return $cached['ok'] ? ($cached['value'] ?? null) : null;
        }

        // Admin page loads read the cache only. The editor preview renders
        // over admin-ajax, which is a deliberate render, so it may fetch.
        if (is_admin() && ! wp_doing_ajax()) {
            return null;
        }

        try {
            Remote_Image_Guard::validate_url($url, self::allowed_hosts(), 'wpmcp_remote_json_allowed_hosts');
            Remote_Image_Guard::assert_public_host((string) wp_parse_url($url, PHP_URL_HOST));
        } catch (\InvalidArgumentException $e) {
            // A refusal costs no request, so it is not cached: fixing the
            // allowlist takes effect on the next render.
            return null;
        }

        $value = self::fetch($url);
        if (null === $value) {
            set_transient($key, ['ok' => false], self::FAILURE_TTL);
            return null;
        }

        set_transient($key, ['ok' => true, 'value' => $value], max(60, $ttl));
        return $value;
    }

    /** @return string[] */
    public static function allowed_hosts(): array
    {
        return array_values(array_map('strval', (array) apply_filters('wpmcp_remote_json_allowed_hosts', [])));
    }

    public static function max_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_remote_json_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /** @return mixed|null */
    private static function fetch(string $url)
    {
        $max      = self::max_bytes();
        $response = wp_safe_remote_get($url, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => $max + 1,
            'headers'             => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $declared = wp_remote_retrieve_header($response, 'content-length');
        $body     = (string) wp_remote_retrieve_body($response);
        if (('' !== (string) $declared && (int) $declared > $max) || strlen($body) > $max) {
            return null;
        }

        $value = json_decode($body, true, 32);
        if (JSON_ERROR_NONE !== json_last_error() || null === $value) {
            return null;
        }
        return $value;
    }
}
