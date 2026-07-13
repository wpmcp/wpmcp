<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Tools\Governance\Update_Governance_Settings;

class UpdateGovernanceSettingsTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_applies_entries_across_all_three_dimensions(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'ability'   => ['wpmcp/delete-post' => false],
            'domain'    => ['database' => false],
            'operation' => ['delete' => false],
        ]);

        $this->assertSame(['wpmcp/delete-post' => false], $out['updated']['ability']);
        $this->assertSame(['database' => false], $out['updated']['domain']);
        $this->assertSame(['delete' => false], $out['updated']['operation']);
        $this->assertSame([], $out['skipped']);

        $this->assertFalse(Governance::ability_toggles()['wpmcp/delete-post']);
        $this->assertFalse(Governance::domain_toggles()['database']);
        $this->assertFalse(Governance::operation_toggles()['delete']);
    }

    public function test_throws_when_entirely_empty_input_is_given(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Update_Governance_Settings())->handle([]);
    }

    public function test_skips_non_boolean_entries_without_throwing(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'ability' => ['wpmcp/delete-post' => 'not-a-bool'],
        ]);

        $this->assertSame([], $out['updated']['ability']);
        $this->assertCount(1, $out['skipped']);
        $this->assertSame('wpmcp/delete-post', $out['skipped'][0]['key']);
    }

    public function test_skips_entries_for_an_unknown_dimension(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'unknown_dimension' => ['x' => false],
        ]);

        $this->assertSame([], $out['updated']['ability'] ?? []);
        $this->assertNotEmpty($out['skipped']);
    }
}
