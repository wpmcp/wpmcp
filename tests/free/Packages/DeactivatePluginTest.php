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
        $this->mock_akismet_api();
    }

    /**
     * Deactivating Akismet tells its API the key is no longer in use (issue
     * #963). Answer that call here so the test never reaches the network.
     */
    private function mock_akismet_api(): void
    {
        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (false === $pre && false !== strpos((string) $url, 'rest.akismet.com/')) {
                return ['headers' => [], 'body' => 'deactivated', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
            }
            return $pre;
        }, 10, 3);
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
