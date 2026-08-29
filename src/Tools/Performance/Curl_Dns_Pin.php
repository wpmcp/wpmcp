<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The curl half of Page_Audit's SSRF defence, isolated in one file.
 *
 * CURLOPT_RESOLVE is the only way to pin a hostname to an already-validated
 * IP for a single WordPress HTTP request, and setting it means calling
 * curl_setopt(), which Plugin Check treats as an error under
 * WordPress.WP.AlternativeFunctions (curl group). There is no WordPress HTTP
 * API equivalent: the API deliberately hides the transport.
 *
 * Keeping it in its own class means a build that must not contain any curl_*
 * call can drop this one file. Page_Audit checks class_exists() before using
 * it and falls back to wp_safe_remote_get() on its own, which re-resolves and
 * revalidates the target against WordPress's allow/deny rules on every hop.
 * That is weaker (a DNS-rebinding TOCTOU window reopens) but it is the same
 * behaviour Page_Audit already has on any host where curl is unavailable.
 */
final class Curl_Dns_Pin
{
    /**
     * An http_api_curl filter callback that pins one request to one IP.
     *
     * @param string $resolve_entry "host:port:ip", curl's CURLOPT_RESOLVE format
     */
    public static function filter(string $resolve_entry): callable
    {
        return static function ($handle) use ($resolve_entry) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- deliberate SSRF defence: pins the audited request's DNS via CURLOPT_RESOLVE, which the WordPress HTTP API cannot express; the request itself still goes through wp_safe_remote_get().
            curl_setopt($handle, CURLOPT_RESOLVE, [$resolve_entry]);
            return $handle;
        };
    }
}
