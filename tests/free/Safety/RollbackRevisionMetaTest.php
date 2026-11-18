<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Update_Post;

/**
 * Issue #872: a page builder that copies its layout meta onto each new
 * revision on save_post (Cornerstone, Elementor) saw a rollback put the post
 * row back first and the meta after, so the revision the undo created held
 * the layout that was just undone. The newest revision must hold the
 * restored state, for rollback-operation and rollback-session alike.
 */
class RollbackRevisionMetaTest extends \WP_UnitTestCase
{
    private const KEY = 'builder_layout';

    private const EXTRA = 'builder_extra';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // What a builder's revision service does: copy the parent's layout
        // rows onto the revision being saved.
        add_action('save_post', [self::class, 'copy_meta_to_revision'], 100);
    }

    protected function tearDown(): void
    {
        remove_action('save_post', [self::class, 'copy_meta_to_revision'], 100);
        parent::tearDown();
    }

    public static function copy_meta_to_revision(int $revision_id): void
    {
        $parent_id = wp_is_post_revision($revision_id);
        if (! $parent_id) {
            return;
        }
        foreach ([self::KEY, self::EXTRA] as $key) {
            $value = get_post_meta($parent_id, $key, true);
            if ('' !== $value) {
                update_metadata('post', $revision_id, $key, wp_slash($value));
            }
        }
    }

    private function post(): int
    {
        $post_id = self::factory()->post->create(['post_content' => 'original body']);
        update_post_meta($post_id, self::KEY, wp_slash('{"layout":"original \\u003c"}'));

        return $post_id;
    }

    private function newest_revision(int $post_id): int
    {
        $ids = wp_get_post_revisions($post_id, ['fields' => 'ids', 'posts_per_page' => 1, 'check_enabled' => false]);

        return (int) reset($ids);
    }

    private function edit(int $post_id, array $extra = []): array
    {
        return (new Update_Post())->handle([
            'post_id' => $post_id,
            'content' => 'edited body',
            'meta'    => [self::KEY => '{"layout":"edited"}', self::EXTRA => 'added by the write'],
        ] + $extra);
    }

    private function assert_newest_revision_matches(int $post_id): void
    {
        clean_post_cache($post_id);
        $revision = $this->newest_revision($post_id);
        $this->assertGreaterThan(0, $revision);
        $this->assertSame('original body', get_post($revision)->post_content);
        $this->assertSame(get_post_meta($post_id, self::KEY, true), get_metadata('post', $revision, self::KEY, true), 'the undo revision holds the restored layout');
        $this->assertSame('{"layout":"original \\u003c"}', get_metadata('post', $revision, self::KEY, true));
        $this->assertFalse(metadata_exists('post', $revision, self::EXTRA), 'a row the undone write added is not on the undo revision');
    }

    public function test_rollback_operation_revision_holds_the_restored_meta(): void
    {
        $post_id = $this->post();
        $out     = $this->edit($post_id);
        $edit_revision = $this->newest_revision($post_id);
        $edit_revision_meta = get_post_meta($edit_revision);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame('{"layout":"original \\u003c"}', get_post_meta($post_id, self::KEY, true));
        $this->assertNotSame($edit_revision, $this->newest_revision($post_id), 'the undo saved a revision of its own');
        $this->assert_newest_revision_matches($post_id);
        // The write's own revision keeps what it recorded.
        $this->assertSame($edit_revision_meta, get_post_meta($edit_revision));
    }

    public function test_rollback_session_revision_holds_the_restored_meta(): void
    {
        $post_id = $this->post();
        $this->edit($post_id, ['session_id' => 'revision-872']);

        $this->assertSame(1, Rollback_Service::restore_session('revision-872'));

        $this->assertSame('{"layout":"original \\u003c"}', get_post_meta($post_id, self::KEY, true));
        $this->assert_newest_revision_matches($post_id);
    }
}
