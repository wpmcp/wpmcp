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

    /**
     * Install a handler that records every errstr it is given and claims the
     * error. Stands in for a monitoring plugin's global handler installed
     * before the tool runs; the caller must restore_error_handler() it.
     *
     * @return array{0: callable, 1: array<int, string>} handler and, by reference, the messages it saw
     */
    private function install_recording_handler(array &$seen): callable
    {
        $recorder = static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = $errstr;

            return true;
        };
        set_error_handler($recorder);

        return $recorder;
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

    public function test_the_installed_handler_swallows_only_the_headers_warning_and_chains_the_rest(): void
    {
        $seen     = [];
        $verdicts = [];
        $recorder = $this->install_recording_handler($seen);

        try {
            $out = (new Export_Content(function () use (&$verdicts): void {
                $handler = $this->current_error_handler();
                $this->assertIsCallable($handler);

                // Call the installed handler directly rather than triggering
                // real warnings: what matters is which errstr values it
                // claims itself and which it hands to the previous handler.
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
        } finally {
            $this->assertSame($recorder, $this->current_error_handler(), 'The pre-existing handler is not back on top after handle().');
            restore_error_handler();
        }

        $this->assertTrue($verdicts['headers'], 'The headers-already-sent warning should be claimed by the suppressor.');
        $this->assertTrue($verdicts['other'], 'The previous handler claimed the unrelated warning, so the suppressor must report it handled.');
        $this->assertSame(
            ['fopen(/nope/nope): Failed to open stream: No such file or directory'],
            $seen,
            'Only the unrelated warning may reach the previously installed handler.'
        );
    }

    public function test_the_installed_handler_defers_to_php_when_there_was_no_previous_handler(): void
    {
        $verdict = null;

        // A null frame on top means "no user handler": the suppressor is then
        // installed with nothing to chain to and must return false so PHP's
        // own handling and error log still see the warning.
        set_error_handler(null);

        try {
            $out = (new Export_Content(function () use (&$verdict): void {
                $verdict = ($this->current_error_handler())(E_WARNING, 'fopen(/nope): Failed to open stream', '/x.php', 1);
                echo $this->minimal_wxr();
            }))->handle([]);
            $this->cleanup_files[] = $out['file'];
        } finally {
            $this->assertNull($this->current_error_handler(), 'The suppressor (or a stray handler) survived handle().');
            restore_error_handler();
        }

        $this->assertFalse($verdict);
    }

    public function test_leaves_a_pre_existing_handler_installed_when_the_exporter_pops_one_frame_too_many(): void
    {
        $before_handler = $this->current_error_handler();
        $seen           = [];
        $recorder       = $this->install_recording_handler($seen);

        try {
            $out = (new Export_Content(function (): void {
                // A hook that calls restore_error_handler() without having
                // installed anything: this pops the suppressor itself. A
                // blind unwind would then keep popping and strip the handler
                // that was installed before the tool ran.
                restore_error_handler();
                echo $this->minimal_wxr();
            }))->handle([]);
            $this->cleanup_files[] = $out['file'];

            $this->assertSame($recorder, $this->current_error_handler(), 'The pre-existing handler was popped by the unwind.');
        } finally {
            restore_error_handler();
        }

        $this->assertSame($before_handler, $this->current_error_handler(), 'The stack beneath the pre-existing handler was disturbed.');
    }

    public function test_removes_its_own_handler_when_the_exporter_leaves_a_null_frame_on_top(): void
    {
        $before_handler = $this->current_error_handler();
        $seen           = [];
        $recorder       = $this->install_recording_handler($seen);

        try {
            $out = (new Export_Content(function (): void {
                // set_error_handler(null) with no matching restore leaves a
                // null frame above the suppressor; stopping at the first null
                // would leave the suppressor live under it.
                set_error_handler(null);
                echo $this->minimal_wxr();
            }))->handle([]);
            $this->cleanup_files[] = $out['file'];

            $this->assertSame($recorder, $this->current_error_handler(), 'The suppressor survived under the null frame.');
        } finally {
            restore_error_handler();
        }

        $this->assertSame($before_handler, $this->current_error_handler());
    }

    /**
     * @dataProvider provide_exporters_that_disturb_the_handler_stack
     */
    public function test_restores_the_stack_when_installed_over_a_null_frame(callable $misbehave): void
    {
        $before_handler = $this->current_error_handler();

        // Installed over a null frame the suppressor's "previous" is null, so
        // a null on top after the export is ambiguous (stack bottom, or a
        // frame the exporter left). Both variants must leave the stack as it
        // was: a null on top with the original handler beneath it.
        set_error_handler(null);

        try {
            $out = (new Export_Content(function () use ($misbehave): void {
                $misbehave();
                echo $this->minimal_wxr();
            }))->handle([]);
            $this->cleanup_files[] = $out['file'];

            $this->assertNull($this->current_error_handler(), 'The suppressor (or a stray handler) survived handle().');
        } finally {
            restore_error_handler();
        }

        $this->assertSame($before_handler, $this->current_error_handler(), 'The handler beneath the null frame was popped.');
    }

    public function provide_exporters_that_disturb_the_handler_stack(): array
    {
        return [
            'pops one frame too many'   => [static function (): void {
                restore_error_handler();
            }],
            'leaves a null frame on top' => [static function (): void {
                set_error_handler(null);
            }],
        ];
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

    public function test_throws_instead_of_capturing_the_callers_buffer_when_the_exporter_closes_its_own(): void
    {
        $before_level = ob_get_level();
        $dir_before   = glob(trailingslashit(\WPMCP\Tools\Export\Export_Dir::path()) . 'wpmcp-export-*.xml') ?: [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed an output buffer it did not open');

        try {
            (new Export_Content(function (): void {
                echo $this->minimal_wxr();
                // A hook that closes a buffer it did not open: this is the
                // tool's capture buffer. An unchecked ob_get_clean() would
                // then consume the caller's buffer as the export payload.
                ob_end_clean();
            }))->handle([]);
        } finally {
            $this->assertSame($before_level, ob_get_level(), 'The caller\'s buffer was consumed.');
            $dir_after = glob(trailingslashit(\WPMCP\Tools\Export\Export_Dir::path()) . 'wpmcp-export-*.xml') ?: [];
            $this->assertSame(count($dir_before), count($dir_after), 'An export file was written anyway.');
        }
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

    public function test_claims_the_headers_already_sent_warning_from_export_wps_own_header_calls(): void
    {
        // The header snapshot/restore branch is only observable under a SAPI
        // that records headers; the CLI SAPI does not (headers_list() is
        // always empty there), so it is verified against a REST dispatch, not
        // here. What the CLI does exercise is the other half: once output has
        // been flushed, header() raises the exact warning the suppressor
        // exists to claim, and nothing else may see it.
        if (! headers_sent()) {
            $this->markTestSkipped('header() only warns after output has been flushed; nothing to observe under this SAPI.');
        }

        $seen     = [];
        $recorder = $this->install_recording_handler($seen);

        try {
            $out = (new Export_Content(function (): void {
                // export_wp() unconditionally sends these two.
                header('Content-Type: text/xml; charset=UTF-8');
                header('Content-Disposition: attachment; filename=wp-export.xml');
                echo $this->minimal_wxr();
            }))->handle([]);
            $this->cleanup_files[] = $out['file'];
        } finally {
            restore_error_handler();
        }

        $xml = file_get_contents($out['file']);
        $this->assertStringNotContainsString('Cannot modify header information', $xml, 'The header warning was printed into the export.');
        $this->assertStringNotContainsString('Warning', $xml);
        $this->assertSame([], $seen, 'The header warning must be claimed by the suppressor, not chained to the previous handler.');
        $this->assertSame($out['size'], strlen($this->minimal_wxr()));
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
