<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Governance\Governance;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;

/**
 * Content writes respect WordPress's post lock (issue #452). A person who
 * has a post open in the editor holds its _edit_lock; a tool that saved the
 * post regardless would lose either the agent's change (when the person
 * saves) or the person's unsaved work (when they reload). Every post write
 * therefore refuses while ANOTHER user holds a live lock, names that user
 * and when the lock was last refreshed, and writes nothing. The caller's own
 * lock and an expired lock never block, reads are never blocked, and a site
 * can switch the check off with the wpmcp_respect_edit_locks filter.
 *
 * Undo follows the same rule: rolling back an agent's write while someone
 * else has that post open would overwrite their editor just the same, so
 * rollback-operation (restored false, the reason as its warning) and
 * rollback-session (a wpmcp_post_locked error) refuse too, restore
 * nothing, and work again once the lock is released.
 *
 * Every call goes through the registered ability, the path an MCP client
 * takes.
 */
class EditLockTest extends \WP_UnitTestCase
{
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
        Snapshot_Store::install();
        Governance::reset_for_tests();
        Rate_Limiter::set_clock_override(fn() => 1_790_000_452);
        add_filter('wpmcp_rate_limit', fn() => 100000);

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

    /** Hold the post's edit lock as $user, refreshed $age seconds ago, as the editor's heartbeat does. */
    private function lock(int $post_id, int $user, int $age = 5): int
    {
        $time = time() - $age;
        update_post_meta($post_id, '_edit_lock', $time . ':' . $user);
        return $time;
    }

    /** @return mixed */
    private function call(string $ability, array $input)
    {
        $a = wp_get_ability($ability);
        $this->assertNotNull($a, $ability . ' is not registered');
        return $a->execute($input);
    }

    private function snapshots_for(int $post_id): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only count of the plugin's own table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %s', Snapshot_Store::table_name(), 'post', (string) $post_id));
    }

    private function content(int $post_id): string
    {
        clean_post_cache($post_id);
        return (string) get_post_field('post_content', $post_id);
    }

    private function assert_locked_refusal($result, int $post_id, int $locked_at): void
    {
        $this->assertWPError($result, 'A write to a post another user is editing must be refused.');
        $this->assertSame('wpmcp_post_locked', $result->get_error_code());
        $message = $result->get_error_message();
        $this->assertStringContainsString('Bea Lockholder', $message, 'The refusal names who holds the lock.');
        $this->assertStringContainsString(gmdate('Y-m-d H:i:s', $locked_at), $message, 'The refusal says when the lock was last refreshed.');
        $this->assertStringContainsString((string) $post_id, $message);
        $data = (array) $result->get_error_data();
        $this->assertSame($post_id, $data['post_id']);
        $this->assertSame($this->editor, $data['locked_by']);
        $this->assertSame(gmdate('c', $locked_at), $data['locked_at']);
    }

    public function test_a_write_to_a_post_another_user_is_editing_is_refused_and_nothing_is_written(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Original body', 'post_title' => 'Original title']);
        $at   = $this->lock($post, $this->editor);

        $result = $this->call('wpmcp/update-post', ['post_id' => $post, 'title' => 'Agent title', 'content' => 'Agent body']);

        $this->assert_locked_refusal($result, $post, $at);
        $this->assertSame('Original body', $this->content($post));
        $this->assertSame('Original title', get_post_field('post_title', $post));
        $this->assertSame(0, $this->snapshots_for($post), 'A refused write leaves no undo point behind.');
    }

    /** @return array<string, array{0: string, 1: callable(int, int): array<string, mixed>}> */
    public static function post_writes(): array
    {
        return [
            'update-blocks'    => ['wpmcp/update-blocks', static fn (int $post, int $rev): array => ['id' => $post, 'blocks' => '<!-- wp:paragraph --><p>Agent</p><!-- /wp:paragraph -->']],
            'restore-revision' => ['wpmcp/restore-revision', static fn (int $post, int $rev): array => ['post_id' => $post, 'revision_id' => $rev]],
            'set-post-meta'    => ['wpmcp/set-post-meta', static fn (int $post, int $rev): array => ['post_id' => $post, 'key' => 'agent_note', 'value' => 'x']],
            'delete-post'      => ['wpmcp/delete-post', static fn (int $post, int $rev): array => ['post_id' => $post]],
        ];
    }

    /**
     * @dataProvider post_writes
     */
    public function test_every_post_write_path_refuses_a_post_another_user_is_editing(string $ability, callable $input): void
    {
        $post = self::factory()->post->create(['post_content' => 'Original body']);
        $rev  = (int) wp_insert_post([
            'post_type'    => 'revision',
            'post_status'  => 'inherit',
            'post_parent'  => $post,
            'post_content' => 'Revision body',
            'post_name'    => $post . '-revision-v1',
        ]);
        $at = $this->lock($post, $this->editor);

        $result = $this->call($ability, $input($post, $rev));

        $this->assert_locked_refusal($result, $post, $at);
        $this->assertSame('Original body', $this->content($post));
        $this->assertSame('publish', get_post_status($post));
        $this->assertSame('', get_post_meta($post, 'agent_note', true));
        $this->assertSame(0, $this->snapshots_for($post));
    }

    public function test_publishing_a_stage_refuses_while_another_user_is_editing_the_original(): void
    {
        $original = self::factory()->post->create(['post_content' => 'Live body']);
        $stage    = $this->call('wpmcp/duplicate-post', ['post_id' => $original, 'stage' => true]);
        $this->assertIsArray($stage);
        $this->assertIsArray($this->call('wpmcp/update-post', ['post_id' => $stage['post_id'], 'content' => 'Staged body']));
        $at = $this->lock($original, $this->editor);

        $result = $this->call('wpmcp/duplicate-post', ['publish_stage' => $stage['post_id']]);

        $this->assert_locked_refusal($result, $original, $at);
        $this->assertSame('Live body', $this->content($original));
    }

    public function test_find_replace_skips_a_locked_post_and_still_writes_the_others(): void
    {
        $locked = self::factory()->post->create(['post_content' => 'Visit Acme Corp']);
        $free   = self::factory()->post->create(['post_content' => 'Call Acme Corp']);
        $this->lock($locked, $this->editor);

        $out = $this->call('wpmcp/find-replace-content', [
            'search'   => 'Acme Corp',
            'replace'  => 'Acme Inc',
            'post_ids' => [$locked, $free],
            'dry_run'  => false,
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('Visit Acme Corp', $this->content($locked));
        $this->assertSame('Call Acme Inc', $this->content($free));
        $this->assertCount(1, $out['failed']);
        $this->assertStringContainsString('Bea Lockholder', wp_json_encode($out['failed']));
    }

    public function test_reads_and_previews_are_never_blocked(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Visit Acme Corp']);
        $this->lock($post, $this->editor);

        $read = $this->call('wpmcp/get-post', ['post_id' => $post]);
        $this->assertIsArray($read, is_wp_error($read) ? $read->get_error_message() : '');

        $preview = $this->call('wpmcp/find-replace-content', ['search' => 'Acme Corp', 'replace' => 'Acme Inc', 'post_ids' => [$post]]);
        $this->assertIsArray($preview, is_wp_error($preview) ? $preview->get_error_message() : '');
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['posts_matched']);
    }

    public function test_the_callers_own_lock_does_not_block_a_write(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Original body']);
        $this->lock($post, $this->agent);

        $result = $this->call('wpmcp/update-post', ['post_id' => $post, 'content' => 'Agent body']);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('Agent body', $this->content($post));
    }

    public function test_an_expired_lock_does_not_block_a_write(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Original body']);
        // Core treats a lock older than its window (150 seconds by default,
        // the wp_check_post_lock_window filter) as released.
        $this->lock($post, $this->editor, 600);

        $result = $this->call('wpmcp/update-post', ['post_id' => $post, 'content' => 'Agent body']);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('Agent body', $this->content($post));
    }

    public function test_a_filter_turns_the_check_off_site_wide(): void
    {
        add_filter('wpmcp_respect_edit_locks', '__return_false');
        $post = self::factory()->post->create(['post_content' => 'Original body']);
        $this->lock($post, $this->editor);

        $result = $this->call('wpmcp/update-post', ['post_id' => $post, 'content' => 'Agent body']);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('Agent body', $this->content($post));
    }

    public function test_rolling_back_the_agents_write_refuses_while_another_user_is_editing_and_works_once_they_leave(): void
    {
        $post  = self::factory()->post->create(['post_content' => 'Original body']);
        $write = $this->call('wpmcp/update-post', ['post_id' => $post, 'content' => 'Agent body']);
        $this->assertIsArray($write);
        $at = $this->lock($post, $this->editor);

        $refused = $this->call('wpmcp/rollback-operation', ['operation_id' => $write['operation_id']]);

        // rollback-operation reports a refusal the way it reports its other
        // refusals: restored false, with the reason as a warning.
        $this->assertIsArray($refused, is_wp_error($refused) ? $refused->get_error_message() : '');
        $this->assertFalse($refused['restored']);
        $this->assertCount(1, $refused['warnings']);
        $this->assertStringContainsString('Bea Lockholder', $refused['warnings'][0]);
        $this->assertStringContainsString(gmdate('Y-m-d H:i:s', $at), $refused['warnings'][0]);
        $this->assertStringContainsString('Nothing was restored', $refused['warnings'][0]);
        $this->assertSame('Agent body', $this->content($post), 'A refused undo restores nothing.');

        // The person closes the editor: the lock lapses and the undo works.
        $this->lock($post, $this->editor, 600);
        $undone = $this->call('wpmcp/rollback-operation', ['operation_id' => $write['operation_id']]);

        $this->assertIsArray($undone, is_wp_error($undone) ? $undone->get_error_message() : '');
        $this->assertTrue($undone['restored']);
        $this->assertSame('Original body', $this->content($post));
    }

    public function test_rolling_back_while_holding_the_lock_yourself_works(): void
    {
        $post  = self::factory()->post->create(['post_content' => 'Original body']);
        $write = $this->call('wpmcp/update-post', ['post_id' => $post, 'content' => 'Agent body']);
        $this->lock($post, $this->agent);

        $undone = $this->call('wpmcp/rollback-operation', ['operation_id' => $write['operation_id']]);

        $this->assertIsArray($undone, is_wp_error($undone) ? $undone->get_error_message() : '');
        $this->assertTrue($undone['restored']);
        $this->assertSame('Original body', $this->content($post));
    }

    public function test_a_session_rollback_touching_a_locked_post_restores_nothing(): void
    {
        $locked  = self::factory()->post->create(['post_content' => 'First original']);
        $free    = self::factory()->post->create(['post_content' => 'Second original']);
        $session = 'edit-lock-session';
        $this->assertIsArray($this->call('wpmcp/update-post', ['post_id' => $locked, 'content' => 'First agent', 'session_id' => $session]));
        $this->assertIsArray($this->call('wpmcp/update-post', ['post_id' => $free, 'content' => 'Second agent', 'session_id' => $session]));
        $at = $this->lock($locked, $this->editor);

        $refused = $this->call('wpmcp/rollback-session', ['session_id' => $session]);

        $this->assert_locked_refusal($refused, $locked, $at);
        $this->assertSame('First agent', $this->content($locked));
        $this->assertSame('Second agent', $this->content($free), 'A session undo is all or nothing.');
    }
}
