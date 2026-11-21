#!/usr/bin/env bash
#
# The GitHub release asset set, with stable names that never change:
#
#   https://github.com/wpmcp/wordpress-mcp/releases/latest/download/wpmcp.zip
#   https://github.com/wpmcp/wordpress-mcp/releases/latest/download/safe-mcp-for-woocommerce.zip
#
# Those links are in the README, on wpmcp-pro.com and in docs, so every
# release must upload the unversioned copies next to the versioned zips. A
# release without them breaks the README's one-click install until the next
# release.
#
#   scripts/release-assets.sh stage [<version>]
#       After the builds (build-release.sh, build-woo-release.sh,
#       build-wporg-release.sh). Checks each zip is the build it claims to be,
#       copies the unversioned files into dist/, and prints the files to
#       upload, in order. <version> defaults to WPMCP_VERSION in wpmcp.php.
#   scripts/release-assets.sh notes [<version>]
#       Prints the "### Downloads" section for the release notes.
#   scripts/release-assets.sh verify <tag>
#       After publishing. Fails unless the release <tag> on GitHub carries
#       every asset, with each unversioned copy the same size as its versioned
#       twin; for the latest release it also follows each
#       releases/latest/download link and requires a 200.
#
# The asset set is release_asset_table in scripts/lib/release-flavors.sh
# (first row is the headline download). Today:
#
#   wpmcp-<v>.zip                      the plugin: free until a licence is
#                                      active, with the upgrade banner
#                                      (scripts/build-release.sh); also the
#                                      zip deployed to Freemius
#   wpmcp.zip                          same bytes, stable name
#   safe-mcp-for-woocommerce-<v>.zip   the wp.org WooCommerce flavor
#   safe-mcp-for-woocommerce.zip       same bytes, stable name
#   wpmcp-directory-<v>.zip            the wordpress.org directory cut, paid
#                                      tier and licensing removed
#
# Up to 1.2.0 the headline wpmcp.zip was the directory cut and the banner
# build was published as wpmcp-pro-<v>.zip; that name is retired.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO="${WPMCP_GITHUB_REPO:-wpmcp/wordpress-mcp}"
DIST="$ROOT/dist"
# shellcheck source=scripts/lib/release-flavors.sh
. "$ROOT/scripts/lib/release-flavors.sh"

die() { echo "release-assets: ERROR: $*" >&2; exit 1; }

current_version() { sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php" | head -1; }

# The asset names for a version, headline first: each versioned zip, then
# its stable copy when it has one.
asset_names() {
	local v=$1 prefix main kind stable text
	while IFS='|' read -r prefix main kind stable text; do
		echo "$prefix-$v.zip"
		[ "$stable" = - ] || echo "$stable"
	done < <(release_asset_table)
}

stage() {
	local v=$1 prefix main kind stable text zip
	while IFS='|' read -r prefix main kind stable text; do
		zip="$DIST/$prefix-$v.zip"
		[ -s "$zip" ] || die "missing $zip; run the builds first (scripts/build-release.sh, build-woo-release.sh, build-wporg-release.sh)"
		case "$kind" in
			self-hosted) assert_self_hosted_zip "$zip" "$main" "$v" || die "$zip failed the self-hosted check" ;;
			wporg) assert_wporg_zip "$zip" "$main" "$v" || die "$zip failed the wp.org check" ;;
			*) die "unknown kind '$kind' for $prefix in release_asset_table" ;;
		esac
		if [ "$stable" != - ]; then
			cp "$zip" "$DIST/$stable"
			cmp -s "$zip" "$DIST/$stable" || die "dist/$stable differs from $zip"
		fi
	done < <(release_asset_table)

	echo "release-assets: $v staged; upload these, in this order:"
	local name
	while IFS= read -r name; do
		[ -s "$DIST/$name" ] || die "missing dist/$name"
		echo "$DIST/$name"
	done < <(asset_names "$v")
}

notes() {
	local v=$1 prefix main kind stable text
	printf '### Downloads\n\n'
	while IFS='|' read -r prefix main kind stable text; do
		if [ "$stable" = - ]; then
			printf -- '- `%s-%s.zip`: %s\n' "$prefix" "$v" "$text"
		else
			printf -- '- `%s-%s.zip` (also `%s`, the same file): %s\n' "$prefix" "$v" "$stable" "$text"
		fi
	done < <(release_asset_table)
}

verify() {
	local tag=$1 v json latest name missing="" prefix main kind stable text sa sb url code
	v=${tag#v}
	json=$(gh api "repos/$REPO/releases/tags/$tag") || die "no release $tag on $REPO"
	size_of() { jq -r --arg n "$1" '.assets[] | select(.name == $n) | .size' <<<"$json"; }
	while IFS= read -r name; do
		[ -n "$(size_of "$name")" ] || missing="$missing $name"
	done < <(asset_names "$v")
	[ -z "$missing" ] || die "release $tag is missing:$missing"
	while IFS='|' read -r prefix main kind stable text; do
		[ "$stable" != - ] || continue
		sa=$(size_of "$stable")
		sb=$(size_of "$prefix-$v.zip")
		[ "$sa" = "$sb" ] || die "$stable ($sa bytes) is not the same file as $prefix-$v.zip ($sb bytes) in $tag"
	done < <(release_asset_table)
	latest=$(gh api "repos/$REPO/releases/latest" --jq .tag_name)
	if [ "$latest" = "$tag" ]; then
		while IFS='|' read -r prefix main kind stable text; do
			[ "$stable" != - ] || continue
			url="https://github.com/$REPO/releases/latest/download/$stable"
			code=$(curl -s -o /dev/null -L -w '%{http_code}' --max-time 60 "$url")
			[ "$code" = 200 ] || die "$url answers $code"
		done < <(release_asset_table)
		echo "release-assets: $tag OK, and the releases/latest/download links resolve"
	else
		echo "release-assets: $tag OK (not the latest release, $latest is; latest links not checked)"
	fi
}

case "${1:-}" in
	stage) v=${2:-$(current_version)}; [ -n "$v" ] || die "no version"; stage "$v" ;;
	notes) v=${2:-$(current_version)}; [ -n "$v" ] || die "no version"; notes "$v" ;;
	verify) [ -n "${2:-}" ] || die "usage: release-assets.sh verify <tag>"; verify "$2" ;;
	*) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 2 ;;
esac
