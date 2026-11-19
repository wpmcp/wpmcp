<?php

/**
 * One-off: measures how many bytes of tools/list make one token, so the
 * benchmark's bytes / N estimate has a measured N (issue #1130).
 *
 *   ANTHROPIC_API_KEY=... php tools/bench/calibrate-tokens.php \
 *       --tools-list=DIR/tools-list-full.json [--tools-list=DIR/tools-list-compact.json] \
 *       [--model=claude-opus-5-5] [--write]
 *
 * The tools/list files come from bin/bench-code-mode.sh --save-tools-list=DIR.
 * Only that public tool list is sent, as the tools of a count_tokens
 * request (POST /v1/messages/count_tokens); never fixture or site data. The
 * tool count is the request's input_tokens minus that of the same request
 * without tools. The factor is the bytes of the tools/list response over
 * those tokens, summed over the files given. --write records it in
 * tools/bench/token-factor.json, where the bench reads it and copies it
 * into every results file.
 *
 * Raw HTTP with the curl extension, like bin/bench-handshake.php: the repo
 * has no Anthropic SDK dependency and a one-off does not warrant one.
 *
 * REPO-ONLY: plain PHP, no WordPress load.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opts  = getopt('', ['tools-list:', 'model::', 'write']);
$files = array_values(array_filter((array) ($opts['tools-list'] ?? []), 'is_string'));
$key   = (string) getenv('ANTHROPIC_API_KEY');
$model = (string) ($opts['model'] ?? 'claude-opus-5-5');

if ([] === $files || ! function_exists('curl_init')) {
    fwrite(STDERR, "usage: ANTHROPIC_API_KEY=... php tools/bench/calibrate-tokens.php --tools-list=FILE [--tools-list=FILE] [--model=ID] [--write]\n");
    exit(2);
}
if ('' === $key) {
    fwrite(STDERR, "ANTHROPIC_API_KEY is not set; nothing was sent.\n");
    exit(2);
}

/** @return int input_tokens of a count_tokens request. */
function wpmcp_bench_count(string $key, string $model, ?array $tools): int
{
    $body = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => 'x']],
    ];
    if (null !== $tools) {
        $body['tools'] = $tools;
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages/count_tokens');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $out    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json   = is_string($out) ? json_decode($out, true) : null;
    if (200 !== $status || ! isset($json['input_tokens'])) {
        fwrite(STDERR, sprintf("count_tokens answered HTTP %d: %s\n", $status, is_string($out) ? substr($out, 0, 500) : ''));
        exit(1);
    }
    return (int) $json['input_tokens'];
}

$baseline = wpmcp_bench_count($key, $model, null);
$bytes    = 0;
$tokens   = 0;
$per_file = [];
foreach ($files as $file) {
    $raw  = (string) file_get_contents($file);
    $msg  = json_decode($raw, true);
    $list = $msg['result']['tools'] ?? ($msg['tools'] ?? null);
    if (! is_array($list)) {
        fwrite(STDERR, "{$file} holds no tools/list result\n");
        exit(1);
    }
    // The MCP tool as an Anthropic tool definition: what a client sends.
    $tools = array_map(static fn(array $t): array => [
        'name'         => (string) $t['name'],
        'description'  => (string) ($t['description'] ?? ''),
        'input_schema' => $t['inputSchema'] ?? ['type' => 'object'],
    ], $list);
    $count       = wpmcp_bench_count($key, $model, $tools) - $baseline;
    $bytes      += strlen($raw);
    $tokens     += $count;
    $per_file[]  = ['file' => basename($file), 'bytes' => strlen($raw), 'tokens' => $count, 'bytes_per_token' => round(strlen($raw) / max(1, $count), 3)];
    printf("%s: %d bytes, %d tokens, %.2f bytes per token\n", basename($file), strlen($raw), $count, strlen($raw) / max(1, $count));
}

$factor = round($bytes / max(1, $tokens), 3);
printf("factor: %.3f bytes per token (%s)\n", $factor, $model);

if (isset($opts['write'])) {
    file_put_contents(__DIR__ . '/token-factor.json', json_encode([
        'bytes_per_token' => $factor,
        'model'           => $model,
        'measured_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        'method'          => 'count_tokens with the tools/list tools minus count_tokens without tools; bytes are the whole tools/list response',
        'files'           => $per_file,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "wrote tools/bench/token-factor.json\n";
}
