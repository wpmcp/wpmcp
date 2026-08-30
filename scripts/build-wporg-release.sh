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
for pattern in 'Pro\\Gate' '\bis_pro\b' '\bGate::' 'can_use_premium_code' '[Ff]reemius' 'WPMCP_FS_' 'fs_dynamic_init' "^\s*'pro',\s*$" ; do
  if grep -rqE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php"; then
    grep -rnE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php" >&2
    fail "paid/licensing surface \"$pattern\" survived into the $SLUG build"
  fi
done
if [ -d "$STAGE/vendor/freemius" ]; then fail "the licensing SDK is still vendored"; fi

# 3b. The registrar itself must not branch on (or even mention) ability tiers
#     in this build (issue #160). This covers exactly one file: the wider
#     invariant is gate 3c's job, and the prose is gate 3d's. grep exits 2 on
#     a missing file and `if` reads that as "clean", so the file's existence
#     is asserted first rather than assumed.
[ -f "$STAGE/src/MCP/Registrar.php" ] || fail "Registrar.php is missing from the $SLUG build"
if grep -qi 'tier' "$STAGE/src/MCP/Registrar.php"; then
  grep -ni 'tier' "$STAGE/src/MCP/Registrar.php" >&2
  fail "Registrar.php still references ability tiers in the $SLUG build"
fi

# 3c. Every Ability this build constructs is free, at token level. Deleting
#     the registrar's runtime tier refusal removed the last backstop, and the
#     "'pro'," text scan in gate 3 only sees tiers written as source literals:
#     Integration_Dispatcher used to pass a computed tier() into three Ability
#     constructors, which no literal scan could ever read. This gate asserts
#     the invariant instead of the vocabulary, so a tier that is computed,
#     inherited or overridden fails the build rather than shipping.
php -r '
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    $tokens = token_get_all(file_get_contents($f->getPathname()));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) { continue; }
        $name = ""; $j = $i + 1;
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) { continue; }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) { $name .= $t[1]; continue; }
            break;
        }
        $short = strrchr($name, "\\");
        if (($short === false ? $name : substr($short, 1)) !== "Ability") { continue; }
        if (!isset($tokens[$j]) || $tokens[$j] !== "(") { continue; }
        // Second top-level argument of the constructor call.
        $depth = 0; $args = []; $cur = "";
        for ($k = $j; $k < $count; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if (in_array($text, ["(", "[", "{"], true)) { $depth++; if ($depth === 1) { continue; } }
            elseif (in_array($text, [")", "]", "}"], true)) { $depth--; if ($depth === 0) { $args[] = trim($cur); break; } }
            elseif ($text === "," && $depth === 1) { $args[] = trim($cur); $cur = ""; continue; }
            $cur .= $text;
        }
        if (count($args) < 2 || $args[1] === "\x27free\x27") { continue; }
        $bad[] = $f->getPathname() . ":" . $tokens[$i][2] . " tier is " . $args[1];
    }
}
if ($bad) { fwrite(STDERR, implode("\n", $bad) . "\n"); exit(1); }
' "$STAGE/src" || fail "the $SLUG build constructs an Ability whose tier is not the literal 'free'"

# 3d. No shipped file may describe licence- or tier-dependent behaviour of
#     THIS plugin, because the reviewer reads the docblocks too and every one
#     of these statements is now false. Patterns are chosen to have no
#     innocent reading: a stock-image license field, the GPL header and a note
#     that WPML is a paid plugin do not match any of them. Tree-wide, not
#     one file, because the claims were spread across eleven of them.
for pattern in 'pro[- ]tier' 'pro[- ]gat(e|ing)' 'pro[- ]licen[cs]e' 'unlicensed' 'without a (live )?licen[cs]e' 'licen[cs]e (that )?laps' 'payment to unlock' 'licen[cs]e gate' ; do
  if grep -rqiE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php"; then
    grep -rniE --include='*.php' -- "$pattern" "$STAGE/src" "$STAGE/$SLUG.php" >&2
    fail "a shipped file describes licence gating the $SLUG build does not have (\"$pattern\")"
  fi
done
if grep -q 'freemius' "$STAGE/composer.json"; then fail "composer.json still requires the licensing SDK"; fi

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
php "$ROOT/tools/compliance/bin/compliance.php" \
  --profile=wporg-free --artifact --path="$BUILD_DIR/$SLUG" \
  || fail "the compliance engine found blockers in $ZIP"

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
