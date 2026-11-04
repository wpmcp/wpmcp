<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Filesystem\Php_Edit_Guard;

/**
 * write-file, edit-file and delete-file are undoable (issue #602).
 *
 * Each write records an operation under the caller's session_id, so it is
 * listed by list-operations (and so on the Mutations tab, which reads the
 * same rows), and rollback-operation / rollback-session put the file back
 * byte for byte, or remove a file the write created. A .php restore runs
 * through the same syntax and site check as a .php write.
 *
 * Every call goes through the registered abilities, the way the MCP adapter
 * runs them. No network: the site check's loopbacks are answered by a
 * pre_http_request mock, as in PhpEditGuardTest.
 */
class FileWriteUndoTest extends \WP_UnitTestCase
{
    private string $rel_dir;

    private int $http_calls = 0;

    /** @var callable returns a WP_Error, or [status, result|null] (null = no scrape output). */
    private $responder;

    public function setUp(): void
    {
        parent::setUp();
        $this->rel_dir = 'wp-content/wpmcp-fs-undo-' . getmypid();
        wp_mkdir_p(ABSPATH . $this->rel_dir);

        add_filter('wpmcp_enable_fs_writes', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->http_calls = 0;
        $this->responder  = static fn (): array => [200, true];
        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
        Php_Edit_Guard::release_lock();
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        Php_Edit_Guard::release_lock();
        foreach ((array) glob(ABSPATH . $this->rel_dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    /** @return mixed */
    public function mock_http($pre, $args, $url)
    {
        ++$this->http_calls;
        parse_str((string) wp_parse_url((string) $url, PHP_URL_QUERY), $query);
        if (empty($query['wp_scrape_key'])) {
            return new \WP_Error('http_request_failed', 'Unexpected request in FileWriteUndoTest: ' . $url);
        }
        $key    = (string) $query['wp_scrape_key'];
        $answer = call_user_func($this->responder, (string) $url);
        if (is_wp_error($answer)) {
            return $answer;
        }
        [$status, $result] = $answer;
        $body = '<html>page</html>';
        if (null !== $result) {
            $body .= "\n###### wp_scraping_result_start:$key ######\n" . wp_json_encode($result)
                . "\n###### wp_scraping_result_end:$key ######\n";
        }
        return [
            'headers'  => [],
            'body'     => $body,
            'response' => ['code' => $status, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** Run a registered ability the way the MCP adapter does; fail on a WP_Error. */
    private function call(string $name, array $args): array
    {
        $ability = wp_get_ability('wpmcp/' . $name);
        $this->assertNotNull($ability, "wpmcp/$name is not registered");
        $result = $ability->execute($args);
        $this->assertNotWPError($result, $name . ': ' . (is_wp_error($result) ? $result->get_error_message() : ''));
        return (array) $result;
    }

    private function path(string $name): string
    {
        return $this->rel_dir . '/' . $name;
    }

    private function bytes(string $name): ?string
    {
        $abs = ABSPATH . $this->path($name);
        return is_file($abs) ? (string) file_get_contents($abs) : null;
    }

    /** A string that is not valid UTF-8 and holds a NUL: a binary file. */
    private function binary(): string
    {
        return "\x89PNG\r\n\x1a\n\x00\xff\xfe\x80 not text \x00\xc3\x28";
    }

    public function test_edit_file_returns_an_operation_id_that_rollback_operation_restores(): void
    {
        file_put_contents(ABSPATH . $this->path('notes.txt'), "line one\n");

        $out = $this->call('edit-file', [
            'path'       => $this->path('notes.txt'),
            'old_string' => 'line one',
            'new_string' => 'line edited',
        ]);
        $this->assertTrue($out['recoverable']);
        $this->assertIsString($out['operation_id'] ?? null, wp_json_encode($out));
        $this->assertSame("line edited\n", $this->bytes('notes.txt'));

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame("line one\n", $this->bytes('notes.txt'));
    }

    public function test_each_file_write_is_listed_as_an_undoable_operation(): void
    {
        file_put_contents(ABSPATH . $this->path('a.txt'), 'a');
        $written = $this->call('write-file', ['path' => $this->path('new.txt'), 'content' => 'x']);
        $edited  = $this->call('edit-file', ['path' => $this->path('a.txt'), 'old_string' => 'a', 'new_string' => 'b']);
        $deleted = $this->call('delete-file', ['path' => $this->path('a.txt'), 'confirm' => true]);

        $listed = $this->call('list-operations', ['limit' => 10]);
        $by_id  = [];
        foreach ($listed['operations'] as $op) {
            $by_id[ $op['operation_id'] ] = $op;
        }
        foreach (['write-file' => $written, 'edit-file' => $edited, 'delete-file' => $deleted] as $tool => $out) {
            $this->assertArrayHasKey($out['operation_id'], $by_id, "$tool is not in list-operations");
            $this->assertSame($tool, $by_id[ $out['operation_id'] ]['tool_name']);
            $this->assertTrue($by_id[ $out['operation_id'] ]['rollback_available'], "$tool is listed as not undoable");
        }
    }

    public function test_a_deleted_binary_file_is_restored_byte_for_byte(): void
    {
        file_put_contents(ABSPATH . $this->path('logo.png'), $this->binary());

        $out = $this->call('delete-file', ['path' => $this->path('logo.png'), 'confirm' => true]);
        $this->assertTrue($out['recoverable']);
        $this->assertNull($this->bytes('logo.png'));

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($this->binary(), $this->bytes('logo.png'));
    }

    public function test_an_overwritten_binary_file_is_restored_byte_for_byte(): void
    {
        file_put_contents(ABSPATH . $this->path('logo.png'), $this->binary());

        $out = $this->call('write-file', ['path' => $this->path('logo.png'), 'content' => 'replaced']);
        $this->assertSame('overwritten', $out['action']);

        $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertSame($this->binary(), $this->bytes('logo.png'));
    }

    public function test_a_created_file_is_undone_by_removing_it(): void
    {
        $out = $this->call('write-file', ['path' => $this->path('fresh.txt'), 'content' => 'new']);
        $this->assertSame('created', $out['action']);
        $this->assertTrue($out['recoverable'], 'a create is undoable: rollback removes the file');
        $this->assertNull($out['backup']);

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertNull($this->bytes('fresh.txt'));
    }

    public function test_rollback_session_puts_every_file_back_as_it_was_before_the_session(): void
    {
        file_put_contents(ABSPATH . $this->path('keep.txt'), "v1\n");
        $session = 'fs-undo-' . wp_generate_password(8, false);

        $this->call('write-file', ['path' => $this->path('made.txt'), 'content' => 'one', 'session_id' => $session]);
        $this->call('edit-file', ['path' => $this->path('made.txt'), 'old_string' => 'one', 'new_string' => 'two', 'session_id' => $session]);
        $this->call('edit-file', ['path' => $this->path('keep.txt'), 'old_string' => 'v1', 'new_string' => 'v2', 'session_id' => $session]);
        $this->call('write-file', ['path' => $this->path('keep.txt'), 'content' => "v3\n", 'session_id' => $session]);
        $this->call('delete-file', ['path' => $this->path('keep.txt'), 'confirm' => true, 'session_id' => $session]);

        $rolled = $this->call('rollback-session', ['session_id' => $session]);
        $this->assertSame([], $rolled['warnings'] ?? [], wp_json_encode($rolled));
        $this->assertNull($this->bytes('made.txt'), 'the file the session created is removed');
        $this->assertSame("v1\n", $this->bytes('keep.txt'), 'the file the session changed is back as it was');
    }

    public function test_the_backup_is_kept_with_the_undo_point_and_never_as_a_php_file(): void
    {
        file_put_contents(ABSPATH . $this->path('plain.txt'), 'before');
        $out = $this->call('write-file', ['path' => $this->path('plain.txt'), 'content' => 'after']);

        $dir = File_Backup::operation_dir($out['operation_id']);
        $this->assertDirectoryExists($dir);
        $this->assertSame('before', file_get_contents(ABSPATH . $out['backup']));
        $this->assertStringStartsWith(
            \WPMCP\Tools\Filesystem\Filesystem_Guard::to_relative($dir),
            $out['backup']
        );
        $this->assertStringEndsNotWith('.php', $out['backup']);
    }

    public function test_a_refused_write_leaves_no_operation_and_no_backup(): void
    {
        file_put_contents(ABSPATH . $this->path('functions.php'), "<?php\nfunction wpmcp_undo_fixture() {}\n");
        $before = Snapshot_Store::row_count();

        $ability = wp_get_ability('wpmcp/write-file');
        $result  = $ability->execute(['path' => $this->path('functions.php'), 'content' => "<?php\nfunction broken( {\n"]);
        $this->assertWPError($result);

        $this->assertSame($before, Snapshot_Store::row_count(), 'a write refused before anything changed must not leave an undo point');
        $this->assertSame("<?php\nfunction wpmcp_undo_fixture() {}\n", $this->bytes('functions.php'));
    }

    public function test_an_editor_cannot_undo_a_file_write(): void
    {
        file_put_contents(ABSPATH . $this->path('notes.txt'), 'admin text');
        $out = $this->call('write-file', ['path' => $this->path('notes.txt'), 'content' => 'changed']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame('changed', $this->bytes('notes.txt'));
        $this->assertStringContainsString('edit_files', implode(' ', $rolled['warnings']));
    }

    public function test_restoring_a_php_file_runs_the_site_check(): void
    {
        $original = "<?php\nfunction wpmcp_undo_fixture() { return 1; }\n";
        file_put_contents(ABSPATH . $this->path('functions.php'), $original);
        $out = $this->call('write-file', [
            'path'    => $this->path('functions.php'),
            'content' => "<?php\nfunction wpmcp_undo_fixture() { return 2; }\n",
        ]);
        $this->assertSame('passed', $out['php_check']);

        $this->http_calls = 0;
        $rolled           = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($original, $this->bytes('functions.php'));
        $this->assertGreaterThanOrEqual(4, $this->http_calls, 'the restore checks the site before and after (front end and admin)');
    }

    public function test_a_php_restore_that_breaks_the_site_is_reverted_and_reported(): void
    {
        $original = "<?php\nfunction wpmcp_undo_fixture() { return 'old'; }\n";
        $edited   = "<?php\nfunction wpmcp_undo_fixture() { return 'new'; }\n";
        file_put_contents(ABSPATH . $this->path('functions.php'), $original);
        $out = $this->call('write-file', ['path' => $this->path('functions.php'), 'content' => $edited]);

        // From now on the old version fatals (something it depends on is gone).
        $file            = ABSPATH . $this->path('functions.php');
        $this->responder = static function () use ($file): array {
            return false !== strpos((string) file_get_contents($file), "'old'")
                ? [500, ['type' => E_ERROR, 'message' => 'Uncaught Error: gone', 'file' => 'functions.php', 'line' => 2]]
                : [200, true];
        };

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($edited, $this->bytes('functions.php'), 'the restore was reverted');
        $this->assertStringContainsString('broke the site', implode(' ', $rolled['warnings']));
    }

    public function test_a_php_restore_is_refused_when_the_site_check_cannot_run(): void
    {
        $original = "<?php\nfunction wpmcp_undo_fixture() { return 'old'; }\n";
        $edited   = "<?php\nfunction wpmcp_undo_fixture() { return 'new'; }\n";
        file_put_contents(ABSPATH . $this->path('functions.php'), $original);
        $out = $this->call('write-file', ['path' => $this->path('functions.php'), 'content' => $edited]);

        $this->responder = static fn () => new \WP_Error('http_request_failed', 'loopback blocked');

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($edited, $this->bytes('functions.php'));
        $warning = implode(' ', $rolled['warnings']);
        $this->assertStringContainsString('site check', $warning);
        $this->assertStringContainsString(\WPMCP\Tools\Filesystem\Filesystem_Guard::to_relative(File_Backup::operation_dir($out['operation_id'])), $warning, 'the warning names the backup for a manual restore');
    }

    public function test_an_unchecked_php_write_is_restored_unchecked(): void
    {
        $original = "<?php\nfunction wpmcp_undo_fixture() { return 'old'; }\n";
        file_put_contents(ABSPATH . $this->path('functions.php'), $original);
        $this->responder = static fn () => new \WP_Error('http_request_failed', 'loopback blocked');

        $out = $this->call('write-file', [
            'path'      => $this->path('functions.php'),
            'content'   => "<?php\nfunction wpmcp_undo_fixture() { return 'new'; }\n",
            'unchecked' => true,
        ]);
        $this->assertStringStartsWith('unchecked', $out['php_check']);

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($original, $this->bytes('functions.php'));
    }

    public function test_undoing_a_created_php_file_runs_the_site_check(): void
    {
        $out = $this->call('write-file', ['path' => $this->path('extra.php'), 'content' => "<?php\n// extra\n"]);

        // Removing the file now fatals: something requires it.
        $file            = ABSPATH . $this->path('extra.php');
        $this->responder = static fn (): array => is_file($file)
            ? [200, true]
            : [500, ['type' => E_ERROR, 'message' => 'Failed opening required extra.php', 'file' => 'functions.php', 'line' => 3]];

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame("<?php\n// extra\n", $this->bytes('extra.php'), 'the removal was reverted');
    }

    public function test_file_write_is_a_restorable_object_type(): void
    {
        $this->assertContains('file_write', Rollback_Service::restorable_object_types());
    }

    /** Issue #593: a file write undone once is refused the second time, and a newer write survives. */
    public function test_a_second_undo_of_a_file_write_is_refused_and_keeps_the_newer_content(): void
    {
        file_put_contents(ABSPATH . $this->path('notes.txt'), "v0\n");
        $out = $this->call('edit-file', ['path' => $this->path('notes.txt'), 'old_string' => 'v0', 'new_string' => 'v1']);

        $this->assertTrue($this->call('rollback-operation', ['operation_id' => $out['operation_id']])['restored']);
        $this->assertSame("v0\n", $this->bytes('notes.txt'));
        $this->call('write-file', ['path' => $this->path('notes.txt'), 'content' => "newer\n"]);

        $again = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertFalse($again['restored'], wp_json_encode($again));
        $this->assertStringContainsString('already undone', implode(' ', $again['warnings']));
        $this->assertSame("newer\n", $this->bytes('notes.txt'));

        $listed = $this->call('list-operations', ['limit' => 20]);
        $row    = current(array_filter($listed['operations'], static fn ($op) => $op['operation_id'] === $out['operation_id']));
        $this->assertFalse($row['rollback_available']);
        $this->assertNotNull($row['rolled_back_at']);
    }

    /** Issue #593: undoing a created file twice does not remove a file written there since. */
    public function test_a_second_undo_of_a_file_create_does_not_remove_a_newer_file(): void
    {
        $out = $this->call('write-file', ['path' => $this->path('made.txt'), 'content' => 'one']);
        $this->assertTrue($this->call('rollback-operation', ['operation_id' => $out['operation_id']])['restored']);
        $this->assertNull($this->bytes('made.txt'));

        file_put_contents(ABSPATH . $this->path('made.txt'), 'someone else');
        $this->assertFalse($this->call('rollback-operation', ['operation_id' => $out['operation_id']])['restored']);
        $this->assertSame('someone else', $this->bytes('made.txt'));
    }

    /**
     * A file restore its site check refused (issue #602) is not recorded as
     * undone (issue #593): the operation stays restorable and a retry works.
     */
    public function test_a_refused_php_restore_stays_restorable(): void
    {
        $original = "<?php\nfunction wpmcp_undo_retry_fixture() { return 'old'; }\n";
        $edited   = "<?php\nfunction wpmcp_undo_retry_fixture() { return 'new'; }\n";
        file_put_contents(ABSPATH . $this->path('functions.php'), $original);
        $out = $this->call('write-file', ['path' => $this->path('functions.php'), 'content' => $edited]);

        $this->responder = static fn () => new \WP_Error('http_request_failed', 'loopback blocked');
        $this->assertFalse($this->call('rollback-operation', ['operation_id' => $out['operation_id']])['restored']);
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($out['operation_id'])));

        $this->responder = static fn (): array => [200, true];
        $retry = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($retry['restored'], wp_json_encode($retry));
        $this->assertSame($original, $this->bytes('functions.php'));
    }

    /** Issue #593: a second rollback-session of file writes changes nothing. */
    public function test_a_second_rollback_session_of_file_writes_changes_nothing(): void
    {
        file_put_contents(ABSPATH . $this->path('keep.txt'), "v1\n");
        $session = 'fs-undo-' . wp_generate_password(8, false);
        $this->call('edit-file', ['path' => $this->path('keep.txt'), 'old_string' => 'v1', 'new_string' => 'v2', 'session_id' => $session]);
        $this->call('delete-file', ['path' => $this->path('keep.txt'), 'confirm' => true, 'session_id' => $session]);

        $this->assertSame(2, $this->call('rollback-session', ['session_id' => $session])['restored_count']);
        $this->assertSame("v1\n", $this->bytes('keep.txt'));
        file_put_contents(ABSPATH . $this->path('keep.txt'), "later\n");

        $again = $this->call('rollback-session', ['session_id' => $session]);
        $this->assertSame(0, $again['restored_count']);
        $this->assertStringContainsString('already undone', implode(' ', $again['warnings']));
        $this->assertSame("later\n", $this->bytes('keep.txt'));
    }
}
