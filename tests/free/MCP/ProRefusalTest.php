<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Admin\Pro_Banner;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Pro_Refusal;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * The sentence a free install sends back when an AI client asks for a
 * Pro-only tool or ability. It must carry the upgrade link with the
 * tool-refusal medium so the pricing page can tell this trigger from the
 * admin banner, and it must read well when a client relays it verbatim.
 */
class ProRefusalTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_pro_banner_offer');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /**
     * Runs $check against a registrar that declares one Pro tool, on an
     * install without a license, then puts the real registrar back.
     */
    private function with_a_declared_pro_tool(callable $check): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        Gate::set_pro_for_tests(false);

        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $fresh    = new Registrar();
        $fresh->register(new Ability('wpmcp/test-pro-only', 'pro', 'A pro tool', [ 'type' => 'object' ], fn () => []));
        $prop->setValue($plugin, $fresh);

        try {
            $check($fresh);
        } finally {
            $prop->setValue($plugin, $original);
        }
    }

    public function test_the_pro_required_refusal_carries_the_upgrade_link(): void
    {
        $this->with_a_declared_pro_tool(function (Registrar $registrar): void {
            $error = $registrar->pro_required('wpmcp/test-pro-only');

            $this->assertInstanceOf(\WP_Error::class, $error);
            $this->assertSame('pro_required', $error->get_error_code());
            $message = $error->get_error_message();
            $this->assertStringContainsString('Activate a WP MCP Pro license on this site', $message);
            $this->assertStringContainsString('utm_medium=tool-refusal', $message);
            $this->assertStringContainsString('code WPMCP20 takes 20% off', $message);
        });
    }

    public function test_stdio_answers_a_declared_pro_tool_with_the_pro_refusal_not_unknown_tool(): void
    {
        $this->with_a_declared_pro_tool(function (Registrar $registrar): void {
            $response = (new Stdio_Transport())->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 7,
                'method'  => 'tools/call',
                'params'  => [ 'name' => 'wpmcp-test-pro-only', 'arguments' => [] ],
            ]);

            $this->assertSame(-32602, $response['error']['code']);
            $this->assertSame($registrar->pro_required('wpmcp/test-pro-only')->get_error_message(), $response['error']['message']);

            $unknown = (new Stdio_Transport())->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 8,
                'method'  => 'tools/call',
                'params'  => [ 'name' => 'wpmcp-definitely-not-declared', 'arguments' => [] ],
            ]);
            $this->assertSame('Unknown tool: wpmcp-definitely-not-declared', $unknown['error']['message']);
        });
    }

    public function test_the_message_names_the_subject_and_carries_the_upgrade_link(): void
    {
        $message = Pro_Refusal::message('This tool');

        $this->assertStringStartsWith('This tool is part of WP MCP Pro.', $message);
        $this->assertStringContainsString('Upgrade at https://wpmcp-pro.com/pricing.html?coupon=WPMCP20', $message);
        $this->assertStringContainsString('utm_medium=tool-refusal', $message);
        $this->assertStringContainsString('code WPMCP20 takes 20% off', $message);
        // The house rule: no em or en dash in anything a client relays.
        $this->assertStringNotContainsString(mb_chr(0x2013, 'UTF-8'), $message, 'refusal copy carries an en dash');
        $this->assertStringNotContainsString(mb_chr(0x2014, 'UTF-8'), $message, 'refusal copy carries an em dash');
    }

    public function test_the_message_follows_the_filtered_offer(): void
    {
        add_filter('wpmcp_pro_banner_offer', static fn (array $offer): array => [
            'coupon'  => 'SAVE30',
            'percent' => 30,
            'url'     => 'https://example.com/buy',
        ]);

        $message = Pro_Refusal::message('The "x" skill');

        $this->assertStringContainsString('https://example.com/buy?coupon=SAVE30', $message);
        $this->assertStringContainsString('code SAVE30 takes 30% off', $message);
        $this->assertStringContainsString('utm_medium=tool-refusal', $message);
    }

    public function test_the_url_matches_the_banner_offer_with_the_refusal_medium(): void
    {
        $this->assertStringContainsString(Pro_Banner::offer('tool-refusal')['url'], Pro_Refusal::message('This tool'));
    }
}
