<?php
/**
 * Re-derive the free-tier invariants from a staged (or extracted) plugin tree.
 *
 * This is the enforcing copy. build-wporg-release.sh runs it as a build gate
 * and tests/free/WporgStripTest.php shells out to the same file, so the thing
 * CI proves non-vacuous is the same thing that blocks a release. Duplicating
 * the scanner in the test was the previous shape and the two had already
 * drifted.
 *
 * Nothing here trusts scripts/flavors/wporg/strip.php to have done its job:
 * every answer is computed from the tree that is about to be zipped.
 *
 *   php scripts/flavors/wporg/assert-free-tier.php <staged-plugin-dir> [manifest]
 *
 * The optional manifest is tests/support/ability-manifest.php: given it, the
 * ability SET is checked as well as the ability tiers, which is the half gate
 * 3c cannot do on its own (it proves nothing paid survived, not that nothing
 * free went missing).
 *
 * Exit 0 clean, 1 with one finding per line on stderr, 2 on bad usage.
 */

declare(strict_types=1);

$stage = $argv[1] ?? '';
if ('' === $stage || ! is_dir($stage)) {
    fwrite(STDERR, "usage: assert-free-tier.php <staged-plugin-dir>\n");
    exit(2);
}
$stage = rtrim($stage, '/');

$manifest_path = $argv[2] ?? '';
if ('' !== $manifest_path && ! is_file($manifest_path)) {
    fwrite(STDERR, "manifest not found: $manifest_path\n");
    exit(2);
}

/**
 * Prose that describes licence- or tier-dependent behaviour of THIS plugin.
 *
 * Every one of these statements is false of the directory build, and
 * guideline 9 treats copy that implies a paid unlock as a finding in its own
 * right. The patterns are chosen to have no innocent reading: the GPL header,
 * the stock-image `license` fields, "Elementor Pro" as a third-party plugin
 * name, the "WPML is a paid plugin" notes and Paid Memberships Pro all fail
 * to match. `PRO` is deliberately case-sensitive for that reason.
 *
 * @var array<string, string>
 */
const FALSE_LICENSING_CLAIMS = [
    'pro tier'          => '/pro[- ]tier/i',
    'pro gate'          => '/pro[- ]gat(e|ing)/i',
    'pro licence'       => '/pro[- ]licen[cs]e/i',
    'unlicensed'        => '/unlicensed/i',
    'without a licence' => '/without a (live )?licen[cs]e/i',
    'a licence lapsing' => '/licen[cs]e (that )?laps/i',
    'payment unlocking' => '/payment to unlock/i',
    'a licence gate'    => '/licen[cs]e gate/i',
    'a PRO marker'      => '/\(PRO\)|\bPRO\b/',
];

/**
 * Method declarations scripts/flavors/wporg/strip.php deletes from Plugin.php.
 * Kept here as literal text rather than imported, because the point of this
 * file is to not trust the strip: a name that still appears anywhere in the
 * shipped tree is either a dangling call or a docblock pointing a reviewer at
 * code the zip does not contain.
 */
const WITHHELD_METHODS = [
    'register_builder_abilities',
    'register_analysis_abilities',
    'register_cli_abilities',
    'register_cli_job_abilities',
    'register_global_class_write_abilities',
    'register_php_exec_abilities',
    'register_widget_builder_abilities',
    'register_block_builder_abilities',
    'register_cloud_abilities',
    'register_elementor_pro_abilities',
    'register_elementor_structural_abilities',
    'register_brand_kit_abilities',
    'register_memory_abilities',
];

/** Directories never inspected: third-party code is not this build's prose. */
const SKIPPED_DIRECTORIES = ['vendor', 'node_modules'];

$findings = [];

// ------------------------------------------------------------------- files
$php = shipped_files($stage, ['php']);
$prose = shipped_files($stage, ['php', 'md', 'txt']);

if ([] === $php) {
    fwrite(STDERR, "no PHP files under $stage: refusing to report a clean tree\n");
    exit(1);
}

// 1. The registrar must not branch on, or even mention, ability tiers.
$registrar = $stage . '/src/MCP/Registrar.php';
if (! is_file($registrar)) {
    $findings[] = 'src/MCP/Registrar.php is missing from the build';
} else {
    foreach (file($registrar) ?: [] as $i => $line) {
        if (false !== stripos($line, 'tier')) {
            $findings[] = sprintf('src/MCP/Registrar.php:%d still names a tier: %s', $i + 1, trim($line));
        }
    }
}

// 2. Every Ability the build constructs is free, at token level. Deleting the
//    registrar's runtime tier refusal removed the last backstop, and a text
//    scan for "'pro'," only sees tiers written as source literals: a computed,
//    inherited or overridden tier is exactly what this has to catch.
foreach ($php as $file) {
    foreach (ability_tiers($file) as [$line, $tier]) {
        if ("'free'" === $tier) {
            continue;
        }
        $findings[] = sprintf(
            '%s:%d constructs an Ability whose tier is %s, not the literal \'free\'',
            relative($stage, $file),
            $line,
            null === $tier ? 'an argument list this gate could not read' : $tier
        );
    }
}

// 3. No shipped file may claim licence gating. Not PHP-only: get-skill returns
//    the bundled markdown bodies verbatim to the connecting client, and
//    readme.txt is the first thing a directory reviewer reads.
foreach ($prose as $file) {
    foreach (file($file) ?: [] as $i => $line) {
        foreach (FALSE_LICENSING_CLAIMS as $label => $pattern) {
            if (preg_match($pattern, $line)) {
                $findings[] = sprintf(
                    '%s:%d describes %s this build does not have: %s',
                    relative($stage, $file),
                    $i + 1,
                    $label,
                    trim($line)
                );
            }
        }
    }
}

// 4. Nothing in the tree may name a withheld registration method, whether as a
//    live call, a dead private declaration or a docblock cross-reference.
foreach ($php as $file) {
    foreach (file($file) ?: [] as $i => $line) {
        foreach (WITHHELD_METHODS as $method) {
            if (str_contains($line, $method)) {
                $findings[] = sprintf(
                    '%s:%d names %s(), which this build does not ship',
                    relative($stage, $file),
                    $i + 1,
                    $method
                );
            }
        }
    }
}

// 5. No private register_*_abilities() may survive without a caller. That is
//    the shape a pruned group leaves behind, and it keeps every class it
//    instantiates out of the unreferenced-file sweep, so the paid handlers
//    stay in the zip while being unreachable: guideline 5 asks for the code to
//    be excluded, not merely unreachable.
foreach ($php as $file) {
    $src = (string) file_get_contents($file);
    preg_match_all('/private function (register_[A-Za-z0-9_]*abilities)\s*\(/', $src, $declared, PREG_OFFSET_CAPTURE);
    foreach ($declared[1] as [$name, $_offset]) {
        if (preg_match('/(\$this->|self::|static::)' . preg_quote($name, '/') . '\s*\(/', $src)) {
            continue;
        }
        $findings[] = sprintf(
            '%s declares private %s() with no caller: its instantiations hold pruned code in the zip',
            relative($stage, $file),
            $name
        );
    }
}

// 6. The ability SET, against the drift-guard manifest. Gate 2 proves every
//    ability the build constructs is free; that is the safety half. This is
//    the other half of the issue's definition of done: no ability the manifest
//    tiers as paid may survive under any name, and no free one may go missing
//    because a strip edit took out more than it meant to.
if ('' !== $manifest_path) {
    $manifest = require $manifest_path;
    $tiers = $manifest['abilities'] ?? [];
    $registered = registered_ability_names($php);

    foreach ($tiers as $name => $tier) {
        if ('free' === $tier) {
            if (! isset($registered[$name])) {
                $findings[] = sprintf('%s is free in the manifest but the build registers no such ability', $name);
            }
            continue;
        }
        if (isset($registered[$name])) {
            $findings[] = sprintf('%s is a paid ability in the manifest and the build still registers it', $name);
        }
    }

    // Deliberately NOT an equality check on the counts. The manifest pins one
    // environment (single site, with the optional test plugins present), so
    // the three multisite-only abilities are absent from it while being
    // present in the source of every build. The two directional checks above
    // are the part that is true independent of the environment: nothing paid
    // survived, and nothing free went missing.
}

if ([] !== $findings) {
    fwrite(STDERR, implode("\n", array_unique($findings)) . "\n");
    exit(1);
}

exit(0);

// ---------------------------------------------------------------- helpers

/**
 * Every shipped file with one of the given extensions, third-party
 * directories excluded.
 *
 * @param string[] $extensions
 * @return string[]
 */
function shipped_files(string $stage, array $extensions): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            static fn ($current) => ! $current->isDir()
                || ! in_array($current->getFilename(), SKIPPED_DIRECTORIES, true)
        )
    );
    foreach ($it as $file) {
        if (in_array(strtolower($file->getExtension()), $extensions, true)) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

function relative(string $stage, string $file): string
{
    return ltrim(substr($file, strlen($stage)), '/');
}

/**
 * Tier argument of every `new Ability(...)` in one file, as source text.
 *
 * Token-level, so a comment or a string naming the class cannot produce a hit
 * and a computed tier is reported as what it is. An argument list this cannot
 * read yields null rather than being skipped: silently passing over a call
 * shape the parser does not handle is how a tier expression escapes a gate
 * that is the only remaining backstop.
 *
 * @return array<int, array{0: int, 1: string|null}> [line, tier or null]
 */
function ability_tiers(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);
    $found = [];

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || T_NEW !== $tokens[$i][0]) {
            continue;
        }
        $name = '';
        $j = $i + 1;
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && T_WHITESPACE === $t[0]) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $t[1];
                continue;
            }
            break;
        }
        $short = strrchr($name, '\\');
        if ('Ability' !== (false === $short ? $name : substr($short, 1))) {
            continue;
        }
        if (! isset($tokens[$j]) || '(' !== $tokens[$j]) {
            continue;
        }

        $found[] = [$tokens[$i][2], tier_argument(split_arguments($tokens, $j))];
    }

    return $found;
}

/**
 * The tier from a split argument list: the named `tier:` argument if the call
 * uses one, otherwise the second positional argument.
 *
 * @param string[]|null $args
 */
function tier_argument(?array $args): ?string
{
    if (null === $args) {
        return null;
    }
    foreach ($args as $arg) {
        if (preg_match('/^tier\s*:\s*(.+)$/s', $arg, $m)) {
            return trim($m[1]);
        }
    }
    if (count($args) < 2) {
        return null;
    }
    // A named argument anywhere before position 2 makes positions meaningless.
    foreach (array_slice($args, 0, 2) as $arg) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)/', $arg)) {
            return null;
        }
    }

    return $args[1];
}

/**
 * Split the argument list whose '(' sits at $open into trimmed source strings,
 * one per top-level comma.
 *
 * Returns null when the list does not close, so an unreadable call is a
 * finding rather than a silent skip. The interpolation and attribute tokens
 * are counted explicitly: T_DOLLAR_OPEN_CURLY_BRACES has the text '${' and
 * T_ATTRIBUTE the text '#[', so a text-only depth counter never increments for
 * them while their matching '}' / ']' still decrements, which drives depth to
 * zero early and truncates the list.
 *
 * @param array<int, array|string> $tokens
 * @return string[]|null
 */
function split_arguments(array $tokens, int $open): ?array
{
    static $extra_openers = null;
    if (null === $extra_openers) {
        $extra_openers = [];
        foreach (['T_DOLLAR_OPEN_CURLY_BRACES', 'T_ATTRIBUTE'] as $constant) {
            if (defined($constant)) {
                $extra_openers[] = constant($constant);
            }
        }
    }

    $depth = 0;
    $args = [];
    $current = '';
    $count = count($tokens);

    for ($i = $open; $i < $count; $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        $id = is_array($t) ? $t[0] : null;

        // T_CURLY_OPEN already carries the text '{', so the text test covers
        // it; only the tokens whose text is not a bare bracket need the id.
        if (in_array($text, ['(', '[', '{'], true) || (null !== $id && in_array($id, $extra_openers, true))) {
            $depth++;
            if (1 === $depth) {
                continue;
            }
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
            if (0 === $depth) {
                $args[] = trim($current);
                return $args;
            }
        } elseif (',' === $text && 1 === $depth) {
            $args[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $text;
    }

    return null;
}

/**
 * Every ability name the build registers.
 *
 * Two shapes: the literal first constructor argument, and the
 * `wpmcp/{slug}-read` / `-write` pair Integration_Dispatcher builds from each
 * integration's own `integration()` slug. The pairs are assembled at runtime
 * from a string this reads statically, which is why a plain literal scan
 * reports 32 free abilities missing that are in fact registered.
 *
 * @param string[] $php
 * @return array<string, true>
 */
function registered_ability_names(array $php): array
{
    $names = [];
    foreach ($php as $file) {
        $src = (string) file_get_contents($file);
        preg_match_all("/new Ability\(\s*'(wpmcp\/[a-z0-9-]+)'/", $src, $literal);
        foreach ($literal[1] as $name) {
            $names[$name] = true;
        }
        if (preg_match("/function integration\(\): string\s*\{\s*return '([a-z0-9-]+)';/", $src, $slug)) {
            $names['wpmcp/' . $slug[1] . '-read'] = true;
            $names['wpmcp/' . $slug[1] . '-write'] = true;
        }
    }

    return $names;
}
