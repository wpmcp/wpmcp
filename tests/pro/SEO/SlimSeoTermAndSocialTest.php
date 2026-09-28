<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Set_Social_Image;
use WPMCP\Tools\SEO\Social_Meta;
use WPMCP\Tools\SEO\Term_SEO;
use WPMCP\Tools\SEO\Update_Term_SEO_Meta;

/**
 * Slim SEO's term and social fields (issue #294). Terms use the same
 * `slim_seo` array as posts, in term meta. Social is two images,
 * facebook_image and twitter_image; the Open Graph title and description
 * are the SEO title and description, and the Twitter card reads the Open
 * Graph tags, so those four fields are reported as inherited.
 */
class SlimSeoTermAndSocialTest extends \WP_UnitTestCase
{
    private const URL = 'https://example.com/slim-share.png';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_term_fields_round_trip_in_term_meta(): void
    {
        $term = get_term(self::factory()->category->create(), 'category');

        Term_SEO::update($term, [
            'title'       => 'Slim term',
            'description' => 'Slim term copy',
            'canonical'   => 'https://example.com/slim-term/',
            'noindex'     => true,
        ]);

        $this->assertSame([
            'title'       => 'Slim term',
            'description' => 'Slim term copy',
            'canonical'   => 'https://example.com/slim-term/',
            'noindex'     => 1,
        ], get_term_meta($term->term_id, 'slim_seo', true));

        $out = Term_SEO::get($term);
        $this->assertTrue($out['supported']);
        $this->assertSame('slimseo', $out['plugin']);
        $this->assertSame(['focus_keyword', 'nofollow'], $out['unsupported_fields']);
        $this->assertSame('Slim term', $out['fields']['title']);
        $this->assertTrue($out['fields']['noindex']);
    }

    public function test_term_write_rolls_back_through_the_term_snapshot(): void
    {
        $term = get_term(self::factory()->category->create(), 'category');
        update_term_meta($term->term_id, 'slim_seo', ['title' => 'Before']);

        $out = (new Update_Term_SEO_Meta())->handle([
            'taxonomy' => 'category',
            'term_id'  => $term->term_id,
            'title'    => 'After',
            'nofollow' => true,
        ]);
        $this->assertSame(['title'], $out['written']);
        $this->assertSame(['nofollow'], $out['skipped_fields']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame(['title' => 'Before'], get_term_meta($term->term_id, 'slim_seo', true));
    }

    public function test_social_reads_images_and_inherits_the_copy(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'slim_seo', [
            'title'          => 'Slim title',
            'description'    => 'Slim copy',
            'facebook_image' => self::URL,
        ]);

        $out = Social_Meta::get($post_id);

        $this->assertTrue($out['supported']);
        $this->assertSame('slimseo', $out['plugin']);
        $this->assertSame(self::URL, $out['fields']['og_image']);
        $this->assertSame('override', $out['sources']['og_image']);
        $this->assertSame('Slim title', $out['fields']['og_title']);
        $this->assertSame('inherited', $out['sources']['og_title']);
        $this->assertSame('Slim copy', $out['fields']['twitter_description']);
        $this->assertSame('inherited', $out['sources']['twitter_description']);
        $this->assertSame('', $out['fields']['twitter_image']);
        $this->assertSame('absent', $out['sources']['twitter_image']);
    }

    public function test_set_social_image_writes_both_keys_and_rolls_back(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'slim_seo', ['title' => 'Kept']);

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL]);

        $this->assertTrue($out['supported']);
        $stored = get_post_meta($post_id, 'slim_seo', true);
        $this->assertSame(self::URL, $stored['facebook_image']);
        $this->assertSame(self::URL, $stored['twitter_image']);
        $this->assertSame('Kept', $stored['title']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame(['title' => 'Kept'], get_post_meta($post_id, 'slim_seo', true));
    }
}
