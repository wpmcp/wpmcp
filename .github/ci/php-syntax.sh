#!/usr/bin/env bash
#
# Syntax check of every shipped PHP file with the PHP on PATH (CI runs it on
# 8.1, the readme.txt "Requires PHP" floor). Checks every file, names each one
# that does not parse, and fails if any does not.
#
# Usage: bash .github/ci/php-syntax.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
echo "php -l with $(php -r 'echo PHP_VERSION;')"
# shellcheck disable=SC2016 # $1 is expanded by the inner sh.
find src wpmcp.php uninstall.php -name '*.php' -print0 \
	| xargs -0 -n 1 -P 8 sh -c 'out=$(php -d display_errors=stdout -l "$1" 2>&1) || { echo "::error file=$1::does not parse on PHP $(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"): $(printf "%s" "$out" | grep -m1 -i error)"; exit 1; }' _
echo "All files parse."
