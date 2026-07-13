<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\Update_Builder_Content;

/**
 * Bricks and Divi content lives in plain postmeta/post_content, so (like
 * DetectBuilderTest/GetBuilderContentTest) these tests are never skipped
 * for missing plugins - the write, read-back, and rollback are all
 * genuinely exercised here, matching UpdateElementTest's style.
 */
class UpdateBuilderContentTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_bricks_page(array $elements): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_bricks_page_content_2', wp_json_encode($elements));
        return $post_id;
    }

    private function make_divi_page(string $content): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => $content]);
        update_post_meta($post_id, '_et_pb_use_builder', 'on');
        return $post_id;
    }

    public function test_writes_bricks_content_and_reads_back(): void
    {
        $post_id = $this->make_bricks_page([['id' => 'abc', 'name' => 'section']]);
        $new_elements = [['id' => 'xyz', 'name' => 'container', 'children' => []]];

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'bricks',
            'content' => wp_json_encode($new_elements),
        ]);

        $this->assertArrayHasKey('operation_id', $out);

        $raw = json_decode(get_post_meta($post_id, '_bricks_page_content_2', true), true);
        $this->assertSame($new_elements, $raw);
    }

    public function test_writes_divi_content_and_reads_back(): void
    {
        $post_id = $this->make_divi_page('[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]');
        $new_content = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Updated[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'divi',
            'content' => $new_content,
        ]);

        $this->assertArrayHasKey('operation_id', $out);

        $post = get_post($post_id);
        $this->assertSame($new_content, $post->post_content);
        $this->assertSame('on', get_post_meta($post_id, '_et_pb_use_builder', true));
    }

    public function test_divi_write_sets_use_builder_flag_when_not_previously_set(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'plain']);

        (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'divi',
            'content' => '[et_pb_section][/et_pb_section]',
        ]);

        $this->assertSame('on', get_post_meta($post_id, '_et_pb_use_builder', true));
    }

    public function test_rollback_restores_prior_bricks_meta_byte_for_byte(): void
    {
        $post_id = $this->make_bricks_page([['id' => 'abc', 'name' => 'section']]);
        $before  = get_post_meta($post_id, '_bricks_page_content_2', true);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'bricks',
            'content' => wp_json_encode([['id' => 'new', 'name' => 'container']]),
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $after = get_post_meta($post_id, '_bricks_page_content_2', true);
        $this->assertSame($before, $after);
    }

    public function test_rollback_restores_prior_divi_post_content_byte_for_byte(): void
    {
        $original = '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]';
        $post_id  = $this->make_divi_page($original);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'divi',
            'content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][/et_pb_column][/et_pb_row][/et_pb_section]',
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $after = get_post($post_id)->post_content;
        $this->assertSame($original, $after);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        $out = (new Update_Builder_Content())->handle(['builder' => 'bricks', 'content' => '[]']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_builder_invalid(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'elementor',
            'content' => '[]',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unsupported_builder', $out->get_error_code());
    }

    public function test_returns_wp_error_for_malformed_bricks_json(): void
    {
        $post_id = $this->make_bricks_page([]);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'bricks',
            'content' => '{not valid json',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_bricks_json', $out->get_error_code());
    }

    public function test_returns_wp_error_for_bricks_json_that_is_not_an_array(): void
    {
        $post_id = $this->make_bricks_page([]);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'bricks',
            'content' => '"just a string"',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_bricks_json', $out->get_error_code());
    }

    public function test_returns_wp_error_for_non_string_divi_content(): void
    {
        $post_id = $this->make_divi_page('[et_pb_section][/et_pb_section]');

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $post_id,
            'builder' => 'divi',
            'content' => ['not' => 'a string'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_divi_content', $out->get_error_code());
    }
}
