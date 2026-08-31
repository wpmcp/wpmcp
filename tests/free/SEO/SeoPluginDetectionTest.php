<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * Proves the optional-plugin test harness for SEO plugins: wpmcp_seo_plugin()
 * reports which SEO plugin (if any) is active in the current test run, so
 * SEO tool tests can gate themselves the same way ACF/WooCommerce tests do.
 *
 * The helper used to restate the adapter's checks and had drifted to knowing
 * only Yoast and RankMath while the adapter detected five plugins. That is a
 * silent failure rather than a loud one: a test gating on the helper skips
 * itself on a leg where the code it covers is live, and the run reports
 * green. It now delegates, and these cases hold the two together.
 */
class SeoPluginDetectionTest extends \WP_UnitTestCase
{
    /** Every plugin the adapter can detect, in its precedence order. */
    private const DETECTABLE = ['yoast', 'rankmath', 'seopress', 'seoframework', 'surerank'];

    /** The signal each detected plugin is keyed off, mirroring the adapter. */
    private static function present(string $plugin): bool
    {
        switch ($plugin) {
            case 'yoast':
                return defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
            case 'rankmath':
                return class_exists('RankMath');
            case 'seopress':
                return defined('SEOPRESS_VERSION')
                    || function_exists('seopress_get_toggle_titles_option');
            case 'seoframework':
                return defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('tsf');
            case 'surerank':
                return defined('SURERANK_VERSION');
            default:
                return false;
        }
    }

    /** The first detectable plugin actually present, or '' when none is. */
    private static function expected(): string
    {
        foreach (self::DETECTABLE as $plugin) {
            if (self::present($plugin)) {
                return $plugin;
            }
        }

        return '';
    }

    /**
     * One case covering every leg, rather than a skip-guarded case per
     * plugin: whichever plugin the leg installs, the helper must name it,
     * and on a leg with none it must say so. A SEOPress or SureRank leg is
     * now asserted rather than silently skipped.
     */
    public function test_reports_the_plugin_the_environment_actually_has(): void
    {
        $this->assertSame(self::expected(), wpmcp_seo_plugin());
    }

    /**
     * SEOPress detection is new on this branch (the postmeta map predates
     * it), so the helper and the adapter must agree about it specifically:
     * this is what makes get-seo-meta / update-seo-meta reachable on a
     * SEOPress site.
     */
    public function test_seopress_is_detectable_and_agreed_on(): void
    {
        if (! self::present('seopress')) {
            $this->markTestSkipped('SEOPress is not installed in this test environment.');
        }
        if (self::present('yoast') || self::present('rankmath')) {
            $this->markTestSkipped('A higher-precedence SEO plugin is active.');
        }

        $this->assertSame('seopress', SEO_Adapter::detect_active_plugin());
        $this->assertSame('seopress', wpmcp_seo_plugin());
    }

    /**
     * The helper reads the real environment even while a test has forced an
     * active plugin for itself, so a forced adapter can never make an
     * environment gate lie.
     */
    public function test_the_harness_helper_ignores_the_adapter_test_seam(): void
    {
        $real = wpmcp_seo_plugin();

        SEO_Adapter::set_active_plugin_for_tests('surerank');
        try {
            $this->assertSame('surerank', SEO_Adapter::active_plugin());
            $this->assertSame($real, wpmcp_seo_plugin());
            $this->assertSame($real, SEO_Adapter::detect_active_plugin());
        } finally {
            SEO_Adapter::set_active_plugin_for_tests(null);
        }
    }

    /** The helper never invents a plugin the adapter cannot detect. */
    public function test_reports_only_a_known_plugin_or_the_empty_string(): void
    {
        $this->assertContains(
            wpmcp_seo_plugin(),
            array_merge([''], self::DETECTABLE)
        );
    }
}
