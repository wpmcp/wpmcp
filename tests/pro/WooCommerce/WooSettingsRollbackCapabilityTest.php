<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * A woo-write settings.update snapshots the store setting's option, and the
 * shared 'default' session holds it beside everyone else's single writes
 * (issue #502). rollback-operation and rollback-session are registered at
 * edit_posts, so restoring that option must take manage_woocommerce, the
 * capability the write took: an Author's rollback leaves a shop manager's
 * store setting in place and says why, while the shop manager can still undo
 * their own change.
 */
class WooSettingsRollbackCapabilityTest extends \WP_UnitTestCase
{
    private const OPTION = 'woocommerce_new_order_settings';

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
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** A shop manager points the new-order email at the owner; returns the woo-write response. */
    private function change_recipient(int $shop_manager): array
    {
        update_option(self::OPTION, ['enabled' => 'yes', 'recipient' => 'former-staff@example.com']);
        wp_set_current_user($shop_manager);
        $out = (new Woo_Write())->handle([
            'op'     => 'settings.update',
            'params' => ['group_id' => 'email_new_order', 'id' => 'recipient', 'value' => 'owner@example.com'],
        ]);
        $this->assertSame(200, $out['status'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('owner@example.com', $this->recipient());
        return $out;
    }

    private function recipient(): string
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return (string) (get_option(self::OPTION)['recipient'] ?? '');
    }

    private function author(): int
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $this->assertFalse(user_can($author, 'manage_woocommerce'));
        return $author;
    }

    public function test_settings_update_snapshot_declares_manage_woocommerce(): void
    {
        $out = $this->change_recipient(self::factory()->user->create(['role' => 'shop_manager']));

        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertSame('option', $row['snapshot']['object_type']);
        $this->assertSame('manage_woocommerce', $row['snapshot']['data']['restore_capability'] ?? null);
    }

    public function test_an_authors_default_session_rollback_skips_a_shop_managers_store_setting(): void
    {
        $this->change_recipient(self::factory()->user->create(['role' => 'shop_manager']));

        wp_set_current_user($this->author());
        $out = (new Rollback_Session())->handle(['session_id' => 'default']);

        $this->assertSame('owner@example.com', $this->recipient(), 'An Author rolled back a shop manager\'s store setting');
        $this->assertSame(0, $out['restored_count']);
        $this->assertNotEmpty(
            array_filter($out['warnings'], static fn (string $w): bool => false !== strpos($w, self::OPTION) && false !== strpos($w, 'skipped') && false !== strpos($w, 'manage_woocommerce')),
            'The skipped store setting is not reported: ' . wp_json_encode($out['warnings'])
        );
    }

    public function test_an_authors_operation_rollback_of_a_store_setting_is_refused_with_a_reason(): void
    {
        $out = $this->change_recipient(self::factory()->user->create(['role' => 'shop_manager']));

        wp_set_current_user($this->author());
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertFalse($rolled['restored']);
        $this->assertSame('owner@example.com', $this->recipient());
        $this->assertCount(1, $rolled['warnings']);
        $this->assertStringContainsString('manage_woocommerce', $rolled['warnings'][0]);
    }

    public function test_a_shop_manager_can_roll_back_their_own_store_setting(): void
    {
        $shop_manager = self::factory()->user->create(['role' => 'shop_manager']);
        $this->assertFalse(user_can($shop_manager, 'manage_options'));
        $out = $this->change_recipient($shop_manager);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($rolled['restored'], (string) wp_json_encode($rolled));
        $this->assertSame('former-staff@example.com', $this->recipient());
    }

    public function test_a_shop_manager_can_roll_back_their_own_store_setting_through_the_default_session(): void
    {
        $shop_manager = self::factory()->user->create(['role' => 'shop_manager']);
        $this->change_recipient($shop_manager);

        $out = (new Rollback_Session())->handle(['session_id' => 'default']);

        $this->assertGreaterThanOrEqual(1, $out['restored_count'], (string) wp_json_encode($out));
        $this->assertSame('former-staff@example.com', $this->recipient());
    }
}
