<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Filesystem\Php_Edit_Guard;
use WPMCP\Tools\Security\File_Quarantine;
use WPMCP\Tools\Security\Incident_Response;

/**
 * incident-response quarantine actions (issue #844): move a suspicious file
 * aside without deleting it, list what is held, put it back byte for byte,
 * and purge it for good.
 *
 * Calls go through the registered ability, the way the MCP adapter runs
 * them, except where a test injects the core checksum map. No network: the
 * PHP site check's loopbacks are answered by a pre_http_request mock, as in
 * FileWriteUndoTest, and every other request fails.
 */
class FileQuarantineTest extends \WP_UnitTestCase
{
    private string $rel_dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->rel_dir = 'wp-content/wpmcp-quarantine-test-' . getmypid();
        wp_mkdir_p(ABSPATH . $this->rel_dir);

        add_filter('wpmcp_enable_fs_writes', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());

        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
        Php_Edit_Guard::release_lock();
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        remove_filter('wpmcp_enable_fs_writes', '__return_true');
        Php_Edit_Guard::release_lock();
        foreach ((array) glob(ABSPATH . $this->rel_dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir(ABSPATH . $this->rel_dir);
        foreach ((array) glob(File_Quarantine::directory() . '/*') as $file) {
            @unlink((string) $file);
        }
        @unlink(File_Quarantine::directory() . '/.htaccess');
        @rmdir(File_Quarantine::directory());
        parent::tearDown();
    }

    /** Answer the site check's loopbacks as a healthy site; fail anything else. */
    public function mock_http($pre, $args, $url)
    {
        parse_str((string) wp_parse_url((string) $url, PHP_URL_QUERY), $query);
        if (empty($query['wp_scrape_key'])) {
            return new \WP_Error('http_request_failed', 'No network in FileQuarantineTest: ' . $url);
        }
        $key = (string) $query['wp_scrape_key'];
        return [
            'headers'  => [],
            'body'     => "<html>page</html>\n###### wp_scraping_result_start:$key ######\ntrue\n###### wp_scraping_result_end:$key ######\n",
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function call(array $args): array
    {
        $ability = wp_get_ability('wpmcp/incident-response');
        $this->assertNotNull($ability);
        $result = $ability->execute($args);
        $this->assertNotWPError($result, is_wp_error($result) ? $result->get_error_message() : '');
        return (array) $result;
    }

    /** @return \WP_Error */
    private function refused(array $args): \WP_Error
    {
        $result = wp_get_ability('wpmcp/incident-response')->execute($args);
        $this->assertWPError($result, (string) wp_json_encode($result));
        return $result;
    }

    private function plant(string $name, string $bytes): string
    {
        $rel = $this->rel_dir . '/' . $name;
        file_put_contents(ABSPATH . $rel, $bytes);
        return $rel;
    }

    private function quarantine(string $rel, array $extra = []): array
    {
        return $this->call(['action' => 'quarantine-file', 'path' => $rel, 'confirm' => true] + $extra);
    }

    public function test_quarantine_moves_the_file_aside_and_records_it(): void
    {
        $bytes = "<?php\n/* planted */ eval(\$_POST['x']);\n";
        $rel   = $this->plant('shell.php', $bytes);
        $mtime = (int) filemtime(ABSPATH . $rel);

        $out = $this->quarantine($rel, ['reason' => 'malware_eval']);

        $this->assertFileDoesNotExist(ABSPATH . $rel);
        $this->assertTrue($out['recoverable']);
        $this->assertIsString($out['operation_id']);
        $entry = $out['entry'];
        $this->assertSame($rel, $entry['path']);
        $this->assertSame(hash('sha256', $bytes), $entry['sha256']);
        $this->assertSame(strlen($bytes), $entry['size']);
        $this->assertSame($mtime, $entry['mtime']);
        $this->assertSame('malware_eval', $entry['reason']);
        $this->assertSame('quarantined', $entry['state']);

        // Held privately, under a name no server runs or maps to PHP.
        $stored = File_Quarantine::stored_path($entry['id']);
        $this->assertFileExists($stored);
        $this->assertSame($bytes, file_get_contents($stored));
        $this->assertDoesNotMatchRegularExpression('/\.(php\d*|phtml|phar|pht)$/i', $stored);
        $this->assertStringContainsString('Require all denied', (string) file_get_contents(File_Quarantine::directory() . '/.htaccess'));
        $this->assertFileExists(File_Quarantine::directory() . '/index.php');
        $this->assertStringNotContainsString(ABSPATH . $this->rel_dir, File_Quarantine::directory());

        $listed = $this->call(['action' => 'list-quarantine']);
        $this->assertSame(1, $listed['count']);
        $this->assertSame($entry['id'], $listed['entries'][0]['id']);
        $this->assertSame('quarantined', $listed['entries'][0]['state']);
    }

    public function test_restore_puts_the_file_back_byte_for_byte(): void
    {
        $bytes = "binary\x00\xff\xfe data";
        $rel   = $this->plant('blob.bin', $bytes);
        $id    = $this->quarantine($rel)['entry']['id'];

        $out = $this->call(['action' => 'restore-quarantined-file', 'id' => $id, 'confirm' => true]);

        $this->assertTrue($out['restored']);
        $this->assertSame($bytes, file_get_contents(ABSPATH . $rel));
        $listed = $this->call(['action' => 'list-quarantine']);
        $this->assertSame('restored', $listed['entries'][0]['state']);
    }

    public function test_restore_refuses_to_overwrite_a_new_file_unless_asked(): void
    {
        $rel = $this->plant('note.txt', 'original');
        $id  = $this->quarantine($rel)['entry']['id'];
        file_put_contents(ABSPATH . $rel, 'something new');

        $error = $this->refused(['action' => 'restore-quarantined-file', 'id' => $id, 'confirm' => true]);
        $this->assertStringContainsString('overwrite', $error->get_error_message());
        $this->assertSame('something new', file_get_contents(ABSPATH . $rel));

        $out = $this->call(['action' => 'restore-quarantined-file', 'id' => $id, 'overwrite' => true, 'confirm' => true]);
        $this->assertTrue($out['restored']);
        $this->assertSame('original', file_get_contents(ABSPATH . $rel));

        // The overwrite is itself undoable: the newer file comes back.
        $rolled = wp_get_ability('wpmcp/rollback-operation')->execute(['operation_id' => $out['operation_id']]);
        $this->assertNotWPError($rolled);
        $this->assertSame('something new', file_get_contents(ABSPATH . $rel));
    }

    public function test_rollback_of_a_quarantine_restores_the_file(): void
    {
        $bytes = "<?php\n// suspicious but needed\n";
        $rel   = $this->plant('needed.php', $bytes);
        $out   = $this->quarantine($rel);
        $this->assertFileDoesNotExist(ABSPATH . $rel);

        $rolled = wp_get_ability('wpmcp/rollback-operation')->execute(['operation_id' => $out['operation_id']]);
        $this->assertNotWPError($rolled, is_wp_error($rolled) ? $rolled->get_error_message() : '');
        $this->assertSame($bytes, file_get_contents(ABSPATH . $rel));

        $listed = $this->call(['action' => 'list-quarantine']);
        $this->assertSame('restored', $listed['entries'][0]['state']);
    }

    public function test_purge_removes_the_held_copy_for_good(): void
    {
        $rel = $this->plant('bad.js', 'evil()');
        $id  = $this->quarantine($rel)['entry']['id'];

        $this->refused(['action' => 'purge-quarantined-file', 'id' => $id]);
        $this->assertFileExists(File_Quarantine::stored_path($id));

        $out = $this->call(['action' => 'purge-quarantined-file', 'id' => $id, 'confirm' => true]);
        $this->assertTrue($out['purged']);
        $this->assertFalse($out['recoverable']);
        $this->assertFileDoesNotExist(File_Quarantine::stored_path($id));
        $this->assertSame(0, $this->call(['action' => 'list-quarantine'])['count']);
        $this->refused(['action' => 'restore-quarantined-file', 'id' => $id, 'confirm' => true]);
    }

    public function test_quarantine_needs_confirm_and_the_fs_writes_opt_in(): void
    {
        $rel = $this->plant('x.txt', 'x');

        $this->refused(['action' => 'quarantine-file', 'path' => $rel]);
        $this->assertFileExists(ABSPATH . $rel);

        remove_filter('wpmcp_enable_fs_writes', '__return_true');
        $error = $this->refused(['action' => 'quarantine-file', 'path' => $rel, 'confirm' => true]);
        $this->assertSame('opt_in_required', $error->get_error_code());
        $this->assertFileExists(ABSPATH . $rel);

        // Listing changes nothing, so it stays available with the opt-in off.
        $this->assertSame(0, $this->call(['action' => 'list-quarantine'])['count']);
    }

    public function test_refuses_wp_config_own_files_and_core_files(): void
    {
        $this->refused(['action' => 'quarantine-file', 'path' => 'wp-config.php', 'confirm' => true]);

        $own = substr(rtrim(WPMCP_DIR, '/') . '/wpmcp.php', strlen(ABSPATH));
        if (is_file(ABSPATH . $own)) {
            $this->refused(['action' => 'quarantine-file', 'path' => $own, 'confirm' => true]);
            $this->assertFileExists(ABSPATH . $own);
        }

        $tool = new Incident_Response(null, null, new File_Quarantine(static fn (): array => ['wp-includes/version.php' => 'abc']));
        try {
            $tool->handle(['action' => 'quarantine-file', 'path' => 'wp-includes/version.php', 'confirm' => true]);
            $this->fail('A core file was quarantined.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reinstall-core-files', $e->getMessage());
        }
        $this->assertFileExists(ABSPATH . 'wp-includes/version.php');
    }

    public function test_an_unknown_file_among_core_folders_can_be_quarantined(): void
    {
        $rel  = 'wp-includes/wpmcp-planted-' . getmypid() . '.txt';
        file_put_contents(ABSPATH . $rel, 'planted');
        $tool = new Incident_Response(null, null, new File_Quarantine(static fn (): array => ['wp-includes/version.php' => 'abc']));
        try {
            $out = $tool->handle(['action' => 'quarantine-file', 'path' => $rel, 'confirm' => true]);
            $this->assertFileDoesNotExist(ABSPATH . $rel);
            $this->assertSame($rel, $out['entry']['path']);
        } finally {
            @unlink(ABSPATH . $rel);
        }
    }

    public function test_core_folders_are_refused_when_the_checksums_cannot_be_fetched(): void
    {
        $rel  = 'wp-includes/wpmcp-planted-' . getmypid() . '.txt';
        file_put_contents(ABSPATH . $rel, 'planted');
        $tool = new Incident_Response(null, null, new File_Quarantine(static fn (): array => []));
        try {
            $this->expectException(\RuntimeException::class);
            $tool->handle(['action' => 'quarantine-file', 'path' => $rel, 'confirm' => true]);
        } finally {
            $this->assertFileExists(ABSPATH . $rel);
            @unlink(ABSPATH . $rel);
        }
    }

    public function test_malware_findings_suggest_the_quarantine_step(): void
    {
        $findings = (new \WPMCP\Tools\Security\Malware_Audit())->with_suggested_actions([
            ['id' => 'malware_eval', 'category' => 'malware', 'status' => 'critical', 'value' => ['location' => 'wp-content/uploads/x.php:3', 'snippet' => '']],
            ['id' => 'malware_uploads_php', 'category' => 'malware', 'status' => 'critical', 'value' => ['location' => 'wp-content/uploads/y.php', 'snippet' => '']],
            ['id' => 'malware_wpmcp_backup_php', 'category' => 'malware', 'status' => 'info', 'value' => ['count' => 1, 'locations' => []]],
        ]);

        $this->assertSame(
            ['ability' => 'wpmcp/incident-response', 'arguments' => ['action' => 'quarantine-file', 'path' => 'wp-content/uploads/x.php', 'reason' => 'malware_eval']],
            $findings[0]['suggested_action']
        );
        $this->assertSame('wp-content/uploads/y.php', $findings[1]['suggested_action']['arguments']['path']);
        $this->assertArrayNotHasKey('suggested_action', $findings[2]);
    }
}
