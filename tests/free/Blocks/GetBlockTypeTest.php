<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\Get_Block_Type;

class GetBlockTypeTest extends \WP_UnitTestCase
{
    public function test_returns_full_attributes_schema_for_a_known_block(): void
    {
        $out = (new Get_Block_Type())->handle(['name' => 'core/heading']);

        $this->assertSame('core/heading', $out['name']);
        $this->assertArrayHasKey('attributes', $out);
        $this->assertArrayHasKey('content', $out['attributes']);
        $this->assertArrayHasKey('level', $out['attributes']);
    }

    public function test_returns_supports_and_provides_uses_context(): void
    {
        $out = (new Get_Block_Type())->handle(['name' => 'core/heading']);

        $this->assertArrayHasKey('supports', $out);
        $this->assertIsArray($out['supports']);
        $this->assertArrayHasKey('uses_context', $out);
        $this->assertIsArray($out['uses_context']);
        $this->assertArrayHasKey('provides_context', $out);
        $this->assertIsArray($out['provides_context']);
    }

    public function test_throws_for_an_unknown_block_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Block_Type())->handle(['name' => 'not-a-real/block']);
    }
}
