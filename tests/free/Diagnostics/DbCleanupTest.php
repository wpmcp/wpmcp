<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Confirmation_Required;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Diagnostics\Db_Cleanup;
use WPMCP\Tools\Diagnostics\Delete_Transient;

/**
 * Database cleanup on delete-transient (issue #414): a dry run by default,
 * a confirmed real run that snapshots every row it deletes (except expired
 * transients) so rollback-operation restores it, bounded per call by an item
 * cap, a snapshot byte cap and a time budget, continued with a cursor.
 */
class DbCleanupTest extends \WP_UnitTestCase
{
    private const ALL = [ 'expired_transients', 'revisions', 'auto_drafts', 'trash', 'spam_comments', 'orphaned_meta' ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        delete_option(Governance_Audit_Log::OPTION);
    }

    /** A post with $n revisions, oldest first; returns [post id, revision ids oldest first]. */
    private function post_with_revisions(int $n): array
    {
        global $wpdb;
        $post = self::factory()->post->create([ 'post_status' => 'publish' ]);
        $ids  = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = self::factory()->post->create([
                'post_type'   => 'revision',
                'post_status' => 'inherit',
                'post_parent' => $post,
                'post_name'   => $post . '-revision-v1',
                'post_date'   => gmdate('Y-m-d H:i:s', time() - (($n - $i) * DAY_IN_SECONDS)),
            ]);
        }
        foreach ($ids as $id) {
            add_metadata('post', $id, '_wpmcp_rev_meta', 'r' . $id);
        }
        $wpdb->flush();
        return [ $post, $ids ];
    }

    private function old_auto_draft(): int
    {
        $id = self::factory()->post->create([
            'post_status' => 'auto-draft',
            'post_date'   => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
        ]);
        add_post_meta($id, '_wpmcp_draft_meta', 'd');
        return $id;
    }

    private function trashed_post(int $days_ago = 40): int
    {
        $id = self::factory()->post->create([ 'post_status' => 'publish' ]);
        wp_trash_post($id);
        update_post_meta($id, '_wp_trash_meta_time', time() - $days_ago * DAY_IN_SECONDS);
        return $id;
    }

    private function spam_comment(string $content = 'spam'): int
    {
        $post = self::factory()->post->create();
        $id   = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_approved' => 'spam', 'comment_content' => $content ]);
        add_comment_meta($id, '_wpmcp_spam_meta', 's');
        return $id;
    }

    /** One orphaned row in each of postmeta, usermeta, termmeta and commentmeta. */
    private function orphaned_meta(): array
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, [ 'post_id' => 999901, 'meta_key' => '_wpmcp_orphan', 'meta_value' => 'p' ]);
        $post = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->usermeta, [ 'user_id' => 999902, 'meta_key' => '_wpmcp_orphan', 'meta_value' => 'u' ]);
        $user = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->termmeta, [ 'term_id' => 999903, 'meta_key' => '_wpmcp_orphan', 'meta_value' => 't' ]);
        $term = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->commentmeta, [ 'comment_id' => 999904, 'meta_key' => '_wpmcp_orphan', 'meta_value' => 'c' ]);
        $comment = (int) $wpdb->insert_id;
        return [ 'postmeta' => $post, 'usermeta' => $user, 'termmeta' => $term, 'commentmeta' => $comment ];
    }

    private function expired_transient(string $name): void
    {
        set_transient($name, 'stale', HOUR_IN_SECONDS);
        update_option('_transient_timeout_' . $name, time() - 60);
    }

    private function post_exists(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id));
    }

    private function comment_exists(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID = %d", $id));
    }

    private function meta_row_exists(string $table, int $id): bool
    {
        global $wpdb;
        $pk = 'usermeta' === $table ? 'umeta_id' : 'meta_id';
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->$table, $pk, $id));
    }

    private function option_exists(string $name): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    /** A byte-for-byte picture of every row cleanup may touch. */
    private function state(): array
    {
        global $wpdb;
        return [
            'posts'       => $wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A),
            'postmeta'    => $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A),
            'comments'    => $wpdb->get_results("SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A),
            'commentmeta' => $wpdb->get_results("SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A),
            'termmeta'    => $wpdb->get_results("SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id", ARRAY_A),
            'usermeta'    => $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
            'rels'        => $wpdb->get_results("SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A),
        ];
    }

    public function test_dry_run_is_the_default_and_deletes_nothing(): void
    {
        $this->post_with_revisions(4);
        $this->old_auto_draft();
        $this->trashed_post();
        $this->spam_comment();
        $this->orphaned_meta();
        $this->expired_transient('wpmcp_cleanup_dry');
        $before = $this->state();

        $out = (new Delete_Transient())->handle([ 'cleanup' => self::ALL, 'keep' => 1 ]);

        $this->assertTrue($out['dry_run']);
        $this->assertSame($before, $this->state());
        $this->assertTrue($this->option_exists('_transient_wpmcp_cleanup_dry'));
        $this->assertSame(3, $out['categories']['revisions']['count']);
        $this->assertGreaterThanOrEqual(1, $out['categories']['auto_drafts']['count']);
        $this->assertGreaterThanOrEqual(1, $out['categories']['trash']['count']);
        $this->assertGreaterThanOrEqual(1, $out['categories']['spam_comments']['count']);
        $this->assertGreaterThanOrEqual(4, $out['categories']['orphaned_meta']['count']);
        $this->assertGreaterThanOrEqual(1, $out['categories']['expired_transients']['count']);
        foreach (self::ALL as $category) {
            $this->assertArrayHasKey('bytes', $out['categories'][ $category ], $category);
            $this->assertIsArray($out['categories'][ $category ]['sample'], $category);
        }
        $this->assertGreaterThan(0, $out['categories']['revisions']['bytes']);
    }

    public function test_real_run_requires_confirm(): void
    {
        $id = $this->spam_comment();

        try {
            (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false ]);
            $this->fail('Expected a confirmation refusal.');
        } catch (Confirmation_Required $e) {
            $this->assertStringContainsString('confirm', $e->getMessage());
        }
        $this->assertTrue($this->comment_exists($id));
    }

    public function test_real_run_requires_manage_options(): void
    {
        $id = $this->spam_comment();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $this->expectException(\RuntimeException::class);
        try {
            (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);
        } finally {
            $this->assertTrue($this->comment_exists($id));
        }
    }

    public function test_unknown_category_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Transient())->handle([ 'cleanup' => [ 'everything' ] ]);
    }

    public function test_revisions_pruning_keeps_the_n_most_recent_per_post(): void
    {
        [ , $revisions ] = $this->post_with_revisions(5);
        [ , $other ]     = $this->post_with_revisions(2);

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'revisions' ], 'keep' => 2, 'dry_run' => false, 'confirm' => true ]);

        $this->assertSame(3, $out['categories']['revisions']['deleted']);
        foreach (array_slice($revisions, 0, 3) as $id) {
            $this->assertFalse($this->post_exists($id), "oldest revision {$id} should be pruned");
            $this->assertSame([], get_post_meta($id, '_wpmcp_rev_meta'));
        }
        foreach (array_slice($revisions, 3) as $id) {
            $this->assertTrue($this->post_exists($id), "recent revision {$id} should be kept");
        }
        foreach ($other as $id) {
            $this->assertTrue($this->post_exists($id));
        }
    }

    public function test_orphaned_meta_covers_post_user_term_and_comment_meta(): void
    {
        $rows      = $this->orphaned_meta();
        $post      = self::factory()->post->create();
        $live_meta = add_post_meta($post, '_wpmcp_live', 'keep');

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'orphaned_meta' ], 'dry_run' => false, 'confirm' => true ]);

        foreach ($rows as $table => $id) {
            $this->assertFalse($this->meta_row_exists($table, $id), "orphaned {$table} row should be deleted");
        }
        $this->assertTrue($this->meta_row_exists('postmeta', (int) $live_meta));
        $this->assertGreaterThanOrEqual(4, $out['categories']['orphaned_meta']['deleted']);
    }

    public function test_cleanup_run_rolls_back_except_expired_transients(): void
    {
        $this->post_with_revisions(3);
        $draft = $this->old_auto_draft();
        $trash = $this->trashed_post();
        $term  = self::factory()->category->create();
        wp_set_post_categories($trash, [ $term ]);
        $trash_comment = self::factory()->comment->create([ 'comment_post_ID' => $trash, 'comment_approved' => 'post-trashed' ]);
        $spam          = $this->spam_comment();
        $this->orphaned_meta();
        $this->expired_transient('wpmcp_cleanup_rb');
        $before = $this->state();

        $out = (new Delete_Transient())->handle([ 'cleanup' => self::ALL, 'keep' => 1, 'dry_run' => false, 'confirm' => true ]);

        $this->assertFalse($out['dry_run']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertFalse($this->post_exists($draft));
        $this->assertFalse($this->post_exists($trash));
        $this->assertFalse($this->comment_exists($trash_comment));
        $this->assertFalse($this->comment_exists($spam));
        $this->assertFalse($this->option_exists('_transient_wpmcp_cleanup_rb'));
        $this->assertFalse($out['categories']['expired_transients']['recoverable']);
        $this->assertTrue($out['categories']['revisions']['recoverable']);
        $this->assertStringContainsString('transient', strtolower(implode(' ', $out['notes'])));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $after = $this->state();
        $this->assertSame($before, $after);
        $this->assertFalse($this->option_exists('_transient_wpmcp_cleanup_rb'), 'expired transients are not restored');
        $this->assertSame('trash', get_post_status($trash));
        $this->assertSame([ $term ], wp_get_post_categories($trash));
    }

    public function test_rows_two_categories_share_are_snapshotted_once(): void
    {
        [ $post, $revisions ] = $this->post_with_revisions(3);
        wp_trash_post($post);
        update_post_meta($post, '_wp_trash_meta_time', time() - 40 * DAY_IN_SECONDS);
        $before = $this->state();

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'revisions', 'trash' ], 'keep' => 1, 'dry_run' => false, 'confirm' => true ]);

        $this->assertFalse($this->post_exists($post));
        foreach ($revisions as $id) {
            $this->assertFalse($this->post_exists($id));
        }
        Rollback_Service::take_warnings();
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([], Rollback_Service::take_warnings());
        $this->assertSame($before, $this->state());
    }

    public function test_item_cap_returns_a_cursor_and_the_next_call_continues(): void
    {
        $ids = [ $this->spam_comment(), $this->spam_comment(), $this->spam_comment() ];

        $first = (new Db_Cleanup(null, 2))->run([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertSame(2, $first['categories']['spam_comments']['deleted']);
        $this->assertNotNull($first['cursor']);
        $this->assertFalse($first['done']);
        $this->assertTrue($this->comment_exists($ids[2]));

        $second = (new Db_Cleanup(null, 2))->run([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true, 'cursor' => $first['cursor'] ]);

        $this->assertSame(1, $second['categories']['spam_comments']['deleted']);
        $this->assertNull($second['cursor']);
        $this->assertTrue($second['done']);
        $this->assertFalse($this->comment_exists($ids[2]));

        // Each call is its own undo point.
        $this->assertNotSame($first['operation_id'], $second['operation_id']);
        $this->assertTrue(Rollback_Service::restore_operation($first['operation_id']));
        $this->assertTrue($this->comment_exists($ids[0]));
        $this->assertTrue($this->comment_exists($ids[1]));
        $this->assertFalse($this->comment_exists($ids[2]));
    }

    public function test_snapshot_byte_cap_stops_the_call_with_a_cursor(): void
    {
        $ids = [ $this->spam_comment(str_repeat('x', 1000)), $this->spam_comment(str_repeat('y', 1000)) ];

        // Room for one comment's rows (about 1.6KB), not two.
        $out = (new Db_Cleanup(null, 100, 1800))->run([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertSame(1, $out['categories']['spam_comments']['deleted']);
        $this->assertNotNull($out['cursor']);
        $this->assertFalse($this->comment_exists($ids[0]));
        $this->assertTrue($this->comment_exists($ids[1]));
    }

    public function test_time_budget_stops_the_call_with_a_cursor(): void
    {
        $ids   = [ $this->spam_comment(), $this->spam_comment(), $this->spam_comment() ];
        $now   = 1000.0;
        $clock = static function () use (&$now): float {
            $now += 4.0;
            return $now;
        };

        $out = (new Db_Cleanup($clock, 100, 4 * MB_IN_BYTES, 10.0))->run([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertGreaterThanOrEqual(1, $out['categories']['spam_comments']['deleted']);
        $this->assertLessThan(3, $out['categories']['spam_comments']['deleted']);
        $this->assertNotNull($out['cursor']);
        $this->assertTrue($this->comment_exists($ids[2]));
    }

    public function test_nothing_outside_the_selected_categories_is_touched(): void
    {
        $this->post_with_revisions(4);
        $this->old_auto_draft();
        $this->trashed_post();
        $this->orphaned_meta();
        $this->expired_transient('wpmcp_cleanup_scope');
        set_transient('wpmcp_cleanup_live', 'fresh', HOUR_IN_SECONDS);
        $published = self::factory()->post->create([ 'post_status' => 'publish' ]);
        $approved  = self::factory()->comment->create([ 'comment_post_ID' => $published ]);
        $spam      = $this->spam_comment();
        $before    = $this->state();

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'keep' => 0, 'dry_run' => false, 'confirm' => true ]);

        $this->assertSame([ 'spam_comments' ], array_keys($out['categories']));
        $this->assertFalse($this->comment_exists($spam));
        $after = $this->state();
        $spam_rows = static fn (array $rows, string $col): array => array_values(array_filter($rows, static fn (array $r): bool => (int) $r[ $col ] !== $spam));
        $this->assertSame($before['posts'], $after['posts']);
        $this->assertSame($before['postmeta'], $after['postmeta']);
        $this->assertSame($before['termmeta'], $after['termmeta']);
        $this->assertSame($before['usermeta'], $after['usermeta']);
        $this->assertSame($before['rels'], $after['rels']);
        $this->assertSame($spam_rows($before['comments'], 'comment_ID'), $after['comments']);
        $this->assertSame($spam_rows($before['commentmeta'], 'comment_id'), $after['commentmeta']);
        $this->assertTrue($this->comment_exists($approved));
        $this->assertTrue($this->option_exists('_transient_wpmcp_cleanup_scope'));
        $this->assertSame('fresh', get_transient('wpmcp_cleanup_live'));
    }

    public function test_expired_transients_only_removes_expired_ones(): void
    {
        $this->expired_transient('wpmcp_cleanup_old');
        set_transient('wpmcp_cleanup_new', 'fresh', HOUR_IN_SECONDS);

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'expired_transients' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertFalse($this->option_exists('_transient_wpmcp_cleanup_old'));
        $this->assertFalse($this->option_exists('_transient_timeout_wpmcp_cleanup_old'));
        $this->assertSame('fresh', get_transient('wpmcp_cleanup_new'));
        $this->assertNull($out['operation_id']);
        $this->assertFalse($out['categories']['expired_transients']['recoverable']);
    }

    public function test_trash_respects_days_and_skips_what_the_snapshot_cannot_restore(): void
    {
        $old        = $this->trashed_post(40);
        $recent     = $this->trashed_post(2);
        $parent     = $this->trashed_post(40);
        $child      = self::factory()->post->create([ 'post_type' => 'page', 'post_parent' => $parent ]);
        $attachment = self::factory()->attachment->create([ 'post_status' => 'trash' ]);
        update_post_meta($attachment, '_wp_trash_meta_time', time() - 40 * DAY_IN_SECONDS);

        $dry = (new Delete_Transient())->handle([ 'cleanup' => [ 'trash' ], 'days' => 30 ]);
        $this->assertSame(1, $dry['categories']['trash']['count']);
        $this->assertSame(2, $dry['categories']['trash']['skipped']);

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'trash' ], 'days' => 30, 'dry_run' => false, 'confirm' => true ]);

        $this->assertFalse($this->post_exists($old));
        $this->assertTrue($this->post_exists($recent));
        $this->assertTrue($this->post_exists($parent));
        $this->assertTrue($this->post_exists($child));
        $this->assertTrue($this->post_exists($attachment));
        $this->assertSame(2, $out['categories']['trash']['skipped']);
        $this->assertNotEmpty($out['notes']);
    }

    public function test_auto_drafts_younger_than_a_week_are_kept(): void
    {
        $old   = $this->old_auto_draft();
        $fresh = self::factory()->post->create([ 'post_status' => 'auto-draft' ]);

        (new Delete_Transient())->handle([ 'cleanup' => [ 'auto_drafts' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertFalse($this->post_exists($old));
        $this->assertTrue($this->post_exists($fresh));
    }

    public function test_spam_comment_with_replies_is_skipped(): void
    {
        $spam  = $this->spam_comment();
        $reply = self::factory()->comment->create([ 'comment_post_ID' => get_comment($spam)->comment_post_ID, 'comment_parent' => $spam ]);

        $out = (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        $this->assertTrue($this->comment_exists($spam));
        $this->assertTrue($this->comment_exists($reply));
        $this->assertSame(1, $out['categories']['spam_comments']['skipped']);
    }

    public function test_real_run_is_audit_logged(): void
    {
        $this->spam_comment();

        (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame('wpmcp/delete-transient', $entry['ability']);
        $this->assertTrue($entry['allowed']);
        $this->assertStringStartsWith('db-cleanup:', $entry['reason']);
    }

    public function test_rollback_of_a_cleanup_requires_manage_options(): void
    {
        $spam = $this->spam_comment();
        $out  = (new Delete_Transient())->handle([ 'cleanup' => [ 'spam_comments' ], 'dry_run' => false, 'confirm' => true ]);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse($this->comment_exists($spam));
    }

    public function test_the_cleanup_snapshot_type_is_restorable(): void
    {
        $this->assertContains('db_cleanup', Rollback_Service::restorable_object_types());
    }

    public function test_schema_advertises_cleanup_and_name_is_optional(): void
    {
        $abilities = wp_get_abilities();
        $ability   = $abilities['wpmcp/delete-transient'];
        $schema    = $ability->get_input_schema();

        $this->assertArrayHasKey('cleanup', $schema['properties']);
        foreach ([ 'keep', 'days', 'dry_run', 'confirm', 'cursor' ] as $key) {
            $this->assertArrayHasKey($key, $schema['properties'], $key);
        }
        $this->assertNotContains('name', (array) ($schema['required'] ?? []));
    }
}
