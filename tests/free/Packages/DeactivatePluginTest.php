<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Deactivate_Plugin;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

class DeactivatePluginTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        deactivate_plugins(['akismet/akismet.php'], true);
        parent::tearDown();
    }

    public function test_refuses_protected_plugin(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Deactivate_Plugin())->handle(['plugin' => 'wpmcp/wpmcp.php']);
    }

    public function test_refuses_protected_elementor_plugin(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Deactivate_Plugin())->handle(['plugin' => 'elementor/elementor.php']);
    }

    public function test_requires_plugin_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Deactivate_Plugin())->handle([]);
    }

    public function test_deactivates_normal_plugin_and_is_snapshotted(): void
    {
        activate_plugin('akismet/akismet.php');
        $this->assertContains('akismet/akismet.php', (array) get_option('active_plugins', []));

        $out = (new Deactivate_Plugin())->handle(['plugin' => 'akismet/akismet.php']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertNotContains('akismet/akismet.php', (array) get_option('active_plugins', []));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_rollback_operation_restores_prior_active_plugins(): void
    {
        activate_plugin('akismet/akismet.php');
        $before = (array) get_option('active_plugins', []);

        $out = (new Deactivate_Plugin())->handle(['plugin' => 'akismet/akismet.php']);
        $this->assertNotContains('akismet/akismet.php', (array) get_option('active_plugins', []));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($restored['restored']);

        $after = (array) get_option('active_plugins', []);
        sort($before);
        sort($after);
        $this->assertSame($before, $after);
        $this->assertContains('akismet/akismet.php', $after);
    }
}
