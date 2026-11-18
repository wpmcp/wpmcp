#!/usr/bin/env bash
#
# Fails when anything licensed is referenced from CI: a licensed leg name or
# plugin slug, a path variable for a local copy, or the directory of copies
# (patterns in .github/ci/licensed-patterns.txt). Licensed copies stay on the
# owner's Mac; the repository is public and so are its workflow runs.
#
# Checked: every file under .github/ except the pattern file, and the live
# legs CI would actually run (.github/ci/live-legs.sh --list), so a leg that
# reaches the CI matrix from another source is caught too.
#
# Usage: bash .github/ci/licensed-guard.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

patterns=.github/ci/licensed-patterns.txt
regex=$(grep -vE '^[[:space:]]*(#|$)' "$patterns" | paste -sd '|' -)
[ -n "$regex" ] || { echo "licensed-guard: no patterns in $patterns" >&2; exit 1; }
# Whole-token match: no letter or digit right before or after.
token="(^|[^A-Za-z0-9])($regex)([^A-Za-z0-9]|$)"

found=0
while IFS= read -r -d '' file; do
	[ "$file" = "$patterns" ] && continue
	if hits=$(grep -nEi -- "$token" "$file"); then
		printf '%s\n' "$hits" | sed "s|^|::error file=$file::licensed reference: $file:|"
		found=1
	fi
done < <(find .github -type f -print0)

if legs=$(bash .github/ci/live-legs.sh --list 2>/dev/null); then
	if hits=$(printf '%s\n' "$legs" | grep -nEi -- "$token"); then
		printf '%s\n' "$hits" | sed 's|^|::error::licensed leg in the CI live matrix: |'
		found=1
	fi
else
	echo "::error::licensed-guard: .github/ci/live-legs.sh --list failed"
	found=1
fi

if [ "$found" -ne 0 ]; then
	echo "licensed-guard: FAIL. Licensed legs run on the owner's Mac only (gate-strategy.md, Tier 2)." >&2
	exit 1
fi
echo "licensed-guard: OK, nothing licensed under .github/ or in the CI live legs."
