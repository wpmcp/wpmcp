<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\Add_Block;
use WPMCP\Tools\Blocks\Duplicate_Block;
use WPMCP\Tools\Blocks\Move_Block;
use WPMCP\Tools\Blocks\Remove_Block;
use WPMCP\Tools\Blocks\Update_Block;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

/**
 * Surgical per-block editing (issue #56): every mutation targets one block
 * by path (an array of zero-based indexes into the parse-blocks tree,
 * descending through innerBlocks), requires the caller to prove freshness
 * via expected_hash (sha256 of post_content as returned by parse-blocks),
 * and must never corrupt the bytes of any untouched block.
 */
class SurgicalBlockEditsTest extends \WP_UnitTestCase
{
    private const PARA    = '<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->';
    private const HEADING = '<!-- wp:heading {"level":3} --><h3>two</h3><!-- /wp:heading -->';
    private const GROUP   = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>inner</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function make_post(?string $content = null): array
    {
        $content ??= self::PARA . self::HEADING . self::GROUP;
        $id = self::factory()->post->create(['post_content' => $content]);
        return [$id, hash('sha256', get_post($id)->post_content)];
    }

    // ---------------------------------------------------------------
    // update-block
    // ---------------------------------------------------------------

    public function test_update_block_attrs_and_inner_html_by_path(): void
    {
        [$id, $h] = $this->make_post();

        $out = (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [1],
            'attrs'         => ['level' => 2],
            'inner_html'    => '<h2>two</h2>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $expected = self::PARA
            . '<!-- wp:heading {"level":2} --><h2>two</h2><!-- /wp:heading -->'
            . self::GROUP;
        $this->assertSame($expected, get_post($id)->post_content);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame(hash('sha256', $expected), $out['content_hash']);
    }

    public function test_update_leaves_untouched_blocks_byte_identical(): void
    {
        [$id, $h] = $this->make_post();

        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [1],
            'inner_html'    => '<h3>renamed</h3>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $content = get_post($id)->post_content;
        $this->assertStringStartsWith(self::PARA, $content);
        $this->assertStringEndsWith(self::GROUP, $content);
    }

    public function test_update_nested_block_by_path(): void
    {
        [$id, $h] = $this->make_post();

        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [2, 0],
            'inner_html'    => '<p>changed</p>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $expected = self::PARA . self::HEADING
            . '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>changed</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';
        $this->assertSame($expected, get_post($id)->post_content);
    }

    public function test_update_inner_html_refused_on_a_block_with_inner_blocks(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inner_html/');
        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [2],
            'inner_html'    => '<div>flat</div>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_update_requires_attrs_or_inner_html(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    // ---------------------------------------------------------------
    // add-block
    // ---------------------------------------------------------------

    public function test_add_block_at_top_level_position(): void
    {
        [$id, $h] = $this->make_post();
        $new = '<!-- wp:paragraph --><p>added</p><!-- /wp:paragraph -->';

        $out = (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [1],
            'markup'        => $new,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(self::PARA . $new . self::HEADING . self::GROUP, get_post($id)->post_content);
        $this->assertArrayHasKey('operation_id', $out);
    }

    public function test_add_block_appended_at_end(): void
    {
        [$id, $h] = $this->make_post();
        $new = '<!-- wp:paragraph --><p>tail</p><!-- /wp:paragraph -->';

        (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [3],
            'markup'        => $new,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(self::PARA . self::HEADING . self::GROUP . $new, get_post($id)->post_content);
    }

    public function test_add_block_nested_inside_a_container(): void
    {
        [$id, $h] = $this->make_post();
        $new = '<!-- wp:paragraph --><p>second inner</p><!-- /wp:paragraph -->';

        (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [2, 1],
            'markup'        => $new,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $expected = self::PARA . self::HEADING
            . '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>inner</p><!-- /wp:paragraph -->'
            . $new
            . '</div><!-- /wp:group -->';
        $this->assertSame($expected, get_post($id)->post_content);
    }

    public function test_add_block_into_container_without_inner_blocks_is_refused(): void
    {
        $empty_group = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';
        [$id, $h] = $this->make_post($empty_group);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inner blocks/');
        (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [0, 0],
            'markup'        => self::PARA,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_add_block_markup_must_be_exactly_one_block(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [0],
            'markup'        => self::PARA . self::HEADING,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    // ---------------------------------------------------------------
    // remove-block
    // ---------------------------------------------------------------

    public function test_remove_block_at_top_level(): void
    {
        [$id, $h] = $this->make_post();

        (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [1],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(self::PARA . self::GROUP, get_post($id)->post_content);
    }

    public function test_remove_nested_block(): void
    {
        [$id, $h] = $this->make_post();

        (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [2, 0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $expected = self::PARA . self::HEADING
            . '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';
        $this->assertSame($expected, get_post($id)->post_content);
    }

    // ---------------------------------------------------------------
    // move-block
    // ---------------------------------------------------------------

    public function test_move_block_within_its_parent(): void
    {
        [$id, $h] = $this->make_post();

        (new Move_Block())->handle([
            'id'            => $id,
            'from_path'     => [0],
            'to_index'      => 1,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(self::HEADING . self::PARA . self::GROUP, get_post($id)->post_content);
    }

    public function test_move_block_to_out_of_range_index_is_refused(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        (new Move_Block())->handle([
            'id'            => $id,
            'from_path'     => [0],
            'to_index'      => 9,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    // ---------------------------------------------------------------
    // duplicate-block
    // ---------------------------------------------------------------

    public function test_duplicate_block_inserts_copy_immediately_after(): void
    {
        [$id, $h] = $this->make_post();

        $out = (new Duplicate_Block())->handle([
            'id'            => $id,
            'path'          => [0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(self::PARA . self::PARA . self::HEADING . self::GROUP, get_post($id)->post_content);
        $this->assertSame([1], $out['new_path']);
    }

    public function test_duplicate_nested_block(): void
    {
        [$id, $h] = $this->make_post();

        (new Duplicate_Block())->handle([
            'id'            => $id,
            'path'          => [2, 0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $inner = '<!-- wp:paragraph --><p>inner</p><!-- /wp:paragraph -->';
        $expected = self::PARA . self::HEADING
            . '<!-- wp:group --><div class="wp-block-group">' . $inner . $inner . '</div><!-- /wp:group -->';
        $this->assertSame($expected, get_post($id)->post_content);
    }

    // ---------------------------------------------------------------
    // Deeply nested trees (three levels)
    // ---------------------------------------------------------------

    private function deep_markup(): string
    {
        return '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';
    }

    public function test_add_block_at_third_nesting_level(): void
    {
        [$id, $h] = $this->make_post($this->deep_markup());
        $new = '<!-- wp:paragraph --><p>c</p><!-- /wp:paragraph -->';

        (new Add_Block())->handle([
            'id'            => $id,
            'path'          => [0, 0, 2],
            'markup'        => $new,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(
            str_replace('<p>b</p><!-- /wp:paragraph -->', '<p>b</p><!-- /wp:paragraph -->' . $new, $this->deep_markup()),
            get_post($id)->post_content
        );
    }

    public function test_remove_block_at_third_nesting_level(): void
    {
        [$id, $h] = $this->make_post($this->deep_markup());

        (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [0, 0, 0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(
            str_replace('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->', '', $this->deep_markup()),
            get_post($id)->post_content
        );
    }

    public function test_move_block_at_third_nesting_level(): void
    {
        [$id, $h] = $this->make_post($this->deep_markup());

        (new Move_Block())->handle([
            'id'            => $id,
            'from_path'     => [0, 0, 0],
            'to_index'      => 1,
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(
            str_replace(
                '<p>a</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>b</p>',
                '<p>b</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>a</p>',
                $this->deep_markup()
            ),
            get_post($id)->post_content
        );
    }

    public function test_update_block_at_third_nesting_level(): void
    {
        [$id, $h] = $this->make_post($this->deep_markup());

        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [0, 0, 1],
            'inner_html'    => '<p>B2</p>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(
            str_replace('<p>b</p>', '<p>B2</p>', $this->deep_markup()),
            get_post($id)->post_content
        );
    }

    // ---------------------------------------------------------------
    // Freshness: stale or missing expected_hash
    // ---------------------------------------------------------------

    public function test_stale_expected_hash_is_refused_with_no_partial_write(): void
    {
        [$id, $h] = $this->make_post();

        // Content changes between the agent's read and its write.
        wp_update_post(['ID' => $id, 'post_content' => self::PARA . self::HEADING]);
        $drifted = get_post($id)->post_content;

        try {
            (new Update_Block())->handle([
                'id'            => $id,
                'path'          => [0],
                'inner_html'    => '<p>clobber</p>',
                'expected_hash' => $h,
                'session_id'    => 's1',
            ]);
            $this->fail('Expected a stale-hash refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stale|changed/i', $e->getMessage());
        }
        $this->assertSame($drifted, get_post($id)->post_content);
    }

    public function test_missing_expected_hash_is_refused(): void
    {
        [$id] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected_hash/');
        (new Remove_Block())->handle([
            'id'         => $id,
            'path'       => [0],
            'session_id' => 's1',
        ]);
    }

    // ---------------------------------------------------------------
    // Structured errors on bad targets, never fatals
    // ---------------------------------------------------------------

    public function test_out_of_range_path_returns_structured_error(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/path/');
        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [99],
            'attrs'         => ['x' => 1],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_path_descending_into_a_leaf_returns_structured_error(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/path/');
        (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [0, 0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_empty_path_returns_structured_error(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_unknown_post_returns_structured_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Block())->handle([
            'id'            => 999999999,
            'path'          => [0],
            'attrs'         => ['x' => 1],
            'expected_hash' => str_repeat('0', 64),
            'session_id'    => 's1',
        ]);
    }

    // ---------------------------------------------------------------
    // Round-trip integrity guard
    // ---------------------------------------------------------------

    public function test_content_that_does_not_round_trip_cleanly_is_refused(): void
    {
        // Nonstandard spacing inside the attrs JSON re-serializes differently,
        // so a surgical edit would silently rewrite this block's bytes.
        // Written straight to the DB (as an importer or an unfiltered_html
        // author would): wp_insert_post's kses pass normalizes the comment
        // and would defeat the fixture.
        $odd = '<!-- wp:heading {"level" : 3} --><h3>x</h3><!-- /wp:heading -->';
        [$id] = $this->make_post();
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => self::PARA . $odd], ['ID' => $id]);
        clean_post_cache($id);
        $h = hash('sha256', get_post($id)->post_content);

        try {
            (new Update_Block())->handle([
                'id'            => $id,
                'path'          => [0],
                'inner_html'    => '<p>touched</p>',
                'expected_hash' => $h,
                'session_id'    => 's1',
            ]);
            $this->fail('Expected a round-trip refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/round-trip/i', $e->getMessage());
        }
        $this->assertSame(self::PARA . $odd, get_post($id)->post_content);
    }

    public function test_whitespace_between_blocks_survives_a_surgical_edit(): void
    {
        // Gutenberg separates sibling blocks with "\n\n" freeform filler
        // nodes; those count as path positions and must survive untouched.
        [$id, $h] = $this->make_post(self::PARA . "\n\n" . self::HEADING);

        // Path [2]: 0 = paragraph, 1 = filler, 2 = heading.
        (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [2],
            'inner_html'    => '<h3>renamed</h3>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        $this->assertSame(
            self::PARA . "\n\n" . '<!-- wp:heading {"level":3} --><h3>renamed</h3><!-- /wp:heading -->',
            get_post($id)->post_content
        );
    }

    // ---------------------------------------------------------------
    // Safety: snapshot-first, restorable via rollback-operation
    // ---------------------------------------------------------------

    public function test_surgical_edit_is_restorable_via_rollback_operation(): void
    {
        [$id, $h] = $this->make_post();
        $original = get_post($id)->post_content;

        $out = (new Update_Block())->handle([
            'id'            => $id,
            'path'          => [0],
            'inner_html'    => '<p>rewritten</p>',
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
        $this->assertNotSame($original, get_post($id)->post_content);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($original, get_post($id)->post_content);
    }

    public function test_remove_block_is_restorable_via_rollback_operation(): void
    {
        [$id, $h] = $this->make_post();
        $original = get_post($id)->post_content;

        $out = (new Remove_Block())->handle([
            'id'            => $id,
            'path'          => [2],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($original, get_post($id)->post_content);
    }
}
