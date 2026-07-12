<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Page_Audit
{
    private const RESPONSE_WARN_MS = 800;
    private const HTML_WARN_BYTES  = 512000; // 500 KB of HTML.

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

        return ['findings' => $findings, 'page_fetch' => $page_fetch];
    }
}
