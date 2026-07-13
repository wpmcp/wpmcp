<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\Tools\Diagnostics\Get_Debug_Config;

class GetDebugConfigTest extends \WP_UnitTestCase
{
    public function test_reports_debug_related_constants(): void
    {
        $out = (new Get_Debug_Config())->handle([]);

        $this->assertArrayHasKey('WP_DEBUG', $out);
        $this->assertArrayHasKey('WP_DEBUG_LOG', $out);
        $this->assertArrayHasKey('WP_DEBUG_DISPLAY', $out);
        $this->assertArrayHasKey('SCRIPT_DEBUG', $out);
        $this->assertArrayHasKey('SAVEQUERIES', $out);
        $this->assertArrayHasKey('log_path', $out);
    }

    public function test_reflects_actual_constant_values(): void
    {
        $out = (new Get_Debug_Config())->handle([]);

        $this->assertSame(defined('WP_DEBUG') && WP_DEBUG, $out['WP_DEBUG']);
        $this->assertSame(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG, $out['SCRIPT_DEBUG']);
        $this->assertSame(defined('SAVEQUERIES') && SAVEQUERIES, $out['SAVEQUERIES']);
    }

    public function test_log_path_is_null_when_debug_log_is_disabled(): void
    {
        $out = (new Get_Debug_Config())->handle([]);

        if (! defined('WP_DEBUG_LOG') || false === WP_DEBUG_LOG) {
            $this->assertNull($out['log_path']);
        } else {
            $this->assertIsString($out['log_path']);
        }
    }
}
