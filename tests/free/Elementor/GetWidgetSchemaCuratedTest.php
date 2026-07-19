<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\Get_Widget_Schema;

/**
 * get-widget-schema serves the curated catalog subset by default and the
 * full introspected control stack behind the `full` flag (issue #59).
 */
class GetWidgetSchemaCuratedTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        wpmcp_ensure_elementor_kit();
    }

    public function test_cataloged_widget_returns_curated_schema_by_default(): void
    {
        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'heading']);

        $this->assertSame('heading', $out['widget_name']);
        $this->assertTrue($out['curated']);
        $this->assertTrue($out['available']);
        $this->assertSame('elementor', $out['requires']);
        $this->assertNotSame('', trim($out['purpose']));

        $this->assertArrayHasKey('params', $out);
        $this->assertArrayNotHasKey('controls', $out);

        $this->assertSame('string', $out['params']['title']['type']);
        $this->assertTrue($out['params']['title']['required']);
        $this->assertSame('h2', $out['params']['header_size']['default']);
    }

    public function test_full_flag_returns_introspected_control_stack(): void
    {
        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'heading', 'full' => true]);

        $this->assertSame('heading', $out['widget_name']);
        $this->assertFalse($out['curated']);
        $this->assertArrayHasKey('controls', $out);
        $this->assertArrayNotHasKey('params', $out);
        $this->assertArrayHasKey('title', $out['controls']);
    }

    public function test_non_cataloged_widget_falls_back_to_introspection(): void
    {
        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'wp-widget-search']);

        $this->assertSame('wp-widget-search', $out['widget_name']);
        $this->assertFalse($out['curated']);
        $this->assertArrayHasKey('controls', $out);
    }

    public function test_pro_only_entry_reports_unavailable_without_elementor_pro(): void
    {
        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types('form');
        if ($widget && 0 === strpos(get_class($widget), 'ElementorPro\\')) {
            $this->markTestSkipped('Elementor Pro is installed; the degrade path cannot be exercised.');
        }

        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'form']);

        $this->assertTrue($out['curated']);
        $this->assertSame('elementor-pro', $out['requires']);
        $this->assertFalse($out['available']);
        $this->assertArrayHasKey('params', $out);
    }

    public function test_unknown_widget_still_errors(): void
    {
        $out = (new Get_Widget_Schema())->handle(['widget_name' => 'totally-fake-widget']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_widget', $out->get_error_code());
    }
}
