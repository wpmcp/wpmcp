<?php
/**
 * A widget double for the conditional-registration tests (issue #392): some
 * plugins register a widget type only on the requests that need it (for
 * example a checkout widget registered only on funnel pages). The test
 * registers it, seeds a page that uses it, then unregisters it so the edit
 * runs in a request where Elementor does not know the type. Requires
 * Elementor to be loaded.
 */

namespace WPMCP\Tests\Support\ElementorConditional;

class Conditional_Widget extends \Elementor\Widget_Base
{
    public const NAME = 'wpmcp-conditional-probe';

    public function get_name()
    {
        return self::NAME;
    }

    public function get_title()
    {
        return 'Conditional probe';
    }

    public function get_icon()
    {
        return 'eicon-code';
    }

    public function get_categories()
    {
        return [ 'general' ];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content_section', [ 'label' => 'Content' ]);
        $this->add_control('probe_label', [
            'label'   => 'Label',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Probe',
        ]);
        $this->end_controls_section();
    }
}
