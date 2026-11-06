<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Sync\Change_Set_Builder;

/**
 * Issue #192: create-post and duplicate-post write a creation row to the
 * snapshot ledger, so a change set derived from a session lists the posts
 * that session created, and rolling the creation back moves the post to the
 * trash (never a hard delete, so restoring it stays possible).
 */
class CreationRowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function rows(string $session): array
    {
        return Snapshot_Store::list_by_session($session);
    }

    public function test_create_post_writes_a_creation_row_under_the_session(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Fresh', 'session_id' => 'build-1']);

        $rows = $this->rows('build-1');
        $this->assertCount(1, $rows);
        $this->assertSame('post_create', $rows[0]['object_type']);
        $this->assertSame($out['post_id'], (int) $rows[0]['object_id']);
        $this->assertSame('create-post', $rows[0]['tool_name']);
        $this->assertSame($rows[0]['operation_id'], $out['operation_id']);
    }

    public function test_create_post_without_a_session_uses_the_default_session(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Fresh']);

        $ids = array_map('intval', wp_list_pluck($this->rows('default'), 'object_id'));
        $this->assertContains($out['post_id'], $ids);
    }

    public function test_duplicate_post_writes_one_creation_row_covering_the_copy_and_its_children(): void
    {
        $parent = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Parent']);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent, 'post_title' => 'Child']);

        $out = (new Duplicate_Post())->handle(['post_id' => $parent, 'include_children' => true, 'session_id' => 'dup']);

        $rows = $this->rows('dup');
        $this->assertCount(1, $rows);
        $this->assertSame('post_create', $rows[0]['object_type']);
        $this->assertSame('duplicate-post', $rows[0]['tool_name']);
        $this->assertSame($out['post_id'], (int) $rows[0]['object_id']);
        $this->assertSame($rows[0]['operation_id'], $out['operation_id']);
        $this->assertCount(1, $out['children']);
    }

    public function test_a_change_set_from_the_session_lists_the_created_posts(): void
    {
        $created = (new Create_Post())->handle(['title' => 'Created', 'session_id' => 'cs']);
        $source  = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Source']);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $source, 'post_title' => 'Kid']);
        $dup = (new Duplicate_Post())->handle(['post_id' => $source, 'include_children' => true, 'session_id' => 'cs']);
        self::factory()->post->create(['post_title' => 'Untouched']);

        $set = (new Change_Set_Builder())->build(['session_id' => 'cs']);

        $ids = array_map('intval', wp_list_pluck($set['objects'], 'object_id'));
        sort($ids);
        $expected = [$created['post_id'], $dup['post_id'], $dup['children'][0]];
        sort($expected);
        $this->assertSame($expected, $ids, 'The change set lists exactly the posts the session created');
        foreach ($set['objects'] as $object) {
            $this->assertSame('absent', $object['base']['state'], 'A created post has no base revision on the target');
        }
    }

    public function test_rollback_operation_trashes_a_created_post_and_it_can_be_restored(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Oops', 'status' => 'publish', 'session_id' => 's']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($result['restored']);
        $post = get_post($out['post_id']);
        $this->assertNotNull($post, 'Rollback of a creation must never hard delete');
        $this->assertSame('trash', $post->post_status);

        wp_untrash_post($out['post_id']);
        $this->assertNotSame('trash', get_post_status($out['post_id']), 'The trashed post restores from the trash');
    }

    public function test_rollback_operation_trashes_every_post_a_duplicate_created(): void
    {
        $source = self::factory()->post->create(['post_type' => 'page']);
        $child  = self::factory()->post->create(['post_type' => 'page', 'post_parent' => $source]);

        $out = (new Duplicate_Post())->handle(['post_id' => $source, 'include_children' => true, 'session_id' => 's']);
        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame('trash', get_post_status($out['post_id']));
        $this->assertSame('trash', get_post_status($out['children'][0]));
        $this->assertNotSame('trash', get_post_status($source), 'The source is never touched');
        $this->assertNotSame('trash', get_post_status($child));
    }

    public function test_rollback_session_trashes_a_created_post_even_after_it_was_edited(): void
    {
        $out = (new Create_Post())->handle(['title' => 'V1', 'session_id' => 'edit-after']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'title' => 'V2', 'session_id' => 'edit-after']);

        (new Rollback_Session())->handle(['session_id' => 'edit-after']);

        $post = get_post($out['post_id']);
        $this->assertNotNull($post);
        $this->assertSame('trash', $post->post_status, 'The later edit must not bring the created post back out of the trash');
    }

    public function test_rollback_session_trashes_a_duplicated_child_even_after_it_was_edited(): void
    {
        $source = self::factory()->post->create(['post_type' => 'page']);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $source]);

        $out = (new Duplicate_Post())->handle(['post_id' => $source, 'include_children' => true, 'session_id' => 'kid']);
        (new Update_Post())->handle(['post_id' => $out['children'][0], 'title' => 'Edited kid', 'session_id' => 'kid']);

        (new Rollback_Session())->handle(['session_id' => 'kid']);

        $this->assertSame('trash', get_post_status($out['children'][0]));
    }

    public function test_rollback_session_of_a_mixed_session_restores_edits_exactly_and_trashes_creations(): void
    {
        $existing = self::factory()->post->create(['post_title' => 'Original', 'post_content' => 'Before']);
        $before   = get_post($existing, ARRAY_A);

        $created = (new Create_Post())->handle(['title' => 'New', 'session_id' => 'mixed']);
        (new Update_Post())->handle(['post_id' => $existing, 'title' => 'Changed', 'content' => 'After', 'session_id' => 'mixed']);
        $source = self::factory()->post->create(['post_title' => 'Dup source']);
        $dup    = (new Duplicate_Post())->handle(['post_id' => $source, 'session_id' => 'mixed']);

        $result = (new Rollback_Session())->handle(['session_id' => 'mixed']);

        $this->assertSame([], $result['warnings']);
        $after = get_post($existing, ARRAY_A);
        $this->assertSame($before['post_title'], $after['post_title']);
        $this->assertSame($before['post_content'], $after['post_content']);
        $this->assertSame($before['post_status'], $after['post_status']);
        $this->assertSame('trash', get_post_status($created['post_id']));
        $this->assertSame('trash', get_post_status($dup['post_id']));
        $this->assertNotSame('trash', get_post_status($source));
    }

    public function test_a_reclaimed_id_is_left_untouched_with_a_warning(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Mine', 'session_id' => 'reclaim']);
        global $wpdb;
        // Simulate a different post occupying the id: post_date_gmt is set once at creation.
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '2001-01-01 00:00:00'], ['ID' => $out['post_id']]);
        clean_post_cache($out['post_id']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertNotSame('trash', get_post_status($out['post_id']));
        $this->assertNotEmpty($result['warnings']);
    }

    /** Issue #756: a post created with a date that no longer matches is still reported as reclaimed. */
    public function test_a_published_creation_whose_date_changed_is_reported_as_reclaimed(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Live', 'status' => 'publish', 'session_id' => 'reclaim-live']);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '2001-01-01 00:00:00'], ['ID' => $out['post_id']]);
        clean_post_cache($out['post_id']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertFalse($result['restored']);
        $this->assertSame('publish', get_post_status($out['post_id']));
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('reclaimed', $result['warnings'][0]);
    }

    /**
     * Issue #756: publishing a draft sets post_date_gmt, which the creation
     * row recorded as the zero date, so the session's create undo took the
     * published post for a different one and left it published. The later
     * edit is put back first, so the identity check sees the post as created.
     */
    public function test_rollback_session_trashes_a_draft_that_was_published_in_the_same_session(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Core iso', 'status' => 'draft', 'session_id' => 'core-1']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'status' => 'publish', 'session_id' => 'core-1']);
        $this->assertSame('publish', get_post_status($out['post_id']));

        $result = (new Rollback_Session())->handle(['session_id' => 'core-1']);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(2, $result['restored_count']);
        $this->assertSame('trash', get_post_status($out['post_id']));
    }

    /** Issue #756: the session's later edit is undone before the creation, so the trashed post keeps its original title. */
    public function test_rollback_session_trashes_a_retitled_draft_with_its_original_title(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Original', 'status' => 'draft', 'session_id' => 'retitle']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'title' => 'Edited', 'session_id' => 'retitle']);

        $result = (new Rollback_Session())->handle(['session_id' => 'retitle']);

        $this->assertSame([], $result['warnings']);
        $this->assertSame('trash', get_post_status($out['post_id']));
        $this->assertSame('Original', get_post_field('post_title', $out['post_id']));
    }

    /** Issue #756: several later edits of the created post, the oldest state after the create wins. */
    public function test_rollback_session_trashes_a_draft_edited_several_times_with_its_original_title(): void
    {
        $out = (new Create_Post())->handle(['title' => 'First', 'status' => 'draft', 'session_id' => 'many']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'title' => 'Second', 'session_id' => 'many']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'status' => 'publish', 'session_id' => 'many']);
        (new Update_Post())->handle(['post_id' => $out['post_id'], 'title' => 'Third', 'session_id' => 'many']);

        $result = (new Rollback_Session())->handle(['session_id' => 'many']);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(4, $result['restored_count']);
        $this->assertSame('trash', get_post_status($out['post_id']));
        $this->assertSame('First', get_post_field('post_title', $out['post_id']));
    }

    /**
     * Issue #756: an undo point whose post was left untouched was not
     * applied, so it is not counted and not recorded as undone; it stays
     * retryable.
     */
    public function test_a_creation_left_untouched_is_not_marked_rolled_back(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Mine', 'session_id' => 'untouched']);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '2001-01-01 00:00:00'], ['ID' => $out['post_id']]);
        clean_post_cache($out['post_id']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($result['restored']);
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($out['operation_id'])));

        $session = (new Rollback_Session())->handle(['session_id' => 'untouched']);
        $this->assertSame(0, $session['restored_count']);
        $this->assertNotEmpty($session['warnings']);
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($out['operation_id'])));
        $this->assertNotSame('trash', get_post_status($out['post_id']));
    }

    /**
     * Issue #756: a draft published outside the undo order (its create undone
     * on its own) is left untouched, and the warning says it was changed
     * since, not that another post took its id.
     */
    public function test_undoing_only_the_create_of_a_since_published_draft_does_not_claim_the_id_was_reclaimed(): void
    {
        $out    = (new Create_Post())->handle(['title' => 'Later', 'status' => 'draft', 'session_id' => 'solo']);
        $update = (new Update_Post())->handle(['post_id' => $out['post_id'], 'status' => 'publish', 'session_id' => 'solo']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertFalse($result['restored']);
        $this->assertSame('publish', get_post_status($out['post_id']));
        $this->assertCount(1, $result['warnings']);
        $this->assertStringNotContainsString('reclaimed', $result['warnings'][0]);
        $this->assertStringContainsString('published', $result['warnings'][0]);

        // Undone in reverse order, both work.
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $update['operation_id']])['restored']);
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $out['operation_id']])['restored']);
        $this->assertSame('trash', get_post_status($out['post_id']));
    }

    public function test_post_create_is_listed_as_restorable(): void
    {
        $this->assertContains('post_create', Rollback_Service::restorable_object_types());
    }
}
