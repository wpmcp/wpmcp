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

# Ship the translation directory the Domain Path header points at (issue #184).
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;
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
#    patterns and ordinary comments cannot false-positive. Walks src, vendor
#    and the flavor main file, not just src: a dependency that grows an
#    exec call site is just as much a rejection as our own code (#167).
# Exit 1 is a surviving construct, exit 2 is the gate not being able to do
# its job at all (a path that was never staged, an unreadable tree). Those are
# different bugs and must not report as the same one.
set +e
php "$ROOT/scripts/lib/exec-gate.php" "$STAGE/src" "$STAGE/vendor" "$STAGE/$SLUG.php"
gate_status=$?
set -e
case "$gate_status" in
  0) ;;
  1) fail "a banned execution or obfuscation construct survived into the $SLUG build" ;;
  2) fail "the exec gate was pointed at a path that was never staged or cannot be read" ;;
  *) fail "the exec gate failed unexpectedly (exit $gate_status)" ;;
esac

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

# 3f. No pay-to-unlock PROSE in PHP. Gate 3's patterns are identifiers, and a
#     reviewer reads sentences: a docblock or a UI string promising that
#     something is withheld pending payment is a guideline 9 finding even
#     when no paid predicate is left to enforce it. This is the gate that
#     would have caught an unstripped class comment describing the two
#     builds, which no identifier grep matches.
for phrase in 'pay.to.unlock' '[Pp]ro licen[cs]e' 'premium licen[cs]e' '[Uu]pgrade to [Pp]ro' 'upsell' 'build we sell' ; do
  if grep -rqE --include='*.php' -- "$phrase" "$STAGE/src" "$STAGE/$SLUG.php"; then
    grep -rnE --include='*.php' -- "$phrase" "$STAGE/src" "$STAGE/$SLUG.php" >&2
    fail "pay-to-unlock copy \"$phrase\" survived into the $SLUG build"
  fi
done

if [ -d "$STAGE/vendor/freemius" ]; then fail "the licensing SDK is still vendored"; fi

# 3b. The free-tier invariants, re-derived from the staged tree by the same
#     script tests/free/WporgStripTest.php runs. One copy on purpose: the
#     scanner used to be duplicated here and in the test, so the copy CI
#     proved non-vacuous was not the copy that blocked a release, and the two
#     had already drifted. It covers the registrar naming no tier, every
#     constructed Ability being literally 'free', no shipped file (PHP,
#     markdown or readme.txt) claiming licence gating, no reference to a
#     withheld registration method, and no orphaned private register_*
#     method holding pruned handlers in the zip.
#
#     The drift-guard manifest is passed too, so the ability SET is checked
#     and not only the tiers: no ability the manifest tiers as paid may
#     survive under any name, and no free one may go missing because an edit
#     took out more than it meant to. The manifest is read from the checkout,
#     never from the stage, and tests/ is not in the zip.
php "$FLAVOR/assert-free-tier.php" "$STAGE" "$ROOT/tests/support/ability-manifest.php" \
  || fail "the $SLUG build fails the free-tier invariants"

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

# 3g. No ability the manifest tiers 'pro' may be REGISTERED in this build.
#     Derived, not hardcoded: the forbidden set is every 'pro' entry in
#     tests/support/ability-manifest.php, so pruning the next paid ability
#     needs no new gate here. Registration is the invariant, not string
#     mentions: a comment or an opt-in guard may still name an ability that is
#     not in this build (Governance/Opt_In_Gates.php deliberately does), but
#     nothing may hand one to the MCP surface. This is the manifest-level half
#     of issue #163's definition of done, and it covers finding B-07
#     (insert-stock-image) the same way it covers the other 90.
php -r '
$manifest = require $argv[2];
$pro = array_filter($manifest["abilities"], static fn ($t) => "pro" === $t);
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1] . "/src"));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    preg_match_all("/new\\s+Ability\\(\\s*\\n\\s*[\\x27\"]([^\\x27\"]+)[\\x27\"]/", file_get_contents($f->getPathname()), $m);
    foreach ($m[1] as $name) {
        if (isset($pro[$name])) { $bad[] = $f->getPathname() . " registers " . $name; }
    }
}
if (!$bad) { exit(0); }
fwrite(STDERR, implode("\n", array_unique($bad)) . "\n");
exit(1);
' "$STAGE" "$ROOT/tests/support/ability-manifest.php" \
  || fail "a pro-tier ability is still registered in the $SLUG build"

# 3h. Every ability named in a document this build SHIPS must be an ability
#     this build registers. readme.txt and the bundled SKILL.md library are
#     read by the reviewer and acted on by the agent, so a document naming a
#     tool that is not here is either a paid upsell (guideline 9) or a broken
#     instruction. Derived too: no per-ability list to maintain.
php -r '
$stage = $argv[1];
$registered = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage . "/src"));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    preg_match_all("/new\\s+Ability\\(\\s*\\n\\s*[\\x27\"]([^\\x27\"]+)[\\x27\"]/", file_get_contents($f->getPathname()), $m);
    foreach ($m[1] as $name) { $registered[$name] = true; }
}
$docs = array_merge(
    (array) glob($stage . "/src/Skills/library/*/SKILL.md"),
    (array) glob($stage . "/src/Skills/library/*/*/SKILL.md"),
    [$stage . "/readme.txt"]
);
$bad = [];
foreach ($docs as $doc) {
    if (!is_file($doc)) { continue; }
    preg_match_all("~wpmcp/[a-z0-9-]+~", file_get_contents($doc), $m);
    foreach (array_unique($m[0]) as $name) {
        if (!isset($registered[$name])) { $bad[] = $doc . " documents " . $name; }
    }
}
if (!$bad) { exit(0); }
fwrite(STDERR, implode("\n", array_unique($bad)) . "\n");
exit(1);
' "$STAGE" || fail "a shipped document names an ability the $SLUG build does not register"


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

# 4b. Compatibility headers, re-derived from the staged files rather than
#     from the checkout the strip ran over. `Tested up to` is a Plugin Check
#     error when it trails the current release and the plugin then stops
#     appearing in directory search (issue #172, finding B-23). The values in
#     the zip must equal the value the repository declares, and the repository
#     value is the one tests/free/Release/ReleaseHeadersTest.php pins.
#     Each capture is stripped of a trailing CR and trailing whitespace before
#     the comparison, the way the PHP test trims, so two values that print the
#     same cannot fail the equality for an invisible reason; the numeric check
#     then runs on the trimmed value.
header_value() { tr -d '\r' | sed 's/[[:space:]]*$//' | head -1; }
readme_tested="$(sed -n 's/^Tested up to:[[:space:]]*//p' "$STAGE/readme.txt" | header_value)"
loader_tested="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Tested up to:[[:space:]]*//p' "$STAGE/$SLUG.php" | header_value)"
root_tested="$(sed -n 's/^Tested up to:[[:space:]]*//p' "$ROOT/readme.txt" | header_value)"

[ -n "$readme_tested" ] || fail "the staged readme.txt has no Tested up to header"
[ -n "$loader_tested" ] || fail "the staged $SLUG.php has no Tested up to header"
[ -n "$root_tested" ] || fail "the repository readme.txt has no Tested up to header"
echo "$readme_tested" | grep -Eq '^[0-9]+(\.[0-9]+)*$' || fail "staged readme.txt Tested up to \"$readme_tested\" must be numbers only"
echo "$loader_tested" | grep -Eq '^[0-9]+(\.[0-9]+)*$' || fail "staged $SLUG.php Tested up to \"$loader_tested\" must be numbers only"
[ "$readme_tested" = "$loader_tested" ] || fail "staged readme.txt says Tested up to $readme_tested and $SLUG.php says $loader_tested"
[ "$readme_tested" = "$root_tested" ] || fail "the zip declares Tested up to $readme_tested and the repository readme.txt declares $root_tested"

staged_stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$STAGE/readme.txt" | head -1)"
[ "$staged_stable" = "$VERSION" ] || fail "staged Stable tag $staged_stable does not equal WPMCP_VERSION $VERSION"

# 4c. The same tree read structurally rather than through the classmap: every
#     WPMCP class the staged code names is declared under src/ (including the
#     aliased, grouped and string-callable forms), no tool class survived the
#     strip with no reference path left into it, and the abilities the stage
#     still registers carry the names and tiers the manifest pins. Gate 4
#     answers "does it autoload"; this answers "is it still wired".
#
#     The manifest is passed explicitly because the stage ships no tests/
#     directory (gate 5 below fails if it does), so the ability third would
#     otherwise silently skip and this gate would report success for a check
#     it never ran. Under --strict a missing manifest is a hard failure, so a
#     path typo here fails loudly rather than degrading.
php "$ROOT/bin/check-ability-drift.php" --strict \
  --manifest "$ROOT/tests/support/ability-manifest.php" "$STAGE" \
  || fail "registration drift in the $SLUG build"

#    scripts. File_Type_Check errors on all three, and ".sh" is on its
#    application-file list, so this script must never be inside its own zip.
find "$STAGE" -name '.*' -not -name '.' -not -path "$STAGE" -print0 | xargs -0 rm -rf
for unwanted in tests test node_modules .github scripts; do
  [ -e "$STAGE/$unwanted" ] && fail "$unwanted must not be in the zip"
done
true

# 5. The coexistence guard. src/flavor-guard.php is global functions, not a
#    class, so gate 4's classmap walk cannot see it; a prune that dropped it
#    would fatal at plugin load. The main file must both load it and carry the
#    WPMCP Flavor header the guard ranks by.
[ -f "$STAGE/src/flavor-guard.php" ] || fail "src/flavor-guard.php missing from the $SLUG build"
grep -q "flavor-guard.php" "$STAGE/$SLUG.php" || fail "$SLUG.php does not load the flavor coexistence guard"
grep -q "^ \* WPMCP Flavor: wporg$" "$STAGE/$SLUG.php" || fail "$SLUG.php does not declare the wporg flavor header"

# 6. Packaging hygiene: no dotfiles, no development directories, no build

# 6a. No updater surface anywhere in what is about to be zipped, vendor/
#    included. Gate 3 already fails on a surviving vendor/freemius or a
#    composer.json that still requires the SDK, so for Freemius this is
#    belt and braces; what it adds is coverage of any *other* dependency
#    that ships an updater, which nothing upstream of here would notice.
#    The pattern list is Updater_Rule::UPDATER_PATTERNS, read at run time so
#    the gate and the compliance engine cannot drift; the Update URI header
#    arm of the same rule is covered by gate 7. Runs after the packaging
#    prune, so the tree it scans is the zip's contents.
bash "$ROOT/scripts/lib/updater-gate.sh" "$STAGE" \
  || fail "an updater surface survived into the $SLUG build"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

# 6b. The execution files themselves are absent from the artifact, checked on
#     the zip listing rather than on the staging directory: the strip list,
#     the staging copy and the zip step are three chances to reintroduce them
#     (#167). Shared with the vertical build (scripts/lib/zip-gate.sh) so the
#     two cannot drift, and read from a captured listing rather than a
#     pipeline, see the note there.
# shellcheck source=scripts/lib/zip-gate.sh
. "$ROOT/scripts/lib/zip-gate.sh"
set +e
zip_excludes "$ZIP" Php_Snippet_Runner.php Wp_Cli_Executor.php Run_Php_Snippet.php Run_Wp_Cli.php
zip_status=$?
set -e
case "$zip_status" in
  0) ;;
  1) fail "an execution file is inside $ZIP" ;;
  *) fail "could not list $ZIP to check it for execution files" ;;
esac

# 7. The compliance engine, in the profile that models the directory, run
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
