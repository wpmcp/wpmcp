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
 * This is a REPO-ONLY tool: the release zips (scripts/build-release.sh,
 * scripts/build-wporg-release.sh, scripts/build-woo-release.sh) stage only
 * the runtime plugin (wpmcp.php, src/, languages/, LICENSE, composer
 * files), so bin/ ships to nobody. Run it from a clone.
 *
 * Session handling: the MCP Adapter's HTTP transport issues an
 * Mcp-Session-Id on the initialize response and rejects every later method
 * without it (HttpSessionValidator: "Missing Mcp-Session-Id header"), so
 * the proxy captures that header once and replays it, together with the
 * negotiated MCP-Protocol-Version, on every subsequent POST.
 *
 * Configuration is environment-only:
 *
 *   WPMCP_SITES          JSON map of named sites, e.g.
 *                        {"prod":{"url":"https://a.example","user":"admin","app_password":"xxxx"}}
 *   WPMCP_SITE_<NAME>_URL / _USER / _APP_PASSWORD
 *                        per-site variables, alternative to WPMCP_SITES.
 *   WPMCP_SITE           which named site to proxy (defaults to the only
 *                        configured site; required when several exist).
 *   WPMCP_ALLOW_INSECURE "1" permits a plain http:// site URL. Off by
 *                        default: the Authorization header carries an
 *                        application password, and WordPress refuses
 *                        application-password auth over non-SSL anyway.
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
 * @throws \RuntimeException When WPMCP_SITES is set but is not valid JSON.
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
        if (! is_array($decoded)) {
            // Silently ignoring a typo here surfaces to the operator as
            // "No sites configured", which sends them looking in the wrong
            // place. Name the parse error instead.
            throw new \RuntimeException(
                'WPMCP_SITES is not valid JSON: ' . json_last_error_msg()
                . '. Expected {"name":{"url":"https://...","user":"...","app_password":"..."}}.'
            );
        }
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
            $only = array_key_first($sites);
            return validate_site($only, $sites[$only], $env);
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

    return validate_site($wanted, $sites[$wanted], $env);
}

/**
 * Rejects a site that cannot possibly work before the first request, so the
 * failure names the missing setting instead of arriving as an HTTP 401.
 *
 * The https requirement is a credential guard, not pedantry: the
 * Authorization header carries an application password, and WordPress
 * refuses application-password auth over non-SSL, so a plain http:// site
 * both leaks the credential and 401s with a message blaming the password.
 *
 * @param array{url:string,user:string,app_password:string} $site Candidate site.
 * @param array<string,string>                              $env  Environment snapshot.
 * @return array{url:string,user:string,app_password:string}
 * @throws \RuntimeException When the site is unusable as configured.
 */
function validate_site(string $name, array $site, array $env): array
{
    if ('' === $site['url']) {
        throw new \RuntimeException(sprintf('Site "%s" has no URL.', $name));
    }
    if ('' === $site['user'] || '' === $site['app_password']) {
        throw new \RuntimeException(
            sprintf(
                'Site "%s" is missing a user or application password. Both are required '
                . '(Users -> Profile -> Application Passwords).',
                $name
            )
        );
    }

    $scheme = strtolower((string) parse_url($site['url'], PHP_URL_SCHEME));
    if ('https' !== $scheme && ('1' !== ($env['WPMCP_ALLOW_INSECURE'] ?? ''))) {
        throw new \RuntimeException(
            sprintf(
                'Site "%s" uses %s://, which would send the application password in the clear '
                . '(and WordPress refuses application-password auth over non-SSL). Use https, or set '
                . 'WPMCP_ALLOW_INSECURE=1 for a local-only site.',
                $name,
                '' === $scheme ? 'no scheme' : $scheme
            )
        );
    }

    return $site;
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
 * Reads one header value out of a raw $http_response_header array.
 *
 * Pure, and case-insensitive because header names on the wire are.
 *
 * @param array<int,string> $headers Raw response header lines.
 */
function header_value(array $headers, string $name): ?string
{
    $needle = strtolower($name);
    $found  = null;

    foreach ($headers as $header) {
        $parts = explode(':', (string) $header, 2);
        if (2 === count($parts) && strtolower(trim($parts[0])) === $needle) {
            $found = trim($parts[1]);
        }
    }

    return $found;
}

/**
 * The HTTP status from a raw $http_response_header array (last status line
 * wins, so a redirect chain reports its final status).
 *
 * @param array<int,string> $headers Raw response header lines.
 */
function status_code(array $headers): int
{
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return $status;
}

/**
 * Session state carried across messages: the Mcp-Session-Id the adapter
 * issues on initialize, and the protocol version it negotiated.
 *
 * A tiny mutable holder rather than globals so main() and the tests can
 * both own an instance.
 */
final class Session
{
    /** @var string|null */
    public $id = null;

    /** @var string|null */
    public $protocol_version = null;

    /**
     * Learns the session from an initialize exchange.
     *
     * @param array<int,string> $headers Raw response header lines.
     * @param mixed             $decoded Decoded response body.
     */
    public function learn(array $headers, $decoded): void
    {
        $id = header_value($headers, 'Mcp-Session-Id');
        if (null !== $id && '' !== $id) {
            $this->id = $id;
        }

        if (is_array($decoded) && isset($decoded['result']['protocolVersion'])) {
            $this->protocol_version = (string) $decoded['result']['protocolVersion'];
        }
    }

    /**
     * The session headers to replay on a non-initialize request.
     *
     * @return array<int,string>
     */
    public function headers(): array
    {
        $headers = [];
        if (null !== $this->id) {
            $headers[] = 'Mcp-Session-Id: ' . $this->id;
        }
        if (null !== $this->protocol_version) {
            $headers[] = 'MCP-Protocol-Version: ' . $this->protocol_version;
        }
        return $headers;
    }
}

/**
 * Forwards one raw JSON-RPC line to the site.
 *
 * @param array{url:string,user:string,app_password:string} $site  Selected site.
 * @param array<int,string>                                 $extra Extra request headers.
 * @return array{body:string,headers:array<int,string>}
 * @throws \RuntimeException On transport or auth failure.
 */
function forward(array $site, string $body, array $extra = []): array
{
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Basic ' . base64_encode($site['user'] . ':' . $site['app_password']),
    ], $extra);

    $context = stream_context_create([
        'http' => [
            'method'          => 'POST',
            'header'          => implode("\r\n", $headers),
            'content'         => $body,
            'ignore_errors'   => true,
            'timeout'         => 60,
            // The http wrapper re-sends caller-supplied headers, including
            // Authorization, to whatever host a Location points at. Never
            // follow a redirect while holding a credential.
            'follow_location' => 0,
            'max_redirects'   => 0,
        ],
    ]);

    $response = @file_get_contents($site['url'] . ENDPOINT_PATH, false, $context);
    $raw      = $http_response_header ?? [];
    $status   = status_code($raw);

    if (false === $response) {
        throw new \RuntimeException(sprintf('Could not reach %s.', $site['url'] . ENDPOINT_PATH));
    }
    if ($status >= 300 && $status < 400) {
        $location = header_value($raw, 'Location');
        throw new \RuntimeException(sprintf(
            'Request to %s was redirected (HTTP %d%s) and the proxy will not resend credentials '
            . 'to a redirect target. Configure the site URL that answers directly.',
            $site['url'] . ENDPOINT_PATH,
            $status,
            null === $location ? '' : ' to ' . $location
        ));
    }
    if ($status >= 400) {
        throw new \RuntimeException(describe_http_failure($status, $site['url']));
    }

    return [ 'body' => $response, 'headers' => $raw ];
}

/** Stderr debug logging, enabled by WPMCP_PROXY_DEBUG=1. */
function debug_log(array $env, string $message): void
{
    if (($env['WPMCP_PROXY_DEBUG'] ?? '') === '1') {
        fwrite(STDERR, '[wpmcp-proxy] ' . $message . "\n");
    }
}

/**
 * Re-serializes a response body onto exactly one line.
 *
 * The client's framing is newline-delimited, so a body containing an
 * internal newline (pretty-printed JSON, an SSE frame, a warning printed
 * ahead of the JSON) would desynchronize the stream. Returns null when the
 * body is not JSON at all, which the caller reports as an error rather than
 * forwarding.
 */
function one_line_response(string $body): ?string
{
    $decoded = json_decode(trim($body), true);
    if (null === $decoded && 'null' !== trim($body)) {
        return null;
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);

    return false === $encoded ? null : $encoded;
}

/**
 * The JSON-RPC error line to write for a failed request, or null when the
 * failing message was a notification.
 *
 * A notification has no id at all, and a response to one is a protocol
 * violation. array_key_exists, not ??: a legitimate id of null must stay
 * distinguishable from an absent one.
 *
 * @param mixed $request Decoded request (or null when it did not parse).
 */
function error_line($request, string $message): ?string
{
    if (! is_array($request) || ! array_key_exists('id', $request)) {
        return null;
    }

    return json_encode([
        'jsonrpc' => '2.0',
        'id'      => $request['id'],
        'error'   => [ 'code' => -32000, 'message' => $message ],
    ]);
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

    $session = new Session();
    $stdin   = fopen('php://stdin', 'r');

    while (false !== ($line = fgets($stdin))) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }

        $request = json_decode($line, true);
        $method  = is_array($request) && isset($request['method']) ? (string) $request['method'] : '';

        try {
            debug_log($env, '-> ' . substr($line, 0, 200));
            $result = forward($site, $line, 'initialize' === $method ? [] : $session->headers());
            debug_log($env, '<- ' . substr($result['body'], 0, 200));

            if ('initialize' === $method) {
                $session->learn($result['headers'], json_decode($result['body'], true));
                debug_log($env, 'session: ' . (string) $session->id);
            }

            if ('' === trim($result['body'])) {
                continue;
            }

            $out = one_line_response($result['body']);
            if (null === $out) {
                fwrite(STDERR, '[wpmcp-proxy] non-JSON response body: ' . substr($result['body'], 0, 500) . "\n");
                $error = error_line($request, 'The site returned a body that is not JSON-RPC.');
                if (null !== $error) {
                    fwrite(STDOUT, $error . "\n");
                }
                continue;
            }

            fwrite(STDOUT, $out . "\n");
        } catch (\RuntimeException $e) {
            fwrite(STDERR, '[wpmcp-proxy] ' . $e->getMessage() . "\n");
            $error = error_line($request, $e->getMessage());
            if (null !== $error) {
                fwrite(STDOUT, $error . "\n");
            }
        }
    }
    fclose($stdin);

    return 0;
}

if (! defined('WPMCP_PROXY_NO_RUN') && 'cli' === PHP_SAPI && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main());
}
