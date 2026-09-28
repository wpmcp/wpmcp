<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Find_Element;

/**
 * find-element (issue #58): read-only search over a page's element tree by
 * element type, widget type, setting value, and CSS class (AND-combined).
 * Returns each match's id, types, label, and ancestor path, plus the
 * current data_hash so a mutation can be chained without a second read.
 */
class FindElementTest extends Structural_Harness
{
    public function test_finds_by_element_type(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle(['post_id' => $post_id, 'el_type' => 'container']);

        $this->assertIsArray($out);
        $this->assertSame(2, $out['match_count']);
        $this->assertSame(['cont001', 'cont002'], array_column($out['matches'], 'element_id'));
    }

    public function test_finds_by_widget_type_with_ancestor_path(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle(['post_id' => $post_id, 'widget_type' => 'button']);

        $this->assertSame(1, $out['match_count']);
        $match = $out['matches'][0];
        $this->assertSame('wid0003', $match['element_id']);
        $this->assertSame('widget', $match['el_type']);
        $this->assertSame('button', $match['widget_type']);
        $this->assertSame(['cont002'], $match['path'], 'Path lists ancestor ids from root to parent.');
    }

    public function test_finds_by_setting_value(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle([
            'post_id'       => $post_id,
            'setting_key'   => 'title',
            'setting_value' => 'Hello',
        ]);

        $this->assertSame(1, $out['match_count']);
        $this->assertSame('wid0001', $out['matches'][0]['element_id']);
    }

    public function test_finds_by_css_class_token_on_both_class_settings(): void
    {
        $post_id = $this->make_page();

        // `css_classes` (containers/sections) - token match, not substring.
        $container = (new Find_Element())->handle(['post_id' => $post_id, 'css_class' => 'hero']);
        $this->assertSame(1, $container['match_count']);
        $this->assertSame('cont001', $container['matches'][0]['element_id']);

        // `_css_classes` (widget advanced tab).
        $widget = (new Find_Element())->handle(['post_id' => $post_id, 'css_class' => 'headline']);
        $this->assertSame(1, $widget['match_count']);
        $this->assertSame('wid0001', $widget['matches'][0]['element_id']);

        // 'her' is a substring of 'hero' but not a class token.
        $substring = (new Find_Element())->handle(['post_id' => $post_id, 'css_class' => 'her']);
        $this->assertSame(0, $substring['match_count']);
    }

    public function test_combines_criteria_with_and(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle([
            'post_id'     => $post_id,
            'el_type'     => 'widget',
            'widget_type' => 'heading',
            'css_class'   => 'headline',
        ]);
        $this->assertSame(1, $out['match_count']);
        $this->assertSame('wid0001', $out['matches'][0]['element_id']);

        $none = (new Find_Element())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'css_class'   => 'hero',
        ]);
        $this->assertSame(0, $none['match_count']);
    }

    public function test_returns_current_data_hash_for_chaining(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle(['post_id' => $post_id, 'el_type' => 'widget']);

        $this->assertSame($this->data_hash($post_id), $out['data_hash']);
    }

    public function test_reports_element_labels(): void
    {
        $tree = $this->default_tree();
        $tree[0]['settings']['_title'] = 'Hero';
        $post_id = $this->make_page($tree);

        $out = (new Find_Element())->handle(['post_id' => $post_id, 'el_type' => 'container']);

        $this->assertSame('Hero', $out['matches'][0]['label']);
        $this->assertNull($out['matches'][1]['label']);
    }

    public function test_refuses_when_no_criteria_given(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle(['post_id' => $post_id]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_criteria', $out->get_error_code());
    }

    public function test_setting_value_requires_setting_key(): void
    {
        $post_id = $this->make_page();

        $out = (new Find_Element())->handle(['post_id' => $post_id, 'setting_value' => 'Hello']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_criteria', $out->get_error_code());
    }
}
