<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Admin\Pro_Banner;
use WPMCP\MCP\Pro_Refusal;

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
        parent::tearDown();
    }

    public function test_the_message_names_the_subject_and_carries_the_upgrade_link(): void
    {
        $message = Pro_Refusal::message('This tool');

        $this->assertStringStartsWith('This tool is part of WP MCP Pro.', $message);
        $this->assertStringContainsString('Upgrade at https://wpmcp-pro.com/pricing.html?coupon=WPMCP20', $message);
        $this->assertStringContainsString('utm_medium=tool-refusal', $message);
        $this->assertStringContainsString('code WPMCP20 takes 20% off', $message);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $message, 'refusal copy carries a dash');
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
