<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * System status and tools (issue #292, fourth slice) as ops on the existing
 * woo-read and woo-write dispatchers. system-status.get returns the
 * WooCommerce system status report by section with secret-like values
 * masked. system-status.tools lists the status tools and says which ones
 * wpmcp will run; system-status.run-tool runs only allowlisted maintenance
 * tools, needs confirm:true for those that delete data, and says plainly
 * that none of them can be rolled back, and why.
 */
class WooSystemStatusTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // The environment section pings two remote hosts unless a cached
        // answer exists; never leave the test box.
        add_filter('pre_http_request', [ $this, 'no_http' ], 10, 3);

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_filter('pre_http_request', [ $this, 'no_http' ], 10);
        remove_all_filters('woocommerce_rest_prepare_system_status');
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function no_http($pre, $args, $url): array
    {
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a tool run writes no snapshot.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_system_status_ops_on_the_existing_dispatchers(): void
    {
        $ops    = Op_Catalog::ops();
        $expect = [
            'system-status.get'      => ['read', 'GET'],
            'system-status.tools'    => ['read', 'GET'],
            'system-status.run-tool' => ['write', 'POST'],
        ];
        foreach ($expect as $op => [$mode, $method]) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('system-status', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
            $this->assertSame($method, $ops[ $op ]['method'], $op);
            $this->assertNull($ops[ $op ]['snapshot'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
        }
        $this->assertFalse($ops['system-status.run-tool']['recoverable'], 'Tool runs are not recoverable');
    }

    // -------------------------------------------------------------- report

    public function test_get_returns_the_report_sections_without_secrets(): void
    {
        add_filter('woocommerce_rest_prepare_system_status', static function ($response) {
            $data                                       = $response->get_data();
            $data['settings']['payments_secret_key']    = 'sk_live_LEAKED';
            $data['environment']['license_token']       = 'tok_LEAKED';
            $data['settings']['nested']['api_password'] = 'pw_LEAKED';
            $response->set_data($data);
            return $response;
        });

        $out = $this->read('system-status.get');
        $this->assertSame(200, $out['status'], wp_json_encode($out));

        $body = $out['body'];
        foreach (['environment', 'database', 'active_plugins', 'theme', 'settings'] as $section) {
            $this->assertArrayHasKey($section, $body, $section);
        }
        $this->assertSame(WC()->version, $body['environment']['version']);
        $this->assertSame(get_woocommerce_currency(), $body['settings']['currency']);
        $this->assertArrayHasKey('wc_database_version', $body['database']);
        $this->assertArrayHasKey('name', $body['theme']);

        $json = (string) wp_json_encode($out);
        foreach (['sk_live_LEAKED', 'tok_LEAKED', 'pw_LEAKED'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('[redacted]', $body['settings']['payments_secret_key']);
    }

    public function test_get_takes_a_section_list_and_refuses_unknown_sections(): void
    {
        $out = $this->read('system-status.get', ['sections' => ['theme', 'security']]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertEqualsCanonicalizing(['theme', 'security'], array_keys($out['body']));

        foreach ([['sections' => ['theme', 'passwords']], ['sections' => 'theme'], ['sections' => []], ['color' => 'red']] as $params) {
            $bad = $this->read('system-status.get', $params);
            $this->assertSame('invalid_params', $bad['error']['code'] ?? null, wp_json_encode($params) . ' => ' . wp_json_encode($bad));
        }
    }

    // --------------------------------------------------------------- tools

    public function test_tools_lists_what_can_run_what_needs_confirm_and_that_nothing_rolls_back(): void
    {
        $out = $this->read('system-status.tools');
        $this->assertSame(200, $out['status'], wp_json_encode($out));

        $rows = [];
        foreach ($out['body']['tools'] as $row) {
            $rows[ $row['id'] ] = $row;
        }

        $this->assertTrue($rows['clear_transients']['runnable']);
        $this->assertFalse($rows['clear_transients']['requires_confirm']);
        $this->assertTrue($rows['regenerate_product_lookup_tables']['runnable']);
        $this->assertTrue($rows['recount_terms']['runnable']);
        $this->assertTrue($rows['clear_sessions']['runnable']);
        $this->assertTrue($rows['clear_sessions']['requires_confirm']);
        $this->assertTrue($rows['delete_orphaned_variations']['requires_confirm']);
        foreach (['reset_roles', 'delete_taxes', 'install_pages'] as $refused) {
            $this->assertFalse($rows[ $refused ]['runnable'], $refused);
        }
        foreach ($rows as $id => $row) {
            $this->assertFalse($row['recoverable'], $id);
            $this->assertNotSame('', (string) $row['note'], "Tool {$id} says why");
            $this->assertStringNotContainsString('<', (string) $row['description'], 'Descriptions are plain text');
        }
    }

    public function test_run_tool_clears_transients_and_says_it_cannot_be_rolled_back(): void
    {
        set_transient('wc_count_comments', (object) ['total' => 3]);
        $ledger = $this->snapshot_count();

        $out = $this->write('system-status.run-tool', ['id' => 'clear_transients']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['body']['success']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertStringContainsString('cache', (string) $out['why_unrecoverable']);
        $this->assertFalse(get_transient('wc_count_comments'));
        $this->assertSame($ledger, $this->snapshot_count(), 'A tool run writes no snapshot');
    }

    public function test_tools_that_delete_data_need_confirm(): void
    {
        $orphan = (int) wp_insert_post([
            'post_type'   => 'product_variation',
            'post_status' => 'publish',
            'post_title'  => 'Orphan',
            'post_parent' => 987654,
        ]);

        $unconfirmed = $this->write('system-status.run-tool', ['id' => 'delete_orphaned_variations']);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null, wp_json_encode($unconfirmed));
        $this->assertNotNull(get_post($orphan));

        $unconfirmed = $this->write('system-status.run-tool', ['id' => 'clear_sessions']);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);

        $out = $this->write('system-status.run-tool', ['id' => 'delete_orphaned_variations'], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertStringContainsString('permanently', (string) $out['why_unrecoverable']);
        clean_post_cache($orphan);
        $this->assertNull(get_post($orphan));
    }

    public function test_run_tool_refuses_tools_outside_the_allowlist(): void
    {
        $role   = get_role('shop_manager')->capabilities;
        $ledger = $this->snapshot_count();

        foreach (['reset_roles', 'delete_taxes', 'install_pages', 'db_update_routine'] as $id) {
            $out = $this->write('system-status.run-tool', ['id' => $id], ['confirm' => true]);
            $this->assertSame('tool_not_allowed', $out['error']['code'] ?? null, $id . ' => ' . wp_json_encode($out));
        }
        $out = $this->write('system-status.run-tool', ['id' => 'no_such_tool']);
        $this->assertSame('unknown_tool', $out['error']['code'] ?? null, wp_json_encode($out));
        $out = $this->write('system-status.run-tool', ['id' => 'clear_transients', 'force' => true]);
        $this->assertSame('invalid_params', $out['error']['code'] ?? null, wp_json_encode($out));

        $this->assertSame($role, get_role('shop_manager')->capabilities);
        $this->assertSame($ledger, $this->snapshot_count());
    }

    public function test_status_ops_need_manage_woocommerce(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        foreach (['system-status.get', 'system-status.tools'] as $op) {
            $this->assertSame('operation_denied', $this->read($op)['error']['code'] ?? null, $op);
        }
        set_transient('wc_count_comments', (object) ['total' => 3]);
        $out = $this->write('system-status.run-tool', ['id' => 'clear_transients']);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertNotFalse(get_transient('wc_count_comments'));

        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $this->assertSame(200, $this->read('system-status.tools')['status']);
    }
}
