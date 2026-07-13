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
}
