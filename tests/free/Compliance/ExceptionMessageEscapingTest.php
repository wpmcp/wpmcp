<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Tools\Blocks\Block_Tree;
use WPMCP\Tools\Compose\Page_Spec;
use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Cron\Run_Event;
use WPMCP\Tools\Media\Svg_Sanitizer;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Create_Redirect;

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

    /**
     * Guard strings are built away from the throw (Page_Spec::reject(),
     * Content_Guard::check_meta()), so the escape has to happen where the
     * operand is interpolated: the throw itself must not re-escape the
     * plugin's own quotes.
     */
    public function test_page_spec_rejection_escapes_the_key_but_keeps_its_literal_quotes(): void
    {
        try {
            Page_Spec::validate(['title' => 'x', '<bad>' => 1]);
            $this->fail('Expected an unknown top-level key to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('spec: unknown key "&lt;bad&gt;"', $e->getMessage());
        }
    }

    public function test_page_spec_rejection_of_a_literal_message_stays_plain_text(): void
    {
        try {
            Page_Spec::validate(['title' => 'x', 'content' => [['type' => 'heading', 'settings' => 'nope']]]);
            $this->fail('Expected a non-object settings value to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('content[0]: "settings" must be an object', $e->getMessage());
        }
    }

    public function test_protected_meta_guard_escapes_the_key_but_keeps_its_literal_quotes(): void
    {
        $post_id = self::factory()->post->create();

        try {
            (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => '_secret<x>', 'value' => 'v']);
            $this->fail('Expected a protected meta key to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Refusing to write protected meta key "_secret&lt;x&gt;".', $e->getMessage());
        }
    }
}
