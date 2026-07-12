<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

class ActivatePluginTest extends \WP_UnitTestCase
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

    public function test_requires_plugin_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Activate_Plugin())->handle([]);
    }

    public function test_unknown_plugin_errors(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Activate_Plugin())->handle(['plugin' => 'ghost/ghost.php']);
    }

    public function test_activates_plugin_and_is_snapshotted(): void
    {
        $this->assertNotContains('akismet/akismet.php', (array) get_option('active_plugins', []));

        $out = (new Activate_Plugin())->handle(['plugin' => 'akismet/akismet.php']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertContains('akismet/akismet.php', (array) get_option('active_plugins', []));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_rollback_operation_restores_prior_active_plugins(): void
    {
        $before = (array) get_option('active_plugins', []);

        $out = (new Activate_Plugin())->handle(['plugin' => 'akismet/akismet.php']);
        $this->assertContains('akismet/akismet.php', (array) get_option('active_plugins', []));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($restored['restored']);

        $this->assertSame($before, (array) get_option('active_plugins', []));
    }
}
