<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Shared fixture for the optimize-media follow-up tests (issue #432): the
 * image editor pinned to GD through wp_image_editors, as OptimizeMediaTest
 * does, so nothing depends on Imagick; no optimizer plugin active; the paid
 * tier on; an administrator as the current user.
 */
trait OptimizeMediaFixture
{
    protected function set_up_optimize_fixture(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available on this PHP build.');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wp_image_editors', [$this, 'pin_gd_editor'], 99);
        add_filter('wpmcp_image_optimizer_plugins', [$this, 'no_optimizer_plugins']);
        wp_cache_delete('wp_image_editor_choose', 'image_editor');
    }

    protected function tear_down_optimize_fixture(): void
    {
        remove_filter('wp_image_editors', [$this, 'pin_gd_editor'], 99);
        remove_all_filters('wpmcp_image_optimizer_plugins');
        remove_all_filters('wpmcp_optimize_media_batch_size');
        remove_all_filters('wpmcp_optimize_media_time_budget');
        remove_all_filters('wpmcp_snapshot_history_limit');
        wp_cache_delete('wp_image_editor_choose', 'image_editor');
        Gate::set_pro_for_tests(null);
    }

    public function pin_gd_editor(): array
    {
        return ['WP_Image_Editor_GD'];
    }

    public function no_optimizer_plugins(): array
    {
        return [];
    }

    protected function pin_optimizer_plugin(string $name): void
    {
        remove_all_filters('wpmcp_image_optimizer_plugins');
        add_filter('wpmcp_image_optimizer_plugins', static fn () => [$name]);
    }

    protected function upload_image(string $image = 'canola.jpg'): int
    {
        return (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/' . $image);
    }

    /** @return array<string,string> absolute path => sha1 of every file the attachment owns */
    protected function file_hashes(int $id): array
    {
        clearstatcache();
        $main = (string) get_attached_file($id);
        $meta = (array) wp_get_attachment_metadata($id);
        $out  = [ $main => sha1_file($main) ];
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            $path         = dirname($main) . '/' . $size['file'];
            $out[ $path ] = sha1_file($path);
        }
        return $out;
    }

    protected function require_webp(): void
    {
        if (! \WP_Image_Editor_GD::supports_mime_type('image/webp')) {
            $this->markTestSkipped('This GD build cannot write image/webp.');
        }
    }
}
