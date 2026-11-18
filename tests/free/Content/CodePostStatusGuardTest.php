<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Governance\Governance;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;

/**
 * Follow-up to issues #890 and #893: the theme layout ops gate switching on
 * a code layout (a Kadence Pro HTML element, an Astra Pro code editor
 * layout, a GP Premium element that executes PHP), and the generic post
 * tools must not switch it on around them. update-post and duplicate-post
 * refuse publish, private, pending and future on such a layout with
 * code_layout, pointing to the layout op, before any snapshot. Unpublishing
 * and the other fields stay open. Every call goes through the registered
 * ability, the path an MCP client takes.
 */
class CodePostStatusGuardTest extends \WP_UnitTestCase
{
    private const SCRIPT = '<script>document.documentElement.dataset.wpmcpKad="ran";</script>';

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
        Rate_Limiter::set_clock_override(fn() => 1_790_000_890);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        foreach (['kadence_element', 'astra-advanced-hook', 'gp_elements'] as $type) {
            register_post_type($type, ['public' => false, 'show_ui' => true, 'supports' => ['title', 'editor', 'custom-fields', 'revisions']]);
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        foreach (['kadence_element', 'astra-advanced-hook', 'gp_elements'] as $type) {
            unregister_post_type($type);
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return mixed */
    private function call(string $ability, array $input)
    {
        $a = wp_get_ability($ability);
        $this->assertNotNull($a, $ability . ' is not registered');
        return $a->execute($input);
    }

    private function kadence(string $type = 'script', string $status = 'draft'): int
    {
        $id = self::factory()->post->create(['post_type' => 'kadence_element', 'post_status' => $status, 'post_title' => 'Code', 'post_content' => self::SCRIPT]);
        update_post_meta($id, '_kad_element_type', $type);
        update_post_meta($id, '_kad_element_hook', 'kadence_before_footer');
        return $id;
    }

    private function astra(string $editor = 'code_editor'): int
    {
        $id = self::factory()->post->create(['post_type' => 'astra-advanced-hook', 'post_status' => 'draft', 'post_title' => 'Snippet', 'post_content' => '']);
        update_post_meta($id, 'ast-advanced-hook-layout', 'hooks');
        update_post_meta($id, 'editor_type', $editor);
        update_post_meta($id, 'ast-advanced-hook-php-code', "<?php echo 'Astra PHP from WP MCP'; ?>");
        return $id;
    }

    private function generatepress(bool $php = true): int
    {
        $id = self::factory()->post->create(['post_type' => 'gp_elements', 'post_status' => 'draft', 'post_title' => 'Hook', 'post_content' => '']);
        update_post_meta($id, '_generate_element_type', 'hook');
        update_post_meta($id, '_generate_hook', 'generate_before_footer');
        update_post_meta($id, '_generate_element_content', "<?php echo 'PHP from WP MCP'; ?>");
        if ($php) {
            update_post_meta($id, '_generate_hook_execute_php', 'true');
        }
        return $id;
    }

    private function status(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post_status($id);
    }

    private function snapshots_for(int $post_id): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only count of the plugin's own table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %s', Snapshot_Store::table_name(), 'post', (string) $post_id));
    }

    private function assert_status_refusal($result, string $op): void
    {
        $this->assertWPError($result, 'Switching on a code layout through a generic tool must be refused.');
        $this->assertSame('code_layout', $result->get_error_code(), $result->get_error_message());
        $this->assertStringContainsString($op, $result->get_error_message(), 'The refusal points to the layout op');
        $this->assertStringContainsString('wp-admin', $result->get_error_message());
        $this->assertStringContainsString('Nothing was written', $result->get_error_message());
    }

    public function test_update_post_refuses_every_live_status_on_a_draft_kadence_html_element(): void
    {
        $id = $this->kadence();

        foreach (['publish', 'private', 'pending', 'future'] as $status) {
            $this->assert_status_refusal($this->call('wpmcp/update-post', ['post_id' => $id, 'status' => $status]), 'update-kadence-layout');
            $this->assertSame('draft', $this->status($id), "status {$status} was written");
        }
        $this->assertSame(0, $this->snapshots_for($id), 'A refused write leaves no undo point behind.');
    }

    public function test_update_post_refuses_publishing_an_astra_code_editor_layout(): void
    {
        $id = $this->astra();

        $this->assert_status_refusal($this->call('wpmcp/update-post', ['post_id' => $id, 'status' => 'publish', 'title' => 'Live']), 'update-astra-layout');
        $this->assertSame('draft', $this->status($id));
        $this->assertSame('Snippet', get_post_field('post_title', $id), 'Nothing in the call is written');
        $this->assertSame(0, $this->snapshots_for($id));
    }

    public function test_update_post_refuses_publishing_a_gp_element_that_executes_php(): void
    {
        $id = $this->generatepress();

        $this->assert_status_refusal($this->call('wpmcp/update-post', ['post_id' => $id, 'status' => 'publish']), 'update-generatepress-layout');
        $this->assertSame('draft', $this->status($id));
        $this->assertSame(0, $this->snapshots_for($id));
    }

    public function test_layouts_without_code_still_publish_through_update_post(): void
    {
        foreach ([$this->kadence('default'), $this->astra('wordpress_editor'), $this->generatepress(false)] as $id) {
            $out = $this->call('wpmcp/update-post', ['post_id' => $id, 'status' => 'publish']);
            $this->assertNotWPError($out);
            $this->assertSame('publish', $this->status($id));
        }
    }

    public function test_update_post_may_still_unpublish_and_rename_a_published_code_layout_with_undo(): void
    {
        $id = $this->kadence('script', 'publish');

        $out = $this->call('wpmcp/update-post', ['post_id' => $id, 'status' => 'draft']);
        $this->assertNotWPError($out);
        $this->assertSame('draft', $this->status($id));

        $undo = $this->call('wpmcp/rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertNotWPError($undo);
        $this->assertSame('publish', $this->status($id), 'Undo puts a published layout back');

        $same = $this->call('wpmcp/update-post', ['post_id' => $id, 'status' => 'publish', 'title' => 'Renamed']);
        $this->assertNotWPError($same, 'Keeping the status a layout already has switches nothing on');
        $this->assertSame('Renamed', get_post_field('post_title', $id));
    }

    public function test_duplicate_post_refuses_a_live_copy_of_a_code_layout_but_copies_a_draft(): void
    {
        $id     = $this->kadence();
        $before = count(get_posts(['post_type' => 'kadence_element', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1]));

        foreach (['publish', 'private', 'pending'] as $status) {
            $this->assert_status_refusal($this->call('wpmcp/duplicate-post', ['post_id' => $id, 'status' => $status]), 'update-kadence-layout');
        }
        $this->assertCount($before, get_posts(['post_type' => 'kadence_element', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1]), 'No copy was made');

        $draft = $this->call('wpmcp/duplicate-post', ['post_id' => $id]);
        $this->assertNotWPError($draft);
        $this->assertSame('draft', $this->status((int) $draft['post_id']));
    }
}
