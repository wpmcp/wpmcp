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
cp -R "$ROOT/languages" "$STAGE/languages"

composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/wpmcp-pro-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" wpmcp -x "*.DS_Store")

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
