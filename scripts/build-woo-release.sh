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
cp -R "$ROOT/languages" "$STAGE/languages"
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/$SLUG.php" > "$STAGE/$SLUG.php"
sed "s/{{VERSION}}/$VERSION/g" "$ROOT/scripts/flavors/woocommerce/readme.txt" > "$STAGE/readme.txt"

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
  "$STAGE/src/Tools/WidgetBuilder" \
  "$STAGE/src/Tools/BlockBuilder" \
  "$STAGE/src/Tools/Cloud" \
  "$STAGE/src/Tools/Search" \
  "$STAGE/src/Cloud" \
  "$STAGE/src/Tools/Memory" \
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

# wp.org's text-domain sniff wants the i18n domain to match the slug.
find "$STAGE/src" -name '*.php' -exec sed -i '' "s/, 'wpmcp' )/, '$SLUG' )/g; s/, 'wpmcp')/, '$SLUG')/g" {} +

# Belt and braces: fail the build if any real eval/exec call site survived.
# Token-level check, so strings and comments (e.g. Malware_Audit's pattern
# descriptions) do not false-positive.
php -r '
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));
foreach ($it as $f) {
    if ($f->getExtension() !== "php") { continue; }
    foreach (token_get_all(file_get_contents($f->getPathname())) as $t) {
        if (!is_array($t)) { continue; }
        if ($t[0] === T_EVAL || ($t[0] === T_STRING && in_array(strtolower($t[1]), ["proc_open", "shell_exec", "passthru", "popen"], true))) {
            $bad[] = $f->getPathname() . ":" . $t[2] . " " . (is_string($t[1]) ? $t[1] : "eval");
        }
    }
}
if ($bad) { fwrite(STDERR, implode("\n", $bad) . "\n"); exit(1); }
' "$STAGE/src" || { echo "ERROR: execution call site found in the $SLUG build" >&2; exit 1; }

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
