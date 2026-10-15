#!/usr/bin/env bash
# Build the self-hosted distribution zip: dist/wpmcp-pro-<version>.zip
#
# One artifact serves free and pro, gated at runtime by Pro\Gate. This is the
# zip sold and delivered off-directory, where licensing and the two guarded
# execution sites are permitted. The WordPress.org cut is a different artifact
# with the paid tier removed rather than gated: scripts/build-wporg-release.sh,
# which is the one that writes dist/wpmcp-<version>.zip.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/wpmcp"
trap 'rm -rf "$STAGE_PARENT"' EXIT
mkdir -p "$STAGE"

cp "$ROOT/wpmcp.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"
# No uninstall file here (issue #468): WordPress would run it instead of the
# licensing SDK's uninstall hook. This build clears its cron events through
# that SDK's after_uninstall action (src/Freemius/Bootstrap.php) instead.
# src/Freemius/Bootstrap.php pins the connect-screen icon to assets/, so the
# self-hosted zip has to carry that directory (#164).
cp -R "$ROOT/assets" "$STAGE/assets"

# Ship the translation directory the Domain Path header points at (issue #184).
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;

composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

# Freemius refuses a zip whose fs_dynamic_init() argument is not a literal
# array, so the staged copy gets the config inlined (scripts/lib/inline-fs-config.php).
php "$ROOT/scripts/lib/inline-fs-config.php" "$ROOT" "$STAGE"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/wpmcp-pro-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" wpmcp -x "*.DS_Store")

# Gate: every assets/ path that src/ resolves against WPMCP_DIR must be in the
# zip, or the Freemius connect screen renders a 404 icon (#164).
for asset in $(grep -rhoE "WPMCP_DIR \. 'assets/[^']+'" "$ROOT/src" | sed -E "s/.*'(assets\/[^']+)'/\1/" | sort -u); do
    unzip -Z1 "$ZIP" | grep -Fx "wpmcp/$asset" >/dev/null || { echo "gate: $asset is referenced from src/ but missing from $ZIP" >&2; exit 1; }
done

# Gate: the Freemius deployment validator needs the literal array (see above).
FS_FILE="$(unzip -p "$ZIP" wpmcp/src/Freemius/wpmcp-fs.php)"
case "$FS_FILE" in
    *"Bootstrap::config()"*) echo "gate: wpmcp-fs.php still calls Bootstrap::config(); Freemius cannot deploy this zip" >&2; exit 1 ;;
esac
case "$FS_FILE" in
    *"'public_key'"*"'has_paid_plans'"*) ;;
    *) echo "gate: wpmcp-fs.php carries no inlined fs_dynamic_init config" >&2; exit 1 ;;
esac

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
