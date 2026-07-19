<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Stock\Set_Stock_Key;
use WPMCP\Tools\Media\Stock\Stock_Key_Store;

/**
 * BYO stock-provider API keys are stored encrypted at rest (issue #64
 * acceptance criterion): the persisted option must never contain the
 * plaintext key.
 */
class StockKeyStoreTest extends \WP_UnitTestCase
{
    private const SECRET = 'wpmcp-super-secret-api-key-123';

    protected function tearDown(): void
    {
        delete_option(Stock_Key_Store::OPTION);
        parent::tearDown();
    }

    public function test_round_trips_a_key(): void
    {
        Stock_Key_Store::set('pexels', self::SECRET);

        $this->assertSame(self::SECRET, Stock_Key_Store::get('pexels'));
    }

    public function test_key_is_encrypted_at_rest(): void
    {
        Stock_Key_Store::set('pexels', self::SECRET);

        $raw = wp_json_encode(get_option(Stock_Key_Store::OPTION));
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString(base64_encode(self::SECRET), $raw);
    }

    public function test_unknown_provider_returns_null(): void
    {
        $this->assertNull(Stock_Key_Store::get('pexels'));
    }

    public function test_clear_removes_a_stored_key(): void
    {
        Stock_Key_Store::set('pexels', self::SECRET);
        Stock_Key_Store::clear('pexels');

        $this->assertNull(Stock_Key_Store::get('pexels'));
    }

    public function test_configured_lists_only_providers_with_keys(): void
    {
        Stock_Key_Store::set('pexels', self::SECRET);
        Stock_Key_Store::set('unsplash', 'other-key');

        $this->assertSame(['pexels', 'unsplash'], Stock_Key_Store::configured());
    }

    public function test_set_stock_key_tool_stores_and_clears_without_echoing_the_key(): void
    {
        $tool = new Set_Stock_Key();

        $out = $tool->handle(['provider' => 'pexels', 'api_key' => self::SECRET]);
        $this->assertSame('pexels', $out['provider']);
        $this->assertTrue($out['configured']);
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($out));
        $this->assertSame(self::SECRET, Stock_Key_Store::get('pexels'));

        $out = $tool->handle(['provider' => 'pexels', 'api_key' => '']);
        $this->assertFalse($out['configured']);
        $this->assertNull(Stock_Key_Store::get('pexels'));
    }

    public function test_set_stock_key_rejects_unknown_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Stock_Key())->handle(['provider' => 'shutterstock', 'api_key' => 'x']);
    }

    public function test_set_stock_key_rejects_keyless_provider(): void
    {
        // Openverse needs no key; storing one would only mislead.
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Stock_Key())->handle(['provider' => 'openverse', 'api_key' => 'x']);
    }
}
