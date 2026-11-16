#!/usr/bin/env bash
# composer check:flavors
#
# Runs the strip and the class-reference guard for BOTH release flavors (the
# wp.org build and the WooCommerce vertical) without zipping or running Plugin
# Check. It calls the build scripts' own --check-only path, so it can never
# disagree with the CI "compliance" and "plugin-check" jobs. Takes seconds.
# Each build script stages into its own mktemp dir and removes it on exit,
# including on failure and Ctrl-C (bash runs the EXIT trap on INT/TERM).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
trap 'exit 130' INT TERM

status=0
for script in build-wporg-release.sh build-woo-release.sh; do
  if ! bash "$ROOT/scripts/$script" --check-only; then
    status=1
  fi
done

if [ "$status" -ne 0 ]; then
  cat >&2 <<'MSG'

FLAVOR CLASS GUARD FAILED
A file that a release flavor ships still names a class the strip removes
(the lines above give the file, the line and the class).
Fix it one of two ways:
  1. register through a hook or filter: shared code (src/Plugin.php,
     src/Safety/*, src/Tools/Meta/*, anything every flavor ships) must never
     name an integration or Pro class; let the integration register itself.
  2. add a strip rule in scripts/flavors/wporg/strip.php (or the woocommerce
     manifest.php) in the same commit, where the codebase already does so.
Re-run with: composer check:flavors   (see CONTRIBUTING.md)
MSG
fi
exit "$status"
