<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Set_Social_Image;
use WPMCP\Tools\SEO\Social_Meta;

/**
 * set-social-image (issue #67): a snapshot-first write of the OpenGraph and
 * Twitter images through the active plugin's own keys, reversible through
 * rollback-operation, with the structured "unsupported" answer on plugins
 * whose social storage is not mapped.
 */
class SetSocialImageTest extends \WP_UnitTestCase
{
    private const URL = 'https://example.com/share.png';

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function image_attachment(): int
    {
        return self::factory()->attachment->create([
            'post_mime_type' => 'image/png',
            'file'           => 'share.png',
            'post_title'     => 'Share image',
        ]);
    }

    public function test_url_write_sets_both_images_on_every_mapped_plugin(): void
    {
        foreach (['yoast', 'rankmath', 'seopress'] as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $post_id = self::factory()->post->create();

            $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL]);

            $this->assertTrue($out['supported'], $plugin);
            $this->assertSame('both', $out['target']);
            $this->assertSame(self::URL, $out['fields']['og_image'], $plugin);
            $this->assertSame(self::URL, $out['fields']['twitter_image'], $plugin);
            $this->assertSame('override', $out['sources']['og_image'], $plugin);
            $this->assertArrayHasKey('operation_id', $out);
        }
    }

    public function test_attachment_write_stores_url_and_id_on_yoast(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $post_id = self::factory()->post->create();
        $att     = $this->image_attachment();

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'attachment_id' => $att, 'target' => 'og']);

        $url = wp_get_attachment_url($att);
        $this->assertSame($url, get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true));
        $this->assertSame((string) $att, get_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', true));
        $this->assertSame('', get_post_meta($post_id, '_yoast_wpseo_twitter-image', true), 'og target leaves twitter alone');
        $this->assertSame($att, $out['attachment_id']);
    }

    public function test_url_write_clears_a_stale_attachment_id(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'rank_math_facebook_image_id', '999');

        (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL, 'target' => 'og']);

        $this->assertSame('', get_post_meta($post_id, 'rank_math_facebook_image_id', true));
    }

    /**
     * On RankMath a twitter-only image is invisible while the post mirrors
     * Twitter from OpenGraph, so the mirror goes off, and the copy the card
     * was inheriting is kept as explicit overrides.
     */
    public function test_rankmath_twitter_only_write_switches_off_the_mirror_and_keeps_copy(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'rank_math_facebook_title', 'Shared title');
        update_post_meta($post_id, 'rank_math_facebook_description', 'Shared copy');

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL, 'target' => 'twitter']);

        $this->assertSame('off', get_post_meta($post_id, 'rank_math_twitter_use_facebook', true));
        $this->assertSame('Shared title', $out['fields']['twitter_title']);
        $this->assertSame('override', $out['sources']['twitter_title']);
        $this->assertSame('Shared copy', $out['fields']['twitter_description']);
        $this->assertSame(self::URL, $out['fields']['twitter_image']);
        $this->assertSame('', $out['fields']['og_image']);
    }

    public function test_write_is_snapshotted_and_rollback_restores_the_previous_image(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'rank_math_facebook_image', 'https://example.com/old.png');

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL, 'target' => 'twitter']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertTrue($out['recoverable']);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);

        $after = Social_Meta::get($post_id);
        $this->assertSame('https://example.com/old.png', $after['fields']['og_image']);
        $this->assertSame('inherited', $after['sources']['twitter_image'], 'mirror switch restored with it');
        $this->assertSame('', get_post_meta($post_id, 'rank_math_twitter_use_facebook', true));
    }

    public function test_unmapped_plugin_answers_unsupported_and_writes_nothing(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $post_id = self::factory()->post->create();

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL]);

        $this->assertFalse($out['supported']);
        $this->assertSame('seoframework', $out['plugin']);
        $this->assertArrayNotHasKey('operation_id', $out);
    }

    /** @return array<string, array{0: string}> */
    public function bad_urls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'relative'   => ['/wp-content/uploads/a.png'],
            'data'       => ['data:image/png;base64,AAAA'],
            'empty'      => [''],
        ];
    }

    /** @dataProvider bad_urls */
    public function test_rejects_non_http_urls(string $url): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $post_id = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);
        (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => $url]);
    }

    public function test_rejects_a_non_image_attachment(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $post_id = self::factory()->post->create();
        $pdf     = self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'file' => 'doc.pdf']);

        $this->expectException(\InvalidArgumentException::class);
        (new Set_Social_Image())->handle(['post_id' => $post_id, 'attachment_id' => $pdf]);
    }

    public function test_rejects_an_unknown_target(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $post_id = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);
        (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL, 'target' => 'pinterest']);
    }

    /** edit_posts on the ability does not reach someone else's post. */
    public function test_contributor_cannot_set_the_image_of_another_authors_post(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $post_id = self::factory()->post->create(['post_author' => $this->admin, 'post_status' => 'publish']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->expectException(\RuntimeException::class);
        (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL]);
    }
}
