#!/usr/bin/env bash
# Fail if any Plugin_Updater_Check error-level marker survives into a tree.
#
# Guideline 8, the build-time half of WPORG-08-UPDATER. Plugin_Updater_Check
# is a file-content regex with no Freemius carve-out (unlike the PHPCS review
# ruleset, which excludes */freemius/* by name) and the wordpress.org run does
# not exclude vendor/, so the directory build has to prove that nothing in the
# tree it is about to zip trips it, vendor included.
#
# Two things this is deliberately not:
#
#   * not a second copy of the policy. The pattern list is read from
#     Updater_Rule::UPDATER_PATTERNS, the same list the compliance engine
#     compiles, so the two cannot drift.
#   * not the whole rule. The Update URI header arm lives in the engine, which
#     runs against the extracted zip as the build's last gate. What is here is
#     the content regexes (case-insensitive, as Plugin_Updater_Check applies
#     them) plus the plugin-update-checker.php basename match, which no
#     content grep can catch.
#
# Scope is *.php because Plugin_Updater_Check is a PHP-file check.
#
#   bash scripts/lib/updater-gate.sh <tree>
#
# Exit codes: 0 clean, 1 marker found, 2 usage or scan failure.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TREE="${1:-}"

usage() { echo "usage: updater-gate.sh <tree>" >&2; exit 2; }
fail() { echo "ERROR: $1" >&2; exit 1; }
broken() { echo "ERROR: $1" >&2; exit 2; }

[ -n "$TREE" ] || usage
[ -d "$TREE" ] || usage

PATTERNS="$(php -r '
require $argv[1] . "/vendor/autoload.php";
foreach (WPMCP\Compliance\Rules\Updater_Rule::UPDATER_PATTERNS as $pattern) { echo $pattern, "\n"; }
' "$ROOT")" || broken "could not read Updater_Rule::UPDATER_PATTERNS"
[ -n "$PATTERNS" ] || broken "Updater_Rule::UPDATER_PATTERNS came back empty"

while IFS= read -r pattern; do
  [ -n "$pattern" ] || continue
  # grep's exit 2 (unreadable path, bad pattern) must not read as "clean",
  # so the status is captured rather than tested by `if`.
  set +e
  grep -rqIEi --include='*.php' -- "$pattern" "$TREE"
  status=$?
  set -e
  case "$status" in
    0)
      grep -rnIEi --include='*.php' -- "$pattern" "$TREE" >&2
      fail "updater surface \"$pattern\" survived into $TREE"
      ;;
    1) ;;
    *) broken "updater scan failed for \"$pattern\" (grep exit $status)" ;;
  esac
done <<PATTERN_LIST
$PATTERNS
PATTERN_LIST

CHECKER="$(find "$TREE" -name 'plugin-update-checker.php' -print 2>/dev/null | head -1)"
[ -z "$CHECKER" ] || fail "plugin-update-checker.php must not ship in a directory plugin: $CHECKER"

exit 0
