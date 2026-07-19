<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\Parse_Blocks;

class ParseBlocksTest extends \WP_UnitTestCase
{
    private array $created_posts = [];

    protected function tearDown(): void
    {
        foreach ($this->created_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        $this->created_posts = [];
        parent::tearDown();
    }

    public function test_parses_raw_block_markup(): void
    {
        $markup = '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->';

        $out = (new Parse_Blocks())->handle(['blocks' => $markup]);

        $this->assertArrayHasKey('blocks', $out);
        $this->assertCount(1, $out['blocks']);
        $this->assertSame('core/paragraph', $out['blocks'][0]['blockName']);
    }

    public function test_parses_a_post_id_content(): void
    {
        $markup  = '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->';
        $post_id = self::factory()->post->create(['post_content' => $markup]);
        $this->created_posts[] = $post_id;

        $out = (new Parse_Blocks())->handle(['id' => $post_id]);

        $this->assertCount(1, $out['blocks']);
        $this->assertSame('core/heading', $out['blocks'][0]['blockName']);
    }

    public function test_recursively_reports_inner_blocks_and_attrs(): void
    {
        $markup = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>nested</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';

        $out = (new Parse_Blocks())->handle(['blocks' => $markup]);

        $group = $out['blocks'][0];
        $this->assertSame('core/group', $group['blockName']);
        $this->assertArrayHasKey('attrs', $group);
        $this->assertArrayHasKey('innerBlocks', $group);
        $this->assertCount(1, $group['innerBlocks']);
        $this->assertSame('core/paragraph', $group['innerBlocks'][0]['blockName']);
    }

    public function test_reports_inner_html_summary(): void
    {
        $markup = '<!-- wp:paragraph --><p>hi there</p><!-- /wp:paragraph -->';

        $out = (new Parse_Blocks())->handle(['blocks' => $markup]);

        $this->assertArrayHasKey('innerHTML', $out['blocks'][0]);
        $this->assertStringContainsString('hi there', $out['blocks'][0]['innerHTML']);
    }

    public function test_returns_content_hash_for_freshness_checks(): void
    {
        $markup  = '<!-- wp:paragraph --><p>hashed</p><!-- /wp:paragraph -->';
        $post_id = self::factory()->post->create(['post_content' => $markup]);
        $this->created_posts[] = $post_id;

        $out = (new Parse_Blocks())->handle(['id' => $post_id]);

        $this->assertSame(hash('sha256', get_post($post_id)->post_content), $out['content_hash']);

        $raw = (new Parse_Blocks())->handle(['blocks' => $markup]);
        $this->assertSame(hash('sha256', $markup), $raw['content_hash']);
    }

    public function test_throws_when_neither_id_nor_blocks_given(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Parse_Blocks())->handle([]);
    }

    public function test_throws_for_an_unknown_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Parse_Blocks())->handle(['id' => 999999999]);
    }
}
