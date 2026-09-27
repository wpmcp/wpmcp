#!/usr/bin/env php
<?php

/**
 * wpmcp stdio-to-HTTP proxy (issue #77).
 *
 * Zero-dependency bridge for MCP clients that only speak stdio: reads
 * newline-delimited JSON-RPC from stdin, forwards each message to a
 * WordPress site's /wp-json/mcp/wpmcp-server endpoint with application
 * password auth, and writes the response back to stdout. No Composer and no
 * WordPress load at RUNTIME: plain PHP streams only. (Its test files live in
 * the plugin's suite and are ordinary PHPUnit test cases, since nothing in
 * this file touches a WordPress API.)
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
 *   WPMCP_SITE           the default site (defaults to the only configured
 *                        site; required when several exist). Every message
 *                        goes here unless a tools/call names another site.
 *   WPMCP_BROADCAST_WRITES "1" lets a tools/call with site "all" run a tool
 *                        that writes on every site (it still needs
 *                        confirm: true on the call). Off by default.
 *
 * Per-call routing (issue #130): with several sites configured, any
 * tools/call may carry "site": "<name>" to run on that site, or "all" to run
 * on every site and get one status per site back. See Router.
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
 * The alias select_site() picked: WPMCP_SITE, or the sole configured site.
 * Call after select_site(), which has already refused every other shape.
 *
 * @param array<string,array{url:string,user:string,app_password:string}> $sites Resolved site map.
 * @param array<string,string>                                            $env   Environment snapshot.
 */
function default_site_name(array $sites, array $env): string
{
    $wanted = strtolower((string) ($env['WPMCP_SITE'] ?? ''));

    return '' !== $wanted ? $wanted : (string) array_key_first($sites);
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
 * @param string                                            $name Site name, for the error message.
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
 * True when a response body is a JSON-RPC envelope the client can act on.
 *
 * The MCP Adapter deliberately carries protocol errors on 4xx statuses
 * (McpErrorFactory::mcp_error_to_http_status maps METHOD_NOT_FOUND,
 * TOOL_NOT_FOUND and SESSION_NOT_FOUND to 404, INVALID_REQUEST to 400 and
 * PERMISSION_DENIED to 403), so status alone cannot decide whether a body is
 * worth forwarding.
 */
function is_jsonrpc_envelope(string $body): bool
{
    $decoded = json_decode(trim($body), true);

    return is_array($decoded)
        && (array_key_exists('error', $decoded) || array_key_exists('result', $decoded));
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
        // A real JSON-RPC error must reach the client with its own code and
        // message. Replacing it with a synthetic -32000 "failed with HTTP
        // 404" is worse than useless: every MCP client probes resources/list
        // and prompts/list straight after initialize, both of which the
        // adapter answers METHOD_NOT_FOUND on 404, so the very first thing
        // a normal session would see is a transport error that is not one.
        // It also made describe_http_failure() blame the application
        // password for an adapter-level UNAUTHORIZED. Only a body that is
        // not a JSON-RPC envelope becomes a transport diagnosis.
        if (is_jsonrpc_envelope($response)) {
            return [ 'body' => $response, 'headers' => $raw ];
        }
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

/**
 * Routes each client message to the right site (issue #130, on top of #77).
 *
 * One stdio connection, several WordPress sites. Every message goes to the
 * default site (WPMCP_SITE, or the only configured one) EXCEPT a tools/call
 * that names another site in its arguments:
 *
 *   "site": "<alias>"  runs the call on that site only.
 *   "site": "all"      broadcasts it to every configured site and answers
 *                      with one status per site (ok, error,
 *                      site_unavailable, tool_unavailable), so one dead or
 *                      locked-down site never breaks the whole call.
 *
 * The routing argument is stripped before forwarding; no site ever sees it.
 *
 * Why routing cannot confuse identities: each site is reached with ITS OWN
 * application password (or whatever credential its config names) and ITS
 * OWN Mcp-Session-Id, held per site and never replayed across sites. The
 * proxy adds no authority of its own, so the target site authenticates the
 * call as its own configured user and applies its own governance, identity
 * scope and audit log exactly as for a direct connection. An unknown alias
 * is refused outright and never falls back to the default site, because a
 * typo that silently writes to production is the worst routing failure
 * there is.
 *
 * Broadcast writes are double-gated, matching the hosted gateway: the
 * workspace must opt in (WPMCP_BROADCAST_WRITES=1, off by default) AND the
 * call must carry confirm: true. A tool counts as a read only when every
 * site that exposes it says readOnlyHint: true in its own tools/list; a
 * missing hint is a write, so the gate fails closed. A refused broadcast
 * reaches no site at all.
 */
// A second class in this file on purpose: the proxy is one zero-dependency
// script run straight from a clone, with no autoloader to find a sibling file.
final class Router // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    /** Proxy-local tool: lists the configured aliases, never credentials. */
    public const LIST_SITES_TOOL = 'wpmcp_proxy_list_sites';

    /** The alias that broadcasts a call to every configured site. */
    public const ALL = 'all';

    /** @var array<string,array{url:string,user:string,app_password:string}> */
    private array $sites;

    private string $default;

    /** @var array<string,string> */
    private array $env;

    /** @var callable */
    private $transport;

    /** @var array<string,Session> */
    private array $sessions = [];

    /** @var array<string,array<string,array{read_only:bool,confirm:bool}>> */
    private array $catalogs = [];

    /** @var array<string,mixed>|null The client's own initialize params, replayed to each new site. */
    private ?array $init_params = null;

    private int $seq = 0;

    /**
     * @param array<string,array{url:string,user:string,app_password:string}> $sites     Configured sites, keyed by alias.
     * @param string                                                          $default   Alias of the default site.
     * @param array<string,string>                                            $env       Environment snapshot.
     * @param callable|null                                                   $transport forward()-shaped callable (test seam).
     * @param Session|null                                                    $session   Session for the default site.
     */
    public function __construct(array $sites, string $default, array $env, ?callable $transport = null, ?Session $session = null)
    {
        if (! isset($sites[ $default ])) {
            throw new \InvalidArgumentException(sprintf('Default site "%s" is not configured.', $default));
        }
        if (isset($sites[ self::ALL ])) {
            throw new \RuntimeException('"all" is reserved for broadcasts and cannot be a site name.');
        }

        $this->sites     = $sites;
        $this->default   = $default;
        $this->env       = $env;
        $this->transport = $transport ?? __NAMESPACE__ . '\\forward';

        $this->sessions[ $default ] = $session ?? new Session();
    }

    private function multi_site(): bool
    {
        return count($this->sites) > 1;
    }

    /**
     * Handle one client message and return the raw response body to frame
     * (which may be empty or "null" for a notification).
     *
     * @param mixed $request Decoded request, or null when the line did not parse.
     * @throws \RuntimeException On a transport, auth or routing failure.
     */
    public function dispatch(string $line, $request): string
    {
        $method = is_array($request) && isset($request['method']) ? (string) $request['method'] : '';

        if ('initialize' === $method) {
            $this->init_params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $result            = $this->send($this->default, $line, []);
            $this->sessions[ $this->default ]->learn($result['headers'], json_decode($result['body'], true));
            debug_log($this->env, 'session(' . $this->default . '): ' . (string) $this->sessions[ $this->default ]->id);
            return $result['body'];
        }

        if ('tools/list' === $method && $this->multi_site()) {
            return $this->tools_list($line);
        }

        if ('tools/call' === $method && is_array($request)) {
            $name = (string) ($request['params']['name'] ?? '');
            $args = $request['params']['arguments'] ?? [];

            if (self::LIST_SITES_TOOL === $name && $this->multi_site()) {
                return $this->list_sites($request);
            }

            if (is_array($args) && array_key_exists('site', $args)) {
                return $this->routed_call($request, $name, $args);
            }
        }

        return $this->send($this->default, $line, $this->sessions[ $this->default ]->headers())['body'];
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $args
     */
    private function routed_call(array $request, string $name, array $args): string
    {
        $alias = $args['site'];
        unset($args['site']);

        if (! is_string($alias) || '' === trim($alias)) {
            throw new \RuntimeException('The "site" argument must be a configured site name or "all".');
        }
        $alias = strtolower(trim($alias));

        if (self::ALL === $alias) {
            return $this->broadcast($request, $name, $args);
        }

        if (! isset($this->sites[ $alias ])) {
            throw new \RuntimeException(sprintf(
                'Unknown site "%s". Configured sites: %s. The call was not sent anywhere.',
                $alias,
                implode(', ', array_keys($this->sites))
            ));
        }

        $this->ensure_session($alias);
        $request['params']['arguments'] = (object) $args;

        return $this->send($alias, (string) json_encode($request, JSON_UNESCAPED_SLASHES), $this->sessions[ $alias ]->headers())['body'];
    }

    /**
     * Run one tool on every configured site and collect a status per site.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $args
     */
    private function broadcast(array $request, string $name, array $args): string
    {
        $statuses = [];
        $targets  = [];
        $is_write = false;

        // Learn every site's catalog BEFORE sending anything, so the write
        // gate is decided once, for the whole broadcast. A gate evaluated
        // per leg would let a refused write land on the sites that happened
        // to come first.
        foreach (array_keys($this->sites) as $alias) {
            try {
                $catalog = $this->catalog($alias);
            } catch (\RuntimeException $e) {
                $statuses[ $alias ] = [ 'site' => $alias, 'status' => 'site_unavailable', 'message' => $e->getMessage() ];
                continue;
            }

            if (! isset($catalog[ $name ])) {
                $statuses[ $alias ] = [
                    'site'    => $alias,
                    'status'  => 'tool_unavailable',
                    'message' => sprintf('Site "%s" does not expose %s to this connection.', $alias, $name),
                ];
                continue;
            }

            $targets[ $alias ] = $catalog[ $name ];
            if (! $catalog[ $name ]['read_only']) {
                $is_write = true;
            }
        }

        if ($is_write) {
            if ('1' !== ($this->env['WPMCP_BROADCAST_WRITES'] ?? '')) {
                throw new \RuntimeException(sprintf(
                    'Refusing to broadcast %s: it writes, and broadcast writes are off for this workspace. '
                    . 'Set WPMCP_BROADCAST_WRITES=1 to allow them (and pass confirm: true on the call). No site was called.',
                    $name
                ));
            }
            if (true !== ($args['confirm'] ?? null)) {
                throw new \RuntimeException(sprintf(
                    'Refusing to broadcast %s without confirm: true: it writes to every configured site. No site was called.',
                    $name
                ));
            }
        }

        foreach ($targets as $alias => $spec) {
            $leg_args = $args;
            // confirm is the proxy's own gate. Forward it only to a tool that
            // declares it, where it means the same thing.
            if (! $spec['confirm']) {
                unset($leg_args['confirm']);
            }

            $leg = [
                'jsonrpc' => '2.0',
                'id'      => $this->next_id(),
                'method'  => 'tools/call',
                'params'  => array_merge(
                    is_array($request['params'] ?? null) ? $request['params'] : [],
                    [ 'name' => $name, 'arguments' => (object) $leg_args ]
                ),
            ];

            try {
                $this->ensure_session($alias);
                $body    = $this->send($alias, (string) json_encode($leg, JSON_UNESCAPED_SLASHES), $this->sessions[ $alias ]->headers())['body'];
                $decoded = json_decode(trim($body), true);
                if (is_array($decoded) && array_key_exists('result', $decoded)) {
                    $statuses[ $alias ] = [ 'site' => $alias, 'status' => 'ok', 'result' => $decoded['result'] ];
                } elseif (is_array($decoded) && array_key_exists('error', $decoded)) {
                    $statuses[ $alias ] = [ 'site' => $alias, 'status' => 'error', 'error' => $decoded['error'] ];
                } else {
                    $statuses[ $alias ] = [ 'site' => $alias, 'status' => 'site_unavailable', 'message' => 'The site returned a body that is not JSON-RPC.' ];
                }
            } catch (\RuntimeException $e) {
                $statuses[ $alias ] = [ 'site' => $alias, 'status' => 'site_unavailable', 'message' => $e->getMessage() ];
            }
        }

        // One entry per configured site, in configuration order.
        $results = [];
        foreach (array_keys($this->sites) as $alias) {
            $results[] = $statuses[ $alias ];
        }

        $ok      = count(array_filter($results, static fn($r) => 'ok' === $r['status']));
        $summary = [ 'site' => self::ALL, 'tool' => $name, 'results' => $results ];

        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => $request['id'] ?? null,
            'result'  => [
                'content'           => [ [ 'type' => 'text', 'text' => (string) json_encode($summary, JSON_UNESCAPED_SLASHES) ] ],
                'structuredContent' => $summary,
                'isError'           => 0 === $ok,
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The default site's tools/list, with the routing argument advertised on
     * every tool and the proxy's own list-sites tool appended.
     */
    private function tools_list(string $line): string
    {
        $body    = $this->send($this->default, $line, $this->sessions[ $this->default ]->headers())['body'];
        $decoded = json_decode(trim($body), true);
        if (! is_array($decoded) || ! isset($decoded['result']['tools']) || ! is_array($decoded['result']['tools'])) {
            return $body;
        }

        $aliases = implode(', ', array_keys($this->sites));
        foreach ($decoded['result']['tools'] as $i => $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $schema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [ 'type' => 'object' ];
            $props  = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            $props['site']         = [
                'type'        => 'string',
                'description' => 'Proxy routing: run on this configured site (' . $aliases . ') instead of the default "'
                    . $this->default . '", or "all" to run on every site. Broadcast writes need the workspace opt-in and confirm: true.',
            ];
            $schema['properties']  = $props;
            $decoded['result']['tools'][ $i ]['inputSchema'] = $schema;
        }

        if (! isset($decoded['result']['nextCursor'])) {
            $decoded['result']['tools'][] = [
                'name'        => self::LIST_SITES_TOOL,
                'description' => 'List the WordPress sites this proxy can route to (names and URLs only). Pass one as "site" on any tool call, or "all".',
                'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'annotations' => [ 'readOnlyHint' => true ],
            ];
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $request */
    private function list_sites(array $request): string
    {
        $sites = [];
        foreach ($this->sites as $alias => $site) {
            // Name and URL only: the credential stays inside the proxy.
            $sites[] = [ 'name' => $alias, 'url' => $site['url'], 'default' => $alias === $this->default ];
        }
        $listed = [ 'default' => $this->default, 'sites' => $sites ];

        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => $request['id'] ?? null,
            'result'  => [
                'content'           => [ [ 'type' => 'text', 'text' => (string) json_encode($listed, JSON_UNESCAPED_SLASHES) ] ],
                'structuredContent' => $listed,
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The tools one site exposes to this connection, from that site's own
     * tools/list (so that site's governance decides), cached per site.
     *
     * @return array<string,array{read_only:bool,confirm:bool}>
     */
    private function catalog(string $alias): array
    {
        if (isset($this->catalogs[ $alias ])) {
            return $this->catalogs[ $alias ];
        }

        $this->ensure_session($alias);

        $catalog = [];
        $cursor  = null;
        for ($page = 0; $page < 50; $page++) {
            $message = [ 'jsonrpc' => '2.0', 'id' => $this->next_id(), 'method' => 'tools/list' ];
            if (null !== $cursor) {
                $message['params'] = [ 'cursor' => $cursor ];
            }

            $body    = $this->send($alias, (string) json_encode($message, JSON_UNESCAPED_SLASHES), $this->sessions[ $alias ]->headers())['body'];
            $decoded = json_decode(trim($body), true);
            if (! is_array($decoded) || ! isset($decoded['result']['tools']) || ! is_array($decoded['result']['tools'])) {
                $error = is_array($decoded) && isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'no tool list';
                throw new \RuntimeException(sprintf('Site "%s" did not return its tools: %s.', $alias, $error));
            }

            foreach ($decoded['result']['tools'] as $tool) {
                if (! is_array($tool) || ! isset($tool['name'])) {
                    continue;
                }
                $catalog[ (string) $tool['name'] ] = [
                    'read_only' => true === ($tool['annotations']['readOnlyHint'] ?? null),
                    'confirm'   => is_array($tool['inputSchema']['properties'] ?? null)
                        && array_key_exists('confirm', $tool['inputSchema']['properties']),
                ];
            }

            $cursor = $decoded['result']['nextCursor'] ?? null;
            if (! is_string($cursor) || '' === $cursor) {
                break;
            }
        }

        return $this->catalogs[ $alias ] = $catalog;
    }

    /**
     * Give a site its own MCP session before the first call to it: the
     * client's initialize params replayed, then notifications/initialized.
     * The session learned here is that site's alone.
     */
    private function ensure_session(string $alias): void
    {
        $session = $this->sessions[ $alias ] ?? null;
        if (null !== $session && (null !== $session->id || $alias === $this->default)) {
            return;
        }

        $session = new Session();
        $init    = [
            'jsonrpc' => '2.0',
            'id'      => $this->next_id(),
            'method'  => 'initialize',
            'params'  => $this->init_params ?? [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => [ 'name' => 'wpmcp-proxy', 'version' => '1' ],
            ],
        ];

        $result  = $this->send($alias, (string) json_encode($init, JSON_UNESCAPED_SLASHES), []);
        $decoded = json_decode(trim($result['body']), true);
        if (! is_array($decoded) || ! array_key_exists('result', $decoded)) {
            $error = is_array($decoded) && isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'no initialize result';
            throw new \RuntimeException(sprintf('Site "%s" refused the MCP handshake: %s.', $alias, $error));
        }
        $session->learn($result['headers'], $decoded);
        $this->sessions[ $alias ] = $session;

        $this->send($alias, '{"jsonrpc":"2.0","method":"notifications/initialized"}', $session->headers());
    }

    /**
     * Send one message to one site with THAT site's own config.
     *
     * A routed site is validated here, on first use, so one misconfigured
     * alias fails only the calls aimed at it. The default site was already
     * validated by select_site() before the router was built (main()), or
     * handed over pre-selected (pump()).
     *
     * @param array<int,string> $extra
     * @return array{body:string,headers:array<int,string>}
     */
    private function send(string $alias, string $body, array $extra): array
    {
        $site = $alias === $this->default
            ? $this->sites[ $alias ]
            : validate_site($alias, $this->sites[ $alias ], $this->env);
        debug_log($this->env, '-> ' . $alias . ' ' . substr($body, 0, 200));
        $result = ($this->transport)($site, $body, $extra);
        debug_log($this->env, '<- ' . $alias . ' ' . substr((string) $result['body'], 0, 200));

        return $result;
    }

    private function next_id(): string
    {
        return 'wpmcp-proxy-' . (++$this->seq);
    }
}

/**
 * The message pump for one site (the #77 entry point), kept for callers
 * and tests that proxy a single site. Delegates to pump_router().
 *
 * @param resource                                          $in      Input stream.
 * @param resource                                          $out     Output stream.
 * @param array{url:string,user:string,app_password:string} $site    Selected site.
 * @param array<string,string>                              $env     Environment snapshot.
 * @param Session|null                                      $session Session state (a fresh one when omitted).
 */
function pump($in, $out, array $site, array $env, ?Session $session = null): void
{
    pump_router($in, $out, new Router([ 'default' => $site ], 'default', $env, null, $session), $env);
}

/**
 * The message pump: reads newline-delimited JSON-RPC from $in, hands each
 * message to the router, writes each response to $out.
 *
 * Extracted from main() so the loop itself is testable over a pair of
 * in-memory streams. Everything that decides what reaches the client lives
 * here (notification suppression, one-line reframing, error envelopes), and
 * a seam that tests cannot drive is a seam that is not covered.
 *
 * @param resource             $in     Input stream.
 * @param resource             $out    Output stream.
 * @param Router               $router Routes each message to its site.
 * @param array<string,string> $env    Environment snapshot.
 */
function pump_router($in, $out, Router $router, array $env): void
{
    while (false !== ($line = fgets($in))) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }

        $request = json_decode($line, true);

        // JSON-RPC 2.0: a notification is a message with no id at all, and
        // anything written back for one is a protocol violation. This is not
        // theoretical here: the adapter answers a notification with HTTP 202
        // and a body of literally "null", which is valid JSON, so without
        // this guard the first thing a client reads after its
        // notifications/initialized is a bare "null" line.
        $is_notification = ! is_array($request) || ! array_key_exists('id', $request);

        try {
            $body = $router->dispatch($line, $request);

            if ($is_notification || '' === trim($body) || 'null' === trim($body)) {
                continue;
            }

            $out_line = one_line_response($body);
            if (null === $out_line) {
                fwrite(STDERR, '[wpmcp-proxy] non-JSON response body: ' . substr($body, 0, 500) . "\n");
                $error = error_line($request, 'The site returned a body that is not JSON-RPC.');
                if (null !== $error) {
                    fwrite($out, $error . "\n");
                }
                continue;
            }

            fwrite($out, $out_line . "\n");
        } catch (\RuntimeException $e) {
            fwrite(STDERR, '[wpmcp-proxy] ' . $e->getMessage() . "\n");
            $error = error_line($request, $e->getMessage());
            if (null !== $error) {
                fwrite($out, $error . "\n");
            }
        }
    }
}

/** The stdio entry point. Separated so tests can include this file without running it. */
function main(): int
{
    $env = [];
    foreach (getenv() as $key => $value) {
        $env[(string) $key] = (string) $value;
    }

    try {
        $sites  = resolve_sites($env);
        $site   = select_site($sites, $env);
        $router = new Router($sites, default_site_name($sites, $env), $env);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, '[wpmcp-proxy] ' . $e->getMessage() . "\n");
        return 1;
    }

    debug_log($env, 'proxying to ' . $site['url'] . ENDPOINT_PATH . ' (sites: ' . implode(', ', array_keys($sites)) . ')');

    $stdin = fopen('php://stdin', 'r');
    if (false === $stdin) {
        fwrite(STDERR, "[wpmcp-proxy] could not open stdin.\n");
        return 1;
    }

    pump_router($stdin, STDOUT, $router, $env);
    fclose($stdin);

    return 0;
}

if (! defined('WPMCP_PROXY_NO_RUN') && 'cli' === PHP_SAPI && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main());
}
