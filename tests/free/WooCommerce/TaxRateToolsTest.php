<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Create_Tax_Rate;
use WPMCP\Tools\WooCommerce\Delete_Tax_Rate;
use WPMCP\Tools\WooCommerce\List_Tax_Rates;
use WPMCP\Tools\WooCommerce\Update_Tax_Rate;

class TaxRateToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        // The test install does not run WooCommerce's role setup, so the
        // administrator role lacks the store capabilities the tools require.
        // Installing the roles here keeps this class passing on its own, not
        // only after another test happened to install them.
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        $user->add_cap('manage_woocommerce');
        wp_set_current_user($user->ID);
        add_filter('wpmcp_enable_delete_tax_rate', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_tax_rate', '__return_true');
        parent::tearDown();
    }

    private function create(array $args = []): array
    {
        return (new Create_Tax_Rate())->handle(array_merge([
            'country'   => 'US',
            'state'     => 'CA',
            'rate'      => '7.25',
            'name'      => 'CA Sales Tax',
            'postcodes' => ['90210', '941*'],
            'cities'    => ['Los Angeles'],
        ], $args));
    }

    public function test_create_returns_the_rate_and_names_its_undo(): void
    {
        $out = $this->create();

        $this->assertGreaterThan(0, $out['id']);
        $this->assertSame('US', $out['country']);
        $this->assertSame('CA', $out['state']);
        $this->assertSame('7.2500', $out['rate']);
        $this->assertSame(['90210', '941*'], $out['postcodes']);
        $this->assertSame(['LOS ANGELES'], $out['cities']);
        $this->assertSame('standard', $out['class']);
        $this->assertIsString($out['operation_id']);
        $this->assertArrayNotHasKey('recoverable', $out);
    }

    /** Issue #761: create-tax-rate returns an undo point, and undoing it deletes the rate. */
    public function test_create_returns_an_undo_point_that_deletes_the_rate(): void
    {
        $out = $this->create();
        $this->assertNotEmpty($out['operation_id']);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored'], implode(' ', $rolled_back['warnings']));

        $this->assertEmpty(\WC_Tax::_get_tax_rate($out['id']));
        $this->assertSame([], (new List_Tax_Rates())->handle(['country' => 'US'])['rates'] ?? []);
    }

    /** Issue #761: a rate edited since its creation is kept, with a warning, and the undo stays retryable. */
    public function test_undo_of_a_create_refuses_a_rate_changed_since(): void
    {
        $out = $this->create();
        $update = (new Update_Tax_Rate())->handle(['id' => $out['id'], 'rate' => '8']);

        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);
        $this->assertStringContainsString('changed since create-tax-rate', implode(' ', $refused['warnings']));
        $this->assertNotEmpty(\WC_Tax::_get_tax_rate($out['id']));

        // Once the edit is undone the rate is as the call left it again, and
        // the creation's undo goes through.
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $update['operation_id']])['restored']);
        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored'], implode(' ', $rolled_back['warnings']));
        $this->assertEmpty(\WC_Tax::_get_tax_rate($out['id']));
    }

    /** Issue #761: rollback-session over a create then an edit lands on "no rate". */
    public function test_rollback_session_undoes_a_create_and_its_edit(): void
    {
        $out = $this->create(['session_id' => 'tax-761']);
        (new Update_Tax_Rate())->handle(['id' => $out['id'], 'rate' => '8', 'postcodes' => ['10001'], 'session_id' => 'tax-761']);

        $result = (new Rollback_Session())->handle(['session_id' => 'tax-761']);
        $this->assertEmpty(\WC_Tax::_get_tax_rate($out['id']), implode(' ', (array) ($result['warnings'] ?? [])));
    }

    public function test_create_refuses_what_woocommerce_would_silently_coerce(): void
    {
        foreach (
            [
            ['rate' => 'abc'],
            ['rate' => '-1'],
            ['rate' => '150'],
            ['country' => 'ZZ'],
            ['class' => 'no-such-class'],
            ['priority' => 0],
            ['postcodes' => '90210'],
            ] as $bad
        ) {
            try {
                $this->create($bad);
                $this->fail('Expected a refusal for ' . wp_json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_list_reports_classes_and_filters_by_country(): void
    {
        $us = $this->create();
        $gb = $this->create(['country' => 'GB', 'state' => '', 'name' => 'VAT', 'rate' => '20', 'postcodes' => [], 'cities' => []]);

        $out = (new List_Tax_Rates())->handle(['country' => 'GB']);

        $this->assertSame('standard', $out['classes'][0]['slug']);
        $ids = array_column($out['rates'], 'id');
        $this->assertContains($gb['id'], $ids);
        $this->assertNotContains($us['id'], $ids);
    }

    public function test_update_is_snapshotted_and_rolls_back_row_and_locations(): void
    {
        $rate = $this->create();

        $out = (new Update_Tax_Rate())->handle([
            'id'        => $rate['id'],
            'rate'      => '9',
            'postcodes' => [],
            'shipping'  => false,
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('9.0000', $out['rate']);
        $this->assertSame([], $out['postcodes']);
        $this->assertFalse($out['shipping']);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $list = (new List_Tax_Rates())->handle(['country' => 'US']);
        $row  = array_values(array_filter($list['rates'], static fn ($r) => $r['id'] === $rate['id']))[0];
        $this->assertSame('7.2500', $row['rate']);
        $this->assertSame(['90210', '941*'], $row['postcodes']);
        $this->assertTrue($row['shipping']);
    }

    public function test_update_refuses_an_empty_change_and_a_missing_rate(): void
    {
        $rate = $this->create();

        try {
            (new Update_Tax_Rate())->handle(['id' => $rate['id']]);
            $this->fail('An update with no fields must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing to update', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        (new Update_Tax_Rate())->handle(['id' => 987654, 'rate' => '1']);
    }

    public function test_delete_is_gated_needs_confirm_and_rolls_back_to_the_same_id(): void
    {
        $rate = $this->create();

        remove_filter('wpmcp_enable_delete_tax_rate', '__return_true');
        try {
            (new Delete_Tax_Rate())->handle(['id' => $rate['id'], 'confirm' => true]);
            $this->fail('delete-tax-rate must be disabled by default.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wpmcp_enable_delete_tax_rate', $e->getMessage());
        }
        add_filter('wpmcp_enable_delete_tax_rate', '__return_true');

        try {
            (new Delete_Tax_Rate())->handle(['id' => $rate['id']]);
            $this->fail('delete-tax-rate must require confirm:true.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm', $e->getMessage());
        }

        $out = (new Delete_Tax_Rate())->handle(['id' => $rate['id'], 'confirm' => true]);
        $this->assertTrue($out['recoverable']);
        $this->assertNull(\WC_Tax::_get_tax_rate($rate['id']));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = \WC_Tax::_get_tax_rate($rate['id']);
        $this->assertNotNull($restored);
        $this->assertSame('CA Sales Tax', $restored['tax_rate_name']);
        $list = (new List_Tax_Rates())->handle(['country' => 'US']);
        $row  = array_values(array_filter($list['rates'], static fn ($r) => $r['id'] === $rate['id']))[0];
        $this->assertSame(['90210', '941*'], $row['postcodes']);
        $this->assertSame(['LOS ANGELES'], $row['cities']);
    }
}
