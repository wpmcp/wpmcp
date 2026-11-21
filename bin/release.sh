#!/usr/bin/env bash
#
# Patch release driver (handoff/2026-11-18/gate-strategy.md, section 6).
# Runs on the Mac. Each phase checks whether it is already done, so a failed
# release resumes at the phase that failed.
#
#   bin/release.sh preflight <version>  on main, clean, equal to origin/main;
#                                       <version> semver and above WPMCP_VERSION;
#                                       disk above 20 GB; GitHub checks green on
#                                       HEAD; licensed-live status (warns when
#                                       missing); the fast tier and the
#                                       baseline gate (below)
#   bin/release.sh bump <version>       branch release/<version>; Version header
#                                       and WPMCP_VERSION in wpmcp.php, Stable
#                                       tag in readme.txt; refuses a changelog
#                                       without a <version> entry or with an em
#                                       or en dash; fast checks
#   bin/release.sh pr <version>         commit, push, open the PR, wait for its
#                                       checks, merge with a merge commit
#   bin/release.sh tag <version>        annotated v<version> tag on main, pushed
#                                       (starts the tag workflow once it exists)
#   bin/release.sh all <version>        the four in order
#
# Not automated here (secrets stay local, owner confirms): the Freemius deploy
# (handoff/2026-11-16/release/freemius-deploy-local.sh pattern), publishing the
# GitHub release, the MCP registry publish. The script prints them at the end.
#
# Flags:
#   --dry-run      print what would change or run; push, merge and tag nothing
#   --skip-gate    preflight without the local gate (CI checks still required);
#                  for an emergency only
#   --allow-red=<regex>
#                  accept failing GitHub checks whose name matches the
#                  extended regex (known failures with an open issue); they
#                  are listed, any other red check still stops the release,
#                  and the pr phase then merges with --admin
#   --wait=<min>   preflight and pr wait up to <min> minutes (default 30) for
#                  running checks instead of stopping
#
# The gate in preflight, after the TableCrafter tools/release.sh preflight:
#   1. the fast tier: `bin/test-local.sh --unit` when the local runner has it
#      (no database, about a minute), else composer lint, drift:check and
#      check:flavors;
#   2. the baseline gate: the plain bin/test-local.sh run, which fails only on
#      failures not listed in tests/phpunit-baseline-<leg>.txt once those
#      baselines exist (plain pass or fail until then). Its log is kept in
#      $TMPDIR and removed on success.

set -euo pipefail
cd "$(dirname "$0")/.."

REPO=wpmcp/wordpress-mcp
DRYRUN=0
SKIP_GATE=0
ALLOW_RED=""
WAIT_MIN=30
ARGS=()
for arg in "$@"; do
	case "$arg" in
		--dry-run) DRYRUN=1 ;;
		--skip-gate) SKIP_GATE=1 ;;
		--allow-red=*) ALLOW_RED=${arg#--allow-red=} ;;
		--wait=*) WAIT_MIN=${arg#--wait=} ;;
		-h | --help) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
		*) ARGS+=("$arg") ;;
	esac
done
PHASE=${ARGS[0]:-}
VER=${ARGS[1]:-}

die() { echo "[release] ERROR: $*" >&2; exit 1; }
info() { echo "[release] $*"; }
warn() { echo "[release] WARNING: $*" >&2; }
run() { if [ "$DRYRUN" = 1 ]; then echo "  DRY: $*"; else "$@"; fi; }

current_version() { sed -n "s/^define( 'WPMCP_VERSION', '\([^']*\)' );/\1/p" wpmcp.php | head -1; }

require_ver() {
	[[ "$VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must be X.Y.Z (got '${VER:-}')"
}

version_gt() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]; }

# Fails on an em dash or an en dash in the given files (house rule).
no_dashes() {
	if grep -n $'\xe2\x80\x94\|\xe2\x80\x93' "$@"; then
		die "em or en dash in $* (see the lines above); use a hyphen, comma, colon or parentheses"
	fi
}

# Reads "<status> <conclusion> <name>" check lines on stdin and prints the
# completed ones that are red and not excused by --allow-red.
red_filter() {
	local line name
	while IFS= read -r line; do
		[[ "$line" == completed\ * ]] || continue
		[[ "$line" =~ ^completed\ (success|skipped|neutral)\  ]] && continue
		name=${line#completed }
		name=${name#* }
		if [ -n "$ALLOW_RED" ] && grep -qE -- "$ALLOW_RED" <<<"$name"; then
			continue
		fi
		echo "$line"
	done
	return 0
}

# Check lines for a commit, waiting up to WAIT_MIN minutes while any runs.
commit_checks() {
	local sha=$1 runs deadline
	deadline=$(($(date +%s) + WAIT_MIN * 60))
	while :; do
		runs=$(gh api "repos/$REPO/commits/$sha/check-runs?per_page=100" --jq '.check_runs[] | "\(.status) \(.conclusion) \(.name)"')
		if [ -n "$runs" ] && ! grep -qv '^completed ' <<<"$runs"; then
			break
		fi
		[ "$(date +%s)" -lt "$deadline" ] || { echo "$runs"; return 1; }
		sleep 30
	done
	echo "$runs"
}

phase_preflight() {
	require_ver
	local cur sha free_gb
	[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "not on main"
	[ -z "$(git status --porcelain)" ] || die "working tree not clean"
	git fetch -q origin main
	[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main is not equal to origin/main (pull or push first)"
	cur=$(current_version)
	[ -n "$cur" ] || die "no WPMCP_VERSION in wpmcp.php"
	version_gt "$VER" "$cur" || die "$VER is not above the current $cur"

	free_gb=$(df -g /System/Volumes/Data 2>/dev/null | awk 'NR == 2 { print $4 }')
	if [ -n "$free_gb" ]; then
		[ "$free_gb" -ge 10 ] || die "only ${free_gb} GB free on /System/Volumes/Data; stop and free space"
		[ "$free_gb" -ge 20 ] || { warn "only ${free_gb} GB free; pruning the test cache first"; bash bin/test-local.sh --prune; }
	fi

	sha=$(git rev-parse HEAD)
	info "GitHub checks on $sha ..."
	local runs bad pending excused
	runs=$(commit_checks "$sha") || true
	[ -n "$runs" ] || die "no GitHub checks on $sha yet"
	pending=$(grep -v '^completed ' <<<"$runs" || true)
	[ -z "$pending" ] || die "checks still running on $sha after $WAIT_MIN minutes:"$'\n'"$pending"
	bad=$(red_filter <<<"$runs")
	[ -z "$bad" ] || die "checks not green on $sha:"$'\n'"$bad"
	excused=$(grep '^completed ' <<<"$runs" | grep -vE '^completed (success|skipped|neutral) ' || true)
	[ -z "$excused" ] || warn "red checks accepted by --allow-red:"$'\n'"$excused"
	info "$(wc -l <<<"$runs" | tr -d ' ') checks, none red outside --allow-red"

	local licensed
	licensed=$(gh api "repos/$REPO/commits/$sha/statuses" --jq '[.[] | select(.context == "licensed-live")][0].state // ""')
	case "$licensed" in
		success) info "licensed-live: success" ;;
		"") warn "no licensed-live status on $sha (the licensed legs did not run on this commit)" ;;
		*) die "licensed-live is $licensed on $sha" ;;
	esac

	if [ "$SKIP_GATE" = 1 ]; then
		warn "--skip-gate: the local fast tier and baseline gate are skipped"
	else
		local log
		log=$(mktemp "${TMPDIR:-/tmp}/wpmcp-release-gate.XXXXXX")
		if grep -q -- '--unit)' bin/test-local.sh; then
			info "fast tier (bin/test-local.sh --unit) ..."
			run bash bin/test-local.sh --unit
		else
			info "fast tier (composer lint, drift:check, check:flavors) ..."
			run composer lint
			run composer drift:check
			run composer check:flavors
		fi
		info "baseline gate (bin/test-local.sh), log $log ..."
		if [ "$DRYRUN" = 1 ]; then
			echo "  DRY: bash bin/test-local.sh"
		elif ! bash bin/test-local.sh >"$log" 2>&1; then
			tail -40 "$log"
			die "the gate failed; full log $log"
		fi
		rm -f "$log"
	fi
	info "preflight OK ($cur -> $VER on $sha)"
}

phase_bump() {
	require_ver
	local branch="release/$VER"
	if [ "$(git rev-parse --abbrev-ref HEAD)" != "$branch" ]; then
		if git rev-parse -q --verify "refs/heads/$branch" >/dev/null; then
			run git checkout "$branch"
		else
			run git checkout -b "$branch"
		fi
	fi
	if [ "$(current_version)" = "$VER" ]; then
		info "wpmcp.php already at $VER"
	elif [ "$DRYRUN" = 1 ]; then
		echo "  DRY: set Version and WPMCP_VERSION in wpmcp.php and Stable tag in readme.txt to $VER"
	else
		perl -0pi -e "s/^( \\* Version: )[0-9.]+/\${1}$VER/m; s/(define\\( 'WPMCP_VERSION', ')[0-9.]+(' \\);)/\${1}$VER\${2}/" wpmcp.php
		perl -0pi -e "s/^(Stable tag: )[0-9.]+/\${1}$VER/m" readme.txt
		[ "$(current_version)" = "$VER" ] || die "could not set WPMCP_VERSION in wpmcp.php"
		grep -q "^ \* Version: $VER\$" wpmcp.php || die "could not set the Version header in wpmcp.php"
		grep -q "^Stable tag: $VER\$" readme.txt || die "could not set Stable tag in readme.txt"
	fi
	# The changelog prose is written by hand (or by the release skill) before
	# this phase; the script only checks it is there and clean.
	# Captured first: with pipefail, grep -q closing the pipe early made awk
	# die of SIGPIPE and the check fail on a changelog that has the entry.
	local changelog
	changelog=$(awk '/^== Changelog ==/ { on = 1; next } /^== / { on = 0 } on' readme.txt)
	grep -q "^= $VER =" <<<"$changelog" \
		|| die "readme.txt has no '= $VER =' entry under == Changelog ==; write it, then run bump again"
	no_dashes readme.txt
	info "fast checks ..."
	run composer lint
	run composer drift:check
	run composer check:flavors
	info "bump OK"
}

phase_pr() {
	require_ver
	local branch="release/$VER" n
	[ "$(git rev-parse --abbrev-ref HEAD)" = "$branch" ] || die "not on $branch (run bump first)"
	if [ -n "$(git status --porcelain wpmcp.php readme.txt)" ]; then
		run git add wpmcp.php readme.txt
		run git commit -m "release: $VER"
	fi
	# The tree was gated in preflight and the bump changes versions only.
	WPMCP_SKIP_LOCAL_TESTS=1 run git push -u origin "$branch"
	n=$(gh pr list --repo "$REPO" --head "$branch" --state open --json number --jq '.[0].number // ""')
	if [ -z "$n" ]; then
		if [ "$DRYRUN" = 1 ]; then
			echo "  DRY: gh pr create --title 'release: $VER'"
			return 0
		fi
		gh pr create --repo "$REPO" --base main --head "$branch" --title "release: $VER" --body "Release $VER: version bump and changelog."
		n=$(gh pr list --repo "$REPO" --head "$branch" --state open --json number --jq '.[0].number')
	fi
	info "waiting for the checks of #$n ..."
	local head runs bad admin=()
	if [ "$DRYRUN" = 1 ]; then
		echo "  DRY: wait for the checks of #$n, then gh pr merge $n --merge"
		return 0
	fi
	head=$(gh pr view "$n" --repo "$REPO" --json headRefOid --jq .headRefOid)
	runs=$(commit_checks "$head") || die "checks of #$n still running after $WAIT_MIN minutes"
	bad=$(red_filter <<<"$runs")
	[ -z "$bad" ] || die "checks of #$n not green:"$'\n'"$bad"
	if grep '^completed ' <<<"$runs" | grep -qvE '^completed (success|skipped|neutral) '; then
		warn "merging #$n with --admin over red checks accepted by --allow-red"
		gh pr comment "$n" --repo "$REPO" --body "Merged with --admin: the red checks match --allow-red ($ALLOW_RED), known failures with open issues." >/dev/null
		admin=(--admin)
	fi
	gh pr merge "$n" --repo "$REPO" --merge ${admin[@]+"${admin[@]}"}
	run git checkout main
	run git pull -q --ff-only origin main
	info "pr OK (#$n merged)"
}

phase_tag() {
	require_ver
	local tag="v$VER"
	if git ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1; then
		info "$tag already on origin"
		return 0
	fi
	[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "not on main"
	[ "$(current_version)" = "$VER" ] || die "main is not at $VER (merge the release PR first)"
	git rev-parse -q --verify "refs/tags/$tag" >/dev/null || run git tag -a "$tag" -m "wpmcp $VER"
	WPMCP_SKIP_LOCAL_TESTS=1 run git push origin "$tag"
	info "tag OK ($tag)"
}

next_steps() {
	cat <<EOF
[release] Left to do by hand (secrets stay on this Mac):
  1. Build: scripts/build-release.sh, scripts/build-woo-release.sh, scripts/build-wporg-release.sh,
     then scripts/release-assets.sh stage $VER (copies dist/wpmcp.zip and
     dist/safe-mcp-for-woocommerce.zip, checks every zip, prints the upload list).
  2. Freemius: deploy dist/wpmcp-$VER.zip with the freemius-deploy-local.sh pattern, then mark it released.
  3. Publish the GitHub release v$VER (after Freemius has the version) with every file
     the stage step listed; put scripts/release-assets.sh notes $VER at the end of the notes.
  4. scripts/release-assets.sh verify v$VER (fails if wpmcp.zip or
     safe-mcp-for-woocommerce.zip is missing, which breaks the README download link).
  5. MCP registry: bump server.json to $VER, mcp-publisher login and publish.
EOF
}

case "$PHASE" in
	red-filter) red_filter ;;
	preflight) phase_preflight ;;
	bump) phase_bump ;;
	pr) phase_pr ;;
	tag) phase_tag; next_steps ;;
	all) phase_preflight; phase_bump; phase_pr; phase_tag; next_steps ;;
	*) die "usage: bin/release.sh <preflight|bump|pr|tag|all> <version> [--dry-run] [--skip-gate]" ;;
esac
