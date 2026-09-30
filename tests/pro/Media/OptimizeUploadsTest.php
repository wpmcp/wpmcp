<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Media\Optimize_Media;
use WPMCP\Tools\Media\Upload_Media;
use WPMCP\Tools\Settings\Get_Settings;
use WPMCP\Tools\Settings\Update_Settings;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * Optimize uploads made through the tools (issue #432). The
 * wpmcp_optimize_uploads setting, off by default and written through
 * update-settings, runs optimize-media's recompression and WebP copy on an
 * image a wpmcp tool call adds to the Media Library. An upload made any
 * other way (the media screen, another plugin) is never touched.
 */
class OptimizeUploadsTest extends \WP_UnitTestCase
{
    use OptimizeMediaFixture;

    private const TOOL = 'wpmcp/test-upload-media';

    private ?Registrar $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up_optimize_fixture();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_432);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        delete_option('wpmcp_optimize_uploads');
        $this->register_upload_tool();
    }

    /**
     * upload-media's handler as a registered tool, through a Registrar like
     * every wpmcp tool, in a private wp_abilities_api_init window against a
     * swapped Registrar (the pattern CliJobTasksTest uses), so the test
     * neither depends on nor disturbs the suite's registry.
     */
    private function register_upload_tool(): void
    {
        $prop           = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->original = $prop->getValue(Plugin::instance());
        $fresh          = new Registrar();
        $prop->setValue(Plugin::instance(), $fresh);

        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($fresh): void {
            $fresh->register(new Ability(self::TOOL, 'free', 'Upload a file.', [
                'type'       => 'object',
                'properties' => [
                    'filename'   => [ 'type' => 'string' ],
                    'data'       => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'filename', 'data' ],
            ], [ new Upload_Media(), 'handle' ], 'upload_files', 'media', 'create'));
        });
        do_action('wp_abilities_api_init');
    }

    protected function tearDown(): void
    {
        if (wp_has_ability(self::TOOL)) {
            wp_unregister_ability(self::TOOL);
        }
        if (null !== $this->original) {
            (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        }
        delete_option('wpmcp_optimize_uploads');
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        $this->tear_down_optimize_fixture();
        parent::tearDown();
    }

    /** upload-media's handler, called as a tool: through the registered ability. */
    private function tool_upload(array $extra = []): array
    {
        $bytes  = (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        $result = wp_get_ability(self::TOOL)->execute($extra + [
            'filename' => 'canola.jpg',
            'data'     => base64_encode($bytes),
        ]);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'upload-media failed');
        return $result;
    }

    public function test_the_setting_is_off_by_default_and_a_tool_upload_is_left_alone(): void
    {
        $result = $this->tool_upload();

        $this->assertArrayNotHasKey('optimized', $result);
        $this->assertSame('', get_post_meta($result['media_id'], Optimize_Media::META_KEY, true));
        $this->assertSame(sha1_file(DIR_TESTDATA . '/images/canola.jpg'), sha1_file((string) get_attached_file($result['media_id'])));
    }

    public function test_with_the_setting_on_a_tool_upload_is_optimized(): void
    {
        $this->require_webp();
        update_option('wpmcp_optimize_uploads', true);

        $result = $this->tool_upload();
        $id     = $result['media_id'];

        $this->assertIsArray(get_post_meta($id, Optimize_Media::META_KEY, true));
        $this->assertArrayHasKey('optimized', $result, 'The tool result says what was done to the upload.');
        $this->assertSame($id, $result['optimized']['media_id']);
        $this->assertLessThanOrEqual($result['optimized']['before_bytes'], $result['optimized']['after_bytes']);
        $this->assertFileExists(get_attached_file($id) . '.webp');
    }

    public function test_the_upload_session_rolls_back_the_optimization_with_the_upload(): void
    {
        $this->require_webp();
        update_option('wpmcp_optimize_uploads', true);

        $result = $this->tool_upload(['session_id' => 'upload-432']);
        $copy   = get_attached_file($result['media_id']) . '.webp';
        $this->assertFileExists($copy);

        Rollback_Service::restore_session('upload-432');

        $this->assertNull(get_post($result['media_id']));
        $this->assertFileDoesNotExist($copy);
    }

    public function test_with_the_setting_on_an_upload_outside_the_tools_is_left_alone(): void
    {
        update_option('wpmcp_optimize_uploads', true);

        $id = $this->upload_image();

        $this->assertSame('', get_post_meta($id, Optimize_Media::META_KEY, true));
        $this->assertFileDoesNotExist(get_attached_file($id) . '.webp');
    }

    public function test_with_the_setting_on_an_active_optimizer_still_wins(): void
    {
        update_option('wpmcp_optimize_uploads', true);
        $this->pin_optimizer_plugin('Acme Image Compressor');

        $result = $this->tool_upload();

        $this->assertSame('', get_post_meta($result['media_id'], Optimize_Media::META_KEY, true));
    }

    public function test_deleting_an_optimized_image_removes_its_modern_copies(): void
    {
        $this->require_webp();
        $id = $this->upload_image();
        (new Optimize_Media())->handle(['media_id' => $id, 'formats' => ['webp']]);
        $copy = get_attached_file($id) . '.webp';
        $this->assertFileExists($copy);

        wp_delete_attachment($id, true);

        $this->assertFileDoesNotExist($copy);
    }

    public function test_both_settings_are_readable_and_writable_through_the_settings_tools(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['wpmcp_optimize_uploads' => true, 'wpmcp_serve_modern_images' => true]]);

        $this->assertSame(['wpmcp_optimize_uploads' => true, 'wpmcp_serve_modern_images' => true], $out['updated']);
        $this->assertTrue((bool) get_option('wpmcp_optimize_uploads'));

        $read = wp_json_encode((new Get_Settings())->handle(['group' => 'media']));
        $this->assertStringContainsString('wpmcp_optimize_uploads', (string) $read);
        $this->assertStringContainsString('wpmcp_serve_modern_images', (string) $read);

        delete_option('wpmcp_serve_modern_images');
    }

    public function test_the_settings_are_not_offered_without_the_paid_tier(): void
    {
        \WPMCP\Pro\Gate::set_pro_for_tests(false);

        $out = (new Update_Settings())->handle(['settings' => ['wpmcp_optimize_uploads' => true]]);

        $this->assertSame([], $out['updated']);
        $this->assertSame('not allowlisted', $out['skipped'][0]['reason']);
    }
}
