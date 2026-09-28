<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance_Audit_Log;

/**
 * wpmcp_governance_audit_log stores every permission-check outcome recorded
 * via Governance_Audit_Log::record(): ability name, the active identity (or
 * the literal 'none'), the allow/deny outcome, and a timestamp. The log is
 * capped at Governance_Audit_Log::CAP entries, oldest-first eviction, so a
 * long-lived site never grows the option unbounded.
 */
class GovernanceAuditLogTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Governance_Audit_Log::OPTION);
        Governance_Audit_Log::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        Governance_Audit_Log::set_clock_for_tests(null);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    public function test_record_appends_an_entry_with_the_given_fields(): void
    {
        Governance_Audit_Log::set_clock_for_tests(1700000000);

        Governance_Audit_Log::record('wpmcp/get-post', 'none', true);

        $entries = Governance_Audit_Log::list();

        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/get-post', $entries[0]['ability']);
        $this->assertSame('none', $entries[0]['identity']);
        $this->assertTrue($entries[0]['allowed']);
        $this->assertSame(1700000000, $entries[0]['timestamp']);
    }

    /**
     * record_quietly() is the one-liner tool handlers use: it fills in the
     * active identity ('none' when there is none) and never lets an audit
     * failure break the outcome it observes.
     */
    public function test_record_quietly_fills_in_the_identity_and_the_reason(): void
    {
        \WPMCP\Identity\Identity_Context::set_current_for_tests(null);

        Governance_Audit_Log::record_quietly('wpmcp/example', false, 'some-reason');

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame('wpmcp/example', $entry['ability']);
        $this->assertSame('none', $entry['identity']);
        $this->assertFalse($entry['allowed']);
        $this->assertSame('some-reason', $entry['reason']);
    }

    public function test_record_quietly_swallows_a_failing_write(): void
    {
        $boom = static function () {
            throw new \RuntimeException('storage is down');
        };
        add_filter('pre_update_option_' . Governance_Audit_Log::OPTION, $boom);

        try {
            Governance_Audit_Log::record_quietly('wpmcp/example', true);
            $this->addToAssertionCount(1);
        } finally {
            remove_filter('pre_update_option_' . Governance_Audit_Log::OPTION, $boom);
        }
    }

    public function test_list_returns_newest_first(): void
    {
        Governance_Audit_Log::set_clock_for_tests(1000);
        Governance_Audit_Log::record('wpmcp/get-post', 'none', true);

        Governance_Audit_Log::set_clock_for_tests(2000);
        Governance_Audit_Log::record('wpmcp/delete-post', 'none', false);

        $entries = Governance_Audit_Log::list();

        $this->assertSame('wpmcp/delete-post', $entries[0]['ability']);
        $this->assertSame('wpmcp/get-post', $entries[1]['ability']);
    }

    public function test_list_respects_a_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Governance_Audit_Log::record("wpmcp/ability-{$i}", 'none', true);
        }

        $this->assertCount(2, Governance_Audit_Log::list(2));
    }

    public function test_log_rotates_out_the_oldest_entries_beyond_the_cap(): void
    {
        for ($i = 0; $i < Governance_Audit_Log::CAP + 10; $i++) {
            Governance_Audit_Log::record("wpmcp/ability-{$i}", 'none', true);
        }

        $entries = Governance_Audit_Log::list(Governance_Audit_Log::CAP + 10);

        $this->assertCount(Governance_Audit_Log::CAP, $entries);
        // Newest-first, so the very first (most recent) entry should be the
        // last one recorded, and the oldest ones should have been evicted.
        $this->assertSame('wpmcp/ability-' . (Governance_Audit_Log::CAP + 9), $entries[0]['ability']);
    }
}
