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
require __DIR__ . '/support/abilities-init.php';
require __DIR__ . '/support/users.php';

// No test may reach the network (issue #323). See tests/support/network-guard.php.
\WPMCP\Tests\Support\Network_Guard::register();

tests_add_filter( 'muplugins_loaded', function () {
    // Run the suite against a canonical MCP Adapter plugin build instead of
    // the bundled library (issue #386), e.g. a trunk checkout after
    // `composer install --no-dev`:
    //   WPMCP_TEST_ADAPTER_PLUGIN=/path/to/mcp-adapter/mcp-adapter.php bin/test-local.sh
    // Loaded first, the way core loads 'mcp-adapter/' before 'wpmcp/', so
    // src/adapter-guard.php is exercised exactly as on a real site.
    // The suite registers vendor/autoload.php before WordPress loads, which
    // a real site never does; apply the guard now, as wpmcp.php would.
    require_once dirname( __DIR__ ) . '/src/adapter-guard.php';
    wpmcp_prefer_shared_mcp_adapter( dirname( __DIR__ ) . '/vendor' );

    $adapter_plugin = getenv( 'WPMCP_TEST_ADAPTER_PLUGIN' );
    if ( $adapter_plugin ) {
        // The adapter plugin reports a second copy with _doing_it_wrong on
        // init, long before any test case listens for it (issue #501).
        // Record every call made while WordPress boots with both copies;
        // AdapterPluginLiveTest asserts the list is empty.
        $GLOBALS['wpmcp_adapter_boot_doing_it_wrong'] = [];
        add_action( 'doing_it_wrong_run', 'wpmcp_record_adapter_boot_doing_it_wrong', 10, 3 );

        require $adapter_plugin;

        // Other plugins in the harness bundle their own adapter copies
        // behind autoloaders that sit ahead of everyone else's (Elementor
        // ships 0.6.x, WooCommerce 0.3.x), and each serves any adapter class
        // not yet loaded when it is asked. Load the whole build now, from
        // its own class map, so every WP\MCP and WP\McpSchema class in the
        // run comes from the build under test and never from a mix.
        $classmap = dirname( $adapter_plugin ) . '/vendor/composer/jetpack_autoload_classmap.php';
        if ( is_readable( $classmap ) ) {
            foreach ( array_keys( (array) require $classmap ) as $class ) {
                // Not the WP-CLI commands (their parent class only exists
                // under WP-CLI) and not the build's own test classes.
                if (
                    wpmcp_is_shared_adapter_class( $class )
                    && 0 !== strpos( $class, 'WP\\MCP\\Cli\\' )
                    && 0 !== strpos( $class, 'WP\\MCP\\Tests\\' )
                ) {
                    class_exists( $class ) || interface_exists( $class ) || trait_exists( $class );
                }
            }
        }
    }

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

    // The live BuddyPress job (issue #363, WPMCP_LIVE_BUDDYPRESS=1) runs the
    // BuddyPress ops through the real BuddyPress, so its hooks fire. Loaded
    // only on request: the stub-backed BuddyPress tests exercise the direct
    // table path, which only runs while BuddyPress's functions are absent.
    // Groups is off in a new install, so the components a site would switch
    // on are named here, before BuddyPress reads them.
    if ( getenv( 'WPMCP_LIVE_BUDDYPRESS' ) ) {
        add_filter( 'pre_option_bp-active-components', 'wpmcp_test_buddypress_components' );
        wpmcp_maybe_require_plugin( 'buddypress/bp-loader.php' );
    }

    // The live bbPress job (WPMCP_LIVE_BBPRESS=1) runs the bbPress ops
    // through the real bbPress. Loaded only on request: the stub-backed
    // bbPress tests register its post types themselves.
    if ( getenv( 'WPMCP_LIVE_BBPRESS' ) ) {
        wpmcp_maybe_require_plugin( 'bbpress/bbpress.php' );
    }

    // The live Breakdance job (issue #457, WPMCP_LIVE_BREAKDANCE=1) runs the
    // Breakdance adapter against a real Breakdance 1.x, copied in from
    // WPMCP_BREAKDANCE_SRC. Loaded only on request: the stub-backed
    // Breakdance tests stand in for its cache generator.
    if ( getenv( 'WPMCP_LIVE_BREAKDANCE' ) ) {
        wpmcp_maybe_require_plugin( 'breakdance/plugin.php' );
    }

    // The live Oxygen job (issue #635, WPMCP_LIVE_OXYGEN=1) runs the Oxygen 6
    // adapter against a real Oxygen 6, copied in from WPMCP_OXYGEN_SRC.
    // Loaded only on request: the stub-backed Oxygen tests stand in for its
    // cache generator and toggle its presence.
    if ( getenv( 'WPMCP_LIVE_OXYGEN' ) ) {
        wpmcp_maybe_require_plugin( 'oxygen/plugin.php' );
    }

    // The live Avada job (issue #637, WPMCP_LIVE_AVADA=1) runs the Avada
    // adapter against the real Avada Core and Avada Builder plugins and the
    // Avada theme, copied in from WPMCP_AVADA_SRC. Loaded only on request:
    // the stub-backed Avada tests stand in for its element map and toggle
    // its presence. The theme is made the active one the way a switched
    // site has it, so WordPress loads its functions.php after the plugins.
    if ( getenv( 'WPMCP_LIVE_AVADA' ) ) {
        add_filter( 'pre_option_template', static fn () => 'Avada' );
        add_filter( 'pre_option_stylesheet', static fn () => 'Avada' );
        wpmcp_maybe_require_plugin( 'fusion-core/fusion-core.php' );
        wpmcp_maybe_require_plugin( 'fusion-builder/fusion-builder.php' );
    }

    // The live WPBakery job (issue #639, WPMCP_LIVE_WPBAKERY=1) runs the
    // WPBakery adapter against a real WPBakery Page Builder, copied in from
    // WPMCP_WPBAKERY_SRC. Loaded only on request: the stub-backed WPBakery
    // tests stand in for its element map and toggle its presence.
    if ( getenv( 'WPMCP_LIVE_WPBAKERY' ) ) {
        wpmcp_maybe_require_plugin( 'js_composer/js_composer.php' );
    }

    // The live Thrive Architect job (issue #640, WPMCP_LIVE_THRIVE=1) runs
    // the Thrive adapter against a real Thrive Architect, copied in from
    // WPMCP_THRIVE_ARCHITECT_SRC. Loaded only on request: the other Thrive
    // tests stand in for its rows and toggle its presence.
    if ( getenv( 'WPMCP_LIVE_THRIVE' ) ) {
        wpmcp_maybe_require_plugin( 'thrive-visual-editor/thrive-visual-editor.php' );
    }

    // The live Divi job (issue #636, WPMCP_LIVE_DIVI=1) runs the Divi 5
    // adapter against the real Divi 5 theme, copied into the themes
    // directory from WPMCP_DIVI_SRC. Divi is a theme, so it is made the
    // active one here, before WordPress loads the theme. Only on request:
    // the stub-backed Divi tests stand in for its version and metadata.
    if ( getenv( 'WPMCP_LIVE_DIVI' ) && is_dir( WP_CONTENT_DIR . '/themes/Divi' ) ) {
        add_filter( 'pre_option_template', static fn () => 'Divi' );
        add_filter( 'pre_option_stylesheet', static fn () => 'Divi' );
    }

    // The live BeTheme job (issue #643, WPMCP_LIVE_BETHEME=1) runs the
    // BeBuilder adapter against the real BeTheme, copied into the themes
    // directory from WPMCP_BETHEME_SRC, made the active theme here. Only on
    // request: the other BeTheme tests stand in for its rows.
    if ( getenv( 'WPMCP_LIVE_BETHEME' ) && is_dir( WP_CONTENT_DIR . '/themes/betheme' ) ) {
        add_filter( 'pre_option_template', static fn () => 'betheme' );
        add_filter( 'pre_option_stylesheet', static fn () => 'betheme' );
    }

    // The live theme add-on job (issue #656, WPMCP_LIVE_THEME_PRO=astra,
    // kadence or generatepress) runs the theme layout ops against one real
    // premium add-on, copied in from WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC
    // or WPMCP_GP_PREMIUM_SRC, beside its free theme made the active one.
    // Each add-on's layouts module is switched on the way its own settings
    // screen stores it, before the add-on reads it.
    $wpmcp_theme_pro = (string) getenv( 'WPMCP_LIVE_THEME_PRO' );
    if ( in_array( $wpmcp_theme_pro, [ 'astra', 'kadence', 'generatepress' ], true ) && is_dir( WP_CONTENT_DIR . '/themes/' . $wpmcp_theme_pro ) ) {
        add_filter( 'pre_option_template', static fn () => $wpmcp_theme_pro );
        add_filter( 'pre_option_stylesheet', static fn () => $wpmcp_theme_pro );
        if ( 'astra' === $wpmcp_theme_pro ) {
            add_filter( 'pre_option__astra_ext_enabled_extensions', static fn () => [ 'advanced-hooks' => 'advanced-hooks' ] );
            wpmcp_maybe_require_plugin( 'astra-addon/astra-addon.php' );
        } elseif ( 'kadence' === $wpmcp_theme_pro ) {
            add_filter( 'pre_option_kadence_pro_theme_config', static fn () => wp_json_encode( [ 'elements' => true ] ) );
            wpmcp_maybe_require_plugin( 'kadence-pro/kadence-pro.php' );
        } else {
            add_filter( 'pre_option_generate_package_elements', static fn () => 'activated' );
            wpmcp_maybe_require_plugin( 'gp-premium/gp-premium.php' );
        }
    }

    // The live MemberPress job (issue #668, WPMCP_LIVE_MEMBERPRESS=1) runs
    // the MemberPress ops against a real MemberPress, copied in from
    // WPMCP_MEMBERPRESS_SRC. Loaded only on request: the stub-backed
    // MemberPress tests build its tables and post types from a fixture.
    if ( getenv( 'WPMCP_LIVE_MEMBERPRESS' ) ) {
        wpmcp_maybe_require_plugin( 'memberpress/memberpress.php' );
    }

    // The live Elementor Pro job (issue #654, WPMCP_LIVE_ELEMENTOR_PRO=1)
    // runs the submissions ops against a real Elementor Pro, copied in from
    // WPMCP_ELEMENTOR_PRO_SRC, beside the Elementor loaded above. Loaded
    // only on request: the stub-backed tests build its tables from a fixture.
    if ( getenv( 'WPMCP_LIVE_ELEMENTOR_PRO' ) ) {
        wpmcp_maybe_require_plugin( 'elementor-pro/elementor-pro.php' );
    }

    // The live JetElements job (issue #653, WPMCP_LIVE_JET_ELEMENTS=1) runs
    // the JetElements addon pack ops against a real JetElements, copied in
    // from WPMCP_JET_ELEMENTS_SRC, beside the Elementor loaded above. Loaded
    // only on request: the stub-backed tests fake its presence.
    if ( getenv( 'WPMCP_LIVE_JET_ELEMENTS' ) ) {
        wpmcp_maybe_require_plugin( 'jet-elements/jet-elements.php' );
    }

    // The live WooCommerce Subscriptions job (issue #658,
    // WPMCP_LIVE_WC_SUBSCRIPTIONS=1) runs the subscriptions ops through the
    // real plugin, copied in from WPMCP_WC_SUBSCRIPTIONS_SRC, beside the
    // WooCommerce loaded above. Loaded only on request: the stub-backed
    // subscription tests stand in for its API.
    if ( getenv( 'WPMCP_LIVE_WC_SUBSCRIPTIONS' ) ) {
        // Subscriptions decides whether WooCommerce is there from the
        // active_plugins option, as on a site, and does not load otherwise;
        // the harness activates nothing.
        add_filter( 'option_active_plugins', 'wpmcp_test_wc_subscriptions_active_plugins' );
        add_filter( 'default_option_active_plugins', 'wpmcp_test_wc_subscriptions_active_plugins' );
        wpmcp_maybe_require_plugin( 'woocommerce-subscriptions/woocommerce-subscriptions.php' );
    }

    // The live WooCommerce Bookings job (issue #659, WPMCP_LIVE_WC_BOOKINGS=1)
    // runs the bookings ops through the real plugin, copied in from
    // WPMCP_WC_BOOKINGS_SRC, beside the WooCommerce loaded above. Loaded
    // only on request: the stub-backed booking tests stand in for its API.
    if ( getenv( 'WPMCP_LIVE_WC_BOOKINGS' ) ) {
        add_filter( 'option_active_plugins', 'wpmcp_test_wc_bookings_active_plugins' );
        add_filter( 'default_option_active_plugins', 'wpmcp_test_wc_bookings_active_plugins' );
        wpmcp_maybe_require_plugin( 'woocommerce-bookings/woocommerce-bookings.php' );
    }

    // The live cache settings job (issue #692, WPMCP_LIVE_CACHE_SETTINGS=1)
    // runs the WP Rocket, Perfmatters and FlyingPress settings ops through
    // the real plugins, each copied in from its WPMCP_*_SRC when set. Loaded
    // only on request: the stub-backed tests seed each option and fake
    // presence. Requests leaving the machine get an empty answer, so the
    // run stays offline (license checks, SaaS jobs, preload crawls).
    if ( getenv( 'WPMCP_LIVE_CACHE_SETTINGS' ) ) {
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
            if ( false !== $pre ) {
                return $pre;
            }
            return [ 'headers' => [], 'body' => '{}', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
        }, 10, 3 );
        wpmcp_maybe_require_plugin( 'wp-rocket/wp-rocket.php' );
        wpmcp_maybe_require_plugin( 'perfmatters/perfmatters.php' );
        wpmcp_maybe_require_plugin( 'flying-press/flying-press.php' );
    }

    // The live CRM job (issue #477, WPMCP_LIVE_CRM=1) runs the FluentCRM
    // and MailPoet ops through the real plugins. Loaded only on request: the
    // stub-backed CRM tests build both plugins' tables from a fixture and
    // fake their presence.
    if ( getenv( 'WPMCP_LIVE_CRM' ) ) {
        // MailPoet asks its own service which sender domains are verified
        // when it mails a confirmation, and wordpress.com for translations;
        // empty answers keep the run offline.
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
            if ( false !== $pre || ( 0 !== strpos( (string) $url, 'https://bridge.mailpoet.com/' ) && 0 !== strpos( (string) $url, 'https://translate.wordpress.com/' ) ) ) {
                return $pre;
            }
            return [ 'headers' => [], 'body' => '{}', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
        }, 10, 3 );
        wpmcp_maybe_require_plugin( 'fluent-crm/fluent-crm.php' );
        wpmcp_maybe_require_plugin( 'mailpoet/mailpoet.php' );
    }

    // The live FunnelKit job (issue #500, WPMCP_LIVE_FUNNELKIT=1) runs the
    // FunnelKit funnel writes through the free Funnel Builder's own REST
    // controllers, beside the WooCommerce loaded above. Loaded only on
    // request: the stub-backed FunnelKit tests seed its tables from a
    // fixture and stand in for its controllers.
    if ( getenv( 'WPMCP_LIVE_FUNNELKIT' ) ) {
        // Funnel Builder decides whether WooCommerce is there from the
        // active_plugins option, as on a site, and leaves out its checkout
        // and thank you steps otherwise; the harness activates nothing.
        add_filter( 'option_active_plugins', 'wpmcp_test_funnelkit_active_plugins' );
        add_filter( 'default_option_active_plugins', 'wpmcp_test_funnelkit_active_plugins' );
        wpmcp_maybe_require_plugin( 'funnel-builder/funnel-builder.php' );
    }

    // The live Amelia job (issue #499, WPMCP_LIVE_AMELIA=1) runs the Amelia
    // ops through the real Amelia Lite. Loaded only on request: the
    // stub-backed Amelia tests build its tables from a fixture and fake its
    // presence.
    if ( getenv( 'WPMCP_LIVE_AMELIA' ) ) {
        wpmcp_maybe_require_plugin( 'ameliabooking/ameliabooking.php' );
    }

    // The live LMS jobs (issue #394, WPMCP_LIVE_LMS=tutor or lifterlms) run
    // the LMS ops through the real Tutor LMS or LifterLMS, one per install.
    // Loaded only on request: the stub-backed LMS tests register the plugins'
    // post types themselves, and the two plugins share the "lesson" type.
    if ( 'tutor' === getenv( 'WPMCP_LIVE_LMS' ) ) {
        wpmcp_maybe_require_plugin( 'tutor/tutor.php' );
    }

    // The live TablePress job (issue #481, WPMCP_LIVE_TABLEPRESS=1) runs the
    // TablePress ops through the real TablePress. Loaded only on request: the
    // stub-backed TablePress tests define a stand-in TablePress class.
    if ( getenv( 'WPMCP_LIVE_TABLEPRESS' ) ) {
        wpmcp_maybe_require_plugin( 'tablepress/tablepress.php' );
    }

    // The live WP Mail SMTP job (WPMCP_LIVE_WP_MAIL_SMTP=1) runs the WP Mail
    // SMTP ops through the real plugin. Loaded only on request: the
    // stub-backed WP Mail SMTP tests define a stand-in for its Options class.
    if ( getenv( 'WPMCP_LIVE_WP_MAIL_SMTP' ) ) {
        wpmcp_maybe_require_plugin( 'wp-mail-smtp/wp_mail_smtp.php' );
    }

    // The live Code Snippets job (issue #576, WPMCP_LIVE_CODE_SNIPPETS=1) runs
    // the Code Snippets ops through the real Code Snippets. Loaded only on
    // request: the stub-backed tests define a stand-in for its API.
    if ( getenv( 'WPMCP_LIVE_CODE_SNIPPETS' ) ) {
        wpmcp_maybe_require_plugin( 'code-snippets/code-snippets.php' );
    }

    // The live Polylang job (issue #498, WPMCP_LIVE_POLYLANG=1) runs the
    // page translation ops through the real Polylang, which the shared suite
    // loads but never boots: with no language defined and no wp-admin
    // request, Polylang picks no context and never loads its API. The job
    // boots it the way wp-admin does, so its API, its translation groups and
    // its sync between translations are all live; the test adds languages.
    if ( getenv( 'WPMCP_LIVE_POLYLANG' ) ) {
        add_filter( 'pll_model', static fn () => 'PLL_Admin_Model' );
        add_filter( 'pll_context', static fn () => 'PLL_Admin' );
    }
    if ( 'lifterlms' === getenv( 'WPMCP_LIVE_LMS' ) ) {
        // LifterLMS reads its add-on catalog from lifterlms.com while it
        // boots and saves courses; an empty catalog keeps the run offline.
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
            if ( false !== $pre || 0 !== strpos( (string) $url, 'https://lifterlms.com/' ) ) {
                return $pre;
            }
            return [ 'headers' => [], 'body' => '{"items":[]}', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
        }, 10, 3 );
        wpmcp_maybe_require_plugin( 'lifterlms/lifterlms.php' );
    }
} );

if ( ! function_exists( 'wpmcp_test_funnelkit_active_plugins' ) ) {
    /** The active_plugins option as the live FunnelKit job's site has it. */
    function wpmcp_test_funnelkit_active_plugins( $plugins ): array {
        return array_values( array_unique( array_merge( (array) $plugins, [ 'woocommerce/woocommerce.php', 'funnel-builder/funnel-builder.php' ] ) ) );
    }
}

if ( ! function_exists( 'wpmcp_test_wc_subscriptions_active_plugins' ) ) {
    /** The active_plugins option as the live WooCommerce Subscriptions job's site has it. */
    function wpmcp_test_wc_subscriptions_active_plugins( $plugins ): array {
        return array_values( array_unique( array_merge( (array) $plugins, [ 'woocommerce/woocommerce.php', 'woocommerce-subscriptions/woocommerce-subscriptions.php' ] ) ) );
    }
}

if ( ! function_exists( 'wpmcp_test_wc_bookings_active_plugins' ) ) {
    /** The active_plugins option as the live WooCommerce Bookings job's site has it. */
    function wpmcp_test_wc_bookings_active_plugins( $plugins ): array {
        return array_values( array_unique( array_merge( (array) $plugins, [ 'woocommerce/woocommerce.php', 'woocommerce-bookings/woocommerce-bookings.php' ] ) ) );
    }
}

if ( ! function_exists( 'wpmcp_test_buddypress_components' ) ) {
    /** The BuddyPress components the live BuddyPress job runs with. */
    function wpmcp_test_buddypress_components(): array {
        return array_fill_keys( [ 'members', 'settings', 'xprofile', 'activity', 'notifications', 'groups' ], '1' );
    }
}

// The live BuddyPress job's tables, built from BuddyPress's own schema once
// per run, before any test transaction starts (DDL commits implicitly). A
// no-op everywhere else.
tests_add_filter( 'setup_theme', function () {
    if ( ! getenv( 'WPMCP_LIVE_BUDDYPRESS' ) || ! function_exists( 'buddypress' ) ) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
    bp_core_install( wpmcp_test_buddypress_components() );
} );

// The live LMS jobs' tables and roles, built by each plugin's own installer
// once per run, before any test transaction starts. A no-op everywhere else.
tests_add_filter( 'init', function () {
    if ( 'tutor' === getenv( 'WPMCP_LIVE_LMS' ) && class_exists( '\\TUTOR\\Tutor' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \TUTOR\Tutor::create_database();
        \TUTOR\Tutor::manage_tutor_roles_and_permissions();
    }
    if ( 'lifterlms' === getenv( 'WPMCP_LIVE_LMS' ) && class_exists( 'LLMS_Install' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        LLMS_Install::create_tables();
        LLMS_Roles::install();
    }
    // TablePress gives administrators its capabilities when it first runs in
    // wp-admin; the live TablePress job grants them the same way.
    if ( getenv( 'WPMCP_LIVE_TABLEPRESS' ) && isset( \TablePress::$model_options ) ) {
        \TablePress::$model_options->add_access_capabilities();
    }
}, 20 );

// The live FunnelKit job's funnel tables, built by Funnel Builder's own
// routine once per run, before any test transaction starts. A no-op
// everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_FUNNELKIT' ) && defined( 'WFFN_PLUGIN_FILE' ) ) {
        require_once plugin_dir_path( WFFN_PLUGIN_FILE ) . 'admin/db/class-wffn-db-tables.php';
        \WFFN_DB_Tables::get_instance()->add_if_needed();
    }
}, 20 );

// The live CRM job's tables, built by FluentCRM's and MailPoet's own
// activation routines once per run, before any test transaction starts.
// FluentCRM reads its options table from init priority 5, so its tables are
// built ahead of that. A no-op everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_CRM' ) && class_exists( '\\FluentCrm\\App\\Hooks\\Handlers\\ActivationHandler' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        ( new \FluentCrm\App\Hooks\Handlers\ActivationHandler() )->handle( false );
    }
}, 1 );
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_CRM' ) && class_exists( '\\MailPoet\\DI\\ContainerWrapper' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Config\Activator::class )->activate();
    }
}, 20 );

// The live Amelia job's tables, roles and settings, built by Amelia's own
// activation routines once per run, before any test transaction starts. A
// no-op everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_AMELIA' ) && class_exists( '\\AmeliaBooking\\Plugin' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \AmeliaBooking\Plugin::activation( false );
        \AmeliaBooking\Infrastructure\WP\InstallActions\ActivationRolesHook::init();
        \AmeliaBooking\Infrastructure\WP\InstallActions\ActivationSettingsHook::init();
    }
}, 1 );

// The live Code Snippets job's table, built by Code Snippets' own DB class
// once per run, before any test transaction starts. A no-op everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_CODE_SNIPPETS' ) && function_exists( 'Code_Snippets\\code_snippets' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \Code_Snippets\code_snippets()->db->create_or_upgrade_tables();
    }
}, 1 );

// The live MemberPress job's tables, built by MemberPress's own MeprDb
// upgrade once per run, before any test transaction starts. A no-op
// everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_MEMBERPRESS' ) && class_exists( 'MeprDb' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \MeprDb::fetch()->upgrade();
    }
}, 1 );

// The live Elementor Pro job's submissions tables, built by Elementor Pro's
// own migrations (it runs them on admin_init) once per run, before any test
// transaction starts. A no-op everywhere else.
tests_add_filter( 'init', function () {
    if ( getenv( 'WPMCP_LIVE_ELEMENTOR_PRO' ) && class_exists( '\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Migration' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \ElementorPro\Modules\Forms\Submissions\Database\Migration::install();
    }
}, 1 );

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

    // Record the set as current (issue #527), so Site_Tables::maybe_install()
    // on admin_init and rest_api_init stays a no-op inside tests.
    update_option( \WPMCP\Site_Tables::VERSION_OPTION, \WPMCP\Site_Tables::VERSION, true );
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

if ( ! function_exists( 'wpmcp_record_adapter_boot_doing_it_wrong' ) ) {
    /** Records one boot-time _doing_it_wrong call for the adapter leg (issue #501). */
    function wpmcp_record_adapter_boot_doing_it_wrong( $function_name, $message, $version ): void {
        $GLOBALS['wpmcp_adapter_boot_doing_it_wrong'][] = sprintf( '%s: %s (%s)', $function_name, wp_strip_all_tags( (string) $message ), $version );
    }
}

require $_tests_dir . '/includes/bootstrap.php';

// WordPress has booted; from here on the test cases watch _doing_it_wrong
// themselves, and expected calls must not land in the boot record.
remove_action( 'doing_it_wrong_run', 'wpmcp_record_adapter_boot_doing_it_wrong', 10 );
