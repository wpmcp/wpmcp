<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Find_Unused_Media;

/**
 * find-unused-media (issue #383): attachments nothing references, so a
 * Media Library clean-up does not have to open every post by hand. The
 * failure that matters is a false "unused" (the agent then deletes an image
 * a page still shows), so every location a reference can live in is covered
 * here: featured images, site logo and icon, post content by URL and by id,
 * page builder data, custom fields, term meta and options.
 */
class FindUnusedMediaTest extends \WP_UnitTestCase
{
    private array $cleanup_paths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup_paths = [];
        remove_all_filters('wpmcp_find_unused_media_time_budget');
        parent::tearDown();
    }

    private function attachment(string $slug, int $parent = 0, string $mime = 'image/jpeg'): int
    {
        $ext = 'application/pdf' === $mime ? 'pdf' : 'jpg';

        return (int) self::factory()->attachment->create_object([
            'file'           => "2024/01/{$slug}.{$ext}",
            'post_mime_type' => $mime,
            'post_title'     => $slug,
            'post_parent'    => $parent,
        ]);
    }

    /** @return int[] unused media ids across every page of a full scan. */
    private function unused_ids(array $args = []): array
    {
        $out = (new Find_Unused_Media())->handle($args + ['per_page' => 100]);
        $this->assertTrue($out['done'], 'a small library is scanned in one call');

        return array_column($out['items'], 'media_id');
    }

    private function url(int $id): string
    {
        return (string) wp_get_attachment_url($id);
    }

    public function test_reports_an_attachment_nothing_references(): void
    {
        $orphan = $this->attachment('orphan-photo');
        self::factory()->post->create(['post_content' => '<p>No images here.</p>']);

        $out = (new Find_Unused_Media())->handle([]);

        $this->assertSame([ $orphan ], array_column($out['items'], 'media_id'));
        $item = $out['items'][0];
        $this->assertSame('orphan-photo', $item['title']);
        $this->assertSame('image/jpeg', $item['mime_type']);
        $this->assertSame($this->url($orphan), $item['url']);
        $this->assertArrayHasKey('size_bytes', $item);
        $this->assertSame(1, $out['scanned']);
        $this->assertSame(1, $out['total']);
        $this->assertNull($out['next_cursor']);
        $this->assertTrue($out['done']);
    }

    public function test_each_result_states_the_checks_that_were_run(): void
    {
        $this->attachment('orphan-photo');

        $out  = (new Find_Unused_Media())->handle([]);
        $item = $out['items'][0];

        foreach ([ 'featured_image', 'site_logo_icon', 'post_content_id', 'post_content_url', 'post_meta_id', 'post_meta_url', 'term_meta', 'options' ] as $check) {
            $this->assertContains($check, $item['checks'], $check);
            $this->assertContains($check, $out['checks'], $check);
        }
        $this->assertNotEmpty($out['not_checked']);
    }

    public function test_featured_image_is_never_reported(): void
    {
        $featured = $this->attachment('featured-photo');
        $post     = self::factory()->post->create();
        set_post_thumbnail($post, $featured);

        $this->assertNotContains($featured, $this->unused_ids());
    }

    public function test_site_logo_and_site_icon_are_never_reported(): void
    {
        $logo       = $this->attachment('custom-logo');
        $icon       = $this->attachment('site-icon');
        $block_logo = $this->attachment('block-site-logo');
        $orphan     = $this->attachment('orphan-photo');

        set_theme_mod('custom_logo', $logo);
        update_option('site_icon', $icon);
        update_option('site_logo', $block_logo);

        $unused = $this->unused_ids();

        $this->assertNotContains($logo, $unused);
        $this->assertNotContains($icon, $unused);
        $this->assertNotContains($block_logo, $unused);
        $this->assertContains($orphan, $unused);
    }

    public function test_image_used_only_in_elementor_data_is_not_reported(): void
    {
        $by_url = $this->attachment('elementor-bg');
        $by_id  = $this->attachment('elementor-widget');
        $orphan = $this->attachment('orphan-photo');
        $page   = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

        // Elementor stores JSON with escaped slashes, the URL of one image
        // and only the id of the other.
        $data = wp_json_encode([
            [
                'id'       => 'a1b2c3',
                'elType'   => 'section',
                'settings' => [ 'background_image' => [ 'url' => $this->url($by_url), 'id' => '' ] ],
                'elements' => [
                    [ 'id' => 'd4e5f6', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'url' => '', 'id' => $by_id ] ] ],
                ],
            ],
        ]);
        $this->assertStringContainsString('\\/', $data);
        update_post_meta($page, '_elementor_data', wp_slash($data));

        $unused = $this->unused_ids();

        $this->assertNotContains($by_url, $unused);
        $this->assertNotContains($by_id, $unused);
        $this->assertContains($orphan, $unused);
    }

    public function test_image_used_only_in_serialized_builder_data_is_not_reported(): void
    {
        $by_id  = $this->attachment('bricks-image');
        $by_url = $this->attachment('bricks-bg');
        $page   = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

        update_post_meta($page, '_bricks_page_content_2', [
            [ 'id' => 'abc', 'name' => 'image', 'settings' => [ 'image' => [ 'id' => $by_id, 'size' => 'large' ] ] ],
            [ 'id' => 'def', 'name' => 'section', 'settings' => [ '_background' => [ 'image' => [ 'url' => $this->url($by_url) ] ] ] ],
        ]);

        $unused = $this->unused_ids();

        $this->assertNotContains($by_id, $unused);
        $this->assertNotContains($by_url, $unused);
    }

    public function test_image_referenced_in_post_content_by_url_or_id_is_not_reported(): void
    {
        $sized    = $this->attachment('content-sized');
        $by_class = $this->attachment('content-class');
        $by_block = $this->attachment('content-block');
        $gallery  = $this->attachment('content-gallery');

        $sized_url = str_replace('.jpg', '-300x200.jpg', $this->url($sized));
        self::factory()->post->create(['post_content' => '<img src="' . $sized_url . '" alt="">']);
        self::factory()->post->create(['post_content' => '<img class="wp-image-' . $by_class . '" src="https://cdn.example.com/x.jpg">']);
        self::factory()->post->create(['post_content' => '<!-- wp:image {"id":' . $by_block . ',"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->']);
        self::factory()->post->create(['post_content' => '[gallery ids="999999,' . $gallery . ',888888"]']);

        $unused = $this->unused_ids();

        $this->assertNotContains($sized, $unused);
        $this->assertNotContains($by_class, $unused);
        $this->assertNotContains($by_block, $unused);
        $this->assertNotContains($gallery, $unused);
    }

    public function test_id_match_is_exact_not_a_prefix(): void
    {
        $orphan = $this->attachment('orphan-photo');

        // "wp-image-{id}0" and "id":{id}0 belong to a different attachment.
        self::factory()->post->create(['post_content' => '<img class="wp-image-' . $orphan . '0"><!-- wp:image {"id":' . $orphan . '0} -->']);

        $this->assertContains($orphan, $this->unused_ids());
    }

    public function test_use_only_in_a_revision_does_not_count(): void
    {
        $image = $this->attachment('revision-only');
        $post  = self::factory()->post->create(['post_content' => '<img class="wp-image-' . $image . '">']);
        wp_save_post_revision($post);
        wp_update_post(['ID' => $post, 'post_content' => '<p>Image removed.</p>']);

        $this->assertContains($image, $this->unused_ids());
    }

    public function test_custom_field_and_product_gallery_references_are_not_reported(): void
    {
        $acf     = $this->attachment('acf-image');
        $gallery = $this->attachment('gallery-image');
        $post    = self::factory()->post->create();

        update_post_meta($post, 'hero_image', (string) $acf);
        update_post_meta($post, '_product_image_gallery', '11111,' . $gallery . ',22222');

        $unused = $this->unused_ids();

        $this->assertNotContains($acf, $unused);
        $this->assertNotContains($gallery, $unused);
    }

    public function test_term_meta_and_option_references_are_not_reported(): void
    {
        $term_thumb = $this->attachment('category-thumb');
        $widget     = $this->attachment('widget-image');
        $header     = $this->attachment('header-image');

        $term = self::factory()->term->create(['taxonomy' => 'category']);
        update_term_meta($term, 'thumbnail_id', (string) $term_thumb);
        update_option('widget_media_image', [ 2 => [ 'attachment_id' => $widget, 'url' => '' ] ]);
        set_theme_mod('header_image', $this->url($header));

        $unused = $this->unused_ids();

        $this->assertNotContains($term_thumb, $unused);
        $this->assertNotContains($widget, $unused);
        $this->assertNotContains($header, $unused);
    }

    public function test_type_filter_limits_the_scan(): void
    {
        $image = $this->attachment('orphan-photo');
        $pdf   = $this->attachment('orphan-document', 0, 'application/pdf');

        $this->assertSame([ $image ], $this->unused_ids(['type' => 'image']));
        $this->assertSame([ $pdf ], $this->unused_ids(['type' => 'application/pdf']));
    }

    public function test_unattached_are_returned_separately_and_may_be_used(): void
    {
        $post              = self::factory()->post->create();
        $attached_orphan   = $this->attachment('attached-orphan', $post);
        $unattached_orphan = $this->attachment('unattached-orphan');
        $unattached_used   = $this->attachment('unattached-used');
        update_post_meta($post, 'hero_image', (string) $unattached_used);

        $out = (new Find_Unused_Media())->handle(['unattached' => true]);

        $this->assertEqualsCanonicalizing([ $attached_orphan, $unattached_orphan ], array_column($out['items'], 'media_id'));

        $unattached = array_column($out['unattached'], 'used', 'media_id');
        $this->assertSame([ $unattached_orphan => false, $unattached_used => true ], $unattached);
        $this->assertArrayNotHasKey($attached_orphan, $unattached);

        $plain = (new Find_Unused_Media())->handle([]);
        $this->assertArrayNotHasKey('unattached', $plain);
    }

    public function test_large_libraries_are_scanned_in_pages_with_a_cursor(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->attachment("paged-{$i}");
        }
        self::factory()->post->create(['post_content' => '<img class="wp-image-' . $ids[2] . '">']);

        $seen   = [];
        $unused = [];
        $cursor = 0;
        $calls  = 0;
        do {
            $out = (new Find_Unused_Media())->handle(['per_page' => 2, 'cursor' => $cursor]);
            $this->assertLessThanOrEqual(2, $out['scanned']);
            $this->assertSame(5, $out['total']);
            $unused = array_merge($unused, array_column($out['items'], 'media_id'));
            $seen[] = $out['scanned'];
            $cursor = $out['next_cursor'];
            $calls++;
        } while (! $out['done'] && $calls < 10);

        $this->assertSame(3, $calls);
        $this->assertSame([ 2, 2, 1 ], $seen);
        $this->assertNull($out['next_cursor']);
        $this->assertSame(0, $out['remaining']);
        $this->assertSame([ $ids[0], $ids[1], $ids[3], $ids[4] ], $unused);
    }

    public function test_per_page_is_capped(): void
    {
        $out = (new Find_Unused_Media())->handle(['per_page' => 100000]);

        $this->assertSame(Find_Unused_Media::MAX_PER_PAGE, $out['per_page']);
    }

    public function test_time_budget_stops_the_page_early_but_always_makes_progress(): void
    {
        $first  = $this->attachment('budget-0');
        $second = $this->attachment('budget-1');
        $this->attachment('budget-2');

        add_filter('wpmcp_find_unused_media_time_budget', '__return_zero');

        $out = (new Find_Unused_Media())->handle(['per_page' => 50]);

        $this->assertSame(1, $out['scanned']);
        $this->assertTrue($out['stopped_early']);
        $this->assertFalse($out['done']);
        $this->assertSame($first, $out['next_cursor']);
        $this->assertSame(2, $out['remaining']);

        $next = (new Find_Unused_Media())->handle(['per_page' => 50, 'cursor' => $out['next_cursor']]);
        $this->assertSame([ $second ], array_column($next['items'], 'media_id'));
    }

    public function test_size_on_disk_counts_every_file_and_totals_the_page(): void
    {
        $uploads = wp_upload_dir();
        $main    = trailingslashit($uploads['path']) . 'wpmcp-unused-media-test.jpg';
        $thumb   = trailingslashit($uploads['path']) . 'wpmcp-unused-media-test-150x150.jpg';
        wp_mkdir_p(dirname($main));
        file_put_contents($main, str_repeat('a', 1000));
        file_put_contents($thumb, str_repeat('b', 200));
        $this->cleanup_paths[] = $main;
        $this->cleanup_paths[] = $thumb;

        $id = (int) self::factory()->attachment->create_object([
            'file'           => $main,
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'On disk',
        ]);
        wp_update_attachment_metadata($id, [
            'width'  => 300,
            'height' => 300,
            'file'   => _wp_relative_upload_path($main),
            'sizes'  => [
                'thumbnail' => [ 'file' => basename($thumb), 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ],
            ],
        ]);
        $this->attachment('no-file-on-disk');

        $out   = (new Find_Unused_Media())->handle([]);
        $sizes = array_column($out['items'], 'size_bytes', 'media_id');

        $this->assertSame(1200, $sizes[ $id ]);
        $this->assertSame(1200, $out['total_bytes']);
    }

    public function test_trashed_attachments_are_skipped(): void
    {
        $orphan = $this->attachment('orphan-photo');
        $gone   = $this->attachment('trashed-photo');
        wp_update_post(['ID' => $gone, 'post_status' => 'trash']);

        $this->assertSame([ $orphan ], $this->unused_ids());
    }
}
