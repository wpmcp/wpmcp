<?php
/**
 * Test support helpers for optional third-party plugins.
 *
 * The integration suite can run with Elementor and/or WooCommerce activated so
 * their parity areas can be exercised. Both plugins are optional: the suite must
 * still run when they are absent. These helpers let plugin-specific tests mark
 * themselves skipped (not failed) when the plugin is not present.
 */

if ( ! function_exists( 'wpmcp_test_plugins_dir' ) ) {
	/**
	 * Resolve the plugins directory of the WordPress test install.
	 *
	 * Runs during muplugins_loaded, before WordPress defines WP_PLUGIN_DIR, so
	 * the path is derived from the same core dir the install scripts use.
	 */
	function wpmcp_test_plugins_dir(): string {
		$tmp = getenv( 'TMPDIR' ) ?: sys_get_temp_dir();
		$core = getenv( 'WP_CORE_DIR' ) ?: rtrim( $tmp, '/' ) . '/wordpress/';

		return rtrim( $core, '/' ) . '/wp-content/plugins';
	}
}

if ( ! function_exists( 'wpmcp_maybe_require_plugin' ) ) {
	/**
	 * Require a plugin's main file when it is present.
	 *
	 * Guarded so a missing plugin never fatals the bootstrap.
	 */
	function wpmcp_maybe_require_plugin( string $relative_main_file ): bool {
		$path = wpmcp_test_plugins_dir() . '/' . ltrim( $relative_main_file, '/' );

		if ( ! is_readable( $path ) ) {
			return false;
		}

		require_once $path;

		return true;
	}
}

if ( ! function_exists( 'wpmcp_elementor_active' ) ) {
	/**
	 * Whether Elementor is loaded in the current test run.
	 */
	function wpmcp_elementor_active(): bool {
		return class_exists( '\\Elementor\\Plugin' );
	}
}

if ( ! function_exists( 'wpmcp_woocommerce_active' ) ) {
	/**
	 * Whether WooCommerce is loaded in the current test run.
	 */
	function wpmcp_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}
}

if ( ! function_exists( 'wpmcp_acf_active' ) ) {
	/**
	 * Whether Advanced Custom Fields is loaded in the current test run.
	 */
	function wpmcp_acf_active(): bool {
		return function_exists( 'get_field' ) || class_exists( 'ACF' );
	}
}

if ( ! function_exists( 'wpmcp_i18n_plugin' ) ) {
	/**
	 * Which multilingual plugin (if any) is active in the current test run.
	 *
	 * Returns 'polylang', 'wpml', or '' (none), mirroring the detection the
	 * i18n adapter itself uses so tests can gate on the same signal the tool
	 * code gates on. Polylang is checked first: on a site running both
	 * plugins (not expected in the test harness, but possible), Polylang wins.
	 */
	function wpmcp_i18n_plugin(): string {
		if ( function_exists( 'pll_the_languages' ) || defined( 'POLYLANG_VERSION' ) ) {
			return 'polylang';
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}

		return '';
	}
}

if ( ! function_exists( 'wpmcp_seo_plugin' ) ) {
	/**
	 * Which SEO plugin (if any) is active in the current test run.
	 *
	 * Returns 'yoast', 'rankmath', or '' (none), mirroring the detection the
	 * SEO adapter itself uses so tests can gate on the same signal the tool
	 * code gates on. Yoast is checked first: on a site running both plugins
	 * (not expected in the test harness, but possible), Yoast wins.
	 */
	function wpmcp_seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}

		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		return '';
	}
}

if ( ! function_exists( 'wpmcp_ensure_elementor_kit' ) ) {
	/**
	 * Ensure Elementor has an active kit post in the current test.
	 *
	 * The WP test framework deletes all posts before each test, taking the
	 * default kit with it; several Elementor code paths (get_controls() on
	 * nested widgets, Document::save()) dereference the active kit and fatal
	 * without one. No-op when Elementor is absent.
	 */
	function wpmcp_ensure_elementor_kit(): void {
		if ( ! wpmcp_elementor_active() ) {
			return;
		}

		$kits = \Elementor\Plugin::instance()->kits_manager;
		if ( ! $kits->get_active_id() || ! get_post( (int) $kits->get_active_id() ) ) {
			update_option( 'elementor_active_kit', \Elementor\Core\Kits\Manager::create_default_kit() );
		}
	}
}
