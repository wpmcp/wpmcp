<?php
/**
 * Widget doubles registered into Elementor's real widgets manager by the
 * addon pack tests (issue #286), shaped like each suite's own widgets: the
 * suite's category (Essential Addons 'essential-addons-elementor', Premium
 * Addons 'premium-elements', Ultimate Addons 'hfe-widgets') or the suite's
 * class namespace. JetElements (issue #653) is recognized by class name
 * only, Elementor\Jet_Elements_*, because its 'cherry' category is shared
 * with the other Crocoblock plugins. Requires Elementor to be loaded.
 */

namespace WPMCP\Tests\Support\ElementorAddons {

    abstract class Fixture_Widget extends \Elementor\Widget_Base
    {
        public function get_title()
        {
            return 'Fixture ' . $this->get_name();
        }

        public function get_icon()
        {
            return 'eicon-code';
        }

        protected function register_controls()
        {
            $this->start_controls_section('content_section', [ 'label' => 'Content' ]);
            $this->add_control('fixture_title', [
                'label'   => 'Title',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => 'Hello',
            ]);
            $this->end_controls_section();
        }
    }

    class Essential_Card extends Fixture_Widget
    {
        public function get_name()
        {
            return 'eael-fixture-card';
        }

        public function get_categories()
        {
            return [ 'essential-addons-elementor' ];
        }
    }

    class Premium_Banner extends Fixture_Widget
    {
        public function get_name()
        {
            return 'premium-addon-fixture-banner';
        }

        public function get_categories()
        {
            return [ 'premium-elements' ];
        }
    }

    class Ultimate_Retina extends Fixture_Widget
    {
        public function get_name()
        {
            return 'fixture-retina';
        }

        public function get_categories()
        {
            return [ 'hfe-widgets' ];
        }
    }
}

namespace Essential_Addons_Elementor\Elements {

    /** Recognized by its namespace alone: its category is Elementor's own. */
    class Fixture_Namespaced extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'eael-fixture-namespaced';
        }

        public function get_categories()
        {
            return [ 'general' ];
        }
    }
}

namespace Elementor {

    /** Shaped like a JetElements widget: Elementor\Jet_Elements_*, category 'cherry'. */
    class Jet_Elements_Fixture_Banner extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'jet-fixture-banner';
        }

        public function get_categories()
        {
            return [ 'cherry' ];
        }
    }

    /** Another Crocoblock plugin's widget in the shared 'cherry' category: not JetElements. */
    class Jet_Fixture_Unfold extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'jet-fixture-unfold';
        }

        public function get_categories()
        {
            return [ 'cherry' ];
        }
    }
}
