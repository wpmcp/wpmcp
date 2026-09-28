<?php

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Tools\Meta\Get_Option;

class GetOptionTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_option('wpmcp_test_option');
        parent::tearDown();
    }

    public function test_returns_an_option_value(): void
    {
        update_option('wpmcp_test_option', 'hello');

        $out = (new Get_Option())->handle(['name' => 'wpmcp_test_option']);

        $this->assertSame('wpmcp_test_option', $out['name']);
        $this->assertSame('hello', $out['value']);
    }

    public function test_requires_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Option())->handle([]);
    }

    /** The stored JS snippet may embed secrets; its tools never echo it back. */
    public function test_refuses_the_custom_code_store(): void
    {
        update_option('wpmcp_custom_code', ['js' => ['site' => 'const key = "x";']], false);
        add_filter('wpmcp_option_denylist_patterns', '__return_empty_array');

        try {
            (new Get_Option())->handle(['name' => 'wpmcp_custom_code']);
            $this->fail('get-option should refuse the custom code store.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('const key', $e->getMessage());
        } finally {
            remove_all_filters('wpmcp_option_denylist_patterns');
            delete_option('wpmcp_custom_code');
        }
    }

    public function test_refuses_a_denylisted_option(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_Option())->handle(['name' => 'auth_key']);
    }
}
