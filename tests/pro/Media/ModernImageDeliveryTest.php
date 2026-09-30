<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\Tools\Media\Optimize_Media;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * Front-end delivery of the WebP/AVIF copies optimize-media writes (issue
 * #432). With wpmcp_serve_modern_images on (off by default), an image whose
 * every candidate has a copy is wrapped in <picture> with a <source> per
 * format and the original <img> kept as the fallback; anything else is left
 * exactly as it was.
 *
 * The markup does not depend on the request (no Accept negotiation, no Vary),
 * so a page cache stores one answer that is right for every browser; and an
 * active optimizer plugin, which serves its own copies, is deferred to.
 */
class ModernImageDeliveryTest extends \WP_UnitTestCase
{
    use OptimizeMediaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up_optimize_fixture();
        $this->require_webp();
        update_option('wpmcp_serve_modern_images', true);
    }

    protected function tearDown(): void
    {
        delete_option('wpmcp_serve_modern_images');
        unset($_SERVER['HTTP_ACCEPT']);
        $this->tear_down_optimize_fixture();
        parent::tearDown();
    }

    private function optimized_image(): int
    {
        $id = $this->upload_image();
        (new Optimize_Media())->handle(['media_id' => $id, 'formats' => ['webp']]);
        return $id;
    }

    /** Post content holding the attachment's full-size image, as the block editor writes it. */
    private function content(int $id): string
    {
        $meta = wp_get_attachment_metadata($id);
        return sprintf(
            '<figure class="wp-block-image size-full"><img src="%s" alt="" class="wp-image-%d" width="%d" height="%d"/></figure>',
            esc_url((string) wp_get_attachment_url($id)),
            $id,
            (int) $meta['width'],
            (int) $meta['height']
        );
    }

    private function render(string $content): string
    {
        return (string) apply_filters('the_content', $content);
    }

    public function test_a_content_image_with_copies_is_served_through_picture_with_the_original_as_fallback(): void
    {
        $id  = $this->optimized_image();
        $out = $this->render($this->content($id));

        $this->assertSame(1, substr_count($out, '<picture'), $out);
        $this->assertMatchesRegularExpression('#<source type="image/webp" srcset="[^"]*' . preg_quote(basename((string) get_attached_file($id)), '#') . '\.webp#', $out);
        $this->assertStringContainsString('src="' . wp_get_attachment_url($id) . '"', $out, 'The original stays the <img> fallback.');
        $this->assertMatchesRegularExpression('#<picture[^>]*>\s*<source[^>]*>\s*<img #', $out);
        // Every candidate in the WebP srcset is a copy that exists.
        preg_match('#<source type="image/webp" srcset="([^"]*)"#', $out, $m);
        $uploads = wp_get_upload_dir();
        foreach (array_map('trim', explode(',', $m[1])) as $candidate) {
            $url = strtok($candidate, ' ');
            $this->assertFileExists(str_replace($uploads['baseurl'], $uploads['basedir'], $url));
        }
    }

    public function test_an_image_without_copies_is_left_alone(): void
    {
        $id      = $this->upload_image();
        $content = $this->content($id);

        $this->assertStringNotContainsString('<picture', $this->render($content));
    }

    public function test_a_missing_copy_for_any_candidate_drops_that_format(): void
    {
        $id   = $this->optimized_image();
        $meta = wp_get_attachment_metadata($id);
        $size = $meta['sizes']['medium'];
        wp_delete_file(dirname((string) get_attached_file($id)) . '/' . $size['file'] . '.webp');

        $this->assertStringNotContainsString('<picture', $this->render($this->content($id)));
    }

    public function test_off_by_default(): void
    {
        delete_option('wpmcp_serve_modern_images');
        $id = $this->optimized_image();

        $this->assertStringNotContainsString('<picture', $this->render($this->content($id)));
        $this->assertStringNotContainsString('<picture', wp_get_attachment_image($id, 'full'));
    }

    public function test_the_markup_does_not_depend_on_the_request_so_a_page_cache_can_store_it(): void
    {
        $id = $this->optimized_image();

        $_SERVER['HTTP_ACCEPT'] = 'image/png,image/*;q=0.8';
        $old_browser            = $this->render($this->content($id));
        $_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp,*/*';
        $new_browser            = $this->render($this->content($id));

        $this->assertSame($old_browser, $new_browser);
        $this->assertStringContainsString('<picture', $new_browser);
    }

    public function test_defers_to_an_active_optimizer_plugin(): void
    {
        $id = $this->optimized_image();
        $this->pin_optimizer_plugin('Acme Image Compressor');

        $this->assertStringNotContainsString('<picture', $this->render($this->content($id)));
        $this->assertStringNotContainsString('<picture', wp_get_attachment_image($id, 'full'));
    }

    public function test_an_image_already_inside_picture_is_not_wrapped_again(): void
    {
        $id      = $this->optimized_image();
        $content = '<picture><source srcset="https://example.com/x.avif" type="image/avif">' . $this->content($id) . '</picture>';

        $this->assertSame(1, substr_count($this->render($content), '<picture'));
    }

    public function test_lazy_loader_markup_is_left_alone(): void
    {
        $id      = $this->optimized_image();
        $content = str_replace('<img src=', '<img data-src="' . esc_url((string) wp_get_attachment_url($id)) . '" src=', $this->content($id));

        $this->assertStringNotContainsString('<picture', $this->render($content));
    }

    public function test_attachment_images_rendered_by_the_theme_are_served_through_picture(): void
    {
        $id  = $this->optimized_image();
        $out = wp_get_attachment_image($id, 'medium');

        $this->assertStringStartsWith('<picture', $out);
        $this->assertStringContainsString('type="image/webp"', $out);
        $this->assertStringContainsString('<img ', $out);
    }

    public function test_nothing_is_served_without_the_paid_tier(): void
    {
        $id = $this->optimized_image();
        \WPMCP\Pro\Gate::set_pro_for_tests(false);

        $this->assertStringNotContainsString('<picture', $this->render($this->content($id)));
    }
}
