<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Analyze_Performance;

class PerformanceAbilitiesTest extends \WP_UnitTestCase
{
    public function test_analyze_performance_ability_is_registered(): void
    {
        $names = array_keys(wp_get_abilities());

        $this->assertContains('wpmcp/analyze-performance', $names);
    }

    public function test_analyze_performance_ability_has_description_and_category(): void
    {
        $ability = wp_get_abilities()['wpmcp/analyze-performance'];

        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }

    public function test_analyze_performance_denies_subscriber_and_allows_administrator(): void
    {
        $ability = wp_get_abilities()['wpmcp/analyze-performance'];

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($ability->check_permissions());

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue($ability->check_permissions());
    }

    public function test_handle_translates_an_invalid_target_into_an_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Analyze_Performance())->handle(['url' => 'https://evil.test/page']);
    }

    public function test_handle_returns_the_analyzer_shape_for_a_valid_default_target(): void
    {
        $out = (new Analyze_Performance())->handle(['include_page_fetch' => false]);

        $this->assertArrayHasKey('target', $out);
        $this->assertArrayHasKey('summary', $out);
        $this->assertArrayHasKey('sections', $out);
        $this->assertArrayHasKey('page_fetch', $out);
        $this->assertArrayHasKey('top_recommendations', $out);
        $this->assertTrue($out['target']['is_front_page']);
    }
}
