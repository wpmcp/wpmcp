<?php

namespace WPMCP\Tests\Free\Export;

use WPMCP\Tools\Export\Export_Content;

/**
 * WordPress core's own export_wp() (wp-admin/includes/export.php) declares
 * several helper functions (wxr_cdata(), wxr_authors_list(), etc.) inside its
 * own function body with no idempotency guard, so calling it more than once
 * in the same PHP process is a fatal "cannot redeclare function" error. This
 * is a real constraint of WordPress core itself, not specific to this tool:
 * export_wp() was written for the one-shot Tools > Export admin-post request
 * lifecycle. Exercise every export_wp()-calling assertion from a single
 * handle() call per test method (never two), and cover the in-process
 * repeat-call case explicitly as its own assertion.
 *
 * Every other behaviour of this tool (buffer handling, error-handler
 * install/restore, response-header restoration, output validation) is
 * exercised through the constructor-injected exporter callable, which stands
 * in for export_wp() and therefore does not consume that one-per-process
 * budget. The same seam is used one directory over in Run_Backup_Job for the
 * same reason.
 */
class ExportContentTest extends \WP_UnitTestCase
{
    private array $cleanup_files = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup_files = [];
        parent::tearDown();
    }

    /**
     * The handler currently on top of PHP's error-handler stack, read without
     * disturbing the stack: set_error_handler(null) returns the previous
     * handler and pushes a frame, restore_error_handler() pops that frame
     * straight back off.
     */
    private function current_error_handler(): ?callable
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private function minimal_wxr(string $title = 'Injected'): string
    {
        return "<?xml version=\"1.0\"?>\n<rss version=\"2.0\"><channel><item><title>{$title}</title></item></channel></rss>";
    }

    public function test_creates_a_wxr_file_containing_the_post_title_in_a_protected_directory(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'   => 'WPMCP Export Fixture Post',
            'post_content' => 'Hello from the export test.',
            'post_status'  => 'publish',
        ]);

        $out = (new Export_Content())->handle([]);
        $this->cleanup_files[] = $out['file'];

        $this->assertFileExists($out['file']);
        $this->assertGreaterThan(0, $out['size']);
        $this->assertGreaterThanOrEqual(1, $out['item_count']);

        $xml = file_get_contents($out['file']);
        $this->assertStringContainsString('WPMCP Export Fixture Post', $xml);
        $this->assertStringContainsString('<rss', $xml);

        $this->assertNotNull(get_post($post_id));

        $dir = dirname($out['file']);
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
    }

    public function test_restores_the_error_handler_and_buffer_level_when_the_exporter_throws(): void
    {
        $before_handler = $this->current_error_handler();
        $before_level   = ob_get_level();

        try {
            (new Export_Content(static function (): void {
                throw new \RuntimeException('exporter blew up');
            }))->handle([]);
            $this->fail('Expected the exporter exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('exporter blew up', $e->getMessage());
        }

        $this->assertSame($before_level, ob_get_level(), 'Output buffer level leaked past the throw.');
        $this->assertSame($before_handler, $this->current_error_handler(), 'Error handler leaked past the throw.');
    }

    public function test_restores_its_own_handler_even_when_the_exporter_leaves_one_installed(): void
    {
        $before_handler = $this->current_error_handler();
        $before_level   = ob_get_level();

        $out = (new Export_Content(function (): void {
            // Simulate a third-party export hook that installs a handler and
            // never restores it. A single restore_error_handler() in the
            // finally block would pop this one and leave ours installed for
            // the rest of the request, which is exactly what issue #181 is
            // about.
            set_error_handler(static function (): bool {
                return true;
            });
            echo $this->minimal_wxr();
        }))->handle([]);
        $this->cleanup_files[] = $out['file'];

        $this->assertSame($before_level, ob_get_level());
        $this->assertSame(
            $before_handler,
            $this->current_error_handler(),
            'The export suppressor (or a stray handler) survived handle().'
        );
    }

    public function test_the_installed_handler_only_swallows_the_headers_already_sent_warning(): void
    {
        $verdicts = [];

        $out = (new Export_Content(function () use (&$verdicts): void {
            $handler = $this->current_error_handler();
            $this->assertIsCallable($handler);

            // Call the installed handler directly rather than triggering real
            // warnings: what matters is which errstr values it claims.
            $verdicts['headers'] = $handler(
                E_WARNING,
                'Cannot modify header information - headers already sent by (output started at /x.php:1)',
                '/x.php',
                1
            );
            $verdicts['other'] = $handler(
                E_WARNING,
                'fopen(/nope/nope): Failed to open stream: No such file or directory',
                '/x.php',
                2
            );

            echo $this->minimal_wxr();
        }))->handle([]);
        $this->cleanup_files[] = $out['file'];

        $this->assertTrue($verdicts['headers'], 'The headers-already-sent warning should be suppressed.');
        $this->assertFalse($verdicts['other'], 'Unrelated warnings must fall through to PHP\'s normal handling.');
    }

    public function test_captures_its_own_buffer_when_the_exporter_leaves_a_nested_buffer_open(): void
    {
        $before_level = ob_get_level();

        $out = (new Export_Content(function (): void {
            echo $this->minimal_wxr('Real Export Payload');
            // A hook that opened a buffer and never closed it. Capturing
            // blindly would read this stray buffer and silently discard the
            // real export output.
            ob_start();
            echo 'stray buffer content';
        }))->handle([]);
        $this->cleanup_files[] = $out['file'];

        $xml = file_get_contents($out['file']);
        $this->assertStringContainsString('Real Export Payload', $xml);
        $this->assertStringNotContainsString('stray buffer content', $xml);
        $this->assertSame(1, $out['item_count']);
        $this->assertSame($before_level, ob_get_level());
    }

    public function test_throws_instead_of_writing_a_file_when_the_export_produces_no_xml(): void
    {
        $before_level = ob_get_level();
        $dir_before   = glob(trailingslashit(\WPMCP\Tools\Export\Export_Dir::path()) . 'wpmcp-export-*.xml') ?: [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('produced no WXR output');

        try {
            (new Export_Content(static function (): void {
                // Produces nothing at all.
            }))->handle([]);
        } finally {
            $this->assertSame($before_level, ob_get_level());
            $dir_after = glob(trailingslashit(\WPMCP\Tools\Export\Export_Dir::path()) . 'wpmcp-export-*.xml') ?: [];
            $this->assertSame(count($dir_before), count($dir_after), 'A zero-byte export file was written anyway.');
        }
    }

    public function test_rejects_output_that_is_not_a_wxr_document(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('produced no WXR output');

        (new Export_Content(static function (): void {
            echo 'Fatal error: something went wrong in a hook';
        }))->handle([]);
    }

    public function test_leaves_response_headers_as_they_were_before_the_export(): void
    {
        $before = headers_list();

        $out = (new Export_Content(function (): void {
            // export_wp() unconditionally sends these two.
            header('Content-Type: text/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename=wp-export.xml');
            echo $this->minimal_wxr();
        }))->handle([]);
        $this->cleanup_files[] = $out['file'];

        $this->assertSame($before, headers_list(), 'export_wp() headers leaked onto the response.');
    }

    public function test_the_injected_exporter_receives_the_sanitized_export_args(): void
    {
        $seen = null;

        $out = (new Export_Content(function (array $args) use (&$seen): void {
            $seen = $args;
            echo $this->minimal_wxr();
        }))->handle([
            'content'    => 'Post',
            'author'     => '7',
            'start_date' => '2024-01',
            'end_date'   => '2024-12',
            'status'     => 'Publish',
        ]);
        $this->cleanup_files[] = $out['file'];

        $this->assertSame(
            [
                'content'    => 'post',
                'author'     => 7,
                'start_date' => '2024-01',
                'end_date'   => '2024-12',
                'status'     => 'publish',
            ],
            $seen
        );
    }
}
