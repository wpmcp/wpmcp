<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Server_Audit;

class ServerAuditTest extends \WP_UnitTestCase
{
    private Server_Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new Server_Audit();
    }

    public function test_php_version_bands(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_php_version('8.2.0')['status']);
        $this->assertSame('pass', $this->audit->evaluate_php_version('8.3.1')['status']);
        $this->assertSame('warning', $this->audit->evaluate_php_version('8.1.27')['status']);
        $this->assertSame('critical', $this->audit->evaluate_php_version('7.4.33')['status']);
    }

    public function test_memory_limit_bands(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('256M')['status']);
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('128M')['status']);
        $this->assertSame('warning', $this->audit->evaluate_memory_limit('64M')['status']);
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('-1')['status']);
    }

    public function test_opcache_pass_when_enabled_warning_when_disabled(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_opcache(true)['status']);
        $this->assertSame('warning', $this->audit->evaluate_opcache(false)['status']);
    }

    public function test_object_cache_pass_when_persistent_warning_when_not(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_object_cache(true)['status']);
        $this->assertSame('warning', $this->audit->evaluate_object_cache(false)['status']);
    }

    public function test_image_lib_passes_when_either_library_present(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_image_lib(true, false)['status']);
        $this->assertSame('pass', $this->audit->evaluate_image_lib(false, true)['status']);
        $this->assertSame('pass', $this->audit->evaluate_image_lib(true, true)['status']);
        $this->assertSame('warning', $this->audit->evaluate_image_lib(false, false)['status']);
    }

    public function test_wp_debug_warns_only_in_production(): void
    {
        $this->assertSame('warning', $this->audit->evaluate_wp_debug(true, 'production')['status']);
        $this->assertSame('info', $this->audit->evaluate_wp_debug(true, 'local')['status']);
        $this->assertSame('pass', $this->audit->evaluate_wp_debug(false, 'production')['status']);
    }

    public function test_plugin_count_warns_above_forty(): void
    {
        $this->assertSame('info', $this->audit->evaluate_plugin_count(12)['status']);
        $this->assertSame('info', $this->audit->evaluate_plugin_count(40)['status']);
        $this->assertSame('warning', $this->audit->evaluate_plugin_count(41)['status']);
        $this->assertSame('warning', $this->audit->evaluate_plugin_count(55)['status']);
    }

    public function test_revisions_warns_above_a_thousand(): void
    {
        $this->assertSame('info', $this->audit->evaluate_revisions(40)['status']);
        $this->assertSame('info', $this->audit->evaluate_revisions(1000)['status']);
        $this->assertSame('warning', $this->audit->evaluate_revisions(1001)['status']);
        $this->assertSame('warning', $this->audit->evaluate_revisions(5000)['status']);
    }

    public function test_cron_backlog_warns_when_overdue(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_cron_backlog(0)['status']);
        $this->assertSame('warning', $this->audit->evaluate_cron_backlog(1)['status']);
        $this->assertSame('warning', $this->audit->evaluate_cron_backlog(7)['status']);
    }

    public function test_autoload_size_bands(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_autoload_size(200 * 1024, [])['status']);
        $this->assertSame('pass', $this->audit->evaluate_autoload_size(1048575, [])['status']);
        $this->assertSame('warning', $this->audit->evaluate_autoload_size(1048576, [])['status']);
        $this->assertSame('warning', $this->audit->evaluate_autoload_size(1500 * 1024, [])['status']);
        $this->assertSame('critical', $this->audit->evaluate_autoload_size(3145728, [])['status']);
        $this->assertSame('critical', $this->audit->evaluate_autoload_size(4 * 1024 * 1024, [])['status']);
    }

    public function test_autoload_size_value_includes_bytes_and_top_options(): void
    {
        $top    = [['option' => 'big_option', 'bytes' => 900]];
        $result = $this->audit->evaluate_autoload_size(900, $top);

        $this->assertSame(900, $result['value']['bytes']);
        $this->assertSame($top, $result['value']['top']);
    }

    public function test_database_size_is_always_info_and_includes_top_tables(): void
    {
        $top    = [['table' => 'wp_options', 'bytes' => 5000]];
        $result = $this->audit->evaluate_database_size(50000, $top);

        $this->assertSame('info', $result['status']);
        $this->assertSame(50000, $result['value']['bytes']);
        $this->assertSame($top, $result['value']['top_tables']);
    }
}
