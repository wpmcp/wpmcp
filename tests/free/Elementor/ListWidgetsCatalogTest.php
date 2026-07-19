<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\List_Widgets;

/**
 * list-widgets is catalog-aware (issue #59): cataloged widgets carry the
 * curated purpose line, searches also match catalog keywords, and Elementor
 * Pro promotion placeholders are reported as unavailable pro widgets rather
 * than free ones.
 */
class ListWidgetsCatalogTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    private function rows(array $args = []): array
    {
        $out = (new List_Widgets())->handle($args);
        $this->assertArrayHasKey('widgets', $out);

        $rows = [];
        foreach ($out['widgets'] as $row) {
            $rows[ $row['name'] ] = $row;
        }
        return $rows;
    }

    public function test_cataloged_widgets_carry_purpose_and_flag(): void
    {
        $rows = $this->rows();

        $this->assertTrue($rows['heading']['cataloged']);
        $this->assertNotSame('', trim((string) $rows['heading']['purpose']));
        $this->assertTrue($rows['heading']['available']);
    }

    public function test_non_cataloged_widgets_are_flagged_without_purpose(): void
    {
        $rows = $this->rows();

        $this->assertArrayHasKey('wp-widget-search', $rows);
        $this->assertFalse($rows['wp-widget-search']['cataloged']);
        $this->assertNull($rows['wp-widget-search']['purpose']);
    }

    public function test_search_matches_catalog_keywords(): void
    {
        // 'title' is a curated keyword of the heading widget but appears in
        // neither its name nor its 'Heading' title.
        $rows = $this->rows(['search' => 'title']);

        $this->assertArrayHasKey('heading', $rows);
    }

    public function test_pro_promotion_placeholders_report_pro_tier_and_unavailable(): void
    {
        $rows = $this->rows();

        if (! isset($rows['form'])) {
            $this->markTestSkipped('Environment does not register the form promotion placeholder.');
        }

        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types('form');
        if (0 === strpos(get_class($widget), 'ElementorPro\\')) {
            $this->markTestSkipped('Elementor Pro is installed; the placeholder path cannot be exercised.');
        }

        $this->assertSame('pro', $rows['form']['tier']);
        $this->assertFalse($rows['form']['available']);
        $this->assertTrue($rows['heading']['available']);
    }

    public function test_tier_filter_excludes_promotion_placeholders_from_free(): void
    {
        $rows = $this->rows(['tier' => 'free']);

        $this->assertArrayHasKey('heading', $rows);
        $this->assertArrayNotHasKey('form', $rows);
    }
}
