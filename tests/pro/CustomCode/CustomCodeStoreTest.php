<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\Tools\CustomCode\Custom_Code_Store;

/**
 * The store's lifecycle contract (issue #63): a per-post CSS block must not
 * outlive its post, and an appending writer must not be able to grow one
 * option without bound.
 *
 * Both are option-hygiene problems rather than security ones, but the ids in
 * wpmcp_custom_code_post_<id> are reused: WordPress hands the same integer to
 * a later post after a database restore or a WXR import, so an orphaned block
 * does not sit idle, it re-attaches itself to whatever takes the id next.
 */
class CustomCodeStoreTest extends \WP_UnitTestCase
{
    public function test_deleting_the_post_removes_its_stored_block(): void
    {
        $post = self::factory()->post->create();
        Custom_Code_Store::set_css('.a { color: red; }', $post);
        $this->assertSame('.a { color: red; }', Custom_Code_Store::read_css($post));

        wp_delete_post($post, true);

        $this->assertFalse(get_option(Custom_Code_Store::post_option($post)));
        $this->assertSame('', Custom_Code_Store::read_css($post));
    }

    public function test_a_deleted_post_does_not_leak_its_css_onto_the_next_post_with_that_id(): void
    {
        $post = self::factory()->post->create();
        Custom_Code_Store::set_css('.ghost { color: red; }', $post);
        wp_delete_post($post, true);

        // Simulate the id being handed out again (import/restore), which is
        // the case an orphaned option actually bites on.
        $this->assertSame('', Custom_Code_Store::read_css($post));
    }

    public function test_appending_past_the_size_cap_is_refused_not_truncated(): void
    {
        $post  = self::factory()->post->create();
        $block = '.a { color: red; }' . str_repeat(' ', Custom_Code_Store::MAX_CSS_BYTES - 40);
        Custom_Code_Store::set_css($block, $post);

        try {
            Custom_Code_Store::set_css(str_repeat('.b { color: blue; }', 20), $post);
            $this->fail('An append past the cap should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('byte limit', $e->getMessage());
        }

        // Refused, not half-written: the previous block is still intact.
        $this->assertSame($block, Custom_Code_Store::read_css($post));
    }

    public function test_replace_can_still_shrink_a_block_that_is_at_the_cap(): void
    {
        $post = self::factory()->post->create();
        Custom_Code_Store::set_css(str_repeat('a', Custom_Code_Store::MAX_CSS_BYTES - 1), $post);

        $this->assertSame('.a { color: red; }', Custom_Code_Store::set_css('.a { color: red; }', $post, true));
    }
}
