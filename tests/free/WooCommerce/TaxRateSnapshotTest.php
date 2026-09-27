<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

/**
 * The 'wc_tax_rate' snapshot object type (issue #195).
 *
 * A tax rate is a woocommerce_tax_rates row plus postcode and city rows in
 * woocommerce_tax_rate_locations: not a post, so it needs its own capture and
 * restore. These tests pin the three promises that matter: an in-place edit
 * is undone exactly, a deleted rate comes back at its ORIGINAL id (past
 * orders reference it), and its locations come back with it.
 */
class TaxRateSnapshotTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function rate(): int
    {
        $id = (int) \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => 'GB',
            'tax_rate_state'    => '',
            'tax_rate'          => '20.0000',
            'tax_rate_name'     => 'VAT',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order'    => 0,
            'tax_rate_class'    => '',
        ]);
        \WC_Tax::_update_tax_rate_postcodes($id, ['SW1A 1AA', 'EC1*']);
        \WC_Tax::_update_tax_rate_cities($id, ['LONDON']);
        return $id;
    }

    public function test_capture_records_the_row_and_its_locations(): void
    {
        $id       = $this->rate();
        $snapshot = Snapshot::capture('wc_tax_rate', $id);

        $this->assertSame('wc_tax_rate', $snapshot['object_type']);
        $this->assertSame($id, $snapshot['object_id']);
        $this->assertSame('20.0000', $snapshot['data']['rate']['tax_rate']);
        $this->assertSame('VAT', $snapshot['data']['rate']['tax_rate_name']);
        $this->assertSame(['SW1A 1AA', 'EC1*'], $snapshot['data']['postcodes']);
        $this->assertSame(['LONDON'], $snapshot['data']['cities']);
    }

    public function test_capture_of_a_missing_rate_records_null(): void
    {
        $snapshot = Snapshot::capture('wc_tax_rate', 987654);

        $this->assertNull($snapshot['data']['rate']);
        $this->assertSame([], $snapshot['data']['postcodes']);
    }

    public function test_restore_undoes_an_in_place_edit_exactly(): void
    {
        $id       = $this->rate();
        $snapshot = Snapshot::capture('wc_tax_rate', $id);

        \WC_Tax::_update_tax_rate($id, ['tax_rate' => '5', 'tax_rate_name' => 'Reduced', 'tax_rate_shipping' => 0]);
        \WC_Tax::_update_tax_rate_postcodes($id, ['M1 1AA']);
        \WC_Tax::_update_tax_rate_cities($id, []);

        Rollback_Service::apply_snapshot($snapshot);

        $after = Snapshot::capture('wc_tax_rate', $id);
        $this->assertSame($snapshot['data'], $after['data']);
    }

    public function test_restore_resurrects_a_deleted_rate_at_its_original_id_with_locations(): void
    {
        $id       = $this->rate();
        $snapshot = Snapshot::capture('wc_tax_rate', $id);

        \WC_Tax::_delete_tax_rate($id);
        $this->assertNull(Snapshot::capture('wc_tax_rate', $id)['data']['rate']);

        Rollback_Service::apply_snapshot($snapshot);

        $after = Snapshot::capture('wc_tax_rate', $id);
        $this->assertSame($snapshot['data'], $after['data']);
        $this->assertSame((string) $id, (string) $after['data']['rate']['tax_rate_id']);
    }

    public function test_restore_is_refused_without_manage_woocommerce(): void
    {
        $id       = $this->rate();
        $snapshot = Snapshot::capture('wc_tax_rate', $id);
        \WC_Tax::_update_tax_rate($id, ['tax_rate' => '5']);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        try {
            Rollback_Service::apply_snapshot($snapshot);
            $this->fail('A restore by a user without manage_woocommerce must be refused.');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('manage_woocommerce', $e->getMessage());
        }
        $this->assertSame('5.0000', Snapshot::capture('wc_tax_rate', $id)['data']['rate']['tax_rate']);
    }
}
