#!/usr/bin/env bash
# Build the WordPress.org directory zip: dist/wpmcp-<version>.zip
#
# This is not the full plugin with a flag flipped. Guideline 5 forbids
# shipping functionality "restricted or locked, only to be made available by
# payment or upgrade", and recommends "add-on plugins, hosted outside of
# WordPress.org, in order to exclude the premium code", so the paid tier is
# physically removed here rather than gated at runtime:
#
#   * no licensing SDK and no licence check (src/Freemius, src/Pro)
#   * no paid predicate anywhere in the shipped PHP
#   * no quota that a payment lifts: snapshot retention is one flat,
#     filterable number for every install
#   * no eval() and no proc_open(): the two execution call sites are pro-tier
#     abilities and leave with the rest of the paid tier
#
# scripts/flavors/wporg/strip.php does the surgery and fails the build if any
# string it expects to rewrite has moved. The gates at the end of this script
# then re-check the result from scratch, so a strip that silently no-ops
# cannot produce a zip.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FLAVOR="$ROOT/scripts/flavors/wporg"
SLUG="wpmcp"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

# Snapshot the checkout before the build touches anything, so gate 7 can ask
# whether the BUILD dirtied it rather than whether it was dirty to begin with.
checkout_state() {
  if command -v git > /dev/null 2>&1 && git -C "$ROOT" rev-parse --git-dir > /dev/null 2>&1; then
    git -C "$ROOT" status --porcelain -- src "$SLUG.php"
  fi
}
CHECKOUT_BEFORE="$(checkout_state)"

STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$SLUG"
trap 'rm -rf "$STAGE_PARENT"' EXIT
mkdir -p "$STAGE"

cp "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"
sed "s/{{VERSION}}/$VERSION/g" "$FLAVOR/$SLUG.php" > "$STAGE/$SLUG.php"
sed "s/{{VERSION}}/$VERSION/g" "$FLAVOR/readme.txt" > "$STAGE/readme.txt"

php "$FLAVOR/strip.php" "$STAGE"

# The licensing SDK is a composer dependency of the full plugin, so it has to
# leave composer.json too, not just vendor/. File_Type_Check errors on a
# vendor/ directory with no composer.json beside it, so the pruned manifest
# ships (the lock file does not: it is a development artifact).
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
composer remove freemius/wordpress-sdk --working-dir="$STAGE" --update-no-dev --quiet --no-interaction
composer dump-autoload --working-dir="$STAGE" --optimize --classmap-authoritative --quiet --no-interaction
rm -f "$STAGE/composer.lock"

# ---------------------------------------------------------------- gates
# Each of these re-derives its answer from the staged tree. None of them
# trusts the strip script to have done its job.

fail() { echo "ERROR: $1" >&2; exit 1; }

# 1. Syntax. A textual transform that produces an unparsable file must never
#    reach a zip.
while IFS= read -r file; do
  php -l "$file" > /dev/null || fail "syntax error in $file"
done < <(find "$STAGE/src" "$STAGE/$SLUG.php" -name '*.php')

# 2. No execution construct, at token level so Malware_Audit's detection
#    patterns and ordinary comments cannot false-positive.
php -r '
$bad = [];
$names = ["proc_open", "shell_exec", "passthru", "popen", "exec", "system", "pcntl_exec", "create_function", "str_rot13", "move_uploaded_file", "assert"];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    $tokens = token_get_all(file_get_contents($f->getPathname()));
    foreach ($tokens as $i => $t) {
        if (!is_array($t)) { continue; }
        if ($t[0] === T_EVAL) { $bad[] = $f->getPathname() . ":" . $t[2] . " eval"; continue; }
        if ($t[0] !== T_STRING || !in_array(strtolower($t[1]), $names, true)) { continue; }
        // A method or property of the same name is not the global function.
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) { continue; }
        $bad[] = $f->getPathname() . ":" . $t[2] . " " . $t[1];
    }
}
if ($bad) { fwrite(STDERR, implode("\n", $bad) . "\n"); exit(1); }
' "$STAGE/src" || fail "an execution construct survived into the $SLUG build"

# The vocabulary these gates scan for, and the paths they assert are gone,
# come from scripts/flavors/wporg/policy.php, which is the same file the
# strip and tests/free/Platform/WporgStripTest.php read. Sharing the list is
# not trusting the strip: every gate below still re-derives its answer from
# the staged tree. What it removes is the drift where the release build and
# CI disagree about what counts as a finding. A malformed entry is a PHP
# error here rather than a silently skipped pattern.
policy() {
  php -r '
$policy = require $argv[1];
if (! isset($policy[$argv[2]]) || [] === $policy[$argv[2]]) {
    fwrite(STDERR, "policy.php declares nothing under " . $argv[2] . "\n");
    exit(1);
}
foreach ($policy[$argv[2]] as $entry) {
    if (! is_string($entry) || "" === trim($entry) || str_contains($entry, "\n")) {
        fwrite(STDERR, "policy.php: malformed entry under " . $argv[2] . "\n");
        exit(1);
    }
    echo $entry, "\n";
}
' "$FLAVOR/policy.php" "$1"
}

# 3. No paid predicate, no licensing SDK, no pro-tier ability. Text-level on
#    purpose: a docblock that still talks about licensing is also a finding,
#    because the reviewer reads those too.
PAID_SOURCE_PATTERNS="$(policy paid_source_patterns)" || fail "could not read the paid-source patterns from policy.php"
while IFS= read -r pattern; do
  [ -n "$pattern" ] || continue
  if grep -rqE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php"; then
    grep -rnE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php" >&2
    fail "paid/licensing surface \"$pattern\" survived into the $SLUG build"
  fi
done <<< "$PAID_SOURCE_PATTERNS"

# 3b. The same question asked of everything in the zip that is not PHP. The
#     bundled SKILL.md playbooks ship inside src/ and the agent reads them, so
#     a document promising that a capability unlocks with a licence is the same
#     guideline 5 and 9 finding as the code that used to enforce it. vendor/ is
#     out of scope: it is full of third-party licence files.
DOCUMENT_COPY_PATTERNS="$(policy document_copy_patterns)" || fail "could not read the document-copy patterns from policy.php"
while IFS= read -r pattern; do
  [ -n "$pattern" ] || continue
  if grep -rqE --exclude='*.php' -- "$pattern" "$STAGE/src"; then
    grep -rnE --exclude='*.php' -- "$pattern" "$STAGE/src" >&2
    fail "pay-to-unlock copy \"$pattern\" survived into the $SLUG build's documents"
  fi
done <<< "$DOCUMENT_COPY_PATTERNS"

# 3c. readme.txt gets its own, narrower list. Guideline 5 recommends
#     "add-on plugins, hosted outside of WordPress.org, in order to exclude
#     the premium code", so a factual pointer at the off-directory add-on is
#     the recommended remedy rather than a finding, and the required
#     "License: GPLv2 or later" header and the third-party image-licence URLs
#     have to survive. What may not appear is copy claiming something in THIS
#     download is withheld pending payment.
README_COPY_PATTERNS="$(policy readme_copy_patterns)" || fail "could not read the readme-copy patterns from policy.php"
while IFS= read -r pattern; do
  [ -n "$pattern" ] || continue
  if grep -qE -- "$pattern" "$STAGE/readme.txt"; then
    grep -nE -- "$pattern" "$STAGE/readme.txt" >&2
    fail "pay-to-unlock copy \"$pattern\" survived into the $SLUG build's readme"
  fi
done <<< "$README_COPY_PATTERNS"

# 3d. Pay-to-unlock copy inside PHP string literals, which is the copy the
#     agent is actually shown: an Ability description goes out in every
#     tools/list response, and is far more visible than the SKILL.md prose
#     3b covers. Gate 3's token patterns cannot see it (a description saying
#     a dialect is PRO carries no Gate::/is_pro token) and 3b skips PHP, so
#     this scans the string tokens on their own. Literals rather than lines,
#     so the docblocks that legitimately discuss third-party paid plugins
#     (WPML, Elementor Pro) are not swept in.
STRING_LITERAL_PATTERNS="$(policy string_literal_patterns)" || fail "could not read the string-literal patterns from policy.php"
php -r '
$patterns = array_filter(explode("\n", $argv[2]), static fn ($p) => "" !== trim($p));
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    foreach (token_get_all(file_get_contents($f->getPathname())) as $t) {
        if (! is_array($t)) { continue; }
        if (! in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) { continue; }
        foreach ($patterns as $pattern) {
            if (preg_match("/" . $pattern . "/", $t[1])) {
                $bad[] = $f->getPathname() . ":" . $t[2] . " matches " . $pattern;
            }
        }
    }
}
if ($bad) { fwrite(STDERR, implode("\n", array_unique($bad)) . "\n"); exit(1); }
' "$STAGE/src" "$STRING_LITERAL_PATTERNS" || fail "an agent-facing string in the $SLUG build advertises a paid tier"

if [ -d "$STAGE/vendor/freemius" ]; then fail "the licensing SDK is still vendored"; fi
if grep -q 'freemius' "$STAGE/composer.json"; then fail "composer.json still requires the licensing SDK"; fi

# 3e. Issue #159 definition of done, checked directly: the gate class and
#     everything else the strip claims to delete must be absent as paths, not
#     merely unreferenced. Read from the shared policy so the strip and the
#     assertion cannot drift apart. Run over the stage here and again over
#     the extracted zip below.
REMOVED_PATHS="$(policy removed_paths)" || fail "could not read the removed paths from policy.php"
case "$REMOVED_PATHS" in
  *src/Pro*) ;;
  *) fail "policy.php no longer removes src/Pro, which issue #159 requires" ;;
esac

assert_pruned() {
  local root="$1" label="$2" relative
  while IFS= read -r relative; do
    [ -n "$relative" ] || continue
    [ -e "$root/$relative" ] && fail "$relative is still in the $label"
  done <<< "$REMOVED_PATHS"
  return 0
}
assert_pruned "$STAGE" "$SLUG stage"

# 4. Every WPMCP class the shipped code names must still exist, so a file the
#    strip removed cannot leave a fatal behind. Resolved against composer's
#    authoritative classmap, which is the same map WordPress will autoload
#    from at runtime.
php -r '
$map = require $argv[1] . "/vendor/composer/autoload_classmap.php";
$known = [];
foreach (array_keys($map) as $class) { $known[strtolower($class)] = true; }
$missing = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1] . "/src"));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    $src = file_get_contents($f->getPathname());
    preg_match_all("/^use\s+(WPMCP\\\\[A-Za-z0-9_\\\\]+);/m", $src, $uses);
    preg_match_all("/new\s+(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)\s*\(/", $src, $news);
    // Static calls and ::class, which resolve at compile time and so slip
    // past a plain `new` scan.
    preg_match_all("/(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)::/", $src, $statics);
    // String callables, the shape add_action() takes. These fatal on the hook
    // rather than at load, which is worse, not better.
    preg_match_all("/[\x27\"]\\\\{0,2}(WPMCP(?:\\\\{1,2}[A-Za-z0-9_]+)+)[\x27\"]/", $src, $strings);
    $named = array_merge($uses[1], $news[1], $statics[1], array_map(
        static fn ($c) => str_replace("\\\\", "\\", $c),
        $strings[1]
    ));
    foreach ($named as $class) {
        $class = ltrim($class, "\\");
        if (!isset($known[strtolower($class)])) { $missing[] = $f->getPathname() . " -> " . $class; }
    }
}
if ($missing) { fwrite(STDERR, implode("\n", array_unique($missing)) . "\n"); exit(1); }
' "$STAGE" || fail "the $SLUG build names a class it does not ship"

# 5. Packaging hygiene: no dotfiles, no development directories, no build
#    scripts. File_Type_Check errors on all three, and ".sh" is on its
#    application-file list, so this script must never be inside its own zip.
find "$STAGE" -name '.*' -not -name '.' -not -path "$STAGE" -print0 | xargs -0 rm -rf
for unwanted in tests test node_modules .github scripts; do
  [ -e "$STAGE/$unwanted" ] && fail "$unwanted must not be in the zip"
done
true

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

# 6. The compliance engine, in the profile that models the directory, run
#    against the extracted zip rather than the checkout. This is the check
#    that decides whether the artifact is submittable.
BUILD_DIR="$ROOT/build/wporg"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
unzip -q "$ZIP" -d "$BUILD_DIR"
# The definition of done is worded about the zip, not the checkout, and the
# zip step selects its own files, so the path assertions are re-run here
# against what a reviewer would actually download.
assert_pruned "$BUILD_DIR/$SLUG" "$SLUG zip"
php "$ROOT/tools/compliance/bin/compliance.php" \
  --profile=wporg-free --artifact --path="$BUILD_DIR/$SLUG" \
  || fail "the compliance engine found blockers in $ZIP"

# 7. The build works in a throwaway stage and must never write to the
#    checkout: the ability counts pinned by tests/support/ability-manifest.php
#    (and asserted every CI run by AbilityManifestTest, which reads the
#    checkout) are only trustworthy if this script left src/ alone. Comparing
#    a before/after snapshot rather than testing for a clean tree is
#    deliberate: a dirty checkout is the normal state of a developer machine,
#    and failing on someone's unrelated work in progress would say "the build
#    modified the checkout" about something the build never touched.
CHECKOUT_AFTER="$(checkout_state)"
if [ "$CHECKOUT_BEFORE" != "$CHECKOUT_AFTER" ]; then
  printf 'before:\n%s\nafter:\n%s\n' "$CHECKOUT_BEFORE" "$CHECKOUT_AFTER" >&2
  fail "the $SLUG build modified the checkout under src/; the full and pro ability counts are no longer trustworthy"
fi

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
