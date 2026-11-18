<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Availability;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Connect\Get_Connection_Info;
use WPMCP\Tools\Connect\List_Tool_Catalog;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;
use WPMCP\Tools\Dispatch\List_Tools;

/**
 * Issue #629: an agent can ask what it cannot do here, and why, without a
 * new tool. list-tools takes include_unavailable and always ends with a
 * one-line summary; get-tool-schema carries an availability block;
 * list-tool-catalog, get-connection-info and the handshake use the same
 * data.
 */
class ToolAvailabilityDiscoveryTest extends \WP_UnitTestCase
{
    private Registrar $original;

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Tool_Exposure::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->original = Plugin::instance()->registrar();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        Gate::set_pro_for_tests(null);
        Registrar::set_woocommerce_loaded_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    /**
     * A site without a license and without WooCommerce: two Pro tools in
     * two domains, one WooCommerce tool, one held pair, one governance-off
     * tool and two plain free tools.
     */
    private function fixture(): Registrar
    {
        Gate::set_pro_for_tests(false);
        Registrar::set_woocommerce_loaded_for_tests(false);
        Governance::set_ability_toggle('wpmcp/test-off', false);

        $fresh = new Registrar();
        $fresh->register(new Ability('wpmcp/test-pro-a', 'pro', 'Pro A', ['type' => 'object'], fn () => [], 'edit_posts', 'forms', 'read'));
        $fresh->register(new Ability('wpmcp/test-pro-b', 'pro', 'Pro B', ['type' => 'object'], fn () => [], 'edit_posts', 'builder', 'read'));
        $fresh->register(new Ability('wpmcp/test-woo', 'free', 'Woo', ['type' => 'object'], fn () => [], 'edit_posts', 'woocommerce', 'read'));
        $fresh->register(new Ability('wpmcp/test-off', 'free', 'Off', ['type' => 'object'], fn () => [], 'edit_posts', 'content', 'read'));
        $fresh->register(new Ability('wpmcp/test-free', 'free', 'Free', ['type' => 'object'], fn () => [], 'edit_posts', 'content', 'read'));
        $fresh->register(new Ability('wpmcp/list-tools', 'free', 'List', ['type' => 'object'], fn () => [], 'edit_posts', 'dispatch', 'read'));
        $fresh->hold_for_plugin(new Ability('wpmcp/test-held-read', 'free', 'Held', ['type' => 'object'], fn () => [], 'edit_posts', 'forms', 'read'), 'Fixture Forms');
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $fresh);

        return $fresh;
    }

    private static function group(array $report, string $reason, ?string $plugin = null): array
    {
        foreach ($report['groups'] as $group) {
            if ($reason === $group['reason'] && (null === $plugin || $plugin === ($group['plugin'] ?? null))) {
                return $group;
            }
        }
        self::fail("No {$reason} group" . (null === $plugin ? '' : " for {$plugin}"));
    }

    public function test_include_unavailable_groups_hidden_tools_by_reason(): void
    {
        $registrar = $this->fixture();

        $out = (new List_Tools())->handle(['include_unavailable' => true], $registrar);

        $this->assertArrayHasKey('unavailable', $out);
        $report = $out['unavailable'];
        $this->assertSame(5, $report['total']);

        $pro = self::group($report, 'pro_required');
        $this->assertSame(2, $pro['count']);
        $this->assertSame([ 'builder' => 1, 'forms' => 1 ], $pro['domains']);
        $this->assertArrayNotHasKey('tools', $pro, 'Pro tools are a count and group names, not stubs.');
        $this->assertStringContainsString('WP MCP Pro license', $pro['label']);

        $woo = self::group($report, 'plugin_missing', 'WooCommerce');
        $this->assertSame([ 'wpmcp/test-woo' ], $woo['tools']);
        $this->assertStringContainsString('WooCommerce', $woo['action']);

        $held = self::group($report, 'plugin_missing', 'Fixture Forms');
        $this->assertSame([ 'wpmcp/test-held-read' ], $held['tools']);

        $off = self::group($report, 'governance_disabled');
        $this->assertSame([ 'wpmcp/test-off' ], $off['tools']);
    }

    public function test_every_list_tools_reply_ends_with_a_one_line_summary(): void
    {
        $registrar = $this->fixture();

        $out = (new List_Tools())->handle([], $registrar);

        $this->assertArrayNotHasKey('unavailable', $out, 'The grouping is opt-in.');
        $this->assertSame('unavailable_summary', array_key_last($out));
        $line = $out['unavailable_summary'];
        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringContainsString('2 Pro tools', $line);
        $this->assertStringContainsString('WooCommerce', $line);
        $this->assertStringContainsString('include_unavailable', $line);
    }

    public function test_the_summary_says_so_when_nothing_is_unavailable(): void
    {
        Gate::set_pro_for_tests(true);
        $fresh = new Registrar();
        $fresh->register(new Ability('wpmcp/test-free', 'free', 'Free', ['type' => 'object'], fn () => [], 'edit_posts', 'content', 'read'));

        $out = (new List_Tools())->handle(['include_unavailable' => true], $fresh);

        $this->assertSame(0, $out['unavailable']['total']);
        $this->assertStringNotContainsString('include_unavailable', $out['unavailable_summary']);
        $this->assertFalse(Tool_Availability::any($fresh));
    }

    public function test_get_tool_schema_carries_an_availability_block(): void
    {
        $this->fixture();
        $schema = new Get_Tool_Schema();

        $this->assertSame([ 'available' => true ], $schema->handle(['name' => 'wpmcp/test-free'])['availability']);

        $woo = $schema->handle(['name' => 'wpmcp/test-woo'])['availability'];
        $this->assertFalse($woo['available']);
        $this->assertSame('plugin_missing', $woo['reason']);
        $this->assertSame('WooCommerce', $woo['plugin']);

        foreach ([ 'wpmcp/test-pro-a' => 'pro_required', 'wpmcp/test-off' => 'governance_disabled', 'wpmcp/test-held-read' => 'plugin_missing' ] as $name => $reason) {
            $error = $schema->handle(['name' => $name]);
            $this->assertInstanceOf(\WP_Error::class, $error, $name);
            $this->assertSame($reason, $error->get_error_code(), $name);
            $availability = $error->get_error_data()['availability'];
            $this->assertFalse($availability['available'], $name);
            $this->assertSame($reason, $availability['reason'], $name);
        }
        $this->assertSame('Fixture Forms', $schema->handle(['name' => 'wpmcp/test-held-read'])->get_error_data()['plugin']);
    }

    public function test_compact_mode_marks_hidden_tools_and_keeps_them_callable(): void
    {
        $registrar = $this->fixture();
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);

        $out    = (new List_Tools())->handle([], $registrar);
        $byname = array_column($out['tools'], null, 'name');
        $this->assertTrue($byname['wpmcp/test-free']['hidden_compact']);
        $this->assertArrayNotHasKey('hidden_compact', $byname['wpmcp/list-tools']);

        $availability = (new Get_Tool_Schema())->handle(['name' => 'wpmcp/test-free'])['availability'];
        $this->assertTrue($availability['available']);
        $this->assertTrue($availability['hidden_compact']);

        delete_option(Tool_Exposure::OPTION);
        $full = array_column((new List_Tools())->handle([], $registrar)['tools'], null, 'name');
        $this->assertArrayNotHasKey('hidden_compact', $full['wpmcp/test-free'], 'Full mode is unchanged.');
    }

    public function test_catalog_connection_info_and_handshake_use_the_same_data(): void
    {
        $registrar = $this->fixture();

        $catalog = (new List_Tool_Catalog())->handle([], $registrar);
        $this->assertSame(Tool_Availability::report($registrar), $catalog['unavailable']);

        $info = (new Get_Connection_Info())->handle([]);
        $this->assertSame(Tool_Availability::summary(Tool_Availability::report($registrar)), $info['unavailable_summary']);

        $this->assertStringContainsString(Tool_Availability::HANDSHAKE_LINE, (new Handshake_Instructions())->build());
    }

    public function test_the_handshake_line_is_left_out_when_everything_is_available(): void
    {
        Gate::set_pro_for_tests(true);
        $fresh = new Registrar();
        $fresh->register(new Ability('wpmcp/test-free', 'free', 'Free', ['type' => 'object'], fn () => [], 'edit_posts', 'content', 'read'));
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $fresh);

        $this->assertStringNotContainsString(Tool_Availability::HANDSHAKE_LINE, (new Handshake_Instructions())->build());
    }
}
