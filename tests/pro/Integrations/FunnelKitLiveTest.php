<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #500: the FunnelKit funnel writes against the real, free Funnel
 * Builder from wordpress.org, with WooCommerce.
 *
 * It runs only in the local gate's live FunnelKit leg (bin/test-local.sh,
 * or bin/test-local.sh --live-funnelkit alone), which sets
 * WPMCP_LIVE_FUNNELKIT=1, installs WooCommerce and Funnel Builder on a
 * separate WordPress install, loads them in tests/bootstrap.php and builds
 * Funnel Builder's tables with its own routine.
 *
 * Funnels, steps and checkout products written through the ops must be
 * what FunnelKit's own classes read back (WFFN_Funnel for the funnel and
 * its steps, WFACP_Common for a checkout's products, its own
 * calculate_discount() for the price), and every rollback must put back
 * exactly the stored funnel, step posts and meta it started from.
 *
 * Everywhere else Funnel Builder is absent and the test is skipped; the
 * live leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group funnelkit-live
 */
class FunnelKitLiveTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WFFN_VERSION') || ! class_exists('WFFN_Funnel') || ! class_exists('WFACP_Common') || ! function_exists('wc_get_product')) {
            $this->markTestSkipped('Needs the real Funnel Builder and WooCommerce (bin/test-local.sh --live-funnelkit, WPMCP_LIVE_FUNNELKIT=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($this->admin)->add_cap('manage_woocommerce');
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function ok(string $op, array $args, array $extra = []): array
    {
        $out = $this->write($op, $args, $extra);
        global $wpdb;
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out) . ' last database error: ' . $wpdb->last_error);
        return $out;
    }

    private function state(int $funnel_id): array
    {
        return FunnelKit_Funnel_Snapshot::capture($funnel_id)['data'];
    }

    /** The funnel's steps as FunnelKit itself loads them. */
    private function funnelkit_steps(int $funnel_id): array
    {
        return array_map(static fn (array $step): array => [ 'type' => $step['type'], 'id' => (int) $step['id'] ], (new \WFFN_Funnel($funnel_id))->get_steps());
    }

    private function product(string $regular, string $sale = ''): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Starter kit ' . $regular);
        $product->set_regular_price($regular);
        if ('' !== $sale) {
            $product->set_sale_price($sale);
        }
        $product->set_status('publish');
        return $product->save();
    }

    public function test_a_funnel_built_through_the_ops_is_what_funnelkit_reads_and_each_step_write_rolls_back_exactly(): void
    {
        $this->assertTrue((new Theme_Integration())->is_available());

        $created = $this->ok('create-funnelkit-funnel', [ 'title' => 'Spring launch' ]);
        $id      = (int) $created['result']['funnel']['id'];
        $funnel  = new \WFFN_Funnel($id);
        $this->assertSame($id, (int) $funnel->get_id());
        $this->assertSame('Spring launch', $funnel->get_title());

        $ids = [];
        foreach ([ 'landing' => 'Welcome', 'wc_checkout' => 'Checkout', 'wc_thankyou' => 'Thanks' ] as $type => $title) {
            $ids[ $type ] = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => $type, 'title' => $title ])['result']['step_id'];
        }
        $this->assertSame([
            [ 'type' => 'landing', 'id' => $ids['landing'] ],
            [ 'type' => 'wc_checkout', 'id' => $ids['wc_checkout'] ],
            [ 'type' => 'wc_thankyou', 'id' => $ids['wc_thankyou'] ],
        ], $this->funnelkit_steps($id));
        $this->assertSame('wfacp_checkout', get_post_type($ids['wc_checkout']));
        $this->assertSame((string) $id, (string) get_post_meta($ids['wc_checkout'], '_bwf_in_funnel', true));

        $before = $this->state($id);

        // Adding a step, then rolling it back, removes the step and its page.
        $added = $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => 'optin', 'title' => 'Get the guide' ]);
        $optin = (int) $added['result']['step_id'];
        $this->assertSame('wffn_optin', get_post_type($optin));
        $this->assertTrue(Rollback_Service::restore_operation($added['operation_id']));
        $this->assertSame($before, $this->state($id));
        $this->assertNull(get_post($optin));

        // Rename keeps the description FunnelKit would otherwise clear.
        $renamed = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'description' => 'Spring sale' ]);
        $renamed = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Summer launch' ]);
        $this->assertSame('Summer launch', (new \WFFN_Funnel($id))->get_title());
        $this->assertSame('Spring sale', (new \WFFN_Funnel($id))->get_desc());

        $mid = $this->state($id);

        $reordered = $this->ok('reorder-funnelkit-steps', [ 'funnel_id' => $id, 'step_ids' => [ $ids['wc_checkout'], $ids['landing'], $ids['wc_thankyou'] ] ]);
        $this->assertSame([ $ids['wc_checkout'], $ids['landing'], $ids['wc_thankyou'] ], array_column($this->funnelkit_steps($id), 'id'));
        $this->assertTrue(Rollback_Service::restore_operation($reordered['operation_id']));
        $this->assertSame($mid, $this->state($id));

        $off = $this->ok('set-funnelkit-step-status', [ 'funnel_id' => $id, 'step_id' => $ids['landing'], 'enabled' => false ]);
        $this->assertSame('draft', get_post_status($ids['landing']));
        $published = $this->ok('publish-funnelkit-steps', [ 'funnel_id' => $id ]);
        $this->assertSame([ $ids['landing'] ], $published['result']['published']);
        $this->assertSame('publish', get_post_status($ids['landing']));
        $this->assertTrue(Rollback_Service::restore_operation($published['operation_id']));
        $this->assertSame('draft', get_post_status($ids['landing']));
        $this->assertTrue(Rollback_Service::restore_operation($off['operation_id']));
        $this->assertSame($mid, $this->state($id));

        $deleted = $this->ok('delete-funnelkit-step', [ 'funnel_id' => $id, 'step_id' => $ids['wc_thankyou'] ], [ 'confirm' => true ]);
        $this->assertNull(get_post($ids['wc_thankyou']));
        $this->assertNotContains($ids['wc_thankyou'], array_column($this->funnelkit_steps($id), 'id'));
        $this->assertTrue(Rollback_Service::restore_operation($deleted['operation_id']));
        $this->assertSame($mid, $this->state($id));
        $this->assertSame(array_values($ids), array_column($this->funnelkit_steps($id), 'id'));

        $this->assertTrue(Rollback_Service::restore_operation($renamed['operation_id']));
        $this->assertSame('Spring launch', (new \WFFN_Funnel($id))->get_title());

        // The whole funnel: delete it, bring it back, then undo its creation.
        $state = $this->state($id);
        $gone  = $this->ok('delete-funnelkit-funnel', [ 'id' => $id ], [ 'confirm' => true ]);
        $this->assertSame(0, (int) (new \WFFN_Funnel($id))->get_id());
        $this->assertNull(get_post($ids['wc_checkout']));
        $this->assertTrue(Rollback_Service::restore_operation($gone['operation_id']));
        $this->assertSame($state, $this->state($id));
        $this->assertSame(array_values($ids), array_column($this->funnelkit_steps($id), 'id'));

        $this->assertTrue(Rollback_Service::restore_operation($created['operation_ids'][0]));
        $this->assertSame(0, (int) (new \WFFN_Funnel($id))->get_id());
        $this->assertNull(get_post($ids['landing']));
    }

    public function test_checkout_products_are_stored_the_way_funnelkit_reads_them_priced_by_its_own_formula_and_roll_back_exactly(): void
    {
        $id       = (int) $this->ok('create-funnelkit-funnel', [ 'title' => 'Kit funnel' ])['result']['funnel']['id'];
        $checkout = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => 'wc_checkout', 'title' => 'Checkout' ])['result']['step_id'];
        $kit      = $this->product('100', '80');
        $case     = $this->product('30');
        $lines    = [
            [ 'product_id' => $kit, 'quantity' => 2, 'discount_type' => 'percent_discount_sale', 'discount_amount' => 20 ],
            [ 'product_id' => $case, 'discount_type' => 'fixed_discount_reg', 'discount_amount' => 5 ],
        ];
        $before = $this->state($id);

        $dry = $this->ok('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => $lines, 'dry_run' => true ]);
        $this->assertSame($before, $this->state($id));
        $preview = array_column($dry['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(64.0, $preview[ $kit ]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(128.0, $preview[ $kit ]['line_total'], 0.001);
        $this->assertEqualsWithDelta(25.0, $preview[ $case ]['line_total'], 0.001);

        $out    = $this->ok('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => $lines ]);
        $stored = array_column(\WFACP_Common::get_page_product($checkout), null, 'id');
        $this->assertSame([ $kit, $case ], array_map('intval', array_keys($stored)));
        $this->assertSame(2, (int) $stored[ $kit ]['quantity']);
        $this->assertSame('percent_discount_sale', $stored[ $kit ]['discount_type']);
        $this->assertEquals(20, $stored[ $kit ]['discount_amount']);
        $this->assertSame('fixed_discount_reg', $stored[ $case ]['discount_type']);

        $priced = array_column($out['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(\WFACP_Common::calculate_discount([ 'wfacp_product_rp' => 100, 'wfacp_product_p' => 80, 'wfacp_discount_amount' => 20, 'wfacp_discount_type' => 'percent_discount_sale' ]), $priced[ $kit ]['unit_price'], 0.001);
        $this->assertEquals($preview[ $kit ]['line_total'], $priced[ $kit ]['line_total']);

        $read = (new Theme_Integration())->handle_read([ 'operation' => 'get-funnelkit-checkout-products', 'args' => [ 'step_id' => $checkout ] ]);
        $this->assertCount(2, $read['result']['products']);

        $removed = $this->ok('remove-funnelkit-checkout-products', [ 'step_id' => $checkout, 'product_ids' => [ $case ] ], [ 'confirm' => true ]);
        $this->assertSame([ $kit ], array_map('intval', array_column(\WFACP_Common::get_page_product($checkout), 'id')));

        $this->assertTrue(Rollback_Service::restore_operation($removed['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->state($id));
        $this->assertSame([], \WFACP_Common::get_page_product($checkout));
    }

    public function test_funnelkits_own_access_check_refuses_a_shop_manager_and_nothing_is_written(): void
    {
        $id      = (int) $this->ok('create-funnelkit-funnel', [ 'title' => 'Locked' ])['result']['funnel']['id'];
        $before  = $this->state($id);
        // A store manager: manage_woocommerce, but not manage_options, the
        // capability FunnelKit's default funnel access goes by.
        $manager = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($manager)->add_cap('manage_woocommerce');
        wp_set_current_user($manager);
        $this->assertTrue(current_user_can('manage_woocommerce'));
        $this->assertFalse(current_user_can('manage_options'));

        $out = $this->write('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Taken' ]);
        $this->assertSame('funnelkit_access_denied', $out['error']['code'] ?? '', (string) wp_json_encode($out));
        $this->assertSame($before, $this->state($id));
    }

    /**
     * Issue #551: a rollback of a FunnelKit write takes the access the write
     * took, FunnelKit's own funnel write check as well as
     * manage_woocommerce. A store manager FunnelKit refuses can neither
     * delete an administrator's funnel by undoing its create nor revert its
     * content by undoing an update or a delete; an administrator can, and so
     * can a role a site grants funnel access with FunnelKit's own filter.
     */
    public function test_a_rollback_takes_funnelkits_own_access_check_as_well_as_manage_woocommerce(): void
    {
        $created = $this->ok('create-funnelkit-funnel', [ 'title' => 'Admin funnel' ]);
        $id      = (int) $created['result']['funnel']['id'];
        $landing = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => 'landing', 'title' => 'Welcome' ])['result']['step_id'];
        $renamed = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Renamed by admin' ]);
        $kept    = (int) $this->ok('create-funnelkit-funnel', [ 'title' => 'Doomed' ])['result']['funnel']['id'];
        $deleted = $this->ok('delete-funnelkit-funnel', [ 'id' => $kept ], [ 'confirm' => true ]);
        $after   = $this->state($id);

        $manager = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($manager)->add_cap('manage_woocommerce');
        wp_set_current_user($manager);
        $denied = $this->write('delete-funnelkit-funnel', [ 'id' => $id ], [ 'confirm' => true ]);
        $this->assertSame('funnelkit_access_denied', $denied['error']['code'] ?? '', (string) wp_json_encode($denied));

        $this->assertFalse(Rollback_Service::restore_operation((string) $created['operation_ids'][0]), 'A user FunnelKit refuses deleted the admin\'s funnel through rollback.');
        $this->assertStringContainsString('FunnelKit', implode(' ', Rollback_Service::take_warnings()));
        $this->assertFalse(Rollback_Service::restore_operation((string) $renamed['operation_id']), 'A user FunnelKit refuses reverted the admin\'s funnel through rollback.');
        $this->assertFalse(Rollback_Service::restore_operation((string) $deleted['operation_id']), 'A user FunnelKit refuses brought a deleted funnel back through rollback.');
        $this->assertSame($after, $this->state($id));
        $this->assertNotNull(get_post($landing));
        $this->assertNull(FunnelKit_Funnel_Snapshot::row($kept));

        // A role the site grants funnel write access through FunnelKit's own filter may undo.
        $grant = static function (array $config): array {
            $config['editor'] = [ 'menu' => [ 'read', 'write' ], 'funnel' => [ 'read', 'write' ], 'analytics' => [ 'read', 'write' ] ];
            return $config;
        };
        add_filter('wffn_user_access_capabilities', $grant);
        try {
            $this->assertTrue(Rollback_Service::restore_operation((string) $renamed['operation_id']), implode(' ', Rollback_Service::take_warnings()));
        } finally {
            remove_filter('wffn_user_access_capabilities', $grant);
        }

        // And an administrator still can.
        wp_set_current_user($this->admin);
        $this->assertTrue(Rollback_Service::restore_operation((string) $deleted['operation_id']), implode(' ', Rollback_Service::take_warnings()));
        $this->assertNotNull(FunnelKit_Funnel_Snapshot::row($kept));
        $this->assertTrue(Rollback_Service::restore_operation((string) $created['operation_ids'][0]), implode(' ', Rollback_Service::take_warnings()));
        $this->assertNull(FunnelKit_Funnel_Snapshot::row($id));
    }
}
