<?php

if ( ! defined( 'WPMCP_TESTING' ) ) {
    define( 'WPMCP_TESTING', true );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: rtrim( sys_get_temp_dir(), '/' ) . '/wordpress-tests-lib';

// bin/test-local.sh gives each checkout its own database by pointing the
// WordPress harness at a generated config instead of the one in the tests lib.
$_tests_config = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
if ( $_tests_config && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
    define( 'WP_TESTS_CONFIG_FILE_PATH', $_tests_config );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require $_tests_dir . '/includes/functions.php';

require __DIR__ . '/support/plugins.php';
require __DIR__ . '/support/network-guard.php';

// No test may reach the network (issue #323). See tests/support/network-guard.php.
\WPMCP\Tests\Support\Network_Guard::register();

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/wpmcp.php';

    // Activate optional third-party plugins when present. Each is guarded so a
    // missing plugin never fatals the suite; plugin-specific tests skip instead.
    wpmcp_maybe_require_plugin( 'elementor/elementor.php' );
    wpmcp_maybe_require_plugin( 'woocommerce/woocommerce.php' );
    wpmcp_maybe_require_plugin( 'advanced-custom-fields/acf.php' );
    wpmcp_maybe_require_plugin( 'wordpress-seo/wp-seo.php' );
    wpmcp_maybe_require_plugin( 'polylang/polylang.php' );

    // The live forms job (issue #66, WPMCP_LIVE_FORMS=1) runs the Contact
    // Form 7 adapter against the real plugin and its Flamingo store. Loaded
    // only on request: with the real classes present the harness doubles
    // stand down, which the stub-backed suite is not written for.
    if ( getenv( 'WPMCP_LIVE_FORMS' ) ) {
        wpmcp_maybe_require_plugin( 'contact-form-7/wp-contact-form-7.php' );
        wpmcp_maybe_require_plugin( 'flamingo/flamingo.php' );
    }

    // The live blocks job (issue #287, WPMCP_LIVE_BLOCKS=1) renders blocks
    // inserted through the block-suites tools with the real block suites.
    // Loaded only on request: the stub-backed block suite tests register
    // stand-in block types under the suites' own names.
    if ( getenv( 'WPMCP_LIVE_BLOCKS' ) ) {
        wpmcp_maybe_require_plugin( 'kadence-blocks/kadence-blocks.php' );
        wpmcp_maybe_require_plugin( 'generateblocks/plugin.php' );
        wpmcp_maybe_require_plugin( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' );
        wpmcp_maybe_require_plugin( 'otter-blocks/otter-blocks.php' );
    }
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

        // Also create the HPOS order tables (issue #292), here for the same
        // DDL-commits-the-transaction reason as the snapshots table above.
        // The authoritative order store stays whatever the install chose;
        // the tables only let a test switch stores for its own duration.
        $synchronizer = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
        if ( function_exists( 'wc_get_container' ) && class_exists( $synchronizer ) ) {
            $sync = wc_get_container()->get( $synchronizer );
            if ( ! $sync->check_orders_table_exists() ) {
                $sync->create_database_tables();
            }
        }
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

// Elementor fetches its remote info feed (https://my.elementor.com/api/v2/info/)
// the first time widgets register in a process, so whichever Elementor test
// happens to run first would reach the network (issue #323). Mock it for the
// whole run as the feed being unreachable, which Elementor already handles by
// caching the failure and carrying on. No test depends on the feed's content.
tests_add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false === $pre && 0 === strpos( (string) $url, 'https://my.elementor.com/api/' ) ) {
        return new WP_Error( 'http_request_failed', 'Elementor remote API is mocked as unreachable in the test suite.' );
    }
    return $pre;
}, 10, 3 );

require $_tests_dir . '/includes/bootstrap.php';
