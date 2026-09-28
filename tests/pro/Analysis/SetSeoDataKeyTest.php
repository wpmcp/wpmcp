<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Request_Log;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Analysis\SeoData\Seo_Data_Key_Store;
use WPMCP\Tools\Analysis\SeoData\Seo_Data_Lookup;
use WPMCP\Tools\Analysis\SeoData\Set_Seo_Data_Key;

/**
 * set-seo-data-key (issue #304): the SEO data provider credential is stored
 * like the stock image keys, encrypted at rest, admin only, and never echoed
 * in a response or written to a log.
 */
class SetSeoDataKeyTest extends \WP_UnitTestCase
{
    private const CREDENTIAL = 'agent@example.test:s3cr3t-Pa55word';

    /** @var array<int, string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Seo_Data_Key_Store::OPTION);
        delete_option(Request_Log::OPTION);
        Seo_Data_Lookup::flush_cache();
        Rate_Limiter::set_clock_override(fn() => 1_700_304_000);
    }

    protected function tearDown(): void
    {
        delete_option(Seo_Data_Key_Store::OPTION);
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        Seo_Data_Lookup::flush_cache();
        Rate_Limiter::set_clock_override(null);
        parent::tearDown();
    }

    public function test_the_store_round_trips_and_encrypts_at_rest(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);

        $this->assertSame(self::CREDENTIAL, Seo_Data_Key_Store::get('dataforseo'));
        $raw = (string) wp_json_encode(get_option(Seo_Data_Key_Store::OPTION));
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('s3cr3t-Pa55word', $raw);
        $this->assertStringNotContainsString(base64_encode(self::CREDENTIAL), $raw);
        $this->assertSame(['dataforseo'], Seo_Data_Key_Store::configured());
    }

    public function test_a_blob_sealed_for_the_stock_store_does_not_open_here(): void
    {
        \WPMCP\Tools\Media\Stock\Stock_Key_Store::set('pexels', self::CREDENTIAL);
        update_option(Seo_Data_Key_Store::OPTION, ['dataforseo' => get_option(\WPMCP\Tools\Media\Stock\Stock_Key_Store::OPTION)['pexels']], false);

        $this->assertNull(Seo_Data_Key_Store::get('dataforseo'), 'Each store derives its own key.');
        delete_option(\WPMCP\Tools\Media\Stock\Stock_Key_Store::OPTION);
    }

    public function test_set_stores_and_never_echoes_the_key(): void
    {
        $out = (new Set_Seo_Data_Key())->handle(['provider' => 'dataforseo', 'api_key' => self::CREDENTIAL]);

        $this->assertSame(['provider' => 'dataforseo', 'configured' => true], $out);
        $this->assertSame(self::CREDENTIAL, Seo_Data_Key_Store::get('dataforseo'));
    }

    public function test_an_empty_key_clears_it(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);

        $out = (new Set_Seo_Data_Key())->handle(['provider' => 'dataforseo', 'api_key' => '']);

        $this->assertFalse($out['configured']);
        $this->assertNull(Seo_Data_Key_Store::get('dataforseo'));
    }

    public function test_a_malformed_credential_is_refused_without_echoing_it(): void
    {
        try {
            (new Set_Seo_Data_Key())->handle(['provider' => 'dataforseo', 'api_key' => 'no-colon-secret-value']);
            $this->fail('Expected a malformed credential to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('login:password', $e->getMessage());
            $this->assertStringNotContainsString('no-colon-secret-value', $e->getMessage());
        }
        $this->assertNull(Seo_Data_Key_Store::get('dataforseo'));
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Seo_Data_Key())->handle(['provider' => 'nope', 'api_key' => self::CREDENTIAL]);
    }

    public function test_changing_the_key_drops_the_cooldown_and_cache(): void
    {
        Seo_Data_Lookup::start_cooldown('dataforseo', 60);
        $this->assertGreaterThan(0, Seo_Data_Lookup::cooldown_remaining('dataforseo'));

        (new Set_Seo_Data_Key())->handle(['provider' => 'dataforseo', 'api_key' => self::CREDENTIAL]);

        $this->assertSame(0, Seo_Data_Lookup::cooldown_remaining('dataforseo'));
    }

    /** @return array<string, Ability> */
    private static function abilities(): array
    {
        $out = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $out[ $ability->name ] = $ability;
        }
        return $out;
    }

    public function test_set_seo_data_key_is_a_pro_admin_only_write(): void
    {
        $ability = self::abilities()['wpmcp/set-seo-data-key'] ?? null;

        $this->assertNotNull($ability, 'set-seo-data-key must be registered');
        $this->assertSame('pro', $ability->tier);
        $this->assertSame('manage_options', $ability->capability);
        $this->assertSame('update', $ability->operation);
        $this->assertSame(['dataforseo'], $ability->input_schema['properties']['provider']['enum']);
    }

    public function test_analyze_seo_advertises_the_lookup_ops(): void
    {
        $ability = self::abilities()['wpmcp/analyze-seo'];

        $this->assertSame('read', $ability->operation);
        $this->assertSame('edit_posts', $ability->capability);
        $props = $ability->input_schema['properties'];
        $this->assertSame(['score', 'keywords', 'backlinks'], $props['op']['enum']);
        $this->assertArrayHasKey('keywords', $props);
        $this->assertArrayHasKey('target', $props);
        $this->assertStringContainsString('set-seo-data-key', $ability->description);
    }

    /** Run an ability's handler through the Registrar's logging wrapper. */
    private function run_logged(Ability $ability, array $input)
    {
        $wrap = new \ReflectionMethod(Registrar::class, 'throttled');
        $callable = $wrap->invoke(new Registrar(), $ability);
        try {
            return $callable($input);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    public function test_the_key_never_reaches_the_request_log_even_with_argument_capture_on(): void
    {
        update_option(Request_Log::CAPTURE_OPTION, 1);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $abilities = self::abilities();

        $mock = function ($pre, $args, $url) {
            $this->requested[] = (string) $url;
            return new \WP_Error('http_request_failed', 'refused for ' . self::CREDENTIAL);
        };
        add_filter('pre_http_request', $mock, 10, 3);

        try {
            $this->run_logged($abilities['wpmcp/set-seo-data-key'], ['provider' => 'dataforseo', 'api_key' => self::CREDENTIAL]);
            $this->run_logged($abilities['wpmcp/analyze-seo'], ['op' => 'backlinks', 'target' => 'example.com']);
        } finally {
            remove_filter('pre_http_request', $mock, 10);
        }

        $this->assertCount(1, $this->requested);
        $log = (string) wp_json_encode(get_option(Request_Log::OPTION));
        $this->assertStringContainsString('wpmcp/set-seo-data-key', $log);
        $this->assertStringContainsString('wpmcp/analyze-seo', $log);
        $this->assertStringNotContainsString('s3cr3t-Pa55word', $log);
        $this->assertStringNotContainsString(base64_encode(self::CREDENTIAL), $log);
    }
}
