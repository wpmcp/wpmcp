#!/usr/bin/env bash
# Build the wp.org vertical zip: dist/wpmcp-for-woocommerce-<version>.zip
#
# Same tree as the full plugin, flavor-gated at runtime (WPMCP_FLAVOR in the
# main file, see Plugin::FLAVOR_GROUPS) and pruned at build time. The prune
# list here MUST stay in sync with the 'woocommerce' whitelist in Plugin.php:
# every excluded group's files leave the zip, and the two remote-execution
# call sites (eval in Php_Snippet_Runner, proc_open in Wp_Cli_Executor) must
# never ship in this build at all.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

SLUG="wpmcp-for-woocommerce"
STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$SLUG"
trap 'rm -rf "$STAGE_PARENT"' EXIT
mkdir -p "$STAGE"

cp "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"

# Ship the translation directory the Domain Path header points at (issue #184).
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/$SLUG.php" > "$STAGE/$SLUG.php"
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/readme.txt" > "$STAGE/readme.txt"

# Name gate. This build stages its own header/readme pair out of
# scripts/flavors/woocommerce/, so neither the source-tree compliance run
# (which reads the root main file) nor build-wporg-release.sh's engine run can
# see it. The whole engine cannot run here yet (the vertical still carries the
# paid-state gating the wporg build strips), so this runs the two rules that
# cover the name against the staged tree, via the same classes
# tools/compliance/bin/compliance.php loads: the header/readme parse comes from
# the engine and Trademark_Rule is the rule WPORG-17-TRADEMARK enforces, with
# nothing re-implemented in shell. A header/title mismatch ships as Plugin
# Check's mismatched_plugin_name; a restricted term in the name or slug is
# its Trademarks_Check. Tag findings are left to ReleaseHeadersTest, which
# knows the for-use exception for the "woocommerce" tag.
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

# Prune the domains the 'woocommerce' flavor never registers.
rm -rf \
  "$STAGE/src/Tools/Elementor" \
  "$STAGE/src/Tools/Builders" \
  "$STAGE/src/Tools/ACF" \
  "$STAGE/src/Tools/I18n" \
  "$STAGE/src/Tools/Rest" \
  "$STAGE/src/Tools/Analytics" \
  "$STAGE/src/Tools/Multisite" \
  "$STAGE/src/Tools/Dispatch" \
  "$STAGE/src/Tools/Bridge" \
  "$STAGE/src/Tools/WidgetBuilder" \
  "$STAGE/src/Tools/BlockBuilder" \
  "$STAGE/src/Tools/Cloud" \
  "$STAGE/src/Tools/Search" \
  "$STAGE/src/Cloud" \
  "$STAGE/src/Tools/Memory" \
  "$STAGE/src/Tools/Sync" \
  "$STAGE/src/Integrations"

# NOTE: src/Memory and src/Admin/Memory_Page.php deliberately STAY. The three
# agent-facing memory tools are dropped with the group above, but published
# guardrails are enforced in Registrar::is_permitted() on every build, and the
# handshake reads the approved entries; those call sites are unconditional, so
# the store must ship. With the group off the post type is never registered
# and Memory_Store::block_rules() short-circuits to an empty rule set.

# Guarded-execution: the guards stay (Governance\Opt_In_Gates references
# them), the runners and their call sites do not.
rm -f \
  "$STAGE/src/Tools/Cli/Run_Wp_Cli.php" \
  "$STAGE/src/Tools/Cli/Wp_Cli_Executor.php" \
  "$STAGE/src/Tools/Code/Run_Php_Snippet.php" \
  "$STAGE/src/Tools/Code/Php_Snippet_Runner.php" \
  "$STAGE/src/Tools/Code/Php_Snippet_Validator.php" \
  "$STAGE/src/Tools/Code/Validate_Php_Snippet.php"

# This build never calls Freemius (free-only, no license checks needed;
# Pro\Gate fails closed without the SDK).
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
composer remove freemius/wordpress-sdk --working-dir="$STAGE" --update-no-dev --quiet --no-interaction
composer dump-autoload --working-dir="$STAGE" --optimize --quiet --no-interaction
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

# wp.org's text-domain sniff wants the i18n domain to match the slug. Two
# forms occur: the domain inline as the last argument, and the domain alone
# on its own line as the last argument of a wrapped i18n call. The second
# form is matched by "own line, no trailing comma", which is what separates
# it from the admin menu slug argument (src/Plugin.php), also the literal
# 'wpmcp' but always followed by a comma. The menu slug is deliberately left
# alone: it is a WordPress-derived identifier that screen ids are built from
# (see Admin/Announcements.php), so rewriting it breaks those lookups.
#
# src/flavor-guard.php is excluded. It carries no i18n call (the stand-down
# notice lives in the main file, which is staged from scripts/flavors/ with
# the right domain already), but it does compare active plugin basenames
# against the literal 'wpmcp' as a filename prefix, and that argument matches
# the first sed form. Rewriting it to '$SLUG' made the guard skip every
# sibling named wpmcp.php, so the vertical never deferred to the full plugin
# and the full plugin was the one that stood down. The gate below pins the
# shipped guard to the source tree byte for byte.
# In-place sed differs between BSD (macOS, needs the empty suffix argument)
# and GNU (CI's ubuntu, where '' would be read as the script).
if sed --version >/dev/null 2>&1; then SED_INPLACE=(sed -i); else SED_INPLACE=(sed -i ''); fi
find "$STAGE/src" -name '*.php' -not -name 'flavor-guard.php' -exec "${SED_INPLACE[@]}" \
  "s/, 'wpmcp' )/, '$SLUG' )/g; s/, 'wpmcp')/, '$SLUG')/g; s/^\([[:space:]]*\)'wpmcp'$/\1'$SLUG'/" {} +

# Belt and braces: fail the build if any i18n call kept the 'wpmcp' domain.
# The two seds above are line-based, so a future call wrapped differently
# would silently ship the wrong domain and fail the wp.org sniff instead.
LEFTOVER_DOMAIN="$(grep -rn --include='*.php' --exclude='flavor-guard.php' -E \
  "(^[[:space:]]*'wpmcp'[[:space:]]*$)|(, ?'wpmcp' ?\))" "$STAGE/src" || true)"
if [ -n "$LEFTOVER_DOMAIN" ]; then
  echo "ERROR: 'wpmcp' text domain survived the rewrite in the $SLUG build:" >&2
  echo "$LEFTOVER_DOMAIN" >&2
  exit 1
fi

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
grep -q 'flavor-guard.php' "$STAGE/$SLUG.php" || {
  echo "ERROR: $SLUG.php does not load the flavor coexistence guard" >&2
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
grep -q "^ \* WPMCP Flavor: woocommerce$" "$STAGE/$SLUG.php" || {
  echo "ERROR: $SLUG.php does not declare the woocommerce flavor header" >&2
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
php "$ROOT/scripts/lib/exec-gate.php" "$STAGE/src" "$STAGE/vendor" "$STAGE/$SLUG.php"
gate_status=$?
set -e
case "$gate_status" in
  0) ;;
  1) echo "ERROR: a banned execution or obfuscation construct found in the $SLUG build" >&2; exit 1 ;;
  2) echo "ERROR: the exec gate was pointed at a path that was never staged or cannot be read" >&2; exit 1 ;;
  *) echo "ERROR: the exec gate failed unexpectedly (exit $gate_status)" >&2; exit 1 ;;
esac

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

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
