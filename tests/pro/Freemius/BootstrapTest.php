<?php

namespace WPMCP\Tests\Pro\Freemius;

use WPMCP\Freemius\Bootstrap;

class BootstrapTest extends \WP_UnitTestCase
{
    public function test_config_returns_expected_static_shape(): void
    {
        $config = Bootstrap::config();

        $this->assertIsArray($config);
        $this->assertSame('wpmcp', $config['slug']);
        $this->assertSame('plugin', $config['type']);
        $this->assertFalse($config['is_premium']);
        $this->assertTrue($config['has_premium_version']);
        $this->assertSame('wpmcp-pro', $config['premium_slug']);
        $this->assertFalse($config['has_addons']);
        $this->assertTrue($config['has_paid_plans']);
        $this->assertTrue($config['anonymous_mode']);
        $this->assertSame('wpmcp', $config['menu']['slug']);
    }
}
