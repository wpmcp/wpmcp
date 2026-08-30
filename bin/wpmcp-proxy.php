#!/usr/bin/env php
<?php
/**
 * wpmcp stdio-to-HTTP proxy (issue #77).
 *
 * Zero-dependency bridge for MCP clients that only speak stdio: reads
 * newline-delimited JSON-RPC from stdin, forwards each message to a
 * WordPress site's /wp-json/mcp/wpmcp-server endpoint with application
 * password auth, and writes the response back to stdout. No Composer, no
 * WordPress load: plain PHP streams only, so its test file can run without
 * a WP test install.
 *
 * Configuration is environment-only:
 *
 *   WPMCP_SITES          JSON map of named sites, e.g.
 *                        {"prod":{"url":"https://a.example","user":"admin","app_password":"xxxx"}}
 *   WPMCP_SITE_<NAME>_URL / _USER / _APP_PASSWORD
 *                        per-site variables, alternative to WPMCP_SITES.
 *   WPMCP_SITE           which named site to proxy (defaults to the only
 *                        configured site; required when several exist).
 *   WPMCP_PROXY_DEBUG    "1" logs request/response summaries to stderr.
 *
 * Usage: WPMCP_SITE=prod php bin/wpmcp-proxy.php
 */

namespace WPMCP\Proxy;

const ENDPOINT_PATH = '/wp-json/mcp/wpmcp-server';

/**
 * Resolves the named-site map from an environment snapshot.
 *
 * Pure: takes the env as an array so tests never touch putenv(). Sites from
 * WPMCP_SITES win over per-variable definitions of the same name. Site names
 * are normalized to lowercase.
 *
 * @param array<string,string> $env Environment snapshot (getenv() shape).
 * @return array<string,array{url:string,user:string,app_password:string}>
 */
function resolve_sites(array $env): array
{
    $sites = [];

    foreach ($env as $key => $value) {
        if (preg_match('/^WPMCP_SITE_([A-Z0-9_]+)_URL$/', $key, $m)) {
            $name = strtolower($m[1]);
            $sites[$name] = [
                'url'          => rtrim($value, '/'),
                'user'         => (string) ($env['WPMCP_SITE_' . $m[1] . '_USER'] ?? ''),
                'app_password' => (string) ($env['WPMCP_SITE_' . $m[1] . '_APP_PASSWORD'] ?? ''),
            ];
        }
    }

    if (isset($env['WPMCP_SITES']) && '' !== $env['WPMCP_SITES']) {
        $decoded = json_decode($env['WPMCP_SITES'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $name => $site) {
                if (! is_array($site) || ! isset($site['url'])) {
                    continue;
                }
                $sites[strtolower((string) $name)] = [
                    'url'          => rtrim((string) $site['url'], '/'),
                    'user'         => (string) ($site['user'] ?? ''),
                    'app_password' => (string) ($site['app_password'] ?? ''),
                ];
            }
        }
    }

    return $sites;
}

/**
 * Picks the site to proxy: WPMCP_SITE when set, the sole site when exactly
 * one is configured. Anything else is a configuration error.
 *
 * @param array<string,array{url:string,user:string,app_password:string}> $sites Resolved site map.
 * @param array<string,string>                                            $env   Environment snapshot.
 * @return array{url:string,user:string,app_password:string}
 * @throws \RuntimeException With a clear, actionable message.
 */
function select_site(array $sites, array $env): array
{
    if ([] === $sites) {
        throw new \RuntimeException(
            'No sites configured. Set WPMCP_SITES (JSON) or WPMCP_SITE_<NAME>_URL/_USER/_APP_PASSWORD.'
        );
    }

    $wanted = strtolower((string) ($env['WPMCP_SITE'] ?? ''));
    if ('' === $wanted) {
        if (1 === count($sites)) {
            return reset($sites);
        }
        throw new \RuntimeException(
            'Several sites configured (' . implode(', ', array_keys($sites)) . '); set WPMCP_SITE to pick one.'
        );
    }

    if (! isset($sites[$wanted])) {
        throw new \RuntimeException(
            sprintf('Unknown site "%s". Configured sites: %s.', $wanted, implode(', ', array_keys($sites)))
        );
    }

    return $sites[$wanted];
}

/**
 * Maps an HTTP status to a clear stderr diagnosis. Auth failures must not be
 * mistaken for protocol errors.
 */
function describe_http_failure(int $status, string $site_url): string
{
    if (401 === $status) {
        return sprintf(
            'Authentication failed against %s (HTTP 401). Check the user and application password '
            . '(Users -> Profile -> Application Passwords).',
            $site_url
        );
    }
    if (403 === $status) {
        return sprintf('Authorization refused by %s (HTTP 403). The user lacks the required capability.', $site_url);
    }
    return sprintf('Request to %s failed with HTTP %d.', $site_url, $status);
}

/**
 * Forwards one raw JSON-RPC line to the site. Returns the response body.
 *
 * @param array{url:string,user:string,app_password:string} $site Selected site.
 * @throws \RuntimeException On transport or auth failure.
 */
function forward(array $site, string $body): string
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($site['user'] . ':' . $site['app_password']),
            ]),
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 60,
        ],
    ]);

    $response = @file_get_contents($site['url'] . ENDPOINT_PATH, false, $context);
    $status   = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }

    if (false === $response) {
        throw new \RuntimeException(sprintf('Could not reach %s.', $site['url'] . ENDPOINT_PATH));
    }
    if ($status >= 400) {
        throw new \RuntimeException(describe_http_failure($status, $site['url']));
    }

    return $response;
}

/** Stderr debug logging, enabled by WPMCP_PROXY_DEBUG=1. */
function debug_log(array $env, string $message): void
{
    if (($env['WPMCP_PROXY_DEBUG'] ?? '') === '1') {
        fwrite(STDERR, '[wpmcp-proxy] ' . $message . "\n");
    }
}

/** The stdio loop. Separated so tests can include this file without running it. */
function main(): int
{
    $env = [];
    foreach (getenv() as $key => $value) {
        $env[(string) $key] = (string) $value;
    }

    try {
        $site = select_site(resolve_sites($env), $env);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, '[wpmcp-proxy] ' . $e->getMessage() . "\n");
        return 1;
    }

    debug_log($env, 'proxying to ' . $site['url'] . ENDPOINT_PATH);

    $stdin = fopen('php://stdin', 'r');
    while (false !== ($line = fgets($stdin))) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }

        // TODO(#77): forward MCP session headers once the HTTP transport
        // requires them; today each POST is self-contained.
        try {
            debug_log($env, '-> ' . substr($line, 0, 200));
            $response = forward($site, $line);
            debug_log($env, '<- ' . substr($response, 0, 200));
            if ('' !== trim($response)) {
                fwrite(STDOUT, trim($response) . "\n");
            }
        } catch (\RuntimeException $e) {
            fwrite(STDERR, '[wpmcp-proxy] ' . $e->getMessage() . "\n");
            $request = json_decode($line, true);
            $id      = is_array($request) ? ($request['id'] ?? null) : null;
            fwrite(STDOUT, json_encode([
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => [ 'code' => -32000, 'message' => $e->getMessage() ],
            ]) . "\n");
        }
    }
    fclose($stdin);

    return 0;
}

if (! defined('WPMCP_PROXY_NO_RUN') && 'cli' === PHP_SAPI && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main());
}
