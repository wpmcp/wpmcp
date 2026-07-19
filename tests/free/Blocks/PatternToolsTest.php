<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\Insert_Pattern;
use WPMCP\Tools\Blocks\List_Patterns;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

/**
 * Pattern discovery and insertion (issue #56): list-patterns reads the
 * registered pattern registry; insert-pattern splices a pattern's parsed
 * blocks into a post at a path position, snapshot-first.
 */
class PatternToolsTest extends \WP_UnitTestCase
{
    private const PATTERN_NAME = 'wpmcp-test/hero';
    private const PATTERN_CONTENT = '<!-- wp:heading {"level":2} --><h2>Hero</h2><!-- /wp:heading -->'
        . "\n\n"
        . '<!-- wp:paragraph --><p>Tagline</p><!-- /wp:paragraph -->';

    private const PARA    = '<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->';
    private const HEADING = '<!-- wp:heading {"level":3} --><h3>two</h3><!-- /wp:heading -->';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_block_pattern(self::PATTERN_NAME, [
            'title'       => 'Test Hero',
            'description' => 'A heading plus tagline.',
            'categories'  => ['header'],
            'content'     => self::PATTERN_CONTENT,
        ]);
    }

    protected function tearDown(): void
    {
        unregister_block_pattern(self::PATTERN_NAME);
        parent::tearDown();
    }

    private function make_post(): array
    {
        $id = self::factory()->post->create(['post_content' => self::PARA . self::HEADING]);
        return [$id, hash('sha256', get_post($id)->post_content)];
    }

    public function test_list_patterns_returns_registered_patterns(): void
    {
        $out = (new List_Patterns())->handle([]);

        $this->assertArrayHasKey('patterns', $out);
        $names = array_column($out['patterns'], 'name');
        $this->assertContains(self::PATTERN_NAME, $names);

        $mine = $out['patterns'][ array_search(self::PATTERN_NAME, $names, true) ];
        $this->assertSame('Test Hero', $mine['title']);
        $this->assertSame(['header'], $mine['categories']);
        $this->assertSame('A heading plus tagline.', $mine['description']);
    }

    public function test_list_patterns_search_filters_by_name_and_title(): void
    {
        $out = (new List_Patterns())->handle(['search' => 'test hero']);

        $names = array_column($out['patterns'], 'name');
        $this->assertSame([self::PATTERN_NAME], $names);
    }

    public function test_insert_pattern_splices_parsed_blocks_at_position(): void
    {
        [$id, $h] = $this->make_post();

        $out = (new Insert_Pattern())->handle([
            'id'            => $id,
            'name'          => self::PATTERN_NAME,
            'path'          => [1],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);

        // Whitespace filler nodes inside the pattern are dropped; the two
        // real blocks land in order at position 1, neighbors byte-identical.
        $expected = self::PARA
            . '<!-- wp:heading {"level":2} --><h2>Hero</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Tagline</p><!-- /wp:paragraph -->'
            . self::HEADING;
        $this->assertSame($expected, get_post($id)->post_content);
        $this->assertSame(2, $out['inserted']);
        $this->assertArrayHasKey('operation_id', $out);
    }

    public function test_insert_pattern_with_unknown_name_returns_structured_error(): void
    {
        [$id, $h] = $this->make_post();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern/i');
        (new Insert_Pattern())->handle([
            'id'            => $id,
            'name'          => 'wpmcp-test/definitely-missing',
            'path'          => [0],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
    }

    public function test_insert_pattern_with_stale_hash_is_refused(): void
    {
        [$id, $h] = $this->make_post();
        wp_update_post(['ID' => $id, 'post_content' => self::PARA]);
        $drifted = get_post($id)->post_content;

        try {
            (new Insert_Pattern())->handle([
                'id'            => $id,
                'name'          => self::PATTERN_NAME,
                'path'          => [0],
                'expected_hash' => $h,
                'session_id'    => 's1',
            ]);
            $this->fail('Expected a stale-hash refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stale|changed/i', $e->getMessage());
        }
        $this->assertSame($drifted, get_post($id)->post_content);
    }

    public function test_insert_pattern_is_restorable_via_rollback_operation(): void
    {
        [$id, $h] = $this->make_post();
        $original = get_post($id)->post_content;

        $out = (new Insert_Pattern())->handle([
            'id'            => $id,
            'name'          => self::PATTERN_NAME,
            'path'          => [2],
            'expected_hash' => $h,
            'session_id'    => 's1',
        ]);
        $this->assertNotSame($original, get_post($id)->post_content);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($original, get_post($id)->post_content);
    }
}
