#!/usr/bin/env bash
# Build the wp.org vertical zip: dist/safe-mcp-for-woocommerce-<version>.zip
#
# Same tree as the directory cut, flavor-gated at runtime (WPMCP_FLAVOR in the
# main file, see Plugin::FLAVOR_GROUPS) and pruned at build time. Guideline 5
# applies to this zip exactly as it does to the directory cut, so the paid
# tier is removed by the same strip (scripts/flavors/wporg/strip.php and its
# shared policy.php, issue #257); scripts/flavors/woocommerce/manifest.php
# then prunes the groups this flavor never registers. That manifest MUST stay
# in sync with the 'woocommerce' whitelist in Plugin.php, and the two
# remote-execution call sites (eval in Php_Snippet_Runner, proc_open in
# Wp_Cli_Executor) must never ship in this build at all.
#
# Read that whitelist narrowly: it gates ONLY the deferred group callbacks in
# the $groups map at the end of Plugin::register_abilities(). Everything
# registered before that map (media, packages, security, performance,
# filesystem, ...) is unconditional and ships in every flavor whether or not
# its group is named. When auditing what a flavor build actually exposes, and
# in particular what it can reach over the network for readme.txt's External
# services section, that unconditional prologue is the authoritative list;
# the whitelist below is not.
set -euo pipefail

# --check-only: run just the strip and the class-reference gates in a
# throwaway stage and exit, with no composer install, zip or Plugin Check.
# `composer check:flavors` calls this.
CHECK_ONLY=0
[ "${1:-}" = "--check-only" ] && CHECK_ONLY=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

# The wp.org slug, and so the zip's directory, the main file and the i18n
# text domain (issue #1140, owner decision 20 Nov 2026: "Safe MCP for
# WooCommerce"; "mcp-for-woocommerce" belongs to another author, and a "wp"
# prefix draws Plugin Check's trademarked_term warning). The compliance engine
# derives the slug from the main file's name, as wp.org's text-domain check
# expects them to agree. src/flavor-guard.php reads only main files named
# wpmcp*.php, so a full or directory build never reads this one; that is
# harmless, because a higher-ranked build never defers to this flavor.
SLUG="safe-mcp-for-woocommerce"
MAIN="$SLUG.php"
STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$SLUG"
trap 'rm -rf "$STAGE_PARENT"' EXIT
mkdir -p "$STAGE"

cp "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"
# Clears the plugin's cron events on uninstall (issue #468). The self-hosted
# build (build-release.sh) leaves it out: there the licensing SDK owns the
# uninstall hook, and uninstall.php would replace it.
cp "$ROOT/uninstall.php" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"

# Ship the translation directory the Domain Path header points at (issue #184).
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/$MAIN" > "$STAGE/$MAIN"
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/readme.txt" > "$STAGE/readme.txt"

# Name gate. This build stages its own header/readme pair out of
# scripts/flavors/woocommerce/, so neither the source-tree compliance run
# (which reads the root main file) nor build-wporg-release.sh's engine run can
# see it. The whole engine runs over the extracted zip at the end; this runs
# the two rules that cover the name first, against the staged tree, so a
# naming mistake fails before the composer steps: the header/readme parse
# comes from the engine and Trademark_Rule is the rule WPORG-17-TRADEMARK
# enforces, with nothing re-implemented in shell. A header/title mismatch
# ships as Plugin Check's mismatched_plugin_name; a restricted term in the
# name or slug is its Trademarks_Check. Tag findings are left to the engine
# run at the end and to ReleaseHeadersTest.
# Skipped by --check-only: it needs the dev autoloader and is a naming check,
# not a class-reference check.
if [ "$CHECK_ONLY" != 1 ]; then
php -r '
require $argv[2] . "/vendor/autoload.php";
$context = WPMCP\Compliance\Rule_Context::for_path($argv[1], WPMCP\Compliance\Profile::wporg_free());
$header = $context->header();
$readme = $context->readme();
$errors = [];
if ("" === trim($header->name())) {
    $errors[] = "no Plugin Name header in the staged main file";
}
if (! $readme->exists()) {
    $errors[] = "no readme.txt in the staged tree";
} elseif ($header->name() !== $readme->title()) {
    $errors[] = sprintf("Plugin Name \"%s\" differs from the readme title \"%s\"", $header->name(), $readme->title());
}
foreach ((new WPMCP\Compliance\Rules\Trademark_Rule())->check($context) as $finding) {
    if (str_starts_with($finding->message(), "tag ")) {
        continue;
    }
    if (WPMCP\Compliance\Severity::BEST_PRACTICE === $finding->severity_override()) {
        continue;
    }
    $errors[] = $finding->location() . "  " . $finding->message();
}
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
' "$STAGE" "$ROOT" || { echo "ERROR: the staged $SLUG name fails the WPORG-17-TRADEMARK / mismatched_plugin_name gate" >&2; exit 1; }
fi

# The shared wp.org strip, then this flavor's manifest. The strip removes the
# paid tier (src/Pro, src/Freemius, every paid predicate and pro-tier
# registration, the pay-to-unlock copy) and the execution call sites with
# count-validated exact-string edits that fail the build when upstream code
# moves; the manifest removes the ability groups this flavor never registers.
#
# NOTE: src/Memory and src/Admin/Memory_Page.php deliberately STAY. The three
# agent-facing memory tools leave with the shared strip, but published
# guardrails are enforced in Registrar::is_permitted() on every build, and the
# handshake reads the approved entries; those call sites are unconditional, so
# the store must ship. With the group off the post type is never registered
# and Memory_Store::block_rules() short-circuits to an empty rule set.
#
# Unlike the directory cut, this flavor drops src/Cloud and
# Admin\Announcements (issue #1140, decision D4): the announcements feed was
# the cloud client's only caller here, so both leave together.
php "$ROOT/scripts/flavors/wporg/strip.php" "$STAGE" "$ROOT/scripts/flavors/woocommerce/manifest.php"

# A textual transform that produces an unparsable file must never reach a zip,
# so the stage is linted after every transform (the strip here, the
# text-domain rewrite below), the way build-wporg-release.sh does.
lint_stage() {
  local file
  while IFS= read -r file; do
    php -l "$file" > /dev/null || { echo "ERROR: syntax error in $file $1" >&2; exit 1; }
  done < <(find "$STAGE/src" "$STAGE/$MAIN" "$STAGE/uninstall.php" -name '*.php')
}
lint_stage "after the strip"

# The narrowing guard (issue #1140): store tools plus the safety core only.
# File writes, raw SQL and DB writes, the PHP snippet store, code snippet
# creation, site migration, rotate-salts and the cloud client must not ship,
# re-derived from the stage against scripts/flavors/woocommerce/narrowed.php
# rather than trusted to the manifest. It runs again over the extracted zip.
php "$ROOT/scripts/flavors/woocommerce/assert-narrowed.php" "$STAGE" \
  || { echo "ERROR: the $SLUG build ships something the wp.org narrowing strips" >&2; exit 1; }

# The class-reference gates, defined once so the full build and --check-only
# run the same commands. See the long comments at the original call sites in
# git history for why each scope is what it is: inheritance edges, the
# always-loaded src/Safety core, and hook callbacks across all of src.
class_ref_gates() {
  php "$ROOT/scripts/lib/check-dangling-inheritance.php" "$STAGE/src" \
    || { echo "ERROR: dangling inheritance edge to a pruned domain in the $SLUG build" >&2; exit 1; }
  # src/Safety loads on every request and nothing flavor-gates it, so it must
  # never name a class this zip prunes (Php_Snippet_Store was the case).
  php "$ROOT/scripts/lib/class-ref-gate.php" "$STAGE" src/Safety \
    || { echo "ERROR: the $SLUG safety core names a class the build does not ship" >&2; exit 1; }
  # Every other shipped file too (issue #935): a plain `use` of a pruned
  # class in shared code (src/MCP, src/Governance, ...) passed the two scans
  # here and fataled only where the class was first used. Plugin.php is left
  # to the hook scan below, whose references sit in branches gated on ability
  # groups this flavor never enables; the allowlist names the few files that
  # reach a pruned class only behind a runtime check.
  php "$ROOT/scripts/lib/class-ref-gate.php" "$STAGE" src --exclude=src/Plugin.php --allow="$ROOT/scripts/flavors/woocommerce/class-ref-allow.php" \
    || { echo "ERROR: a file in the $SLUG build names a class the build does not ship" >&2; exit 1; }
  # Every hook callback in the whole tree, Plugin.php included, must name a
  # shipped class unless the branch it sits in cannot run in this flavor.
  php "$ROOT/scripts/lib/class-ref-gate.php" "$STAGE" src --hooks --flavor=woocommerce \
    || { echo "ERROR: a hook in the $SLUG build names a class the build does not ship" >&2; exit 1; }
}

if [ "$CHECK_ONLY" = 1 ]; then
  # shellcheck source=scripts/lib/stage-classmap.sh
  . "$ROOT/scripts/lib/stage-classmap.sh"
  stage_classmap "$STAGE"
  class_ref_gates
  echo "ok: the $SLUG build names no class it does not ship"
  exit 0
fi

# The free-tier invariants the directory cut is held to, re-derived from this
# stage by the same script build-wporg-release.sh runs (registrar names no
# tier, every Ability literally 'free', no licence-gating prose, no withheld
# registration method referenced, no orphaned register_* method). No ability
# manifest is passed: tests/support/ability-manifest.php pins the full
# surface, and this flavor drops whole groups by design, so its "no free
# ability went missing" half does not apply here.
php "$ROOT/scripts/flavors/wporg/assert-free-tier.php" "$STAGE" \
  || { echo "ERROR: the $SLUG build fails the free-tier invariants" >&2; exit 1; }

# The licensing SDK leaves composer.json too, not just vendor/. Plugin Check's
# File_Type_Check errors on a vendor/ directory with no composer.json beside
# it, so the pruned manifest ships, as it does in the directory cut; the lock
# file does not (it is a development artifact).
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
composer remove freemius/wordpress-sdk --working-dir="$STAGE" --update-no-dev --quiet --no-interaction
composer dump-autoload --working-dir="$STAGE" --optimize --quiet --no-interaction
rm -f "$STAGE/composer.lock"
if [ -d "$STAGE/vendor/freemius" ]; then
  echo "ERROR: the licensing SDK is still vendored in the $SLUG build" >&2; exit 1
fi
if grep -q 'freemius' "$STAGE/composer.json"; then
  echo "ERROR: the $SLUG build's composer.json still requires the licensing SDK" >&2; exit 1
fi

# wp.org's text-domain sniff wants the i18n domain to match the slug. The
# rewrite, its two matched forms, why the admin menu slug and
# src/flavor-guard.php are left alone, and the leftover check that fails the
# build are all in scripts/flavors/woocommerce/text-domain.php, which
# FlavorBuildBlockersTest runs too so the pinned tree is the built one.
php "$ROOT/scripts/flavors/woocommerce/text-domain.php" "$STAGE" "$SLUG" \
  || { echo "ERROR: the text-domain rewrite failed in the $SLUG build" >&2; exit 1; }

# Lint again: the rewrite is the second textual transform over src/, and an
# unparsable file must not reach the zip whichever transform produced it.
lint_stage "after the text-domain rewrite"

# Coexistence with the full plugin is handled at bootstrap, not by rewriting
# identifiers. src/flavor-guard.php ranks the active WP MCP builds by the
# WPMCP Flavor header in each main file and makes this build stand down
# whenever a higher-ranked one is active, so the two never share a request
# whichever directory the other lives in or loads from. A build-time
# rename of the 'wpmcp_' prefix was tried and reverted: it splits identifiers
# whose two halves are written differently (the caller's 'wpmcp_restore' vs
# the registration's 'wp_ajax_wpmcp_restore'), it renames WP_Error codes that
# are part of the MCP response contract, it leaves the filter names quoted in
# user-facing exception text pointing at filters that no longer exist, and it
# orphans the custom tables and options of any install that updates into it.
grep -q 'flavor-guard.php' "$STAGE/$MAIN" || {
  echo "ERROR: $MAIN does not load the flavor coexistence guard" >&2
  exit 1
}
[ -f "$STAGE/src/flavor-guard.php" ] || {
  echo "ERROR: src/flavor-guard.php missing from the $SLUG build" >&2
  exit 1
}
# The guard is the one file every build must ship unchanged: it is the code
# that decides which build boots, and the text-domain rewrite above once
# inverted that decision. Content, not presence.
cmp -s "$ROOT/src/flavor-guard.php" "$STAGE/src/flavor-guard.php" || {
  echo "ERROR: src/flavor-guard.php in the $SLUG build differs from the source tree:" >&2
  diff "$ROOT/src/flavor-guard.php" "$STAGE/src/flavor-guard.php" >&2 || true
  exit 1
}
# Excluding the guard from the rewrite is only sound while it has no i18n
# call of its own; a string added there would ship with the wrong domain.
GUARD_I18N="$(grep -nE \
  "(^|[^A-Za-z0-9_])(__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x|translate)\(" \
  "$STAGE/src/flavor-guard.php" || true)"
[ -z "$GUARD_I18N" ] || {
  echo "ERROR: src/flavor-guard.php contains an i18n call; it is excluded from the text-domain rewrite, so move the string to the main file:" >&2
  echo "$GUARD_I18N" >&2
  exit 1
}
# The MCP adapter guard (issue #386): global functions the main file calls
# right after the autoloader, so a prune that dropped them would fatal.
[ -f "$STAGE/src/adapter-guard.php" ] && grep -q "wpmcp_prefer_shared_mcp_adapter" "$STAGE/$MAIN" || {
  echo "ERROR: the $SLUG build does not ship or apply src/adapter-guard.php" >&2
  exit 1
}
grep -q "^ \* WPMCP Flavor: woocommerce$" "$STAGE/$MAIN" || {
  echo "ERROR: $MAIN does not declare the woocommerce flavor header" >&2
  exit 1
}

# Belt and braces: fail the build if any real eval/exec call site survived.
# Shared token-level gate (scripts/lib/exec-gate.php), so strings and
# comments (e.g. Malware_Audit's pattern descriptions) do not false-positive.
# Same widened list and coverage as the wp.org build: src, vendor and the
# flavor main file (#167).
# Exit 1 is a surviving construct, exit 2 is the gate not being able to do its
# job at all. Reporting a path that was never staged as "a construct survived"
# would send the next reader looking for a call site that is not there.
set +e
php "$ROOT/scripts/lib/exec-gate.php" "$STAGE/src" "$STAGE/vendor" "$STAGE/$MAIN"
gate_status=$?
set -e
case "$gate_status" in
  0) ;;
  1) echo "ERROR: a banned execution or obfuscation construct found in the $SLUG build" >&2; exit 1 ;;
  2) echo "ERROR: the exec gate was pointed at a path that was never staged or cannot be read" >&2; exit 1 ;;
  *) echo "ERROR: the exec gate failed unexpectedly (exit $gate_status)" >&2; exit 1 ;;
esac

# Belt and braces: fail the build if the prune list left a dangling
# inheritance edge, the always-loaded safety core names a pruned class, or a
# hook callback does (class_ref_gates, defined after the strip above).
class_ref_gates

# wp.org rejects a non-wordpress.org Update URI; only the full build carries one.
# shellcheck source=scripts/lib/release-flavors.sh
. "$ROOT/scripts/lib/release-flavors.sh"
assert_no_update_uri "$STAGE/$SLUG.php" || exit 1

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

# The compliance engine, in the profile that models the directory, run against
# the extracted zip rather than the checkout. build-wporg-release.sh has had
# this gate since it was written; this build is a wp.org submission too, and
# without it nothing ever checked the woocommerce artifact. It is what catches
# a missing or unrecognised ABSPATH guard (issue #170) in the shipped bytes.
# Every pack runs, the same as for the directory cut (issue #257).
BUILD_DIR="$ROOT/build/woocommerce"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
unzip -q "$ZIP" -d "$BUILD_DIR"
php "$ROOT/tools/compliance/bin/compliance.php" \
  --profile=wporg-free --artifact --path="$BUILD_DIR/$SLUG" \
  || { echo "ERROR: the compliance engine found blockers in $ZIP" >&2; exit 1; }
# The narrowing guard again, over the shipped bytes rather than the stage.
php "$ROOT/scripts/flavors/woocommerce/assert-narrowed.php" "$BUILD_DIR/$SLUG" \
  || { echo "ERROR: $ZIP ships something the wp.org narrowing strips" >&2; exit 1; }
# The execution files themselves are absent from the artifact, checked on the
# zip listing rather than on the staging directory: the rm list, the staging
# copy and the zip step are three chances to reintroduce them (#167). Shared
# with the wp.org build (scripts/lib/zip-gate.sh) so the two cannot drift,
# and read from a captured listing rather than a pipeline, see the note there.
# shellcheck source=scripts/lib/zip-gate.sh
. "$ROOT/scripts/lib/zip-gate.sh"
set +e
zip_excludes "$ZIP" Php_Snippet_Runner.php Wp_Cli_Executor.php Run_Php_Snippet.php Run_Wp_Cli.php
zip_status=$?
set -e
case "$zip_status" in
  0) ;;
  1) echo "ERROR: an execution file is inside $ZIP" >&2; exit 1 ;;
  *) echo "ERROR: could not list $ZIP to check it for execution files" >&2; exit 1 ;;
esac

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
