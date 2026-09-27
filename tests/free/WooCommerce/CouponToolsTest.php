<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Create_Coupon;
use WPMCP\Tools\WooCommerce\Delete_Coupon;
use WPMCP\Tools\WooCommerce\Get_Coupon;
use WPMCP\Tools\WooCommerce\List_Coupons;
use WPMCP\Tools\WooCommerce\Update_Coupon;
use WPMCP\Tools\WooCommerce\Validate_Coupon;

/**
 * Coupon tools (issue #195). A coupon is a shop_coupon post whose settings
 * are postmeta, so writes ride the 'post' snapshot type; the rollback tests
 * here prove that covers every setting, and that WooCommerce's code-to-id
 * cache follows a rolled-back code change.
 */
class CouponToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wpmcp_enable_delete_coupon', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_coupon', '__return_true');
        parent::tearDown();
    }

    private function create(array $args = []): array
    {
        return (new Create_Coupon())->handle(array_merge([
            'code'          => 'SPRING10',
            'discount_type' => 'percent',
            'amount'        => '10',
            'status'        => 'publish',
        ], $args));
    }

    public function test_create_defaults_to_draft_and_names_its_undo(): void
    {
        $out = (new Create_Coupon())->handle(['code' => 'Quiet Launch', 'amount' => 5]);

        $this->assertSame('quiet launch', $out['code']);
        $this->assertSame('draft', $out['status']);
        $this->assertSame('fixed_cart', $out['discount_type']);
        $this->assertSame('5', $out['amount']);
        $this->assertFalse($out['recoverable']);
        $this->assertSame('delete-coupon', $out['undo']);
    }

    public function test_create_refuses_duplicate_codes_even_against_drafts(): void
    {
        (new Create_Coupon())->handle(['code' => 'dupe']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used');
        (new Create_Coupon())->handle(['code' => 'DUPE']);
    }

    public function test_create_refuses_inputs_woocommerce_would_coerce(): void
    {
        foreach (
            [
            ['discount_type' => 'bogus'],
            ['amount' => '150'],
            ['amount' => '-5', 'discount_type' => 'fixed_cart'],
            ['email_restrictions' => ['not-an-email']],
            ['usage_limit' => -1],
            ['product_ids' => ['abc']],
            ['date_expires' => 'not a date'],
            ['status' => 'trash'],
            ] as $i => $bad
        ) {
            try {
                $this->create(array_merge(['code' => 'bad-' . $i], $bad));
                $this->fail('Expected a refusal for ' . wp_json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, (new List_Coupons())->handle([])['total']);
    }

    public function test_list_and_get_by_code(): void
    {
        $made = $this->create(['usage_limit' => 100, 'date_expires' => '2030-01-31']);

        $list = (new List_Coupons())->handle(['search' => 'spring']);
        $this->assertSame(1, $list['total']);
        $this->assertSame($made['id'], $list['coupons'][0]['id']);

        $got = (new Get_Coupon())->handle(['code' => 'SPRING10']);
        $this->assertSame($made['id'], $got['id']);
        $this->assertSame(100, $got['usage_limit']);
        $this->assertStringStartsWith('2030-01-31', (string) $got['date_expires']);
    }

    public function test_update_rolls_back_every_setting_and_the_code_lookup(): void
    {
        $made = $this->create([
            'minimum_amount'     => '50',
            'email_restrictions' => ['*@example.com'],
            'usage_limit'        => 10,
        ]);

        $out = (new Update_Coupon())->handle([
            'id'                 => $made['id'],
            'code'               => 'SUMMER25',
            'amount'             => '25',
            'minimum_amount'     => '',
            'email_restrictions' => [],
            'usage_limit'        => 0,
            'free_shipping'      => true,
        ]);
        $this->assertSame('summer25', $out['code']);
        $this->assertSame($made['id'], wc_get_coupon_id_by_code('summer25'));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = (new Get_Coupon())->handle(['id' => $made['id']]);
        $this->assertSame('spring10', $restored['code']);
        $this->assertSame('10', $restored['amount']);
        $this->assertSame('50', $restored['minimum_amount']);
        $this->assertSame(['*@example.com'], $restored['email_restrictions']);
        $this->assertSame(10, $restored['usage_limit']);
        $this->assertFalse($restored['free_shipping']);

        // Checkout resolves codes through a cache keyed by code: the old code
        // must find the coupon again and the rolled-back one must not.
        $this->assertSame($made['id'], wc_get_coupon_id_by_code('spring10'));
        $this->assertSame(0, wc_get_coupon_id_by_code('summer25'));
    }

    public function test_update_refuses_taking_another_coupons_code(): void
    {
        $this->create();
        $other = $this->create(['code' => 'OTHER']);

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Coupon())->handle(['id' => $other['id'], 'code' => 'spring10']);
    }

    public function test_delete_is_gated_and_needs_confirm(): void
    {
        $made = $this->create();

        remove_filter('wpmcp_enable_delete_coupon', '__return_true');
        try {
            (new Delete_Coupon())->handle(['id' => $made['id'], 'confirm' => true]);
            $this->fail('delete-coupon must be disabled by default.');
        } catch (\RuntimeException $e) {
            $this->assertSame('publish', get_post_status($made['id']));
        }
        add_filter('wpmcp_enable_delete_coupon', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Coupon())->handle(['id' => $made['id']]);
    }

    public function test_trash_and_force_delete_both_roll_back(): void
    {
        $made = $this->create(['usage_limit' => 7]);

        $trash = (new Delete_Coupon())->handle(['id' => $made['id'], 'confirm' => true]);
        $this->assertSame('trashed', $trash['deleted']);
        $this->assertSame('trash', get_post_status($made['id']));
        (new Rollback_Operation())->handle(['operation_id' => $trash['operation_id']]);
        $this->assertSame('publish', get_post_status($made['id']));

        $force = (new Delete_Coupon())->handle(['id' => $made['id'], 'confirm' => true, 'force' => true]);
        $this->assertNull(get_post($made['id']));
        (new Rollback_Operation())->handle(['operation_id' => $force['operation_id']]);

        $restored = (new Get_Coupon())->handle(['id' => $made['id']]);
        $this->assertSame('spring10', $restored['code']);
        $this->assertSame(7, $restored['usage_limit']);
        $this->assertSame($made['id'], wc_get_coupon_id_by_code('spring10'));
    }

    public function test_validate_reports_each_rule(): void
    {
        $this->create([
            'minimum_amount'       => '50',
            'usage_limit_per_user' => 1,
            'email_restrictions'   => ['*@example.com'],
        ]);

        $bare = (new Validate_Coupon())->handle(['code' => 'spring10']);
        $this->assertTrue($bare['valid']);
        $by_check = array_column($bare['checks'], 'status', 'check');
        $this->assertSame('pass', $by_check['published']);
        $this->assertSame('skipped', $by_check['spend']);
        $this->assertSame('skipped', $by_check['email_restrictions']);

        $low = (new Validate_Coupon())->handle(['code' => 'spring10', 'subtotal' => '20', 'email' => 'a@elsewhere.test']);
        $this->assertFalse($low['valid']);
        $by_check = array_column($low['checks'], 'status', 'check');
        $this->assertSame('fail', $by_check['spend']);
        $this->assertSame('fail', $by_check['email_restrictions']);

        $ok = (new Validate_Coupon())->handle(['code' => 'spring10', 'subtotal' => 80, 'email' => 'Buyer@Example.com']);
        $this->assertTrue($ok['valid']);
    }

    public function test_validate_flags_drafts_expiry_and_unknown_codes(): void
    {
        $this->create(['code' => 'draft-one', 'status' => 'draft']);
        $this->create(['code' => 'old-one', 'date_expires' => '2001-01-01']);

        $this->assertFalse((new Validate_Coupon())->handle(['code' => 'draft-one'])['valid']);
        $old = (new Validate_Coupon())->handle(['code' => 'old-one']);
        $this->assertFalse($old['valid']);
        $this->assertSame('fail', array_column($old['checks'], 'status', 'check')['expiry']);

        $missing = (new Validate_Coupon())->handle(['code' => 'nope']);
        $this->assertFalse($missing['valid']);
        $this->assertNull($missing['id']);
    }
}
