<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\OptinMonster_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * OptinMonster (issue #685): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * OptinMonster is not loaded in the shared test core. Presence is driven
 * through wpmcp_optinmonster_active, and its post type is registered here the
 * way OptinMonster 2.17.1 does in OMAPI/Type.php: a campaign is an `omapi`
 * post whose every capability maps to the plugin's access capability
 * (manage_options, filterable through optin_monster_api_menu_cap). Campaigns
 * are built in the OptinMonster app and synced down by OMAPI_Save: post_name
 * is the campaign slug, post_excerpt its app id, and the post meta _omapi_type,
 * _omapi_ids and _omapi_enabled (set to true when the campaign is active,
 * deleted otherwise) plus the display rules the plugin's own settings screen
 * writes (_omapi_automatic, _omapi_users, _omapi_never, _omapi_only,
 * _omapi_categories, _omapi_show, _omapi_test, _omapi_auto_location).
 * OMAPI_Rules::exclude_if_not_enabled() hides a campaign whose
 * _omapi_enabled is empty.
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_optinmonster_write) and each is undone in a test.
 */
class OptinMonsterPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'optinmonster-list-campaigns'   => 'read',
        'optinmonster-get-campaign'     => 'read',
        'optinmonster-enable-campaign'  => 'write',
        'optinmonster-disable-campaign' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $cap = 'manage_options';
        register_post_type('omapi', [
            'public'          => false,
            'rewrite'         => false,
            'capability_type' => 'om_campaign',
            'map_meta_cap'    => false,
            'capabilities'    => array_fill_keys([ 'edit_post', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_post', 'read_private_posts', 'delete_post', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts' ], $cap),
            'supports'        => [ 'title' ],
        ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_optinmonster_active', '__return_true');
        add_filter('wpmcp_enable_optinmonster_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_optinmonster_active');
        remove_all_filters('wpmcp_enable_optinmonster_write');
        remove_all_filters('optin_monster_api_menu_cap');
        unregister_post_type('omapi');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A campaign as OMAPI_Save::new_optin() stores it after a sync. */
    private function campaign(string $title = 'Spring sale lightbox', string $slug = 'abc123xyz', string $type = 'lightbox', bool $enabled = true): int
    {
        $id = self::factory()->post->create([
            'post_type'    => 'omapi',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_excerpt' => '987654',
            'post_content' => '<script>/* embed */</script>',
            'post_status'  => $enabled ? 'publish' : 'draft',
        ]);
        update_post_meta($id, '_omapi_type', $type);
        update_post_meta($id, '_omapi_ids', [ '987654', $slug ]);
        if ($enabled) {
            update_post_meta($id, '_omapi_enabled', true);
        }
        update_post_meta($id, '_omapi_users', 'all');
        update_post_meta($id, '_omapi_never', [ '12' ]);
        update_post_meta($id, '_omapi_only', []);
        update_post_meta($id, '_omapi_categories', [ '3' ]);
        update_post_meta($id, '_omapi_show', [ 'index', 'is_single' ]);
        update_post_meta($id, '_omapi_test', '');
        if ('post' === $type) {
            update_post_meta($id, '_omapi_automatic', 1);
            update_post_meta($id, '_omapi_auto_location', 'paragraphs');
        }
        return $id;
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertArrayNotHasKey('optinmonster-create-campaign', $ops, 'campaigns are built in the OptinMonster app');
    }

    public function test_while_optinmonster_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_optinmonster_active');
        add_filter('wpmcp_optinmonster_active', '__return_false');
        $out = $this->read('optinmonster-list-campaigns');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate OptinMonster', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_file_constant(): void
    {
        remove_all_filters('wpmcp_optinmonster_active');
        $this->assertSame(defined('OMAPI_FILE') || class_exists('OMAPI', false), OptinMonster_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->campaign();
        remove_all_filters('wpmcp_enable_optinmonster_write');
        foreach ([ 'optinmonster-enable-campaign', 'optinmonster-disable-campaign' ] as $op) {
            $out = $this->write($op, [ 'id' => $id ]);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_optinmonster_write', $out['error']['message']);
        }
        $this->assertSame('1', get_post_meta($id, '_omapi_enabled', true));
    }

    public function test_only_users_with_the_optinmonster_access_capability_may_read_or_write(): void
    {
        $id = $this->campaign();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('optinmonster-list-campaigns')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('optinmonster-get-campaign', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('optinmonster-disable-campaign', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('1', get_post_meta($id, '_omapi_enabled', true));

        add_filter('optin_monster_api_menu_cap', static fn (): string => 'edit_others_posts');
        $this->ok($this->read('optinmonster-list-campaigns'));
    }

    public function test_list_campaigns_returns_each_campaign_with_its_type_and_switch(): void
    {
        $a = $this->campaign('Alpha', 'alpha1', 'lightbox');
        $b = $this->campaign('Beta', 'beta22', 'post', false);

        $out = $this->ok($this->read('optinmonster-list-campaigns'));
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['campaigns'], null, 'id');
        $this->assertSame('alpha1', $rows[ $a ]['slug']);
        $this->assertSame('lightbox', $rows[ $a ]['type']);
        $this->assertTrue($rows[ $a ]['enabled']);
        $this->assertFalse($rows[ $b ]['enabled']);
        $this->assertTrue($rows[ $b ]['automatic']);
        $this->assertArrayNotHasKey('embed', $rows[ $a ]);

        $this->assertSame([ $b ], array_column($this->read('optinmonster-list-campaigns', [ 'enabled' => false ])['campaigns'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('optinmonster-list-campaigns', [ 'type' => 'lightbox' ])['campaigns'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('optinmonster-list-campaigns', [ 'search' => 'Alpha' ])['campaigns'], 'id'));
    }

    public function test_get_campaign_returns_the_display_rules(): void
    {
        $id = $this->campaign('Inline', 'inl', 'post');
        $c  = $this->ok($this->read('optinmonster-get-campaign', [ 'id' => $id ]))['campaign'];
        $this->assertSame('Inline', $c['title']);
        $this->assertSame('987654', $c['app_id']);
        $this->assertSame('all', $c['rules']['users']);
        $this->assertSame([ '12' ], $c['rules']['never']);
        $this->assertSame([ '3' ], $c['rules']['categories']);
        $this->assertSame([ 'index', 'is_single' ], $c['rules']['show']);
        $this->assertSame('paragraphs', $c['rules']['auto_location']);
        $this->assertFalse($c['test_mode']);
        $this->assertSame('campaign_not_found', $this->read('optinmonster-get-campaign', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_disable_and_enable_switch_the_campaign_and_each_rolls_back(): void
    {
        $id = $this->campaign();

        $off = $this->ok($this->write('optinmonster-disable-campaign', [ 'id' => $id ]));
        $this->assertTrue($off['recoverable']);
        $this->assertSame('', get_post_meta($id, '_omapi_enabled', true), 'deleted, as OMAPI_Save does for an inactive campaign');
        $this->assertFalse($off['result']['campaign']['enabled']);

        $on = $this->ok($this->write('optinmonster-enable-campaign', [ 'id' => $id ]));
        $this->assertSame('1', get_post_meta($id, '_omapi_enabled', true));
        $this->assertTrue($on['result']['campaign']['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation($on['operation_id']));
        $this->assertSame('', get_post_meta($id, '_omapi_enabled', true));
        $this->assertTrue(Rollback_Service::restore_operation($off['operation_id']));
        $this->assertSame('1', get_post_meta($id, '_omapi_enabled', true));
    }

    public function test_writes_refuse_a_non_campaign(): void
    {
        $this->assertSame('campaign_not_found', $this->write('optinmonster-enable-campaign', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }
}
