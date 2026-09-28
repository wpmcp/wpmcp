<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\{Avada_Shortcodes, WPBakery_Shortcodes};

/**
 * The Avada (Fusion Builder) dialect of the shared shortcode layout parser.
 * The fixture follows what the Avada builder saves, taken from public page
 * exports: a fusion_builder_container > fusion_builder_row >
 * fusion_builder_column tree with a nested row_inner / column_inner, text
 * bodies on fusion_text, fusion_title, fusion_imageframe and fusion_button,
 * empty elements written self-closed as `[tag ... /]`, a base64
 * dynamic_params attribute, a hyphenated attribute name, a column carrying
 * the same attribute twice (older Avada saves wrote type="1_1" type="1_1"),
 * a parent element (fusion_checklist) mixing closed and self-closed
 * children, base64 fusion_code, and a shortcode Avada does not ship.
 */
class AvadaShortcodesTest extends \WP_UnitTestCase
{
    public const COLUMN_A = '[fusion_builder_column type="1_2" type="1_2" layout="1_2" spacing="" center_content="no" hide_on_mobile="small-visibility,medium-visibility,large-visibility" first="true" last="false"]';

    public const SEPARATOR = '[fusion_separator style_type="default" flex_grow="0" hide_on_mobile="small-visibility,medium-visibility,large-visibility" alignment="center" /]';

    public const UNKNOWN = "[acme_widget mode=bare label='single quoted' \"positional\" /]";

    public const FIXTURE = '[fusion_builder_container type="flex" hundred_percent="no" equal_height_columns="no" hide_on_mobile="small-visibility,medium-visibility,large-visibility" status="published" background_position="center center" border_style="solid" padding_top="40px"]'
        . '[fusion_builder_row]' . self::COLUMN_A
        . '[fusion_title title_type="text" size="2" content_align="left" style_type="default" text_color="var(--awb-color8)"]Welcome[/fusion_title]'
        . '[fusion_text columns="" rule_style="default" animation_direction="left" animation_speed="0.3"]' . "\n"
        . '<p>Hello \\ world [gallery ids="1,2"]</p>' . "\n" . '[/fusion_text]'
        . '[fusion_imageframe image_id="42|full" lightbox="no" linktarget="_self" align="none" alt="Hero"]https://example.com/wp-content/uploads/hero.jpg[/fusion_imageframe]'
        . self::SEPARATOR
        . '[/fusion_builder_column]'
        . '[fusion_builder_column type="1_2" layout="1_2" first="false" last="true"]'
        . '[fusion_builder_row_inner][fusion_builder_column_inner type="1_1" layout="1_1" first="true" last="true"]'
        . '[fusion_button link="https://example.com/book" target="_self" color="default" stretch="default" awb-switch-editor-focus=""]Book now[/fusion_button]'
        . '[fusion_checklist type="icons" circlecolor="var(--awb-color8)" size="16"]'
        . '[fusion_li_item icon="fa-check fas"]<p>Fast</p>[/fusion_li_item]'
        . '[fusion_li_item icon="" dynamic_params="eyJlbGVtZW50X2NvbnRlbnQiOnsiZGF0YSI6InBvc3RfdGl0bGUifX0=" /]'
        . '[/fusion_checklist]'
        . '[/fusion_builder_column_inner][/fusion_builder_row_inner][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' . "\n"
        . '[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"]'
        . '[fusion_tabs design="classic" layout="horizontal"][fusion_tab title="One" icon=""]A[/fusion_tab][fusion_tab title="Two" icon=""][/fusion_tab][/fusion_tabs]'
        . '[fusion_code]PHAgY2xhc3M9ImEiPkhpPC9wPg==[/fusion_code]'
        . self::UNKNOWN
        . '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

    public function test_avada_reuses_the_shared_shortcode_parser(): void
    {
        $this->assertTrue(is_subclass_of(Avada_Shortcodes::class, WPBakery_Shortcodes::class));
    }

    public function test_tree_exposes_containers_rows_columns_and_elements(): void
    {
        $tree = Avada_Shortcodes::tree(self::FIXTURE);

        $this->assertCount(2, $tree);
        $this->assertSame('fusion_builder_container', $tree[0]['tag']);
        $this->assertSame('flex', $tree[0]['attrs']['type']);
        $this->assertSame('fusion_builder_row', $tree[0]['children'][0]['tag']);

        $column = $tree[0]['children'][0]['children'][0];
        $this->assertSame('0.0.0', $column['path']);
        $this->assertSame('1_2', $column['attrs']['type']);
        $this->assertSame(['fusion_title', 'fusion_text', 'fusion_imageframe', 'fusion_separator'], array_column($column['children'], 'tag'));

        $this->assertSame('Welcome', $column['children'][0]['text']);
        $this->assertSame("\n<p>Hello \\ world [gallery ids=\"1,2\"]</p>\n", $column['children'][1]['text']);
        $this->assertSame('https://example.com/wp-content/uploads/hero.jpg', $column['children'][2]['text']);

        $separator = $column['children'][3];
        $this->assertSame('0.0.0.3', $separator['path']);
        $this->assertSame('center', $separator['attrs']['alignment']);
        $this->assertArrayNotHasKey('text', $separator);
        $this->assertArrayNotHasKey('children', $separator);

        $inner = $tree[0]['children'][0]['children'][1]['children'][0]['children'][0]['children'];
        $this->assertSame('0.0.1.0.0.1', $inner[1]['path']);
        $this->assertSame('', $inner[0]['attrs']['awb-switch-editor-focus']);
        $this->assertSame(['fusion_li_item', 'fusion_li_item'], array_column($inner[1]['children'], 'tag'));
        $this->assertSame('<p>Fast</p>', $inner[1]['children'][0]['text']);
        $this->assertSame('eyJlbGVtZW50X2NvbnRlbnQiOnsiZGF0YSI6InBvc3RfdGl0bGUifX0=', $inner[1]['children'][1]['attrs']['dynamic_params']);

        $elements = $tree[1]['children'][0]['children'][0]['children'];
        $this->assertSame(['fusion_tabs', 'fusion_code', 'acme_widget'], array_column($elements, 'tag'));
        $this->assertSame('PHAgY2xhc3M9ImEiPkhpPC9wPg==', $elements[1]['text']);
        $this->assertSame(['mode' => 'bare', 'label' => 'single quoted', 0 => 'positional'], $elements[2]['attrs']);
    }

    public function test_update_keeps_every_byte_outside_the_element_and_its_self_closing_form(): void
    {
        $out = Avada_Shortcodes::update(self::FIXTURE, '0.0.0.3', ['alignment' => 'left'], null);

        $this->assertSame(
            str_replace(self::SEPARATOR, '[fusion_separator style_type="default" flex_grow="0" hide_on_mobile="small-visibility,medium-visibility,large-visibility" alignment="left" /]', self::FIXTURE),
            $out
        );
    }

    public function test_update_text_and_attrs_encode_what_would_break_the_shortcode(): void
    {
        $out = Avada_Shortcodes::update(self::FIXTURE, '0.0.0.0', ['size' => null, 'title_link' => 'a"b[c]', 'animate' => true], 'Hello again');

        $this->assertStringContainsString('[fusion_title title_type="text" content_align="left" style_type="default" text_color="var(--awb-color8)" title_link="a&quot;b&#91;c&#93;" animate="true"]Hello again[/fusion_title]', $out);
        $this->assertStringStartsWith(substr(self::FIXTURE, 0, strpos(self::FIXTURE, '[fusion_title')), $out);
        $this->assertStringEndsWith(substr(self::FIXTURE, strpos(self::FIXTURE, '[fusion_text')), $out);
    }

    public function test_untouched_unknown_shortcodes_and_duplicate_attributes_keep_their_bytes(): void
    {
        $out = Avada_Shortcodes::update(self::FIXTURE, '1.0.0.0.0', ['title' => 'Uno'], null);

        $this->assertSame(str_replace('[fusion_tab title="One" icon=""]', '[fusion_tab title="Uno" icon=""]', self::FIXTURE), $out);
        $this->assertStringContainsString(self::UNKNOWN, $out);
        $this->assertStringContainsString(self::COLUMN_A, $out);
    }

    public function test_add_writes_empty_elements_self_closed_and_layout_tags_closed(): void
    {
        [$out, $path] = Avada_Shortcodes::add(self::FIXTURE, '0.0.0', 1, ['tag' => 'fusion_separator', 'attrs' => ['style_type' => 'single solid']]);
        $this->assertSame('0.0.0.1', $path);
        $this->assertStringContainsString('[/fusion_title][fusion_separator style_type="single solid" /][fusion_text', $out);

        [$out, $path] = Avada_Shortcodes::add(self::FIXTURE, '', null, [
            'tag'      => 'fusion_builder_container',
            'attrs'    => ['type' => 'flex'],
            'children' => [
                ['tag' => 'fusion_builder_row', 'children' => [
                    ['tag' => 'fusion_builder_column', 'attrs' => ['type' => '1_1', 'layout' => '1_1'], 'children' => [
                        ['tag' => 'fusion_text', 'text' => '<p>Footer</p>'],
                        ['tag' => 'fusion_text'],
                    ]],
                ]],
            ],
        ]);
        $this->assertSame('2', $path);
        $this->assertSame(
            self::FIXTURE . '[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"]'
            . '[fusion_text]<p>Footer</p>[/fusion_text][fusion_text][/fusion_text]'
            . '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]',
            $out
        );

        [$out] = Avada_Shortcodes::add(self::FIXTURE, '1.0.0', 0, ['tag' => 'fusion_builder_row_inner']);
        $this->assertStringContainsString('[fusion_builder_column type="1_1" layout="1_1"][fusion_builder_row_inner][/fusion_builder_row_inner][fusion_tabs', $out);
    }

    public function test_add_refuses_a_self_closed_or_text_element_as_parent(): void
    {
        foreach (['0.0.0.3', '0.0.0.1'] as $to) {
            try {
                Avada_Shortcodes::add(self::FIXTURE, $to, null, ['tag' => 'fusion_text']);
                $this->fail('Expected rejection for ' . $to);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot hold child elements', $e->getMessage());
            }
        }
    }

    public function test_remove_and_move_splice_whole_elements(): void
    {
        $this->assertSame(str_replace(self::SEPARATOR, '', self::FIXTURE), Avada_Shortcodes::remove(self::FIXTURE, '0.0.0.3'));

        $out  = Avada_Shortcodes::move(self::FIXTURE, '1.0.0.2', '0.0.0', 0);
        $tree = Avada_Shortcodes::tree($out);
        $this->assertSame('acme_widget', $tree[0]['children'][0]['children'][0]['children'][0]['tag']);
        $this->assertStringContainsString(self::COLUMN_A . self::UNKNOWN . '[fusion_title', $out);
        $this->assertSame(strlen(self::FIXTURE), strlen($out));
    }

    public function test_wpbakery_encoding_and_void_form_are_unchanged(): void
    {
        [$out] = WPBakery_Shortcodes::add('[vc_row][vc_column][/vc_column][/vc_row]', '0.0', null, ['tag' => 'vc_btn', 'attrs' => ['title' => 'a"b']]);

        $this->assertSame('[vc_row][vc_column][vc_btn title="a``b"][/vc_column][/vc_row]', $out);
    }
}
