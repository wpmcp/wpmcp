<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\SEO\Crawler_Files;
use WPMCP\Tools\SEO\Get_Crawler_Files;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Update_Crawler_Files;

/**
 * Site-level crawler files (issue #384): robots.txt rules, llms.txt and the
 * core XML sitemap's inclusion lists.
 *
 * Every write lands in one option through Safe_Mutation, so each case that
 * writes also checks that rollback-operation puts the site back. The
 * conflict cases pin the rule that a physical file or an SEO plugin that
 * owns a file is reported and refused, never silently overridden.
 *
 * The harness loads Yoast SEO, which switches core sitemaps off, so the
 * core-sitemap cases force the detected SEO plugin to none and core sitemaps
 * back on; the conflict cases force them the other way.
 */
class CrawlerFilesTest extends \WP_UnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Crawler_Files::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->root = trailingslashit(get_temp_dir()) . 'wpmcp-crawler-' . wp_generate_password(8, false);
        wp_mkdir_p($this->root);
        Crawler_Files::set_root_for_tests($this->root);

        SEO_Adapter::set_active_plugin_for_tests('');
        add_filter('wp_sitemaps_enabled', '__return_true', PHP_INT_MAX);
    }

    protected function tearDown(): void
    {
        Crawler_Files::set_root_for_tests(null);
        SEO_Adapter::set_active_plugin_for_tests(null);
        remove_all_filters('wpmcp_llms_txt_owner');
        foreach (['robots.txt', 'llms.txt'] as $file) {
            if (file_exists($this->root . '/' . $file)) {
                unlink($this->root . '/' . $file);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function robots(): string
    {
        ob_start();
        do_robots();
        return (string) ob_get_clean();
    }

    /** @return string[] the core sitemap index entries, as URLs. */
    private function sitemap_index(): array
    {
        // Pretty permalinks, so the entry URLs do not depend on whatever
        // structure an earlier test in the run left behind.
        $this->set_permalink_structure('/%postname%/');
        $server = new \WP_Sitemaps();
        $server->register_sitemaps();
        return array_map(static fn (array $entry): string => $entry['loc'], $server->index->get_sitemap_list());
    }

    // -----------------------------------------------------------------
    // robots.txt
    // -----------------------------------------------------------------

    public function test_robots_rules_appear_in_robots_txt_and_rollback_removes_them(): void
    {
        $out = (new Update_Crawler_Files())->handle(['robots_rules' => "User-agent: GPTBot\nDisallow: /private/"]);

        $this->assertNotEmpty($out['operation_id']);
        $robots = $this->robots();
        $this->assertStringContainsString('User-agent: GPTBot', $robots);
        $this->assertStringContainsString('Disallow: /private/', $robots);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertStringNotContainsString('GPTBot', $this->robots());
        $this->assertFalse(get_option(Crawler_Files::OPTION));
    }

    public function test_robots_rules_that_are_not_directives_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a robots.txt directive');

        (new Update_Crawler_Files())->handle(['robots_rules' => "Disallow: /a\n<script>alert(1)</script>"]);
    }

    public function test_a_physical_robots_txt_is_reported_and_blocks_a_robots_write(): void
    {
        file_put_contents($this->root . '/robots.txt', "User-agent: *\nDisallow: /static/\n");

        $read = (new Get_Crawler_Files())->handle([]);
        $this->assertTrue($read['robots']['physical_file']);
        $this->assertStringContainsString('Disallow: /static/', $read['robots']['effective']);

        try {
            (new Update_Crawler_Files())->handle(['robots_rules' => 'Disallow: /x/']);
            $this->fail('A robots write must be refused while a physical robots.txt is served.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('physical robots.txt', $e->getMessage());
        }
        $this->assertFalse(get_option(Crawler_Files::OPTION));
    }

    // -----------------------------------------------------------------
    // llms.txt
    // -----------------------------------------------------------------

    public function test_llms_txt_is_served_at_the_site_root_from_the_chosen_pages(): void
    {
        $about   = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About Us', 'post_status' => 'publish']);
        $pricing = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Pricing', 'post_status' => 'publish']);
        $other   = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Unchosen Page', 'post_status' => 'publish']);

        $out = (new Update_Crawler_Files())->handle([
            'llms' => [
                'enabled' => true,
                'summary' => 'A shop that sells widgets.',
                'pages'   => [
                    ['post_id' => $about, 'summary' => 'Who we are.'],
                    ['post_id' => $pricing],
                ],
            ],
        ]);

        $body = Crawler_Files::llms_response('/llms.txt');
        $this->assertNotNull($body);
        $this->assertStringStartsWith('# ', $body);
        $this->assertStringContainsString('> A shop that sells widgets.', $body);
        $this->assertStringContainsString('[About Us](' . get_permalink($about) . '): Who we are.', $body);
        $this->assertStringContainsString('[Pricing](' . get_permalink($pricing) . ')', $body);
        $this->assertStringNotContainsString('Unchosen Page', $body);
        $this->assertNull(Crawler_Files::llms_response('/other.txt'));

        $served = null;
        $_SERVER['REQUEST_URI'] = '/llms.txt';
        Crawler_Files::maybe_serve_llms_txt(static function (string $text) use (&$served): void {
            $served = $text;
        });
        $this->assertSame($body, $served);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertNull(Crawler_Files::llms_response('/llms.txt'));
    }

    public function test_llms_pages_must_be_published_and_public(): void
    {
        $draft = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a published');

        (new Update_Crawler_Files())->handle(['llms' => ['enabled' => true, 'pages' => [['post_id' => $draft]]]]);
    }

    public function test_a_physical_llms_txt_blocks_an_llms_write(): void
    {
        file_put_contents($this->root . '/llms.txt', "# Hand written\n");
        $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $this->assertTrue((new Get_Crawler_Files())->handle([])['llms']['physical_file']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('physical llms.txt');

        (new Update_Crawler_Files())->handle(['llms' => ['enabled' => true, 'pages' => [['post_id' => $page]]]]);
    }

    public function test_an_seo_plugin_that_serves_llms_txt_is_reported_and_blocks_an_llms_write(): void
    {
        add_filter('wpmcp_llms_txt_owner', static fn (): string => 'yoast');
        $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $this->assertSame('yoast', (new Get_Crawler_Files())->handle([])['llms']['owner']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Yoast');

        (new Update_Crawler_Files())->handle(['llms' => ['enabled' => true, 'pages' => [['post_id' => $page]]]]);
    }

    // -----------------------------------------------------------------
    // XML sitemap
    // -----------------------------------------------------------------

    public function test_excluding_a_post_type_removes_it_from_the_core_sitemap_index(): void
    {
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        self::factory()->post->create(['post_type' => 'post', 'post_status' => 'publish']);

        $before = implode("\n", $this->sitemap_index());
        $this->assertStringContainsString('wp-sitemap-posts-page-1', $before);

        $out = (new Update_Crawler_Files())->handle(['sitemap' => ['exclude_post_types' => ['page']]]);

        $after = implode("\n", $this->sitemap_index());
        $this->assertStringNotContainsString('wp-sitemap-posts-page-1', $after);
        $this->assertStringContainsString('wp-sitemap-posts-post-1', $after);

        $read = (new Get_Crawler_Files())->handle([]);
        $this->assertSame('core', $read['sitemap']['owner']);
        $this->assertNotContains('page', $read['sitemap']['post_types']);
        $this->assertContains('post', $read['sitemap']['post_types']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertStringContainsString('wp-sitemap-posts-page-1', implode("\n", $this->sitemap_index()));
    }

    public function test_excluding_a_single_post_drops_it_from_its_sitemap_page(): void
    {
        $keep = self::factory()->post->create(['post_status' => 'publish']);
        $drop = self::factory()->post->create(['post_status' => 'publish']);

        (new Update_Crawler_Files())->handle(['sitemap' => ['exclude_post_ids' => [$drop]]]);

        $urls = array_column((new \WP_Sitemaps_Posts())->get_url_list(1, 'post'), 'loc');
        $this->assertContains(get_permalink($keep), $urls);
        $this->assertNotContains(get_permalink($drop), $urls);
    }

    public function test_excluding_a_taxonomy_removes_it_from_the_core_sitemap_index(): void
    {
        $term = self::factory()->category->create();
        self::factory()->post->create(['post_status' => 'publish', 'post_category' => [$term]]);
        $this->assertStringContainsString('wp-sitemap-taxonomies-category-1', implode("\n", $this->sitemap_index()));

        (new Update_Crawler_Files())->handle(['sitemap' => ['exclude_taxonomies' => ['category']]]);

        $this->assertStringNotContainsString('wp-sitemap-taxonomies-category-1', implode("\n", $this->sitemap_index()));
    }

    public function test_unknown_post_types_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_such_type');

        (new Update_Crawler_Files())->handle(['sitemap' => ['exclude_post_types' => ['no_such_type']]]);
    }

    public function test_an_seo_plugin_sitemap_is_reported_and_blocks_a_sitemap_write(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        remove_filter('wp_sitemaps_enabled', '__return_true', PHP_INT_MAX);
        add_filter('wp_sitemaps_enabled', '__return_false', PHP_INT_MAX);

        try {
            $read = (new Get_Crawler_Files())->handle([]);
            $this->assertSame('yoast', $read['sitemap']['owner']);
            $this->assertStringContainsString('sitemap_index.xml', $read['sitemap']['url']);

            try {
                (new Update_Crawler_Files())->handle(['sitemap' => ['exclude_post_types' => ['page']]]);
                $this->fail('A sitemap write must be refused while an SEO plugin serves the sitemap.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Yoast', $e->getMessage());
            }
            $this->assertFalse(get_option(Crawler_Files::OPTION));
        } finally {
            remove_filter('wp_sitemaps_enabled', '__return_false', PHP_INT_MAX);
        }
    }

    // -----------------------------------------------------------------
    // shape and contract
    // -----------------------------------------------------------------

    public function test_a_refused_section_writes_nothing_from_the_other_sections(): void
    {
        file_put_contents($this->root . '/robots.txt', "User-agent: *\n");

        try {
            (new Update_Crawler_Files())->handle([
                'robots_rules' => 'Disallow: /x/',
                'sitemap'      => ['exclude_post_types' => ['page']],
            ]);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertFalse(get_option(Crawler_Files::OPTION));
        }
    }

    public function test_an_update_with_nothing_to_change_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing to update');

        (new Update_Crawler_Files())->handle([]);
    }

    public function test_get_reports_the_effective_robots_txt_and_the_managed_rules(): void
    {
        (new Update_Crawler_Files())->handle(['robots_rules' => 'Disallow: /tmp/']);

        $read = (new Get_Crawler_Files())->handle([]);

        $this->assertFalse($read['robots']['physical_file']);
        $this->assertSame('Disallow: /tmp/', $read['robots']['managed_rules']);
        $this->assertStringContainsString('User-agent: *', $read['robots']['effective']);
        $this->assertStringContainsString('Disallow: /tmp/', $read['robots']['effective']);
        $this->assertSame(home_url('/robots.txt'), $read['robots']['url']);
        $this->assertSame(home_url('/llms.txt'), $read['llms']['url']);
        $this->assertFalse($read['llms']['enabled']);
    }

    public function test_rollback_of_a_crawler_write_needs_manage_options(): void
    {
        $out = (new Update_Crawler_Files())->handle(['robots_rules' => 'Disallow: /tmp/']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertStringContainsString('Disallow: /tmp/', $this->robots());
    }

    public function test_the_two_tools_register_on_the_free_tier(): void
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);
        $abilities = [];
        foreach ($registrar->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertSame('free', $abilities['wpmcp/get-crawler-files']->tier);
        $this->assertSame('free', $abilities['wpmcp/update-crawler-files']->tier);
        $this->assertSame('seo', $abilities['wpmcp/update-crawler-files']->domain);
        $this->assertSame('edit_posts', $abilities['wpmcp/get-crawler-files']->capability);
        $this->assertSame('manage_options', $abilities['wpmcp/update-crawler-files']->capability);
        $this->assertTrue($abilities['wpmcp/get-crawler-files']->read_only_hint);
        $this->assertFalse($abilities['wpmcp/update-crawler-files']->read_only_hint);
    }
}
