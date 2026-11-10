#!/usr/bin/env php
<?php

/**
 * Times the MCP handshake over HTTP (issue #733).
 *
 * Clients allow the handshake about 5 seconds: Claude Desktop skips its
 * connector checks past that, and Claude Code's version negotiation probe
 * times out at 5000ms. This sends the requests a client sends when it
 * connects, on both protocol revisions, and prints for each the time the
 * client saw next to the time the server reports in its Server-Timing
 * header (app: the whole PHP request, abilities: ability registration). The
 * difference is the network: a tunnel, a proxy, TLS.
 *
 *   php bin/bench-handshake.php --url=https://example.com --user=admin --password='xxxx xxxx xxxx xxxx' [--runs=5]
 *
 * --url is the site address; the MCP endpoint is found at
 * /wp-json/mcp/wpmcp-server, or with --plain-permalinks at
 * /?rest_route=/mcp/wpmcp-server. --password is an application password.
 *
 * REPO-ONLY, like bin/wpmcp-proxy.php: the release zips do not ship bin/.
 * Plain PHP with the curl extension, no WordPress load.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opts = getopt('', ['url:', 'user:', 'password:', 'runs::', 'plain-permalinks']);
if (! isset($opts['url'], $opts['user'], $opts['password']) || ! function_exists('curl_init')) {
    fwrite(STDERR, "usage: php bin/bench-handshake.php --url=URL --user=USER --password=APP_PASSWORD [--runs=5] [--plain-permalinks]\n(needs the curl extension)\n");
    exit(2);
}

$site     = rtrim((string) $opts['url'], '/');
$endpoint = isset($opts['plain-permalinks']) ? $site . '/?rest_route=/mcp/wpmcp-server' : $site . '/wp-json/mcp/wpmcp-server';
$auth     = 'Basic ' . base64_encode($opts['user'] . ':' . $opts['password']);
$runs     = max(1, (int) ($opts['runs'] ?? 5));

/**
 * @param array<string,mixed>  $body
 * @param array<string,string> $headers
 * @return array{ms: float, status: int, body: string, headers: array<string,string>}
 */
function wpmcp_bench_post(string $url, array $body, array $headers): array
{
    $lines = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $received = [];
    $ch       = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => $lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
            $parts = explode(':', $line, 2);
            if (2 === count($parts)) {
                $received[ strtolower(trim($parts[0])) ] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $start  = microtime(true);
    $output = curl_exec($ch);
    $ms     = (microtime(true) - $start) * 1000;

    return [
        'ms'      => $ms,
        'status'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body'    => is_string($output) ? $output : '',
        'headers' => $received,
    ];
}

/** @return array<string,float> Server-Timing metrics by name. */
function wpmcp_bench_server_timing(string $header): array
{
    $metrics = [];
    foreach (explode(',', $header) as $metric) {
        if (preg_match('/^\s*([a-z-]+);dur=([0-9.]+)/i', $metric, $m)) {
            $metrics[ $m[1] ] = (float) $m[2];
        }
    }
    return $metrics;
}

$modern = [
    'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
    'io.modelcontextprotocol/clientInfo'         => ['name' => 'wpmcp-bench', 'version' => '1'],
];

$rows = [];
for ($i = 0; $i < $runs; $i++) {
    $steps = [];

    $init = wpmcp_bench_post($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities'    => new stdClass(),
        'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
    ]], ['Authorization' => $auth]);
    $steps['2025-11-25 initialize'] = $init;
    $session = $init['headers']['mcp-session-id'] ?? '';

    $steps['2025-11-25 notifications/initialized'] = wpmcp_bench_post($endpoint, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization' => $auth, 'Mcp-Session-Id' => $session]);
    $steps['2025-11-25 tools/list'] = wpmcp_bench_post($endpoint, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Authorization' => $auth, 'Mcp-Session-Id' => $session]);
    $steps['2026-07-28 server/discover'] = wpmcp_bench_post(
        $endpoint,
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'server/discover', 'params' => ['_meta' => $modern]],
        ['Authorization' => $auth, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']
    );
    $steps['2026-07-28 tools/list'] = wpmcp_bench_post(
        $endpoint,
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => ['_meta' => $modern]],
        ['Authorization' => $auth, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']
    );
    $steps['unauthenticated initialize (401)'] = wpmcp_bench_post($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities'    => new stdClass(),
        'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
    ]], []);

    foreach ($steps as $name => $step) {
        $timing = wpmcp_bench_server_timing($step['headers']['server-timing'] ?? '');
        $rows[ $name ]['client'][]    = $step['ms'];
        $rows[ $name ]['app'][]       = $timing['app'] ?? null;
        $rows[ $name ]['abilities'][] = $timing['abilities'] ?? null;
        $rows[ $name ]['status']      = $step['status'];
        $rows[ $name ]['bytes']       = strlen($step['body']);
    }
}

/** @param array<int,float|null> $values */
function wpmcp_bench_median(array $values): string
{
    $values = array_values(array_filter($values, static fn ($v) => null !== $v));
    if ([] === $values) {
        return '-';
    }
    sort($values);
    return sprintf('%.0f', $values[ intdiv(count($values), 2) ]);
}

printf("%s, %d run(s), medians in ms\n", $endpoint, $runs);
printf("%-38s %6s %8s %8s %10s %9s %9s\n", 'request', 'status', 'bytes', 'client', 'server', 'abilities', 'network');
foreach ($rows as $name => $row) {
    $client  = wpmcp_bench_median($row['client']);
    $server  = wpmcp_bench_median($row['app']);
    $network = '-' === $server ? '-' : (string) max(0, (int) $client - (int) $server);
    printf("%-38s %6d %8d %8s %10s %9s %9s\n", $name, $row['status'], $row['bytes'], $client, $server, wpmcp_bench_median($row['abilities']), $network);
}
printf("first request of the run: %.0fms (a cold PHP worker or opcache pays its warm-up here)\n", $rows['2025-11-25 initialize']['client'][0]);
