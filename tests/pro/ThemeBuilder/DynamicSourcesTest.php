<?php

namespace WPMCP\Tests\Pro\ThemeBuilder;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Tools\ThemeBuilder\Dynamic\Dynamic_Sources;

/**
 * Dynamic source discovery (issue #290): the bindable fields a single,
 * archive or search template can use, reached as the list-dynamic-sources op
 * on the theme-read dispatcher. Core post and site fields are always there;
 * ACF fields appear only while ACF is active, read from the field groups
 * whose location rules match the post type asked about.
 */
class DynamicSourcesTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_dynamic_sources_acf_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string,array<string,mixed>> source key => source */
    private function by_key(array $discovered): array
    {
        $out = [];
        foreach ($discovered['sources'] as $source) {
            $out[$source['key']] = $source;
        }
        return $out;
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read(['operation' => $op, 'args' => $args]);
    }

    public function test_core_post_and_site_fields_are_listed_for_a_single_template(): void
    {
        $sources = $this->by_key(Dynamic_Sources::discover('single', 'post'));

        $expected = [
            'post.title'          => 'text',
            'post.content'        => 'content',
            'post.excerpt'        => 'text',
            'post.featured_image' => 'image',
            'post.author'         => 'text',
            'post.date'           => 'text',
            'post.permalink'      => 'url',
            'post.terms.category' => 'text',
            'post.terms.post_tag' => 'text',
            'site.name'           => 'text',
            'site.tagline'        => 'text',
            'site.logo'           => 'image',
        ];
        foreach ($expected as $key => $type) {
            $this->assertArrayHasKey($key, $sources, $key);
            $this->assertSame($type, $sources[$key]['type'], $key);
            $this->assertSame('{{' . $key . '}}', $sources[$key]['token'], $key);
        }
    }

    public function test_archive_and_search_contexts_add_their_own_sources_and_the_loop(): void
    {
        $single  = $this->by_key(Dynamic_Sources::discover('single', 'post'));
        $archive = Dynamic_Sources::discover('archive', 'post');
        $search  = Dynamic_Sources::discover('search');

        $this->assertArrayNotHasKey('archive.title', $single);
        $this->assertArrayNotHasKey('search.query', $single);
        $this->assertArrayHasKey('archive.title', $this->by_key($archive));
        $this->assertArrayHasKey('loop.pagination', $this->by_key($archive));
        $this->assertArrayHasKey('search.query', $this->by_key($search));
        $this->assertArrayNotHasKey('archive.title', $this->by_key($search));
        $this->assertTrue($archive['loop']);
        $this->assertFalse(Dynamic_Sources::discover('single')['loop']);
    }

    public function test_an_unknown_context_is_an_error_not_an_empty_list(): void
    {
        $this->assertInstanceOf(\WP_Error::class, Dynamic_Sources::discover('header'));
    }

    public function test_without_acf_no_acf_sources_are_listed(): void
    {
        add_filter('wpmcp_dynamic_sources_acf_active', '__return_false');

        $discovered = Dynamic_Sources::discover('single', 'post');

        $this->assertFalse($discovered['acf_active']);
        foreach (array_keys($this->by_key($discovered)) as $key) {
            $this->assertStringStartsNotWith('acf.', $key);
        }
    }

    public function test_with_acf_the_field_groups_for_the_post_type_are_listed_by_field_type(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            $this->markTestSkipped('ACF not active');
        }
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_290',
            'title'    => 'Dynamic 290',
            'fields'   => [
                ['key' => 'field_290_subtitle', 'label' => 'Subtitle', 'name' => 'subtitle_290', 'type' => 'text'],
                ['key' => 'field_290_hero', 'label' => 'Hero', 'name' => 'hero_290', 'type' => 'image'],
                ['key' => 'field_290_site', 'label' => 'Website', 'name' => 'website_290', 'type' => 'url'],
                ['key' => 'field_290_body', 'label' => 'Body', 'name' => 'body_290', 'type' => 'wysiwyg'],
                ['key' => 'field_290_rows', 'label' => 'Rows', 'name' => 'rows_290', 'type' => 'repeater'],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ]);

        $discovered = Dynamic_Sources::discover('single', 'post');
        $sources    = $this->by_key($discovered);

        $this->assertTrue($discovered['acf_active']);
        $this->assertSame('text', $sources['acf.subtitle_290']['type']);
        $this->assertSame('image', $sources['acf.hero_290']['type']);
        $this->assertSame('url', $sources['acf.website_290']['type']);
        $this->assertSame('html', $sources['acf.body_290']['type']);
        $this->assertSame('Dynamic 290', $sources['acf.subtitle_290']['field_group']);
        // A repeater has no single scalar to print; it is reported, not bound.
        $this->assertArrayNotHasKey('acf.rows_290', $sources);
        $this->assertContains('rows_290', array_column($discovered['skipped'], 'name'));

        // The group's location rule targets posts, so pages do not see it.
        $this->assertArrayNotHasKey('acf.subtitle_290', $this->by_key(Dynamic_Sources::discover('single', 'page')));
    }

    public function test_list_dynamic_sources_is_a_theme_read_op_on_a_licensed_site(): void
    {
        $out = $this->read('list-dynamic-sources', ['context' => 'single', 'post_type' => 'post']);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertContains('post.title', array_column($out['result']['sources'], 'key'));
        $this->assertNotEmpty($out['result']['syntax']);
    }

    public function test_list_dynamic_sources_is_absent_without_a_licence(): void
    {
        Gate::set_pro_for_tests(false);

        $names = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        $this->assertNotContains('list-dynamic-sources', $names);
        $this->assertNotContains('create-dynamic-template', $names);
        $this->assertNotContains('update-dynamic-template', $names);
        $this->assertSame('unknown_operation', $this->read('list-dynamic-sources')['error']['code']);
    }
}
