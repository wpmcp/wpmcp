<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Image_Optimizer_Pack;
use WPMCP\Integrations\Operation_Refused;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The image optimization plugin ops (issue #693): get-image-optimizer-status
 * and optimize-attachment on the theme dispatcher pair, for Smush,
 * ShortPixel, EWWW Image Optimizer and Imagify.
 *
 * None of the plugins is installed in the shared test core. Presence is
 * driven through wpmcp_image_optimizer_active, the settings and per-image
 * records are seeded the way each plugin stores them (option rows, post
 * meta, and ShortPixel's and EWWW's own tables, schema copied from
 * ShortPixel 6.6.2 InstallHelper::getPostMetaSQL() and EWWW 8.9.2
 * common.php), and each plugin's optimize call is replaced through
 * wpmcp_image_optimizer_callback by a stand-in that rewrites the files and
 * records meta the way the real plugin does.
 */
class ImageOptimizerPackTest extends \WP_UnitTestCase
{
    /** @var string[] slugs the presence filter reports active */
    private array $active = [];

    /** @var array<int,array{0:string,1:int}> */
    private array $calls = [];

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::drop_tables();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$wpdb->prefix}shortpixel_postmeta (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            attach_id bigint unsigned NOT NULL,
            parent bigint unsigned NOT NULL,
            image_type tinyint default 0,
            size varchar(200),
            status tinyint default 0,
            PRIMARY KEY id (id)
        )");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}ewwwio_images (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint unsigned,
            gallery varchar(10),
            path text NOT NULL,
            pending tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY id (id)
        )");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop_tables();
    }

    private static function drop_tables(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shortpixel_postmeta, {$wpdb->prefix}ewwwio_images");
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->active = [];
        $this->calls  = [];
        add_filter('wpmcp_image_optimizer_active', [ $this, 'presence' ], 10, 2);
        add_filter('wpmcp_image_optimizer_callback', [ $this, 'optimizer_callback' ], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_image_optimizer_active');
        remove_all_filters('wpmcp_image_optimizer_callback');
        remove_all_filters(Image_Optimizer_Pack::ENABLE_FILTER);
        parent::tearDown();
    }

    public function presence(bool $active, string $slug): bool
    {
        return in_array($slug, $this->active, true);
    }

    /** A stand-in optimizer: rewrites the main file and records meta, like the plugin would. */
    public function optimizer_callback($callable, string $slug): callable
    {
        return function (int $id) use ($slug): array {
            $this->calls[] = [ $slug, $id ];
            $main = (string) get_attached_file($id);
            file_put_contents($main, 'optimized-bytes'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($main . '.webp', 'webp-bytes'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            update_post_meta($id, 'wp-smpro-smush-data', [ 'stats' => [ 'percent' => 40 ] ]);
            return 'imagify' === $slug ? [ 'status' => 'queued' ] : [ 'status' => 'optimized' ];
        };
    }

    private function enable_writes(): void
    {
        add_filter(Image_Optimizer_Pack::ENABLE_FILTER, '__return_true');
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function image(): int
    {
        return (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    public function test_ops_are_listed_and_hidden_behind_a_missing_plugin(): void
    {
        $ops = $this->catalog();
        $this->assertArrayHasKey('get-image-optimizer-status', $ops);
        $this->assertArrayHasKey('optimize-attachment', $ops);
        $this->assertFalse($ops['get-image-optimizer-status']['dependency_met']);

        $out = $this->read('get-image-optimizer-status');
        $this->assertSame('image_optimizer_inactive', $out['error']['code']);
        $this->assertStringContainsString('Smush', $out['error']['message']);
        $this->assertStringContainsString('Imagify', $out['error']['message']);
        $this->assertArrayHasKey('plugins_url', $out['error']['data']);
    }

    public function test_status_reports_settings_counts_and_cached_quota_without_secrets(): void
    {
        global $wpdb;
        $this->active = [ 'smush', 'shortpixel', 'ewww', 'imagify' ];
        $a = $this->image();
        $b = $this->image();
        update_post_meta($a, 'wp-smpro-smush-data', [ 'stats' => [] ]);
        update_post_meta($a, '_imagify_status', 'success');
        update_post_meta($b, '_imagify_status', 'error');
        $wpdb->insert($wpdb->prefix . 'shortpixel_postmeta', [ 'attach_id' => $b, 'parent' => 0, 'image_type' => 0, 'status' => 2 ]);
        $wpdb->insert($wpdb->prefix . 'ewwwio_images', [ 'attachment_id' => $a, 'gallery' => 'media', 'path' => 'x', 'pending' => 0 ]);
        $wpdb->insert($wpdb->prefix . 'ewwwio_images', [ 'attachment_id' => $b, 'gallery' => 'media', 'path' => 'y', 'pending' => 1 ]);

        update_option('wp-smush-settings', [ 'auto' => true, 'lossy' => 1, 'backup' => true, 'webp_mod' => true, 'avif_mod' => false, 'api_key' => 'smush-secret' ]);
        update_option('spio_settings', [ 'compressionType' => 1, 'backupImages' => true, 'createWebp' => true, 'createAvif' => true, 'autoMediaLibrary' => false ]);
        update_option('spio_key', [ 'apiKey' => 'SPIO-SECRET-KEY', 'verifiedKey' => true ]);
        set_transient('quotaData', [ 'APICallsQuota' => 100, 'APICallsMade' => 30, 'APICallsQuotaOneTime' => 50, 'APICallsMadeOneTime' => 5, 'Unlimited' => false, 'APIKey' => 'SPIO-SECRET-KEY' ]);
        update_option('ewww_image_optimizer_webp', true);
        update_option('ewww_image_optimizer_backup_files', 'local');
        update_option('ewww_image_optimizer_jpg_level', '10');
        update_option('ewww_image_optimizer_cloud_key', 'EWWW-SECRET-KEY');
        update_option('imagify_settings', [ 'api_key' => 'IMAGIFY-SECRET', 'optimization_level' => 2, 'auto_optimize' => 1, 'backup' => 1, 'convert_to_webp' => 0, 'convert_to_avif' => 1, 'optimization_format' => 'avif' ]);

        $out = $this->read('get-image-optimizer-status');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $by = array_column($out['result']['optimizers'], null, 'plugin');
        $this->assertSame([ 'smush', 'shortpixel', 'ewww', 'imagify' ], array_keys($by));

        $this->assertTrue($by['smush']['settings']['webp']);
        $this->assertFalse($by['smush']['settings']['avif']);
        $this->assertTrue($by['smush']['settings']['auto_optimize']);
        $this->assertSame(2, $by['smush']['library']['images']);
        $this->assertSame(1, $by['smush']['library']['optimized']);
        $this->assertSame(1, $by['smush']['library']['pending']);

        $this->assertTrue($by['shortpixel']['settings']['avif']);
        $this->assertFalse($by['shortpixel']['settings']['auto_optimize']);
        $this->assertTrue($by['shortpixel']['api_key_set']);
        $this->assertSame(1, $by['shortpixel']['library']['optimized']);
        $this->assertSame([ 'unlimited' => false, 'monthly_total' => 100, 'monthly_used' => 30, 'one_time_total' => 50, 'one_time_used' => 5, 'remaining' => 115 ], $by['shortpixel']['quota']);

        $this->assertTrue($by['ewww']['settings']['webp']);
        $this->assertTrue($by['ewww']['api_key_set']);
        $this->assertSame(1, $by['ewww']['library']['optimized']);

        $this->assertTrue($by['imagify']['settings']['avif']);
        $this->assertFalse($by['imagify']['settings']['webp']);
        $this->assertSame(1, $by['imagify']['library']['optimized']);
        $this->assertNull($by['imagify']['quota']);

        $json = (string) wp_json_encode($out);
        foreach ([ 'smush-secret', 'SPIO-SECRET-KEY', 'EWWW-SECRET-KEY', 'IMAGIFY-SECRET' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_status_can_be_narrowed_to_one_active_plugin(): void
    {
        $this->active = [ 'smush', 'imagify' ];
        $out = $this->read('get-image-optimizer-status', [ 'plugin' => 'imagify' ]);
        $this->assertSame([ 'imagify' ], array_column($out['result']['optimizers'], 'plugin'));

        $out = $this->read('get-image-optimizer-status', [ 'plugin' => 'ewww' ]);
        $this->assertSame('image_optimizer_inactive', $out['error']['code']);
        $this->assertStringContainsString('EWWW Image Optimizer', $out['error']['message']);
    }

    public function test_optimize_is_off_until_the_site_opts_in(): void
    {
        $this->active = [ 'smush' ];
        $id  = $this->image();
        $out = $this->write('optimize-attachment', [ 'media_id' => $id ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString(Image_Optimizer_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame([], $this->calls);
    }

    public function test_optimize_runs_through_the_plugin_and_rollback_restores_files_and_meta(): void
    {
        $this->active = [ 'smush' ];
        $this->enable_writes();
        $id     = $this->image();
        $main   = (string) get_attached_file($id);
        $before = sha1_file($main);

        $out = $this->write('optimize-attachment', [ 'media_id' => $id ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ [ 'smush', $id ] ], $this->calls);
        $this->assertSame('smush', $out['result']['plugin']);
        $this->assertSame('optimized', $out['result']['status']);
        $this->assertTrue($out['recoverable']);
        $this->assertCount(1, $out['operation_ids']);
        $this->assertNotSame($before, sha1_file($main));
        $this->assertFileExists($main . '.webp');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        clearstatcache();
        $this->assertSame($before, sha1_file($main));
        $this->assertFileDoesNotExist($main . '.webp');
        $this->assertSame('', get_post_meta($id, 'wp-smpro-smush-data', true));
    }

    public function test_optimize_needs_a_plugin_choice_when_several_are_active(): void
    {
        $this->active = [ 'smush', 'imagify' ];
        $this->enable_writes();
        $id = $this->image();

        $out = $this->write('optimize-attachment', [ 'media_id' => $id ]);
        $this->assertSame('image_optimizer_ambiguous', $out['error']['code']);
        $this->assertSame([ 'smush', 'imagify' ], $out['error']['data']['active']);

        $out = $this->write('optimize-attachment', [ 'media_id' => $id, 'plugin' => 'imagify' ]);
        $this->assertSame('queued', $out['result']['status']);
        $this->assertSame([ [ 'imagify', $id ] ], $this->calls);
    }

    public function test_optimize_refuses_non_images_and_inactive_plugins_without_a_snapshot(): void
    {
        $this->active = [ 'smush' ];
        $this->enable_writes();
        $doc = self::factory()->attachment->create([ 'post_mime_type' => 'application/pdf' ]);

        $out = $this->write('optimize-attachment', [ 'media_id' => $doc ]);
        $this->assertSame('not_an_image', $out['error']['code']);

        $out = $this->write('optimize-attachment', [ 'media_id' => $this->image(), 'plugin' => 'ewww' ]);
        $this->assertSame('image_optimizer_inactive', $out['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_a_plugin_failure_voids_the_write_and_restores_the_files(): void
    {
        $this->active = [ 'ewww' ];
        $this->enable_writes();
        $id     = $this->image();
        $main   = (string) get_attached_file($id);
        $before = sha1_file($main);
        remove_all_filters('wpmcp_image_optimizer_callback');
        add_filter('wpmcp_image_optimizer_callback', static fn () => static function (int $id) use ($main): array {
            file_put_contents($main, 'half-written'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            throw new Operation_Refused('image_optimizer_failed', 'The optimizer reported an error.');
        });

        $out = $this->write('optimize-attachment', [ 'media_id' => $id ]);
        $this->assertSame('image_optimizer_failed', $out['error']['code']);
        clearstatcache();
        $this->assertSame($before, sha1_file($main));
    }
}
