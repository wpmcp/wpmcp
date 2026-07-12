<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Update_Plugin;

class UpdatePluginTest extends \WP_UnitTestCase
{
    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Update_Plugin())->handle(['plugin' => 'akismet/akismet.php', 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_update_plugin', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Plugin())->handle(['plugin' => 'akismet/akismet.php']);
    }

    public function test_refuses_protected_plugin_when_enabled(): void
    {
        add_filter('wpmcp_enable_update_plugin', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Plugin())->handle(['plugin' => 'wpmcp/wpmcp.php', 'confirm' => true]);
    }

    public function test_requires_plugin_argument_when_enabled(): void
    {
        add_filter('wpmcp_enable_update_plugin', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Plugin())->handle(['confirm' => true]);
    }

    public function test_unknown_plugin_errors_when_enabled(): void
    {
        add_filter('wpmcp_enable_update_plugin', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Plugin())->handle(['plugin' => 'ghost/ghost.php', 'confirm' => true]);
    }

    public function test_reports_up_to_date_when_no_update_available(): void
    {
        add_filter('wpmcp_enable_update_plugin', '__return_true');
        set_site_transient('update_plugins', (object) ['response' => []]);

        $out = (new Update_Plugin())->handle(['plugin' => 'akismet/akismet.php', 'confirm' => true]);

        $this->assertTrue($out['up_to_date']);
        $this->assertFalse($out['updated']);
    }
}
