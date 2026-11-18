<?php

namespace WPMCP\Tools\Diagnostics;

use WPMCP\MCP\Opt_In_Required;
use WPMCP\Privacy\Masking;
use WPMCP\Tools\Filesystem\Filesystem_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: search the web server access log (issue #848), reached as
 * get-debug-log with source=access, so a cleanup can find the request that
 * dropped a file and the IPs that keep probing the site.
 *
 * Gates and limits:
 *
 *  - Off by default: access logs hold every visitor's IP, so the site opts
 *    in with the wpmcp_enable_access_log filter or the
 *    WPMCP_ENABLE_ACCESS_LOG constant (opt_in_required otherwise). The
 *    ability itself already needs Filesystem_Guard::read_capability().
 *  - IPs and user agents are masked with the Masking rule (203.0.*.* and
 *    M***), since a search returns many visitors at once. A site that wants
 *    full addresses for blocking opts in a second time with
 *    wpmcp_access_log_unmasked. An ip filter always matches the full
 *    address, so a known IP can be searched while masked.
 *  - Query string values whose name looks like a secret or an identity
 *    (key, token, pass, login, email, ...) are always masked.
 *  - Only files inside the folders of the known log locations (common
 *    Apache, nginx, LiteSpeed and host paths, the WPMCP_ACCESS_LOG constant
 *    and the wpmcp_access_log_paths filter) can be read; never an
 *    arbitrary path.
 *  - Reads from the end in chunks, newest first, across the log and its
 *    rotations (.1, .2.gz, ...), within a byte and time budget per call. A
 *    search pages with an opaque cursor; a summary reports complete:false
 *    when the budget ran out first.
 */
class Access_Log_Search
{
    public const ENABLE_FILTER = 'wpmcp_enable_access_log';
    public const ENABLE_CONST  = 'WPMCP_ENABLE_ACCESS_LOG';
    public const UNMASK_FILTER = 'wpmcp_access_log_unmasked';
    public const PATHS_FILTER  = 'wpmcp_access_log_paths';

    public const DEFAULT_LIMIT  = 50;
    public const MAX_LIMIT      = 200;
    public const CHUNK_BYTES    = 256 * 1024;
    public const MAX_SCAN_BYTES = 16 * 1024 * 1024;
    public const MAX_SECONDS    = 3.0;
    public const MAX_GZ_BYTES   = 8 * 1024 * 1024;
    public const MAX_GZ_DECODED = 32 * 1024 * 1024;
    public const MAX_ROTATIONS  = 7;
    public const TOP_N          = 10;
    public const MAX_PHP_POSTS  = 50;

    /** Query parameter names whose values are masked. */
    private const SECRET_KEYS = '/pass|pwd|token|key|secret|auth|nonce|sess|sid|code|sig|hash|otp|jwt|reset|mail|user|login|^log$/i';

    /** Combined format, with an optional vhost:port first (vhost_combined). */
    private const LINE = '/^(?:(\S+) )??(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) (\S+)(?: [^"]*)?" (\d{3}) (\S+)(?: "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)")?/';

    private bool $unmasked = false;

    /** @var array<string, string> decoded gzip files for this call */
    private array $gz = [];

    public static function is_enabled(): bool
    {
        $default = defined(self::ENABLE_CONST) && (bool) constant(self::ENABLE_CONST);
        return true === (bool) apply_filters('wpmcp_enable_access_log', $default);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new Opt_In_Required(
                'Reading the web server access log is disabled.',
                'wpmcp_enable_access_log',
                'WPMCP_ENABLE_ACCESS_LOG',
                'Access logs hold the IP address of every visitor.'
            );
        }
        $this->unmasked = true === (bool) apply_filters('wpmcp_access_log_unmasked', false);

        $log    = $this->locate(isset($args['path']) ? (string) $args['path'] : '');
        $files  = $this->rotations($log);
        $filter = $this->filter(is_array($args['filter'] ?? null) ? $args['filter'] : []);
        $limit  = isset($args['lines']) ? max(1, min(self::MAX_LIMIT, (int) $args['lines'])) : self::DEFAULT_LIMIT;
        [ $file_index, $offset ] = $this->cursor(isset($args['cursor']) ? (string) $args['cursor'] : '', count($files));

        $summary = ! empty($args['summary']);
        $agg     = [ 'requests' => 0, 'ips' => [], 'paths' => [], 'status' => [], 'php_posts' => [] ];
        $entries = [];
        $scanned = [];
        $skipped = [];
        $budget  = [ 'bytes' => 0, 'start' => microtime(true) ];
        $next    = null;
        $done    = false;

        $file_count = count($files);
        for ($i = $file_index; $i < $file_count && ! $done; $i++) {
            $file = $files[ $i ];
            $size = $this->size($file);
            if (null === $size) {
                $skipped[] = $file;
                continue;
            }
            $scanned[] = $file;
            $end       = ($i === $file_index && null !== $offset) ? min($offset, $size) : $size;

            while ($end > 0) {
                if ($budget['bytes'] >= self::MAX_SCAN_BYTES || (microtime(true) - $budget['start']) >= self::MAX_SECONDS) {
                    $next = $i . ':' . $end;
                    $done = true;
                    break;
                }
                $start = max(0, $end - self::CHUNK_BYTES);
                $chunk = $this->read($file, $start, $end - $start);
                $budget['bytes'] += strlen($chunk);
                $base = $start;
                if ($start > 0) {
                    $nl = strpos($chunk, "\n");
                    if (false === $nl) {
                        // One line longer than a chunk: not an access log line.
                        $end = $start;
                        continue;
                    }
                    $chunk = (string) substr($chunk, $nl + 1);
                    $base  = $start + $nl + 1;
                }

                $lines  = explode("\n", $chunk);
                $starts = [];
                $pos    = $base;
                foreach ($lines as $j => $line) {
                    $starts[ $j ] = $pos;
                    $pos         += strlen($line) + 1;
                }

                for ($j = count($lines) - 1; $j >= 0; $j--) {
                    $entry = $this->parse($lines[ $j ]);
                    if (null === $entry) {
                        continue;
                    }
                    if (null !== $filter['since'] && null !== $entry['ts'] && $entry['ts'] < $filter['since']) {
                        // Logs are chronological: nothing older can match.
                        $done = true;
                        break;
                    }
                    if (! $this->matches($entry, $filter)) {
                        continue;
                    }
                    if ($summary) {
                        $this->aggregate($agg, $entry);
                        continue;
                    }
                    $entries[] = $this->present($entry);
                    if (count($entries) >= $limit) {
                        $remaining = $starts[ $j ];
                        if ($remaining > 0) {
                            $next = $i . ':' . $remaining;
                        } elseif ($i + 1 < $file_count) {
                            $next = ($i + 1) . ':';
                        }
                        $done = true;
                        break;
                    }
                }
                if ($done) {
                    break;
                }
                $end = $base;
            }
        }

        $out = [
            'source'  => 'access',
            'log'     => $log,
            'files'   => $scanned,
            'masked'  => ! $this->unmasked,
        ];
        if ([] !== $skipped) {
            $out['skipped'] = $skipped;
        }
        if ($summary) {
            $out['summary'] = $this->summarize($agg, null === $next);
            return $out;
        }

        return $out + [
            'count'       => count($entries),
            'entries'     => $entries,
            'next_cursor' => $next,
        ];
    }

    // ------------------------------------------------------------------
    // Locating the log
    // ------------------------------------------------------------------

    /**
     * The known access log locations, in order: the WPMCP_ACCESS_LOG
     * constant, then common server and host paths, then the filter.
     *
     * @return string[]
     */
    public static function candidates(): array
    {
        $paths = [];
        if (defined('WPMCP_ACCESS_LOG') && is_string(constant('WPMCP_ACCESS_LOG')) && '' !== constant('WPMCP_ACCESS_LOG')) {
            $paths[] = (string) constant('WPMCP_ACCESS_LOG');
        }
        $home = dirname(rtrim(ABSPATH, '/\\'));
        $paths = array_merge($paths, [
            '/var/log/apache2/access.log',
            '/var/log/apache2/access_log',
            '/var/log/httpd/access_log',
            '/var/log/httpd/access.log',
            '/var/log/nginx/access.log',
            '/usr/local/lsws/logs/access.log',
            '/usr/local/apache/logs/access_log',
            $home . '/logs/access.log',
            $home . '/logs/access_log',
            $home . '/access-logs/access.log',
        ]);

        $filtered = apply_filters('wpmcp_access_log_paths', $paths);
        return array_values(array_filter(
            array_map('strval', is_array($filtered) ? $filtered : $paths),
            static fn (string $p): bool => '' !== $p && false === strpos($p, "\0")
        ));
    }

    /** The log to read: the requested path inside a log folder, or the first readable candidate. */
    private function locate(string $requested): string
    {
        $candidates = self::candidates();

        if ('' !== $requested) {
            // The folder is checked first, so a refusal never tells whether
            // a file outside the log folders exists.
            $dir     = realpath(dirname($requested));
            $allowed = false;
            foreach ($candidates as $candidate) {
                if (false !== $dir && realpath(dirname($candidate)) === $dir) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                throw new \RuntimeException(esc_html(sprintf(
                    'The path %s is not inside a known access log folder. Allowed folders are those of: %s. Add a folder with the WPMCP_ACCESS_LOG constant or the %s filter.',
                    $requested,
                    implode(', ', $candidates),
                    self::PATHS_FILTER
                )));
            }
            $real = realpath($requested);
            if (false === $real || dirname($real) !== $dir || ! is_file($real) || ! is_readable($real) || Filesystem_Guard::is_protected($real)) {
                throw new \RuntimeException(esc_html(sprintf('The access log %s does not exist or cannot be read.', $requested)));
            }
            return $real;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $real = realpath($candidate);
                return false === $real ? $candidate : $real;
            }
        }

        throw new \RuntimeException(esc_html(sprintf(
            'No readable web server access log was found. Tried: %s. Define WPMCP_ACCESS_LOG in wp-config.php with the full path of the log, or add paths with the %s filter; the web server user must be able to read it.',
            implode(', ', $candidates),
            self::PATHS_FILTER
        )));
    }

    /**
     * The log and its rotations, newest first: access.log, access.log.1,
     * access.log.2.gz and so on (either form of each number).
     *
     * @return string[]
     */
    private function rotations(string $log): array
    {
        $files = [ $log ];
        for ($n = 1; $n <= self::MAX_ROTATIONS; $n++) {
            foreach ([ $log . '.' . $n, $log . '.' . $n . '.gz' ] as $rotated) {
                if (is_file($rotated) && is_readable($rotated)) {
                    $files[] = $rotated;
                }
            }
        }
        return $files;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    private static function is_gz(string $file): bool
    {
        return '.gz' === substr($file, -3);
    }

    /** Bytes in $file (decoded for gzip), or null when it cannot be read within budget. */
    private function size(string $file): ?int
    {
        if (! self::is_gz($file)) {
            $size = filesize($file);
            return false === $size ? null : (int) $size;
        }
        if (! isset($this->gz[ $file ])) {
            $raw = filesize($file);
            if (false === $raw || $raw > self::MAX_GZ_BYTES) {
                return null;
            }
            $data = gzdecode((string) file_get_contents($file), self::MAX_GZ_DECODED);
            if (false === $data) {
                return null;
            }
            $this->gz[ $file ] = $data;
        }
        return strlen($this->gz[ $file ]);
    }

    private function read(string $file, int $start, int $length): string
    {
        if (self::is_gz($file)) {
            return (string) substr($this->gz[ $file ], $start, $length);
        }
        // file_get_contents() with an offset reads a slice without loading
        // the whole file (see Get_Debug_Log::read_tail()).
        $data = file_get_contents($file, false, null, $start, $length);
        return false === $data ? '' : $data;
    }

    /** @return array{0: int, 1: int|null} file index and end offset */
    private function cursor(string $cursor, int $files): array
    {
        if ('' === $cursor) {
            return [ 0, null ];
        }
        if (1 !== preg_match('/^(\d+):(\d*)$/', $cursor, $m) || (int) $m[1] >= $files) {
            throw new \InvalidArgumentException('Invalid cursor: pass the next_cursor of the previous call unchanged.');
        }
        return [ (int) $m[1], '' === $m[2] ? null : (int) $m[2] ];
    }

    // ------------------------------------------------------------------
    // Parsing and filtering
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function parse(string $line): ?array
    {
        $line = rtrim($line, "\r");
        if ('' === $line || 1 !== preg_match(self::LINE, $line, $m)) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[3]);
        $url  = $m[5];
        $q    = strpos($url, '?');

        return [
            'ts'      => false === $time ? null : $time->getTimestamp(),
            'ip'      => $m[2],
            'method'  => $m[4],
            'path'    => $url,
            'route'   => false === $q ? $url : substr($url, 0, $q),
            'status'  => (int) $m[6],
            'bytes'   => ctype_digit($m[7]) ? (int) $m[7] : 0,
            'referer' => isset($m[8]) && '-' !== $m[8] ? stripcslashes($m[8]) : '',
            'agent'   => isset($m[9]) && '-' !== $m[9] ? stripcslashes($m[9]) : '',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function filter(array $raw): array
    {
        $since = isset($raw['since']) ? self::time($raw['since'], 'since') : null;
        $until = isset($raw['until']) ? self::time($raw['until'], 'until') : null;

        if (isset($raw['around_file']) && '' !== (string) $raw['around_file']) {
            $abs = Filesystem_Guard::resolve_path((string) $raw['around_file']);
            if (is_wp_error($abs) || ! file_exists($abs)) {
                throw new \InvalidArgumentException(esc_html(sprintf('around_file %s is not a file inside the WordPress installation.', (string) $raw['around_file'])));
            }
            $window = isset($raw['window']) ? max(1, min(1440, (int) $raw['window'])) : 10;
            $mtime  = (int) filemtime($abs);
            $since  = $mtime - $window * 60;
            $until  = $mtime + $window * 60;
        }

        $status = isset($raw['status']) ? strtolower(trim((string) $raw['status'])) : '';
        if ('' !== $status && 1 !== preg_match('/^[1-5](\d\d|xx)$/', $status)) {
            throw new \InvalidArgumentException('status must be a code such as 404 or a class such as 4xx.');
        }

        return [
            'ip'     => isset($raw['ip']) ? trim((string) $raw['ip']) : '',
            'path'   => isset($raw['path']) ? (string) $raw['path'] : '',
            'status' => $status,
            'method' => isset($raw['method']) ? strtoupper(trim((string) $raw['method'])) : '',
            'agent'  => isset($raw['agent']) ? (string) $raw['agent'] : '',
            'since'  => $since,
            'until'  => $until,
        ];
    }

    /** @param mixed $value */
    private static function time($value, string $name): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);
        if (false === $ts) {
            throw new \InvalidArgumentException(esc_html(sprintf('%s must be a date and time such as 2026-10-10T12:00:00Z or a Unix timestamp.', $name)));
        }
        return $ts;
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed> $f
     */
    private function matches(array $e, array $f): bool
    {
        if (null !== $f['until'] && null !== $e['ts'] && $e['ts'] > $f['until']) {
            return false;
        }
        if ('' !== $f['ip'] && $e['ip'] !== $f['ip'] && 0 !== strpos($e['ip'], rtrim($f['ip'], '*'))) {
            return false;
        }
        if ('' !== $f['method'] && $e['method'] !== $f['method']) {
            return false;
        }
        if ('' !== $f['status']) {
            $code = (string) $e['status'];
            if ('xx' === substr($f['status'], 1) ? $code[0] !== $f['status'][0] : $code !== $f['status']) {
                return false;
            }
        }
        if ('' !== $f['path'] && false === stripos($e['path'], $f['path'])) {
            return false;
        }
        return '' === $f['agent'] || false !== stripos($e['agent'], $f['agent']);
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function present(array $e): array
    {
        $row = [
            'time'   => null === $e['ts'] ? null : gmdate('Y-m-d\TH:i:sP', $e['ts']),
            'ip'     => $this->ip($e['ip']),
            'method' => $e['method'],
            'path'   => self::mask_query($e['path']),
            'status' => $e['status'],
            'bytes'  => $e['bytes'],
            'agent'  => $this->unmasked ? $e['agent'] : Masking::value($e['agent']),
        ];
        if ('' !== $e['referer']) {
            $row['referer'] = self::mask_query($e['referer']);
        }
        return $row;
    }

    /** An IPv4 address keeps its first two parts, an IPv6 one its first two groups. */
    private function ip(string $ip): string
    {
        if ($this->unmasked) {
            return $ip;
        }
        if (1 === preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $ip, $m)) {
            return $m[1] . '.' . $m[2] . '.*.*';
        }
        if (false !== strpos($ip, ':') && false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = explode(':', $ip);
            return $groups[0] . ':' . $groups[1] . ':*';
        }
        return (string) Masking::value($ip);
    }

    /** Values of query parameters named like a secret or an identity read ***. */
    public static function mask_query(string $url): string
    {
        $q = strpos($url, '?');
        if (false === $q) {
            return $url;
        }
        $pairs = explode('&', substr($url, $q + 1));
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            if (false === $eq) {
                continue;
            }
            $name = rawurldecode(substr($pair, 0, $eq));
            if (1 === preg_match(self::SECRET_KEYS, $name)) {
                $pairs[ $i ] = substr($pair, 0, $eq) . '=***';
            }
        }
        return substr($url, 0, $q + 1) . implode('&', $pairs);
    }

    /**
     * @param array<string, mixed> $agg
     * @param array<string, mixed> $e
     */
    private function aggregate(array &$agg, array $e): void
    {
        $agg['requests']++;
        $ip                  = $this->ip($e['ip']);
        $agg['ips'][ $ip ]   = ($agg['ips'][ $ip ] ?? 0) + 1;
        $route               = (string) $e['route'];
        $agg['paths'][ $route ] = ($agg['paths'][ $route ] ?? 0) + 1;
        $class               = substr((string) $e['status'], 0, 1) . 'xx';
        $agg['status'][ $class ] = ($agg['status'][ $class ] ?? 0) + 1;

        $php_post = 'POST' === $e['method'] && 1 === preg_match('#/wp-content/.*\.php$#i', $route);
        if ($php_post && count($agg['php_posts']) < self::MAX_PHP_POSTS) {
            $agg['php_posts'][] = $this->present($e);
        }
    }

    /**
     * @param array<string, mixed> $agg
     * @return array<string, mixed>
     */
    private function summarize(array $agg, bool $complete): array
    {
        $top = static function (array $counts, string $key): array {
            arsort($counts);
            $rows = [];
            foreach (array_slice($counts, 0, self::TOP_N, true) as $value => $count) {
                $rows[] = [ $key => (string) $value, 'count' => $count ];
            }
            return $rows;
        };
        ksort($agg['status']);

        $paths = [];
        foreach ($agg['paths'] as $route => $count) {
            $paths[ self::mask_query((string) $route) ] = $count;
        }

        return [
            'requests'  => $agg['requests'],
            'top_ips'   => $top($agg['ips'], 'ip'),
            'top_paths' => $top($paths, 'path'),
            'status'    => $agg['status'],
            'php_posts' => $agg['php_posts'],
            'complete'  => $complete,
        ];
    }
}
