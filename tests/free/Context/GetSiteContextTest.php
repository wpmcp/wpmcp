<?php

namespace WPMCP\Tests\Free\Context;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Context\Get_Site_Context;

class GetSiteContextTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_reports_site_identity_and_versions(): void
    {
        $out = (new Get_Site_Context())->handle([]);

        $this->assertSame(get_bloginfo('name'), $out['site']['name']);
        $this->assertSame(home_url(), $out['site']['url']);
        $this->assertSame(get_bloginfo('description'), $out['site']['tagline']);
        $this->assertSame(get_bloginfo('version'), $out['wordpress_version']);
        $this->assertSame(PHP_VERSION, $out['php_version']);
    }

    public function test_reports_active_theme_and_plugin_summary(): void
    {
        $theme = wp_get_theme();

        $out = (new Get_Site_Context())->handle([]);

        $this->assertSame($theme->get('Name'), $out['theme']['name']);
        $this->assertSame($theme->get('Version'), $out['theme']['version']);
        $this->assertSame((bool) $theme->parent(), $out['theme']['is_child']);

        $active_plugins = (array) get_option('active_plugins', []);
        $this->assertSame(count($active_plugins), $out['plugins']['active_count']);
        $this->assertSame(array_values($active_plugins), $out['plugins']['active_slugs']);
    }

    /**
     * Issue #722: deactivate_plugins() unsets an entry without renumbering,
     * so the option can start at key 1. active_slugs must still reach the
     * wire as a JSON list, and so must the other list fields.
     */
    public function test_active_slugs_is_a_json_list_when_the_option_has_a_key_gap(): void
    {
        $filter = static fn (): array => [ 1 => 'wpmcp/wpmcp.php', 3 => 'hello.php' ];
        add_filter('pre_option_active_plugins', $filter);
        try {
            $out = (new Get_Site_Context())->handle([]);
        } finally {
            remove_filter('pre_option_active_plugins', $filter);
        }

        $this->assertSame(2, $out['plugins']['active_count']);
        $this->assertSame([ 'wpmcp/wpmcp.php', 'hello.php' ], $out['plugins']['active_slugs']);

        $wire = json_decode((string) wp_json_encode($out));
        $this->assertIsArray($wire->plugins->active_slugs, 'active_slugs must encode as a JSON list, not an object');
        $this->assertSame([ 'wpmcp/wpmcp.php', 'hello.php' ], $wire->plugins->active_slugs);
        $this->assertIsArray($wire->post_types, 'post_types must encode as a JSON list');
        $this->assertIsArray($wire->taxonomies, 'taxonomies must encode as a JSON list');
    }

    public function test_reports_public_post_type_counts_including_a_freshly_created_post(): void
    {
        $before = (new Get_Site_Context())->handle([]);
        $before_count = 0;
        foreach ($before['post_types'] as $row) {
            if ('post' === $row['name']) {
                $before_count = $row['count'];
            }
        }

        self::factory()->post->create(['post_type' => 'post', 'post_status' => 'publish']);

        $out = (new Get_Site_Context())->handle([]);

        $post_row = null;
        foreach ($out['post_types'] as $row) {
            if ('post' === $row['name']) {
                $post_row = $row;
            }
        }

        $this->assertNotNull($post_row, 'Expected the "post" post type to be present');
        $this->assertSame($before_count + 1, $post_row['count']);
    }

    public function test_reports_public_taxonomies(): void
    {
        $out = (new Get_Site_Context())->handle([]);

        $names = array_column($out['taxonomies'], 'name');
        $this->assertContains('category', $names);
        $this->assertContains('post_tag', $names);
    }

    public function test_reports_user_count_locale_timezone_and_multisite_status(): void
    {
        self::factory()->user->create(['role' => 'subscriber']);

        $out = (new Get_Site_Context())->handle([]);

        $this->assertSame((int) count_users()['total_users'], $out['user_count']);
        $this->assertSame(get_locale(), $out['locale']);
        $this->assertSame(get_option('timezone_string'), $out['timezone']);
        $this->assertSame(is_multisite(), $out['is_multisite']);
    }

    public function test_reports_active_integrations(): void
    {
        $out = (new Get_Site_Context())->handle([]);

        $this->assertTrue($out['capabilities']['elementor']);
        $this->assertTrue($out['capabilities']['woocommerce']);
        $this->assertTrue($out['capabilities']['acf']);
        $this->assertSame('yoast' === wpmcp_seo_plugin(), $out['capabilities']['yoast']);
        $this->assertSame('rankmath' === wpmcp_seo_plugin(), $out['capabilities']['rankmath']);
    }

    public function test_reports_wpmcp_plugin_version_and_pro_status(): void
    {
        Gate::set_pro_for_tests(true);

        $out = (new Get_Site_Context())->handle([]);

        $this->assertSame(WPMCP_VERSION, $out['wpmcp']['version']);
        $this->assertTrue($out['wpmcp']['pro_active']);

        Gate::set_pro_for_tests(false);

        $out = (new Get_Site_Context())->handle([]);

        $this->assertFalse($out['wpmcp']['pro_active']);
    }

    public function test_does_not_leak_the_admin_email_anywhere_in_the_payload(): void
    {
        $admin_email = get_option('admin_email');

        $out = (new Get_Site_Context())->handle([]);

        $this->assertArrayNotHasKey('admin_email', $out['site']);
        $this->assertStringNotContainsString(
            $admin_email,
            (string) wp_json_encode($out),
            'Expected the admin email to not appear anywhere in the payload'
        );
    }
}
