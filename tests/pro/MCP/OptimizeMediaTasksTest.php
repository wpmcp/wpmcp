<?php

namespace WPMCP\Tests\Pro\MCP;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Media\Optimize_Media;
use WPMCP\Tools\Media\Optimize_Media_Job;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * A background optimize-media run as an MCP Task (issue #432): the job it
 * queues is handed back as a task handle to a client that declared the
 * extension, polled through tasks/get with its progress, and cancelled
 * through tasks/cancel, exactly like a backup or CLI job.
 *
 * optimize-media is pro and not registered on an unlicensed test install,
 * so it is registered here against a swapped Registrar, as CliJobTasksTest
 * does.
 */
class OptimizeMediaTasksTest extends \WP_UnitTestCase
{
    private ?Registrar $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available on this PHP build.');
        }
        Governance::reset_for_tests();
        delete_option(Optimize_Media_Job::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_432);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        add_filter('wp_image_editors', static fn () => ['WP_Image_Editor_GD'], 99);
        add_filter('wpmcp_image_optimizer_plugins', static fn () => []);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $plugin         = Plugin::instance();
        $prop           = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->original = $prop->getValue($plugin);
        $fresh          = new Registrar();
        $prop->setValue($plugin, $fresh);

        Gate::set_pro_for_tests(true);
        if (wp_has_ability('wpmcp/optimize-media')) {
            wp_unregister_ability('wpmcp/optimize-media');
        }
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($fresh): void {
            $fresh->register(new Ability('wpmcp/optimize-media', 'pro', 'Optimize images.', [
                'type'       => 'object',
                'properties' => [
                    'background' => [ 'type' => 'boolean' ],
                    'job_id'     => [ 'type' => 'integer' ],
                    'cancel'     => [ 'type' => 'boolean' ],
                    'quality'    => [ 'type' => 'integer' ],
                ],
            ], [ new Optimize_Media(), 'handle' ], 'upload_files', 'media', 'update'));
        });
        do_action('wp_abilities_api_init');
    }

    protected function tearDown(): void
    {
        if (wp_has_ability('wpmcp/optimize-media')) {
            wp_unregister_ability('wpmcp/optimize-media');
        }
        if (null !== $this->original) {
            (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        }
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wp_image_editors');
        remove_all_filters('wpmcp_image_optimizer_plugins');
        Rate_Limiter::set_clock_override(null);
        wp_clear_scheduled_hook(Optimize_Media_Job::HOOK);
        delete_option(Optimize_Media_Job::OPTION);
        Governance::reset_for_tests();
        parent::tearDown();
    }

    private function modern(string $method, array $params): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [ 'extensions' => [ 'io.modelcontextprotocol/tasks' => new \stdClass() ] ],
        ];

        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ]);

        return json_decode((string) wp_json_encode($response), true);
    }

    public function test_a_background_run_is_a_task_polled_to_completion(): void
    {
        self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $task = $this->modern('tools/call', [ 'name' => 'wpmcp-optimize-media', 'arguments' => [ 'background' => true, 'quality' => 40 ] ])['result'];

        $this->assertSame('task', $task['resultType'] ?? null, (string) wp_json_encode($task));
        $this->assertSame('working', $task['status']);
        $this->assertStringStartsWith('wpmcp-optimize-', $task['taskId']);

        $job_id = (int) substr($task['taskId'], strlen('wpmcp-optimize-'));
        $admin  = get_current_user_id();
        wp_set_current_user(0);
        (new Optimize_Media_Job())->handle($job_id);
        wp_set_current_user($admin);

        $done = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result'];
        $this->assertSame('completed', $done['status'], (string) wp_json_encode($done));
        $this->assertStringContainsString('1 of 1', $done['statusMessage']);
        $this->assertSame(1, $done['result']['structuredContent']['progress']['done']);
    }

    public function test_a_queued_run_can_be_cancelled_as_a_task(): void
    {
        self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $task = $this->modern('tools/call', [ 'name' => 'wpmcp-optimize-media', 'arguments' => [ 'background' => true ] ])['result'];

        $this->modern('tasks/cancel', [ 'taskId' => $task['taskId'] ]);

        $this->assertSame('cancelled', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result']['status']);
    }
}
