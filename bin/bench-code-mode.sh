#!/usr/bin/env bash
#
# The code mode benchmark (issue #1130): tools/list bytes, tool calls, model
# turns, result bytes and wall time for five scripted tasks, on the full and
# the compact tool surface. Every later code mode PR (epic #1138) reports its
# delta against tools/bench/baseline-<date>.json.
#
#   bin/bench-code-mode.sh [--mode=inprocess] [--scenario=N] [--json | --markdown]
#                          [--out=FILE] [--save-tools-list=DIR]
#   bin/bench-code-mode.sh --mode=http --url=URL --user=USER --password=APP_PASSWORD
#                          [--wp-path=SITE] [--wp-cli=wp] [--scenario=N] [--json | --markdown]
#                          [--out=FILE] [--save-tools-list=DIR] [--plain-permalinks]
#   bin/bench-code-mode.sh --prune [--mode=http --wp-path=SITE]
#
# inprocess (the default) runs tools/bench/BenchTest.php through
# bin/test-local.sh as a targeted run: the cached WordPress install of the
# test gate (WooCommerce, Elementor and Yoast SEO among its plugins), the
# private MariaDB, and a database of the run's own that test-local.sh drops
# when it exits, also on failure and Ctrl-C. Each replay calls the abilities'
# own execute(), so the numbers are deterministic and can be rerun.
#
# http replays the same sequences as MCP tools/call requests against a
# running site (tools/bench/http.php): real response bytes and latency.
# Fixtures are seeded through WP-CLI in --wp-path and removed after every
# replay; on exit the site's exposure mode is put back and the fixtures are
# pruned again. Use a disposable local site: the scenarios write.
#
# Not part of the test gate. While it runs it holds a run slot of the gate
# ($WPMCP_TEST_CACHE/locks/run-<n>), so a gate queues behind it instead of
# competing for the machine, and it waits while two PHPUnit runs are already
# going. Under 10 GB free on the cache's volume it stops before starting.
#
# --out writes the results JSON (the baseline is one); the table goes to
# stdout either way unless --json. --save-tools-list writes the tools/list
# payloads for tools/bench/calibrate-tokens.php. --prune removes what dead
# runs left behind: temporary result directories, and with --mode=http
# --wp-path the site fixtures.
#
# REPO-ONLY, like the rest of bin/.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)

CACHE=${WPMCP_TEST_CACHE:-$HOME/.cache/wpmcp-tests}
MAX_PARALLEL=${WPMCP_TEST_MAX_PARALLEL:-1}
SLOT_TIMEOUT=${WPMCP_TEST_SLOT_TIMEOUT:-3600}
HARD_MIN_FREE_GB=${WPMCP_TEST_HARD_MIN_FREE_GB:-10}
TMP_ROOT=${TMPDIR:-/tmp}
TMP_ROOT=${TMP_ROOT%/}
PREFIX=wpmcp-code-mode-bench
PHP=${WPMCP_TEST_PHP:-php}

say() { printf '\033[1m[bench-code-mode]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31m[bench-code-mode]\033[0m %s\n' "$*" >&2; exit 1; }

mode=inprocess scenario="" format=table out="" save_tools="" prune=false
url="" user="" password="" wp_path="" wp_cli=wp plain=""
for arg in "$@"; do
	case "$arg" in
		--mode=*) mode=${arg#*=} ;;
		--scenario=*) scenario=${arg#*=} ;;
		--json) format=json ;;
		--markdown) format=markdown ;;
		--out=*) out=${arg#*=} ;;
		--save-tools-list=*) save_tools=${arg#*=} ;;
		--prune) prune=true ;;
		--url=*) url=${arg#*=} ;;
		--user=*) user=${arg#*=} ;;
		--password=*) password=${arg#*=} ;;
		--wp-path=*) wp_path=${arg#*=} ;;
		--wp-cli=*) wp_cli=${arg#*=} ;;
		--plain-permalinks) plain=--plain-permalinks ;;
		-h | --help) sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) die "unknown option: $arg (see --help)" ;;
	esac
done
case "$mode" in inprocess | http) ;; *) die "--mode is inprocess or http, not $mode" ;; esac
[ -z "$scenario" ] || [[ "$scenario" =~ ^[1-9][0-9]*$ ]] || die "--scenario takes a scenario number"
[ -z "$save_tools" ] || [ -d "$save_tools" ] || die "--save-tools-list needs an existing directory"

pid_alive() { [ -n "$1" ] && kill -0 "$1" 2>/dev/null; }

prune_tmp() {
	local dir pid
	for dir in "$TMP_ROOT/$PREFIX".*; do
		[ -d "$dir" ] || continue
		pid=$(cat "$dir/pid" 2>/dev/null || true)
		pid_alive "$pid" && continue
		rm -rf "$dir"
		say "removed $dir"
	done
}

remote_prune() {
	[ -n "$wp_path" ] || return 0
	"$wp_cli" --path="$wp_path" eval-file "$ROOT/tools/bench/remote.php" prune >&2 || say "pruning the site fixtures failed"
}

if [ "$prune" = true ]; then
	prune_tmp
	[ "$mode" != http ] || remote_prune
	exit 0
fi

# ------------------------------------------------------------- guards

free_gb=$(df -Pk "$(dirname "$CACHE")" | awk 'NR == 2 { printf "%d", $4 / 1048576 }')
[ "$free_gb" -ge "$HARD_MIN_FREE_GB" ] || die "only ${free_gb} GB free (under ${HARD_MIN_FREE_GB} GB): free some space first, nothing was started"

prune_tmp
RUN_DIR=$(mktemp -d "$TMP_ROOT/$PREFIX.XXXXXX")
echo $$ >"$RUN_DIR/pid"
SLOT=""
CHILD=""
ORIGINAL_EXPOSURE=""
EXPOSURE_SAVED=false

finish() {
	local status=$?
	if [ -n "$CHILD" ] && pid_alive "$CHILD"; then
		kill "$CHILD" 2>/dev/null || true
		wait "$CHILD" 2>/dev/null || true
	fi
	if [ "$mode" = http ] && [ -n "$wp_path" ]; then
		if [ "$EXPOSURE_SAVED" = true ]; then
			if [ -n "$ORIGINAL_EXPOSURE" ]; then
				"$wp_cli" --path="$wp_path" option update wpmcp_tool_exposure_mode "$ORIGINAL_EXPOSURE" >/dev/null 2>&1 || say "could not restore the exposure mode ($ORIGINAL_EXPOSURE)"
			else
				"$wp_cli" --path="$wp_path" option delete wpmcp_tool_exposure_mode >/dev/null 2>&1 || true
			fi
		fi
		remote_prune
	fi
	[ -z "$SLOT" ] || rm -rf "$SLOT"
	rm -rf "$RUN_DIR"
	exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# A run slot of the gate, taken the way bin/test-local.sh takes one (a lock
# directory holding the pid; a dead holder's slot is taken over).
acquire_slot() {
	local n lock holder waited=0 said=false
	mkdir -p "$CACHE/locks"
	while :; do
		for n in $(seq 1 "$MAX_PARALLEL"); do
			lock="$CACHE/locks/run-$n"
			if mkdir "$lock" 2>/dev/null; then
				echo $$ >"$lock/pid"
				SLOT=$lock
				return 0
			fi
			holder=$(cat "$lock/pid" 2>/dev/null || true)
			if [ -n "$holder" ] && ! pid_alive "$holder"; then
				rm -rf "$lock"
			fi
		done
		[ "$waited" -lt "$SLOT_TIMEOUT" ] || die "timed out after ${SLOT_TIMEOUT}s waiting for a run slot of the gate"
		[ "$said" = true ] || { say "waiting for a run slot of the gate (a gate is running)"; said=true; }
		sleep 5
		waited=$((waited + 5))
	done
}

# At most two heavy runs on the machine.
wait_for_phpunit() {
	local waited=0 said=false
	while [ "$(pgrep -f 'vendor/bin/phpunit' | wc -l | tr -d ' ')" -ge 2 ]; do
		[ "$waited" -lt "$SLOT_TIMEOUT" ] || die "timed out after ${SLOT_TIMEOUT}s waiting for running PHPUnit processes to finish"
		[ "$said" = true ] || { say "two PHPUnit runs are going; waiting"; said=true; }
		sleep 5
		waited=$((waited + 5))
	done
}

RESULTS="$RUN_DIR/results.json"
export WPMCP_BENCH_COMMIT
WPMCP_BENCH_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || true)

# --------------------------------------------------------------- run

status=0
if [ "$mode" = inprocess ]; then
	acquire_slot
	wait_for_phpunit
	say "in-process run on the test gate's WordPress install${scenario:+, scenario $scenario}"
	WPMCP_BENCH_OUT="$RESULTS" WPMCP_BENCH_SCENARIO="$scenario" WPMCP_BENCH_SAVE_TOOLS_LIST="$save_tools" \
		bash bin/test-local.sh -- tools/bench/BenchTest.php >"$RUN_DIR/phpunit.log" 2>&1 &
	CHILD=$!
	wait "$CHILD" || status=$?
	CHILD=""
	if [ "$status" -ne 0 ]; then
		grep -E '^[0-9]+\) |^(step|verify|compact)|FAILURES|Tests:|Fatal|PHP Parse' "$RUN_DIR/phpunit.log" >&2 || tail -40 "$RUN_DIR/phpunit.log" >&2
	fi
else
	[ -n "$url" ] && [ -n "$user" ] && [ -n "$password" ] || die "--mode=http needs --url, --user and --password (an application password)"
	if [ -n "$wp_path" ]; then
		command -v "$wp_cli" >/dev/null 2>&1 || die "WP-CLI ($wp_cli) is not installed; pass --wp-cli=PATH"
		ORIGINAL_EXPOSURE=$("$wp_cli" --path="$wp_path" option get wpmcp_tool_exposure_mode 2>/dev/null || true)
		EXPOSURE_SAVED=true
	else
		say "no --wp-path: only the tools/list of the site's current mode is measured, every scenario is skipped"
	fi
	acquire_slot
	say "HTTP run against $url${scenario:+, scenario $scenario}"
	"$PHP" tools/bench/http.php --url="$url" --user="$user" --password="$password" \
		${wp_path:+--wp-path="$wp_path"} --wp-cli="$wp_cli" ${scenario:+--scenario="$scenario"} \
		${save_tools:+--save-tools-list="$save_tools"} ${plain:+$plain} --json="$RESULTS" &
	CHILD=$!
	wait "$CHILD" || status=$?
	CHILD=""
fi

[ -s "$RESULTS" ] || die "the run wrote no results (exit $status)"
[ -z "$out" ] || cp "$RESULTS" "$out"
case "$format" in
	json) cat "$RESULTS" ;;
	markdown) "$PHP" tools/bench/report.php "$RESULTS" --markdown ;;
	*) "$PHP" tools/bench/report.php "$RESULTS" ;;
esac
[ "$status" -eq 0 ] || say "some scenarios failed (exit $status); they are marked in the table"
exit "$status"
