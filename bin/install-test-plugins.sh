#!/usr/bin/env bash
#
# Install optional third-party plugins into the WordPress test install so their
# parity areas can be exercised by the integration suite.
#
# Installs Elementor, WooCommerce, Advanced Custom Fields, Yoast SEO, and
# Polylang from the wordpress.org plugin repository or, with WPMCP_LIVE_FORMS=1,
# Contact Form 7 and Flamingo only (bin/test-local.sh --live-forms), or, with
# WPMCP_LIVE_BLOCKS=1, Kadence Blocks, GenerateBlocks, Spectra and Otter Blocks
# only (bin/test-local.sh --live-blocks). The script
# is idempotent: plugins already present are left untouched, so it is safe to
# run repeatedly both locally and from CI.
#
# Elementor is pinned (ELEMENTOR_VERSION, default below) because the suite
# asserts against its live atomic-widget schema and runs alongside its own
# MCP module: an unpinned install let Elementor 4.3.0 (2026-09-22) turn every
# open PR red overnight with no change on our side. Set ELEMENTOR_VERSION=latest
# to test against the newest release instead: ELEMENTOR_VERSION=latest
# bin/test-local.sh runs the suite on a separate install with it, so drift is
# checked on demand without disturbing the pinned install.
# When moving the pin, re-record tests/support/elementor-atomic-prop-fixtures.php.
# The other plugins still track latest stable.
#
# The plugins are only downloaded here. Activation happens in tests/bootstrap.php,
# which requires each plugin's main file when it is present and skips it when it
# is not. That keeps the suite runnable even if this script was never run.

set -euo pipefail

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}
WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
PLUGINS_DIR="$WP_CORE_DIR/wp-content/plugins"
ELEMENTOR_VERSION=${ELEMENTOR_VERSION:-4.3.2}
if [ "$ELEMENTOR_VERSION" != "latest" ] && ! [[ "$ELEMENTOR_VERSION" =~ ^[0-9]+(\.[0-9]+){1,3}(-[A-Za-z0-9.]+)?$ ]]; then
	echo "ELEMENTOR_VERSION must be 'latest' or a version such as 4.3.2 (got '$ELEMENTOR_VERSION')." >&2
	exit 1
fi

# slug => plugin main file relative to the plugin directory.
# Used to sanity-check an install without unpacking assumptions elsewhere.
PLUGINS=(
	"elementor:elementor.php"
	"woocommerce:woocommerce.php"
	"advanced-custom-fields:acf.php"
	"wordpress-seo:wp-seo.php"
	"polylang:polylang.php"
)

# The live forms job (issue #66) runs the Contact Form 7 adapter against the
# real Contact Form 7 and Flamingo instead of the harness doubles. The two sets
# are exclusive on purpose: with the real plugins loaded the doubles stand
# down (real classes always win), so the stub-backed suite cannot share a run
# with them.
if [ "${WPMCP_LIVE_FORMS:-}" = "1" ]; then
	PLUGINS=(
		"contact-form-7:wp-contact-form-7.php"
		"flamingo:flamingo.php"
	)
fi

# The live blocks job (issue #287) renders blocks inserted through the
# block-suites tools with the real block suites loaded. Exclusive for the same
# reason as the forms set: the stub-backed suite registers stand-in block types
# under the suites' names and fakes their presence, which the real plugins
# would contradict.
if [ "${WPMCP_LIVE_BLOCKS:-}" = "1" ]; then
	PLUGINS=(
		"kadence-blocks:kadence-blocks.php"
		"generateblocks:plugin.php"
		"ultimate-addons-for-gutenberg:ultimate-addons-for-gutenberg.php"
		"otter-blocks:otter-blocks.php"
	)
fi

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		exit 1
	fi
}

# Version to fetch for a slug: "latest" means the latest-stable zip.
plugin_version() {
	case "$1" in
		elementor) echo "$ELEMENTOR_VERSION" ;;
		*) echo "latest" ;;
	esac
}

# The Version header of a plugin main file.
header_version() {
	sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$1" | head -n 1 | tr -d '[:space:]'
}

install_plugin() {
	local slug=$1
	local main_file=$2
	local target="$PLUGINS_DIR/$slug"
	local version
	version=$(plugin_version "$slug")
	local ref="latest-stable"
	if [ "$version" != "latest" ]; then
		ref="$version"
	fi

	if [ -f "$target/$main_file" ]; then
		local present
		present=$(header_version "$target/$main_file")
		# A pinned plugin must be the pinned version, or the pin means nothing
		# on a reused install; "latest" keeps whatever is already there.
		if [ "$ref" = "latest-stable" ] || [ "$present" = "$version" ]; then
			echo "Plugin '$slug' ${present} already installed, skipping."
			return 0
		fi
		echo "Plugin '$slug' is ${present:-an unknown version}, pinned to ${version}: reinstalling."
		rm -rf "$target"
	fi

	echo "Installing plugin '$slug' ($ref) from wordpress.org..."
	local zip="$TMPDIR/${slug}.${ref}.zip"
	download "https://downloads.wordpress.org/plugin/${slug}.${ref}.zip" "$zip"
	unzip -q -o "$zip" -d "$PLUGINS_DIR"
	rm -f "$zip"

	if [ ! -f "$target/$main_file" ]; then
		echo "Expected '$target/$main_file' after install but it is missing." >&2
		exit 1
	fi
	local installed
	installed=$(header_version "$target/$main_file")
	echo "Installed plugin '$slug' ${installed}."
}

mkdir -p "$PLUGINS_DIR"

for entry in "${PLUGINS[@]}"; do
	install_plugin "${entry%%:*}" "${entry##*:}"
done

echo "Test plugins ready in $PLUGINS_DIR"
