<?php
/**
 * Rewrite the 'wpmcp' i18n text domain in a staged src/ to the flavor slug.
 *
 *   php scripts/flavors/woocommerce/text-domain.php <staged-plugin-dir> <slug>
 *
 * wp.org's text-domain sniff wants the i18n domain to match the slug. Two
 * forms occur: the domain inline as the last argument, and the domain alone
 * on its own line as the last argument of a wrapped i18n call. The second
 * form is matched by "own line, no trailing comma", which is what separates
 * it from the admin menu slug argument (src/Plugin.php), also the literal
 * 'wpmcp' but always followed by a comma. The menu slug is deliberately left
 * alone: it is a WordPress-derived identifier that screen ids are built from
 * (see Admin/Announcements.php), so rewriting it breaks those lookups.
 *
 * src/flavor-guard.php is excluded. It carries no i18n call (the stand-down
 * notice lives in the main file, which is staged from scripts/flavors/ with
 * the right domain already), but it does compare active plugin basenames
 * against the literal 'wpmcp' as a filename prefix, and that argument matches
 * the inline form. Rewriting it made the guard skip every sibling named
 * wpmcp.php, so the vertical never deferred to the full plugin and the full
 * plugin was the one that stood down. build-woo-release.sh pins the shipped
 * guard to the source tree byte for byte.
 *
 * This used to be two sed expressions and a grep inline in the build script.
 * It is a file of its own so tests/free/Compliance/FlavorBuildBlockersTest.php
 * can stage the vertical exactly as the build does (issue #257): the engine
 * reports a mismatched domain as a blocker, so a test that skipped this step
 * would pin a tree the build never produces.
 *
 * Exit 0 on success, 1 when a 'wpmcp' domain survived the rewrite (one
 * location per line on stderr), 2 on bad usage.
 */

declare(strict_types=1);

$stage = rtrim($argv[1] ?? '', '/');
$slug  = $argv[2] ?? '';
if ('' === $stage || ! is_dir($stage . '/src') || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
    fwrite(STDERR, "usage: text-domain.php <staged-plugin-dir> <slug>\n");
    exit(2);
}

/** Line-based, exactly as the sed expressions it replaces: a match never spans a newline. */
$rewrites = [
    "/, 'wpmcp' \\)/"             => ", '$slug' )",
    "/, 'wpmcp'\\)/"              => ", '$slug')",
    "/^([^\\S\\n]*)'wpmcp'$/m"    => "\$1'$slug'",
];
/**
 * Belt and braces: any i18n call that kept the 'wpmcp' domain. The rewrites
 * above are line-based, so a future call wrapped differently would silently
 * ship the wrong domain and fail the wp.org sniff instead.
 */
$leftover = "/(^[^\\S\\n]*'wpmcp'[^\\S\\n]*$)|(, ?'wpmcp' ?\\))/m";

$survivors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ('php' !== $file->getExtension() || 'flavor-guard.php' === $file->getFilename()) {
        continue;
    }
    $path     = $file->getPathname();
    $original = (string) file_get_contents($path);
    $contents = (string) preg_replace(array_keys($rewrites), array_values($rewrites), $original);
    if ($contents !== $original && false === file_put_contents($path, $contents)) {
        fwrite(STDERR, "could not write $path\n");
        exit(1);
    }
    if (preg_match_all($leftover, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$text, $offset]) {
            $survivors[] = sprintf('%s:%d: %s', $path, substr_count($contents, "\n", 0, $offset) + 1, trim($text));
        }
    }
}

if ([] !== $survivors) {
    fwrite(STDERR, "'wpmcp' text domain survived the rewrite:\n" . implode("\n", $survivors) . "\n");
    exit(1);
}
