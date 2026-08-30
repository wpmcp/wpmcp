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

# 3. No paid predicate, no licensing SDK, no pro-tier ability. Text-level on
#    purpose: a docblock that still talks about licensing is also a finding,
#    because the reviewer reads those too.
#    pro_locked and the 'tier' => 'pro' error payload are here because they
#    live in files the strip edits in place rather than deletes: if one of
#    those edits drifted while the others still applied, no Gate::/is_pro
#    token would be left on the surviving line to catch it.
for pattern in 'Pro\\Gate' '\bis_pro\b' '\bGate::' '\bpro_active\b' '\bpro_locked\b' 'can_use_premium_code' '[Ff]reemius' 'WPMCP_FS_' 'fs_dynamic_init' "^\s*'pro',\s*$" "'tier'\s*=>\s*'pro'" ; do
  if grep -rqE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php"; then
    grep -rnE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php" >&2
    fail "paid/licensing surface \"$pattern\" survived into the $SLUG build"
  fi
done

# 3b. The same question asked of everything in the zip that is not PHP. The
#     bundled SKILL.md playbooks ship inside src/ and the agent reads them, so
#     a document promising that a capability unlocks with a licence is the same
#     guideline 5 and 9 finding as the code that used to enforce it. Scoped to
#     src/ and readme.txt on purpose: vendor/ is full of third-party licence
#     files, and readme.txt's own "License: GPLv2 or later" header is required.
for pattern in 'pro licen[sc]e' 'pro[ -]tier' '[Pp]remium' 'unlicensed' 'needs? an active .* [Ll]icense' ; do
  if grep -rqE --exclude='*.php' -- "$pattern" "$STAGE/src" "$STAGE/readme.txt"; then
    grep -rnE --exclude='*.php' -- "$pattern" "$STAGE/src" "$STAGE/readme.txt" >&2
    fail "pay-to-unlock copy \"$pattern\" survived into the $SLUG build's documents"
  fi
done

if [ -d "$STAGE/vendor/freemius" ]; then fail "the licensing SDK is still vendored"; fi
if grep -q 'freemius' "$STAGE/composer.json"; then fail "composer.json still requires the licensing SDK"; fi

# 3c. Issue #159 definition of done, checked directly: the gate class and
#     everything else the strip claims to delete must be absent as paths, not
#     merely unreferenced. Read straight out of the strip's own REMOVED_PATHS
#     so the two lists cannot drift apart, and because remove_path() ignores
#     the return value of unlink()/rmdir(), which makes a partial removal
#     silent. Run over the stage here and again over the extracted zip below.
REMOVED_PATHS="$(php -r '
$src = file_get_contents($argv[1]);
preg_match("/const REMOVED_PATHS = \[(.*?)\n\];/s", $src, $m) || exit(1);
preg_match_all("/^\s*\x27([^\x27]+)\x27,\s*$/m", $m[1], $p);
echo implode("\n", $p[1]), "\n";
' "$FLAVOR/strip.php")" || fail "could not read REMOVED_PATHS out of the strip script"
[ -n "$REMOVED_PATHS" ] || fail "the strip script declares no removed paths"

assert_pruned() {
  local root="$1" label="$2" relative
  [ -e "$root/src/Pro/Gate.php" ] && fail "src/Pro/Gate.php is still in the $label"
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

# 7. The last definition-of-done bullet for issue #159 is that the full and pro
#    builds still produce their current ability counts. Those counts are pinned
#    by tests/support/ability-manifest.php and asserted on every CI run by
#    tests/free/Platform/AbilityManifestTest.php, which reads the checkout. The
#    only way this build could move them is by editing the checkout instead of
#    the throwaway stage, so that is what gets checked here.
if command -v git > /dev/null 2>&1 && git -C "$ROOT" rev-parse --git-dir > /dev/null 2>&1; then
  if [ -n "$(git -C "$ROOT" status --porcelain -- src wpmcp.php)" ]; then
    git -C "$ROOT" status --porcelain -- src wpmcp.php >&2
    fail "the $SLUG build modified the checkout; the full and pro ability counts are no longer trustworthy"
  fi
fi

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
