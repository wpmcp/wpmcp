<?php
/**
 * Widget doubles for the Happy Addons, Royal Elementor Addons and PowerPack
 * Lite packs (issue #653), registered into Elementor's real widgets manager.
 * Each sits in its suite's own class namespace, which is how the pack
 * recognizes it (the free builds: Happy_Addons\Elementor\Widget\InfoBox,
 * WprAddons\Modules\PromoBox\Widgets\Wpr_Promo_Box,
 * PowerpackElementsLite\Modules\InfoBox\Widgets\Info_Box), and the category
 * is Elementor's own so the namespace alone decides. Requires Elementor and
 * tests/support/elementor-addon-widgets.php to be loaded.
 */

namespace Happy_Addons\Elementor\Widget {

    class Fixture_Infobox extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'ha-fixture-infobox';
        }

        public function get_categories()
        {
            return [ 'general' ];
        }
    }
}

namespace WprAddons\Modules\FixturePromo\Widgets {

    class Wpr_Fixture_Promo extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'wpr-fixture-promo';
        }

        public function get_categories()
        {
            return [ 'general' ];
        }
    }
}

namespace PowerpackElementsLite\Modules\FixtureBox\Widgets {

    class Fixture_Box extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'pp-fixture-box';
        }

        public function get_categories()
        {
            return [ 'general' ];
        }
    }
}
