#!/usr/bin/env php
<?php
/**
 * Static ability-registration drift guard (issue #86, Wave 0 item #55).
 *
 * Fast, WordPress-free complement to tests/free/Platform/AbilityManifestTest.php.
 * The manifest test needs a full WP test environment; this script runs in
 * milliseconds in any CI job and catches the two cheap-to-catch drift modes:
 *
 *   1. A `use WPMCP\Tools\...` import in src/Plugin.php whose class file no
 *      longer exists on disk (tool deleted but registration left behind).
 *   2. A class file under src/Tools that nothing else in src/ references
 *      (tool shipped on disk but never wired into any registration path).
 *
 * Usage: php bin/check-ability-drift.php [--strict]
 *
 * Missing files always fail (exit 1). Orphaned tool classes are reported as
 * warnings by default and fail only with --strict, because some tools are
 * registered indirectly (flavors, pro bootstrap, dispatcher tables).
 */

$root   = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);

$pluginPhp = file_get_contents($root . '/src/Plugin.php');
if (false === $pluginPhp) {
    fwrite(STDERR, "check-ability-drift: cannot read src/Plugin.php\n");
    exit(1);
}

$failures = [];
$warnings = [];

// 1. Every WPMCP\Tools import in Plugin.php must resolve to a file (PSR-4).
preg_match_all('/^use\s+(WPMCP\\\\Tools\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $pluginPhp, $m);
foreach ($m[1] as $fqcn) {
    $rel  = str_replace('\\', '/', substr($fqcn, strlen('WPMCP\\')));
    $file = $root . '/src/' . $rel . '.php';
    if (! is_file($file)) {
        $failures[] = "imported but missing on disk: {$fqcn} (expected src/{$rel}.php)";
    }
}

// 2. Every class file under src/Tools must be referenced somewhere in src/.
$srcFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
$sources  = [];
$tools    = [];
foreach ($srcFiles as $f) {
    if ('php' !== $f->getExtension()) {
        continue;
    }
    $path             = $f->getPathname();
    $sources[$path]   = file_get_contents($path);
    // Only class files count (Upper_Snake basenames, PSR-4 style); kebab-case
    // files under src/Tools are data files loaded by filename, not classes.
    if (str_starts_with($path, $root . '/src/Tools/') && preg_match('/^[A-Z][A-Za-z0-9_]*$/', $f->getBasename('.php'))) {
        $tools[] = $path;
    }
}

foreach ($tools as $toolPath) {
    $short      = basename($toolPath, '.php');
    $referenced = false;
    foreach ($sources as $path => $code) {
        if ($path === $toolPath) {
            continue;
        }
        if (false !== strpos($code, $short)) {
            $referenced = true;
            break;
        }
    }
    if (! $referenced) {
        $warnings[] = 'tool class never referenced outside its own file: ' . substr($toolPath, strlen($root) + 1);
    }
}

foreach ($failures as $f) {
    fwrite(STDERR, "FAIL: {$f}\n");
}
foreach ($warnings as $w) {
    fwrite(STDERR, ($strict ? 'FAIL' : 'WARN') . ": {$w}\n");
}

printf(
    "check-ability-drift: %d imports checked, %d tool classes scanned, %d missing, %d orphaned\n",
    count($m[1]),
    count($tools),
    count($failures),
    count($warnings)
);

if ($failures || ($strict && $warnings)) {
    exit(1);
}
echo "check-ability-drift: OK\n";
exit(0);
