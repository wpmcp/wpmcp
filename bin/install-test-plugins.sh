#!/usr/bin/env bash
#
# Install optional third-party plugins into the WordPress test install so their
# parity areas can be exercised by the integration suite.
#
# Installs Elementor, WooCommerce, Advanced Custom Fields, Yoast SEO, and
# Polylang from the wordpress.org plugin repository or, with WPMCP_LIVE_FORMS=1,
# Contact Form 7 and Flamingo only (bin/test-local.sh --live-forms), or, with
# WPMCP_LIVE_BLOCKS=1, Kadence Blocks, GenerateBlocks, Spectra and Otter Blocks
# only (bin/test-local.sh --live-blocks), or, with WPMCP_LIVE_BUDDYPRESS=1,
# BuddyPress only (bin/test-local.sh --live-buddypress), or, with
# WPMCP_LIVE_TABLEPRESS=1, TablePress only (bin/test-local.sh --live-tablepress), or,
# with WPMCP_LIVE_CRM=1, FluentCRM and MailPoet only (bin/test-local.sh
# --live-crm), or, with WPMCP_LIVE_AMELIA=1, Amelia Lite only
# (bin/test-local.sh --live-amelia), or, with WPMCP_LIVE_FUNNELKIT=1,
# WooCommerce and FunnelKit Funnel Builder only (bin/test-local.sh
# --live-funnelkit), or, with WPMCP_LIVE_WC_SUBSCRIPTIONS=1, WooCommerce plus
# a local copy of WooCommerce Subscriptions (WPMCP_WC_SUBSCRIPTIONS_SRC) only
# (bin/test-local.sh --live-wc-subscriptions), or, with WPMCP_LIVE_DIVI=1, no
# plugin and the Divi 5 theme copied from WPMCP_DIVI_SRC (bin/test-local.sh
# --live-divi), or, with WPMCP_LIVE_THEME_PRO=astra|kadence|generatepress, no
# plugin but that theme's premium add-on copied from a local directory and
# the free theme from wordpress.org (bin/test-local.sh --live-theme-pro), or,
# with WPMCP_LIVE_WC_BOOKINGS=1, WooCommerce plus a local copy of WooCommerce
# Bookings (WPMCP_WC_BOOKINGS_SRC) only (bin/test-local.sh --live-wc-bookings),
# or, with WPMCP_LIVE_ELEMENTOR_PRO=1, Elementor plus a local copy of
# Elementor Pro (WPMCP_ELEMENTOR_PRO_SRC) only (bin/test-local.sh
# --live-elementor-pro), or, with WPMCP_LIVE_WP_MAIL_SMTP=1, WP Mail SMTP
# only (bin/test-local.sh --live-wp-mail-smtp).
# The script is idempotent: plugins already present are left untouched, so it
# is safe to run repeatedly both locally and from CI.
#
# WPMCP_PLUGIN_VERSIONS="slug=version ..." asks for exact versions instead
# (issue #532): bin/test-local.sh resolves the latest stable release of every
# unpinned plugin on WordPress.org, keys its install on those versions and
# passes them here, so a new release gets a fresh install, and a plugin
# already present at another version is reinstalled. --list prints the set
# the current environment installs, one "slug main-file version" line each
# ("latest" for an unpinned plugin), and changes nothing.
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

# Every download goes through the shared download cache when
# WPMCP_DOWNLOAD_CACHE names one (bin/test-local.sh sets it); see the file.
# shellcheck source=bin/download-cache.sh
. "$(dirname "$0")/download-cache.sh"
download() { dc_download "$@"; }

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}
WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
PLUGINS_DIR="$WP_CORE_DIR/wp-content/plugins"
list_only=false
if [ "${1:-}" = "--list" ]; then
	list_only=true
fi
ELEMENTOR_VERSION=${ELEMENTOR_VERSION:-4.3.4}
if [ "$ELEMENTOR_VERSION" != "latest" ] && ! [[ "$ELEMENTOR_VERSION" =~ ^[0-9]+(\.[0-9]+){1,3}(-[A-Za-z0-9.]+)?$ ]]; then
	echo "ELEMENTOR_VERSION must be 'latest' or a version such as 4.3.4 (got '$ELEMENTOR_VERSION')." >&2
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

# The live BuddyPress job (issue #363) runs the BuddyPress ops through the real
# BuddyPress, so its hooks fire. Exclusive too: the stub-backed BuddyPress tests
# fake its presence and build its tables from a fixture, and exercise the direct
# table path that only runs while BuddyPress's own functions are absent.
if [ "${WPMCP_LIVE_BUDDYPRESS:-}" = "1" ]; then
	PLUGINS=(
		"buddypress:bp-loader.php"
	)
fi

# The live LMS jobs (issue #394) run the Tutor LMS and LifterLMS ops through
# the real plugins. One plugin per install: both register a post type named
# "lesson", so loading them together would test neither the way a site runs.
# Exclusive too: the stub-backed LMS tests register the plugins' post types
# themselves.
if [ "${WPMCP_LIVE_LMS:-}" = "tutor" ]; then
	PLUGINS=(
		"tutor:tutor.php"
	)
elif [ "${WPMCP_LIVE_LMS:-}" = "lifterlms" ]; then
	PLUGINS=(
		"lifterlms:lifterlms.php"
	)
fi

# The live TablePress job (issue #481) runs the TablePress ops through the
# real TablePress. Exclusive too: the stub-backed TablePress tests define a
# stand-in TablePress class, which the real plugin would contradict.
if [ "${WPMCP_LIVE_TABLEPRESS:-}" = "1" ]; then
	PLUGINS=(
		"tablepress:tablepress.php"
	)
fi

# The live WP Mail SMTP job runs the WP Mail SMTP ops on the theme pair
# through the real plugin, its Options class, encryption and constants.
# Exclusive too: the stub-backed WP Mail SMTP tests define a stand-in for its
# Options class and its wp_mail_smtp() container.
if [ "${WPMCP_LIVE_WP_MAIL_SMTP:-}" = "1" ]; then
	PLUGINS=(
		"wp-mail-smtp:wp_mail_smtp.php"
	)
fi

# The live CRM job (issue #477) runs the FluentCRM and MailPoet ops through
# the real plugins, so their own APIs, hooks and opt-in rules run. Both on one
# install: they share no tables, post types or hooks. Exclusive too: the
# stub-backed CRM tests fake both plugins' presence over fixture tables.
if [ "${WPMCP_LIVE_CRM:-}" = "1" ]; then
	PLUGINS=(
		"fluent-crm:fluent-crm.php"
		"mailpoet:mailpoet.php"
	)
fi

# The live FunnelKit job (issue #500) runs the FunnelKit funnel writes through
# the free Funnel Builder's own REST controllers, with the WooCommerce its
# checkout steps need. Exclusive too: the stub-backed FunnelKit tests seed its
# tables from a fixture and stand in for its controllers.
if [ "${WPMCP_LIVE_FUNNELKIT:-}" = "1" ]; then
	PLUGINS=(
		"woocommerce:woocommerce.php"
		"funnel-builder:funnel-builder.php"
	)
fi

# The live Amelia job (issue #499) runs the Amelia ops through the real
# Amelia Lite, so its own command handlers, slot checks and notifications run.
# Only the free Lite edition is on wordpress.org; the paid editions are not
# tested. Exclusive too: the stub-backed Amelia tests build its tables from a
# fixture and fake its presence.
if [ "${WPMCP_LIVE_AMELIA:-}" = "1" ]; then
	PLUGINS=(
		"ameliabooking:ameliabooking.php"
	)
fi

# The live Breakdance job (issue #457) runs the Breakdance adapter against a
# real Breakdance 1.x. Breakdance is a commercial plugin and not on
# wordpress.org, so it is copied from a local directory holding it
# (WPMCP_BREAKDANCE_SRC) instead of downloaded. Exclusive too: the stub-backed
# Breakdance tests stand in for its cache generator and toggle its presence.
if [ "${WPMCP_LIVE_BREAKDANCE:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_BREAKDANCE:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_BREAKDANCE_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/plugin.php" ]; then
		echo "WPMCP_LIVE_BREAKDANCE=1 needs WPMCP_BREAKDANCE_SRC, a directory holding the Breakdance plugin (plugin.php)." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR"
	rm -rf "$PLUGINS_DIR/breakdance"
	cp -R "$src" "$PLUGINS_DIR/breakdance"
	echo "Copied Breakdance $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/plugin.php" | head -n 1) from $src."
fi

# The live Avada job (issue #637) runs the Avada adapter against the real
# Avada Builder (fusion-builder) and Avada Core (fusion-core) plugins and the
# Avada theme. All three are commercial and not on wordpress.org, so they are
# copied from one local directory holding the three unpacked folders
# (WPMCP_AVADA_SRC: Avada/, fusion-builder/, fusion-core/) instead of
# downloaded. Exclusive too: the stub-backed Avada tests stand in for its
# element map and toggle its presence.
if [ "${WPMCP_LIVE_AVADA:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_AVADA:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_AVADA_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/fusion-builder/fusion-builder.php" ] || [ ! -f "$src/fusion-core/fusion-core.php" ] || [ ! -f "$src/Avada/style.css" ]; then
		echo "WPMCP_LIVE_AVADA=1 needs WPMCP_AVADA_SRC, a directory holding the Avada/ theme and the fusion-builder/ and fusion-core/ plugins." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR" "$WP_CORE_DIR/wp-content/themes"
	for slug in fusion-builder fusion-core; do
		rm -rf "${PLUGINS_DIR:?}/$slug"
		cp -R "$src/$slug" "$PLUGINS_DIR/$slug"
	done
	rm -rf "$WP_CORE_DIR/wp-content/themes/Avada"
	cp -R "$src/Avada" "$WP_CORE_DIR/wp-content/themes/Avada"
	echo "Copied Avada Builder $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/fusion-builder/fusion-builder.php" | head -n 1), Avada Core $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/fusion-core/fusion-core.php" | head -n 1) and the Avada theme $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/Avada/style.css" | head -n 1) from $src."
fi

# The live Oxygen job (issue #635) runs the Oxygen 6 adapter against a real
# Oxygen 6. Oxygen is a commercial plugin and not on wordpress.org, so it is
# copied from a local directory holding it (WPMCP_OXYGEN_SRC) instead of
# downloaded. Exclusive too: the stub-backed Oxygen tests stand in for its
# cache generator and toggle its presence.
if [ "${WPMCP_LIVE_OXYGEN:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_OXYGEN:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_OXYGEN_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/plugin.php" ]; then
		echo "WPMCP_LIVE_OXYGEN=1 needs WPMCP_OXYGEN_SRC, a directory holding the Oxygen 6 plugin (plugin.php)." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR"
	rm -rf "$PLUGINS_DIR/oxygen"
	cp -R "$src" "$PLUGINS_DIR/oxygen"
	echo "Copied Oxygen $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/plugin.php" | head -n 1) from $src."
fi

# The live WPBakery job (issue #639) runs the WPBakery adapter against a real
# WPBakery Page Builder. It is a commercial plugin and not on wordpress.org,
# so it is copied from a local directory holding it (WPMCP_WPBAKERY_SRC)
# instead of downloaded. Exclusive too: the stub-backed WPBakery tests stand
# in for its element map and toggle its presence.
if [ "${WPMCP_LIVE_WPBAKERY:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_WPBAKERY:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_WPBAKERY_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/js_composer.php" ]; then
		echo "WPMCP_LIVE_WPBAKERY=1 needs WPMCP_WPBAKERY_SRC, a directory holding the WPBakery plugin (js_composer.php)." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR"
	rm -rf "$PLUGINS_DIR/js_composer"
	cp -R "$src" "$PLUGINS_DIR/js_composer"
	echo "Copied WPBakery $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/js_composer.php" | head -n 1) from $src."
fi

# The live Divi job (issue #636) runs the Divi 5 adapter against a real
# Divi 5. Divi is a commercial theme, not a plugin, and not on
# wordpress.org, so it is copied from a local directory holding it
# (WPMCP_DIVI_SRC) into the themes directory, and tests/bootstrap.php makes
# it the active theme. Exclusive too: the stub-backed Divi tests stand in for
# its version and module metadata.
if [ "${WPMCP_LIVE_DIVI:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_DIVI:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_DIVI_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/style.css" ] || [ ! -d "$src/includes/builder-5" ]; then
		echo "WPMCP_LIVE_DIVI=1 needs WPMCP_DIVI_SRC, a directory holding the Divi 5 theme (style.css and includes/builder-5)." >&2
		exit 1
	fi
	mkdir -p "$WP_CORE_DIR/wp-content/themes"
	rm -rf "$WP_CORE_DIR/wp-content/themes/Divi"
	cp -R "$src" "$WP_CORE_DIR/wp-content/themes/Divi"
	echo "Copied Divi $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/style.css" | head -n 1) from $src."
fi

# The live MemberPress job (issue #668) runs the MemberPress ops against a real
# MemberPress. MemberPress is a commercial plugin and not on wordpress.org, so
# it is copied from a local directory holding it (WPMCP_MEMBERPRESS_SRC)
# instead of downloaded. Exclusive too: the stub-backed MemberPress tests build
# its tables and post types from a fixture and fake its presence.
if [ "${WPMCP_LIVE_MEMBERPRESS:-}" = "1" ] && [ "$list_only" = true ]; then
	PLUGINS=()
elif [ "${WPMCP_LIVE_MEMBERPRESS:-}" = "1" ]; then
	PLUGINS=()
	src=${WPMCP_MEMBERPRESS_SRC:-}
	if [ -z "$src" ] || [ ! -f "$src/memberpress.php" ]; then
		echo "WPMCP_LIVE_MEMBERPRESS=1 needs WPMCP_MEMBERPRESS_SRC, a directory holding the MemberPress plugin (memberpress.php)." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR"
	rm -rf "$PLUGINS_DIR/memberpress"
	cp -R "$src" "$PLUGINS_DIR/memberpress"
	echo "Copied MemberPress $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/memberpress.php" | head -n 1) from $src."
fi

# The live WooCommerce Subscriptions job (issue #658) runs the subscriptions
# ops through the real plugin beside WooCommerce. Subscriptions is a
# commercial plugin and not on wordpress.org, so it is copied from a local
# directory holding it (WPMCP_WC_SUBSCRIPTIONS_SRC) instead of downloaded.
# Exclusive too: the stub-backed subscription tests stand in for its API.
if [ "${WPMCP_LIVE_WC_SUBSCRIPTIONS:-}" = "1" ]; then
	PLUGINS=(
		"woocommerce:woocommerce.php"
	)
	if [ "$list_only" = false ]; then
		src=${WPMCP_WC_SUBSCRIPTIONS_SRC:-}
		if [ -z "$src" ] || [ ! -f "$src/woocommerce-subscriptions.php" ]; then
			echo "WPMCP_LIVE_WC_SUBSCRIPTIONS=1 needs WPMCP_WC_SUBSCRIPTIONS_SRC, a directory holding the WooCommerce Subscriptions plugin (woocommerce-subscriptions.php)." >&2
			exit 1
		fi
		mkdir -p "$PLUGINS_DIR"
		rm -rf "$PLUGINS_DIR/woocommerce-subscriptions"
		cp -R "$src" "$PLUGINS_DIR/woocommerce-subscriptions"
		echo "Copied WooCommerce Subscriptions $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/woocommerce-subscriptions.php" | head -n 1) from $src."
	fi
fi

# The live WooCommerce Bookings job (issue #659) runs the bookings ops
# through the real plugin beside WooCommerce. Bookings is a commercial
# plugin and not on wordpress.org, so it is copied from a local directory
# holding it (WPMCP_WC_BOOKINGS_SRC) instead of downloaded. Exclusive too:
# the stub-backed booking tests stand in for its API.
if [ "${WPMCP_LIVE_WC_BOOKINGS:-}" = "1" ]; then
	PLUGINS=(
		"woocommerce:woocommerce.php"
	)
	if [ "$list_only" = false ]; then
		src=${WPMCP_WC_BOOKINGS_SRC:-}
		if [ -z "$src" ] || [ ! -f "$src/woocommerce-bookings.php" ]; then
			echo "WPMCP_LIVE_WC_BOOKINGS=1 needs WPMCP_WC_BOOKINGS_SRC, a directory holding the WooCommerce Bookings plugin (woocommerce-bookings.php)." >&2
			exit 1
		fi
		mkdir -p "$PLUGINS_DIR"
		rm -rf "$PLUGINS_DIR/woocommerce-bookings"
		cp -R "$src" "$PLUGINS_DIR/woocommerce-bookings"
		echo "Copied WooCommerce Bookings $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/woocommerce-bookings.php" | head -n 1) from $src."
	fi
fi

# The live Elementor Pro job (issue #654) runs the Elementor Pro submissions
# ops against a real Elementor Pro beside the pinned free Elementor it needs.
# Elementor Pro is a commercial plugin and not on wordpress.org, so it is
# copied from a local directory holding it (WPMCP_ELEMENTOR_PRO_SRC) instead
# of downloaded. Exclusive too: the stub-backed tests build its tables from a
# fixture and fake its presence.
if [ "${WPMCP_LIVE_ELEMENTOR_PRO:-}" = "1" ]; then
	PLUGINS=(
		"elementor:elementor.php"
	)
	if [ "$list_only" = false ]; then
		src=${WPMCP_ELEMENTOR_PRO_SRC:-}
		if [ -z "$src" ] || [ ! -f "$src/elementor-pro.php" ]; then
			echo "WPMCP_LIVE_ELEMENTOR_PRO=1 needs WPMCP_ELEMENTOR_PRO_SRC, a directory holding the Elementor Pro plugin (elementor-pro.php)." >&2
			exit 1
		fi
		mkdir -p "$PLUGINS_DIR"
		rm -rf "$PLUGINS_DIR/elementor-pro"
		cp -R "$src" "$PLUGINS_DIR/elementor-pro"
		echo "Copied Elementor Pro $(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$src/elementor-pro.php" | head -n 1) from $src."
	fi
fi

# The version a slug is pinned to here: "latest" means the latest-stable zip.
default_version() {
	case "$1" in
		elementor) echo "$ELEMENTOR_VERSION" ;;
		*) echo "latest" ;;
	esac
}

# Version to fetch for a slug: the one WPMCP_PLUGIN_VERSIONS asks for, else
# the default above.
plugin_version() {
	local pair
	for pair in ${WPMCP_PLUGIN_VERSIONS:-}; do
		if [ "${pair%%=*}" = "$1" ] && [ -n "${pair#*=}" ]; then
			echo "${pair#*=}"
			return 0
		fi
	done
	default_version "$1"
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
		# A plugin asked for at a version must be that version, or the pin
		# means nothing on a reused install; "latest" keeps whatever is
		# already there.
		if [ "$ref" = "latest-stable" ] || [ "$present" = "$version" ]; then
			echo "Plugin '$slug' ${present} already installed, skipping."
			return 0
		fi
		echo "Plugin '$slug' is ${present:-an unknown version}, wanted ${version}: reinstalling."
		rm -rf "$target"
	fi

	if [ -n "${WPMCP_DOWNLOAD_CACHE:-}" ] && [ "$ref" != "latest-stable" ]; then
		# From the download cache, fetched once per exact version.
		echo "Installing plugin '$slug' ($ref) from the download cache or wordpress.org..."
		local name="plugins/${slug}-${ref}.zip"
		dc_get "$name" zip "" fetch_plugin_zip "$slug" "$ref" || exit 1
		unzip -q -o "$DC_PATH" -d "$PLUGINS_DIR" || { dc_unlock "$name"; exit 1; }
		dc_unlock "$name"
		installed_check "$slug" "$main_file"
		return 0
	fi

	echo "Installing plugin '$slug' ($ref) from wordpress.org..."
	local zip="$TMPDIR/${slug}.${ref}.zip"
	if ! download "https://downloads.wordpress.org/plugin/${slug}.${ref}.zip" "$zip"; then
		# A resolved latest release that WordPress.org keeps no versioned zip
		# for (a plugin released from trunk) is the latest-stable zip.
		if [ "$ref" = "latest-stable" ] || [ "$(default_version "$slug")" != "latest" ]; then
			echo "Could not download '$slug' $ref from wordpress.org." >&2
			exit 1
		fi
		echo "No versioned zip for '$slug' $ref; installing latest-stable instead."
		zip="$TMPDIR/${slug}.latest-stable.zip"
		download "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "$zip"
	fi
	unzip -q -o "$zip" -d "$PLUGINS_DIR"
	rm -f "$zip"
	installed_check "$slug" "$main_file"
}

# Fails unless plugin <slug> is in place with <main file>, and says which
# version it is.
installed_check() {
	local target="$PLUGINS_DIR/$1"
	if [ ! -f "$target/$2" ]; then
		echo "Expected '$target/$2' after install but it is missing." >&2
		exit 1
	fi
	echo "Installed plugin '$1' $(header_version "$target/$2")."
}

# Fetches the zip of plugin <slug> at version <ref> to <file>: the versioned
# zip, or for a resolved latest release WordPress.org keeps no versioned zip
# for (a plugin released from trunk), the latest-stable one.
fetch_plugin_zip() {
	local slug=$1 ref=$2 file=$3
	dc_download "https://downloads.wordpress.org/plugin/${slug}.${ref}.zip" "$file" && return 0
	if [ "$(default_version "$slug")" != "latest" ]; then
		echo "Could not download '$slug' $ref from wordpress.org." >&2
		return 1
	fi
	echo "No versioned zip for '$slug' $ref; installing latest-stable instead." >&2
	dc_download "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "$file"
}

# The live theme add-on job (issue #656, WPMCP_LIVE_THEME_PRO=astra, kadence
# or generatepress) runs the theme layout ops against one real premium add-on
# beside its free theme: Astra Pro (WPMCP_ASTRA_PRO_SRC), Kadence Pro
# (WPMCP_KADENCE_PRO_SRC) or GP Premium (WPMCP_GP_PREMIUM_SRC). The add-ons
# are commercial, so each is copied from a local directory holding it; the
# free theme is downloaded from wordpress.org. Exclusive too: the stub-backed
# layout tests fake the add-on's presence and hook catalog.
if [ -n "${WPMCP_LIVE_THEME_PRO:-}" ]; then
	PLUGINS=()
fi
if [ -n "${WPMCP_LIVE_THEME_PRO:-}" ] && [ "$list_only" = false ]; then
	case "$WPMCP_LIVE_THEME_PRO" in
		astra) theme=astra; addon=astra-addon; src=${WPMCP_ASTRA_PRO_SRC:-}; var=WPMCP_ASTRA_PRO_SRC ;;
		kadence) theme=kadence; addon=kadence-pro; src=${WPMCP_KADENCE_PRO_SRC:-}; var=WPMCP_KADENCE_PRO_SRC ;;
		generatepress) theme=generatepress; addon=gp-premium; src=${WPMCP_GP_PREMIUM_SRC:-}; var=WPMCP_GP_PREMIUM_SRC ;;
		*) echo "WPMCP_LIVE_THEME_PRO must be astra, kadence or generatepress (got '$WPMCP_LIVE_THEME_PRO')." >&2; exit 1 ;;
	esac
	if [ -z "$src" ] || [ ! -f "$src/$addon.php" ]; then
		echo "WPMCP_LIVE_THEME_PRO=$WPMCP_LIVE_THEME_PRO needs $var, a directory holding the $addon plugin ($addon.php)." >&2
		exit 1
	fi
	mkdir -p "$PLUGINS_DIR" "$WP_CORE_DIR/wp-content/themes"
	rm -rf "${PLUGINS_DIR:?}/$addon"
	cp -R "$src" "$PLUGINS_DIR/$addon"
	echo "Copied $addon $(header_version "$src/$addon.php") from $src."
	if [ ! -f "$WP_CORE_DIR/wp-content/themes/$theme/style.css" ]; then
		echo "Installing theme '$theme' from wordpress.org..."
		# The theme zip has no version in its URL: cached by URL, fetched again
		# after WPMCP_TEST_LATEST_TTL_HOURS.
		dc_unzip "themes/$theme.zip" "https://downloads.wordpress.org/theme/$theme.zip" "$WP_CORE_DIR/wp-content/themes" "${WPMCP_TEST_LATEST_TTL_HOURS:-24}" || exit 1
	fi
	echo "Theme '$theme' $(header_version "$WP_CORE_DIR/wp-content/themes/$theme/style.css") ready."
fi

if [ "$list_only" = true ]; then
	for entry in "${PLUGINS[@]+"${PLUGINS[@]}"}"; do
		echo "${entry%%:*} ${entry##*:} $(default_version "${entry%%:*}")"
	done
	exit 0
fi

mkdir -p "$PLUGINS_DIR"

for entry in "${PLUGINS[@]+"${PLUGINS[@]}"}"; do
	install_plugin "${entry%%:*}" "${entry##*:}"
done

echo "Test plugins ready in $PLUGINS_DIR"
