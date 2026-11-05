<?php

namespace WPMCP\Tests\Free\Search;

use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Reindex_Search;
use WPMCP\Tools\Search\Search_Content;
use WPMCP\Tools\Search\Search_Index_Store;

/**
 * Issue #592: on a site whose index held one object (written by save-time
 * indexing) the Sample Page, which existed before the plugin, was not in
 * it, and search-content answered "bike messenger" with no results,
 * empty:false and hint:null. That reads as "this text is not on the site".
 *
 * Until reindex-search has completed a pass over every indexable post the
 * index is partial, and the response says so (index.complete:false and a
 * hint naming reindex-search). After a completed unfiltered pass it does
 * not, and save-time indexing keeps it complete from there.
 */
class PartialIndexHintTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search_Index_Store::ensure_installed();
        Search_Index_Store::truncate();
    }

    /** Posts that existed before the plugin: in the database, not in the index. */
    private function pre_existing_page(string $content): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Sample Page', 'post_status' => 'publish', 'post_content' => $content]);
        Content_Indexer::purge_post($id);
        return $id;
    }

    public function test_a_partly_built_index_says_so_and_names_reindex_search(): void
    {
        $sample = $this->pre_existing_page("<!-- wp:paragraph --><p>Hi there! I'm a bike messenger by day.</p><!-- /wp:paragraph -->");
        // Edited after install: indexed on save.
        self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Pricing', 'post_status' => 'draft', 'post_content' => 'Upgrade any time.']);

        $out = (new Search_Content())->handle(['query' => 'bike messenger']);

        $this->assertSame([], $out['results']);
        $this->assertFalse($out['index']['empty']);
        $this->assertFalse($out['index']['complete'], 'An index only save-time indexing has written is partial');
        $this->assertNotNull($out['hint'], 'An empty result on a partial index must not read as "not on the site"');
        $this->assertStringContainsString('reindex-search', (string) $out['hint']);
        $this->assertStringContainsString('not', (string) $out['hint']);

        $reindex = (new Reindex_Search())->handle([]);
        $this->assertTrue($reindex['complete']);

        $after = (new Search_Content())->handle(['query' => 'bike messenger']);
        $this->assertSame($sample, $after['results'][0]['post_id'] ?? null);
        $this->assertTrue($after['index']['complete']);
        $this->assertNull($after['hint']);
    }

    public function test_a_paged_rebuild_is_complete_only_after_its_last_page(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->pre_existing_page("Paged text {$i}");
        }

        $first = (new Reindex_Search())->handle(['batch_size' => 2]);
        $this->assertFalse($first['complete']);
        $this->assertFalse((new Search_Content())->handle(['query' => 'paged'])['index']['complete']);

        (new Reindex_Search())->handle(['batch_size' => 2, 'offset' => 2]);
        $this->assertTrue((new Search_Content())->handle(['query' => 'paged'])['index']['complete']);
    }

    public function test_a_rebuild_limited_to_some_post_types_does_not_count_as_complete(): void
    {
        $this->pre_existing_page('Typed text');

        (new Reindex_Search())->handle(['post_types' => ['post']]);

        $out = (new Search_Content())->handle(['query' => 'typed']);
        $this->assertFalse($out['index']['complete']);
        $this->assertStringContainsString('reindex-search', (string) $out['hint']);
    }

    public function test_a_wiped_index_is_partial_again(): void
    {
        (new Reindex_Search())->handle([]);
        self::factory()->post->create(['post_title' => 'Saved later', 'post_status' => 'publish']);
        $this->assertTrue((new Search_Content())->handle(['query' => 'saved'])['index']['complete'], 'Save-time indexing keeps a complete index complete');

        Search_Index_Store::truncate();
        self::factory()->post->create(['post_title' => 'Saved after the wipe', 'post_status' => 'publish']);

        $this->assertFalse((new Search_Content())->handle(['query' => 'saved'])['index']['complete']);
    }
}
