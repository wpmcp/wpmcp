<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Tools\CustomCode\Add_Custom_Js;
use WPMCP\Tools\CustomCode\Custom_Code_Renderer;
use WPMCP\Tools\CustomCode\Custom_Code_Store;

/**
 * add-custom-js (issue #63) is the XSS-class surface of this group, so what
 * is tested here is the ORDER and completeness of the guard chain, not just
 * that a happy path stores a snippet: the gate must refuse before the
 * capability check ever runs, both outcomes must be audited with a reason,
 * and closing the gate must stop RENDERING a snippet that is already stored.
 */
class AddCustomJsTest extends \WP_UnitTestCase
{
    private Add_Custom_Js $tool;

    public function set_up(): void
    {
        parent::set_up();
        $this->tool = new Add_Custom_Js();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(Governance_Audit_Log::OPTION);
    }

    public function tear_down(): void
    {
        remove_all_filters('wpmcp_allow_js_injection');
        parent::tear_down();
    }

    private function open_gate(): void
    {
        add_filter('wpmcp_allow_js_injection', '__return_true');
    }

    /** @return array<int, array<string, mixed>> */
    private function audit_rows(): array
    {
        return array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn ($row) => 'wpmcp/add-custom-js' === $row['ability']
        ));
    }

    public function test_refuses_while_the_opt_in_gate_is_closed(): void
    {
        try {
            $this->tool->handle(['js' => 'console.log(1)']);
            $this->fail('The default-off gate should have refused the write.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        $this->assertSame([], Custom_Code_Store::read());

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['allowed']);
        $this->assertSame(Add_Custom_Js::REASON_GATE_CLOSED, $rows[0]['reason']);
    }

    /**
     * The gate is checked BEFORE the capability, so a site that never opted
     * in never reveals whether the caller would otherwise have qualified.
     */
    public function test_gate_is_checked_before_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        try {
            $this->tool->handle(['js' => 'console.log(1)']);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertSame(Add_Custom_Js::REASON_GATE_CLOSED, $this->audit_rows()[0]['reason']);
        }
    }

    public function test_refuses_a_caller_without_unfiltered_html(): void
    {
        $this->open_gate();
        $deny = function ($caps, $cap) {
            return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps;
        };
        add_filter('map_meta_cap', $deny, 10, 2);

        try {
            $this->tool->handle(['js' => 'console.log(1)']);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unfiltered_html', $e->getMessage());
            $this->assertSame(Add_Custom_Js::REASON_NO_UNFILTERED_HTML, $this->audit_rows()[0]['reason']);
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }
    }

    public function test_refuses_a_script_breakout(): void
    {
        $this->open_gate();

        try {
            $this->tool->handle(['js' => 'x=1;</script><script>alert(1)']);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertSame(Add_Custom_Js::REASON_SCRIPT_BREAKOUT, $this->audit_rows()[0]['reason']);
        }

        $this->assertSame([], Custom_Code_Store::read());
    }

    public function test_stores_and_audits_an_allowed_write(): void
    {
        $this->open_gate();

        $out = $this->tool->handle(['js' => 'console.log(1)']);

        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('console.log(1)', Custom_Code_Store::read()['js']['site']);

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['allowed']);
        $this->assertSame(Add_Custom_Js::REASON_STORED, $rows[0]['reason']);
    }

    public function test_empty_js_is_audited_too(): void
    {
        $this->open_gate();

        try {
            $this->tool->handle(['js' => '   ']);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(Add_Custom_Js::REASON_EMPTY, $this->audit_rows()[0]['reason']);
        }
    }

    /**
     * The gate is not only a write gate: closing it must also stop rendering
     * a snippet that was stored while it was open, or "turn it off" would be
     * a lie for every visitor already being served the snippet.
     */
    public function test_closing_the_gate_stops_rendering_a_stored_snippet(): void
    {
        $this->open_gate();
        $this->tool->handle(['js' => 'console.log(1)']);

        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertStringContainsString('console.log(1)', (string) ob_get_clean());

        remove_all_filters('wpmcp_allow_js_injection');

        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertSame('', (string) ob_get_clean());
    }

    /**
     * Every other default-off dangerous ability is listed in Opt_In_Gates, so
     * the ability grid marks the row and refuses to write an enabling
     * governance toggle for a gate only code can open. Missing here, an admin
     * enabling this ability in the grid would get no warning and a false
     * sense of having opened the gate.
     */
    public function test_is_registered_as_an_opt_in_gated_ability(): void
    {
        $this->assertTrue(Opt_In_Gates::is_gated('wpmcp/add-custom-js'));
        $this->assertSame('wpmcp_allow_js_injection', Opt_In_Gates::filter_for('wpmcp/add-custom-js'));
        $this->assertFalse(Opt_In_Gates::is_open('wpmcp/add-custom-js'));

        $this->open_gate();
        $this->assertTrue(Opt_In_Gates::is_open('wpmcp/add-custom-js'));
    }
}
