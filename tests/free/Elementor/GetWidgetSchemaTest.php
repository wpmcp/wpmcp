<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\Get_Widget_Schema;

class GetWidgetSchemaTest extends \WP_UnitTestCase
{
    public function test_returns_control_schema_for_known_widget(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'heading', 'full' => true]);

        $this->assertArrayHasKey('widget_name', $out);
        $this->assertSame('heading', $out['widget_name']);
        $this->assertArrayHasKey('controls', $out);
        $this->assertArrayHasKey('title', $out['controls']);

        $title_control = $out['controls']['title'];
        $this->assertArrayHasKey('type', $title_control);
        $this->assertArrayHasKey('label', $title_control);
        $this->assertArrayHasKey('default', $title_control);
        $this->assertArrayHasKey('section', $title_control);
    }

    public function test_unknown_widget_returns_error(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'totally-fake-widget']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_widget', $out->get_error_code());
    }

    public function test_missing_widget_name_returns_error(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Get_Widget_Schema())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_widget_name', $out->get_error_code());
    }
}
