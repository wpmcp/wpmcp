<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Optimize_Media;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * A GD editor that cannot write WebP or AVIF, standing in for a server whose
 * image library was built without them. Lets the unsupported-format path be
 * tested on any machine, whatever its real GD or Imagick build supports.
 */
class Optimize_Media_Gd_Without_Modern_Formats extends \WP_Image_Editor_GD
{
    public static function supports_mime_type($mime_type)
    {
        if (in_array($mime_type, ['image/webp', 'image/avif'], true)) {
            return false;
        }
        return parent::supports_mime_type($mime_type);
    }
}

/**
 * optimize-media (issue #380, PRO): recompress Media Library images, cap
 * their longest edge and write WebP/AVIF copies, all through the server's
 * own image editor and all undoable.
 *
 * Every test pins the editor to GD through the wp_image_editors filter, so
 * nothing here depends on Imagick being installed; a machine without GD
 * skips rather than fails.
 */
class OptimizeMediaTest extends \WP_UnitTestCase
{
    /** @var string[] */
    private array $editors = ['WP_Image_Editor_GD'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available on this PHP build.');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->editors = ['WP_Image_Editor_GD'];
        add_filter('wp_image_editors', [$this, 'pin_editors'], 99);
        // No optimizer plugin is active in the test site; pin that so a
        // plugin installed for other suites cannot change these results.
        add_filter('wpmcp_image_optimizer_plugins', [$this, 'no_optimizers']);
        wp_cache_delete('wp_image_editor_choose', 'image_editor');
    }

    protected function tearDown(): void
    {
        remove_filter('wp_image_editors', [$this, 'pin_editors'], 99);
        remove_filter('wpmcp_image_optimizer_plugins', [$this, 'no_optimizers']);
        remove_all_filters('wpmcp_optimize_media_batch_size');
        remove_all_filters('wpmcp_optimize_media_time_budget');
        wp_cache_delete('wp_image_editor_choose', 'image_editor');
        parent::tearDown();
    }

    public function pin_editors(): array
    {
        return $this->editors;
    }

    public function no_optimizers(): array
    {
        return [];
    }

    private function upload(string $image = 'canola.jpg'): int
    {
        return (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/' . $image);
    }

    /** @return array<string,string> absolute path => sha1 of every file the attachment owns */
    private function hashes(int $id): array
    {
        $out  = [];
        $main = (string) get_attached_file($id);
        $meta = (array) wp_get_attachment_metadata($id);
        $out[ $main ] = sha1_file($main);
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            $path         = dirname($main) . '/' . $size['file'];
            $out[ $path ] = sha1_file($path);
        }
        return $out;
    }

    private function require_gd_format(string $mime): void
    {
        if (! \WP_Image_Editor_GD::supports_mime_type($mime)) {
            $this->markTestSkipped("This GD build cannot write {$mime}.");
        }
    }

    public function test_optimizing_reduces_size_and_records_before_and_after_bytes(): void
    {
        $id  = $this->upload();
        $out = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40]);

        $this->assertFalse($out['dry_run']);
        $this->assertCount(1, $out['items']);
        $item = $out['items'][0];
        $this->assertSame($id, $item['media_id']);
        $this->assertNotEmpty($item['operation_id']);
        $this->assertGreaterThan(0, $item['before_bytes']);
        $this->assertLessThan($item['before_bytes'], $item['after_bytes']);
        $this->assertSame($item['before_bytes'] - $item['after_bytes'], $item['saved_bytes']);
        $this->assertSame($item['saved_bytes'], $out['totals']['saved_bytes']);

        // The bytes on disk agree with the report.
        clearstatcache();
        $on_disk = 0;
        foreach (array_keys($this->hashes($id)) as $path) {
            $on_disk += (int) filesize($path);
        }
        $this->assertSame($item['after_bytes'], $on_disk);

        // And the attachment remembers what was done to it.
        $record = get_post_meta($id, '_wpmcp_optimized', true);
        $this->assertIsArray($record);
        $this->assertSame($item['before_bytes'], $record['before_bytes']);
        $this->assertSame($item['after_bytes'], $record['after_bytes']);
    }

    public function test_never_replaces_a_file_with_a_larger_one(): void
    {
        $id    = $this->upload();
        $sizes = [];
        foreach (array_keys($this->hashes($id)) as $path) {
            $sizes[ $path ] = (int) filesize($path);
        }

        $out = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 100]);

        clearstatcache();
        foreach ($sizes as $path => $bytes) {
            $this->assertLessThanOrEqual($bytes, (int) filesize($path), basename($path) . ' grew');
        }
        $item = $out['items'][0];
        $this->assertLessThanOrEqual($item['before_bytes'], $item['after_bytes']);
        $this->assertGreaterThanOrEqual(0, $item['saved_bytes']);
    }

    public function test_generates_webp_copies_of_the_original_and_every_size_with_gd(): void
    {
        $this->require_gd_format('image/webp');
        $id  = $this->upload();
        $out = (new Optimize_Media())->handle(['media_id' => $id, 'formats' => ['webp']]);

        $item = $out['items'][0];
        $this->assertArrayHasKey('webp', $item['generated']);
        $this->assertGreaterThan(0, $item['generated']['webp']['bytes']);
        $this->assertSame([], $out['unsupported']);

        foreach (array_keys($this->hashes($id)) as $path) {
            $copy = $path . '.webp';
            if (in_array(basename($copy), $item['generated']['webp']['files'], true)) {
                $this->assertFileExists($copy);
                $this->assertSame('image/webp', wp_get_image_mime($copy));
            }
        }
        $main = (string) get_attached_file($id);
        $this->assertContains(basename($main) . '.webp', $item['generated']['webp']['files']);
    }

    public function test_rollback_restores_originals_byte_for_byte_and_removes_generated_copies(): void
    {
        $this->require_gd_format('image/webp');
        $id          = $this->upload();
        $before      = $this->hashes($id);
        $before_meta = wp_get_attachment_metadata($id);

        $out  = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40, 'formats' => ['webp'], 'max_edge' => 300]);
        $item = $out['items'][0];
        $this->assertNotSame($before, $this->hashes($id));
        $copies = array_map(
            static fn ($name) => dirname((string) get_attached_file($id)) . '/' . $name,
            $item['generated']['webp']['files']
        );
        $this->assertNotEmpty($copies);

        $this->assertTrue(Rollback_Service::restore_operation($item['operation_id']));
        clearstatcache();

        $this->assertSame($before, $this->hashes($id));
        $this->assertEquals($before_meta, wp_get_attachment_metadata($id));
        $this->assertSame('', get_post_meta($id, '_wpmcp_optimized', true));
        foreach ($copies as $copy) {
            $this->assertFileDoesNotExist($copy);
        }
    }

    public function test_max_edge_caps_the_longest_side_and_updates_metadata(): void
    {
        $id = $this->upload();
        (new Optimize_Media())->handle(['media_id' => $id, 'max_edge' => 300]);

        $meta = wp_get_attachment_metadata($id);
        $this->assertLessThanOrEqual(300, max((int) $meta['width'], (int) $meta['height']));
        $size = wp_getimagesize((string) get_attached_file($id));
        $this->assertSame((int) $meta['width'], (int) $size[0]);
        $this->assertSame((int) $meta['height'], (int) $size[1]);
    }

    public function test_unsupported_formats_get_a_clear_reason_not_a_failure(): void
    {
        $this->editors = [Optimize_Media_Gd_Without_Modern_Formats::class];
        wp_cache_delete('wp_image_editor_choose', 'image_editor');
        $id = $this->upload();

        $out = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40, 'formats' => ['webp', 'avif']]);

        $this->assertArrayHasKey('webp', $out['unsupported']);
        $this->assertArrayHasKey('avif', $out['unsupported']);
        $this->assertStringContainsString('WebP', $out['unsupported']['webp']);
        $this->assertStringContainsString('AVIF', $out['unsupported']['avif']);
        // Recompression still ran; only the copies were skipped.
        $item = $out['items'][0];
        $this->assertLessThan($item['before_bytes'], $item['after_bytes']);
        $this->assertSame([], $item['generated']);
        $this->assertFileDoesNotExist(get_attached_file($id) . '.webp');
    }

    public function test_rejects_an_unknown_format(): void
    {
        $id = $this->upload();
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['media_id' => $id, 'formats' => ['gif']]);
    }

    public function test_dry_run_estimates_savings_and_changes_nothing(): void
    {
        $id     = $this->upload();
        $before = $this->hashes($id);
        $main   = (string) get_attached_file($id);

        $out = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40, 'formats' => ['webp'], 'dry_run' => true]);

        $this->assertTrue($out['dry_run']);
        $item = $out['items'][0];
        $this->assertArrayNotHasKey('operation_id', $item);
        $this->assertGreaterThan(0, $item['saved_bytes']);
        $this->assertGreaterThan(0, $out['totals']['saved_bytes']);
        clearstatcache();
        $this->assertSame($before, $this->hashes($id));
        $this->assertFileDoesNotExist($main . '.webp');
        $this->assertSame('', get_post_meta($id, '_wpmcp_optimized', true));
    }

    public function test_batches_the_library_with_a_cursor_and_a_cap(): void
    {
        $ids = [$this->upload(), $this->upload(), $this->upload()];
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 2);

        $first = (new Optimize_Media())->handle(['quality' => 40]);
        $this->assertSame([$ids[0], $ids[1]], array_column($first['items'], 'media_id'));
        $this->assertSame($ids[1], $first['next_cursor']);

        $second = (new Optimize_Media())->handle(['quality' => 40, 'cursor' => $first['next_cursor']]);
        $this->assertSame([$ids[2]], array_column($second['items'], 'media_id'));
        $this->assertNull($second['next_cursor']);
    }

    public function test_a_spent_time_budget_still_processes_one_and_hands_back_a_cursor(): void
    {
        $ids = [$this->upload(), $this->upload()];
        add_filter('wpmcp_optimize_media_time_budget', static fn () => 0.0);

        $out = (new Optimize_Media())->handle(['quality' => 40]);
        $this->assertSame([$ids[0]], array_column($out['items'], 'media_id'));
        $this->assertSame($ids[0], $out['next_cursor']);
    }

    public function test_batch_skips_images_already_optimized_unless_forced(): void
    {
        $id = $this->upload();
        (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40]);

        $again = (new Optimize_Media())->handle(['cursor' => $id - 1]);
        $this->assertSame('already optimized', $again['items'][0]['skipped'] ?? null);

        $forced = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40, 'force' => true]);
        $this->assertArrayNotHasKey('skipped', $forced['items'][0]);
    }

    public function test_defers_to_an_active_image_optimization_plugin(): void
    {
        remove_filter('wpmcp_image_optimizer_plugins', [$this, 'no_optimizers']);
        add_filter('wpmcp_image_optimizer_plugins', static fn () => ['Acme Image Compressor']);
        $id     = $this->upload();
        $before = $this->hashes($id);

        $out = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40]);
        $this->assertSame(['Acme Image Compressor'], $out['deferred_to']);
        $this->assertSame([], $out['items']);
        clearstatcache();
        $this->assertSame($before, $this->hashes($id));

        $forced = (new Optimize_Media())->handle(['media_id' => $id, 'quality' => 40, 'force' => true]);
        $this->assertCount(1, $forced['items']);
        remove_all_filters('wpmcp_image_optimizer_plugins');
    }

    public function test_detects_optimizer_plugins_from_their_headers(): void
    {
        $this->assertTrue(Optimize_Media::looks_like_optimizer('Acme Image Optimizer', 'Compress images on upload.'));
        $this->assertTrue(Optimize_Media::looks_like_optimizer('Pixel Squeeze', 'Converts your images to WebP and AVIF.'));
        $this->assertFalse(Optimize_Media::looks_like_optimizer('Contact Form', 'A simple contact form.'));
    }

    public function test_rejects_a_non_image_attachment(): void
    {
        $pdf = (int) self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['media_id' => $pdf]);
    }

    public function test_rejects_a_missing_attachment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['media_id' => 999999]);
    }
}
