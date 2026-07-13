<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Governance\List_Governance_Audit_Log;

class ListGovernanceAuditLogTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Governance_Audit_Log::OPTION);
        Governance_Audit_Log::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        delete_option(Governance_Audit_Log::OPTION);
        Governance_Audit_Log::set_clock_for_tests(null);
        parent::tearDown();
    }

    public function test_returns_an_empty_log_with_no_entries(): void
    {
        $out = (new List_Governance_Audit_Log())->handle([]);

        $this->assertSame(['entries' => []], $out);
    }

    public function test_returns_entries_newest_first_with_a_default_limit(): void
    {
        Governance_Audit_Log::record('wpmcp/get-post', 'none', true);
        Governance_Audit_Log::record('wpmcp/delete-post', 'none', false);

        $out = (new List_Governance_Audit_Log())->handle([]);

        $this->assertSame('wpmcp/delete-post', $out['entries'][0]['ability']);
        $this->assertSame('wpmcp/get-post', $out['entries'][1]['ability']);
    }

    public function test_respects_a_limit_argument(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Governance_Audit_Log::record("wpmcp/ability-{$i}", 'none', true);
        }

        $out = (new List_Governance_Audit_Log())->handle(['limit' => 2]);

        $this->assertCount(2, $out['entries']);
    }
}
