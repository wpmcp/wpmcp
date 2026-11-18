<?php
/**
 * Faithful test doubles for the catalog APIs of the three addon suites the
 * second #653 slice adds, each read from the free wordpress.org build:
 *
 *  - Happy Addons 3.52 (happy-elementor-addons):
 *    Happy_Addons\Elementor\Classes\Widgets_Manager::get_local_widgets_map()
 *    is widget key => ['cat', 'is_active', 'title', ...] and its register()
 *    loop registers every key not in happyaddons_inactive_widgets.
 *    Extensions_Manager::get_local_features_map() and
 *    get_local_extensions_map() work the same way against
 *    happyaddons_inactive_features and happyaddons_inactive_extensions;
 *    get_always_on_features() lists the features that cannot be switched off.
 *  - Royal Elementor Addons 1.7 (royal-elementor-addons):
 *    WprAddons\Classes\Utilities::get_registered_modules(),
 *    get_theme_builder_modules() and get_woocommerce_builder_modules() are
 *    title => [slug, demo url, filter, badge].
 *  - PowerPack Lite for Elementor 3.2 (powerpack-lite-for-elementor):
 *    powerpack_elements_lite_get_modules() is widget name => title,
 *    powerpack_elements_lite_get_default_off_modules() the names that ship
 *    switched off and powerpack_elements_lite_get_legacy_module_names()
 *    legacy name => current name. The doubles read
 *    $GLOBALS['wpmcp_test_powerpack'].
 *
 * Presence is not faked here: the tests turn each pack on through its
 * wpmcp_{suite}_active filter. Loaded only by the tests that need it; a real
 * class or function always wins.
 */

namespace Happy_Addons\Elementor\Classes {

    if (! class_exists(__NAMESPACE__ . '\\Widgets_Manager', false)) {
        class Widgets_Manager
        {
            /** @var array<string,array<string,mixed>> */
            public static array $map = [];

            public static function get_local_widgets_map()
            {
                return self::$map;
            }
        }
    }

    if (! class_exists(__NAMESPACE__ . '\\Extensions_Manager', false)) {
        class Extensions_Manager
        {
            /** @var array<string,array<string,mixed>> */
            public static array $features = [];

            /** @var array<string,array<string,mixed>> */
            public static array $extensions = [];

            public static function get_local_features_map()
            {
                return self::$features;
            }

            public static function get_local_extensions_map()
            {
                return self::$extensions;
            }

            public static function get_always_on_features()
            {
                $out = [];
                foreach (self::$features as $key => $data) {
                    if (! empty($data['always_on'])) {
                        $out[] = $key;
                    }
                }
                return $out;
            }
        }
    }
}

namespace WprAddons\Classes {

    if (! class_exists(__NAMESPACE__ . '\\Utilities', false)) {
        class Utilities
        {
            /** @var array<string,array<int,string>> */
            public static array $modules = [];

            /** @var array<string,array<int,string>> */
            public static array $theme = [];

            /** @var array<string,array<int,string>> */
            public static array $woo = [];

            public static function get_registered_modules()
            {
                return self::$modules;
            }

            public static function get_theme_builder_modules()
            {
                return self::$theme;
            }

            public static function get_woocommerce_builder_modules()
            {
                return self::$woo;
            }
        }
    }
}

namespace {

    if (! function_exists('powerpack_elements_lite_get_modules')) {
        function powerpack_elements_lite_get_modules()
        {
            return (array) ($GLOBALS['wpmcp_test_powerpack']['modules'] ?? []);
        }
    }

    if (! function_exists('powerpack_elements_lite_get_default_off_modules')) {
        function powerpack_elements_lite_get_default_off_modules()
        {
            return (array) ($GLOBALS['wpmcp_test_powerpack']['default_off'] ?? []);
        }
    }

    if (! function_exists('powerpack_elements_lite_get_legacy_module_names')) {
        function powerpack_elements_lite_get_legacy_module_names()
        {
            return [
                'pp-hotspots'     => 'pp-image-hotspots',
                'pp-link-effects' => 'pa-link-effects',
            ];
        }
    }
}
