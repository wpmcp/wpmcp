<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

require_once __DIR__ . '/../../support/aioseo-tables.php';

/**
 * The 'aioseo_row' snapshot on a site without the table: the free plugin
 * creates `aioseo_posts` only, and `aioseo_terms` ships with the paid one.
 * Capture records that the table was absent, and the restore touches
 * nothing rather than failing the rollback.
 */
class AioseoRowMissingTableTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_aioseo_tables(false);
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_aioseo_tables();
    }

    public function test_missing_table_captures_nothing_and_restores_nothing(): void
    {
        $term_id  = self::factory()->category->create();
        $snapshot = Snapshot::capture('aioseo_row', 'term:' . $term_id);

        $this->assertFalse($snapshot['data']['table_exists']);
        $this->assertSame([], $snapshot['data']['rows']);
        $this->assertNull(Snapshot::aioseo_rows('term', $term_id));

        Rollback_Service::apply_snapshot($snapshot);
        $this->assertNull(Snapshot::aioseo_rows('term', $term_id));
    }

    public function test_the_post_table_is_still_seen(): void
    {
        $post_id = self::factory()->post->create();

        $this->assertSame([], Snapshot::aioseo_rows('post', $post_id));
    }
}
