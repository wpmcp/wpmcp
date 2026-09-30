<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Governance\Governance;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;

/**
 * Elementor 4.3's editor signals, applied to wpmcp writes (issue #452).
 *
 * Elementor 4.3 keeps two per-document markers for tools that write while a
 * person has the document open (Elementor\Modules\Mcp\Utils\Editor_Sync_State):
 *
 * - the elementor/mcp/pre_execute_guard filter refuses a write while another
 *   user holds the document's lock with unsaved changes (the editor's
 *   heartbeat reports those through elementor/heartbeat/unsaved_signal);
 * - after a write, set_mcp_mutation() records a marker the open editor's
 *   heartbeat reads (elementor/heartbeat/mutation_marker), so it tells the
 *   person the document changed underneath them.
 *
 * wpmcp applies the guard to every write to an Elementor document and sets
 * the marker after each successful one. On an Elementor without the guard
 * hook, nothing changes.
 */
class ElementorEditLockTest extends \WP_UnitTestCase
{
    private const SYNC = '\\Elementor\\Modules\\Mcp\\Utils\\Editor_Sync_State';

    private int $agent;
    private int $editor;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_elementor_active() || ! class_exists(self::SYNC)) {
            $this->markTestSkipped('Elementor 4.3 or later is not active.');
        }
        Snapshot_Store::install();
        Governance::reset_for_tests();
        Rate_Limiter::set_clock_override(fn() => 1_790_004_523);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        // Elementor registers its guard when its MCP module loads; the test
        // install may not load that module, so register Elementor's own
        // callbacks the same way the module does.
        if (! has_filter('elementor/mcp/pre_execute_guard')) {
            $sync = self::SYNC;
            (new $sync())->register_hooks();
        }

        $this->agent  = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Agent Account']);
        $this->editor = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Bea Lockholder']);
        wp_set_current_user($this->agent);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_respect_edit_locks');
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function elementor_page(): int
    {
        $post = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'Original body']);
        update_post_meta($post, '_elementor_edit_mode', 'builder');
        update_post_meta($post, '_elementor_data', wp_slash(wp_json_encode([
            ['id' => 'sect001', 'elType' => 'section', 'settings' => [], 'elements' => []],
        ])));
        return $post;
    }

    /** $user has the document open in Elementor with unsaved changes. */
    private function open_with_unsaved_changes(int $post, int $user, int $age = 5): void
    {
        update_post_meta($post, '_edit_lock', (time() - $age) . ':' . $user);
        $previous = get_current_user_id();
        wp_set_current_user($user);
        $sync = self::SYNC;
        $sync::set_editor_unsaved($post);
        wp_set_current_user($previous);
    }

    private function mutation_time(int $post): int
    {
        $sync = self::SYNC;
        return (int) $sync::get_mcp_mutation_time($post);
    }

    /** @return mixed */
    private function update(int $post, string $content)
    {
        return wp_get_ability('wpmcp/update-post')->execute(['post_id' => $post, 'content' => $content]);
    }

    public function test_a_page_open_with_unsaved_changes_by_another_user_refuses_the_write(): void
    {
        $post = $this->elementor_page();
        $this->open_with_unsaved_changes($post, $this->editor);

        $result = $this->update($post, 'Agent body');

        $this->assertWPError($result);
        $this->assertSame('wpmcp_post_locked', $result->get_error_code());
        $this->assertStringContainsString('Bea Lockholder', $result->get_error_message());
        $this->assertStringContainsString('unsaved changes', $result->get_error_message(), "Elementor's own refusal is passed on.");
        $this->assertSame('elementor_editor_unsaved_changes', ((array) $result->get_error_data())['elementor'] ?? null);
        $this->assertSame('Original body', get_post_field('post_content', $post));
        $this->assertSame(0, $this->mutation_time($post));
    }

    public function test_the_elementor_guard_is_applied_to_elementor_documents(): void
    {
        $post = $this->elementor_page();
        add_filter('elementor/mcp/pre_execute_guard', static function ($error, $input) use ($post) {
            return (int) ($input['post_id'] ?? 0) === $post
                ? new \WP_Error('site_guard', 'This document is frozen by the site.')
                : $error;
        }, 20, 2);

        $result = $this->update($post, 'Agent body');

        $this->assertWPError($result);
        $this->assertSame('wpmcp_post_locked', $result->get_error_code());
        $this->assertStringContainsString('This document is frozen by the site.', $result->get_error_message());
        $this->assertSame('Original body', get_post_field('post_content', $post));
    }

    public function test_a_successful_write_sets_the_marker_the_open_editor_reads(): void
    {
        $post = $this->elementor_page();
        // The agent's own open editor with unsaved changes never blocks it.
        $this->open_with_unsaved_changes($post, $this->agent);

        $result = $this->update($post, 'Agent body');

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertGreaterThan(0, $this->mutation_time($post), 'The editor heartbeat must learn the document changed.');
    }

    public function test_a_write_to_a_page_not_built_with_elementor_sets_no_marker(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Original body']);

        $result = $this->update($post, 'Agent body');

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(0, $this->mutation_time($post));
    }

    public function test_without_the_guard_hook_behavior_is_unchanged(): void
    {
        remove_all_filters('elementor/mcp/pre_execute_guard');
        $post = $this->elementor_page();
        // Unsaved changes flagged, but no live lock: nothing to refuse.
        $this->open_with_unsaved_changes($post, $this->editor, 600);

        $result = $this->update($post, 'Agent body');

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('Agent body', get_post_field('post_content', $post));
    }

    public function test_the_filter_also_turns_off_the_elementor_guard(): void
    {
        add_filter('wpmcp_respect_edit_locks', '__return_false');
        $post = $this->elementor_page();
        $this->open_with_unsaved_changes($post, $this->editor);

        $result = $this->update($post, 'Agent body');

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('Agent body', get_post_field('post_content', $post));
    }
}
