<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Safety\Rollback_Service;
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

    /**
     * There is one site-wide slot, so a second write REPLACES the first
     * snippet rather than appending (concatenating two scripts can change
     * what both mean). The response has to say so, or an agent calling an
     * "add-" tool has no way to learn it just discarded earlier work.
     */
    public function test_reports_whether_it_replaced_a_previous_snippet(): void
    {
        $this->open_gate();

        $first  = $this->tool->handle(['js' => 'console.log(1)']);
        $second = $this->tool->handle(['js' => 'console.log(2)']);

        $this->assertFalse($first['replaced_previous']);
        $this->assertTrue($second['replaced_previous']);
        $this->assertSame('console.log(2)', Custom_Code_Store::read()['js']['site']);
    }

    /** Every write is snapshotted: rolling one back restores the snippet before it. */
    public function test_rollback_restores_the_previous_snippet(): void
    {
        $this->open_gate();

        $first  = $this->tool->handle(['js' => 'console.log(1)']);
        $second = $this->tool->handle(['js' => 'console.log(2)']);

        $this->assertTrue(Rollback_Service::restore_operation($second['operation_id']));
        $this->assertSame('console.log(1)', Custom_Code_Store::read()['js']['site']);

        $this->assertTrue(Rollback_Service::restore_operation($first['operation_id']));
        $this->assertSame('', (string) (Custom_Code_Store::read()['js']['site'] ?? ''));

        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertStringNotContainsString('wpmcp-custom-js', (string) ob_get_clean());
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
     * The documented refusal order is gate FIRST. Running the input-validation
     * check ahead of guard() meant a site that never opened the opt-in gate
     * answered an empty payload with "A js value is required." - which tells
     * the caller the gate is open and the tool is live, when neither is true.
     */
    public function test_gate_is_checked_before_an_empty_payload(): void
    {
        try {
            $this->tool->handle(['js' => '   ']);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(Add_Custom_Js::REASON_GATE_CLOSED, $rows[0]['reason']);
    }

    /**
     * "<!--" followed by "<script" puts the HTML tokenizer into script-data
     * double-escaped state, where the renderer's own "</script>" no longer
     * closes the element and the rest of the page is swallowed as script
     * data. Checking only for "</script>" missed it.
     */
    public function test_refuses_a_script_data_double_escape(): void
    {
        $this->open_gate();

        foreach (['x=1;<!--<script>', 'x="<!--"; y="<script";'] as $payload) {
            try {
                $this->tool->handle(['js' => $payload]);
                $this->fail('Expected a refusal for ' . $payload);
            } catch (\RuntimeException $e) {
                $this->assertSame(Add_Custom_Js::REASON_SCRIPT_BREAKOUT, $this->audit_rows()[0]['reason']);
            }

            $this->assertSame([], Custom_Code_Store::read());
            delete_option(Governance_Audit_Log::OPTION);
        }
    }

    /**
     * The render-time twin of the check above, for a snippet that reached the
     * option some other way (direct DB edit, an older build of this plugin).
     */
    /**
     * ESC is a zero-width mode switch in ISO-2022-JP: on a legacy charset
     * "<ESC(B/script>" is "</script>" to the browser while the breakout
     * pattern sees no such sequence.
     */
    public function test_refuses_control_bytes_that_hide_a_breakout(): void
    {
        $this->open_gate();

        try {
            $this->tool->handle(['js' => "x=1;<\x1b(B/script><img src=x onerror=alert(1)>"]);
            $this->fail('A control byte should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame(Add_Custom_Js::REASON_SCRIPT_BREAKOUT, $this->audit_rows()[0]['reason']);
        }

        $this->assertSame([], Custom_Code_Store::read());
    }

    /**
     * Only a snippet this plugin wrote is printed. update-rows on wp_options
     * never consults Option_Guard, so without this a manage_options caller
     * lacking unfiltered_html could write the option and serve script to
     * every visitor.
     */
    public function test_a_directly_written_snippet_is_not_printed(): void
    {
        $this->open_gate();

        update_option(Custom_Code_Store::OPTION, ['js' => ['site' => 'console.log("direct")']], false);
        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertSame('', (string) ob_get_clean());

        update_option(Custom_Code_Store::OPTION, ['js' => ['site' => 'console.log("forged")', 'sig' => str_repeat('0', 64)]], false);
        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_a_tool_written_snippet_is_printed(): void
    {
        $this->open_gate();
        $this->tool->handle(['js' => 'console.log(1)']);

        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertStringContainsString('console.log(1)', (string) ob_get_clean());
    }

    /** js='' with replace=true clears the snippet, snapshot-first. */
    public function test_empty_js_with_replace_clears_the_snippet_reversibly(): void
    {
        $this->open_gate();
        $this->tool->handle(['js' => 'console.log(1)']);

        $out = $this->tool->handle(['js' => '', 'replace' => true]);

        $this->assertTrue($out['cleared']);
        $this->assertSame('', (string) (Custom_Code_Store::read()['js']['site'] ?? ''));
        $this->assertSame(Add_Custom_Js::REASON_CLEARED, $this->audit_rows()[0]['reason']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('console.log(1)', Custom_Code_Store::read()['js']['site']);
    }

    /** A legacy scalar under 'js' must not fatal inside Safe_Mutation after the snapshot. */
    public function test_a_scalar_legacy_js_value_is_normalized(): void
    {
        $this->open_gate();
        update_option(Custom_Code_Store::OPTION, ['js' => 'legacy'], false);

        $this->tool->handle(['js' => 'console.log(1)']);

        $this->assertSame('console.log(1)', Custom_Code_Store::read()['js']['site']);
    }

    public function test_renderer_drops_a_stored_double_escape(): void
    {
        $this->open_gate();
        update_option(Custom_Code_Store::OPTION, ['js' => ['site' => 'x=1;<!--<script>']], false);

        ob_start();
        Custom_Code_Renderer::print_js();
        $this->assertSame('', (string) ob_get_clean());
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
