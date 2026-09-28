<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\Get_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * Cross-plugin conformance suite (issue #67, first acceptance criterion):
 * one input schema replayed against every adapter, proving the agent-facing
 * behavior is identical regardless of which SEO plugin a site runs.
 *
 * The per-adapter suites (SeoAdapterTest, SeoPressAdapterTest,
 * SeoFrameworkAdapterTest, SureRankAdapterTest) each prove one plugin's own
 * storage format. They cannot prove the thing the issue actually asks for,
 * which is that the same call produces the same answer everywhere: that only
 * shows up when the identical payload runs through all of them and the
 * results are compared to each other rather than to a per-plugin expectation.
 *
 * Every adapter is exercised through the WPMCP_TESTING seam because only one
 * SEO plugin can really be installed on a test leg. That is sound here: what
 * is under test is the adapter's translation layer (neutral field set to and
 * from each plugin's postmeta), which is pure postmeta work and needs none of
 * the plugin's own code loaded. The per-plugin suites that DO run against a
 * real install are what back the key strings themselves.
 */
class SeoConformanceTest extends \WP_UnitTestCase
{
    /** Every plugin the adapter claims to write the neutral field set on. */
    private const ADAPTERS = ['yoast', 'rankmath', 'seopress', 'seoframework', 'surerank'];

    /**
     * Adapters whose plugin has no per-post focus keyword field at all. They
     * are expected to differ on that one field and only that one: the schema
     * still accepts it, the value simply has nowhere to go.
     */
    private const NO_FOCUS_KEYWORD = ['seoframework', 'surerank'];

    private const INPUT = [
        'title'         => 'Conformance title',
        'description'   => 'Conformance description',
        'focus_keyword' => 'conformance',
        'canonical'     => 'https://example.com/conformance',
        'noindex'       => true,
        'nofollow'      => false,
    ];

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    /**
     * The same write, replayed on every adapter, reads back as the same
     * neutral field set. Compared adapter-to-adapter rather than against a
     * hardcoded expectation, so a plugin that silently drops or mangles a
     * field fails here even if its own suite is happy.
     */
    public function test_one_input_schema_reads_back_identically_on_every_adapter(): void
    {
        $results = [];

        foreach (self::ADAPTERS as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $post_id = self::factory()->post->create();

            SEO_Adapter::update_meta($post_id, self::INPUT);
            $out = SEO_Adapter::get_meta($post_id);

            if (in_array($plugin, self::NO_FOCUS_KEYWORD, true)) {
                $this->assertSame(
                    '',
                    $out['focus_keyword'],
                    "{$plugin} has no focus keyword field, so it must report an empty one"
                );
                $out['focus_keyword'] = self::INPUT['focus_keyword'];
            }

            $results[$plugin] = $out;
        }

        $baseline = $results['yoast'];
        $this->assertSame(self::INPUT['title'], $baseline['title']);
        $this->assertSame(self::INPUT['canonical'], $baseline['canonical']);
        $this->assertTrue($baseline['noindex']);
        $this->assertFalse($baseline['nofollow']);

        foreach ($results as $plugin => $out) {
            $this->assertSame(
                $baseline,
                $out,
                "{$plugin} must report the same neutral field set as every other adapter"
            );
        }
    }

    /**
     * The neutral field set has a fixed shape and fixed types on every
     * adapter: an agent must never have to test whether noindex came back as
     * a bool here and the string '1' there.
     */
    public function test_every_adapter_returns_the_same_keys_and_types(): void
    {
        foreach (self::ADAPTERS as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $post_id = self::factory()->post->create();

            $out = SEO_Adapter::get_meta($post_id);

            $this->assertSame(
                ['title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow'],
                array_keys($out),
                "{$plugin} must return the neutral field set in the documented order"
            );
            foreach (['title', 'description', 'focus_keyword', 'canonical'] as $field) {
                $this->assertIsString($out[$field], "{$plugin}.{$field} must be a string");
            }
            foreach (['noindex', 'nofollow'] as $field) {
                $this->assertIsBool($out[$field], "{$plugin}.{$field} must be a bool");
            }
        }
    }

    /**
     * A partial write touches only the fields it names. Replayed everywhere
     * because each adapter reaches its robots storage differently (Yoast and
     * The SEO Framework write '1'/'0' strings, RankMath rewrites a robots
     * array, SEOPress writes 'yes'/'', SureRank rewrites one serialized
     * array), and each of those is a chance to clobber a neighbouring field.
     */
    public function test_a_partial_write_leaves_the_other_fields_untouched_on_every_adapter(): void
    {
        foreach (self::ADAPTERS as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $post_id = self::factory()->post->create();

            SEO_Adapter::update_meta($post_id, self::INPUT);
            SEO_Adapter::update_meta($post_id, ['noindex' => false]);

            $out = SEO_Adapter::get_meta($post_id);

            $this->assertSame(self::INPUT['title'], $out['title'], "{$plugin} lost title");
            $this->assertSame(
                self::INPUT['description'],
                $out['description'],
                "{$plugin} lost description"
            );
            $this->assertSame(
                self::INPUT['canonical'],
                $out['canonical'],
                "{$plugin} lost canonical"
            );
            $this->assertFalse($out['noindex'], "{$plugin} did not clear noindex");
            $this->assertFalse($out['nofollow'], "{$plugin} flipped nofollow");
        }
    }

    /**
     * With no supported plugin the adapter still answers with the full
     * neutral field set, empty, rather than a short array the caller has to
     * defend against. The tool layer is what decides whether the ability is
     * registered at all.
     */
    public function test_no_active_plugin_still_returns_the_full_neutral_shape(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('');
        $post_id = self::factory()->post->create();

        $out = SEO_Adapter::get_meta($post_id);

        $this->assertSame(
            ['title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow'],
            array_keys($out)
        );
        $this->assertSame('', $out['title']);
        $this->assertFalse($out['noindex']);
    }

    /**
     * And the same through the tool, so the conformance guarantee is the one
     * an agent actually sees rather than one that stops at the adapter.
     */
    public function test_the_tool_surface_is_identical_across_adapters(): void
    {
        $shapes = [];

        foreach (self::ADAPTERS as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $post_id = self::factory()->post->create();

            SEO_Adapter::update_meta($post_id, self::INPUT);
            $out = (new Get_SEO_Meta())->handle(['post_id' => $post_id]);

            $this->assertSame($post_id, $out['post_id']);
            unset($out['post_id'], $out['focus_keyword']);
            $shapes[$plugin] = $out;
        }

        $baseline = $shapes['yoast'];
        foreach ($shapes as $plugin => $shape) {
            $this->assertSame($baseline, $shape, "wpmcp/get-seo-meta differs on {$plugin}");
        }
    }
}
