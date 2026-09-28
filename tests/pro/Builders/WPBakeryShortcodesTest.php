<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\WPBakery_Shortcodes;

/**
 * Parser and writer coverage for the WPBakery shortcode layout, against a
 * fixture shaped like what the WPBakery editor saves (nested rows, inner
 * rows, a tabs group, void elements, encoded attributes). Pure string work,
 * so the plugin never needs to be installed.
 */
class WPBakeryShortcodesTest extends \WP_UnitTestCase
{
    public const FIXTURE = '[vc_row full_width="stretch_row" css=".vc_custom_1600000000001{padding-top: 40px !important;}"]'
        . '[vc_column width="1/2"][vc_column_text css=".vc_custom_1600000000002{margin-bottom: 0px !important;}"]'
        . '<h2>Welcome</h2><p>Hello \\ world [gallery ids="1,2"]</p>[/vc_column_text]'
        . '[vc_single_image image="42" img_size="large" onclick="link_image"][/vc_column]'
        . '[vc_column width="1/2"][vc_row_inner][vc_column_inner width="1/1"]'
        . '[vc_btn title="Book now" color="primary" link="url:https%3A%2F%2Fexample.com%2Fbook|title:Book%20now||"]'
        . '[vc_custom_heading text="Quote: ``fast``" font_container="tag:h3|text_align:left"]'
        . '[/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]' . "\n"
        . '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One" tab_id="t1"][vc_column_text]A[/vc_column_text][/vc_tta_section]'
        . '[vc_tta_section title="Two" tab_id="t2"][/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]';

    public function test_tree_exposes_paths_attrs_children_and_text(): void
    {
        $tree = WPBakery_Shortcodes::tree(self::FIXTURE);

        $this->assertCount(2, $tree);
        $this->assertSame('0', $tree[0]['path']);
        $this->assertSame('vc_row', $tree[0]['tag']);
        $this->assertSame('stretch_row', $tree[0]['attrs']['full_width']);

        $text = $tree[0]['children'][0]['children'][0];
        $this->assertSame('0.0.0', $text['path']);
        $this->assertSame('vc_column_text', $text['tag']);
        $this->assertSame('<h2>Welcome</h2><p>Hello \\ world [gallery ids="1,2"]</p>', $text['text']);
        $this->assertArrayNotHasKey('children', $text);

        $image = $tree[0]['children'][0]['children'][1];
        $this->assertSame('vc_single_image', $image['tag']);
        $this->assertSame(['image' => '42', 'img_size' => 'large', 'onclick' => 'link_image'], $image['attrs']);
        $this->assertArrayNotHasKey('text', $image);
        $this->assertArrayNotHasKey('children', $image);

        $inner = $tree[0]['children'][1]['children'][0]['children'][0]['children'];
        $this->assertSame('0.1.0.0.1', $inner[1]['path']);
        $this->assertSame('Quote: ``fast``', $inner[1]['attrs']['text']);
        $this->assertSame('url:https%3A%2F%2Fexample.com%2Fbook|title:Book%20now||', $inner[0]['attrs']['link']);

        $tabs = $tree[1]['children'][0]['children'][0];
        $this->assertSame('vc_tta_tabs', $tabs['tag']);
        $this->assertSame('t2', $tabs['children'][1]['attrs']['tab_id']);
        $this->assertSame([], $tabs['children'][1]['children']);
    }

    public function test_tree_of_non_builder_content_is_empty(): void
    {
        $this->assertSame([], WPBakery_Shortcodes::tree('<p>Plain [[vc_row]] text</p>'));
    }

    public function test_attribute_forms_parse_like_wordpress_without_unslashing(): void
    {
        $tree = WPBakery_Shortcodes::tree("[vc_btn a='single' b=bare \"positional\" loose C=\"x\\\\y\" /]");

        $this->assertSame(['a' => 'single', 'b' => 'bare', 0 => 'positional', 1 => 'loose', 'c' => 'x\\\\y'], $tree[0]['attrs']);
    }

    public function test_custom_css_rules_are_collected_in_document_order(): void
    {
        $this->assertSame(
            [
                '.vc_custom_1600000000001{padding-top: 40px !important;}',
                '.vc_custom_1600000000002{margin-bottom: 0px !important;}',
            ],
            WPBakery_Shortcodes::custom_css_rules(self::FIXTURE)
        );
    }

    public function test_update_attrs_merges_removes_and_encodes(): void
    {
        $out = WPBakery_Shortcodes::update(self::FIXTURE, '0.0.1', ['img_size' => null, 'alignment' => 'center', 'el_class' => 'a"b[c]', 'lazy' => true], null);

        $this->assertStringContainsString('[vc_single_image image="42" onclick="link_image" alignment="center" el_class="a``b`{`c`}`" lazy="true"][/vc_column]', $out);
    }

    public function test_update_text_keeps_every_other_byte(): void
    {
        $out = WPBakery_Shortcodes::update(self::FIXTURE, '0.0.0', null, '<p>New copy</p>');

        $expected = str_replace('<h2>Welcome</h2><p>Hello \\ world [gallery ids="1,2"]</p>', '<p>New copy</p>', self::FIXTURE);
        $this->assertSame($expected, $out);
    }

    public function test_update_text_on_an_empty_container(): void
    {
        $out  = WPBakery_Shortcodes::update(self::FIXTURE, '1.0.0.1', ['title' => 'Deux'], 'Body');
        $tree = WPBakery_Shortcodes::tree($out);

        $this->assertSame('Body', $tree[1]['children'][0]['children'][0]['children'][1]['text']);
        $this->assertSame('Deux', $tree[1]['children'][0]['children'][0]['children'][1]['attrs']['title']);
    }

    public function test_update_rejects_bad_requests(): void
    {
        $cases = [
            [['0.0.0', null, null], 'Provide attrs'],
            [['0.0.1', null, 'x'], 'no closing tag'],
            [['0.0', null, 'x'], 'has child elements'],
            [['0.0.0', null, 'a [/vc_column_text] b'], 'own closing tag'],
            [['0.0.0', ['bad key' => 'x'], null], 'Attribute names'],
            [['0.0.0', ['k' => ['nested']], null], 'Attribute values'],
            [['', ['k' => 'v'], null], 'path to an element'],
            [['9', ['k' => 'v'], null], "No element at path 9"],
            [['0.x', ['k' => 'v'], null], 'Invalid path'],
        ];
        foreach ($cases as [$call, $message]) {
            try {
                WPBakery_Shortcodes::update(self::FIXTURE, ...$call);
                $this->fail('Expected rejection: ' . $message);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_add_inserts_at_index_or_appends_and_reports_path(): void
    {
        [$out, $path] = WPBakery_Shortcodes::add(self::FIXTURE, '0.0', 1, ['tag' => 'vc_separator', 'attrs' => ['color' => 'grey']]);
        $this->assertSame('0.0.1', $path);
        $this->assertStringContainsString('[/vc_column_text][vc_separator color="grey"][vc_single_image', $out);

        [$out, $path] = WPBakery_Shortcodes::add(self::FIXTURE, '', null, [
            'tag'      => 'vc_row',
            'children' => [
                ['tag' => 'vc_column', 'attrs' => ['width' => '1/1'], 'children' => [
                    ['tag' => 'vc_column_text', 'text' => '<p>Footer</p>'],
                ]],
            ],
        ]);
        $this->assertSame('2', $path);
        $this->assertSame(self::FIXTURE . '[vc_row][vc_column width="1/1"][vc_column_text]<p>Footer</p>[/vc_column_text][/vc_column][/vc_row]', $out);

        [$out] = WPBakery_Shortcodes::add(self::FIXTURE, '1.0', 0, ['tag' => 'vc_row_inner']);
        $this->assertStringContainsString('[vc_row][vc_column][vc_row_inner][/vc_row_inner][vc_tta_tabs]', $out);
    }

    public function test_add_rejects_bad_targets_and_specs(): void
    {
        $cases = [
            ['0.0.0', ['tag' => 'vc_btn'], 'cannot hold child elements'],
            ['0.0.1', ['tag' => 'vc_btn'], 'cannot hold child elements'],
            ['0', ['tag' => '1bad'], 'valid shortcode tag'],
            ['0', ['tag' => 'vc_column', 'attrs' => 'x'], 'must be an object'],
            ['0', ['tag' => 'vc_column', 'text' => 'a', 'children' => []], 'both text and children'],
            ['0', ['tag' => 'vc_column_text', 'text' => 5], 'must be a string'],
            ['0', ['tag' => 'vc_column', 'children' => 'x'], 'must be a list'],
            ['0', ['tag' => 'vc_column', 'children' => ['x']], 'element objects'],
        ];
        foreach ($cases as [$to, $element, $message]) {
            try {
                WPBakery_Shortcodes::add(self::FIXTURE, $to, null, $element);
                $this->fail('Expected rejection: ' . $message);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        WPBakery_Shortcodes::add(self::FIXTURE, '0', -1, ['tag' => 'vc_column']);
    }

    public function test_remove_cuts_exactly_one_element(): void
    {
        $out = WPBakery_Shortcodes::remove(self::FIXTURE, '0.0.1');

        $this->assertSame(str_replace('[vc_single_image image="42" img_size="large" onclick="link_image"]', '', self::FIXTURE), $out);
    }

    public function test_move_forward_backward_and_across_containers(): void
    {
        // Across containers, into a deeper column.
        $out  = WPBakery_Shortcodes::move(self::FIXTURE, '0.0.1', '0.1.0.0', 0);
        $tree = WPBakery_Shortcodes::tree($out);
        $this->assertCount(1, $tree[0]['children'][0]['children']);
        $this->assertSame('vc_single_image', $tree[0]['children'][1]['children'][0]['children'][0]['children'][0]['tag']);

        // Backward at the top level.
        $out  = WPBakery_Shortcodes::move(self::FIXTURE, '1', '', 0);
        $this->assertStringStartsWith('[vc_row][vc_column][vc_tta_tabs]', $out);
        $this->assertSame(strlen(self::FIXTURE), strlen($out));

        // Forward among siblings: final index is the position after the move.
        $out  = WPBakery_Shortcodes::move(self::FIXTURE, '0.0.0', '0.0', 1);
        $this->assertStringContainsString('[vc_column width="1/2"][vc_single_image image="42" img_size="large" onclick="link_image"][vc_column_text', $out);

        // Same place is a no-op.
        $this->assertSame(self::FIXTURE, WPBakery_Shortcodes::move(self::FIXTURE, '0.0.0', '0.0', 0));
    }

    public function test_move_rejects_moving_into_itself(): void
    {
        foreach (['0', '0.1'] as $to) {
            try {
                WPBakery_Shortcodes::move(self::FIXTURE, '0', $to, null);
                $this->fail('Expected rejection');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('into itself', $e->getMessage());
            }
        }
    }

    public function test_unclosed_opener_inside_container_is_a_void_sibling(): void
    {
        $tree = WPBakery_Shortcodes::tree('[vc_row][vc_column][vc_empty_space height="32px"][vc_column_text]x[/vc_column_text][/vc_column][/vc_row][/stray]');

        $children = $tree[0]['children'][0]['children'];
        $this->assertSame(['vc_empty_space', 'vc_column_text'], array_column($children, 'tag'));
        $this->assertSame('x', $children[1]['text']);
    }
}
