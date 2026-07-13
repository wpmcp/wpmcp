<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Analysis\A11y_Analyzer;

class A11yAnalyzerTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function clean_extract(): array
    {
        return [
            'headings'    => [
                ['level' => 1, 'text' => 'Welcome'],
                ['level' => 2, 'text' => 'About'],
            ],
            'images'      => [
                ['src' => 'x.jpg', 'alt' => 'Described', 'location' => 'img[1]'],
            ],
            'links'       => [
                ['url' => '/about', 'text' => 'About our practice', 'internal' => true],
            ],
            'form_fields' => [
                ['label' => 'Email', 'type' => 'email', 'location' => 'input[1]'],
            ],
        ];
    }

    private function status_map(array $report): array
    {
        return array_column($report['checks'], 'status', 'id');
    }

    public function test_clean_page_passes_everything(): void
    {
        $report = A11y_Analyzer::analyze($this->clean_extract());
        $status = $this->status_map($report);

        $this->assertSame('pass', $status['image_alts']);
        $this->assertSame('pass', $status['heading_hierarchy']);
        $this->assertSame('pass', $status['link_text_quality']);
        $this->assertSame('pass', $status['form_label_coverage']);
        $this->assertSame(100, $report['score']);
        $this->assertSame(0, $report['summary']['failures']);
    }

    public function test_missing_alt_fails_with_location(): void
    {
        $ex             = $this->clean_extract();
        $ex['images'][] = ['src' => 'y.jpg', 'alt' => '', 'location' => 'img[2]'];

        $report = A11y_Analyzer::analyze($ex);
        $status = $this->status_map($report);

        $this->assertSame('fail', $status['image_alts']);

        $alt_check = null;
        foreach ($report['checks'] as $c) {
            if ('image_alts' === $c['id']) {
                $alt_check = $c;
            }
        }
        $this->assertNotNull($alt_check);
        $this->assertContains('img[2]', $alt_check['locations']);
    }

    public function test_heading_order_jump_warns(): void
    {
        $ex             = $this->clean_extract();
        $ex['headings'] = [
            ['level' => 1, 'text' => 'Title'],
            ['level' => 4, 'text' => 'Jumped'],
        ];

        $report = A11y_Analyzer::analyze($ex);
        $status = $this->status_map($report);

        $this->assertSame('warn', $status['heading_hierarchy']);
    }

    public function test_generic_and_empty_link_text_warns(): void
    {
        $ex          = $this->clean_extract();
        $ex['links'] = [
            ['url' => '/a', 'text' => 'click here', 'internal' => true],
            ['url' => '/b', 'text' => '', 'internal' => true],
        ];

        $report = A11y_Analyzer::analyze($ex);
        $status = $this->status_map($report);

        $this->assertSame('warn', $status['link_text_quality']);
    }

    public function test_unlabeled_form_field_fails(): void
    {
        $ex                = $this->clean_extract();
        $ex['form_fields'] = [
            ['label' => 'Email', 'type' => 'email', 'location' => 'input[1]'],
            ['label' => '', 'type' => 'text', 'location' => 'input[2]'],
        ];

        $report = A11y_Analyzer::analyze($ex);
        $status = $this->status_map($report);

        $this->assertSame('fail', $status['form_label_coverage']);
    }

    public function test_form_check_absent_when_no_form(): void
    {
        $ex                = $this->clean_extract();
        $ex['form_fields'] = [];
        $report            = A11y_Analyzer::analyze($ex);
        $ids               = array_column($report['checks'], 'id');

        $this->assertNotContains('form_label_coverage', $ids);
    }
}
