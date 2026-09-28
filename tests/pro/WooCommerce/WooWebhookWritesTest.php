<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Webhook management (issue #292, second slice) as ops on the existing
 * woo-write dispatcher, through WC_Webhook. The signing secret is never
 * returned (it is masked in every response); the delivery URL must be https
 * and pass the SSRF guard; every change is covered by a snapshot of the raw
 * webhook row, secret included, so rollback restores it exactly without the
 * secret ever being shown; a create records a creation row whose rollback
 * deletes the webhook. Delete is destructive and needs confirm.
 */
class WooWebhookWritesTest extends \WP_UnitTestCase
{
    private const SECRET = 'original-signing-secret-7f3a';

    /** Public IP literals, so the SSRF guard needs no DNS in the test run. */
    private const URL       = 'https://1.1.1.1/wpmcp-hook';
    private const OTHER_URL = 'https://8.8.8.8/other-hook';

    /** @var string[] outbound requests attempted during a test */
    private array $requests = [];

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

        // No request ever leaves the test run; WooCommerce's delivery ping is
        // answered here and recorded.
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'intercept'], 10, 3);

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_filter('pre_http_request', [$this, 'intercept'], 10);
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function intercept($pre, $args, $url): array
    {
        $this->requests[] = (string) $url;
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }

    // ------------------------------------------------------------ fixtures

    private function webhook(string $status = 'active'): int
    {
        $webhook = new \WC_Webhook();
        $webhook->set_name('Order feed');
        $webhook->set_topic('order.created');
        $webhook->set_delivery_url(self::URL);
        $webhook->set_secret(self::SECRET);
        $webhook->set_status($status);
        $webhook->set_user_id(get_current_user_id());
        $webhook->set_pending_delivery(false);
        return (int) $webhook->save();
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    private function enable_delete(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($enabled, $op) => 'webhooks.delete' === $op ? true : $enabled, 10, 2);
    }

    /** The raw webhook row, secret included, or null. */
    private function row(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test oracle reads the raw row.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id = %d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** The row plus what WooCommerce itself reads back, so stale caches show. */
    private function state(int $id): array
    {
        $row = $this->row($id);
        $wc  = null;
        if (null !== $row) {
            $webhook = wc_get_webhook($id);
            $wc      = $webhook ? [$webhook->get_status(), $webhook->get_delivery_url(), $webhook->get_secret(), $webhook->get_topic(), $webhook->get_name()] : null;
        }
        return ['row' => $row, 'wc' => $wc];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function webhook_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test oracle.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_webhooks");
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_webhook_writes_on_the_existing_dispatcher(): void
    {
        $ops    = Op_Catalog::ops();
        $expect = [
            'webhooks.create' => ['write', 'POST', 'wc_webhook_create'],
            'webhooks.update' => ['write', 'PUT', 'wc_webhook'],
            'webhooks.pause'  => ['write', 'PUT', 'wc_webhook'],
            'webhooks.delete' => ['destructive', 'DELETE', 'wc_webhook'],
        ];
        foreach ($expect as $op => [$mode, $method, $snapshot]) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('webhooks', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
            $this->assertSame($method, $ops[ $op ]['method'], $op);
            $this->assertSame($snapshot, $ops[ $op ]['snapshot']['type'] ?? null, $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
        }

        foreach (['wc_webhook', 'wc_webhook_create'] as $type) {
            $this->assertContains($type, Rollback_Service::restorable_object_types());
        }
    }

    // -------------------------------------------------------------- create

    public function test_create_masks_the_secret_and_rollback_deletes_the_webhook(): void
    {
        $count = $this->webhook_count();

        $out = $this->write('webhooks.create', [
            'name'         => 'New orders',
            'topic'        => 'order.created',
            'delivery_url' => self::URL,
            'secret'       => 'caller-chosen-secret-9c1d',
            'status'       => 'paused',
        ]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $id  = (int) $out['body']['id'];
        $row = $this->row($id);
        $this->assertSame('caller-chosen-secret-9c1d', $row['secret'], 'The secret is stored');
        $this->assertSame('[redacted]', $out['body']['secret']);
        $this->assertStringNotContainsString('caller-chosen-secret-9c1d', (string) wp_json_encode($out), 'The secret is never returned');
        $this->assertSame('paused', $row['status']);
        $this->assertSame(self::URL, $row['delivery_url']);
        $this->assertSame((string) get_current_user_id(), (string) $row['user_id'], 'A webhook delivers as the user who made it');

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertNull($this->row($id), 'Rolling back a create deletes the webhook: it is configuration, not content');
        $this->assertSame($count, $this->webhook_count());
    }

    public function test_create_without_a_secret_generates_one_and_still_never_shows_it(): void
    {
        $out = $this->write('webhooks.create', ['topic' => 'product.updated', 'delivery_url' => self::URL, 'status' => 'disabled']);
        $this->assertSame(201, $out['status'], wp_json_encode($out));

        $secret = (string) $this->row((int) $out['body']['id'])['secret'];
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $this->assertStringNotContainsString($secret, (string) wp_json_encode($out));
    }

    public function test_the_delivery_url_must_be_https_and_pass_the_ssrf_guard(): void
    {
        $count  = $this->webhook_count();
        $ledger = $this->snapshot_count();

        $refused = [
            'http://1.1.1.1/hook',
            'https://127.0.0.1/hook',
            'https://localhost/hook',
            'https://10.0.0.5/hook',
            'https://192.168.1.10/hook',
            'https://172.16.0.1/hook',
            'https://169.254.169.254/latest/meta-data',
            'https://user:pass@1.1.1.1/hook',
            'https://1.1.1.1:8443/hook',
            'https://[::1]/hook',
            'ftp://1.1.1.1/hook',
            'not a url',
        ];
        foreach ($refused as $url) {
            $out = $this->write('webhooks.create', ['topic' => 'order.created', 'delivery_url' => $url]);
            $this->assertSame('invalid_delivery_url', $out['error']['code'] ?? null, $url . ' => ' . wp_json_encode($out));
        }

        $hook = $this->webhook();
        $before = $this->state($hook);
        $ledger_after_fixture = $this->snapshot_count();
        $out = $this->write('webhooks.update', ['id' => $hook, 'delivery_url' => 'https://10.1.2.3/steal']);
        $this->assertSame('invalid_delivery_url', $out['error']['code'] ?? null);
        $this->assertSame($before, $this->state($hook));

        $this->assertSame($count + 1, $this->webhook_count());
        $this->assertSame($ledger, $ledger_after_fixture);
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
        $this->assertSame([], $this->requests, 'Nothing was fetched');
    }

    public function test_create_and_update_refuse_bad_topics_statuses_owners_and_unknown_params(): void
    {
        $hook   = $this->webhook();
        $before = $this->state($hook);
        $count  = $this->webhook_count();
        $ledger = $this->snapshot_count();

        $cases = [
            ['webhooks.create', ['delivery_url' => self::URL], 'invalid_params'],
            ['webhooks.create', ['topic' => 'order.exploded', 'delivery_url' => self::URL], 'invalid_params'],
            ['webhooks.create', ['topic' => 'action.woocommerce_checkout_order_processed', 'delivery_url' => self::URL], 'invalid_params'],
            ['webhooks.create', ['topic' => 'order.created', 'delivery_url' => self::URL, 'status' => 'on'], 'invalid_params'],
            ['webhooks.create', ['topic' => 'order.created', 'delivery_url' => self::URL, 'user_id' => 1], 'forbidden_param'],
            ['webhooks.create', ['topic' => 'order.created', 'delivery_url' => self::URL, 'api_version' => 'legacy_v3'], 'invalid_params'],
            ['webhooks.update', ['id' => $hook], 'invalid_params'],
            ['webhooks.update', ['id' => 999999, 'name' => 'x'], 'unknown_webhook'],
            ['webhooks.update', ['id' => $hook, 'user_id' => 1], 'forbidden_param'],
            ['webhooks.update', ['id' => $hook, 'failure_count' => 0], 'invalid_params'],
            ['webhooks.pause', ['id' => 999999], 'unknown_webhook'],
        ];
        foreach ($cases as [$op, $params, $code]) {
            $out = $this->write($op, $params);
            $this->assertSame($code, $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }

        $this->assertSame($before, $this->state($hook));
        $this->assertSame($count, $this->webhook_count());
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
    }

    // -------------------------------------------------------------- update

    public function test_update_rolls_back_exactly_secret_included_without_ever_showing_it(): void
    {
        $hook   = $this->webhook('paused');
        $before = $this->state($hook);

        $out = $this->write('webhooks.update', [
            'id'           => $hook,
            'name'         => 'Renamed feed',
            'topic'        => 'order.updated',
            'delivery_url' => self::OTHER_URL,
            'secret'       => 'rotated-secret-2b8e',
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('Renamed feed', $out['body']['name']);
        $this->assertSame('[redacted]', $out['body']['secret']);
        $this->assertSame('rotated-secret-2b8e', $this->row($hook)['secret']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('rotated-secret-2b8e', $json);
        $this->assertStringNotContainsString(self::SECRET, $json);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($rolled), 'The rollback result never shows the secret either');
        $this->assertSame($before, $this->state($hook));
        $this->assertSame(self::SECRET, $this->row($hook)['secret']);
    }

    public function test_pause_sets_paused_and_rolls_back_to_active(): void
    {
        $hook   = $this->webhook('active');
        $before = $this->state($hook);

        $out = $this->write('webhooks.pause', ['id' => $hook]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('paused', $out['body']['status']);
        $this->assertSame('paused', wc_get_webhook($hook)->get_status());

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($hook));
        $this->assertSame('active', wc_get_webhook($hook)->get_status());
    }

    // -------------------------------------------------------------- delete

    public function test_delete_is_destructive_and_rollback_resurrects_the_same_row(): void
    {
        $hook   = $this->webhook();
        $before = $this->state($hook);

        $off = $this->write('webhooks.delete', ['id' => $hook], ['confirm' => true]);
        $this->assertSame('operation_disabled', $off['error']['code'] ?? null);

        $this->enable_delete();
        $unconfirmed = $this->write('webhooks.delete', ['id' => $hook]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertSame($before, $this->state($hook));

        $out = $this->write('webhooks.delete', ['id' => $hook], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($out));
        $this->assertNull($this->row($hook));

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame($before, $this->state($hook), 'The same id, secret and dates come back');
    }

    // ------------------------------------------------------ gates, sessions

    public function test_webhook_writes_need_manage_woocommerce(): void
    {
        $hook = $this->webhook();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $outs = [
            $this->write('webhooks.create', ['topic' => 'order.created', 'delivery_url' => self::URL]),
            $this->write('webhooks.update', ['id' => $hook, 'name' => 'x']),
            $this->write('webhooks.pause', ['id' => $hook]),
        ];
        foreach ($outs as $out) {
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, wp_json_encode($out));
            $this->assertSame('capability', $out['error']['data']['reason'] ?? null);
        }
    }

    public function test_a_webhook_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $hook   = $this->webhook();
        $before = $this->state($hook);
        $out    = $this->write('webhooks.update', ['id' => $hook, 'name' => 'Edited']);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);
        $this->assertSame('Edited', wc_get_webhook($hook)->get_name());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($hook));
    }

    public function test_a_session_that_created_then_edited_a_webhook_unwinds_to_nothing(): void
    {
        $session = wp_generate_uuid4();
        $count   = $this->webhook_count();

        $made = $this->write('webhooks.create', ['topic' => 'coupon.created', 'delivery_url' => self::URL, 'status' => 'paused'], ['session_id' => $session]);
        $this->assertSame(201, $made['status'], wp_json_encode($made));
        $id = (int) $made['body']['id'];

        $edit = $this->write('webhooks.update', ['id' => $id, 'name' => 'Coupons'], ['session_id' => $session]);
        $this->assertSame(200, $edit['status'], wp_json_encode($edit));

        (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertNull($this->row($id));
        $this->assertSame($count, $this->webhook_count());
    }
}
