<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Redirects\Redirect_Handler;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * The front-end redirect lookup is object-cached (issue #182).
 *
 * Redirect_Handler runs on every front-end request, so its source-path lookup
 * is the one repeated read against wpmcp_redirects. The cache holds one map of
 * the enabled redirects per last_changed stamp, which every Redirect_Store
 * write bumps, so any change made through the store (tools, rollback),
 * through the generic database tools (Database_Guard::invalidate_caches) or
 * by a reinstall is visible on the next request. The write-path lookup
 * (find_by_source) stays a live read because it feeds clash checks,
 * snapshots and rollback.
 */
class RedirectLookupCacheTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        Redirect_Store::invalidate_cache();
    }

    /** Change a row behind the store's back, the way an out-of-band write would. */
    private function raw_update(int $id, array $fields): void
    {
        global $wpdb;
        $wpdb->update(Redirect_Store::table_name(), $fields, ['id' => $id]);
    }

    public function test_a_repeated_lookup_is_served_from_the_cache_without_a_query(): void
    {
        global $wpdb;
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $first  = Redirect_Store::find_by_source_cached('/old');
        $before = $wpdb->num_queries;
        $second = Redirect_Store::find_by_source_cached('/OLD/');

        $this->assertSame($first, $second);
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_a_miss_is_cached_too(): void
    {
        global $wpdb;
        $this->assertNull(Redirect_Store::find_by_source_cached('/nothing'));

        $before = $wpdb->num_queries;
        $this->assertNull(Redirect_Store::find_by_source_cached('/nothing'));
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_the_handler_uses_the_cached_lookup(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $this->assertSame('/new', (new Redirect_Handler())->resolve('/old')['target']);

        // Proves the handler reads through the cache: a write that skips the
        // store (and so skips invalidation) is not seen.
        $this->raw_update($id, ['target_url' => '/sneaky']);

        $this->assertSame('/new', (new Redirect_Handler())->resolve('/old')['target']);
    }

    public function test_the_write_path_lookup_stays_live(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        Redirect_Store::find_by_source_cached('/old');

        $this->raw_update($id, ['target_url' => '/changed']);

        $this->assertSame('/changed', Redirect_Store::find_by_source('/old')['target_url']);
    }

    public function test_insert_invalidates_a_cached_miss(): void
    {
        $this->assertNull(Redirect_Store::find_by_source_cached('/old'));

        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $this->assertSame('/new', Redirect_Store::find_by_source_cached('/old')['target_url']);
        $this->assertSame('/new', (new Redirect_Handler())->resolve('/old')['target']);
    }

    public function test_update_invalidates_the_old_and_the_new_source(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        Redirect_Store::find_by_source_cached('/old');
        $this->assertNull(Redirect_Store::find_by_source_cached('/renamed'));

        Redirect_Store::update($id, ['source_path' => '/renamed', 'target_url' => '/newer']);

        $this->assertNull(Redirect_Store::find_by_source_cached('/old'));
        $this->assertSame('/newer', Redirect_Store::find_by_source_cached('/renamed')['target_url']);
    }

    public function test_disabling_through_the_store_stops_the_redirect_on_the_next_lookup(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $this->assertNotNull((new Redirect_Handler())->resolve('/old'));

        Redirect_Store::update($id, ['enabled' => 0]);

        $this->assertNull((new Redirect_Handler())->resolve('/old'));
    }

    public function test_delete_and_delete_by_source_invalidate(): void
    {
        $a = Redirect_Store::insert(['source_path' => '/a', 'target_url' => '/x']);
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/y']);
        Redirect_Store::find_by_source_cached('/a');
        Redirect_Store::find_by_source_cached('/b');

        Redirect_Store::delete($a);
        $this->assertNull(Redirect_Store::find_by_source_cached('/a'));

        Redirect_Store::delete_by_source('/b');
        $this->assertNull(Redirect_Store::find_by_source_cached('/b'));
    }

    public function test_the_rollback_writes_invalidate(): void
    {
        $id  = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $row = Redirect_Store::get($id);

        Redirect_Store::delete($id);
        $this->assertNull(Redirect_Store::find_by_source_cached('/old'));
        Redirect_Store::insert_raw($row);
        $this->assertSame($id, Redirect_Store::find_by_source_cached('/old')['id']);

        Redirect_Store::update($id, ['target_url' => '/changed']);
        $this->assertSame('/changed', Redirect_Store::find_by_source_cached('/old')['target_url']);
        Redirect_Store::overwrite($id, $row);
        $this->assertSame('/new', Redirect_Store::find_by_source_cached('/old')['target_url']);
    }

    public function test_a_generic_database_tool_write_invalidates(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        Redirect_Store::find_by_source_cached('/old');

        // What update-rows does: a raw write, then invalidate_caches().
        $this->raw_update($id, ['target_url' => '/via-db-tool']);
        Database_Guard::invalidate_caches(Redirect_Store::table_name(), ['where' => ['id' => $id]]);

        $this->assertSame('/via-db-tool', Redirect_Store::find_by_source_cached('/old')['target_url']);
    }

    public function test_recording_a_hit_does_not_invalidate_the_lookup(): void
    {
        global $wpdb;
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        Redirect_Store::find_by_source_cached('/old');

        Redirect_Store::record_hit($id);

        $before = $wpdb->num_queries;
        Redirect_Store::find_by_source_cached('/old');
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_distinct_paths_share_one_cached_map(): void
    {
        global $wpdb;
        Redirect_Store::insert(['source_path' => '/a', 'target_url' => '/x']);
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/y']);

        Redirect_Store::find_by_source_cached('/a');
        $before = $wpdb->num_queries;

        $this->assertSame('/y', Redirect_Store::find_by_source_cached('/b')['target_url']);
        $this->assertNull(Redirect_Store::find_by_source_cached('/never-seen-before'));
        $this->assertNull(Redirect_Store::find_by_source_cached('/another-crawler-probe'));
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_an_already_normalized_path_is_looked_up_as_given(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $this->assertSame('/new', Redirect_Store::find_by_source_cached('/old', true)['target_url']);
    }

    public function test_a_disabled_redirect_is_not_in_the_cached_lookup(): void
    {
        Redirect_Store::insert(['source_path' => '/off', 'target_url' => '/new', 'enabled' => 0]);

        $this->assertNull(Redirect_Store::find_by_source_cached('/off'));
        $this->assertNotNull(Redirect_Store::find_by_source('/off'));
    }

    public function test_the_cached_row_carries_no_hit_telemetry(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new', 'status_code' => 302]);

        $row = Redirect_Store::find_by_source_cached('/old');

        $this->assertArrayNotHasKey('hits', $row);
        $this->assertArrayNotHasKey('last_hit_at', $row);
        $this->assertSame(302, $row['status_code']);
        $this->assertTrue($row['enabled']);
    }

    public function test_a_failed_lookup_query_is_not_cached_as_a_miss(): void
    {
        global $wpdb;
        $table = Redirect_Store::table_name();

        // Point the lookup's SELECT at a table that does not exist, the way a
        // transient database error or a not-yet-installed table would fail.
        $break = static function (string $query) use ($table): string {
            if (0 === stripos(ltrim($query), 'SELECT') && false !== strpos($query, $table)) {
                return str_replace($table, $table . '_missing', $query);
            }
            return $query;
        };
        add_filter('query', $break);
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->assertNull(Redirect_Store::find_by_source_cached('/old'));
        } finally {
            $wpdb->suppress_errors($suppress);
            remove_filter('query', $break);
        }

        // Written behind the store's back, so nothing bumps the stamp: only a
        // lookup that re-queries can see it.
        $wpdb->insert($table, [
            'source_path' => '/old',
            'target_url'  => '/new',
            'created_at'  => current_time('mysql', true),
            'updated_at'  => current_time('mysql', true),
        ]);

        $this->assertSame('/new', Redirect_Store::find_by_source_cached('/old')['target_url']);
    }

    public function test_install_retires_the_cached_lookup(): void
    {
        global $wpdb;
        $this->assertNull(Redirect_Store::find_by_source_cached('/old'));

        $wpdb->insert(Redirect_Store::table_name(), [
            'source_path' => '/old',
            'target_url'  => '/new',
            'created_at'  => current_time('mysql', true),
            'updated_at'  => current_time('mysql', true),
        ]);
        // The schema already matches; keep dbDelta from issuing any DDL,
        // which would implicitly commit this test's isolation transaction.
        add_filter('dbdelta_queries', '__return_empty_array');
        try {
            Redirect_Store::install();
        } finally {
            remove_filter('dbdelta_queries', '__return_empty_array');
        }

        $this->assertSame('/new', Redirect_Store::find_by_source_cached('/old')['target_url']);
    }
}
