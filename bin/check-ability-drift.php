#!/usr/bin/env php
<?php
/**
 * Static ability-registration drift guard (issue #86, Wave 0 item #55).
 *
 * Fast, WordPress-free complement to tests/free/Platform/AbilityManifestTest.php,
 * which needs a full WP test environment. This script runs in any CI job (and
 * against a staged build tree) and catches three drift modes:
 *
 *   1. Named-class drift: any WPMCP class named anywhere under src/ (import,
 *      aliased import, group import, `new \WPMCP\...`, `Foo::` static, or a
 *      string callable) that no longer has a declaration on disk. This is the
 *      shape a strip step or a deleted tool leaves behind, and it fatals at
 *      load or on the hook.
 *   2. Unreachable tools: a class file under src/Tools that is not reachable
 *      from any registration root (the plugin entry files, plus anything named
 *      from outside src/: scripts, flavors, tools, bin, tests). Reachability is
 *      a graph walk, so a dead cluster of tools that only reference each other
 *      is still reported.
 *   3. Ability drift: the ability name/tier literals in `new Ability(...)`
 *      calls, diffed against tests/support/ability-manifest.php. Catches an
 *      ability renamed or silently re-tiered without regenerating the manifest.
 *
 * References are matched on PHP tokens with comments discarded, so a class
 * mentioned only in a docblock does not count as wiring, and Get_Post_Meta
 * does not satisfy Get_Post.
 *
 * Usage: php bin/check-ability-drift.php [--strict] [root]
 *
 * Missing declarations and ability drift always fail (exit 1). Unreachable
 * tools are warnings by default and fail with --strict.
 */

$argvRest = array_values(array_filter(
    array_slice($argv, 1),
    static fn($a) => '--strict' !== $a
));
$strict = in_array('--strict', $argv, true);
$root   = rtrim($argvRest[0] ?? dirname(__DIR__), '/');

if (! is_dir($root . '/src')) {
    fwrite(STDERR, "check-ability-drift: no src/ directory under {$root}\n");
    exit(1);
}

/**
 * Tool classes that are legitimately not reachable from a registration root:
 * wired at runtime through a path this static walk cannot see, or landed
 * deliberately ahead of their consumer. Each entry carries its reason. An
 * empty allowlist is the goal; a name parked here without a reason is drift
 * wearing a hat.
 *
 * @var array<string,string> class short name => why it is exempt
 */
$knownUnreachable = [
    'Url_Rewriter' => 'landed ahead of its consumers (restore and migration) '
        . 'deliberately and under test; see '
        . 'docs/superpowers/specs/2026-08-13-site-backup-archive-format.md',
];

/**
 * Abilities registered only on multisite. tests/support/ability-manifest.php
 * pins the canonical single-site environment, so these names are statically
 * present but legitimately absent from the manifest.
 *
 * @var list<string>
 */
$multisiteOnlyAbilities = [
    'wpmcp/get-network-info',
    'wpmcp/list-network-sites',
    'wpmcp/get-site-details',
];

/** Collect every .php file under a directory. @return list<string> */
$phpFiles = static function (string $dir): array {
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ('php' === $f->getExtension()) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
};

/**
 * Identifiers a file names in code, comments discarded. Qualified names are
 * split so both the short name and every namespace segment land in the set,
 * and class-name string literals ('WPMCP\\Tools\\X') are decoded.
 *
 * @return array<string,true>
 */
$identifiers = static function (string $code): array {
    $out = [];
    foreach (@token_get_all($code) as $token) {
        if (! is_array($token)) {
            continue;
        }
        [$id, $text] = $token;
        if (T_COMMENT === $id || T_DOC_COMMENT === $id) {
            continue;
        }
        if (T_CONSTANT_ENCAPSED_STRING === $id) {
            $literal = stripslashes(substr($text, 1, -1));
            if (false === stripos($literal, 'WPMCP\\')) {
                continue;
            }
            foreach (explode('\\', $literal) as $part) {
                if ('' !== $part) {
                    $out[$part] = true;
                }
            }
            continue;
        }
        $isName = T_STRING === $id
            || (defined('T_NAME_QUALIFIED') && T_NAME_QUALIFIED === $id)
            || (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $id)
            || (defined('T_NAME_RELATIVE') && T_NAME_RELATIVE === $id);
        if (! $isName) {
            continue;
        }
        foreach (explode('\\', $text) as $part) {
            if ('' !== $part) {
                $out[$part] = true;
            }
        }
    }
    return $out;
};

/** The file's code with comments blanked, for the regex passes. */
$stripComments = static function (string $code): string {
    $out = '';
    foreach (@token_get_all($code) as $token) {
        if (is_array($token)) {
            $out .= (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0])
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
};

$failures = [];
$warnings = [];

// ---------------------------------------------------------------- class index
// Authoritative for whatever tree we were pointed at: every class, interface,
// trait and enum actually declared under src/.
$srcFiles  = $phpFiles($root . '/src');
$sources   = [];
$idents    = [];
$declared  = [];  // FQCN (lowercased) => file
$shortToF  = [];  // short name => list of FQCN
foreach ($srcFiles as $path) {
    $code           = (string) file_get_contents($path);
    $sources[$path] = $stripComments($code);
    $idents[$path]  = $identifiers($code);
    $ns             = '';
    if (preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $sources[$path], $nm)) {
        $ns = $nm[1] . '\\';
    }
    if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $sources[$path], $cm)) {
        foreach ($cm[1] as $short) {
            $fqcn                            = $ns . $short;
            $declared[strtolower($fqcn)]     = $path;
            $shortToF[$short][]              = $fqcn;
        }
    }
}

// -------------------------------------------------- 1. named classes resolve
$namedCount = 0;
foreach ($srcFiles as $path) {
    $code  = $sources[$path];
    $named = [];

    // Plain and aliased imports.
    preg_match_all('/^\s*use\s+(WPMCP\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+[A-Za-z0-9_]+)?\s*;/m', $code, $uses);
    $named = array_merge($named, $uses[1]);
    $matchedUseLines = count($uses[1]);

    // Group imports: use WPMCP\Tools\{A, B as C};
    if (preg_match_all('/^\s*use\s+(WPMCP\\\\[A-Za-z0-9_\\\\]*)\\\\\{([^}]+)\}\s*;/m', $code, $groups, PREG_SET_ORDER)) {
        foreach ($groups as $g) {
            $matchedUseLines++;
            foreach (explode(',', $g[2]) as $item) {
                $item = trim(preg_replace('/\s+as\s+[A-Za-z0-9_]+$/i', '', trim($item)));
                if ('' !== $item) {
                    $named[] = rtrim($g[1], '\\') . '\\' . $item;
                }
            }
        }
    }

    // A `use WPMCP\...` line the two patterns above did not consume is a
    // parser gap, not a pass: say so loudly rather than dropping it.
    $totalUseLines = preg_match_all('/^\s*use\s+WPMCP\\\\/m', $code);
    if ($totalUseLines > $matchedUseLines) {
        $failures[] = 'unparsed WPMCP use statement in ' . substr($path, strlen($root) + 1)
            . ' (' . ($totalUseLines - $matchedUseLines) . ' of ' . $totalUseLines . ' unmatched)';
    }

    // Direct instantiation, statics/::class, and string callables.
    preg_match_all('/new\s+(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)\s*\(/', $code, $news);
    preg_match_all('/(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)::/', $code, $statics);
    preg_match_all('/[\'"]\\\\{0,2}(WPMCP(?:\\\\{1,2}[A-Za-z0-9_]+)+)[\'"]/', $code, $strings);
    $named = array_merge(
        $named,
        $news[1],
        $statics[1],
        array_map(static fn($c) => str_replace('\\\\', '\\', $c), $strings[1])
    );

    // A class named only behind a class_exists()/interface_exists() probe is
    // optional by construction: the guard is the code saying it may be absent,
    // so its absence is not a fatal waiting to happen.
    $guarded = [];
    if (preg_match_all('/(?:class_exists|interface_exists|trait_exists|enum_exists)\s*\(\s*[\'"]?\\\\?(WPMCP(?:\\\\{1,2}[A-Za-z0-9_]+)+)/', $code, $gm)) {
        foreach ($gm[1] as $g) {
            $guarded[strtolower(str_replace('\\\\\\\\', '\\\\', $g))] = true;
        }
    }

    foreach (array_unique($named) as $fqcn) {
        $fqcn = ltrim($fqcn, '\\');
        $namedCount++;
        if (isset($guarded[strtolower($fqcn)])) {
            continue;
        }
        if (! isset($declared[strtolower($fqcn)])) {
            $rel = str_replace('\\', '/', substr($fqcn, strlen('WPMCP\\')));
            $failures[] = 'named but not declared on disk: ' . $fqcn
                . ' (from ' . substr($path, strlen($root) + 1) . ', expected src/' . $rel . '.php)';
        }
    }
}
$failures = array_values(array_unique($failures));

// ------------------------------------------- 2. tools reachable from a root
// Roots are the production wiring paths: the plugin entry files, plus any
// src class named from outside src/ (scripts/, which is where the flavor
// builds wire things, tools/, bin/). tests/ is deliberately not a root: a
// tool referenced only by its own unit test is still not wired into the
// product, which is exactly the drift this check exists to see.
$queue   = [];
$seedIds = [];
foreach (glob($root . '/*.php') ?: [] as $entry) {
    $seedIds[] = $identifiers((string) file_get_contents($entry));
}
foreach (['scripts', 'tools', 'bin'] as $dir) {
    foreach ($phpFiles($root . '/' . $dir) as $path) {
        $seedIds[] = $identifiers((string) file_get_contents($path));
    }
}
$seeds = $seedIds ? array_merge(...$seedIds) : [];
foreach ($shortToF as $short => $fqcns) {
    if (! isset($seeds[$short])) {
        continue;
    }
    foreach ($fqcns as $fqcn) {
        $queue[] = $declared[strtolower($fqcn)];
    }
}

$reached = [];
while ($queue) {
    $path = array_pop($queue);
    if (isset($reached[$path]) || ! isset($idents[$path])) {
        continue;
    }
    $reached[$path] = true;
    foreach ($shortToF as $short => $fqcns) {
        if (! isset($idents[$path][$short])) {
            continue;
        }
        foreach ($fqcns as $fqcn) {
            $target = $declared[strtolower($fqcn)];
            if (! isset($reached[$target])) {
                $queue[] = $target;
            }
        }
    }
}

$toolPrefix = $root . '/src/Tools/';
$tools      = [];
foreach ($declared as $lcFqcn => $path) {
    if (str_starts_with($path, $toolPrefix)) {
        $tools[$path] = true;
    }
}
foreach (array_keys($tools) as $path) {
    if (isset($reached[$path])) {
        continue;
    }
    $short = basename($path, '.php');
    if (isset($knownUnreachable[$short])) {
        continue;
    }
    $warnings[] = 'tool class not reachable from any registration root: '
        . substr($path, strlen($root) + 1);
}
sort($warnings);

// --------------------------------------------- 3. ability names/tiers pinned
$abilities   = [];
$manifestSay = 'skipped (no tests/support/ability-manifest.php)';
foreach ($sources as $path => $code) {
    if (preg_match_all('/new\s+Ability\s*\(\s*(\'[^\']*\'|"[^"]*")\s*,\s*(\'[^\']*\'|"[^"]*")/', $code, $am, PREG_SET_ORDER)) {
        foreach ($am as $a) {
            $abilities[trim($a[1], '\'"')] = trim($a[2], '\'"');
        }
    }
}
$manifestFile = $root . '/tests/support/ability-manifest.php';
if (is_file($manifestFile)) {
    $manifest = require $manifestFile;
    $pinned   = $manifest['abilities'] ?? [];
    $unknown  = 0;
    $retiered = 0;
    foreach ($abilities as $name => $tier) {
        if (! isset($pinned[$name])) {
            if (in_array($name, $multisiteOnlyAbilities, true)) {
                continue;
            }
            $failures[] = "ability registered but not in the manifest: {$name}"
                . ' (rename or add? run composer manifest:regenerate)';
            $unknown++;
            continue;
        }
        if ($pinned[$name] !== $tier) {
            $failures[] = "ability re-tiered without regenerating the manifest: {$name}"
                . " (code says {$tier}, manifest says {$pinned[$name]})";
            $retiered++;
        }
    }
    $manifestSay = sprintf(
        '%d literal registrations vs %d pinned, %d unknown, %d re-tiered',
        count($abilities),
        count($pinned),
        $unknown,
        $retiered
    );
}

// ------------------------------------------------------------------- report
foreach ($failures as $f) {
    fwrite(STDERR, "FAIL: {$f}\n");
}
foreach ($warnings as $w) {
    fwrite(STDERR, ($strict ? 'FAIL' : 'WARN') . ": {$w}\n");
}

printf(
    "check-ability-drift: %d class references checked, %d tool classes scanned, %d undeclared, %d unreachable; abilities: %s\n",
    $namedCount,
    count($tools),
    count($failures),
    count($warnings),
    $manifestSay
);

if ($failures || $warnings) {
    exit($failures || $strict ? 1 : 0);
}
echo "check-ability-drift: OK\n";
exit(0);
