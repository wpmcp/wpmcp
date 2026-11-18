<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Add_Container;
use WPMCP\Tools\Rollback_Operation;

/**
 * add-container (issue #58): create container/section/column elements
 * top-level or nested, with optional position, hash-guarded and
 * snapshot-first like every structural op.
 */
class AddContainerTest extends Structural_Harness
{
    public function test_creates_top_level_container(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'settings'      => ['flex_direction' => 'row'],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertArrayHasKey('element_id', $out);
        $this->assertSame($this->data_hash($post_id), $out['data_hash']);

        $tree = $this->tree($post_id);
        $this->assertCount(3, $tree);
        $created = $tree[2];
        $this->assertSame($out['element_id'], $created['id']);
        $this->assertSame('container', $created['elType']);
        $this->assertSame('row', $created['settings']['flex_direction']);
        $this->assert_builder_clean($post_id);
    }

    public function test_creates_nested_container_inside_parent(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont002',
        ]);

        $this->assertIsArray($out);
        $parent = $this->find_in($this->tree($post_id), 'cont002');
        $ids    = array_column($parent['elements'], 'id');
        $this->assertContains($out['element_id'], $ids);

        $nested = $this->find_in($this->tree($post_id), $out['element_id']);
        $this->assertSame('container', $nested['elType']);
        $this->assertTrue((bool) ($nested['isInner'] ?? false), 'A nested container must be marked isInner.');
        $this->assert_builder_clean($post_id);
    }

    public function test_inserts_at_position_among_siblings(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'position'      => 0,
        ]);

        $tree = $this->tree($post_id);
        $this->assertSame($out['element_id'], $tree[0]['id']);
        $this->assertSame('cont001', $tree[1]['id']);
        $this->assertSame('cont002', $tree[2]['id']);
    }

    public function test_creates_section_and_column(): void
    {
        $post_id = $this->make_page();

        $section = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'el_type'       => 'section',
        ]);
        $this->assertIsArray($section);

        $column = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'el_type'       => 'column',
            'parent_id'     => $section['element_id'],
        ]);
        $this->assertIsArray($column);

        $tree = $this->tree($post_id);
        $this->assertSame('section', $this->find_in($tree, $section['element_id'])['elType']);
        $this->assertSame('column', $this->find_in($tree, $column['element_id'])['elType']);
        $this->assert_builder_clean($post_id);
    }

    public function test_refuses_top_level_column(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'el_type'       => 'column',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('column_requires_parent', $out->get_error_code());
    }

    public function test_refuses_unknown_el_type(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'el_type'       => 'widget',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_el_type', $out->get_error_code());
    }

    public function test_refuses_widget_parent(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'wid0001',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_parent', $out->get_error_code());
    }

    public function test_refuses_unknown_parent(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'no-such',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('parent_not_found', $out->get_error_code());
    }

    public function test_stale_hash_refusal_writes_nothing(): void
    {
        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', 'stale'),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_rollback_operation_restores_pre_add_state(): void
    {
        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertNotSame($before, $this->raw($post_id));
        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_works_on_a_page_with_no_elementor_data_yet(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', ''),
        ]);

        $this->assertIsArray($out);
        $tree = $this->tree($post_id);
        $this->assertCount(1, $tree);
        $this->assertSame('container', $tree[0]['elType']);
    }
}
