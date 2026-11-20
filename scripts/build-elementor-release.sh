#!/usr/bin/env bash
# Build Safe MCP for Elementor: dist/safe-mcp-for-elementor-<version>.zip
#
# The GitHub-only Elementor flavor (owner decision, 21 Nov 2026). It is the
# self-hosted tree that scripts/build-release.sh stages, licensing SDK and
# upgrade banner included, under its own folder and main file
# (scripts/flavors/elementor/), flavor-gated at runtime rather than pruned:
#
#  - Plugin::FLAVOR_GROUPS['elementor'] and Plugin::FLAVOR_TIERS['elementor']
#    decide what an unlicensed install registers and which tools are free;
#  - a WP MCP Pro licence (the same licensing product as the full plugin)
#    lifts both in place (Plugin::FLAVOR_GROUPS_LIFT_WITH_PRO), so a buyer
#    never installs a second zip;
#  - the licensing SDK runs with has_premium_version off for this flavor
#    (Freemius\Bootstrap::upgrades_in_place()), so it never offers the full
#    plugin's server package as this folder's update or as a download.
#
# Unlike build-release.sh it ships uninstall.php: WordPress then runs that
# file instead of the SDK's uninstall hook, so deleting this flavor never
# reports the shared licence install as uninstalled, and the file is a no-op
# while another WP MCP build is installed (wpmcp_other_build_installed()).
# The fs_dynamic_init() config is not inlined: this zip is never deployed to
# the licensing server, and Bootstrap::config() decides the flavor at runtime.
#
# Not a wordpress.org build: the Update URI header is required here (it stops
# WordPress offering a same-slug directory package as an update).
set -euo pipefail

# --check-only: render the main file and readme into a throwaway stage and run
# the header, readme and guard gates, with no composer install or zip.
# `composer check:flavors` calls this.
CHECK_ONLY=0
[ "${1:-}" = "--check-only" ] && CHECK_ONLY=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

SLUG="safe-mcp-for-elementor"
MAIN="$SLUG.php"
SRC_DIR="$ROOT/scripts/flavors/elementor"
# shellcheck source=scripts/lib/release-flavors.sh
. "$ROOT/scripts/lib/release-flavors.sh"
UPDATE_URI="$WPMCP_UPDATE_URI"

STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$SLUG"
# Runs on success, failure and Ctrl-C (bash runs the EXIT trap on INT/TERM).
trap 'rm -rf "$STAGE_PARENT"' EXIT
trap 'exit 130' INT TERM
mkdir -p "$STAGE"

cp "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$ROOT/uninstall.php" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"
# The connect-screen icon (src/Freemius/Bootstrap.php) and the admin assets.
cp -R "$ROOT/assets" "$STAGE/assets"
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;
sed "s/{{VERSION}}/$VERSION/g" "$SRC_DIR/$MAIN" > "$STAGE/$MAIN"
# The External services section is the full plugin's, verbatim: this build
# ships the same tree, so it can reach the same hosts.
SERVICES="$(awk '/^== External services ==$/{on=1; next} on && /^== /{exit} on' "$ROOT/readme.txt" | sed -e '1{/^$/d;}' -e '${/^$/d;}')"
[ -n "$SERVICES" ] || { echo "could not read the External services section from readme.txt" >&2; exit 1; }
SERVICES="$SERVICES" php -r '
$t = (string) file_get_contents($argv[1]);
$t = str_replace(["{{VERSION}}", "{{EXTERNAL_SERVICES}}"], [$argv[2], (string) getenv("SERVICES")], $t);
file_put_contents($argv[3], $t);
' "$SRC_DIR/readme.txt" "$VERSION" "$STAGE/readme.txt"

fail() { echo "gate: $*" >&2; exit 1; }

# Gate: the main file parses and carries no unrendered placeholder.
php -l "$STAGE/$MAIN" >/dev/null || fail "$MAIN does not parse"
if grep -q '{{[A-Z_]*}}' "$STAGE/$MAIN" "$STAGE/readme.txt"; then fail "unrendered placeholder in $MAIN or readme.txt"; fi
grep -q '^\* api\.freemius\.com' "$STAGE/readme.txt" || fail "readme.txt does not disclose the licensing service"
grep -q '^=== Safe MCP for Elementor ===$' "$STAGE/readme.txt" || fail "readme.txt title is not Safe MCP for Elementor"
grep -q '^ \* Plugin Name: Safe MCP for Elementor$' <<<"$(sed -n '1,5p' "$STAGE/$MAIN")" || fail "$MAIN Plugin Name is not Safe MCP for Elementor"
grep -q 'Not affiliated with or endorsed by Elementor Ltd' "$STAGE/readme.txt" || fail "readme.txt lacks the not-affiliated line"

# Gate: the headers this build depends on. 'WPMCP Flavor: elementor' is what
# src/flavor-guard.php ranks; Requires Plugins keeps it off sites without
# Elementor; Update URI keeps a same-slug directory package from replacing it.
HDR="$(sed -n '1,40p' "$STAGE/$MAIN")"
grep -q '^ \* WPMCP Flavor: elementor$' <<<"$HDR" || fail "$MAIN has no 'WPMCP Flavor: elementor' header"
grep -q '^ \* Requires Plugins: elementor$' <<<"$HDR" || fail "$MAIN has no 'Requires Plugins: elementor' header"
grep -qF " * Update URI: $UPDATE_URI" <<<"$HDR" || fail "$MAIN has no 'Update URI: $UPDATE_URI' header"
grep -q "^ \* Version: $VERSION\$" <<<"$HDR" || fail "$MAIN is not version $VERSION"
grep -q "^define( 'WPMCP_FLAVOR', 'elementor' );" "$STAGE/$MAIN" || fail "$MAIN does not define WPMCP_FLAVOR 'elementor'"
grep -q "^Stable tag: $VERSION\$" "$STAGE/readme.txt" || fail "readme.txt Stable tag is not $VERSION"

# Gate: the coexistence guard ships byte-identical in every build; a copy that
# differs could rank this flavor differently from the build next to it.
cmp -s "$ROOT/src/flavor-guard.php" "$STAGE/src/flavor-guard.php" || fail "src/flavor-guard.php differs from the source copy"

if [ "$CHECK_ONLY" = 1 ]; then
    echo "elementor flavor: header, readme and guard gates pass"
    exit 0
fi

composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$SLUG" -x "*.DS_Store")

# Gate: the upgrade path, as every self-hosted asset (release_asset_table):
# Update URI, the banner, the Pro gate and the licensing SDK. Then this
# flavor's own files: without uninstall.php, deleting it would run the SDK's
# uninstall hook against the licence install it shares.
assert_self_hosted_zip "$ZIP" "$SLUG/$MAIN" "$VERSION" || fail "$ZIP is not a self-hosted build"
LISTING="$(unzip -Z1 "$ZIP")"
for path in readme.txt uninstall.php src/flavor-guard.php src/Freemius/Bootstrap.php vendor/autoload.php; do
    grep -Fxq "$SLUG/$path" <<<"$LISTING" || fail "$SLUG/$path missing from $ZIP"
done

# Gate: every assets/ path that src/ resolves against WPMCP_DIR is in the zip.
for asset in $(grep -rhoE "WPMCP_DIR \. 'assets/[^']+'" "$ROOT/src" | sed -E "s/.*'(assets\/[^']+)'/\1/" | sort -u); do
    grep -Fxq "$SLUG/$asset" <<<"$LISTING" || fail "$asset is referenced from src/ but missing from $ZIP"
done

echo "built $ZIP"
unzip -l "$ZIP" | tail -1
