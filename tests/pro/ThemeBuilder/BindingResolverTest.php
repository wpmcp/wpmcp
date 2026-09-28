<?php

namespace WPMCP\Tests\Pro\ThemeBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\ThemeBuilder\Dynamic\Binding_Resolver;

/**
 * Binding tokens (issue #290): `{{group.field}}` placeholders in template
 * markup, resolved at render time with the escaping their field type needs.
 * Text is esc_html'd, URLs esc_url'd, rich HTML wp_kses_post'd, images
 * printed through wp_get_attachment_image(); inside a tag (an attribute
 * value) every type collapses to an attribute-safe string. Resolution is a
 * single pass, so a value that itself looks like a token is printed, never
 * resolved.
 */
class BindingResolverTest extends \WP_UnitTestCase
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

    // ---- escaping per type ---------------------------------------------------

    public function test_text_is_html_escaped(): void
    {
        $this->assertSame('Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;', Binding_Resolver::escape('text', 'Tom & <b>Jerry</b>', false));
    }

    public function test_url_is_url_escaped_and_a_script_url_is_dropped(): void
    {
        $this->assertSame(esc_url('https://example.com/a b'), Binding_Resolver::escape('url', 'https://example.com/a b', false));
        $this->assertSame('', Binding_Resolver::escape('url', 'javascript:alert(1)', false));
        $this->assertSame('', Binding_Resolver::escape('url', 'javascript:alert(1)', true));
    }

    public function test_rich_html_is_filtered_with_wp_kses_post(): void
    {
        $out = Binding_Resolver::escape('html', '<p onclick="x()">Hi <strong>there</strong></p><script>alert(1)</script>', false);

        $this->assertStringContainsString('<strong>there</strong>', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    public function test_an_image_is_printed_through_wp_get_attachment_image(): void
    {
        $attachment = (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $out = Binding_Resolver::escape('image', $attachment, false);

        $this->assertSame(wp_get_attachment_image($attachment, 'large'), $out);
        $this->assertStringStartsWith('<img', $out);
        // In an attribute an image is its URL, never a tag.
        $this->assertSame(esc_url((string) wp_get_attachment_image_url($attachment, 'large')), Binding_Resolver::escape('image', $attachment, true));
    }

    public function test_every_type_is_attribute_safe_inside_a_tag(): void
    {
        $this->assertSame('say &quot;hi&quot;', Binding_Resolver::escape('text', 'say "hi"', true));
        $this->assertSame('bold', Binding_Resolver::escape('html', '<b>bold</b>', true));
        $this->assertStringNotContainsString('"', Binding_Resolver::escape('content', '<p class="x">"quoted"</p>', true));
    }

    // ---- resolving a template ------------------------------------------------

    public function test_post_fields_resolve_with_escaping_by_position(): void
    {
        $post = self::factory()->post->create_and_get(['post_title' => 'Fish "&" Chips']);

        $out = Binding_Resolver::resolve(
            '<h1>{{post.title}}</h1><a href="{{ post.permalink }}" title="{{post.title}}">more</a>',
            $post
        );

        $this->assertStringContainsString('<h1>' . esc_html(get_the_title($post)) . '</h1>', $out);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($post)) . '"', $out);
        $this->assertStringContainsString('title="' . esc_attr(wp_strip_all_tags(get_the_title($post))) . '"', $out);
        $this->assertStringNotContainsString('{{', $out);
    }

    public function test_featured_image_terms_author_and_site_fields_resolve(): void
    {
        $author     = self::factory()->user->create(['display_name' => 'Ada Author']);
        $post       = self::factory()->post->create_and_get(['post_author' => $author]);
        $category   = self::factory()->category->create(['name' => 'Recipes 290']);
        $attachment = (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_set_post_categories($post->ID, [$category]);
        set_post_thumbnail($post->ID, $attachment);
        update_option('blogname', 'Site & Co');

        $out = Binding_Resolver::resolve(
            '{{post.featured_image}}|{{post.terms.category}}|{{post.author}}|{{site.name}}|{{post.date}}',
            $post
        );

        $this->assertStringContainsString(wp_get_attachment_image($attachment, 'large'), $out);
        $this->assertStringContainsString('Recipes 290', $out);
        $this->assertStringContainsString('Ada Author', $out);
        $this->assertStringContainsString('Site &amp; Co', $out);
        $this->assertStringContainsString(esc_html(get_the_date('', $post)), $out);
    }

    public function test_a_value_that_looks_like_a_token_is_printed_not_resolved(): void
    {
        update_option('blogname', 'Secret Site Name 290');
        $post = self::factory()->post->create_and_get(['post_title' => 'Title {{site.name}}']);

        $out = Binding_Resolver::resolve('<h1>{{post.title}}</h1>', $post);

        $this->assertStringContainsString('{{site.name}}', $out);
        $this->assertStringNotContainsString('Secret Site Name 290', $out);
    }

    public function test_a_field_with_no_value_resolves_to_nothing(): void
    {
        add_filter('wpmcp_dynamic_sources_acf_active', '__return_false');
        $post = self::factory()->post->create_and_get();

        $this->assertSame('<p></p><p></p>', Binding_Resolver::resolve('<p>{{post.featured_image}}</p><p>{{acf.missing}}</p>', $post));
        $this->assertSame('<p></p>', Binding_Resolver::resolve('<p>{{post.title}}</p>', null));
    }

    public function test_the_loop_repeats_its_body_for_each_post(): void
    {
        $a = self::factory()->post->create_and_get(['post_title' => 'Loop A 290']);
        $b = self::factory()->post->create_and_get(['post_title' => 'Loop B 290']);

        $out = Binding_Resolver::resolve('<ul>{{#loop}}<li>{{post.title}}</li>{{/loop}}</ul>', null, [$a, $b]);

        $this->assertSame('<ul><li>Loop A 290</li><li>Loop B 290</li></ul>', $out);
    }

    public function test_acf_fields_resolve_by_field_type(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            $this->markTestSkipped('ACF not active');
        }
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_290_resolve',
            'title'    => 'Resolve 290',
            'fields'   => [
                ['key' => 'field_290r_text', 'label' => 'Text', 'name' => 'text_290', 'type' => 'text'],
                ['key' => 'field_290r_url', 'label' => 'Url', 'name' => 'url_290', 'type' => 'url'],
                ['key' => 'field_290r_body', 'label' => 'Body', 'name' => 'body_290', 'type' => 'wysiwyg'],
                ['key' => 'field_290r_image', 'label' => 'Image', 'name' => 'image_290', 'type' => 'image', 'return_format' => 'array'],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ]);
        $post       = self::factory()->post->create_and_get();
        $attachment = (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_field('field_290r_text', '<em>raw</em> text', $post->ID);
        update_field('field_290r_url', 'javascript:alert(1)', $post->ID);
        update_field('field_290r_body', '<p>Rich <strong>body</strong></p><script>alert(1)</script>', $post->ID);
        update_field('field_290r_image', $attachment, $post->ID);

        $out = Binding_Resolver::resolve(
            '<div>{{acf.text_290}}</div><a href="{{acf.url_290}}">x</a><div>{{acf.body_290}}</div><div>{{acf.image_290}}</div>',
            $post
        );

        $this->assertStringContainsString('&lt;em&gt;raw&lt;/em&gt; text', $out);
        $this->assertStringContainsString('href=""', $out);
        $this->assertStringContainsString('<strong>body</strong>', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString(wp_get_attachment_image($attachment, 'large'), $out);
    }

    public function test_site_and_archive_sources_resolve_from_the_live_request(): void
    {
        update_option('blogdescription', 'Tag <line>');
        $logo = (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        set_theme_mod('custom_logo', $logo);
        $category = self::factory()->category->create(['name' => 'Cat 290', 'description' => 'About <em>cats</em><script>x</script>']);
        self::factory()->post->create_many(3, ['post_category' => [$category]]);
        update_option('posts_per_page', 2);
        $this->go_to(get_category_link($category));

        $out = Binding_Resolver::resolve(
            '{{site.tagline}}|<a href="{{site.url}}">{{site.logo}}</a>|{{archive.description}}|{{loop.count}}|{{loop.pagination}}',
            null
        );

        $this->assertStringContainsString(esc_html(get_bloginfo('description')), $out);
        $this->assertStringContainsString('href="' . esc_url(home_url('/')) . '"', $out);
        $this->assertStringContainsString(wp_get_attachment_image($logo, 'large'), $out);
        $this->assertStringContainsString('<em>cats</em>', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('|3|', $out);
        $this->assertStringContainsString('page-numbers', $out);
        remove_theme_mod('custom_logo');
    }

    public function test_post_content_and_url_sources_resolve(): void
    {
        $post = self::factory()->post->create_and_get(['post_content' => 'Body copy 290', 'post_excerpt' => 'Short 290']);

        $out = Binding_Resolver::resolve('{{post.content}}|{{post.excerpt}}|{{post.author_url}}|{{post.featured_image_url}}', $post);

        $this->assertStringContainsString('<p>Body copy 290</p>', $out);
        $this->assertStringContainsString('Short 290', $out);
        $this->assertStringContainsString(esc_url(get_author_posts_url((int) $post->post_author)), $out);
    }

    public function test_the_render_filter_binds_the_queried_post_and_the_main_query_loop(): void
    {
        $post = self::factory()->post->create_and_get(['post_title' => 'Queried 290']);
        $this->go_to(get_permalink($post));

        $this->assertSame('<p>Queried 290</p>', Binding_Resolver::filter_rendered('<p>{{post.title}}</p>', ['part_type' => 'single']));
        $this->assertSame('<p>static</p>', Binding_Resolver::filter_rendered('<p>static</p>', ['part_type' => 'header']));

        $this->go_to(home_url('/?s=Queried'));
        $this->assertSame('<i>Queried 290</i>', Binding_Resolver::filter_rendered('{{#loop}}<i>{{post.title}}</i>{{/loop}}', ['part_type' => 'search']));
    }

    public function test_acf_choice_link_and_boolean_values_print_as_text_or_url(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            $this->markTestSkipped('ACF not active');
        }
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_290_choice',
            'title'    => 'Choice 290',
            'fields'   => [
                ['key' => 'field_290c_pick', 'label' => 'Pick', 'name' => 'pick_290', 'type' => 'checkbox', 'choices' => ['red' => 'Red', 'blue' => 'Blue']],
                ['key' => 'field_290c_one', 'label' => 'One', 'name' => 'one_290', 'type' => 'select', 'choices' => ['a' => 'Alpha'], 'return_format' => 'array'],
                ['key' => 'field_290c_flag', 'label' => 'Flag', 'name' => 'flag_290', 'type' => 'true_false'],
                ['key' => 'field_290c_link', 'label' => 'Link', 'name' => 'link_290', 'type' => 'link'],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ]);
        $post = self::factory()->post->create_and_get();
        update_field('field_290c_pick', ['red', 'blue'], $post->ID);
        update_field('field_290c_one', 'a', $post->ID);
        update_field('field_290c_flag', 1, $post->ID);
        update_field('field_290c_link', ['url' => 'https://example.com/290', 'title' => 'x', 'target' => ''], $post->ID);

        $out = Binding_Resolver::resolve('{{acf.pick_290}}|{{acf.one_290}}|{{acf.flag_290}}|{{acf.link_290}}', $post);

        $this->assertSame('red, blue|Alpha|1|https://example.com/290', $out);
    }

    // ---- validating a template on write -------------------------------------

    public function test_validate_accepts_known_tokens_and_refuses_unknown_ones(): void
    {
        $this->assertTrue(Binding_Resolver::validate('<h1>{{post.title}}</h1>{{site.name}}', 'single'));

        $err = Binding_Resolver::validate('<h1>{{post.titel}}</h1>', 'single');
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('wpmcp_unknown_binding', $err->get_error_code());
    }

    public function test_validate_refuses_tokens_from_another_context(): void
    {
        $this->assertInstanceOf(\WP_Error::class, Binding_Resolver::validate('{{search.query}}', 'single'));
        $this->assertTrue(Binding_Resolver::validate('{{search.query}}', 'search'));
    }

    public function test_validate_checks_the_loop(): void
    {
        $this->assertTrue(Binding_Resolver::validate('{{#loop}}{{post.title}}{{/loop}}', 'archive'));
        // A single template has no loop to repeat over.
        $this->assertInstanceOf(\WP_Error::class, Binding_Resolver::validate('{{#loop}}{{post.title}}{{/loop}}', 'single'));
        // Unbalanced and nested loops are refused.
        $this->assertInstanceOf(\WP_Error::class, Binding_Resolver::validate('{{#loop}}{{post.title}}', 'archive'));
        $this->assertInstanceOf(\WP_Error::class, Binding_Resolver::validate('{{#loop}}{{#loop}}x{{/loop}}{{/loop}}', 'archive'));
    }

    public function test_validate_refuses_acf_tokens_without_acf(): void
    {
        add_filter('wpmcp_dynamic_sources_acf_active', '__return_false');

        $this->assertInstanceOf(\WP_Error::class, Binding_Resolver::validate('{{acf.subtitle}}', 'single'));
    }
}
