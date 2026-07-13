#!/usr/bin/env bash
#
# Install optional third-party plugins into the WordPress test install so their
# parity areas can be exercised by the integration suite.
#
# Currently installs the latest stable Elementor, WooCommerce, Advanced
# Custom Fields, and Yoast SEO from the wordpress.org plugin repository. The
# script is idempotent: plugins already present are left untouched, so it is
# safe to run repeatedly both locally and from CI.
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

# slug => plugin main file relative to the plugin directory.
# Used to sanity-check an install without unpacking assumptions elsewhere.
PLUGINS=(
	"elementor:elementor.php"
	"woocommerce:woocommerce.php"
	"advanced-custom-fields:acf.php"
	"wordpress-seo:wp-seo.php"
)

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sL "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		exit 1
	fi
}

install_plugin() {
	local slug=$1
	local main_file=$2
	local target="$PLUGINS_DIR/$slug"

	if [ -f "$target/$main_file" ]; then
		echo "Plugin '$slug' already installed, skipping."
		return 0
	fi

	echo "Installing plugin '$slug' from wordpress.org..."
	local zip="$TMPDIR/${slug}.latest-stable.zip"
	download "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "$zip"
	unzip -q -o "$zip" -d "$PLUGINS_DIR"
	rm -f "$zip"

	if [ ! -f "$target/$main_file" ]; then
		echo "Expected '$target/$main_file' after install but it is missing." >&2
		exit 1
	fi
	echo "Installed plugin '$slug'."
}

mkdir -p "$PLUGINS_DIR"

for entry in "${PLUGINS[@]}"; do
	install_plugin "${entry%%:*}" "${entry##*:}"
done

echo "Test plugins ready in $PLUGINS_DIR"
