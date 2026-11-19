<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Integrations\Rank_Math_Redirects_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Redirects\Create_Redirect;
use WPMCP\Tools\Redirects\Delete_Redirect;
use WPMCP\Tools\Redirects\List_Redirects;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Update_Redirect;

require_once __DIR__ . '/../../support/redirection-tables.php';
require_once __DIR__ . '/../../support/rank-math-tables.php';

/**
 * The generic redirect tools route to the active redirect plugin (issue
 * #930): with Redirection or Rank Math's Redirections module active they
 * read and write that plugin's redirects through its own pack (so every
 * write keeps that pack's guards and snapshot), with neither they keep using
 * WP MCP's own table, and with both they refuse until the caller names a
 * provider. provider:'wpmcp' always picks WP MCP's own table.
 */
class RedirectProviderRoutingTest extends \WP_UnitTestCase
{
    private int $group;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_redirection_tables();
        wpmcp_test_create_rank_math_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_redirection_tables();
        wpmcp_test_drop_rank_math_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Snapshot_Store::install();
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->group = wpmcp_test_redirection_group('Redirections');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_redirection_active');
        remove_all_filters('wpmcp_rank_math_active');
        remove_all_filters(Rank_Math_Redirects_Pack::ENABLE_FILTER);
        delete_option('rank_math_modules');
        parent::tearDown();
    }

    private function redirection_on(): void
    {
        add_filter('wpmcp_redirection_active', '__return_true');
    }

    private function rank_math_on(bool $module = true): void
    {
        add_filter('wpmcp_rank_math_active', '__return_true');
        add_filter(Rank_Math_Redirects_Pack::ENABLE_FILTER, '__return_true');
        update_option('rank_math_modules', $module ? [ 'redirections' ] : [ '404-monitor' ]);
    }

    private function redirection_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'redirection_items'));
    }

    private function rank_math_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'rank_math_redirections'));
    }

    public function test_without_a_redirect_plugin_the_managed_table_is_used(): void
    {
        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930' ]);

        $this->assertIsArray($out);
        $this->assertSame('wpmcp', $out['provider']);
        $this->assertSame(1, Redirect_Store::count([]));
        $this->assertSame('wpmcp', (new List_Redirects())->handle([])['provider']);
    }

    public function test_create_routes_to_redirection_and_rolls_back(): void
    {
        $this->redirection_on();

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'status_code' => 302, 'notes' => 'moved' ]);

        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $this->assertSame('redirection', $out['provider']);
        $this->assertSame('create-redirection-redirect', $out['routed_operation']);
        $this->assertSame(0, Redirect_Store::count([]), 'nothing lands in the managed table');
        $this->assertSame(1, $this->redirection_count());
        $row = wpmcp_test_redirection_row((int) $out['redirect']['id']);
        $this->assertSame('/new-930', $row['action_data']);
        $this->assertSame(302, (int) $row['action_code']);
        $this->assertSame('moved', $row['title']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(0, $this->redirection_count());
    }

    public function test_list_routes_to_redirection_and_keeps_pending_suggestions(): void
    {
        $this->redirection_on();
        wpmcp_test_redirection_item('/a-930', '/b-930', $this->group);
        Redirect_Store::insert([ 'source_path' => '/managed-930', 'target_url' => '/x', 'target_post_id' => 0, 'status_code' => 301, 'enabled' => 1, 'notes' => '' ]);

        $out = (new List_Redirects())->handle([ 'limit' => 10 ]);

        $this->assertSame('redirection', $out['provider']);
        $this->assertSame(1, $out['total']);
        $this->assertSame('/a-930', $out['redirects'][0]['source']);
        $this->assertArrayHasKey('pending_suggestions', $out);
        $this->assertSame(1, $out['managed_total'], 'WP MCP\'s own redirects are counted so they are not forgotten');
    }

    public function test_update_routes_enabled_alone_to_disable_and_refuses_a_mixed_change(): void
    {
        $this->redirection_on();
        $id = wpmcp_test_redirection_item('/a-930', '/b-930', $this->group);

        $mixed = (new Update_Redirect())->handle([ 'redirect_id' => $id, 'enabled' => false, 'target' => '/c-930' ]);
        $this->assertInstanceOf(\WP_Error::class, $mixed);
        $this->assertSame('redirect_update_split', $mixed->get_error_code());

        $off = (new Update_Redirect())->handle([ 'redirect_id' => $id, 'enabled' => false ]);
        $this->assertIsArray($off, $off instanceof \WP_Error ? $off->get_error_message() : '');
        $this->assertSame('disable-redirection-redirect', $off['routed_operation']);
        $this->assertSame('disabled', wpmcp_test_redirection_row($id)['status']);

        $retarget = (new Update_Redirect())->handle([ 'redirect_id' => $id, 'target' => '/c-930' ]);
        $this->assertIsArray($retarget, $retarget instanceof \WP_Error ? $retarget->get_error_message() : '');
        $this->assertSame('/c-930', wpmcp_test_redirection_row($id)['action_data']);
    }

    public function test_delete_routes_with_the_plugins_confirm_and_rolls_back(): void
    {
        $this->redirection_on();
        $id = wpmcp_test_redirection_item('/a-930', '/b-930', $this->group);

        $unconfirmed = (new Delete_Redirect())->handle([ 'redirect_id' => $id ]);
        $this->assertInstanceOf(\WP_Error::class, $unconfirmed);
        $this->assertSame('confirmation_required', $unconfirmed->get_error_code());
        $this->assertNotNull(wpmcp_test_redirection_row($id));

        $out = (new Delete_Redirect())->handle([ 'redirect_id' => $id, 'confirm' => true ]);
        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $this->assertNull(wpmcp_test_redirection_row($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNotNull(wpmcp_test_redirection_row($id));
    }

    public function test_two_active_plugins_refuse_until_a_provider_is_named(): void
    {
        $this->redirection_on();
        $this->rank_math_on();

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930' ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('redirect_provider_ambiguous', $out->get_error_code());
        $this->assertSame([ 'redirection', 'rankmath' ], $out->get_error_data()['active_providers']);
        $this->assertSame(0, $this->redirection_count() + $this->rank_math_count() + Redirect_Store::count([]));

        $this->assertInstanceOf(\WP_Error::class, (new List_Redirects())->handle([]));

        $rm = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'provider' => 'rankmath' ]);
        $this->assertIsArray($rm, $rm instanceof \WP_Error ? $rm->get_error_message() : '');
        $this->assertSame('rankmath', $rm['provider']);
        $this->assertSame(1, $this->rank_math_count());
        $this->assertSame(0, $this->redirection_count());
    }

    public function test_rank_math_with_its_redirections_module_off_is_not_a_provider(): void
    {
        $this->redirection_on();
        $this->rank_math_on(false);

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930' ]);
        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $this->assertSame('redirection', $out['provider']);
    }

    public function test_provider_wpmcp_keeps_the_managed_table_while_a_plugin_is_active(): void
    {
        $this->redirection_on();

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'provider' => 'wpmcp' ]);
        $this->assertIsArray($out);
        $this->assertSame('wpmcp', $out['provider']);
        $this->assertSame(1, Redirect_Store::count([]));
        $this->assertSame(0, $this->redirection_count());
    }

    public function test_an_inactive_or_unknown_provider_is_refused(): void
    {
        $inactive = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'provider' => 'rankmath' ]);
        $this->assertInstanceOf(\WP_Error::class, $inactive);
        $this->assertSame('redirect_provider_inactive', $inactive->get_error_code());

        $unknown = (new List_Redirects())->handle([ 'provider' => 'nope' ]);
        $this->assertInstanceOf(\WP_Error::class, $unknown);
        $this->assertSame('redirect_provider_unknown', $unknown->get_error_code());
        $this->assertSame(0, Redirect_Store::count([]));
    }

    public function test_rank_math_refuses_notes_it_cannot_store_and_maps_enabled_to_status(): void
    {
        $this->rank_math_on();

        $notes = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'notes' => 'x' ]);
        $this->assertInstanceOf(\WP_Error::class, $notes);
        $this->assertSame('redirect_field_unsupported', $notes->get_error_code());
        $this->assertSame(0, $this->rank_math_count());

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target' => '/new-930', 'enabled' => false ]);
        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $this->assertSame('inactive', $out['redirection']['status']);

        $list = (new List_Redirects())->handle([ 'enabled' => false ]);
        $this->assertSame('rankmath', $list['provider']);
        $this->assertSame(1, $list['total']);
        $this->assertCount(1, $list['redirects']);
    }

    public function test_a_target_post_id_becomes_its_permalink_path_for_a_plugin(): void
    {
        $this->redirection_on();
        $post = self::factory()->post->create([ 'post_status' => 'publish', 'post_name' => 'target-930' ]);

        $out = (new Create_Redirect())->handle([ 'source' => '/old-930', 'target_post_id' => $post ]);
        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $row = wpmcp_test_redirection_row((int) $out['redirect']['id']);
        $this->assertSame(wp_make_link_relative((string) get_permalink($post)), $row['action_data']);
        $this->assertNotEmpty($out['note']);
    }
}
