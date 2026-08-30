<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Tools\Blocks\Block_Tree;
use WPMCP\Tools\Cron\Run_Event;
use WPMCP\Tools\Media\Svg_Sanitizer;

/**
 * Issue #173: every thrown message escapes its dynamic operands, and only
 * those. The messages travel a plain-text JSON-RPC channel to the agent, so
 * the static half must survive verbatim: escaping the whole message would
 * turn the plugin's own quotes, angle brackets and arrows into entities the
 * operator then has to decode by eye.
 */
class ExceptionMessageEscapingTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_run_cron_event');
        parent::tearDown();
    }

    private function one_block(): array
    {
        return [
            [
                'blockName'    => 'core/paragraph',
                'attrs'        => [],
                'innerBlocks'  => [],
                'innerContent' => ['<p>hi</p>'],
                'innerHTML'    => '<p>hi</p>',
            ],
        ];
    }

    public function test_block_path_error_keeps_its_literal_punctuation(): void
    {
        try {
            Block_Tree::get($this->one_block(), [3]);
            $this->fail('Expected an out-of-range path to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Invalid block path [3]: no block at segment 0 (parent has 1 position(s)).',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('&#', $e->getMessage());
        }
    }

    public function test_block_path_error_reports_every_segment_of_a_nested_path(): void
    {
        try {
            Block_Tree::get($this->one_block(), [0, 2]);
            $this->fail('Expected an out-of-range nested path to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid block path [0,2]:', $e->getMessage());
            $this->assertStringContainsString('no block at segment 1', $e->getMessage());
        }
    }

    public function test_cron_run_event_escapes_the_hook_but_not_the_quotes_around_it(): void
    {
        add_filter('wpmcp_enable_run_cron_event', '__return_true');

        try {
            (new Run_Event())->handle(['hook' => '<img src=x onerror=alert(1)>']);
            $this->fail('Expected an unscheduled hook to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Hook "&lt;img src=x onerror=alert(1)&gt;" is not scheduled; refusing to run it.',
                $e->getMessage()
            );
        }
    }

    public function test_svg_rejection_names_the_element_in_plain_text(): void
    {
        try {
            Svg_Sanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
            $this->fail('Expected a script element to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('SVG element <script> is not allowed.', $e->getMessage());
        }
    }
}
