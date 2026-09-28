<?php
/**
 * Faithful test doubles for the two Elementor addon suite APIs the addon packs
 * (issue #286) read their module list from. Both call shapes were verified
 * against the free wordpress.org builds:
 *
 *  - Premium Addons for Elementor 4.11:
 *    PremiumAddons\Admin\Includes\Admin_Helper::get_elements_keys() returns a
 *    list of ['key' => ..., 'draw_svg' => bool?] rows (admin/includes/keys.php).
 *  - Ultimate Addons for Elementor 2.9 (header-footer-elementor):
 *    HFE\WidgetsManager\Base\HFE_Helper::get_widget_list() returns
 *    WidgetKey => ['slug' => ..., 'default' => bool, ...].
 *
 * Essential Addons needs no class: it publishes its module config in
 * $GLOBALS['eael_config']. Loaded only by the tests that need it; a real
 * class always wins.
 */

namespace PremiumAddons\Admin\Includes {

    if (! class_exists(__NAMESPACE__ . '\\Admin_Helper', false)) {
        class Admin_Helper
        {
            /** @var array<int,array<string,mixed>> */
            public static array $keys = [];

            public static function get_elements_keys()
            {
                return self::$keys;
            }
        }
    }
}

namespace HFE\WidgetsManager\Base {

    if (! class_exists(__NAMESPACE__ . '\\HFE_Helper', false)) {
        class HFE_Helper
        {
            /** @var array<string,array<string,mixed>> */
            public static array $list = [];

            public static function get_widget_list()
            {
                return self::$list;
            }
        }
    }
}
