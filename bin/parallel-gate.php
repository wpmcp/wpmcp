<?php

/**
 * The parallel test gate: `bin/test-local.sh --parallel[=N]`.
 *
 * bin/test-local.sh builds the plan (the same legs a serial run would run,
 * from bin/legs.tsv) and hands it here in WPMCP_PARALLEL_LEGS. This script:
 *
 *   1. Makes a workspace for the run: an APFS RAM disk (hdiutil) unless
 *      WPMCP_PARALLEL_RAMDISK=0, holding a MariaDB server of its own (own
 *      port and socket, never the private MariaDB of serial runs nor any other
 *      server), each job's TMPDIR and the generated PHPUnit configurations.
 *      The server runs without a binary log or durability (nothing it holds
 *      outlives the run), max_connections sized for the workers.
 *   2. Splits the three suite legs (each the whole suite, about 10,000 tests)
 *      into shards of test files, balanced by the per-file times of earlier
 *      runs ($CACHE/timings, from PHPUnit's JUnit reports; test counts the
 *      first time). Each shard is a `bin/test-local.sh --leg` worker with its
 *      own database, core copy, config and tmp. Its database is copied from a
 *      template the WordPress test installer filled once per core and network
 *      mode, instead of running the installer in every shard.
 *   3. Runs every job, longest first, on N workers. PHP runs with no coverage
 *      extension unless the job collects coverage (pcov doubles a run's time
 *      even when it collects nothing), and with the opcache file cache.
 *   4. Merges the coverage shards and checks the floor, compares each leg's
 *      failing tests with its known-failure baseline
 *      (tests/phpunit-baseline/<leg>.txt, shrink only), prints one table, and
 *      exits non-zero when a leg failed: a new failing test, a job that ended
 *      badly with no failing test to show for it, lint, drift, or the floor.
 *
 * Logs: one per job, the JUnit reports, the merged coverage and summary.txt
 * under WPMCP_PARALLEL_LOG_ROOT (default $CACHE/parallel-logs), in
 * parallel-<timestamp>/; the newest WPMCP_PARALLEL_KEEP_LOGS (default 10)
 * are kept.
 *
 * Cleanup, also on failure, Ctrl-C and TERM: every worker's process group is
 * stopped (each worker then drops its database and core copy), the MariaDB
 * server is shut down, the RAM disk detached, core copies moved aside by
 * workers deleted. Nothing is left running.
 *
 * Environment (set by bin/test-local.sh unless noted):
 *   WPMCP_PARALLEL_LEGS, WPMCP_PARALLEL_WORKERS, WPMCP_PARALLEL_COVERAGE,
 *   WPMCP_COVERAGE_FLOOR, WPMCP_TEST_CACHE, WPMCP_MARIADB_BIN, WPMCP_PARALLEL_PHP
 *   WPMCP_PARALLEL_RAMDISK=0     workspace and database on disk instead
 *   WPMCP_PARALLEL_DB_TEMPLATES=0 run the WordPress installer in every job
 *   WPMCP_PARALLEL_OPCACHE=0     no opcache file cache
 *   WPMCP_PARALLEL_PHP_FAST=0    keep php.ini (and pcov) for every job
 *   WPMCP_PARALLEL_SHARDS=<k>    shards per suite leg (default: from the times)
 *   WPMCP_PARALLEL_REUSE_LOGS=<dir> run nothing: report on that run's logs
 *   WPMCP_PARALLEL_PRUNE_BASELINE=1 remove fixed entries from the baselines
 *   WPMCP_PARALLEL_FAIL_FAST=0   run the heavy legs even when the fast tier fails
 */

declare(strict_types=1);

// --------------------------------------------------------------- settings

$root    = dirname(__DIR__);
$cache   = (string) (getenv('WPMCP_TEST_CACHE') ?: getenv('HOME') . '/.cache/wpmcp-tests');
$legs    = array_values(array_filter(array_map('trim', explode("\n", (string) getenv('WPMCP_PARALLEL_LEGS')))));
$workers = max(1, (int) (getenv('WPMCP_PARALLEL_WORKERS') ?: 1));
$want_coverage = getenv('WPMCP_PARALLEL_COVERAGE') === 'true';
$floor   = (string) (getenv('WPMCP_COVERAGE_FLOOR') ?: '0');
$bin     = (string) getenv('WPMCP_MARIADB_BIN');
$php     = (string) (getenv('WPMCP_PARALLEL_PHP') ?: 'php');
$flag    = static fn (string $name, bool $default): bool => getenv($name) === false || getenv($name) === '' ? $default : getenv($name) !== '0';
$use_ramdisk   = $flag('WPMCP_PARALLEL_RAMDISK', true);
$use_templates = $flag('WPMCP_PARALLEL_DB_TEMPLATES', true);
// The opcache file cache was measured to cost more than it saves: every job
// has a core copy of its own, so its compiled files are keyed on paths that
// never come back, and wiping the 1 GB of them took 40 s at the start of a
// run. Opcache stays on in memory; WPMCP_PARALLEL_OPCACHE=1 adds the file cache.
$use_opcache   = $flag('WPMCP_PARALLEL_OPCACHE', false);
$php_fast      = $flag('WPMCP_PARALLEL_PHP_FAST', true);
$fail_fast     = $flag('WPMCP_PARALLEL_FAIL_FAST', true);
$prune_baseline = $flag('WPMCP_PARALLEL_PRUNE_BASELINE', false);
$seed_baseline  = $flag('WPMCP_PARALLEL_SEED_BASELINE', false);
$fixed_shards  = (int) (getenv('WPMCP_PARALLEL_SHARDS') ?: 0);
$reuse_logs    = (string) (getenv('WPMCP_PARALLEL_REUSE_LOGS') ?: '');
$log_root      = (string) (getenv('WPMCP_PARALLEL_LOG_ROOT') ?: $cache . '/parallel-logs');
$keep_logs     = max(1, (int) (getenv('WPMCP_PARALLEL_KEEP_LOGS') ?: 10));
$run_id        = getmypid();
$t0            = microtime(true);

function say(string $message): void
{
    fwrite(STDOUT, "\033[1m[parallel]\033[0m {$message}\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "\033[31m[parallel]\033[0m {$message}\n");
    exit(1);
}

/** Runs a shell command, returns [exit code, output]. */
function sh(string $command): array
{
    exec($command . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

function fmt_secs(float $s): string
{
    return $s >= 60 ? sprintf('%dm%04.1fs', (int) ($s / 60), fmod($s, 60)) : sprintf('%.1fs', $s);
}

// --------------------------------------------------------------- leg table

/** @return array<string, array<string, string>> rows of bin/legs.tsv by kind. */
function leg_table(string $root): array
{
    $rows = [];
    foreach (file($root . '/bin/legs.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $c = explode("\t", $line);
        $rows[$c[1]] ??= ['id' => $c[0], 'kind' => $c[1], 'tier' => $c[2], 'groups' => $c[5], 'tests' => $c[7]];
    }
    return $rows;
}

/** The key a leg's baseline and timings are filed under: "phpunit-7.1", "live-forms". */
function leg_key(string $spec): string
{
    $p = preg_split('/\s+/', $spec);
    return in_array($p[0], ['phpunit', 'multisite'], true) ? $p[0] . '-' . $p[1] : $p[0];
}

/** Every test file of the suite, in the order PHPUnit runs them serially. */
function suite_files(string $root): array
{
    $files = [];
    foreach (['tests/free', 'tests/pro'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        $here = [];
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), 'Test.php')) {
                $here[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        sort($here);
        array_push($files, ...$here);
    }
    return $files;
}

// --------------------------------------------------------------- timings

function timings_file(string $cache, string $key): string
{
    return $cache . '/timings/' . preg_replace('/[^a-z0-9.-]/i', '_', $key) . '.json';
}

function load_timings(string $cache, string $key): array
{
    $f = timings_file($cache, $key);
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function save_timings(string $cache, string $key, array $new, bool $replace = false): void
{
    if ($new === []) {
        return;
    }
    @mkdir($cache . '/timings', 0777, true);
    $old = load_timings($cache, $key);
    foreach ($new as $k => $v) {
        if (is_string($v)) {
            $old[$k] = $v;
            continue;
        }
        // A moving average, so one slow run does not reshape every shard.
        $old[$k] = ! $replace && isset($old[$k]) && is_numeric($old[$k]) ? round(0.5 * $old[$k] + 0.5 * $v, 3) : round($v, 3);
    }
    $tmp = timings_file($cache, $key) . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, timings_file($cache, $key));
}

/** Seconds a file is expected to take: measured, else by its test count. */
function file_weight(string $root, string $file, array $times, float $per_test): float
{
    if (isset($times[$file])) {
        return max(0.05, (float) $times[$file]);
    }
    $src = (string) @file_get_contents($root . '/' . $file);
    $n   = max(1, preg_match_all('/function\s+test\w*\s*\(/', $src) + preg_match_all('/@test\b/', $src));
    return $n * $per_test;
}

/**
 * Splits <files> into <k> shards of about equal weight, each a contiguous run
 * of the serial order: every test keeps the neighbours it has in a serial
 * run, except at the first file of a shard, so a test that only passes after
 * another (state left behind) behaves as it does serially. Interleaving by
 * weight balances a little better but turned four such tests red, and which
 * ones moved with every new set of timings.
 * @return array<int, array{files: string[], weight: float}>
 */
function split_files(array $files, array $weights, int $k): array
{
    $left = array_sum(array_map(static fn ($f) => $weights[$f], $files));
    $out  = [];
    $cur  = ['files' => [], 'weight' => 0.0];
    $n    = count($files);
    foreach ($files as $i => $f) {
        $cur['files'][] = $f;
        $cur['weight'] += $weights[$f];
        $parts_left = $k - count($out);
        // Close the shard once it holds its share of what is left, as long as
        // enough files remain for the shards still to come.
        if ($parts_left > 1 && $cur['weight'] >= $left / $parts_left && $n - $i - 1 >= $parts_left - 1) {
            $left -= $cur['weight'];
            $out[] = $cur;
            $cur   = ['files' => [], 'weight' => 0.0];
        }
    }
    if ($cur['files'] !== []) {
        $out[] = $cur;
    }
    return $out;
}

/** Writes a PHPUnit configuration running only <files> (absolute paths). */
/** Sorts like the serial run: tests/free before tests/pro, then by path. */
function serial_key(string $file): string
{
    return (str_starts_with($file, 'tests/free/') ? '0' : '1') . $file;
}

/**
 * Plans one suite leg: files heavier than most of a shard are split by
 * method (balanced by measured time), the rest cut into contiguous shards of
 * about <target> seconds.
 * @return array{bounds: string[], splits: array<string, string[][]>}
 */
function plan_leg(array $files, array $w, array $times, float $target, float $bootstrap): array
{
    $by_file = [];
    foreach ($times as $k => $t) {
        if (is_numeric($t) && ($at = strpos($k, '::')) !== false) {
            $by_file[substr($k, 0, $at)][substr($k, $at + 2)] = (float) $t;
        }
    }
    $splits = [];
    $rest   = [];
    foreach ($files as $f) {
        $methods = $by_file[$f] ?? [];
        $parts   = (int) min(8, ceil($w[$f] / ($target * 0.6)));
        if ($parts < 2 || count($methods) < 2) {
            $rest[] = $f;
            continue;
        }
        arsort($methods);
        $groups = array_fill(0, min($parts, count($methods)), ['m' => [], 'w' => 0.0]);
        foreach ($methods as $m => $t) {
            $g = 0;
            foreach ($groups as $i => $grp) {
                if ($grp['w'] < $groups[$g]['w']) {
                    $g = $i;
                }
            }
            $groups[$g]['m'][] = $m;
            $groups[$g]['w']  += $t;
        }
        $splits[$f] = array_map(static fn ($g) => $g['m'], $groups);
    }
    $rest_w = array_sum(array_map(static fn ($f) => $w[$f], $rest));
    $k      = (int) max(1, min(40, ceil($rest_w / max(1.0, $target - $bootstrap))));
    $bounds = [];
    foreach (array_slice(split_files($rest, array_intersect_key($w, array_flip($rest)), $k), 1) as $shard) {
        $bounds[] = $shard['files'][0];
    }
    return ['bounds' => $bounds, 'splits' => $splits];
}

/** @return array<string, array{bounds: string[], splits: array<string, string[][]>}> */
function read_plan(string $file): array
{
    $plan = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $c = explode("\t", $line);
        if ($c[0] === 'shard' && count($c) >= 3) {
            $plan[$c[1]]['bounds'][] = $c[2];
            $plan[$c[1]]['splits'] ??= [];
        } elseif ($c[0] === 'split' && count($c) >= 4) {
            $plan[$c[1]]['splits'][$c[2]] = array_map(static fn ($g) => explode(',', $g), explode('|', $c[3]));
            $plan[$c[1]]['bounds'] ??= [];
        } elseif ($c[0] === 'leg' && count($c) >= 2) {
            $plan[$c[1]] ??= ['bounds' => [], 'splits' => []];
        }
    }
    return $plan;
}

function write_plan(string $file, array $plan): void
{
    $out = <<<TXT
# The shard layout of the parallel gate (bin/test-local.sh --parallel), per
# suite leg. Committed, so that the layout, and with it which tests share a
# PHPUnit process, only changes on purpose: several tests here depend on what
# ran before them, and a layout that moved with every run's timings would
# turn them red and green at random.
#
#   leg    <leg>                         the leg has a plan
#   shard  <leg>  <first file>           a shard starts at this file; shards are
#                                        contiguous runs of the serial order
#   split  <leg>  <file>  <m,m|m,m>      this file runs as jobs of its own, one per
#                                        group of methods; the first group also
#                                        takes any method not named
#
# Regenerate from the latest timings with
#   WPMCP_PARALLEL_REBALANCE=1 bin/test-local.sh --parallel
# and check the known-failure baselines (tests/phpunit-baseline-*.txt) after:
# a new layout can move a test that depends on its neighbours.

TXT;
    ksort($plan);
    foreach ($plan as $leg => $p) {
        $out .= "leg\t{$leg}\n";
        foreach ($p['bounds'] as $b) {
            $out .= "shard\t{$leg}\t{$b}\n";
        }
        foreach ($p['splits'] as $f => $groups) {
            $out .= "split\t{$leg}\t{$f}\t" . implode('|', array_map(static fn ($g) => implode(',', $g), $groups)) . "\n";
        }
    }
    file_put_contents($file, $out);
}

/** The test class a test file declares, with its namespace, or null. */
function file_class(string $root, string $file): ?string
{
    $src = (string) @file_get_contents($root . '/' . $file);
    if (! preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $src, $c)) {
        return null;
    }
    return (preg_match('/^namespace\s+([^;]+);/m', $src, $n) ? trim($n[1]) . '\\' : '') . $c[1];
}

/**
 * A PHPUnit --filter running only the tests of <classes>. A worker loads
 * every test file, as a serial run does (some tests only pass because another
 * file declared a class or function), and runs only these.
 */
function class_filter(array $classes): string
{
    return '/^(?:' . implode('|', array_map(static fn ($c) => preg_quote($c, '/'), $classes)) . ')::/';
}

/**
 * Writes the PHPUnit configuration of a job: phpunit.xml.dist with absolute
 * paths and no result cache (workers would race on it), and, given <files>,
 * only those files.
 */
function write_config(string $root, string $path, ?array $files): void
{
    $xml = (string) file_get_contents($root . '/phpunit.xml.dist');
    $xml = (string) preg_replace('#<directory>(tests/[^<]+)</directory>#', '<directory>' . $root . '/$1</directory>', $xml);
    if ($files === null) {
        $files = [];
        $keep_suites = true;
    }
    $list = '';
    foreach ($files as $f) {
        $list .= '      <file>' . htmlspecialchars($root . '/' . $f, ENT_XML1) . "</file>\n";
    }
    if (empty($keep_suites)) {
        $xml = (string) preg_replace('#<testsuites>.*</testsuites>#s', "<testsuites>\n    <testsuite name=\"shard\">\n{$list}    </testsuite>\n  </testsuites>", $xml, 1);
    }
    $xml = str_replace('bootstrap="tests/bootstrap.php"', 'bootstrap="' . $root . '/tests/bootstrap.php" cacheResult="false"', $xml);
    $xml = str_replace('<directory suffix=".php">src</directory>', '<directory suffix=".php">' . $root . '/src</directory>', $xml);
    file_put_contents($path, $xml);
}

// --------------------------------------------------------------- JUnit

/**
 * Reads the JUnit reports in <dir>.
 * @return array{tests:int, failures:int, errors:int, skipped:int, failing:string[], files:array<string,float>, classes:array<string,float>}
 */
function read_junit(string $dir, string $root): array
{
    $r = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'failing' => [], 'files' => [], 'classes' => [], 'methods' => [], 'class_of' => []];
    foreach (glob($dir . '/run-*.xml') ?: [] as $f) {
        $xml = @simplexml_load_file($f);
        if ($xml === false) {
            continue;
        }
        foreach ($xml->xpath('//testcase') ?: [] as $tc) {
            $r['tests']++;
            $class = (string) $tc['class'];
            $name  = (string) preg_replace('/ with data set .*$/s', '', (string) $tc['name']);
            $file  = (string) $tc['file'];
            $time  = (float) $tc['time'];
            if (str_starts_with($file, $root . '/')) {
                $file = substr($file, strlen($root) + 1);
            }
            if ($file !== '') {
                $r['files'][$file] = ($r['files'][$file] ?? 0) + $time;
                $r['methods'][$file . '::' . $name] = ($r['methods'][$file . '::' . $name] ?? 0) + $time;
                $r['class_of'][$file] = $class;
            }
            $r['classes'][$class] = ($r['classes'][$class] ?? 0) + $time;
            // PHPUnit 9 logs a risky test as an error of type RiskyTestError;
            // a serial run passes with risky tests, so they do not fail here.
            $risky = isset($tc->error) && ! isset($tc->failure) && str_contains((string) $tc->error['type'], 'Risky');
            if ($risky) {
                $r['risky'] = ($r['risky'] ?? 0) + 1;
            } elseif (isset($tc->failure) || isset($tc->error)) {
                isset($tc->failure) ? $r['failures']++ : $r['errors']++;
                $r['failing'][] = $class . '::' . $name;
            } elseif (isset($tc->skipped)) {
                $r['skipped']++;
            }
        }
    }
    $r['failing'] = array_values(array_unique($r['failing']));
    sort($r['failing']);
    return $r;
}

// --------------------------------------------------------------- coverage

/** Merges clover reports: a statement is covered when any shard covered it. */
function merge_clover(array $files, string $out): array
{
    $lines = [];
    foreach ($files as $f) {
        $xml = @simplexml_load_file($f);
        if ($xml === false) {
            continue;
        }
        foreach ($xml->xpath('//file') ?: [] as $file) {
            $name = (string) $file['name'];
            foreach ($file->line as $line) {
                if ((string) $line['type'] !== 'stmt') {
                    continue;
                }
                $n = (int) $line['num'];
                $lines[$name][$n] = max($lines[$name][$n] ?? 0, (int) $line['count']);
            }
        }
    }
    $statements = 0;
    $covered    = 0;
    $body       = '';
    foreach ($lines as $name => $nums) {
        ksort($nums);
        $fs = count($nums);
        $fc = count(array_filter($nums));
        $statements += $fs;
        $covered    += $fc;
        $body .= '    <file name="' . htmlspecialchars($name, ENT_XML1) . "\">\n";
        foreach ($nums as $n => $c) {
            $body .= "      <line num=\"{$n}\" type=\"stmt\" count=\"{$c}\"/>\n";
        }
        $body .= "      <metrics statements=\"{$fs}\" coveredstatements=\"{$fc}\"/>\n    </file>\n";
    }
    $ts = time();
    file_put_contents($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"{$ts}\">\n  <project timestamp=\"{$ts}\">\n{$body}    <metrics files=\"" . count($lines) . "\" statements=\"{$statements}\" coveredstatements=\"{$covered}\"/>\n  </project>\n</coverage>\n");
    return [$statements, $covered];
}

// --------------------------------------------------------------- baselines

function baseline_file(string $root, string $key): string
{
    return $root . '/tests/phpunit-baseline-' . $key . '.txt';
}

/** @return string[] */
function read_baseline(string $root, string $key): array
{
    $f = baseline_file($root, $key);
    if (! is_file($f)) {
        return [];
    }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $out[] = $line;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Writes the first baseline of a leg that has none: its failing tests now.
 * Never touches an existing baseline (those only shrink, by pruning).
 */
function seed_baseline_file(string $root, string $key, array $failing, string $source): bool
{
    $f = baseline_file($root, $key);
    if (is_file($f) || $failing === []) {
        return false;
    }
    $header = <<<TXT
# Known failing tests of the {$key} leg, one Class::test per line (data sets
# stripped), sorted. The parallel gate (bin/test-local.sh --parallel) fails
# only on a failing test that is not listed here, and reports listed tests
# that pass again.
#
# POLICY: this list may only SHRINK. Never add a line: fix the test (or the
# code) instead. Drop the lines of fixed tests with
# `bin/test-local.sh --parallel --prune-baseline`; it only ever removes.
#
# Seeded from {$source}.

TXT;
    file_put_contents($f, $header . implode("\n", $failing) . "\n");
    return true;
}

/** Removes <fixed> from a baseline, keeping its header. Never adds a line. */
function prune_baseline_file(string $root, string $key, array $fixed): void
{
    $f = baseline_file($root, $key);
    if (! is_file($f) || $fixed === []) {
        return;
    }
    $keep = array_filter(file($f, FILE_IGNORE_NEW_LINES) ?: [], static fn ($l) => ! in_array(trim($l), $fixed, true));
    file_put_contents($f, implode("\n", $keep) . "\n");
}

// --------------------------------------------------------------- cleanup

$state = [
    'ramdisk' => null,     // /dev/diskN
    'mount' => null,
    'workspace' => null,
    'db_pid' => null,
    'db_sock' => null,
    'trash' => null,
    'running' => [],       // job id => [proc, pgid]
    'cleaned' => false,
    'sampler' => null,
];

function stop_jobs(array &$state): void
{
    foreach ($state['running'] as $id => $r) {
        @posix_kill(-$r['pgid'], SIGTERM);
    }
    $deadline = microtime(true) + 60;
    while ($state['running'] !== [] && microtime(true) < $deadline) {
        foreach ($state['running'] as $id => $r) {
            $s = proc_get_status($r['proc']);
            if (! $s['running']) {
                proc_close($r['proc']);
                unset($state['running'][$id]);
            }
        }
        usleep(200000);
    }
    foreach ($state['running'] as $id => $r) {
        @posix_kill(-$r['pgid'], SIGKILL);
        @proc_close($r['proc']);
        unset($state['running'][$id]);
    }
}

function cleanup(array &$state, string $bin): void
{
    if ($state['cleaned']) {
        return;
    }
    $state['cleaned'] = true;
    stop_jobs($state);
    if ($state['sampler'] !== null) {
        @proc_terminate($state['sampler']);
        @proc_close($state['sampler']);
    }
    if ($state['db_sock'] !== null && is_file($state['workspace'] . '/db/mariadbd.pid')) {
        sh(escapeshellarg($bin . '/mariadb-admin') . ' --no-defaults --socket=' . escapeshellarg($state['db_sock']) . ' -uroot shutdown');
        $pid = (int) @file_get_contents($state['workspace'] . '/db/mariadbd.pid');
        for ($i = 0; $i < 100 && $pid > 0 && @posix_kill($pid, 0); $i++) {
            usleep(100000);
        }
        if ($pid > 0 && @posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }
    foreach ($state['reapers'] ?? [] as $p) {
        proc_close($p);
    }
    if ($state['trash'] !== null && is_dir($state['trash'])) {
        sh('rm -rf ' . escapeshellarg($state['trash']));
    }
    if ($state['ramdisk'] !== null) {
        for ($i = 0; $i < 5; $i++) {
            [$rc] = sh('hdiutil detach -force ' . escapeshellarg($state['ramdisk']));
            if ($rc === 0) {
                break;
            }
            sleep(1);
        }
    } elseif ($state['workspace'] !== null && is_dir($state['workspace'])) {
        sh('rm -rf ' . escapeshellarg($state['workspace']));
    }
}

register_shutdown_function(static function () use (&$state, $bin): void {
    cleanup($state, $bin);
});
pcntl_async_signals(true);
$interrupted = 0;
foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
    pcntl_signal($sig, static function (int $sig) use (&$interrupted): void {
        $interrupted = $sig;
    });
}

// --------------------------------------------------------------- jobs

$table = leg_table($root);
$jobs  = [];
$report_only_early = $reuse_logs !== '';
// Local time, as the rest of the gate's logs.
$log_dir = $reuse_logs !== '' ? $reuse_logs : $log_root . '/parallel-' . trim((string) shell_exec('date +%Y%m%d-%H%M%S'));

/** Adds a job: a `bin/test-local.sh --leg` worker, or a plain command. */
$add_job = static function (array $job) use (&$jobs): void {
    // leg: the summary line the job counts towards; spec: the leg its
    // `bin/test-local.sh --leg` worker runs (the same, but for the canary).
    $job += ['whole' => false, 'files' => null, 'config' => null, 'coverage' => false, 'cmd' => null, 'env' => [], 'shard' => false, 'filter' => null, 'failfast' => false];
    $job['spec'] ??= $job['leg'];
    $jobs[$job['id']] = $job;
};

$suite_legs = [];
$has_suite  = (bool) array_filter($legs, static fn ($l) => preg_match('/^(phpunit|multisite) /', $l));
$job_times  = load_timings($cache, 'jobs');
if ($has_suite && ! $report_only_early) {
    // The fast tier (phpunit-fast.xml.dist): classes that need no WordPress,
    // one process per class under paratest. It starts first; when it fails
    // and WPMCP_PARALLEL_FAIL_FAST is not 0, every other job is stopped.
    if (is_file($root . '/phpunit-fast.xml.dist') && is_file($root . '/vendor/bin/paratest')) {
        array_unshift($legs, 'fast');
        $add_job(['id' => 'fast', 'leg' => 'fast', 'est' => 1e6, 'cmd' => 'fast', 'failfast' => true]);
        // The slower classes that need no WordPress either (gate, build and
        // compliance checks): once per run here, instead of once per suite
        // leg, where they gave the same result three times.
        $add_job(['id' => 'fast-heavy', 'leg' => 'fast', 'est' => (float) ($job_times['fast-heavy'] ?? 60), 'cmd' => 'fast-heavy']);
    }
    // The canary (tests/canary-classes.txt): classes known to depend on what
    // ran before them, run in one process in their serial order on the
    // default WordPress, so a leak between them shows even though the shards
    // split them up.
    $canary = is_file($root . '/tests/canary-classes.txt') ? array_values(array_filter(array_map('trim', file($root . '/tests/canary-classes.txt')), static fn ($l) => $l !== '' && $l[0] !== '#')) : [];
    $default_suite = current(array_filter($legs, static fn ($l) => str_starts_with($l, 'phpunit ')));
    if ($canary !== [] && $default_suite !== false) {
        $legs[] = 'canary';
        $add_job(['id' => 'canary', 'leg' => 'canary', 'spec' => preg_replace('/ floor$/', ' none', $default_suite), 'est' => (float) ($job_times['canary'] ?? 30), 'whole' => true,
            'filter' => class_filter(array_values(array_filter(array_map(static fn ($f) => file_class($root, $f), $canary))))]);
    }
}
foreach ($legs as $spec) {
    if ($spec === 'fast' || $spec === 'canary') {
        continue;
    }
    $p = preg_split('/\s+/', $spec);
    $kind = $p[0];
    if ($kind === 'lint') {
        $add_job(['id' => 'lint-psr12', 'leg' => $spec, 'est' => 30, 'cmd' => 'psr12']);
        $add_job(['id' => 'lint-wpcs', 'leg' => $spec, 'est' => 30, 'cmd' => 'wpcs']);
    } elseif ($kind === 'drift') {
        $add_job(['id' => 'drift', 'leg' => $spec, 'est' => 3, 'cmd' => 'drift']);
    } elseif ($kind === 'phpunit' || $kind === 'multisite') {
        $suite_legs[] = $spec;
    } else {
        $tests = $table[$kind]['tests'] ?? '-';
        $add_job([
            'id' => $kind,
            'leg' => $spec,
            'est' => (float) ($job_times[$kind] ?? 20),
            // Every test file loaded, as serially: the group picks the tests.
            'whole' => true,
        ]);
    }
}

// Shards. Their layout comes from tests/parallel-shards.tsv, committed, so
// it does not move with each run's timings or with --parallel=N: many tests
// here pass or fail depending on what ran before them in the same process,
// and a stable layout keeps that from turning the gate red at random (and
// keeps each leg's baseline meaningful). Each shard is a contiguous run of
// the serial order, so tests keep their serial neighbours; a file too heavy
// for one shard is split by method into jobs of its own. A leg missing from
// the file, or WPMCP_PARALLEL_REBALANCE=1, plans that leg from the timings
// of earlier runs and writes it back.
$per_test       = 0.04;
$files          = suite_files($root);
// What the fast tier runs (no WordPress, the same result on every leg) is not
// run again in each suite leg: WPMCP_PARALLEL_FAST_ONCE=0 keeps it there too.
$fast_files = [];
if ($has_suite && $flag('WPMCP_PARALLEL_FAST_ONCE', true) && isset($jobs['fast'])) {
    preg_match_all('#<file>([^<]+)</file>#', (string) @file_get_contents($root . '/phpunit-fast.xml.dist'), $m);
    $fast_files = array_flip($m[1]);
}
// The coverage leg keeps them: the floor is measured over the whole suite.
$all_files = $files;
$bootstrap_secs = 6.0;
$plan_file      = $root . '/tests/parallel-shards.tsv';
$rebalance      = $flag('WPMCP_PARALLEL_REBALANCE', false);
$plan           = read_plan($plan_file);
$plan_changed   = false;
// Plans are sized for a 12 core machine whatever N is, so N never moves them.
$plan_workers = 12;
$total = 0.0;
foreach ($jobs as $j) {
    $total += $j['cmd'] === 'fast' ? 10 : min(60, $j['est']);
}
$weights = [];
$leg_files = [];
foreach ($suite_legs as $spec) {
    $is_cov = (preg_split('/\s+/', $spec)[2] ?? '') === 'floor' && $want_coverage;
    $leg_files[$spec] = $is_cov ? $all_files : array_values(array_filter($all_files, static fn ($f) => ! isset($fast_files[$f])));
}
foreach ($suite_legs as $spec) {
    $files = $leg_files[$spec];
    $times = load_timings($cache, leg_key($spec));
    foreach ($files as $f) {
        $weights[$spec][$f] = file_weight($root, $f, $times, $per_test);
    }
    $total += array_sum($weights[$spec]);
}
$target = max(20.0, $total / ($plan_workers * 2.5));
foreach ($suite_legs as $spec) {
    $files = $leg_files[$spec];
    $key   = leg_key($spec);
    $times = load_timings($cache, $key);
    $w     = $weights[$spec];
    $p     = preg_split('/\s+/', $spec);
    $cov   = ($p[2] ?? '') === 'floor' && $want_coverage;
    if ($rebalance || ! isset($plan[$key])) {
        $plan[$key] = plan_leg($files, $w, $times, $target, $bootstrap_secs);
        $plan_changed = true;
    }
    $legplan = $plan[$key];
    // Method splits: the first part takes every method the others do not
    // name, so a new test still runs.
    $quote = static fn (array $ms) => implode('|', array_map(static fn ($m) => preg_quote($m, '/'), $ms));
    foreach ($legplan['splits'] as $f => $groups) {
        $class = $times['class:' . $f] ?? file_class($root, $f);
        if ($class === null || ! in_array($f, $files, true)) {
            continue;
        }
        $named = array_merge(...array_slice($groups, 1));
        $fq    = preg_quote($class, '/');
        foreach ($groups as $i => $ms) {
            $est = 0.0;
            foreach ($ms as $m) {
                $est += (float) ($times[$f . '::' . $m] ?? 1);
            }
            $add_job([
                'id' => $key . '-' . preg_replace('/Test$/', '', basename($f, '.php')) . '-' . ($i + 1),
                'leg' => $spec,
                'est' => ($i === 0 ? max($est, $w[$f] - array_sum(array_map(static fn ($m) => (float) ($times[$f . '::' . $m] ?? 1), $named))) : $est) + $bootstrap_secs,
                'whole' => true,
                'filter' => $i === 0 ? '/^' . $fq . '::(?!(?:' . $quote($named) . ')(?:\s|$))/' : '/^' . $fq . '::(?:' . $quote($ms) . ')(?:\s|$)/',
                'coverage' => $cov,
                'shard' => true,
            ]);
        }
    }
    // Contiguous shards between the boundaries; a file the plan does not
    // know goes with the files around it.
    $order  = array_flip($files);
    $bounds = array_values(array_filter($legplan['bounds'], static fn ($b) => true));
    $shards = array_fill(0, count($bounds) + 1, []);
    foreach ($files as $f) {
        if (isset($legplan['splits'][$f])) {
            continue;
        }
        $i = 0;
        foreach ($bounds as $bi => $b) {
            if (serial_key($f) >= serial_key($b)) {
                $i = $bi + 1;
            }
        }
        $shards[$i][] = $f;
    }
    foreach (array_values(array_filter($shards)) as $i => $shard) {
        $add_job([
            'id' => $key . '-s' . ($i + 1),
            'leg' => $spec,
            'est' => array_sum(array_map(static fn ($f) => $w[$f], $shard)) + $bootstrap_secs,
            'whole' => true,
            'filter' => class_filter(array_values(array_filter(array_map(static fn ($f) => $times['class:' . $f] ?? file_class($root, $f), $shard)))),
            'coverage' => $cov,
            'shard' => true,
        ]);
    }
}
if ($plan_changed && ! $report_only_early) {
    write_plan($plan_file, $plan);
    say('Shard layout written to tests/parallel-shards.tsv (commit it; see its header).');
}

// --------------------------------------------------------------- workspace

$report_only = $reuse_logs !== '';
if (! $report_only) {
    @mkdir($log_dir, 0777, true);
    // Keep the newest log directories within the budget.
    $dirs = glob($log_root . '/parallel-*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    foreach (array_slice($dirs, 0, max(0, count($dirs) - $keep_logs)) as $old) {
        // Never the logs of a run still going, nor of one from the last hour.
        $owner = (int) @file_get_contents($old . '/.pid');
        if (($owner > 0 && @posix_kill($owner, 0)) || filemtime($old) > time() - 3600) {
            continue;
        }
        sh('rm -rf ' . escapeshellarg($old));
    }
    file_put_contents($log_dir . '/.pid', (string) getmypid());

    if ($use_ramdisk) {
        $mb = 4096;
        [$rc, $dev] = sh('hdiutil attach -nobrowse -nomount ram://' . ($mb * 2048));
        $dev = trim($dev);
        if ($rc !== 0 || ! str_starts_with($dev, '/dev/disk')) {
            fail("could not make a RAM disk: {$dev}");
        }
        $state['ramdisk'] = $dev;
        $vol = 'wpmcp-gate-' . $run_id;
        [$rc, $o] = sh('diskutil erasevolume APFS ' . escapeshellarg($vol) . ' ' . escapeshellarg($dev));
        if ($rc !== 0 || ! is_dir('/Volumes/' . $vol)) {
            fail("could not format the RAM disk: {$o}");
        }
        $state['mount'] = '/Volumes/' . $vol;
        // Spotlight would index every file the run writes there.
        touch('/Volumes/' . $vol . '/.metadata_never_index');
        $ws = $state['mount'];
    } else {
        $ws = $cache . '/parallel-' . $run_id;
        @mkdir($ws, 0777, true);
    }
    $state['workspace'] = $ws;
    foreach (['db', 'tmp', 'configs', 'junit', 'coverage'] as $d) {
        @mkdir($ws . '/' . $d, 0777, true);
    }
    $state['trash'] = $cache . '/cores/.trash-' . $run_id;
    @mkdir($state['trash'], 0777, true);
    // Spotlight indexed every core copy and log of a run (2 to 3 cores of
    // mds_stores). An empty .metadata_never_index asks it to stay out.
    foreach ([$cache, $cache . '/cores', $state['trash'], $log_dir, $log_root, $ws] as $d) {
        if (is_dir($d) && ! is_file($d . '/.metadata_never_index')) {
            @touch($d . '/.metadata_never_index');
        }
    }

    // ---- the run's own MariaDB
    $t_db = microtime(true);
    $port = 0;
    for ($p = 3400 + ($run_id % 500); $p < 3999; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:{$p}");
        if ($s !== false) {
            fclose($s);
            $port = $p;
            break;
        }
    }
    $port > 0 || fail('no free port for the run\'s MariaDB');
    $sock = $ws . '/db/m.sock';
    strlen($sock) < 100 || fail("socket path too long: {$sock}");
    $state['db_sock'] = $sock;
    $base = dirname($bin);
    // An initialised data directory is kept in the cache and copied, which is
    // faster than mariadb-install-db on every run.
    $seed = $cache . '/parallel-datadir-seed';
    if (! is_dir($seed . '/mysql')) {
        sh('rm -rf ' . escapeshellarg($seed));
        [$rc, $o] = sh(escapeshellarg($bin . '/mariadb-install-db') . ' --no-defaults --basedir=' . escapeshellarg($base) . ' --datadir=' . escapeshellarg($seed) . ' --auth-root-authentication-method=normal --skip-test-db');
        $rc === 0 || fail("mariadb-install-db failed: {$o}");
    }
    sh('cp -R ' . escapeshellarg($seed) . ' ' . escapeshellarg($ws . '/db/data'));
    // Some tests leave connections open until their process ends (one shard
    // once held 146 idle ones), so the cap is generous: an idle connection
    // costs the server little, a refused one fails a whole shard.
    $max_conn = max(2000, $workers * 200);
    $args = [
        '--no-defaults', '--basedir=' . $base, '--datadir=' . $ws . '/db/data',
        '--port=' . $port, '--bind-address=127.0.0.1', '--socket=' . $sock,
        '--pid-file=' . $ws . '/db/mariadbd.pid', '--log-error=' . $ws . '/db/error.log',
        '--tmpdir=' . $ws . '/tmp', '--skip-log-bin', '--sync-binlog=0',
        '--innodb-flush-log-at-trx-commit=0', '--innodb-doublewrite=0',
        '--innodb-flush-method=nosync', '--performance-schema=OFF',
        '--max-connections=' . $max_conn, '--innodb-buffer-pool-size=1G',
        '--innodb-log-file-size=256M', '--skip-name-resolve',
        '--table-open-cache=8000', '--max-allowed-packet=256M',
        // One tablespace instead of a file per table: each shard's plugins
        // create and alter some hundred tables at bootstrap, and a file per
        // table made that 31 s slower over a whole gate (bin/bench-parallel-gate.sh).
        '--innodb-file-per-table=0',
    ];
    // WPMCP_PARALLEL_DB_ARGS: more mariadbd options, one per line, to measure.
    foreach (array_filter(explode("\n", (string) getenv('WPMCP_PARALLEL_DB_ARGS'))) as $a) {
        $args[] = trim($a);
    }
    $cmd = 'nohup ' . escapeshellarg($bin . '/mariadbd');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd . ' >/dev/null 2>&1 &');
    $admin = escapeshellarg($bin . '/mariadb-admin') . ' --no-defaults --socket=' . escapeshellarg($sock) . ' -uroot';
    $up = false;
    for ($i = 0; $i < 150; $i++) {
        [$rc] = sh($admin . ' ping');
        if ($rc === 0) {
            $up = true;
            break;
        }
        usleep(100000);
    }
    $up || fail('the run\'s MariaDB did not start, see ' . $ws . '/db/error.log: ' . @file_get_contents($ws . '/db/error.log'));
    $state['db_pid'] = (int) @file_get_contents($ws . '/db/mariadbd.pid');
    $client = escapeshellarg($bin . '/mariadb') . ' --no-defaults --socket=' . escapeshellarg($sock) . ' -uroot';
    sh($client . ' -e ' . escapeshellarg("CREATE USER IF NOT EXISTS 'wpmcp'@'127.0.0.1' IDENTIFIED BY 'wpmcp'; CREATE USER IF NOT EXISTS 'wpmcp'@'localhost' IDENTIFIED BY 'wpmcp'; GRANT ALL ON *.* TO 'wpmcp'@'127.0.0.1'; GRANT ALL ON *.* TO 'wpmcp'@'localhost';"));
    say(sprintf('MariaDB for this run on 127.0.0.1:%d (%s, max_connections %d), up in %s.', $port, $use_ramdisk ? 'RAM disk' : 'disk', $max_conn, fmt_secs(microtime(true) - $t_db)));

    // ---- PHP for the jobs
    $opcache_dir = $cache . '/opcache';
    if ($use_opcache) {
        @mkdir($opcache_dir, 0777, true);
        // The file cache has a budget too: wiped above 1 GB.
        [, $kb] = sh('du -sk ' . escapeshellarg($opcache_dir) . " | cut -f1");
        if ((int) $kb > 1048576) {
            sh('rm -rf ' . escapeshellarg($opcache_dir));
            @mkdir($opcache_dir, 0777, true);
        }
    } elseif (is_dir($opcache_dir)) {
        // Left by earlier runs: deleted in the background with the trash.
        @rename($opcache_dir, $state['trash'] . '/opcache-' . $run_id);
    }
    $ini = $use_opcache
        ? ' -d opcache.enable_cli=1 -d opcache.file_cache=' . escapeshellarg($opcache_dir) . ' -d opcache.validate_timestamps=1 -d opcache.memory_consumption=512 -d opcache.max_accelerated_files=100000'
        : ' -d opcache.enable_cli=1 -d opcache.memory_consumption=512 -d opcache.max_accelerated_files=100000';
    $ini .= ' -d realpath_cache_size=16M -d realpath_cache_ttl=3600';
    $wrap = static function (string $path, string $flags) use ($php): void {
        file_put_contents($path, "#!/bin/sh\nexec " . escapeshellarg($php) . $flags . ' "$@"' . "\n");
        chmod($path, 0755);
    };
    // -n: no php.ini, so no pcov; this php.ini loads nothing else.
    $wrap($ws . '/php-fast', ($php_fast ? ' -n' : '') . $ini);
    $wrap($ws . '/php-cov', $ini);
}

// --------------------------------------------------------------- run

$sample = ['cpu_peak' => 0.0, 'mem_peak_gb' => 0.0, 'load_peak' => 0.0];
$ncpu = (int) trim((string) shell_exec('sysctl -n hw.ncpu')) ?: 1;
$page = (int) trim((string) shell_exec('sysctl -n hw.pagesize')) ?: 16384;
$take_sample = static function () use (&$sample, $ncpu, $page): void {
    $cpu = 0.0;
    foreach (explode("\n", (string) shell_exec('ps -A -o %cpu=')) as $l) {
        $cpu += (float) trim($l);
    }
    $sample['cpu_peak'] = max($sample['cpu_peak'], $cpu / $ncpu);
    $vm = (string) shell_exec('vm_stat');
    $pages = 0;
    foreach (['Pages active', 'Pages wired down', 'Pages occupied by compressor'] as $k) {
        if (preg_match('/' . preg_quote($k, '/') . ':\s+(\d+)/', $vm, $m)) {
            $pages += (int) $m[1];
        }
    }
    $sample['mem_peak_gb'] = max($sample['mem_peak_gb'], $pages * $page / 1073741824);
    $load = (string) shell_exec('sysctl -n vm.loadavg');
    if (preg_match('/[\d.]+/', $load, $m)) {
        $sample['load_peak'] = max($sample['load_peak'], (float) $m[0]);
    }
};

$results = [];
if ($report_only) {
    $manifest = json_decode((string) @file_get_contents($log_dir . '/jobs.json'), true);
    is_array($manifest) || fail("--reuse-logs: no jobs.json in {$log_dir}");
    $jobs    = $manifest['jobs'];
    $results = $manifest['results'];
    $legs    = $manifest['legs'] ?? array_values(array_unique(array_column($jobs, 'leg')));
    $sample  = $manifest['sample'] ?? $sample;
    $wall    = (float) ($manifest['wall'] ?? 0);
    say("Reporting on {$log_dir} (nothing is run).");
} else {
    $queue = array_values($jobs);
    // Longest first. Lint and drift are cheap and first in the table either way.
    usort($queue, static fn ($a, $b) => $b['est'] <=> $a['est']);
    say(sprintf(
        '%d legs as %d jobs on %d workers (suite shards: %s); logs in %s',
        count($legs),
        count($queue),
        $workers,
        implode(', ', array_map(static fn ($s) => leg_key($s) . ' x' . count(array_filter($jobs, static fn ($j) => $j['leg'] === $s)), $suite_legs)) ?: 'none',
        $log_dir
    ));

    $base_env = getenv();
    unset($base_env['WPMCP_PARALLEL_LEGS']);
    $start_job = static function (array $job) use ($root, $ws, $log_dir, $port, $sock, $bin, $run_id, $use_templates, $base_env, $state, $cache, $workers): array {
        $id   = $job['id'];
        $jdir = $ws . '/junit/' . $id;
        $tmp  = $ws . '/tmp/' . $id;
        @mkdir($jdir, 0777, true);
        @mkdir($tmp, 0777, true);
        $env = $base_env + [];
        $env['TMPDIR'] = $tmp . '/';
        $env['WPMCP_PARALLEL_CHILD'] = '1';
        $env['WPMCP_PARALLEL_RUN_ID'] = (string) $run_id;
        $env['WPMCP_TEST_DB_HOST'] = '127.0.0.1:' . $port;
        $env['WPMCP_TEST_DB_USER'] = 'wpmcp';
        $env['WPMCP_TEST_DB_PASS'] = 'wpmcp';
        $env['WPMCP_TEST_DB_SOCK'] = $sock;
        $env['WPMCP_MARIADB_BIN'] = $bin;
        $env['WPMCP_DB_TEMPLATES'] = $use_templates ? '1' : '0';
        $env['WPMCP_TEST_PHP'] = $ws . ($job['coverage'] ? '/php-cov' : '/php-fast');
        $env['WPMCP_JUNIT_DIR'] = $jdir;
        $env['WPMCP_CORE_TRASH'] = $state['trash'];
        unset($env['WPMCP_PHPUNIT_CONFIG'], $env['WPMCP_COVERAGE_OUT']);
        if (! empty($job['files']) || ! empty($job['whole'])) {
            $config = $ws . '/configs/' . $id . '.xml';
            write_config($root, $config, empty($job['whole']) ? $job['files'] : null);
            $env['WPMCP_PHPUNIT_CONFIG'] = $config;
        }
        unset($env['WPMCP_PHPUNIT_FILTER']);
        if (! empty($job['filter'])) {
            $env['WPMCP_PHPUNIT_FILTER'] = $job['filter'];
        }
        if ($job['coverage']) {
            $env['WPMCP_COVERAGE_OUT'] = $ws . '/coverage/' . $id . '.xml';
        }
        $tl = escapeshellarg($root . '/bin/test-local.sh');
        $cmd = match ($job['cmd']) {
            'psr12' => escapeshellarg($ws . '/php-fast') . ' vendor/bin/phpcs -q --parallel=4 --cache=' . escapeshellarg($cache . '/phpcs-psr12.cache'),
            'wpcs' => 'WPMCP_PHPCS_EXTRA_ARGS=' . escapeshellarg("--parallel=4\n--cache={$cache}/phpcs-wpcs.cache") . ' ' . escapeshellarg($ws . '/php-fast') . ' bin/check-phpcs-baseline.php',
            'drift' => escapeshellarg($ws . '/php-fast') . ' bin/check-ability-drift.php --strict',
            'fast' => escapeshellarg($ws . '/php-fast') . ' vendor/bin/paratest -c phpunit-fast.xml.dist --testsuite fast --processes=' . max(2, intdiv($workers, 3)) . ' --log-junit ' . escapeshellarg($jdir . '/run-1.xml'),
            'fast-heavy' => escapeshellarg($ws . '/php-fast') . ' vendor/bin/paratest -c phpunit-fast.xml.dist --testsuite heavy --processes=' . max(2, intdiv($workers, 3)) . ' --log-junit ' . escapeshellarg($jdir . '/run-1.xml'),
            default => 'bash ' . $tl . ' --leg ' . escapeshellarg($job['spec']),
        };
        // A process group of its own, so a stop reaches PHPUnit too.
        $full = 'exec perl -e ' . escapeshellarg('setpgrp(0, 0); exec @ARGV or die "exec: $!"') . ' /bin/sh -c ' . escapeshellarg($cmd);
        $proc = proc_open($full, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log_dir . '/' . $id . '.log', 'w'], 2 => ['file', $log_dir . '/' . $id . '.log', 'a']], $pipes, $root, $env);
        $proc !== false || fail("could not start job {$id}");
        $st = proc_get_status($proc);
        return ['proc' => $proc, 'pgid' => $st['pid'], 'start' => microtime(true)];
    };

    $last_sample = 0.0;
    $aborted     = false;
    $reap_trash  = static function (array &$state, bool $wait = false): void {
        $state['reapers'] ??= [];
        do {
            foreach ($state['reapers'] as $i => $p) {
                if (! proc_get_status($p)['running']) {
                    proc_close($p);
                    unset($state['reapers'][$i]);
                }
            }
            foreach (glob($state['trash'] . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (count($state['reapers']) >= 2) {
                    break;
                }
                // Renamed first, so no second reaper picks it up.
                $doomed = $dir . '.deleting';
                if (str_ends_with($dir, '.deleting') || ! @rename($dir, $doomed)) {
                    continue;
                }
                $p = proc_open('exec nice -n 10 rm -rf ' . escapeshellarg($doomed), [], $pipes);
                if ($p !== false) {
                    $state['reapers'][] = $p;
                }
            }
            if ($wait && $state['reapers'] !== []) {
                usleep(200000);
            }
        } while ($wait && $state['reapers'] !== []);
    };
    $pending     = $queue;
    while (($pending !== [] || $state['running'] !== []) && $interrupted === 0) {
        while ($pending !== [] && count($state['running']) < $workers) {
            $job = array_shift($pending);
            $state['running'][$job['id']] = $start_job($job);
        }
        foreach ($state['running'] as $id => $r) {
            $s = proc_get_status($r['proc']);
            if ($s['running']) {
                continue;
            }
            proc_close($r['proc']);
            unset($state['running'][$id]);
            $results[$id] = ['rc' => $s['exitcode'], 'start' => $r['start'] - $t0, 'end' => microtime(true) - $t0];
            $tag = $s['exitcode'] === 0 ? 'done' : 'FAIL';
            say(sprintf('%s %-30s %7s  (%d/%d, %s)', $tag, $id, fmt_secs($results[$id]['end'] - $results[$id]['start']), count($results), count($jobs), fmt_secs(microtime(true) - $t0)));
            if ($s['exitcode'] !== 0 && $jobs[$id]['failfast'] && $fail_fast) {
                say("The {$id} tier failed: stopping every other job (WPMCP_PARALLEL_FAIL_FAST=0 runs them anyway).");
                $aborted = true;
                $pending = [];
                stop_jobs($state);
            }
        }
        // Core copies the workers moved aside are deleted while the run goes
        // on, two at a time and niced, so the end of the run does not wait on
        // forty rm -rf of 18,000 files each.
        $reap_trash($state);
        if (microtime(true) - $last_sample > 1.0) {
            $take_sample();
            $last_sample = microtime(true);
        }
        usleep(50000);
    }
    foreach ($jobs as $id => $job) {
        $results[$id] ??= ['rc' => -1, 'start' => 0, 'end' => 0, 'not_run' => true];
    }
    $wall = microtime(true) - $t0;
    if ($interrupted !== 0) {
        say('Interrupted: stopping every job and cleaning up.');
        cleanup($state, $bin);
        exit(128 + $interrupted);
    }
}

// --------------------------------------------------------------- report

$legs_out = [];
$failed   = 0;
$job_times = [];
$class_times = [];
foreach ($legs as $spec) {
    $key  = leg_key($spec);
    $mine = array_filter($jobs, static fn ($j) => $j['leg'] === $spec);
    $row  = ['leg' => $spec, 'key' => $key, 'jobs' => count($mine), 'tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0,
        'failing' => [], 'bad_jobs' => [], 'cpu' => 0.0, 'first' => INF, 'last' => 0.0];
    $file_times = [];
    foreach ($mine as $id => $job) {
        $res = $results[$id] ?? ['rc' => -1, 'start' => 0, 'end' => 0];
        $row['cpu']  += $res['end'] - $res['start'];
        $row['first'] = min($row['first'], $res['start']);
        $row['last']  = max($row['last'], $res['end']);
        $jdir = ($report_only ? $log_dir . '/junit' : $ws . '/junit') . '/' . $id;
        $ju = read_junit($jdir, $root);
        $row['tests']    += $ju['tests'];
        $row['failures'] += $ju['failures'];
        $row['errors']   += $ju['errors'];
        $row['skipped']  += $ju['skipped'];
        $row['failing']   = array_merge($row['failing'], $ju['failing']);
        foreach ($ju['files'] as $f => $t) {
            $file_times[$f] = ($file_times[$f] ?? 0) + $t;
        }
        foreach ($ju['methods'] as $m => $t) {
            $file_times[$m] = ($file_times[$m] ?? 0) + $t;
        }
        foreach ($ju['class_of'] as $f => $c) {
            $file_times['class:' . $f] = $c;
        }
        foreach ($ju['classes'] as $c => $t) {
            $class_times[$key . ' ' . $c] = $t;
        }
        if ($res['rc'] !== 0 && $ju['failing'] === []) {
            $row['bad_jobs'][] = $id . (! empty($res['not_run']) ? ' (not run: stopped by the failing fast tier)' : ' (exit ' . $res['rc'] . ')');
        }
        if (! in_array($job['cmd'], ['psr12', 'wpcs', 'drift', 'fast'], true) && empty($job['coverage']) && $res['rc'] === 0) {
            if (empty($job['shard'])) {
                $job_times[$id] = $res['end'] - $res['start'];
            }
        }
    }
    sort($row['failing']);
    $baseline = read_baseline($root, $key);
    $row['new']   = array_values(array_diff($row['failing'], $baseline));
    $row['fixed'] = array_values(array_diff($baseline, $row['failing']));
    $row['baselined'] = count($row['failing']) - count($row['new']);
    $row['ok'] = $row['new'] === [] && $row['bad_jobs'] === [];
    if (! $report_only && in_array(explode(' ', $spec)[0], ['phpunit', 'multisite'], true) && $file_times !== []) {
        save_timings($cache, $key, $file_times);
    }
    $legs_out[] = $row;
}
if (! $report_only) {
    $old_jobs = load_timings($cache, 'jobs');
    foreach ($job_times as $k => $v) {
        // A job's first run builds its install: keep the faster of the two.
        if (isset($old_jobs[$k]) && is_numeric($old_jobs[$k])) {
            $job_times[$k] = min($v, 0.3 * (float) $old_jobs[$k] + 0.7 * $v);
        }
    }
    save_timings($cache, 'jobs', $job_times, true);
}

// Coverage: merge the shards and check the floor.
$coverage_line = '';
$cov_files = $report_only ? glob($log_dir . '/coverage/*.xml') : glob($ws . '/coverage/*.xml');
if ($cov_files) {
    [$stmts, $covered] = merge_clover($cov_files, $log_dir . '/clover-merged.xml');
    $pct = $stmts > 0 ? 100 * $covered / $stmts : 0;
    $cov_ok = $pct + 0.0001 >= (float) $floor;
    $coverage_line = sprintf('coverage %.2f%% (%d of %d statements, %d shards), floor %s%%: %s', $pct, $covered, $stmts, count($cov_files), $floor, $cov_ok ? 'ok' : 'BELOW THE FLOOR');
    if (! $cov_ok) {
        $failed++;
    }
}

// Keep the reports with the logs.
if (! $report_only) {
    sh('cp -R ' . escapeshellarg($ws . '/junit') . ' ' . escapeshellarg($log_dir . '/junit'));
    if ($cov_files) {
        sh('cp -R ' . escapeshellarg($ws . '/coverage') . ' ' . escapeshellarg($log_dir . '/coverage'));
    }
    file_put_contents($log_dir . '/jobs.json', json_encode(['legs' => $legs, 'jobs' => $jobs, 'results' => $results, 'sample' => $sample, 'wall' => $wall, 'workers' => $workers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Baselines are only seeded from a run where every job ended properly.
$run_clean = array_sum(array_map(static fn ($r) => count($r['bad_jobs']), $legs_out)) === 0;
if ($seed_baseline && ! $run_clean) {
    say('Not seeding baselines: some jobs ended badly in this run.');
}
$head = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null'));
$seed_source = 'a parallel run on ' . ($head ?: 'an unknown commit') . ', ' . trim((string) shell_exec('date "+%Y-%m-%d %H:%M %Z"'));
$out = [];
$out[] = sprintf('%-4s  %-28s %5s %7s %6s %6s %7s %9s %9s  %s', 'ok', 'leg', 'jobs', 'tests', 'fail', 'error', 'skip', 'wall', 'cpu', 'baseline');
foreach ($legs_out as $r) {
    $ok = $r['ok'] ? 'pass' : 'FAIL';
    if (! $r['ok']) {
        $failed++;
    }
    $wall_leg = $r['last'] > 0 && is_finite($r['first']) ? $r['last'] - $r['first'] : 0;
    $base = $r['failing'] === [] && $r['fixed'] === [] ? '-' : sprintf('%d known, %d new, %d fixed', $r['baselined'], count($r['new']), count($r['fixed']));
    $out[] = sprintf('%-4s  %-28s %5d %7d %6d %6d %7d %9s %9s  %s', $ok, $r['leg'], $r['jobs'], $r['tests'], $r['failures'], $r['errors'], $r['skipped'], fmt_secs($wall_leg), fmt_secs($r['cpu']), $base);
}
$out[] = '';
foreach ($legs_out as $r) {
    foreach ($r['bad_jobs'] as $b) {
        $out[] = "  {$r['key']}: job {$b} ended badly with no failing test to show; see its log.";
    }
    if ($r['new'] !== []) {
        $out[] = "  {$r['key']}: NEW failures (not in " . substr(baseline_file($root, $r['key']), strlen($root) + 1) . '):';
        foreach ($r['new'] as $t) {
            $out[] = "    {$t}";
        }
    }
    // A leg whose jobs ended badly is no baseline: its list would be noise.
    if ($seed_baseline && $run_clean && seed_baseline_file($root, $r['key'], $r['failing'], $seed_source)) {
        $out[] = "  {$r['key']}: baseline seeded with " . count($r['failing']) . ' known failing tests.';
    }
    if ($r['fixed'] !== []) {
        $out[] = "  {$r['key']}: fixed, still in the baseline" . ($prune_baseline ? ' (pruned now):' : ' (run with --prune-baseline to drop them):');
        foreach ($r['fixed'] as $t) {
            $out[] = "    {$t}";
        }
        if ($prune_baseline) {
            prune_baseline_file($root, $r['key'], $r['fixed']);
        }
    }
}
if ($coverage_line !== '') {
    $out[] = '  ' . $coverage_line;
}
arsort($class_times);
$out[] = '  slowest test classes:';
foreach (array_slice($class_times, 0, 20, true) as $c => $t) {
    $out[] = sprintf('    %8s  %s', fmt_secs($t), $c);
}
$out[] = sprintf('  wall clock %s on %d workers; peak CPU %.0f%% of %d cores, peak load %.1f, peak memory in use %.1f GB', fmt_secs($wall), $workers, $sample['cpu_peak'], $ncpu, $sample['load_peak'], $sample['mem_peak_gb']);
$out[] = '  logs: ' . $log_dir;
file_put_contents($log_dir . '/summary.txt', implode("\n", $out) . "\n");
say('Summary:');
fwrite(STDOUT, implode("\n", $out) . "\n");
if ($failed > 0) {
    fail("{$failed} check(s) failed.");
}
say('All green (known failures aside, see the baseline column).');
exit(0);
