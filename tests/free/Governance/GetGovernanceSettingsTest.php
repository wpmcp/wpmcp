<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Tools\Governance\Get_Governance_Settings;

class GetGovernanceSettingsTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_returns_empty_maps_with_no_configuration(): void
    {
        $out = (new Get_Governance_Settings())->handle([]);

        $this->assertSame(['ability' => [], 'domain' => [], 'operation' => [], 'site_wide' => 'off'], $out);
    }

    public function test_returns_stored_toggles_across_all_three_dimensions(): void
    {
        Governance::set_ability_toggle('wpmcp/delete-post', false);
        Governance::set_domain_toggle('database', false);
        Governance::set_operation_toggle('delete', false);

        $out = (new Get_Governance_Settings())->handle([]);

        $this->assertSame(['wpmcp/delete-post' => false], $out['ability']);
        $this->assertSame(['database' => false], $out['domain']);
        $this->assertSame(['delete' => false], $out['operation']);
    }
}
