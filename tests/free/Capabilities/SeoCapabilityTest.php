<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the SEO domain: get-seo-status, get-seo-meta,
 * update-seo-meta, generate-schema-markup, generate-meta-tags,
 * get-social-meta, set-social-image and get-term-seo-meta all require
 * edit_posts; update-term-seo-meta requires manage_categories, like the
 * other term writes. get-seo-status and the two generators are always
 * registered; the plugin-backed tools are gated behind an active SEO plugin,
 * matching SeoAbilitiesRegistrationTest's guard.
 *
 * The pro abilities are asserted here rather than in the pro suite because
 * this is the per-domain capability suite: what it defends is that no future
 * edit to register_seo_abilities() can silently widen one ability's
 * capability while the others stay correct. Tier is a separate concern and
 * stays covered by the per-tool tests.
 */
class SeoCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_get_seo_status_requires_edit_posts(): void
    {
        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertArrayHasKey('wpmcp/get-seo-status', $abilities);
        $this->assertSame('edit_posts', $abilities['wpmcp/get-seo-status']->capability);
    }

    public function test_get_seo_status_denies_subscriber_and_allows_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/get-seo-status']->check_permissions(),
            'wpmcp/get-seo-status must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-seo-status']->check_permissions(),
            'wpmcp/get-seo-status must allow a user holding edit_posts'
        );
    }

    public function test_meta_tools_require_edit_posts_when_an_seo_plugin_is_active(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertSame('edit_posts', $abilities['wpmcp/get-seo-meta']->capability);
        $this->assertSame('edit_posts', $abilities['wpmcp/update-seo-meta']->capability);

        $wp_abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($wp_abilities['wpmcp/update-seo-meta']->check_permissions());

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue($wp_abilities['wpmcp/update-seo-meta']->check_permissions());
    }

    /**
     * The declared capability of every SEO ability, pro ones included.
     * Asserted against declared() rather than all(), because all() has
     * already dropped the pro entries on a free-tier run and an assertion
     * that silently skips there is exactly the hole this suite exists to
     * close: the capability string is a static declaration and is wrong on
     * every tier at once if it is wrong at all.
     *
     * create-redirect sits deliberately higher. Redirects are a site-wide
     * routing change, not a per-post edit, so it is listed explicitly rather
     * than swept in with the rest. update-term-seo-meta sits at
     * manage_categories for the same kind of reason: a term's SEO fields
     * shape a whole archive, and every other term write in the plugin
     * (set-term-meta, update-term) is gated there too.
     */
    private const EXPECTED_CAPABILITIES = [
        'wpmcp/get-seo-status'          => 'edit_posts',
        'wpmcp/get-seo-meta'            => 'edit_posts',
        'wpmcp/update-seo-meta'         => 'edit_posts',
        'wpmcp/generate-schema-markup'  => 'edit_posts',
        'wpmcp/generate-meta-tags'      => 'edit_posts',
        'wpmcp/get-social-meta'         => 'edit_posts',
        'wpmcp/set-social-image'        => 'edit_posts',
        'wpmcp/get-term-seo-meta'       => 'edit_posts',
        'wpmcp/update-term-seo-meta'    => 'manage_categories',
        'wpmcp/create-redirect'         => 'manage_options',
    ];

    /** @return array<string, \WPMCP\MCP\Ability> */
    private function declared_abilities(): array
    {
        $out = [];
        foreach (Plugin::instance()->registrar()->declared() as $ability) {
            $out[ $ability->name ] = $ability;
        }

        return $out;
    }

    public function test_every_seo_ability_declares_the_expected_capability(): void
    {
        $declared = $this->declared_abilities();

        foreach (self::EXPECTED_CAPABILITIES as $name => $capability) {
            if (! isset($declared[$name])) {
                // The postmeta-backed tools only declare when a supported SEO
                // plugin is active, and redirects are their own group.
                continue;
            }
            $this->assertSame(
                $capability,
                $declared[$name]->capability,
                "{$name} must declare {$capability}"
            );
        }

        $this->assertArrayHasKey('wpmcp/generate-schema-markup', $declared);
        $this->assertArrayHasKey('wpmcp/generate-meta-tags', $declared);
        $this->assertArrayHasKey('wpmcp/get-seo-status', $declared);
    }

    /**
     * The SEO domain is wider than this suite's map (it also carries the
     * linking, redirect and orphan tools), so the sweep asserts the floor
     * rather than the exact string: no ability in the domain may sit below
     * edit_posts. A tool added later with 'read' fails here even though it
     * has no entry above.
     */
    public function test_no_seo_domain_ability_sits_below_edit_posts(): void
    {
        $allowed = ['edit_posts', 'manage_categories', 'manage_options'];
        $seen    = 0;

        foreach ($this->declared_abilities() as $name => $ability) {
            if ('seo' !== $ability->domain) {
                continue;
            }
            $seen++;
            $this->assertContains(
                $ability->capability,
                $allowed,
                "{$name} declares {$ability->capability}, which is below edit_posts"
            );
        }

        $this->assertGreaterThanOrEqual(2, $seen, 'Expected the SEO domain to be registered');
    }

    /**
     * Permission outcome for the two pro reads. Where the tier exposes them,
     * this goes through the ability's own check_permissions(); where it does
     * not, it asserts the same thing against the declared capability, so the
     * case cannot quietly become a skip on a free-tier run and stop
     * defending anything.
     */
    public function test_generate_schema_markup_denies_subscriber_and_allows_edit_posts(): void
    {
        $this->assertPermissionOutcome('wpmcp/generate-schema-markup');
    }

    public function test_generate_meta_tags_denies_subscriber_and_allows_edit_posts(): void
    {
        $this->assertPermissionOutcome('wpmcp/generate-meta-tags');
    }

    public function test_set_social_image_and_term_read_deny_subscriber_and_allow_edit_posts(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->assertPermissionOutcome('wpmcp/set-social-image');
        $this->assertPermissionOutcome('wpmcp/get-term-seo-meta');
    }

    /**
     * An author holds edit_posts but not manage_categories, so the term SEO
     * write must refuse them while an editor gets through.
     */
    public function test_update_term_seo_meta_requires_manage_categories(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $name     = 'wpmcp/update-term-seo-meta';
        $declared = $this->declared_abilities();
        $this->assertArrayHasKey($name, $declared);
        $this->assertSame('manage_categories', $declared[$name]->capability);

        $exposed = wp_get_abilities()[$name] ?? null;

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertFalse(null !== $exposed ? $exposed->check_permissions() : current_user_can('manage_categories'));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertTrue(null !== $exposed ? $exposed->check_permissions() : current_user_can('manage_categories'));
    }

    public function test_get_social_meta_denies_subscriber_and_allows_edit_posts(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->assertPermissionOutcome('wpmcp/get-social-meta');
    }

    private function assertPermissionOutcome(string $name): void
    {
        $declared = $this->declared_abilities();
        $this->assertArrayHasKey($name, $declared, "{$name} must be declared");

        $exposed    = wp_get_abilities()[$name] ?? null;
        $capability = $declared[$name]->capability;

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            null !== $exposed ? $exposed->check_permissions() : current_user_can($capability),
            "{$name} must deny a subscriber"
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            null !== $exposed ? $exposed->check_permissions() : current_user_can($capability),
            "{$name} must allow a user holding edit_posts"
        );
    }
}
