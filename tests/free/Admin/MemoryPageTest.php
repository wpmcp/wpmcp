<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Memory_Page;
use WPMCP\Memory\Memory_Store;

/**
 * The admin side of agent memory (issue #131): the pending-proposal badge and
 * the classification metabox.
 *
 * There is deliberately no bespoke approval screen, so the tests here cover
 * only what WordPress's own pending -> publish flow does not give for free.
 * The metabox saves through Memory_Store, which means an administrator's edit
 * is validated by exactly the same rules as an agent proposal: no malformed
 * target, and no untargeted block rule, from either direction.
 */
class MemoryPageTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Memory_Store::flush_rules_cache();
        unset($_POST[ Memory_Page::NONCE ], $_POST['wpmcp_memory_kind'], $_POST['wpmcp_memory_severity'], $_POST['wpmcp_memory_targets']);
        parent::tearDown();
    }

    public function test_a_quiet_site_gets_no_badge_decoration(): void
    {
        $this->assertSame('wpmcp', Memory_Page::badged('wpmcp', 0));
        $this->assertSame('wpmcp', Memory_Page::badged('wpmcp'));
    }

    public function test_pending_proposals_render_the_wordpress_count_bubble(): void
    {
        Memory_Store::propose(['text' => 'One.']);
        Memory_Store::propose(['text' => 'Two.']);

        $label = Memory_Page::badged('Memory');

        $this->assertStringContainsString('awaiting-mod', $label);
        $this->assertStringContainsString('count-2', $label);
        $this->assertStringStartsWith('Memory ', $label);
    }

    /**
     * The badge is a menu title, and _wp_menu_output() echoes menu titles raw
     * on every wp-admin screen. Since issue #183 the label is translator
     * supplied, so a .mo file would be an injection vector into all of
     * wp-admin if badged() passed it through unescaped. Both the badged and
     * the quiet-site path have to escape.
     */
    public function test_the_label_is_escaped_on_both_the_badged_and_the_quiet_path(): void
    {
        $hostile = '<img src=x onerror=alert(1)>Memory';

        $this->assertSame(esc_html($hostile), Memory_Page::badged($hostile, 0));
        $this->assertStringNotContainsString('<img', Memory_Page::badged($hostile, 0));

        Memory_Store::propose(['text' => 'One.']);
        $badged = Memory_Page::badged($hostile);

        $this->assertStringNotContainsString('<img', $badged);
        $this->assertStringContainsString('&lt;img', $badged);
        $this->assertStringContainsString('awaiting-mod', $badged);
    }

    public function test_published_entries_do_not_count_towards_the_badge(): void
    {
        $id = Memory_Store::propose(['text' => 'One.']);
        Memory_Store::approve($id);

        $this->assertSame(0, Memory_Page::pending_count());
    }

    public function test_the_submenu_points_at_the_cpt_list_table(): void
    {
        $this->assertSame('edit.php?post_type=' . Memory_Store::POST_TYPE, Memory_Page::submenu_slug());
    }

    public function test_the_metabox_renders_the_stored_classification(): void
    {
        $id = Memory_Store::propose([
            'text'     => 'Never delete the homepage.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['post_id:5'],
        ]);

        ob_start();
        (new Memory_Page())->render_meta_box(get_post($id));
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('wpmcp_memory_kind', $html);
        $this->assertStringContainsString('value="guardrail" selected', $html);
        $this->assertStringContainsString('value="block" selected', $html);
        $this->assertStringContainsString('post_id:5', $html);
        $this->assertStringContainsString('enforced by the server', $html);
    }

    public function test_saving_the_metabox_updates_the_classification(): void
    {
        $id = Memory_Store::propose(['text' => 'Never delete the homepage.']);

        $_POST[ Memory_Page::NONCE ]      = wp_create_nonce(Memory_Page::NONCE);
        $_POST['wpmcp_memory_kind']       = 'guardrail';
        $_POST['wpmcp_memory_severity']   = 'block';
        $_POST['wpmcp_memory_targets']    = "post_id:5\n\ntool:delete-post\n";

        (new Memory_Page())->save_meta_box($id);

        $entry = Memory_Store::get($id);
        $this->assertSame('guardrail', $entry['kind']);
        $this->assertSame('block', $entry['severity']);
        $this->assertSame(['post_id:5', 'tool:delete-post'], $entry['targets']);
    }

    /**
     * The admin path is not a way around the validator: an untargeted block
     * rule is refused here exactly as it is refused for an agent proposal,
     * and the previous classification survives.
     */
    public function test_an_untargeted_block_edit_is_refused_and_leaves_the_entry_unchanged(): void
    {
        $id = Memory_Store::propose(['text' => 'A note.']);

        $_POST[ Memory_Page::NONCE ]    = wp_create_nonce(Memory_Page::NONCE);
        $_POST['wpmcp_memory_kind']     = 'guardrail';
        $_POST['wpmcp_memory_severity'] = 'block';
        $_POST['wpmcp_memory_targets']  = '';

        (new Memory_Page())->save_meta_box($id);

        $entry = Memory_Store::get($id);
        $this->assertSame('note', $entry['severity']);
        $this->assertSame([], $entry['targets']);
    }

    public function test_saving_without_a_valid_nonce_changes_nothing(): void
    {
        $id = Memory_Store::propose(['text' => 'A note.']);

        $_POST[ Memory_Page::NONCE ]    = 'not-a-nonce';
        $_POST['wpmcp_memory_severity'] = 'block';
        $_POST['wpmcp_memory_targets']  = 'post_id:5';

        (new Memory_Page())->save_meta_box($id);

        $this->assertSame('note', Memory_Store::get($id)['severity']);
    }

    public function test_saving_without_manage_options_changes_nothing(): void
    {
        $id = Memory_Store::propose(['text' => 'A note.']);

        $_POST[ Memory_Page::NONCE ]    = wp_create_nonce(Memory_Page::NONCE);
        $_POST['wpmcp_memory_severity'] = 'block';
        $_POST['wpmcp_memory_targets']  = 'post_id:5';

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        (new Memory_Page())->save_meta_box($id);

        $this->assertSame('note', Memory_Store::get($id)['severity']);
    }

    public function test_the_badge_is_absent_before_the_post_type_exists(): void
    {
        unregister_post_type(Memory_Store::POST_TYPE);

        $this->assertSame(0, Memory_Page::pending_count());

        Memory_Store::ensure_post_type();
    }

    public function test_saving_a_post_that_is_not_a_memory_entry_is_a_no_op(): void
    {
        $post = self::factory()->post->create();

        $_POST[ Memory_Page::NONCE ]    = wp_create_nonce(Memory_Page::NONCE);
        $_POST['wpmcp_memory_severity'] = 'block';

        (new Memory_Page())->save_meta_box($post);

        $this->assertNull(Memory_Store::get($post));
    }

    public function test_parse_targets_splits_lines_and_drops_blanks(): void
    {
        $this->assertSame(
            ['post_id:5', 'tool:delete-post'],
            Memory_Page::parse_targets("  post_id:5 \r\n\n tool:delete-post \n ")
        );
        $this->assertSame([], Memory_Page::parse_targets("\n\n"));
    }

    public function test_register_hooks_wires_the_metabox_and_the_save_handler(): void
    {
        $page = new Memory_Page();
        $page->register_hooks();

        $this->assertNotFalse(has_action('add_meta_boxes', [$page, 'add_meta_box']));
        $this->assertNotFalse(has_action('save_post_' . Memory_Store::POST_TYPE, [$page, 'save_meta_box']));

        remove_action('add_meta_boxes', [$page, 'add_meta_box']);
        remove_action('save_post_' . Memory_Store::POST_TYPE, [$page, 'save_meta_box']);
    }

    /**
     * The metabox save writes the post back through Memory_Store, and that
     * write fires save_post again. The store's re-entrancy latch is what
     * stops the pair from recursing; if it ever regresses this test hangs or
     * blows the stack rather than silently passing.
     */
    public function test_the_metabox_save_hook_does_not_recurse(): void
    {
        $page = new Memory_Page();
        $page->register_hooks();
        $id = Memory_Store::propose(['text' => 'A note.']);

        $_POST[ Memory_Page::NONCE ]    = wp_create_nonce(Memory_Page::NONCE);
        $_POST['wpmcp_memory_kind']     = 'convention';
        $_POST['wpmcp_memory_severity'] = 'note';
        $_POST['wpmcp_memory_targets']  = '';

        wp_update_post(['ID' => $id, 'post_content' => '<b>Bold</b>   note.']);

        $entry = Memory_Store::get($id);
        $this->assertSame('convention', $entry['kind']);
        $this->assertSame('Bold note.', $entry['text']);

        remove_action('add_meta_boxes', [$page, 'add_meta_box']);
        remove_action('save_post_' . Memory_Store::POST_TYPE, [$page, 'save_meta_box']);
    }

    public function test_add_meta_box_registers_against_the_memory_post_type(): void
    {
        global $wp_meta_boxes;
        $wp_meta_boxes = [];

        (new Memory_Page())->add_meta_box();

        $this->assertArrayHasKey('wpmcp-memory-fields', $wp_meta_boxes[ Memory_Store::POST_TYPE ]['side']['default']);
    }
}
