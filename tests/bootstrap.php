<?php

if ( ! defined( 'WPMCP_TESTING' ) ) {
    define( 'WPMCP_TESTING', true );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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
    wpmcp_maybe_require_plugin( 'polylang/polylang.php' );
} );

// Recreate the wpmcp snapshots table once per run, BEFORE any test
// transaction starts. The table is otherwise created lazily by
// Snapshot_Store::install() from individual tests' setUp(); when the FIRST
// install of a run happens inside a test, MySQL's implicit DDL commit
// silently ends that test's isolation transaction, and every row the test
// writes afterwards is committed to the shared test database. The WP test
// installer only resets core tables, so those leaked snapshot rows survive
// across runs and poison count-based assertions (List_Operations,
// Snapshot_Store CRUD) nondeterministically. With the table guaranteed to
// exist here, every per-test install() call is a pure no-op and per-test
// transactions stay intact.
tests_add_filter( 'muplugins_loaded', function () {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmcp_snapshots" );
    \WPMCP\Safety\Snapshot_Store::install();

    // Same DDL-commits-the-transaction reasoning for the managed redirects
    // table (issue #128): create it once here so no test's first write is
    // also the first CREATE TABLE of the run.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmcp_redirects" );
    \WPMCP\Tools\Redirects\Redirect_Store::install();

    // Same reasoning for the content search index table (issue #83): the
    // incremental indexer runs on save_post for the whole suite, so its table
    // must exist before any test transaction starts. Creating it lazily inside
    // a test would implicitly commit that test's transaction and leak rows.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmcp_search_index" );
    \WPMCP\Tools\Search\Search_Index_Store::install();
}, 20 );

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
