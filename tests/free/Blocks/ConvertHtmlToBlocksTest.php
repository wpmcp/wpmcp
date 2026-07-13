<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\Convert_Html_To_Blocks;

class ConvertHtmlToBlocksTest extends \WP_UnitTestCase
{
    public function test_converts_heading_and_paragraph_to_core_blocks(): void
    {
        $html = '<h2>Title</h2><p>Hello</p>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $this->assertArrayHasKey('markup', $out);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(2, $blocks);
        $this->assertSame('core/heading', $blocks[0]['blockName']);
        $this->assertSame(2, $blocks[0]['attrs']['level']);
        $this->assertSame('core/paragraph', $blocks[1]['blockName']);
    }

    public function test_converts_unordered_list_to_core_list(): void
    {
        $html = '<ul><li>a</li><li>b</li></ul>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/list', $blocks[0]['blockName']);
        $this->assertArrayNotHasKey('ordered', $blocks[0]['attrs']);
        $this->assertStringContainsString('<li>a</li>', $blocks[0]['innerHTML']);
        $this->assertStringContainsString('<li>b</li>', $blocks[0]['innerHTML']);
    }

    public function test_converts_ordered_list_to_core_list_with_ordered_attr(): void
    {
        $html = '<ol><li>a</li><li>b</li></ol>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/list', $blocks[0]['blockName']);
        $this->assertTrue($blocks[0]['attrs']['ordered']);
    }

    public function test_converts_image_to_core_image(): void
    {
        $html = '<img src="https://example.com/photo.jpg" alt="A photo">';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/image', $blocks[0]['blockName']);
        $this->assertStringContainsString('https://example.com/photo.jpg', $blocks[0]['innerHTML']);
        $this->assertStringContainsString('A photo', $blocks[0]['innerHTML']);
    }

    public function test_wraps_unrecognized_element_in_core_html_block(): void
    {
        $html = '<custom-tag data-foo="bar">unusual content</custom-tag>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/html', $blocks[0]['blockName']);
        $this->assertStringContainsString('unusual content', $blocks[0]['innerHTML']);
        $this->assertStringContainsString('custom-tag', $blocks[0]['innerHTML']);
    }

    public function test_round_trip_through_parse_blocks_is_stable(): void
    {
        $html = '<h2>Title</h2><p>Hello</p><ul><li>a</li><li>b</li></ul>'
            . '<img src="https://example.com/photo.jpg" alt="A photo">'
            . '<custom-tag>unusual</custom-tag>';

        $markup = (new Convert_Html_To_Blocks())->handle(['html' => $html])['markup'];

        $first_parse  = parse_blocks($markup);
        $reserialized = serialize_blocks($first_parse);
        $second_parse = parse_blocks($reserialized);

        $this->assertSame($markup, $reserialized);
        $this->assertSame(
            array_column($first_parse, 'blockName'),
            array_column($second_parse, 'blockName')
        );
    }

    public function test_converts_blockquote_to_core_quote(): void
    {
        $html = '<blockquote><p>A quote</p></blockquote>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/quote', $blocks[0]['blockName']);
        $this->assertStringContainsString('A quote', $blocks[0]['innerHTML']);
    }

    public function test_converts_pre_to_core_code(): void
    {
        $html = '<pre><code>echo "hi";</code></pre>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/code', $blocks[0]['blockName']);
        $this->assertStringContainsString('echo "hi";', $blocks[0]['innerHTML']);
    }

    public function test_converts_bare_top_level_code_to_core_code(): void
    {
        $html = '<code>echo "hi";</code>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/code', $blocks[0]['blockName']);
        $this->assertStringContainsString('echo "hi";', $blocks[0]['innerHTML']);
    }

    public function test_converts_hr_to_core_separator(): void
    {
        $html = '<hr>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/separator', $blocks[0]['blockName']);
    }

    public function test_converts_table_to_core_table(): void
    {
        $html = '<table><tr><td>a</td><td>b</td></tr></table>';

        $out = (new Convert_Html_To_Blocks())->handle(['html' => $html]);

        $blocks = array_values(array_filter(
            parse_blocks($out['markup']),
            static fn (array $block): bool => null !== $block['blockName']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('core/table', $blocks[0]['blockName']);
        $this->assertStringContainsString('<td>a</td>', $blocks[0]['innerHTML']);
    }
}
