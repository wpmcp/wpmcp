<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Tools\SEO\Generate_Meta_Tags;
use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * generate-meta-tags (issue #67): a proposal (read) tool. Values come from
 * the plugin's stored fields where usable and from the post record where
 * not, each tag says which, and the rendered HTML is escaped.
 */
class GenerateMetaTagsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * The post factory fills post_excerpt even when '' is passed, so the
     * content fallback is only reachable once it is cleared after creation.
     */
    private function blank_excerpt(int $id): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_excerpt' => ''], ['ID' => $id]);
        clean_post_cache($id);
    }

    /** @return array<string, array{content: string, source: string}> */
    private function by_key(array $out): array
    {
        $map = [];
        foreach ($out['tags'] as $tag) {
            $map['' === $tag['key'] ? $tag['kind'] : $tag['key']] = $tag;
        }
        return $map;
    }

    public function test_derives_everything_from_the_post_with_no_plugin(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('');
        $id = self::factory()->post->create([
            'post_title'   => 'Plain post',
            'post_excerpt' => 'The excerpt.',
            'post_status'  => 'publish',
        ]);

        $out  = (new Generate_Meta_Tags())->handle(['post_id' => $id]);
        $tags = $this->by_key($out);

        $this->assertFalse($out['plugin_emits_tags']);
        $this->assertSame('Plain post', $tags['title']['content']);
        $this->assertSame('post', $tags['title']['source']);
        $this->assertSame('The excerpt.', $tags['description']['content']);
        $this->assertSame(get_permalink($id), $tags['canonical']['content']);
        $this->assertSame('article', $tags['og:type']['content']);
        $this->assertSame('Plain post', $tags['og:title']['content']);
        $this->assertSame('summary', $tags['twitter:card']['content'], 'no image, so the small card');
        $this->assertArrayNotHasKey('robots', $tags);
        $this->assertArrayNotHasKey('og:image', $tags);
    }

    public function test_plugin_fields_win_and_are_marked_as_plugin(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id = self::factory()->post->create(['post_title' => 'Raw title', 'post_status' => 'publish']);
        SEO_Adapter::update_meta($id, [
            'title'       => 'Curated title',
            'description' => 'Curated description',
            'canonical'   => 'https://example.com/canonical/',
            'noindex'     => true,
        ]);
        update_post_meta($id, 'rank_math_facebook_image', 'https://example.com/og.png');

        $out  = (new Generate_Meta_Tags())->handle(['post_id' => $id]);
        $tags = $this->by_key($out);

        $this->assertTrue($out['plugin_emits_tags']);
        $this->assertSame('rankmath', $out['plugin']);
        $this->assertSame('Curated title', $tags['title']['content']);
        $this->assertSame('plugin', $tags['title']['source']);
        $this->assertSame('Curated description', $tags['description']['content']);
        $this->assertSame('https://example.com/canonical/', $tags['canonical']['content']);
        $this->assertSame('https://example.com/canonical/', $tags['og:url']['content']);
        $this->assertSame('noindex', $tags['robots']['content']);
        $this->assertSame('https://example.com/og.png', $tags['og:image']['content']);
        $this->assertSame('https://example.com/og.png', $tags['twitter:image']['content'], 'RankMath mirrors OG');
        $this->assertSame('summary_large_image', $tags['twitter:card']['content']);
    }

    public function test_unrendered_template_strings_fall_back_to_the_post(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id = self::factory()->post->create([
            'post_title'   => 'Real title',
            'post_excerpt' => '',
            'post_content' => 'Body copy for the description.',
            'post_status'  => 'publish',
        ]);
        $this->blank_excerpt($id);
        SEO_Adapter::update_meta($id, ['title' => '%%title%% %%sep%% %%sitename%%', 'description' => '%%excerpt%%']);

        $tags = $this->by_key((new Generate_Meta_Tags())->handle(['post_id' => $id]));

        $this->assertSame('Real title', $tags['title']['content']);
        $this->assertSame('post', $tags['title']['source']);
        $this->assertSame('Body copy for the description.', $tags['description']['content']);
    }

    public function test_long_content_is_trimmed_on_a_word_boundary(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('');
        $id = self::factory()->post->create([
            'post_excerpt' => '',
            'post_content' => str_repeat('wordy ', 60),
            'post_status'  => 'publish',
        ]);

        $this->blank_excerpt($id);
        $description = $this->by_key((new Generate_Meta_Tags())->handle(['post_id' => $id]))['description']['content'];

        $this->assertLessThanOrEqual(163, mb_strlen($description));
        $this->assertStringEndsWith('wordy...', $description);
    }

    public function test_html_is_escaped(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id = self::factory()->post->create(['post_title' => 'Safe', 'post_status' => 'publish']);
        SEO_Adapter::update_meta($id, ['description' => 'Say "hi" & bye']);
        update_post_meta($id, 'rank_math_facebook_image', 'javascript:alert(1)');

        $html = (new Generate_Meta_Tags())->handle(['post_id' => $id])['html'];

        $this->assertStringContainsString('content="Say &quot;hi&quot; &amp; bye"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_writes_nothing(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id     = self::factory()->post->create(['post_status' => 'publish']);
        $before = get_post_meta($id);

        (new Generate_Meta_Tags())->handle(['post_id' => $id]);

        $this->assertSame($before, get_post_meta($id));
    }

    public function test_refuses_another_authors_draft(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $id    = self::factory()->post->create(['post_status' => 'draft', 'post_author' => $admin]);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->expectException(\RuntimeException::class);
        (new Generate_Meta_Tags())->handle(['post_id' => $id]);
    }
}
