<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Page_Audit
{
    private const FETCH_TIMEOUT      = 10;
    private const MAX_HTML_BYTES     = 2097152; // 2 MB cap for parsing.
    private const RESPONSE_WARN_MS   = 800;
    private const HTML_WARN_BYTES    = 512000; // 500 KB of HTML.
    private const RENDER_BLOCK_WARN  = 5;

    /**
     * Perform the HTTP fetch and normalize the response. SSRF-safe: the
     * target host must resolve to a public IP (no loopback, private, link-
     * local, or reserved range) before wp_safe_remote_get() is even called;
     * wp_safe_remote_get() then applies WordPress's own SSRF protections as
     * a second layer of defense.
     *
     * @return array { ok, status_code, response_ms, total_bytes, headers, body, error, host }
     */
    public function fetch(string $url, int $timeout = self::FETCH_TIMEOUT): array
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        if ('' === $host || $this->resolves_to_private_ip($host)) {
            return [
                'ok' => false, 'status_code' => 0, 'response_ms' => 0, 'total_bytes' => 0,
                'headers' => [], 'body' => '', 'error' => 'refused_private_target', 'host' => $host,
            ];
        }

        $start = microtime(true);
        $response = wp_safe_remote_get($url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'user-agent'  => 'WPMCP-Performance-Analyzer/1.0',
        ]);
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'ok' => false, 'status_code' => 0, 'response_ms' => $elapsed, 'total_bytes' => 0,
                'headers' => [], 'body' => '', 'error' => $response->get_error_message(), 'host' => $host,
            ];
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $body    = (string) wp_remote_retrieve_body($response);
        $bytes   = strlen($body);
        $headers = $this->normalize_headers(wp_remote_retrieve_headers($response));
        if (strlen($body) > self::MAX_HTML_BYTES) {
            $body = substr($body, 0, self::MAX_HTML_BYTES);
        }

        return [
            'ok'          => true,
            'status_code' => $status,
            'response_ms' => $elapsed,
            'total_bytes' => $bytes,
            'headers'     => $headers,
            'body'        => $body,
            'error'       => null,
            'host'        => $host,
        ];
    }

    /**
     * True when $host is a literal IP, or resolves via DNS to an IP, in a
     * private/loopback/link-local/reserved range. Uses PHP's built-in
     * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE, which covers
     * 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16,
     * ::1, and IPv6 unique-local (fc00::/7), plus other reserved ranges.
     */
    private function resolves_to_private_ip(string $host): bool
    {
        $host = trim($host, '[]');

        $candidates = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve_hostname($host);
        if (empty($candidates)) {
            // Unresolvable host: refuse rather than let it slip through.
            return true;
        }

        foreach ($candidates as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (false === $public) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function resolve_hostname(string $host): array
    {
        $ips = [];

        $a_records = @dns_get_record($host, DNS_A);
        foreach ((array) $a_records as $record) {
            if (! empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        $aaaa_records = @dns_get_record($host, DNS_AAAA);
        foreach ((array) $aaaa_records as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private function normalize_headers($headers): array
    {
        $out = [];
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (! is_array($headers)) {
            return $out;
        }
        foreach ($headers as $key => $value) {
            $out[ strtolower((string) $key) ] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return $out;
    }

    /**
     * Parse a fetched struct into findings plus a page_fetch meta block. Pure.
     *
     * @param array $fetched     Output of fetch().
     * @param bool  $deep_assets Reserved for bounded asset-size sampling.
     * @return array { findings: Finding[], page_fetch: array }
     */
    public function analyze(array $fetched, bool $deep_assets): array
    {
        $page_fetch = [
            'ok'          => ! empty($fetched['ok']),
            'status_code' => (int) ($fetched['status_code'] ?? 0),
            'response_ms' => (int) ($fetched['response_ms'] ?? 0),
            'total_bytes' => (int) ($fetched['total_bytes'] ?? 0),
            'error'       => $fetched['error'] ?? null,
        ];

        if (empty($fetched['ok'])) {
            $findings = [
                Finding::make(
                    'page_fetch',
                    'page',
                    'Page fetch',
                    'warning',
                    false,
                    sprintf('Could not fetch the page: %s', (string) ($fetched['error'] ?? 'unknown error')),
                    'The request failed (often a local firewall, DNS, or self-signed SSL issue). Server and database checks are still reported.'
                ),
            ];
            return ['findings' => $findings, 'page_fetch' => $page_fetch];
        }

        $status   = (int) ($fetched['status_code'] ?? 0);
        $findings = [];

        $findings[] = (200 === $status)
            ? Finding::make('http_status', 'page', 'HTTP status', 'pass', $status, 'Page returned HTTP 200.')
            : Finding::make(
                'http_status',
                'page',
                'HTTP status',
                'warning',
                $status,
                sprintf('Page returned HTTP %d.', $status),
                'A non-200 status means the analyzed URL is redirecting or erroring; verify the target.'
            );

        if ($status >= 300 && $status < 400) {
            $location   = (string) (((array) ($fetched['headers'] ?? []))['location'] ?? '');
            $findings[] = Finding::make(
                'redirect',
                'page',
                'Redirect',
                'warning',
                $status,
                '' !== $location
                    ? sprintf('The page returned an HTTP %d redirect to %s (not followed).', $status, $location)
                    : sprintf('The page returned an HTTP %d redirect (not followed).', $status),
                'The analyzer does not follow redirects; audit the final URL directly, or fix the redirect so the page serves its content at this address.'
            );
        }

        $ms         = (int) ($fetched['response_ms'] ?? 0);
        $findings[] = ($ms > self::RESPONSE_WARN_MS)
            ? Finding::make(
                'response_time',
                'page',
                'Server response time',
                'warning',
                $ms,
                sprintf('Full HTML response took %d ms.', $ms),
                'Add page caching (a cache plugin or server cache) so HTML is served without a full PHP/DB render.'
            )
            : Finding::make('response_time', 'page', 'Server response time', 'pass', $ms, sprintf('Full HTML response took %d ms.', $ms));

        $bytes      = (int) ($fetched['total_bytes'] ?? 0);
        $findings[] = ($bytes > self::HTML_WARN_BYTES)
            ? Finding::make(
                'html_size',
                'page',
                'HTML size',
                'warning',
                $bytes,
                sprintf('The HTML document is %d KB.', (int) round($bytes / 1024)),
                'Large HTML often means inlined data or huge page builders; trim markup and avoid inlining big payloads.'
            )
            : Finding::make('html_size', 'page', 'HTML size', 'pass', $bytes, sprintf('The HTML document is %d KB.', (int) round($bytes / 1024)));

        $headers  = (array) ($fetched['headers'] ?? []);
        $encoding = strtolower((string) ($headers['content-encoding'] ?? ''));
        $findings[] = (false !== strpos($encoding, 'gzip') || false !== strpos($encoding, 'br'))
            ? Finding::make('compression', 'page', 'Compression', 'pass', $encoding, sprintf('Response is compressed (%s).', $encoding))
            : Finding::make(
                'compression',
                'page',
                'Compression',
                'warning',
                $encoding ?: 'none',
                'Response is not gzip/brotli compressed.',
                'Enable gzip or brotli at the server (or via a cache plugin) to cut transfer size.'
            );

        $has_cache_headers = ! empty($headers['cache-control']) || ! empty($headers['expires']) || ! empty($headers['x-cache']);
        $findings[]        = $has_cache_headers
            ? Finding::make('cache_headers', 'page', 'Cache headers', 'pass', true, 'The page sends caching headers.')
            : Finding::make(
                'cache_headers',
                'page',
                'Cache headers',
                'warning',
                false,
                'No Cache-Control / Expires headers on the HTML.',
                'A page cache that emits Cache-Control lets browsers and CDNs reuse the response.'
            );

        $body = (string) ($fetched['body'] ?? '');
        $host = (string) ($fetched['host'] ?? '');
        $dom  = $this->parse_dom($body);
        if (null !== $dom) {
            $findings = array_merge($findings, $this->asset_findings($dom, $host));
        }

        return ['findings' => $findings, 'page_fetch' => $page_fetch];
    }

    private function parse_dom(string $html): ?\DOMDocument
    {
        if ('' === trim($html)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $dom      = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $dom;
    }

    private function asset_findings(\DOMDocument $dom, string $host): array
    {
        $links   = $dom->getElementsByTagName('link');
        $scripts = $dom->getElementsByTagName('script');
        $images  = $dom->getElementsByTagName('img');

        $head_css      = 0;
        $css_total     = 0;
        $third_parties = [];
        foreach ($links as $link) {
            $rel = strtolower((string) $link->getAttribute('rel'));
            if ('stylesheet' !== $rel) {
                continue;
            }
            $css_total++;
            if ($this->in_head($link)) {
                $head_css++;
            }
            $this->track_third_party((string) $link->getAttribute('href'), $host, $third_parties);
        }

        $js_total  = 0;
        $sync_head = 0;
        foreach ($scripts as $script) {
            $src = (string) $script->getAttribute('src');
            if ('' === $src) {
                continue; // inline.
            }
            $js_total++;
            $this->track_third_party($src, $host, $third_parties);
            $is_async = $script->hasAttribute('async') || $script->hasAttribute('defer');
            if (! $is_async && $this->in_head($script)) {
                $sync_head++;
            }
        }

        $img_total = 0;
        $not_lazy  = 0;
        foreach ($images as $img) {
            $img_total++;
            if ('lazy' !== strtolower((string) $img->getAttribute('loading'))) {
                $not_lazy++;
            }
        }

        $render_blocking = $head_css + $sync_head;

        $findings   = [];
        $findings[] = ($render_blocking > self::RENDER_BLOCK_WARN)
            ? Finding::make(
                'render_blocking',
                'assets',
                'Render-blocking resources',
                'warning',
                $render_blocking,
                sprintf('%d render-blocking resources in <head> (%d CSS, %d sync JS).', $render_blocking, $head_css, $sync_head),
                'Defer non-critical JS (async/defer) and combine or inline critical CSS to unblock first paint.'
            )
            : Finding::make(
                'render_blocking',
                'assets',
                'Render-blocking resources',
                'info',
                $render_blocking,
                sprintf('%d render-blocking resources in <head> (%d CSS, %d sync JS).', $render_blocking, $head_css, $sync_head)
            );

        $findings[] = Finding::make(
            'asset_counts',
            'assets',
            'Asset counts',
            'info',
            ['css' => $css_total, 'js' => $js_total, 'images' => $img_total],
            sprintf('%d CSS, %d JS, %d images referenced.', $css_total, $js_total, $img_total)
        );

        $findings[] = ($not_lazy > 0)
            ? Finding::make(
                'image_lazy_loading',
                'assets',
                'Image lazy-loading',
                'info',
                $not_lazy,
                sprintf('%d of %d images lack loading="lazy".', $not_lazy, $img_total),
                'Add loading="lazy" to below-the-fold images to defer offscreen downloads.'
            )
            : Finding::make('image_lazy_loading', 'assets', 'Image lazy-loading', 'pass', 0, 'All images use lazy-loading (or there are none).');

        $third_party_hosts = array_keys($third_parties);
        $findings[]        = (count($third_party_hosts) > 0)
            ? Finding::make(
                'third_party',
                'assets',
                'Third-party domains',
                'info',
                $third_party_hosts,
                sprintf('%d third-party domain(s) referenced.', count($third_party_hosts)),
                'Each extra domain adds DNS and connection cost; self-host fonts/scripts where practical.'
            )
            : Finding::make('third_party', 'assets', 'Third-party domains', 'pass', [], 'No third-party asset domains referenced.');

        return $findings;
    }

    private function track_third_party(string $url, string $host, array &$accumulator): void
    {
        $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ('' !== $url_host && $url_host !== $host) {
            $accumulator[ $url_host ] = true;
        }
    }

    private function in_head(\DOMNode $node): bool
    {
        for ($parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode) {
            if ('head' === strtolower($parent->nodeName)) {
                return true;
            }
        }
        return false;
    }
}
