#!/usr/bin/env bash
#
# Benchmarks the parallel gate (bin/test-local.sh --parallel): one full gate
# per configuration, one result line each, so the defaults in
# bin/parallel-gate.php can be checked on a quiet machine with one command.
#
#   bin/bench-parallel-gate.sh              every configuration below
#   bin/bench-parallel-gate.sh n12 n16      only those
#
# Configurations: n12 n14 n16 n20 (workers, everything on), and at 12 workers
# fpt0 (InnoDB without a file per table), noram (workspace and database on
# disk), notpl (the WordPress installer in every job), noopc (no opcache file
# cache), pcov (php.ini and pcov loaded in every job).
#
# Each line: wall clock of the parallel run, end to end time of the command,
# peak CPU and load, the load average when it started (other work on the
# machine skews the numbers; run it when nothing else is testing), its exit
# code and how many new failures it reported. Results are appended to
# $WPMCP_TEST_CACHE/bench/results.txt and printed. Each gate cleans up after
# itself (RAM disk, database server, core copies); the logs go to the
# parallel gate's own log budget. Stops before a run when the disk has less
# than 20 GB free. Licensed live legs run when their WPMCP_*_SRC variables
# are set, as in any gate.

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
CACHE=${WPMCP_TEST_CACHE:-$HOME/.cache/wpmcp-tests}
OUT="$CACHE/bench"
mkdir -p "$OUT"
RESULTS="$OUT/results.txt"

configs=("$@")
[ ${#configs[@]} -gt 0 ] || configs=(n12 n14 n16 n20 fpt0 noram notpl noopc pcov)

run() {
	local name=$1 n=$2 log start end free load
	shift 2
	free=$(df -Pk "$CACHE" | awk 'NR == 2 { print $4 }')
	if [ "${free:-0}" -lt $((20 * 1048576)) ]; then
		echo "$name: not run, under 20 GB free" | tee -a "$RESULTS"
		return
	fi
	load=$(sysctl -n vm.loadavg 2>/dev/null | awk '{ print $2 }')
	log="$OUT/$name.log"
	start=$(date +%s)
	env "$@" bash "$ROOT/bin/test-local.sh" --parallel="$n" </dev/null >"$log" 2>&1
	local rc=$?
	end=$(date +%s)
	local text
	text=$(sed 's/\x1b\[[0-9;]*m//g' "$log")
	printf '%s  %-6s n=%-3s %-44s run %-10s end-to-end %4ss  %s  load at start %s  exit %s  new failures %s\n' \
		"$(date '+%Y-%m-%d %H:%M %Z')" "$name" "$n" "${*:-defaults}" \
		"$(grep -o 'wall clock [^ ]*' <<<"$text" | awk '{ print $3 }')" "$((end - start))" \
		"$(grep -o 'peak CPU [^;]*; peak load [^,]*' <<<"$text")" "${load:-?}" "$rc" \
		"$(sed -n '/NEW failures/,/^  [a-z].*:/p' <<<"$text" | grep -c '^    WPMCP')" | tee -a "$RESULTS"
}

for c in "${configs[@]}"; do
	case "$c" in
		n12) run n12 12 WPMCP_BENCH=1 ;;
		n14) run n14 14 WPMCP_BENCH=1 ;;
		n16) run n16 16 WPMCP_BENCH=1 ;;
		n20) run n20 20 WPMCP_BENCH=1 ;;
		fpt0) run fpt0 12 WPMCP_PARALLEL_DB_ARGS=--innodb-file-per-table=0 ;;
		noram) run noram 12 WPMCP_PARALLEL_RAMDISK=0 ;;
		notpl) run notpl 12 WPMCP_PARALLEL_DB_TEMPLATES=0 ;;
		noopc) run noopc 12 WPMCP_PARALLEL_OPCACHE=0 ;;
		pcov) run pcov 12 WPMCP_PARALLEL_PHP_FAST=0 ;;
		*) echo "unknown configuration: $c" >&2; exit 2 ;;
	esac
done
echo "Results: $RESULTS"
