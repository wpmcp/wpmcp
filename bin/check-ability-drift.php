#!/usr/bin/env php
<?php
/**
 * Static ability-registration drift guard (issue #86, Wave 0 item #55).
 *
 * Fast, WordPress-free complement to tests/free/Platform/AbilityManifestTest.php,
 * which needs a full WP test environment. This script runs in any CI job (and
 * against a staged build tree) and catches three drift modes:
 *
 *   1. Named-class drift: any WPMCP class named anywhere under src/ or in a
 *      root entry file (import, aliased import, group import, comma-separated
 *      import, `new \WPMCP\...`, `Foo::` static, or a string callable) that no
 *      longer has a declaration on disk. This is the shape a strip step or a
 *      deleted tool leaves behind, and it fatals at load or on the hook.
 *   2. Unreachable tools: a class file under src/Tools that is not reachable
 *      from any registration root (the plugin entry files, plus anything named
 *      from outside src/: scripts, tools, bin). tests/ is deliberately not a
 *      root. Reachability is a graph walk, so a dead cluster of tools that
 *      only reference each other is still reported. An import with no call
 *      site in the importing file is not an edge: a tool dropped from the
 *      registrar but left with a stale `use` line is the drift, not the alibi.
 *   3. Ability drift: the ability name/tier literals in `new Ability(...)`
 *      calls, diffed against tests/support/ability-manifest.php. Catches an
 *      ability renamed or silently re-tiered without regenerating the manifest.
 *
 * References are matched on PHP tokens with comments discarded, so a class
 * mentioned only in a docblock does not count as wiring, and Get_Post_Meta
 * does not satisfy Get_Post. Only the last segment of a qualified name is
 * indexed as a class reference, so a namespace segment does not keep a
 * same-named class alive.
 *
 * Known limit, stated rather than implied: the ability check is one
 * directional. It walks the names it finds in code, so an ability still
 * pinned in the manifest but no longer registered anywhere passes here. Only
 * 251 of the pinned abilities are registered with literal name and tier; the
 * table-driven rest (cloud, integrations) are invisible to a static read.
 * Both gaps stay the runtime AbilityManifestTest's job.
 *
 * Usage: php bin/check-ability-drift.php [--strict] [--manifest PATH]
 *                                        [--no-manifest] [root]
 *
 * Missing declarations, unparsed use statements and ability drift always fail
 * (exit 1). Unreachable tools are warnings by default and fail with --strict.
 * Under --strict a missing manifest is a failure too: a guard that reports
 * success for a third it never ran is worse than one that did not run.
 */

$strict      = false;
$noManifest  = false;
$manifestArg = null;
$positional  = [];
$args        = array_slice($argv, 1);
for ($i = 0, $n = count($args); $i < $n; $i++) {
    $a = $args[$i];
    if ('--strict' === $a) {
        $strict = true;
    } elseif ('--no-manifest' === $a) {
        $noManifest = true;
    } elseif (str_starts_with($a, '--manifest=')) {
        $manifestArg = substr($a, strlen('--manifest='));
    } elseif ('--manifest' === $a) {
        $manifestArg = $args[++$i] ?? '';
    } else {
        $positional[] = $a;
    }
}
$root = rtrim($positional[0] ?? dirname(__DIR__), '/');

if (! is_dir($root . '/src')) {
    fwrite(STDERR, "check-ability-drift: no src/ directory under {$root}\n");
    exit(1);
}

$ownRepo = realpath($root) === realpath(dirname(__DIR__));

/**
 * Tool classes that are legitimately not reachable from a registration root:
 * wired at runtime through a path this static walk cannot see, or landed
 * deliberately ahead of their consumer. Keyed on the src-relative path so a
 * future class that merely shares a short name is not silenced too. Each entry
 * carries its reason, and a stale entry is itself reported: an empty allowlist
 * is the goal, and a name parked here without a reason is drift wearing a hat.
 *
 * @var array<string,string> src-relative path => why it is exempt
 */
$knownUnreachable = [
    'src/Tools/Backup/Url_Rewriter.php' => 'landed ahead of its consumers '
        . '(restore and migration) deliberately and under test; see '
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
 * Class references a file makes in code: comments discarded, namespace
 * declarations discarded, import statements discarded, and only the last
 * segment of a qualified name kept.
 *
 * Dropping imports is deliberate. A `use` line is a declaration of intent, not
 * a call: a tool deleted from the registrar and left with its import still in
 * place has no registration path into it, and counting the import as an edge
 * is exactly how that drift hides. A class actually used shows up again at its
 * call site, so nothing genuinely wired is lost.
 *
 * Dropping every segment but the last is deliberate too: the "Content" in
 * WPMCP\Tools\Content\Get_Post is a namespace, not a reference to a class
 * named Content.
 *
 * @return array<string,true>
 */
$identifiers = static function (string $code): array {
    $sig = [];
    foreach (@token_get_all($code) as $t) {
        if (
            is_array($t)
            && (T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0])
        ) {
            continue;
        }
        $sig[] = $t;
    }

    $out   = [];
    $depth = 0;
    $skip  = false;   // inside a top-level import statement
    for ($i = 0, $n = count($sig); $i < $n; $i++) {
        $t = $sig[$i];
        if (! is_array($t)) {
            if ('{' === $t) {
                $depth++;
            } elseif ('}' === $t) {
                $depth--;
            } elseif (';' === $t) {
                $skip = false;
            }
            continue;
        }
        [$id, $text] = $t;

        // `use` at depth 0 is an import unless it is a closure's `use (...)`.
        // Inside a class body it is a trait use, which is a real reference.
        if (T_USE === $id && 0 === $depth) {
            $next = $sig[$i + 1] ?? null;
            if (! (is_string($next) && '(' === $next)) {
                $skip = true;
            }
            continue;
        }
        if (T_NAMESPACE === $id) {
            if (isset($sig[$i + 1]) && is_array($sig[$i + 1])) {
                $i++;  // the namespace name itself is not a class reference
            }
            continue;
        }
        if ($skip) {
            continue;
        }

        if (T_CONSTANT_ENCAPSED_STRING === $id) {
            $literal = stripslashes(substr($text, 1, -1));
            if (false === stripos($literal, 'WPMCP\\')) {
                continue;
            }
            $parts = explode('\\', $literal);
            $last  = (string) end($parts);
            if ('' !== $last) {
                $out[$last] = true;
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
        $parts = explode('\\', $text);
        $last  = (string) end($parts);
        if ('' !== $last) {
            $out[$last] = true;
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

/**
 * Every WPMCP class name a `use` statement imports, in any spelling PHP
 * accepts: plain, aliased, comma-separated, and group (with or without a
 * sub-namespace segment). Returns the parsed names plus the count of WPMCP
 * import statements this parser could not consume, which is a parser gap and
 * therefore a failure rather than a silent pass.
 *
 * @return array{0:list<string>,1:int}
 */
$parseImports = static function (string $code): array {
    $names    = [];
    $unparsed = 0;
    if (! preg_match_all('/^[ \t]*use\s+([^;]+);/m', $code, $stmts, PREG_SET_ORDER)) {
        return [$names, 0];
    }
    foreach ($stmts as $stmt) {
        $clause = trim($stmt[1]);
        if (false === stripos($clause, 'WPMCP')) {
            continue;
        }
        // `use function ...` / `use const ...` name callables, not classes.
        if (preg_match('/^(function|const)\s/i', $clause)) {
            continue;
        }
        $before = count($names);
        if (preg_match('/^(.*?)\\\\\{([^}]*)\}$/s', $clause, $gm)) {
            $prefix = trim(ltrim($gm[1], '\\'));
            foreach (explode(',', $gm[2]) as $item) {
                $item = trim(preg_replace('/\s+as\s+[A-Za-z0-9_]+$/i', '', trim($item)));
                if ('' !== $item) {
                    $names[] = rtrim($prefix, '\\') . '\\' . $item;
                }
            }
        } else {
            foreach (explode(',', $clause) as $item) {
                $item = trim(preg_replace('/\s+as\s+[A-Za-z0-9_]+$/i', '', trim($item)));
                $item = ltrim($item, '\\');
                if ('' !== $item && preg_match('/^WPMCP(\\\\[A-Za-z0-9_]+)+$/', $item)) {
                    $names[] = $item;
                }
            }
        }
        if (count($names) === $before) {
            $unparsed++;
        }
    }
    return [$names, $unparsed];
};

$undeclared        = [];
$unparsedUses      = [];
$abilityFailures   = [];
$allowlistFailures = [];
$warnings          = [];

// ---------------------------------------------------------------- class index
// Authoritative for whatever tree we were pointed at: every class, interface,
// trait and enum actually declared under src/.
$srcFiles   = $phpFiles($root . '/src');
$entryFiles = array_values(array_filter(glob($root . '/*.php') ?: [], 'is_file'));
// The entry file is the one file where a dangling class fatals at load, and
// the wp.org flavor regenerates it, so it is scanned as well as seeded from.
$scanFiles  = array_merge($srcFiles, $entryFiles);
$sources    = [];
$idents     = [];
$declared   = [];  // FQCN (lowercased) => file
$shortToF   = [];  // short name => list of FQCN
foreach ($scanFiles as $path) {
    $code           = (string) file_get_contents($path);
    $sources[$path] = $stripComments($code);
    $idents[$path]  = $identifiers($code);
    $ns             = '';
    if (preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $sources[$path], $nm)) {
        $ns = $nm[1] . '\\';
    }
    if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $sources[$path], $cm)) {
        foreach ($cm[1] as $short) {
            $fqcn                        = $ns . $short;
            $declared[strtolower($fqcn)] = $path;
            $shortToF[$short][]          = $fqcn;
        }
    }
}

// -------------------------------------------------- 1. named classes resolve
$namedCount = 0;
foreach ($scanFiles as $path) {
    $code  = $sources[$path];
    $rel   = substr($path, strlen($root) + 1);

    [$imported, $unparsed] = $parseImports($code);
    $named                 = $imported;
    if ($unparsed > 0) {
        $unparsedUses[] = 'unparsed WPMCP use statement in ' . $rel
            . ' (' . $unparsed . ' unmatched)';
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
    // so its absence is not a fatal waiting to happen. Both spellings count,
    // the `::class` form and the string literal, so the guarded key is
    // normalised exactly the way the string-callable key above is.
    $guarded = [];
    if (preg_match_all('/(?:class_exists|interface_exists|trait_exists|enum_exists)\s*\(\s*[\'"]?\\\\{0,2}(WPMCP(?:\\\\{1,2}[A-Za-z0-9_]+)+)/', $code, $gm)) {
        foreach ($gm[1] as $g) {
            $guarded[strtolower(str_replace('\\\\', '\\', $g))] = true;
        }
    }

    foreach (array_unique($named) as $fqcn) {
        $fqcn = ltrim($fqcn, '\\');
        $namedCount++;
        if (isset($guarded[strtolower($fqcn)])) {
            continue;
        }
        if (! isset($declared[strtolower($fqcn)])) {
            $expect       = str_replace('\\', '/', substr($fqcn, strlen('WPMCP\\')));
            $undeclared[] = 'named but not declared on disk: ' . $fqcn
                . ' (from ' . $rel . ', expected src/' . $expect . '.php)';
        }
    }
}
$undeclared   = array_values(array_unique($undeclared));
$unparsedUses = array_values(array_unique($unparsedUses));

// ------------------------------------------- 2. tools reachable from a root
// Roots are the production wiring paths: the plugin entry files, plus any
// src class named from outside src/ (scripts/, which is where the flavor
// builds wire things, tools/, bin/). tests/ is deliberately not a root: a
// tool referenced only by its own unit test is still not wired into the
// product, which is exactly the drift this check exists to see.
$queue   = [];
$seedIds = [];
foreach ($entryFiles as $entry) {
    $seedIds[] = $idents[$entry];
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
    $rel = substr($path, strlen($root) + 1);
    if (isset($knownUnreachable[$rel])) {
        continue;
    }
    $warnings[] = 'tool class not reachable from any registration root: ' . $rel;
}
sort($warnings);

// The allowlist has to expire on its own. An entry that is wired again has
// stopped being an exemption; one whose file is gone is a name nobody removed.
foreach ($knownUnreachable as $rel => $reason) {
    $abs = $root . '/' . $rel;
    if (! is_file($abs)) {
        if ($ownRepo) {
            $allowlistFailures[] = 'unreachable allowlist entry no longer exists, '
                . 'remove it from bin/check-ability-drift.php: ' . $rel;
        }
        continue;
    }
    if (isset($reached[$abs])) {
        $allowlistFailures[] = 'unreachable allowlist entry is reachable now, '
            . 'remove it from bin/check-ability-drift.php: ' . $rel;
    }
}

// --------------------------------------------- 3. ability names/tiers pinned
$abilities   = [];
$manifestSay = '';
foreach ($sources as $path => $code) {
    if (preg_match_all('/new\s+Ability\s*\(\s*(\'[^\']*\'|"[^"]*")\s*,\s*(\'[^\']*\'|"[^"]*")/', $code, $am, PREG_SET_ORDER)) {
        foreach ($am as $a) {
            $abilities[trim($a[1], '\'"')] = trim($a[2], '\'"');
        }
    }
}
$manifestFile = $manifestArg ?? ($root . '/tests/support/ability-manifest.php');
$unknown      = 0;
$retiered     = 0;
if ($noManifest) {
    $manifestSay = 'skipped (--no-manifest)';
} elseif (! is_file($manifestFile)) {
    $manifestSay = 'not found at ' . $manifestFile;
    if ($strict) {
        $abilityFailures[] = 'ability manifest not found at ' . $manifestFile
            . ' (pass --manifest PATH, or --no-manifest to run without the'
            . ' ability check)';
    } else {
        $manifestSay .= ' (skipped)';
    }
} else {
    $manifest = require $manifestFile;
    if (
        ! is_array($manifest)
        || ! isset($manifest['abilities'])
        || ! is_array($manifest['abilities'])
    ) {
        $manifestSay       = 'unreadable';
        $abilityFailures[] = 'ability manifest unreadable: ' . $manifestFile
            . ' (expected an array with an "abilities" map; run'
            . ' composer manifest:regenerate)';
    } else {
        $pinned = $manifest['abilities'];
        foreach ($abilities as $name => $tier) {
            if (! isset($pinned[$name])) {
                if (in_array($name, $multisiteOnlyAbilities, true)) {
                    continue;
                }
                $abilityFailures[] = "ability registered but not in the manifest: {$name}"
                    . ' (rename or add? run composer manifest:regenerate)';
                $unknown++;
                continue;
            }
            if ($pinned[$name] !== $tier) {
                $abilityFailures[] = "ability re-tiered without regenerating the manifest: {$name}"
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
}

// ------------------------------------------------------------------- report
$abilityFailures   = array_values(array_unique($abilityFailures));
$allowlistFailures = array_values(array_unique($allowlistFailures));
$failures          = array_merge(
    $undeclared,
    $unparsedUses,
    $allowlistFailures,
    $abilityFailures
);

foreach ($failures as $f) {
    fwrite(STDERR, "FAIL: {$f}\n");
}
foreach ($warnings as $w) {
    fwrite(STDERR, ($strict ? 'FAIL' : 'WARN') . ": {$w}\n");
}

printf(
    "check-ability-drift: %d class references checked, %d tool classes scanned, "
    . "%d undeclared, %d unparsed use, %d stale allowlist, %d unreachable; "
    . "abilities: %s\n",
    $namedCount,
    count($tools),
    count($undeclared),
    count($unparsedUses),
    count($allowlistFailures),
    count($warnings),
    $manifestSay
);

if ($failures || $warnings) {
    exit($failures || $strict ? 1 : 0);
}
echo "check-ability-drift: OK\n";
exit(0);
