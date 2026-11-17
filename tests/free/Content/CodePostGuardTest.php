<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Governance\Governance;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #893: the code of a theme add-on code layout (a Kadence Pro HTML
 * element, an Astra Pro code editor layout) is withheld and refused by the
 * theme layout ops with code_layout, and the generic post and meta tools
 * must hold the same line: get-post withholds it, update-post,
 * update-blocks, find-replace-content, set-post-meta and create-post refuse
 * to change it, and nothing is written. Every call goes through the
 * registered ability, the path an MCP client takes.
 */
class CodePostGuardTest extends \WP_UnitTestCase
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
        Rate_Limiter::set_clock_override(fn() => 1_790_000_893);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        register_post_type('kadence_element', ['public' => false, 'show_ui' => true, 'supports' => ['title', 'editor', 'custom-fields', 'revisions']]);
        register_post_type('astra-advanced-hook', ['public' => false, 'show_ui' => true, 'supports' => ['title', 'editor', 'custom-fields', 'revisions']]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        unregister_post_type('kadence_element');
        unregister_post_type('astra-advanced-hook');
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

    private function kadence(string $type = 'script'): int
    {
        $id = self::factory()->post->create(['post_type' => 'kadence_element', 'post_title' => 'Code', 'post_content' => self::SCRIPT]);
        update_post_meta($id, '_kad_element_type', $type);
        update_post_meta($id, '_kad_element_hook', 'kadence_before_footer');
        return $id;
    }

    private function astra(string $editor = 'code_editor'): int
    {
        $id = self::factory()->post->create(['post_type' => 'astra-advanced-hook', 'post_title' => 'Snippet', 'post_content' => '']);
        update_post_meta($id, 'ast-advanced-hook-layout', 'hooks');
        update_post_meta($id, 'editor_type', $editor);
        update_post_meta($id, 'ast-advanced-hook-php-code', self::SCRIPT);
        return $id;
    }

    private function content(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post_field('post_content', $id);
    }

    private function snapshots_for(int $post_id): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only count of the plugin's own table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %s', Snapshot_Store::table_name(), 'post', (string) $post_id));
    }

    private function assert_code_refusal($result): void
    {
        $this->assertWPError($result, 'A write to a code layout must be refused.');
        $this->assertSame('code_layout', $result->get_error_code(), $result->get_error_message());
        $this->assertStringContainsString('wp-admin only', $result->get_error_message());
    }

    public function test_get_post_withholds_a_kadence_html_elements_code_with_a_note(): void
    {
        $id  = $this->kadence();
        $out = $this->call('wpmcp/get-post', ['post_id' => $id]);

        $this->assertIsArray($out);
        $this->assertSame('', $out['content']);
        $this->assertTrue($out['code_withheld']);
        $this->assertStringContainsString('HTML code element', $out['note']);
        $this->assertStringContainsString('get-kadence-layout', $out['note'], 'The note points to the layout op');
        $this->assertStringNotContainsString('wpmcpKad', (string) wp_json_encode($out));
    }

    public function test_get_post_still_returns_a_kadence_content_sections_markup(): void
    {
        $id  = $this->kadence('default');
        $out = $this->call('wpmcp/get-post', ['post_id' => $id]);

        $this->assertSame(self::SCRIPT, $out['content']);
        $this->assertArrayNotHasKey('code_withheld', $out);
    }

    public function test_update_post_refuses_a_kadence_html_elements_code_and_writes_nothing(): void
    {
        $id = $this->kadence();

        $result = $this->call('wpmcp/update-post', ['post_id' => $id, 'content' => '<p>changed</p><script>x()</script>']);

        $this->assert_code_refusal($result);
        $this->assertSame(self::SCRIPT, $this->content($id));
        $this->assertSame(0, $this->snapshots_for($id), 'A refused write leaves no undo point behind.');
    }

    public function test_update_post_may_still_rename_a_kadence_html_element(): void
    {
        $id = $this->kadence();

        $out = $this->call('wpmcp/update-post', ['post_id' => $id, 'title' => 'Renamed']);

        $this->assertNotWPError($out);
        $this->assertSame('Renamed', get_post_field('post_title', $id));
        $this->assertSame(self::SCRIPT, $this->content($id));
    }

    public function test_update_blocks_refuses_a_kadence_html_elements_code(): void
    {
        $id = $this->kadence();

        $result = $this->call('wpmcp/update-blocks', ['id' => $id, 'blocks' => '<!-- wp:html --><script>x()</script><!-- /wp:html -->']);

        $this->assert_code_refusal($result);
        $this->assertSame(self::SCRIPT, $this->content($id));
    }

    public function test_find_replace_content_leaves_a_kadence_html_element_alone(): void
    {
        $id    = $this->kadence();
        $other = self::factory()->post->create(['post_content' => 'wpmcpKad in a post']);

        $out = $this->call('wpmcp/find-replace-content', ['search' => 'wpmcpKad', 'replace' => 'changed', 'post_types' => ['kadence_element', 'post'], 'dry_run' => false, 'confirm' => true]);

        $this->assertNotWPError($out);
        $this->assertSame(self::SCRIPT, $this->content($id));
        $this->assertSame('changed in a post', $this->content($other));
    }

    public function test_astra_code_layout_code_meta_is_withheld_from_get_post_and_get_post_meta(): void
    {
        $id = $this->astra();

        $post = $this->call('wpmcp/get-post', ['post_id' => $id]);
        $this->assertArrayNotHasKey('ast-advanced-hook-php-code', $post['meta']);
        $this->assertTrue($post['code_withheld']);
        $this->assertStringContainsString('get-astra-layout', $post['note']);

        $all = $this->call('wpmcp/get-post-meta', ['post_id' => $id]);
        $this->assertArrayNotHasKey('ast-advanced-hook-php-code', $all['meta']);
        $this->assertArrayHasKey('editor_type', $all['meta'], 'Settings that are not code stay readable');
        $one = $this->call('wpmcp/get-post-meta', ['post_id' => $id, 'key' => 'ast-advanced-hook-php-code']);
        $this->assertSame([], $one['meta']);
        $this->assertStringNotContainsString('wpmcpKad', (string) wp_json_encode([$post, $all, $one]));
    }

    public function test_astra_code_and_editor_switch_are_refused_by_every_generic_meta_write(): void
    {
        $id   = $this->astra('wordpress_editor');
        $code = '<?php echo "x"; ?>';

        $this->assert_code_refusal($this->call('wpmcp/set-post-meta', ['post_id' => $id, 'key' => 'ast-advanced-hook-php-code', 'value' => $code]));
        $this->assert_code_refusal($this->call('wpmcp/set-post-meta', ['post_id' => $id, 'key' => 'editor_type', 'value' => 'code_editor']));
        $this->assert_code_refusal($this->call('wpmcp/update-post', ['post_id' => $id, 'meta' => ['editor_type' => 'code_editor']]));
        $this->assert_code_refusal($this->call('wpmcp/create-post', ['post_type' => 'astra-advanced-hook', 'title' => 'New', 'meta' => ['ast-advanced-hook-php-code' => $code, 'editor_type' => 'code_editor']]));
        $this->assert_code_refusal($this->call('wpmcp/find-replace-content', ['search' => 'wpmcpKad', 'replace' => 'x', 'fields' => ['meta'], 'meta_keys' => ['ast-advanced-hook-php-code'], 'dry_run' => false, 'confirm' => true]));

        $this->assertSame('wordpress_editor', get_post_meta($id, 'editor_type', true));
        $this->assertSame(self::SCRIPT, get_post_meta($id, 'ast-advanced-hook-php-code', true));
        $this->assertSame(0, $this->snapshots_for($id));

        $plain = self::factory()->post->create();
        $this->assertNotWPError($this->call('wpmcp/set-post-meta', ['post_id' => $plain, 'key' => 'editor_type', 'value' => 'x']), 'editor_type on another post type is ordinary meta');
    }

    public function test_get_revision_withholds_the_code_of_a_kadence_html_element(): void
    {
        $id  = $this->kadence();
        $rev = (int) wp_insert_post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_content' => self::SCRIPT, 'post_name' => $id . '-revision-v1']);

        $out = $this->call('wpmcp/get-revision', ['revision_id' => $rev]);

        $this->assertNotWPError($out);
        $this->assertSame('', $out['content']);
        $listed = $this->call('wpmcp/list-revisions', ['post_id' => $id]);
        $this->assertStringNotContainsString('wpmcpKad', (string) wp_json_encode($listed));
    }
}
