<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Redirects\Redirect_Handler;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * The front-end redirect lookup is object-cached (issue #182).
 *
 * Redirect_Handler runs on every front-end request, so its source-path lookup
 * is the one repeated read against wpmcp_redirects. It is cached under a
 * last_changed key that every Redirect_Store write bumps, so any change made
 * through the store (tools, rollback) or through the generic database tools
 * (Database_Guard::invalidate_caches) is visible on the next request. The
 * write-path lookup (find_by_source) stays a live read because it feeds
 * clash checks, snapshots and rollback.
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
}
