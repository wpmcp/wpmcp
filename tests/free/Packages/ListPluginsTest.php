<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\List_Plugins;

class ListPluginsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_lists_plugins_with_active_and_protected_flags(): void
    {
        $out = (new List_Plugins())->handle([]);

        $this->assertArrayHasKey('plugins', $out);
        $this->assertNotEmpty($out['plugins']);

        $rows = [];
        foreach ($out['plugins'] as $row) {
            $rows[ $row['file'] ] = $row;
        }

        $this->assertArrayHasKey('akismet/akismet.php', $rows);
        $this->assertFalse($rows['akismet/akismet.php']['is_protected']);
        $this->assertArrayHasKey('active', $rows['akismet/akismet.php']);
        $this->assertArrayHasKey('name', $rows['akismet/akismet.php']);
        $this->assertArrayHasKey('version', $rows['akismet/akismet.php']);
    }

    public function test_marks_active_plugin_correctly(): void
    {
        activate_plugin('akismet/akismet.php');

        $out  = (new List_Plugins())->handle([]);
        $rows = [];
        foreach ($out['plugins'] as $row) {
            $rows[ $row['file'] ] = $row;
        }

        $this->assertTrue($rows['akismet/akismet.php']['active']);

        deactivate_plugins(['akismet/akismet.php']);
    }
}
