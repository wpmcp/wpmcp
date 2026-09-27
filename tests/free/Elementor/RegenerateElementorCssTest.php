<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Builders\Elementor_Cache;
use WPMCP\Tools\Elementor\Regenerate_Elementor_Css;

/**
 * regenerate-elementor-css: rebuild one document's generated CSS (and drop
 * its render cache), or purge Elementor's generated files site-wide behind
 * an explicit confirm. A cache operation, so like clear-cache it is not
 * snapshotted.
 */
class RegenerateElementorCssTest extends \WP_UnitTestCase
{
    private const NAME = 'wpmcp/regenerate-elementor-css';

    protected function setUp(): void
    {
        parent::setUp();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // The test framework deletes every post between tests, the default
        // kit included; Elementor needs one to build a document's CSS.
        $kits = \Elementor\Plugin::instance()->kits_manager;
        if (! $kits->get_active_id() || ! get_post((int) $kits->get_active_id())) {
            update_option('elementor_active_kit', \Elementor\Core\Kits\Manager::create_default_kit());
        }
    }

    protected function tearDown(): void
    {
        Elementor_Cache::set_available_for_tests(null);
        parent::tearDown();
    }

    private function page(string $color = '#ff0000'): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode([
            [
                'id'         => 'wid0001',
                'elType'     => 'widget',
                'settings'   => ['title' => 'Hi', 'title_color' => $color],
                'elements'   => [],
                'widgetType' => 'heading',
            ],
        ])));

        return $post_id;
    }

    private function seed_probes(int $post_id): void
    {
        update_post_meta($post_id, '_elementor_element_cache', wp_slash(wp_json_encode(['timeout' => time() + 3600, 'value' => ['content' => 'stale']])));
        update_post_meta($post_id, '_elementor_css', ['status' => 'stale-probe']);
    }

    // ---- registration -------------------------------------------------------

    public function test_is_registered_as_a_free_elementor_ability(): void
    {
        $abilities = wp_get_abilities();

        $this->assertArrayHasKey(self::NAME, $abilities);
        $this->assertSame('wpmcp', $abilities[self::NAME]->get_category());
        $this->assertNotEmpty($abilities[self::NAME]->get_description());

        $manifest = require dirname(__DIR__, 2) . '/support/ability-manifest.php';
        $this->assertSame('free', $manifest['abilities'][self::NAME] ?? null);
    }

    public function test_description_stays_short(): void
    {
        $this->assertLessThanOrEqual(300, strlen(wp_get_abilities()[self::NAME]->get_description()));
    }

    public function test_permissions_allow_editors_and_deny_subscribers(): void
    {
        $ability = wp_get_abilities()[self::NAME];

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertTrue($ability->check_permissions());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertFalse($ability->check_permissions());
    }

    // ---- one post -----------------------------------------------------------

    public function test_single_post_rebuilds_css_and_drops_render_cache(): void
    {
        $post_id = $this->page('#ff0000');
        $this->seed_probes($post_id);

        $out = (new Regenerate_Elementor_Css())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('post', $out['scope']);
        $this->assertSame($post_id, $out['post_id']);
        $this->assertContains($out['css_status'], ['file', 'inline']);
        $this->assertEmpty(get_post_meta($post_id, '_elementor_element_cache', true));

        $css  = \Elementor\Core\Files\CSS\Post::create($post_id);
        $meta = $css->get_meta();
        $this->assertNotSame('stale-probe', $meta['status']);
        $body = 'file' === $meta['status'] ? (string) file_get_contents($css->get_path()) : (string) ($meta['css'] ?? '');
        $this->assertStringContainsString('#ff0000', $body, 'The CSS is rebuilt from the stored data.');
    }

    public function test_single_post_leaves_other_posts_alone(): void
    {
        $post_id = $this->page();
        $other   = $this->page();
        $this->seed_probes($other);

        (new Regenerate_Elementor_Css())->handle(['post_id' => $post_id]);

        $this->assertSame(['status' => 'stale-probe'], get_post_meta($other, '_elementor_css', true));
    }

    public function test_refuses_a_post_without_elementor_data(): void
    {
        $post_id = self::factory()->post->create();

        $out = (new Regenerate_Elementor_Css())->handle(['post_id' => $post_id]);

        $this->assertWPError($out);
        $this->assertSame('not_elementor', $out->get_error_code());
    }

    public function test_refuses_a_post_the_user_cannot_edit(): void
    {
        $post_id = $this->page();
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $out = (new Regenerate_Elementor_Css())->handle(['post_id' => $post_id]);

        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    // ---- site-wide ----------------------------------------------------------

    public function test_site_wide_requires_confirm(): void
    {
        $post_id = $this->page();
        $this->seed_probes($post_id);

        $out = (new Regenerate_Elementor_Css())->handle([]);

        $this->assertWPError($out);
        $this->assertSame('confirm_required', $out->get_error_code());
        $this->assertSame(['status' => 'stale-probe'], get_post_meta($post_id, '_elementor_css', true), 'Nothing is purged without confirm.');
    }

    public function test_site_wide_with_confirm_purges_every_document(): void
    {
        $a = $this->page();
        $b = $this->page();
        $this->seed_probes($a);
        $this->seed_probes($b);

        $out = (new Regenerate_Elementor_Css())->handle(['confirm' => true]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('site', $out['scope']);
        foreach ([$a, $b] as $id) {
            $this->assertEmpty(get_post_meta($id, '_elementor_css', true));
            $this->assertEmpty(get_post_meta($id, '_elementor_element_cache', true));
        }
    }

    public function test_site_wide_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Regenerate_Elementor_Css())->handle(['confirm' => true]);

        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    // ---- without Elementor --------------------------------------------------

    public function test_reports_elementor_inactive_and_touches_nothing(): void
    {
        $post_id = $this->page();
        $this->seed_probes($post_id);
        Elementor_Cache::set_available_for_tests(false);

        $single = (new Regenerate_Elementor_Css())->handle(['post_id' => $post_id]);
        $site   = (new Regenerate_Elementor_Css())->handle(['confirm' => true]);

        $this->assertSame('elementor_inactive', $single->get_error_code());
        $this->assertSame('elementor_inactive', $site->get_error_code());
        $this->assertSame(['status' => 'stale-probe'], get_post_meta($post_id, '_elementor_css', true));
    }
}
