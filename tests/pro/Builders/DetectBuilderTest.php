<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Builders\Detect_Builder;

/**
 * Builder detection reads only plain postmeta/post_content markers (no
 * Bricks or Divi plugin classes involved), so unlike the Elementor pro
 * suite these tests are never skipped: every case here is exercised in CI
 * regardless of which page-builder plugins are actually installed.
 */
class DetectBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_detects_bricks_from_page_content_meta(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_bricks_page_content_2', wp_json_encode([['id' => 'abc', 'name' => 'section']]));

        $out = (new Detect_Builder())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertSame('bricks', $out['builder']);
    }

    public function test_detects_divi_from_shortcode_and_flag(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Hi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        ]);
        update_post_meta($post_id, '_et_pb_use_builder', 'on');

        $out = (new Detect_Builder())->handle(['post_id' => $post_id]);

        $this->assertSame('divi', $out['builder']);
    }

    public function test_detects_elementor_from_edit_mode_meta(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        $out = (new Detect_Builder())->handle(['post_id' => $post_id]);

        $this->assertSame('elementor', $out['builder']);
    }

    public function test_detects_gutenberg_from_block_comment_markers(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
        ]);

        $out = (new Detect_Builder())->handle(['post_id' => $post_id]);

        $this->assertSame('gutenberg', $out['builder']);
    }

    public function test_detects_classic_for_plain_content(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '<p>Just plain HTML content, no builder markers.</p>',
        ]);

        $out = (new Detect_Builder())->handle(['post_id' => $post_id]);

        $this->assertSame('classic', $out['builder']);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        $out = (new Detect_Builder())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_post_not_found(): void
    {
        $out = (new Detect_Builder())->handle(['post_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('post_not_found', $out->get_error_code());
    }
}
