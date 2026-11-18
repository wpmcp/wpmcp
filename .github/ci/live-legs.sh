#!/usr/bin/env bash
#
# The free live legs CI runs, split into groups (one CI job each).
#
#   live-legs.sh --list            every free leg kind, one per line
#   live-legs.sh --list --group 2  the leg kinds of group 2 of $LIVE_GROUPS
#
# The legs come from the leg table, bin/legs.tsv, through
# `bin/test-local.sh --list-legs=free --json`, so a new free leg reaches CI
# with no change here. Licensed legs are never asked for (tier "free" only),
# and .github/ci/licensed-guard.sh checks this output as well.
#
# Groups are balanced by a rough weight (live legs cost about the same: setup,
# not tests), heavier for the legs that install many plugins or run PHPUnit
# more than once. Longest first, each to the lightest group, ties by name, so
# the split is stable.
set -euo pipefail
cd "$(dirname "$0")/../.."
GROUPS_N=${LIVE_GROUPS:-3}

legs_json() { bash bin/test-local.sh --list-legs=free --json; }

case "${1:-}" in
	--list)
		group=""
		[ "${2:-}" != --group ] || group=${3:?group number}
		# shellcheck disable=SC2016 # PHP code, expanded by PHP.
		legs_json | php -r '
			$legs = json_decode(stream_get_contents(STDIN), true);
			if (!is_array($legs) || !$legs) { fwrite(STDERR, "live-legs: no free legs listed\n"); exit(1); }
			$weight = ["live-site-gate" => 3, "live-forms" => 2.5, "live-lms" => 2, "live-funnelkit" => 1.5, "live-crm" => 1.5, "live-helpdesk" => 1.5, "live-polylang" => 1.5];
			$n = (int) $argv[1]; $want = $argv[2];
			$kinds = array_values(array_unique(array_map(fn($l) => $l["kind"], array_filter($legs, fn($l) => ($l["tier"] ?? "") === "free"))));
			usort($kinds, fn($a, $b) => ($weight[$b] ?? 1) <=> ($weight[$a] ?? 1) ?: strcmp($a, $b));
			$load = array_fill(1, $n, 0.0); $in = [];
			foreach ($kinds as $k) { $g = array_keys($load, min($load))[0]; $in[$k] = $g; $load[$g] += $weight[$k] ?? 1; }
			ksort($in);
			foreach ($in as $k => $g) { if ($want === "" || (int) $want === $g) { echo $k, "\n"; } }
		' "$GROUPS_N" "$group"
		;;
	*) echo "usage: $0 --list [--group <n>]" >&2; exit 2 ;;
esac
