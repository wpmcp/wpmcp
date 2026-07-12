<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Set_Post_Terms;
use WPMCP\Safety\Snapshot_Store;

class SetPostTermsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_replace_mode_overwrites_existing_terms(): void
    {
        $id       = self::factory()->post->create();
        $original = self::factory()->category->create();
        $term_a   = self::factory()->category->create();
        $term_b   = self::factory()->category->create();
        wp_set_object_terms($id, [$original], 'category');

        $out = (new Set_Post_Terms())->handle([
            'post_id'    => $id,
            'taxonomy'   => 'category',
            'terms'      => [$term_a, $term_b],
            'mode'       => 'replace',
            'session_id' => 's1',
        ]);

        $this->assertSame('category', $out['taxonomy']);
        $assigned = wp_get_post_terms($id, 'category', ['fields' => 'ids']);
        sort($assigned);
        $expected = [$term_a, $term_b];
        sort($expected);
        $this->assertSame($expected, $assigned);
    }

    public function test_append_mode_keeps_existing_terms(): void
    {
        $id       = self::factory()->post->create();
        $existing = self::factory()->tag->create();
        $new_term = self::factory()->tag->create();
        wp_set_object_terms($id, [$existing], 'post_tag');

        (new Set_Post_Terms())->handle([
            'post_id'  => $id,
            'taxonomy' => 'post_tag',
            'terms'    => [$new_term],
            'mode'     => 'append',
        ]);

        $assigned = wp_get_post_terms($id, 'post_tag', ['fields' => 'ids']);
        sort($assigned);
        $expected = [$existing, $new_term];
        sort($expected);
        $this->assertSame($expected, $assigned);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Post_Terms())->handle(['post_id' => 999999, 'taxonomy' => 'category', 'terms' => [1]]);
    }
}
