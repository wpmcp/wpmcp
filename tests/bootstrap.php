<?php

if ( ! defined( 'WPMCP_TESTING' ) ) {
    define( 'WPMCP_TESTING', true );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: rtrim( sys_get_temp_dir(), '/' ) . '/wordpress-tests-lib';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require $_tests_dir . '/includes/functions.php';

require __DIR__ . '/support/plugins.php';

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/wpmcp.php';

    // Activate optional third-party plugins when present. Each is guarded so a
    // missing plugin never fatals the suite; plugin-specific tests skip instead.
    wpmcp_maybe_require_plugin( 'elementor/elementor.php' );
    wpmcp_maybe_require_plugin( 'woocommerce/woocommerce.php' );
    wpmcp_maybe_require_plugin( 'advanced-custom-fields/acf.php' );
    wpmcp_maybe_require_plugin( 'wordpress-seo/wp-seo.php' );
} );

// WooCommerce needs its install routine to run against the test DB so its custom
// tables exist and WC() is usable. Run it once WooCommerce has loaded and WP is
// initialized, guarded so it is a no-op when WooCommerce is absent.
tests_add_filter( 'setup_theme', function () {
    if ( class_exists( 'WC_Install' ) ) {
        WC_Install::install();
    }
} );

// Elementor's kit manager syncs a few WordPress options (blogname,
// blogdescription) into its active "kit" post whenever they change. The test
// framework deletes all posts before every test, so that kit post is gone by the
// time an unrelated test updates one of those options, and Elementor throws
// "Invalid post". These listeners are irrelevant to parity testing, so neutralize
// them in the harness. Runs after Elementor has initialized on `init` (priority
// 0); guarded so it is a no-op when Elementor is absent.
tests_add_filter( 'init', function () {
    if ( ! wpmcp_elementor_active() ) {
        return;
    }

    remove_all_actions( 'update_option_blogname' );
    remove_all_actions( 'update_option_blogdescription' );
}, 999 );

require $_tests_dir . '/includes/bootstrap.php';
