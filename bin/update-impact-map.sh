#!/usr/bin/env bash
#
# Refreshes tests/impact-map.json, the committed fallback of the test impact
# map that PR runs of the PHPUnit workflow select tests with
# (.github/ci/impact.php). CI keeps a fresher copy in its cache (impact-map-*,
# rebuilt by every full run on main); this file is what a PR uses when that
# cache is gone, and impact.php refuses either once it is 14 days old.
#
#   bin/update-impact-map.sh                   the impact-map artifact of the
#                                              newest main run that has one
#   bin/update-impact-map.sh --run <id>        that run's impact-map artifact
#   bin/update-impact-map.sh --coverage <id>   build it from that run's coverage
#                                              shards (needs vendor/ and about
#                                              1 GB of downloads, removed after)
#   bin/update-impact-map.sh --prune           remove leftovers of killed runs
#
# Downloads go to a temporary directory that is removed on exit, failure or
# Ctrl-C. Commit the result with the change that needs it, or on its own.
set -euo pipefail
cd "$(dirname "$0")/.."

REPO=wpmcp/wordpress-mcp
TMP_ROOT=${TMPDIR:-/tmp}
PREFIX=wpmcp-impact-map

die() { echo "update-impact-map: $*" >&2; exit 1; }

case "${1:-}" in
	--prune)
		find "$TMP_ROOT" -maxdepth 1 -name "$PREFIX.*" -type d -mmin +60 -print -exec rm -rf {} +
		exit 0
		;;
	--run | --coverage) [ -n "${2:-}" ] || die "$1 needs a run id" ;;
	"") ;;
	*) die "usage: $0 [--run <id> | --coverage <id> | --prune]" ;;
esac

work=$(mktemp -d "$TMP_ROOT/$PREFIX.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM

if [ "${1:-}" = --coverage ]; then
	[ -f vendor/autoload.php ] || die "run composer install first"
	run=$2
	sha=$(gh api "repos/$REPO/actions/runs/$run" --jq .head_sha)
	gh run download "$run" --repo "$REPO" --pattern 'unit-phpunit-default-*' --dir "$work"
	dirs=()
	while IFS= read -r d; do dirs+=("$d"); done < <(find "$work" -maxdepth 1 -type d -name 'unit-phpunit-default-*' | sort)
	[ "${#dirs[@]}" -eq 4 ] || die "run $run has ${#dirs[@]} coverage shards, expected 4 (expired after 3 days?)"
	php -d memory_limit=-1 .github/ci/impact-map.php tests/impact-map.json "$sha" "PHPUnit run $run" "${dirs[@]}"
else
	run=${2:-}
	if [ -z "$run" ]; then
		for id in $(gh run list --repo "$REPO" --workflow PHPUnit --branch main --status completed --limit 20 --json databaseId --jq '.[].databaseId'); do
			if gh api "repos/$REPO/actions/runs/$id/artifacts" --jq '.artifacts[] | select(.name == "impact-map" and (.expired | not)) | .id' | grep -q .; then
				run=$id
				break
			fi
		done
		[ -n "$run" ] || die "no recent main run has an impact-map artifact; use --coverage <run id>"
	fi
	gh run download "$run" --repo "$REPO" --name impact-map --dir "$work"
	php -r '$m = json_decode(file_get_contents($argv[1]), true); exit(is_array($m) && !empty($m["files"]) ? 0 : 1);' "$work/impact-map.json" \
		|| die "the impact-map artifact of run $run is not a valid map"
	cp "$work/impact-map.json" tests/impact-map.json
fi
php -r '$m = json_decode(file_get_contents("tests/impact-map.json"), true); printf("tests/impact-map.json: %d src files, %d test files, built %s at %s\n", count($m["files"]), count($m["tests"]), $m["generated_at"], substr($m["commit"], 0, 8));'
