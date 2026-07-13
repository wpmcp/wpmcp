<?php

namespace WPMCP\Tests\Free\Linking;

use WPMCP\Tools\Linking\Suggest_Internal_Links;

class SuggestInternalLinksTest extends \WP_UnitTestCase
{
    /** @var int[] */
    private array $post_ids = [];

    public function tearDown(): void
    {
        foreach ($this->post_ids as $id) {
            wp_delete_post($id, true);
        }
        $this->post_ids = [];
        parent::tearDown();
    }

    public function test_suggests_post_sharing_a_category(): void
    {
        $cat = self::factory()->category->create(['name' => 'Travel']);
        $source  = self::factory()->post->create(['post_title' => 'Trip Guide', 'post_status' => 'publish', 'post_category' => [$cat]]);
        $related = self::factory()->post->create(['post_title' => 'Packing Tips', 'post_status' => 'publish', 'post_category' => [$cat]]);
        $this->post_ids = [$source, $related];

        $out = (new Suggest_Internal_Links())->handle(['post_id' => $source]);

        $ids = array_column($out['suggestions'], 'id');
        $this->assertContains($related, $ids, 'A post sharing a category should be suggested');
        $suggestion = $out['suggestions'][array_search($related, $ids, true)];
        $this->assertSame('shared_terms', $suggestion['reason']);
    }

    public function test_excludes_already_linked_posts(): void
    {
        $cat = self::factory()->category->create(['name' => 'News']);
        $related = self::factory()->post->create(['post_title' => 'Already Linked', 'post_status' => 'publish', 'post_category' => [$cat]]);
        $source  = self::factory()->post->create([
            'post_title'    => 'Source',
            'post_status'   => 'publish',
            'post_category' => [$cat],
            'post_content'  => '<a href="' . get_permalink($related) . '">go</a>',
        ]);
        $this->post_ids = [$source, $related];

        $out = (new Suggest_Internal_Links())->handle(['post_id' => $source]);

        $ids = array_column($out['suggestions'], 'id');
        $this->assertNotContains($related, $ids, 'A post already linked from the source is excluded');
    }

    public function test_excludes_self(): void
    {
        $cat = self::factory()->category->create(['name' => 'Solo']);
        $source = self::factory()->post->create(['post_status' => 'publish', 'post_category' => [$cat]]);
        $this->post_ids = [$source];

        $out = (new Suggest_Internal_Links())->handle(['post_id' => $source]);

        $ids = array_column($out['suggestions'], 'id');
        $this->assertNotContains($source, $ids);
    }

    public function test_ranks_keyword_overlap_when_no_shared_terms(): void
    {
        $c1 = self::factory()->category->create(['name' => 'One']);
        $c2 = self::factory()->category->create(['name' => 'Two']);
        $c3 = self::factory()->category->create(['name' => 'Three']);
        $source  = self::factory()->post->create(['post_title' => 'Grand Rapids Brewery Tour', 'post_status' => 'publish', 'post_category' => [$c1]]);
        $related = self::factory()->post->create(['post_title' => 'Best Rapids Brewery Beers', 'post_status' => 'publish', 'post_category' => [$c2]]);
        $noise   = self::factory()->post->create(['post_title' => 'Completely Unrelated', 'post_status' => 'publish', 'post_category' => [$c3]]);
        $this->post_ids = [$source, $related, $noise];

        $out = (new Suggest_Internal_Links())->handle(['post_id' => $source]);

        $ids = array_column($out['suggestions'], 'id');
        $this->assertContains($related, $ids, 'Title keyword overlap should surface a suggestion');
        $suggestion = $out['suggestions'][array_search($related, $ids, true)];
        $this->assertSame('keyword', $suggestion['reason']);
    }

    public function test_invalid_post_id_returns_error(): void
    {
        $out = (new Suggest_Internal_Links())->handle(['post_id' => 99999999]);

        $this->assertArrayHasKey('error', $out);
    }
}
