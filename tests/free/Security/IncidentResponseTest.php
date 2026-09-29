<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Security\Core_File_Repair;
use WPMCP\Tools\Security\Incident_Response;
use WPMCP\Tools\Security\Salt_Rotator;

/**
 * incident-response (issue #382): contain a compromise by revoking
 * application passwords, ending sessions, rotating the keys and salts and
 * reinstalling modified core files.
 *
 * Nothing here touches the test install's own wp-config.php or core files:
 * salt rotation runs against a fixture config in a temp directory, and the
 * core repair runs against a fixture root with an injected checksum map and
 * an injected package archive, so no network is used either.
 */
class IncidentResponseTest extends \WP_UnitTestCase
{
    private const ABILITY = 'wpmcp/incident-response';

    private int $admin;
    private string $tmp = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        $this->tmp = sys_get_temp_dir() . '/wpmcp-ir-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
        unset($GLOBALS['wp_rest_application_password_uuid']);
        unset($_COOKIE[LOGGED_IN_COOKIE]);
    }

    public function tearDown(): void
    {
        Core_File_Repair::set_root_for_tests(null);
        unset($GLOBALS['wp_rest_application_password_uuid']);
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if ('' === $dir || ! is_dir($dir)) {
            return;
        }
        chmod($dir, 0777);
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function tool(?Salt_Rotator $salts = null, ?Core_File_Repair $core = null): Incident_Response
    {
        return new Incident_Response($salts, $core);
    }

    // -----------------------------------------------------------------
    // registration
    // -----------------------------------------------------------------

    public function test_ability_is_registered_free_admin_only_and_destructive(): void
    {
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey(self::ABILITY, $abilities);

        $manifest = require dirname(__DIR__, 2) . '/support/ability-manifest.php';
        $this->assertSame('free', $manifest['abilities'][ self::ABILITY ] ?? null);

        $ability = $abilities[ self::ABILITY ];
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertFalse($ability->check_permissions());
        wp_set_current_user($this->admin);
        $this->assertTrue($ability->check_permissions());

        $description = $ability->get_description();
        $this->assertStringContainsString('confirm', $description);
        $this->assertMatchesRegularExpression('/cannot be undone/i', $description);
    }

    // -----------------------------------------------------------------
    // application passwords
    // -----------------------------------------------------------------

    public function test_list_app_passwords_never_returns_secrets_and_masks_the_ip(): void
    {
        [$plain, $item] = \WP_Application_Passwords::create_new_application_password($this->admin, ['name' => 'Laptop']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        \WP_Application_Passwords::record_application_password_usage($this->admin, $item['uuid']);

        $result = $this->tool()->handle(['action' => 'list-app-passwords']);

        $this->assertSame($this->admin, $result['user_id']);
        $this->assertCount(1, $result['app_passwords']);
        $row = $result['app_passwords'][0];
        $this->assertSame($item['uuid'], $row['uuid']);
        $this->assertSame('Laptop', $row['name']);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertSame('203.0.113.0', $row['last_ip']);

        $json = (string) wp_json_encode($result);
        $this->assertStringNotContainsString($plain, $json);
        $this->assertStringNotContainsString((string) $item['password'], $json);
        $this->assertStringNotContainsString('203.0.113.77', $json);
    }

    public function test_listing_another_users_passwords_needs_edit_users(): void
    {
        $other = self::factory()->user->create(['role' => 'administrator']);
        \WP_Application_Passwords::create_new_application_password($other, ['name' => 'Other']);

        $result = $this->tool()->handle(['action' => 'list-app-passwords', 'user_id' => $other]);
        $this->assertCount(1, $result['app_passwords']);

        add_role('wpmcp_ir_ops', 'IR ops', ['read' => true, 'manage_options' => true]);
        $ops = self::factory()->user->create(['role' => 'wpmcp_ir_ops']);
        wp_set_current_user($ops);
        try {
            $this->expectException(\RuntimeException::class);
            $this->tool()->handle(['action' => 'list-app-passwords', 'user_id' => $other]);
        } finally {
            remove_role('wpmcp_ir_ops');
        }
    }

    public function test_revoke_needs_confirm(): void
    {
        [, $item] = \WP_Application_Passwords::create_new_application_password($this->admin, ['name' => 'Keep']);

        try {
            $this->tool()->handle(['action' => 'revoke-app-password', 'uuid' => $item['uuid']]);
            $this->fail('revoking without confirm must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm', $e->getMessage());
        }
        $this->assertNotNull(\WP_Application_Passwords::get_user_application_password($this->admin, $item['uuid']));
    }

    public function test_owner_revokes_one_password_by_uuid_and_it_is_audited(): void
    {
        $owner = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($owner);
        [, $one] = \WP_Application_Passwords::create_new_application_password($owner, ['name' => 'One']);
        [, $two] = \WP_Application_Passwords::create_new_application_password($owner, ['name' => 'Two']);

        $result = $this->tool()->handle(['action' => 'revoke-app-password', 'uuid' => $one['uuid'], 'confirm' => true]);

        $this->assertSame([['uuid' => $one['uuid'], 'name' => 'One']], $result['revoked']);
        $this->assertFalse($result['recoverable']);
        $this->assertNull(\WP_Application_Passwords::get_user_application_password($owner, $one['uuid']));
        $this->assertNotNull(\WP_Application_Passwords::get_user_application_password($owner, $two['uuid']));

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame(self::ABILITY, $entry['ability']);
        $this->assertTrue($entry['allowed']);
        $this->assertStringStartsWith('revoke-app-password', $entry['reason']);
    }

    public function test_revoking_an_unknown_uuid_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tool()->handle(['action' => 'revoke-app-password', 'uuid' => wp_generate_uuid4(), 'confirm' => true]);
    }

    public function test_revoke_all_keeps_the_password_this_request_uses_unless_asked(): void
    {
        [, $current] = \WP_Application_Passwords::create_new_application_password($this->admin, ['name' => 'Agent']);
        [, $other]   = \WP_Application_Passwords::create_new_application_password($this->admin, ['name' => 'Leaked']);
        $GLOBALS['wp_rest_application_password_uuid'] = $current['uuid'];

        $result = $this->tool()->handle(['action' => 'revoke-app-password', 'all' => true, 'confirm' => true]);

        $this->assertTrue($result['kept_current']);
        $this->assertSame([$other['uuid']], array_column($result['revoked'], 'uuid'));
        $this->assertNotNull(\WP_Application_Passwords::get_user_application_password($this->admin, $current['uuid']));

        $result = $this->tool()->handle(['action' => 'revoke-app-password', 'all' => true, 'include_current' => true, 'confirm' => true]);

        $this->assertFalse($result['kept_current']);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin));
    }

    public function test_revoking_another_users_passwords_needs_edit_users(): void
    {
        $victim = self::factory()->user->create(['role' => 'administrator']);
        [, $item] = \WP_Application_Passwords::create_new_application_password($victim, ['name' => 'X']);

        add_role('wpmcp_ir_ops', 'IR ops', ['read' => true, 'manage_options' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => 'wpmcp_ir_ops']));
        try {
            $this->tool()->handle(['action' => 'revoke-app-password', 'user_id' => $victim, 'all' => true, 'confirm' => true]);
            $this->fail('revoking another user\'s passwords without edit_users must be refused');
        } catch (\RuntimeException $e) {
            $this->assertNotNull(\WP_Application_Passwords::get_user_application_password($victim, $item['uuid']));
        } finally {
            remove_role('wpmcp_ir_ops');
        }
    }

    // -----------------------------------------------------------------
    // sessions
    // -----------------------------------------------------------------

    private function sign_in_as_caller(): string
    {
        $token = \WP_Session_Tokens::get_instance($this->admin)->create(time() + HOUR_IN_SECONDS);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($this->admin, time() + HOUR_IN_SECONDS, 'logged_in', $token);
        return $token;
    }

    private function sessions(int $user_id): int
    {
        return count(\WP_Session_Tokens::get_instance($user_id)->get_all());
    }

    public function test_end_sessions_needs_confirm(): void
    {
        $user = self::factory()->user->create(['role' => 'author']);
        \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->tool()->handle(['action' => 'end-sessions', 'user_id' => $user]);
        } finally {
            $this->assertSame(1, $this->sessions($user));
        }
    }

    public function test_end_sessions_for_one_user_leaves_the_caller_signed_in(): void
    {
        $this->sign_in_as_caller();
        $user = self::factory()->user->create(['role' => 'author']);
        \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);
        \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);

        $result = $this->tool()->handle(['action' => 'end-sessions', 'user_id' => $user, 'confirm' => true]);

        $this->assertSame(0, $this->sessions($user));
        $this->assertSame(1, $this->sessions($this->admin));
        $this->assertSame(2, $result['sessions_ended']);
        $this->assertFalse($result['recoverable']);
    }

    public function test_end_sessions_for_all_users_keeps_the_callers_current_session_unless_asked(): void
    {
        $token = $this->sign_in_as_caller();
        \WP_Session_Tokens::get_instance($this->admin)->create(time() + HOUR_IN_SECONDS); // caller's other device
        $a = self::factory()->user->create(['role' => 'author']);
        $b = self::factory()->user->create(['role' => 'administrator']);
        \WP_Session_Tokens::get_instance($a)->create(time() + HOUR_IN_SECONDS);
        \WP_Session_Tokens::get_instance($b)->create(time() + HOUR_IN_SECONDS);

        $result = $this->tool()->handle(['action' => 'end-sessions', 'all' => true, 'confirm' => true]);

        $this->assertTrue($result['kept_current']);
        $this->assertSame(0, $this->sessions($a));
        $this->assertSame(0, $this->sessions($b));
        $this->assertSame(1, $this->sessions($this->admin));
        $this->assertNotNull(\WP_Session_Tokens::get_instance($this->admin)->get($token));

        $result = $this->tool()->handle(['action' => 'end-sessions', 'all' => true, 'include_current' => true, 'confirm' => true]);

        $this->assertFalse($result['kept_current']);
        $this->assertSame(0, $this->sessions($this->admin));
    }

    public function test_ending_another_users_sessions_needs_edit_users(): void
    {
        $user = self::factory()->user->create(['role' => 'author']);
        \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);

        add_role('wpmcp_ir_ops', 'IR ops', ['read' => true, 'manage_options' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => 'wpmcp_ir_ops']));
        try {
            $this->tool()->handle(['action' => 'end-sessions', 'user_id' => $user, 'confirm' => true]);
            $this->fail('ending another user\'s sessions without edit_users must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(1, $this->sessions($user));
        } finally {
            remove_role('wpmcp_ir_ops');
        }
    }

    // -----------------------------------------------------------------
    // salt rotation (fixture wp-config.php only)
    // -----------------------------------------------------------------

    /** A wp-config.php whose salt literals match the constants this test run has defined. */
    private function fixture_config(array $skip = [], array $override = []): string
    {
        $lines = ["<?php", "define( 'DB_NAME', 'fixture_db' );", "define( 'DB_PASSWORD', 'not-a-real-secret' );"];
        foreach (Salt_Rotator::KEYS as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $value   = $override[ $key ] ?? (defined($key) ? (string) constant($key) : '');
            $lines[] = sprintf('define( %s, %s );', var_export($key, true), var_export($value, true));
        }
        $lines[] = "\$table_prefix = 'wp_';";
        $lines[] = "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}";
        $path = $this->tmp . '/wp-config.php';
        file_put_contents($path, implode("\n", $lines) . "\n");
        chmod($path, 0640);
        return $path;
    }

    /** @return array<string,string> KEY => literal value in $source. */
    private function salts_in(string $source): array
    {
        preg_match_all("/define\\(\\s*'([A-Z_]+)',\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\);/", $source, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $out[ $match[1] ] = stripcslashes($match[2]);
        }
        return array_intersect_key($out, array_flip(Salt_Rotator::KEYS));
    }

    private function backups(): array
    {
        return array_values(array_filter(
            (array) scandir($this->tmp),
            static fn($f) => 0 === strpos((string) $f, 'wp-config.wpmcp-backup-')
        ));
    }

    public function test_rotate_salts_needs_confirm(): void
    {
        $config   = $this->fixture_config();
        $original = file_get_contents($config);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->tool(new Salt_Rotator($config))->handle(['action' => 'rotate-salts']);
        } finally {
            $this->assertSame($original, file_get_contents($config));
        }
    }

    public function test_rotate_salts_writes_valid_new_keys_and_backs_up_the_original(): void
    {
        $config   = $this->fixture_config();
        $original = (string) file_get_contents($config);
        $before   = $this->salts_in($original);

        $result = $this->tool(new Salt_Rotator($config))->handle(['action' => 'rotate-salts', 'confirm' => true]);

        $after = (string) file_get_contents($config);
        token_get_all($after, TOKEN_PARSE); // throws ParseError on a broken file
        $salts = $this->salts_in($after);
        $this->assertSame(Salt_Rotator::KEYS, array_keys($salts));
        foreach ($salts as $key => $value) {
            $this->assertSame(64, strlen($value), $key);
            $this->assertNotSame($before[ $key ] ?? null, $value, $key);
        }
        $this->assertSame(8, count(array_unique($salts)));
        $this->assertStringContainsString("define( 'DB_PASSWORD', 'not-a-real-secret' );", $after);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $after);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($config)), -4));

        $backups = $this->backups();
        $this->assertCount(1, $backups);
        $this->assertStringEndsWith('.php', $backups[0]);
        $this->assertSame($original, file_get_contents($this->tmp . '/' . $backups[0]));
        $this->assertSame([], glob($this->tmp . '/*.tmp') ?: []);

        $this->assertSame(Salt_Rotator::KEYS, $result['rotated']);
        $this->assertSame($backups[0], $result['backup']);
        $this->assertFalse($result['recoverable']);
        $json = (string) wp_json_encode($result);
        foreach (array_merge($salts, $before) as $value) {
            if ('' !== $value) {
                $this->assertStringNotContainsString($value, $json);
            }
        }
        $this->assertStringNotContainsString('not-a-real-secret', $json);

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame(self::ABILITY, $entry['ability']);
        $this->assertTrue($entry['allowed']);
        $this->assertStringStartsWith('rotate-salts', $entry['reason']);
    }

    public function test_rotate_salts_refuses_an_unwritable_config_with_the_reason(): void
    {
        $config   = $this->fixture_config();
        $original = file_get_contents($config);
        chmod($config, 0444);

        try {
            $this->tool(new Salt_Rotator($config))->handle(['action' => 'rotate-salts', 'confirm' => true]);
            $this->fail('an unwritable wp-config.php must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not writable', $e->getMessage());
        }
        $this->assertSame($original, file_get_contents($config));
        $this->assertSame([], $this->backups());

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertFalse($entry['allowed']);
        $this->assertStringStartsWith('rotate-salts', $entry['reason']);
    }

    public function test_rotate_salts_refuses_when_a_salt_is_defined_outside_wp_config(): void
    {
        $config   = $this->fixture_config(['NONCE_SALT']);
        $original = file_get_contents($config);

        try {
            $this->tool(new Salt_Rotator($config))->handle(['action' => 'rotate-salts', 'confirm' => true]);
            $this->fail('a salt wp-config.php does not define must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NONCE_SALT', $e->getMessage());
        }
        $this->assertSame($original, file_get_contents($config));
        $this->assertSame([], $this->backups());
    }

    public function test_rotate_salts_refuses_when_the_running_value_is_not_the_files_literal(): void
    {
        $config   = $this->fixture_config([], ['AUTH_KEY' => 'a value that some other file overrides']);
        $original = file_get_contents($config);

        try {
            $this->tool(new Salt_Rotator($config))->handle(['action' => 'rotate-salts', 'confirm' => true]);
            $this->fail('a salt whose running value comes from elsewhere must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AUTH_KEY', $e->getMessage());
        }
        $this->assertSame($original, file_get_contents($config));
    }

    // -----------------------------------------------------------------
    // core file reinstall (fixture root, injected checksums and package)
    // -----------------------------------------------------------------

    /** Build the fixture install and return a Core_File_Repair wired to it. */
    private function fixture_core(?callable $checksums = null, ?string $zip = null): Core_File_Repair
    {
        $root = $this->tmp . '/site';
        foreach (['wp-includes', 'wp-admin', 'wp-content/plugins'] as $dir) {
            mkdir($root . '/' . $dir, 0777, true);
        }
        file_put_contents($root . '/wp-includes/good.php', 'good');
        file_put_contents($root . '/wp-includes/bad.php', 'hacked');
        file_put_contents($root . '/wp-includes/tampered.php', 'modified');
        file_put_contents($root . '/wp-content/plugins/hello.php', 'plugin edit');
        Core_File_Repair::set_root_for_tests($root);

        $map = [
            'wp-includes/good.php'         => md5('good'),
            'wp-includes/bad.php'          => md5('clean'),
            'wp-admin/gone.php'            => md5('gone-clean'),
            'wp-includes/tampered.php'     => md5('pkg-clean'),
            'wp-content/plugins/hello.php' => md5('hello-clean'),
        ];

        if (null === $zip) {
            $zip     = $this->tmp . '/package.zip';
            $archive = new \ZipArchive();
            $archive->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $archive->addFromString('wordpress/wp-includes/good.php', 'good');
            $archive->addFromString('wordpress/wp-includes/bad.php', 'clean');
            $archive->addFromString('wordpress/wp-admin/gone.php', 'gone-clean');
            $archive->addFromString('wordpress/wp-includes/tampered.php', 'not what the checksum says');
            $archive->addFromString('wordpress/wp-content/plugins/hello.php', 'hello-clean');
            $archive->close();
        }

        return new Core_File_Repair(
            $checksums ?? static fn(): array => $map,
            static fn(string $version): string => $zip
        );
    }

    public function test_reinstall_needs_confirm_and_touches_nothing_without_it(): void
    {
        $core = $this->fixture_core();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->tool(null, $core)->handle(['action' => 'reinstall-core-files']);
        } finally {
            $this->assertSame('hacked', file_get_contents($this->tmp . '/site/wp-includes/bad.php'));
        }
    }

    public function test_reinstall_repairs_flagged_core_files_from_the_verified_package_and_never_wp_content(): void
    {
        $core = $this->fixture_core();
        $root = $this->tmp . '/site';

        $result = $this->tool(null, $core)->handle(['action' => 'reinstall-core-files', 'confirm' => true]);

        $this->assertSame(['wp-admin/gone.php', 'wp-includes/bad.php'], $result['repaired']);
        $this->assertSame('clean', file_get_contents($root . '/wp-includes/bad.php'));
        $this->assertSame('gone-clean', file_get_contents($root . '/wp-admin/gone.php'));
        $this->assertSame('good', file_get_contents($root . '/wp-includes/good.php'));

        // The package copy fails its checksum, so the file is left alone and reported.
        $this->assertSame('modified', file_get_contents($root . '/wp-includes/tampered.php'));
        $this->assertContains('wp-includes/tampered.php', array_column($result['skipped'], 'path'));

        // wp-content is never core, whatever the checksum list says.
        $this->assertSame('plugin edit', file_get_contents($root . '/wp-content/plugins/hello.php'));
        $this->assertNotContains('wp-content/plugins/hello.php', array_column($result['skipped'], 'path'));

        $this->assertTrue($result['recoverable']);
        $this->assertNotEmpty($result['operation_id']);

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame(self::ABILITY, $entry['ability']);
        $this->assertStringStartsWith('reinstall-core-files', $entry['reason']);
    }

    public function test_replaced_core_files_are_restored_by_rollback_operation(): void
    {
        $core = $this->fixture_core();
        $root = $this->tmp . '/site';

        $result = $this->tool(null, $core)->handle(['action' => 'reinstall-core-files', 'confirm' => true]);
        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));

        $this->assertSame('hacked', file_get_contents($root . '/wp-includes/bad.php'));
        $this->assertFileDoesNotExist($root . '/wp-admin/gone.php');
        $this->assertContains('core_files', Rollback_Service::restorable_object_types());
    }

    public function test_reinstall_can_be_limited_to_named_paths(): void
    {
        $core = $this->fixture_core();
        $root = $this->tmp . '/site';

        $result = $this->tool(null, $core)->handle([
            'action'  => 'reinstall-core-files',
            'paths'   => ['wp-includes/bad.php', 'wp-includes/good.php', '../wp-config.php'],
            'confirm' => true,
        ]);

        $this->assertSame(['wp-includes/bad.php'], $result['repaired']);
        $this->assertFileDoesNotExist($root . '/wp-admin/gone.php');
        $skipped = array_column($result['skipped'], 'reason', 'path');
        $this->assertArrayHasKey('wp-includes/good.php', $skipped);
        $this->assertArrayHasKey('../wp-config.php', $skipped);
    }

    public function test_reinstall_refuses_when_official_checksums_are_unavailable(): void
    {
        $core = $this->fixture_core(static fn(): array => []);

        try {
            $this->tool(null, $core)->handle(['action' => 'reinstall-core-files', 'confirm' => true]);
            $this->fail('a repair without official checksums must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame('hacked', file_get_contents($this->tmp . '/site/wp-includes/bad.php'));
    }

    public function test_reinstall_refuses_when_the_package_cannot_be_fetched(): void
    {
        $core = $this->fixture_core(null, $this->tmp . '/missing.zip');

        $this->expectException(\RuntimeException::class);
        try {
            $this->tool(null, $core)->handle(['action' => 'reinstall-core-files', 'confirm' => true]);
        } finally {
            $this->assertSame('hacked', file_get_contents($this->tmp . '/site/wp-includes/bad.php'));
        }
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool()->handle(['action' => 'set-role']);
    }
}
