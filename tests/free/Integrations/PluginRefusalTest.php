<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\BetterDocs_Pack;
use WPMCP\Integrations\Code_Snippets_Pack;
use WPMCP\Integrations\Ops_Status_Packs;
use WPMCP\Integrations\Plugin_Refusal;

/**
 * Issue #877: every pack's "plugin not active" answer names the plugin,
 * says whether it is installed, links the plugins screen and, for a plugin
 * the site lacks, the wordpress.org page (free) or vendor page (premium).
 * The pack's own error code stays.
 */
class PluginRefusalTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_betterdocs_active');
        remove_all_filters('wpmcp_code_snippets_active');
        parent::tearDown();
    }

    public function test_a_free_plugin_that_is_not_installed_gets_its_wordpress_org_page(): void
    {
        $r = Ops_Status_Packs::presence(false, 'betterdocs_inactive', 'BetterDocs');

        $this->assertSame('betterdocs_inactive', $r['code']);
        $this->assertStringContainsString('BetterDocs is not installed on this site', $r['message']);
        $this->assertStringContainsString('https://wordpress.org/plugins/betterdocs/', $r['message']);
        $this->assertStringContainsString(admin_url('plugins.php'), $r['message']);
        $this->assertSame('missing', $r['data']['state']);
        $this->assertSame('betterdocs', $r['data']['plugin']);
        $this->assertSame(admin_url('plugins.php'), $r['data']['plugins_url']);
        $this->assertSame('https://wordpress.org/plugins/betterdocs/', $r['data']['install_url']);
    }

    public function test_helpdesk_packs_get_the_same_shape(): void
    {
        foreach ([ [ 'fluent_support_inactive', 'Fluent Support', 'fluent-support' ], [ 'awesome_support_inactive', 'Awesome Support', 'awesome-support' ] ] as [ $code, $name, $slug ]) {
            $r = Ops_Status_Packs::presence(false, $code, $name);
            $this->assertSame($code, $r['code']);
            $this->assertStringContainsString($name, $r['message']);
            $this->assertSame("https://wordpress.org/plugins/{$slug}/", $r['data']['install_url']);
        }
    }

    public function test_a_premium_plugin_gets_its_vendor_page(): void
    {
        $r = Ops_Status_Packs::presence(false, 'memberpress_inactive', 'MemberPress');
        $this->assertSame('https://memberpress.com/', $r['data']['install_url']);
        $this->assertStringContainsString('https://memberpress.com/', $r['message']);
    }

    public function test_an_unknown_plugin_still_gets_the_plugins_link(): void
    {
        $r = Plugin_Refusal::make('x_inactive', 'Some Plugin');
        $this->assertArrayNotHasKey('install_url', $r['data']);
        $this->assertStringContainsString(admin_url('plugins.php'), $r['message']);
    }

    public function test_an_installed_but_inactive_plugin_is_asked_to_be_activated(): void
    {
        $dir = WP_PLUGIN_DIR . '/wpmcp-refusal-fixture';
        wp_mkdir_p($dir);
        file_put_contents($dir . '/fixture.php', "<?php\n");
        try {
            $r = Plugin_Refusal::make('fixture_inactive', 'BetterDocs', 'wpmcp-refusal-fixture/fixture.php');
        } finally {
            unlink($dir . '/fixture.php');
            rmdir($dir);
        }

        $this->assertSame('fixture_inactive', $r['code']);
        $this->assertSame('inactive', $r['data']['state']);
        $this->assertStringContainsString('BetterDocs is installed but not active on this site. Activate BetterDocs', $r['message']);
    }

    public function test_betterdocs_and_code_snippets_ops_refuse_through_the_shared_shape(): void
    {
        add_filter('wpmcp_betterdocs_active', '__return_false');
        add_filter('wpmcp_code_snippets_active', '__return_false');

        $op = BetterDocs_Pack::operations()['betterdocs-list-docs'] ?? null;
        $this->assertNotNull($op);
        $r = ($op['requires'])();
        $this->assertSame('betterdocs_inactive', $r['code']);
        $this->assertArrayHasKey('plugins_url', $r['data']);

        $c = Code_Snippets_Pack::requirement();
        $this->assertSame('code_snippets_inactive', $c['code']);
        $this->assertSame('https://wordpress.org/plugins/code-snippets/', $c['data']['install_url']);
    }
}
