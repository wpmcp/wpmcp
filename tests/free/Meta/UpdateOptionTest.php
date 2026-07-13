<?php

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Safety\Snapshot_Store;

class UpdateOptionTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        delete_option('wpmcp_test_option');
        remove_all_filters('wpmcp_enable_option_write');
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Update_Option())->handle(['name' => 'wpmcp_test_option', 'value' => 'x']);
    }

    public function test_enabled_via_filter(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');

        $out = (new Update_Option())->handle(['name' => 'wpmcp_test_option', 'value' => 'x']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('x', get_option('wpmcp_test_option'));
    }
}
