<?php

/**
 * The HTTP mode of the code mode benchmark (issue #1130): the same
 * scenarios, replayed as MCP tools/call requests against a running site,
 * so the bytes are the real response bodies and the times include PHP
 * start-up and the network.
 *
 *   php tools/bench/http.php --url=URL --user=USER --password=APP_PASSWORD \
 *       --wp-path=SITE [--wp-cli=wp] [--scenario=N] [--json=OUT] \
 *       [--save-tools-list=DIR] [--plain-permalinks]
 *
 * Normally started by bin/bench-code-mode.sh --mode=http, which restores the
 * site's exposure mode and prunes the fixtures on exit.
 *
 * --wp-path is the site's WordPress directory, for WP-CLI: the fixtures are
 * seeded and checked there (tools/bench/remote.php), and the exposure mode
 * is switched there to measure each surface's tools/list. Without it only
 * the tools/list of the site's current mode is measured and every scenario
 * is reported as skipped.
 *
 * Scenario 1 refunds an order: the site must enable refunds.create with the
 * wpmcp_woo_op_enabled filter (an mu-plugin), or the run fails at that step.
 * Use a disposable local site: the scenarios write.
 *
 * REPO-ONLY: plain PHP with the curl extension, no WordPress load.
 */

namespace WPMCP\Bench;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Surface.php';

$opts = getopt('', ['url:', 'user:', 'password:', 'wp-path::', 'wp-cli::', 'scenario::', 'json::', 'save-tools-list::', 'plain-permalinks']);
if (! isset($opts['url'], $opts['user'], $opts['password']) || ! function_exists('curl_init')) {
    fwrite(STDERR, "usage: php tools/bench/http.php --url=URL --user=USER --password=APP_PASSWORD [--wp-path=SITE] [--wp-cli=wp] [--scenario=N] [--json=OUT] [--save-tools-list=DIR] [--plain-permalinks]\n(needs the curl extension)\n");
    exit(2);
}

final class Http_Client
{
    private string $endpoint;
    private string $auth;
    private string $session = '';
    private int $id = 1;

    public function __construct(string $endpoint, string $auth)
    {
        $this->endpoint = $endpoint;
        $this->auth     = $auth;
    }

    /** @return array{status: int, body: string, headers: array<string,string>} */
    public function post(array $message): array
    {
        $lines = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: ' . $this->auth, 'MCP-Protocol-Version: 2025-11-25'];
        if ('' !== $this->session) {
            $lines[] = 'Mcp-Session-Id: ' . $this->session;
        }
        $headers = [];
        $ch      = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($message),
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (2 === count($parts)) {
                    $headers[ strtolower(trim($parts[0])) ] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : '', 'headers' => $headers];
    }

    public function initialize(): void
    {
        $init = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
        ]]);
        if (200 !== $init['status']) {
            throw new \RuntimeException(sprintf('initialize answered HTTP %d: %s', $init['status'], substr($init['body'], 0, 300)));
        }
        $this->session = $init['headers']['mcp-session-id'] ?? '';
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /** The JSON-RPC message in a body, which is JSON or a text/event-stream. */
    public static function decode(string $body): ?array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $json = json_decode(trim(substr($line, 5)), true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        return null;
    }

    /** @return array{bytes: int, tools: int, payload: string} */
    public function tools_list(): array
    {
        $r    = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'tools/list']);
        $msg  = self::decode($r['body']);
        $list = $msg['result']['tools'] ?? null;
        if (! is_array($list)) {
            throw new \RuntimeException(sprintf('tools/list answered HTTP %d: %s', $r['status'], substr($r['body'], 0, 300)));
        }
        return ['bytes' => strlen($r['body']), 'tools' => count($list), 'payload' => $r['body']];
    }

    /**
     * The HTTP transport for Runner: one tools/call, measured as the
     * response body the client receives.
     *
     * @return array{value: mixed, bytes: int, error: ?string}
     */
    public function call(string $ability, array $args): array
    {
        $r   = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'tools/call', 'params' => [
            'name'      => str_replace('/', '-', $ability),
            'arguments' => [] === $args ? new \stdClass() : $args,
        ]]);
        $msg = self::decode($r['body']);
        if (! is_array($msg)) {
            return ['value' => null, 'bytes' => strlen($r['body']), 'error' => sprintf('HTTP %d, not JSON-RPC: %s', $r['status'], substr($r['body'], 0, 200))];
        }
        if (isset($msg['error'])) {
            return ['value' => null, 'bytes' => strlen($r['body']), 'error' => (string) ($msg['error']['message'] ?? json_encode($msg['error']))];
        }

        $result = (array) ($msg['result'] ?? []);
        $text   = (string) ($result['content'][0]['text'] ?? '');
        if (! empty($result['isError'])) {
            return ['value' => null, 'bytes' => strlen($r['body']), 'error' => $text];
        }
        $value = $result['structuredContent'] ?? json_decode($text, true);

        $error = null;
        if (is_array($value) && ! empty($value['error'])) {
            $error = is_array($value['error']) ? (string) ($value['error']['code'] ?? '') . ': ' . (string) ($value['error']['message'] ?? '') : (string) $value['error'];
        } elseif (is_array($value) && isset($value['status']) && is_int($value['status']) && $value['status'] >= 400) {
            $error = 'HTTP ' . $value['status'];
        }

        return ['value' => $value, 'bytes' => strlen($r['body']), 'error' => $error];
    }
}

/** Runs WP-CLI on the site and decodes the last JSON line it printed. */
function wp_cli(string $wp, string $path, array $args): ?array
{
    $cmd = escapeshellcmd($wp) . ' --path=' . escapeshellarg($path) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $lines, $code);
    foreach (array_reverse($lines) as $line) {
        $json = json_decode($line, true);
        if (is_array($json)) {
            return $json;
        }
    }
    fwrite(STDERR, sprintf("wp %s exited %d:\n%s\n", implode(' ', $args), $code, implode("\n", array_slice($lines, -20))));
    return null;
}

$site     = rtrim((string) $opts['url'], '/');
$endpoint = isset($opts['plain-permalinks']) ? $site . '/?rest_route=/mcp/wpmcp-server' : $site . '/wp-json/mcp/wpmcp-server';
$client   = new Http_Client($endpoint, 'Basic ' . base64_encode($opts['user'] . ':' . $opts['password']));
$wp_path  = (string) ($opts['wp-path'] ?? '');
$wp       = (string) ($opts['wp-cli'] ?? 'wp');
$only     = (string) ($opts['scenario'] ?? '');
$dump     = (string) ($opts['save-tools-list'] ?? '');
$remote   = __DIR__ . '/remote.php';

$client->initialize();

$doc = [
    'issue'        => 1130,
    'mode'         => 'http',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit'       => (string) getenv('WPMCP_BENCH_COMMIT'),
    'site'         => $site,
    'tokens'       => ['bytes_per_token' => 4.0, 'method' => 'bytes / 4, not calibrated'],
    'methods'      => [
        'tools_list_bytes' => 'the tools/list response body over HTTP',
        'calls'            => 'tool calls the scripted sequence makes',
        'turns'            => 'model round trips: a step whose calls are independent (a fan-out) is one turn',
        'result_bytes'     => 'tools/call response bodies over HTTP, summed (text content and structuredContent)',
        'ms'               => 'wall time of the replay as the client saw it, without the tools/list',
    ],
    'tools_list'   => [],
    'scenarios'    => [],
];
$factor = __DIR__ . '/token-factor.json';
if (is_readable($factor)) {
    $data = json_decode((string) file_get_contents($factor), true);
    if (is_array($data) && (float) ($data['bytes_per_token'] ?? 0) > 0) {
        $doc['tokens'] = ['bytes_per_token' => (float) $data['bytes_per_token'], 'method' => sprintf('bytes / %.2f, calibrated with count_tokens on %s', (float) $data['bytes_per_token'], (string) ($data['measured_at'] ?? '?'))];
    }
}

$surfaces = '' === $wp_path ? ['current'] : [Surface::FULL, Surface::COMPACT];
foreach ($surfaces as $surface) {
    if ('current' !== $surface) {
        exec(escapeshellcmd($wp) . ' --path=' . escapeshellarg($wp_path) . ' option update wpmcp_tool_exposure_mode ' . escapeshellarg($surface) . ' 2>&1', $ignored, $code);
        if (0 !== $code) {
            fwrite(STDERR, "could not set the exposure mode to {$surface} through WP-CLI\n");
            exit(1);
        }
    }
    $list = $client->tools_list();
    if ('' !== $dump && is_dir($dump)) {
        file_put_contents($dump . '/tools-list-' . $surface . '.json', $list['payload']);
    }
    unset($list['payload']);
    $doc['tools_list'][ $surface ] = $list + ['source' => 'HTTP tools/list response body'];
}

$files = glob(__DIR__ . '/scenarios/*.php') ?: [];
sort($files, SORT_NATURAL);
$failed = 0;
foreach ($files as $file) {
    if ('' !== $only && (int) $only !== (int) basename($file)) {
        continue;
    }
    $scenario = require $file;
    $entry    = ['id' => (int) $scenario['id'], 'slug' => (string) $scenario['slug'], 'title' => (string) $scenario['title'], 'surfaces' => []];

    foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
        if ('' === $wp_path) {
            $entry['surfaces'][ $surface ] = ['status' => 'skipped', 'reason' => 'needs --wp-path to seed the fixtures'];
            continue;
        }
        $seeded = wp_cli($wp, $wp_path, ['eval-file', $remote, 'seed', (string) $scenario['id']]);
        if (null === $seeded) {
            $entry['surfaces'][ $surface ] = ['status' => 'failed', 'reason' => 'seeding through WP-CLI failed'];
            $failed++;
            continue;
        }
        if (isset($seeded['skipped'])) {
            $entry['surfaces'][ $surface ] = ['status' => 'skipped', 'reason' => (string) $seeded['skipped']];
            continue;
        }

        $fixtures = (array) $seeded['fixtures'];
        $steps    = ($scenario['surfaces'])($fixtures)[ $surface ];
        $out      = (new Runner([$client, 'call']))->run($steps);

        if ('ok' === $out['status']) {
            $tmp = tempnam(sys_get_temp_dir(), 'wpmcp-bench-');
            file_put_contents($tmp, json_encode(['fixtures' => $fixtures, 'state' => $out['state']]));
            $checked = wp_cli($wp, $wp_path, ['eval-file', $remote, 'verify', (string) $scenario['id'], $tmp]);
            unlink($tmp);
            if (null === $checked || null !== ($checked['problem'] ?? null)) {
                $out['status'] = 'failed';
                $out['reason'] = 'verify: ' . (string) ($checked['problem'] ?? 'WP-CLI failed');
            }
        }
        wp_cli($wp, $wp_path, ['eval-file', $remote, 'prune']);

        unset($out['state']);
        $entry['surfaces'][ $surface ] = $out;
        if ('failed' === $out['status']) {
            $failed++;
        }
    }
    $doc['scenarios'][] = $entry;
}

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (isset($opts['json']) && '' !== (string) $opts['json']) {
    file_put_contents((string) $opts['json'], $json);
} else {
    echo $json;
}
exit($failed > 0 ? 1 : 0);
