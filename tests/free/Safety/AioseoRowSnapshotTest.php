<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

require_once __DIR__ . '/../../support/aioseo-tables.php';

/**
 * The 'aioseo_row' snapshot type (issue #294). All in One SEO keeps a post's
 * or term's SEO fields in a row of its own `aioseo_posts` / `aioseo_terms`
 * table, which neither the post nor the term snapshot sees. This captures
 * every row the object has, verbatim, and restores exactly that set: the
 * same primary key, every column including NULLs, and no row at all when
 * there was none before.
 *
 * It is not db_rows: that type restores only rows the tool's WHERE matched
 * (it cannot remove a row the write inserted) and its restore is gated at
 * manage_options, while the SEO tools and rollback run at edit_posts.
 */
class AioseoRowSnapshotTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_aioseo_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_aioseo_tables();
    }

    private function insert(string $kind, int $id, array $columns): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . ('term' === $kind ? 'aioseo_terms' : 'aioseo_posts'),
            array_merge(
                ['term' === $kind ? 'term_id' : 'post_id' => $id, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
                $columns
            )
        );

        return (int) $wpdb->insert_id;
    }

    public function test_restore_puts_the_captured_row_back_exactly(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $row_id  = $this->insert('post', $post_id, [
            'title'          => 'Before',
            'robots_default' => 0,
            'robots_noindex' => 1,
            'og_image_width' => null,
            'schema_type'    => 'Article',
        ]);
        $before = wpmcp_test_aioseo_rows('post', $post_id);

        $snapshot = Snapshot::capture('aioseo_row', 'post:' . $post_id);
        $this->assertSame('aioseo_row', $snapshot['object_type']);
        $this->assertSame('post:' . $post_id, $snapshot['object_id']);
        $this->assertTrue($snapshot['data']['table_exists']);
        $this->assertSame($before, $snapshot['data']['rows']);

        $wpdb->update($wpdb->prefix . 'aioseo_posts', ['title' => 'After', 'robots_noindex' => 0, 'og_image_width' => 1200], ['id' => $row_id]);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame($before, wpmcp_test_aioseo_rows('post', $post_id), 'same id, same columns, NULL kept NULL');
    }

    public function test_restore_removes_a_row_the_write_created(): void
    {
        $post_id  = self::factory()->post->create();
        $snapshot = Snapshot::capture('aioseo_row', 'post:' . $post_id);
        $this->assertSame([], $snapshot['data']['rows']);

        $this->insert('post', $post_id, ['title' => 'Created by the write']);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame([], wpmcp_test_aioseo_rows('post', $post_id));
    }

    public function test_restore_resurrects_a_deleted_row_at_its_id(): void
    {
        global $wpdb;
        $term_id = self::factory()->category->create();
        $this->insert('term', $term_id, ['description' => 'Term row']);
        $before   = wpmcp_test_aioseo_rows('term', $term_id);
        $snapshot = Snapshot::capture('aioseo_row', 'term:' . $term_id);

        $wpdb->delete($wpdb->prefix . 'aioseo_terms', ['term_id' => $term_id]);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame($before, wpmcp_test_aioseo_rows('term', $term_id));
    }

    public function test_restore_leaves_other_objects_rows_alone(): void
    {
        global $wpdb;
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();
        $this->insert('post', $a, ['title' => 'A before']);
        $b_row = $this->insert('post', $b, ['title' => 'B before']);

        $snapshot = Snapshot::capture('aioseo_row', 'post:' . $a);
        $wpdb->update($wpdb->prefix . 'aioseo_posts', ['title' => 'B later'], ['id' => $b_row]);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame('B later', wpmcp_test_aioseo_rows('post', $b)[0]['title']);
    }

    public function test_is_a_restorable_type(): void
    {
        $this->assertContains('aioseo_row', Rollback_Service::restorable_object_types());
    }
}
