# shellcheck shell=bash
#
# The release asset table and the per-kind zip checks, shared by
# scripts/build-release.sh, the wp.org flavor builds and
# scripts/release-assets.sh. Source it; it defines functions and a table only.
#
# A new flavor (for example a WooCommerce-style vertical for another
# ecosystem) gets its GitHub asset, its stable latest/download name, its
# release-notes line and its zip checks by adding one row to
# release_asset_table and calling assert_wporg_zip (or assert_self_hosted_zip)
# at the end of its build.

# The header every self-hosted build carries and no wp.org build may carry.
# It stops WordPress offering a same-slug wordpress.org package as an update
# to a self-hosted install; wp.org rejects any value other than its own URL.
WPMCP_UPDATE_URI='https://wpmcp-pro.com/'

# One row per release asset, headline first:
#   versioned-prefix | main file inside the zip | kind | stable name | notes text
# kind: self-hosted (licensing SDK, banner, Update URI) or wporg (none of them).
# stable name: the unversioned copy published every release, so
# releases/latest/download/<stable> always works; "-" for none.
# The versioned asset is <prefix>-<version>.zip.
release_asset_table() {
	cat <<'TABLE'
wpmcp|wpmcp/wpmcp.php|self-hosted|wpmcp.zip|the WP MCP plugin. Free to use; upload it under Plugins > Add New > Upload Plugin. Pro unlocks in the same plugin when you add a licence.
safe-mcp-for-woocommerce|safe-mcp-for-woocommerce/safe-mcp-for-woocommerce.php|wporg|safe-mcp-for-woocommerce.zip|Safe MCP for WooCommerce, store tools and the safety core only.
wpmcp-directory|wpmcp/wpmcp.php|wporg|-|the wordpress.org directory edition, without the licensing SDK or Pro features.
TABLE
}

# _rf_zip_has <zip> <path>: the zip lists that exact path. The listing is
# captured first: with pipefail, grep -q closing a pipe early can fail the
# pipeline on a match.
_rf_zip_has() {
	local listing
	listing=$(unzip -Z1 "$1") || return 2
	grep -Fxq -- "$2" <<<"$listing"
}

# _rf_header <zip> <main>: the first 40 lines of the main file.
_rf_header() { unzip -p "$1" "$2" | sed -n '1,40p'; }

# assert_self_hosted_zip <zip> <main> [<version>]
# Prints the reason and returns 1 unless the zip carries the Update URI header,
# the upgrade banner, the Pro gate and the licensing SDK.
assert_self_hosted_zip() {
	local zip=$1 main=$2 ver=${3:-} hdr root p
	root=${main%%/*}
	_rf_zip_has "$zip" "$main" || { echo "$zip has no $main; it is not a plugin build" >&2; return 1; }
	hdr=$(_rf_header "$zip" "$main")
	if [ -n "$ver" ] && ! grep -q "^ \* Version: $ver\$" <<<"$hdr"; then
		echo "$zip is not version $ver" >&2; return 1
	fi
	grep -qF " * Update URI: $WPMCP_UPDATE_URI" <<<"$hdr" \
		|| { echo "$main in $zip has no 'Update URI: $WPMCP_UPDATE_URI' header" >&2; return 1; }
	for p in src/Admin/Pro_Banner.php src/Pro/Gate.php vendor/freemius/wordpress-sdk/start.php; do
		_rf_zip_has "$zip" "$root/$p" || { echo "$zip is not the banner build: $root/$p is missing" >&2; return 1; }
	done
}

# assert_wporg_zip <zip> <main> [<version>]
# Prints the reason and returns 1 if the zip carries an Update URI header, the
# licensing SDK or the upgrade banner.
assert_wporg_zip() {
	local zip=$1 main=$2 ver=${3:-} hdr listing
	_rf_zip_has "$zip" "$main" || { echo "$zip has no $main" >&2; return 1; }
	hdr=$(_rf_header "$zip" "$main")
	if [ -n "$ver" ] && ! grep -q "^ \* Version: $ver\$" <<<"$hdr"; then
		echo "$zip is not version $ver" >&2; return 1
	fi
	if grep -qi 'Update URI:' <<<"$hdr"; then
		echo "$zip carries an Update URI header; wordpress.org rejects it" >&2; return 1
	fi
	listing=$(unzip -Z1 "$zip") || return 1
	if grep -q '/vendor/freemius/\|/src/Admin/Pro_Banner\.php$' <<<"$listing"; then
		echo "$zip carries the licensing SDK or the upgrade banner" >&2; return 1
	fi
}

# assert_no_update_uri <main file on disk>: for a staged wp.org main file,
# before it is zipped.
assert_no_update_uri() {
	if grep -qi '^[ *]*Update URI:' "$1"; then
		echo "$1 carries an Update URI header; wordpress.org rejects it" >&2; return 1
	fi
}
