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

        /**
         * The control stack as Elementor holds it on a REST request, where it
         * keeps the editor keys (label, options, selectors) that it strips on
         * a front-end request such as a PHPUnit run. Shaped after the
         * JetElements Banner widget's stack read in that mode: a layout
         * section and heading, a text control, a select, a responsive slider
         * with its tablet copy, a repeater and an empty default.
         *
         * @param string|null $control_id
         * @return mixed
         */
        public function get_controls($control_id = null)
        {
            $stack = [
                'content_section'     => [ 'name' => 'content_section', 'type' => 'section', 'tab' => 'content', 'label' => 'Content' ],
                'fixture_heading'     => [ 'name' => 'fixture_heading', 'type' => 'heading', 'tab' => 'content', 'section' => 'content_section', 'label' => 'Text' ],
                'fixture_title'       => [ 'name' => 'fixture_title', 'type' => 'text', 'tab' => 'content', 'section' => 'content_section', 'label' => 'Title', 'default' => 'Hello', 'dynamic' => [ 'active' => true ] ],
                'fixture_link'        => [ 'name' => 'fixture_link', 'type' => 'text', 'tab' => 'content', 'section' => 'content_section', 'label' => 'Link', 'default' => '' ],
                'fixture_tag'         => [
                    'name'      => 'fixture_tag',
                    'type'      => 'select',
                    'tab'       => 'content',
                    'section'   => 'content_section',
                    'label'     => 'Title HTML Tag',
                    'default'   => 'h5',
                    'options'   => [ 'h1' => 'H1', 'h2' => 'H2', 'h5' => 'H5', 'div' => 'div' ],
                    'condition' => [ 'fixture_title!' => '' ],
                ],
                'fixture_height'      => [
                    'name'       => 'fixture_height',
                    'type'       => 'slider',
                    'tab'        => 'style',
                    'section'    => 'content_section',
                    'label'      => 'Height',
                    'default'    => [ 'unit' => 'px', 'size' => 300 ],
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 1000 ] ],
                    'selectors'  => [ '{{WRAPPER}} .fixture-banner' => 'height: {{SIZE}}{{UNIT}};' ],
                    'responsive' => [ 'max' => 'desktop' ],
                ],
                'fixture_height_tablet' => [
                    'name'       => 'fixture_height_tablet',
                    'type'       => 'slider',
                    'tab'        => 'style',
                    'section'    => 'content_section',
                    'label'      => 'Height',
                    'parent'     => 'fixture_height',
                    'selectors'  => [ '{{WRAPPER}} .fixture-banner' => 'height: {{SIZE}}{{UNIT}};' ],
                    'responsive' => [ 'max' => 'tablet' ],
                ],
                'fixture_items'       => [
                    'name'        => 'fixture_items',
                    'type'        => 'repeater',
                    'tab'         => 'content',
                    'section'     => 'content_section',
                    'label'       => 'Items',
                    'fields'      => [ 'item_text' => [ 'name' => 'item_text', 'type' => 'text', 'label' => 'Text' ] ],
                    'title_field' => '{{{ item_text }}}',
                    'default'     => [],
                ],
            ];
            if (null === $control_id) {
                return $stack;
            }
            return $stack[ $control_id ] ?? null;
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
