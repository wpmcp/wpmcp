<?php

namespace WPMCP\Tests\Free\Build;

/**
 * The WordPress.org WooCommerce listing is narrowed to store tools plus the
 * safety core (issue #1140), and a guard fails the build if anything it
 * strips comes back.
 *
 * Runs the real strip with the real WooCommerce manifest over a staging of
 * this checkout, then scripts/flavors/woocommerce/assert-narrowed.php over
 * the result, as scripts/build-woo-release.sh does. The guard is tested in
 * both directions: it passes the stage the strip produces, and it fails a
 * copy of that stage with a stripped class, registration, action, document
 * mention or the cloud client put back.
 *
 * The full plugin is not narrowed: tests/support/ability-manifest.php and
 * AbilityManifestTest pin its surface, and the last test here checks that
 * every ability this flavor strips is still registered there.
 */
class WooNarrowingTest extends \WP_UnitTestCase
{
    /** Matches the ability name in a `new Ability( 'wpmcp/x', 'tier', ...` call. */
    private const ABILITY_RE = '/new\s+Ability\(\s*\n\s*[\'"]([^\'"]+)[\'"]/';

    private const MANIFEST = 'scripts/flavors/woocommerce/manifest.php';
    private const NARROWED = 'scripts/flavors/woocommerce/narrowed.php';
    private const GUARD    = 'scripts/flavors/woocommerce/assert-narrowed.php';

    private static ?string $stage = null;

    /** @var string[] copies made by individual tests, removed after the class */
    private static array $copies = [];

    public static function tearDownAfterClass(): void
    {
        foreach (array_merge(self::$copies, null === self::$stage ? [] : [dirname(self::$stage)]) as $path) {
            if (is_dir($path)) {
                self::remove_tree($path);
            }
        }
        self::$copies = [];
        self::$stage  = null;
        parent::tearDownAfterClass();
    }

    public function test_the_guard_passes_the_narrowed_stage(): void
    {
        [$code, $output] = self::guard(self::stage());

        $this->assertSame(0, $code, "the guard rejected the stage the strip produced:\n" . $output);
    }

    public function test_no_stripped_ability_is_registered(): void
    {
        $survivors = array_values(array_intersect(self::registered(self::stage()), array_keys(self::narrowed()['abilities'])));

        $this->assertSame([], $survivors, 'stripped abilities are still registered: ' . implode(', ', $survivors));
    }

    public function test_the_store_tools_and_the_safety_core_stay(): void
    {
        $registered = self::registered(self::stage());

        foreach (
            [
                'wpmcp/rollback-operation', 'wpmcp/rollback-session', 'wpmcp/list-operations',
                'wpmcp/create-post', 'wpmcp/update-post', 'wpmcp/upload-media',
                'wpmcp/list-tables', 'wpmcp/describe-table', 'wpmcp/scan-security',
                'wpmcp/incident-response',
            ] as $kept
        ) {
            $this->assertContains($kept, $registered, "$kept left the narrowed build");
        }
        $this->assertStringContainsString(
            'function register_woocommerce_abilities(',
            (string) file_get_contents(self::stage() . '/src/Plugin.php')
        );
    }

    public function test_every_stripped_class_and_path_is_gone(): void
    {
        $stage    = self::stage();
        $narrowed = self::narrowed();

        foreach ($narrowed['classes'] as $class) {
            $this->assertFileDoesNotExist($stage . '/src/' . str_replace('\\', '/', substr($class, strlen('WPMCP\\'))) . '.php');
        }
        foreach ($narrowed['paths'] as $path) {
            $this->assertFileDoesNotExist($stage . '/' . $path);
        }
    }

    public function test_incident_response_keeps_only_its_credential_and_session_actions(): void
    {
        $source = (string) file_get_contents(self::stage() . '/src/Tools/Security/Incident_Response.php');

        foreach (array_keys(self::narrowed()['actions']['wpmcp/incident-response']) as $action) {
            $this->assertStringNotContainsString("'$action'", $source);
        }
        foreach (['Salt_Rotator', 'Core_File_Repair', 'File_Quarantine'] as $class) {
            $this->assertStringNotContainsString($class, $source);
        }
        foreach (['list-app-passwords', 'revoke-app-password', 'list-sessions', 'end-sessions'] as $kept) {
            $this->assertStringContainsString("'$kept',", $source, "$kept must stay");
        }
        $this->assertSame(0, self::lint(self::stage() . '/src/Tools/Security/Incident_Response.php'));
    }

    public function test_the_security_scan_hints_name_no_stripped_tool(): void
    {
        foreach (['Compromise_Audit.php', 'Malware_Audit.php'] as $file) {
            $source = (string) file_get_contents(self::stage() . '/src/Tools/Security/' . $file);
            foreach (['read-file', 'search-files', 'delete-file', 'quarantine-file', 'search-content'] as $tool) {
                $this->assertStringNotContainsString("$tool'", $source, "$file still points at $tool");
                $this->assertStringNotContainsString("with $tool", $source, "$file still points at $tool");
            }
            $this->assertSame(0, self::lint(self::stage() . '/src/Tools/Security/' . $file));
        }
    }

    /**
     * Issue #1147, owner decision (a): whole-site restore and the SQL
     * importer leave the listing, taking backups does not.
     */
    public function test_restore_leaves_and_backup_creation_stays(): void
    {
        $stage      = self::stage();
        $registered = self::registered($stage);

        $this->assertNotContains('wpmcp/restore-site-backup', $registered);
        foreach (
            [
                'wpmcp/trigger-backup', 'wpmcp/get-backup-status', 'wpmcp/list-backup-jobs',
                'wpmcp/cancel-backup-job', 'wpmcp/get-backup-manifest', 'wpmcp/delete-backup-archive',
            ] as $kept
        ) {
            $this->assertContains($kept, $registered, "$kept left the narrowed build");
        }
        foreach (['Run_Backup_Job', 'Db_Dumper', 'Site_Archive_Builder', 'Backup_Job_Store', 'Restore_Files'] as $class) {
            $this->assertFileExists($stage . '/src/Tools/Backup/' . $class . '.php', "$class must stay");
        }

        // No shipped string sends an agent to the tool, in any spelling.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension() || 'Not_In_Build.php' === $file->getFilename()) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $this->assertStringNotContainsString('restore-site-backup', $token[1], $file->getPathname() . ':' . $token[2]);
                }
            }
            $this->assertSame(0, self::lint($file->getPathname()), $file->getPathname());
        }

        // The refusal names what is left out.
        self::load_refusal();
        $error = \WPMCP\MCP\Not_In_Build::refusal('wpmcp/restore-site-backup', ['job_id' => 1, 'dry_run' => false]);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('not_in_this_build', $error->get_error_code());
        $this->assertStringContainsString('whole-site restore from a backup archive', $error->get_error_message());
    }

    public function test_the_guard_fails_when_the_sql_importer_comes_back(): void
    {
        $copy = self::copy_of_stage();
        copy(self::repo_root() . '/src/Tools/Backup/Sql_Importer.php', $copy . '/src/Tools/Backup/Sql_Importer.php');

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Sql_Importer', $output);
    }

    public function test_the_guard_fails_when_a_stripped_tool_class_creeps_back(): void
    {
        $copy = self::copy_of_stage();
        copy(self::repo_root() . '/src/Tools/Filesystem/Write_File.php', $copy . '/src/Tools/Filesystem/Write_File.php');

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Write_File', $output);
    }

    public function test_the_guard_fails_when_a_stripped_registration_creeps_back(): void
    {
        $copy   = self::copy_of_stage();
        $plugin = $copy . '/src/Plugin.php';
        $source = (string) file_get_contents($plugin);
        $source = preg_replace(
            "/\n    public function register_abilities_into\(Registrar \\\$registrar\): void\n    \{\n/",
            "\$0        \$registrar->register(new Ability(\n            'wpmcp/query',\n            'free',\n            'x',\n            [],\n            '__return_true'\n        ));\n",
            $source,
            1,
            $count
        );
        $this->assertSame(1, $count, 'could not plant the registration');
        file_put_contents($plugin, $source);

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('wpmcp/query', $output);
    }

    public function test_the_guard_fails_when_rotate_salts_comes_back(): void
    {
        $copy = self::copy_of_stage();
        copy(self::repo_root() . '/src/Tools/Security/Incident_Response.php', $copy . '/src/Tools/Security/Incident_Response.php');

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('rotate_salts', $output);
        $this->assertStringContainsString('rotate-salts', $output);
    }

    public function test_the_guard_fails_when_a_document_names_a_stripped_tool(): void
    {
        $copy = self::copy_of_stage();
        file_put_contents(
            $copy . '/src/Skills/library/wpmcp-safe-writes/SKILL.md',
            "\nFix it with `wpmcp/edit-file`.\n",
            FILE_APPEND
        );

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('wpmcp-safe-writes/SKILL.md', $output);
    }

    public function test_the_guard_fails_when_the_cloud_client_comes_back(): void
    {
        $copy = self::copy_of_stage();
        self::copy_tree(self::repo_root() . '/src/Cloud', $copy . '/src/Cloud');

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Cloud_Client', $output);
    }

    public function test_the_guard_fails_when_the_refusal_drifts_from_the_list(): void
    {
        $copy    = self::copy_of_stage();
        $refusal = $copy . '/src/MCP/Not_In_Build.php';
        $source  = (string) file_get_contents($refusal);
        $this->assertSame(1, substr_count($source, "        'wpmcp/query'"), 'could not find the entry to drop');
        file_put_contents($refusal, preg_replace("/\n        'wpmcp\/query'[^\n]*/", '', $source));

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('wpmcp/query', $output);
    }

    public function test_the_guard_fails_when_a_refused_action_drifts_from_the_list(): void
    {
        $copy    = self::copy_of_stage();
        $refusal = $copy . '/src/MCP/Not_In_Build.php';
        $source  = (string) file_get_contents($refusal);
        $this->assertSame(1, substr_count($source, "            'quarantine-file'"), 'could not find the entry to drop');
        file_put_contents($refusal, preg_replace("/\n            'quarantine-file'[^\n]*/", '', $source));

        [$code, $output] = self::guard($copy);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('wpmcp/incident-response quarantine-file', $output);
    }

    public function test_a_stripped_tool_called_by_name_gets_the_build_refusal(): void
    {
        self::load_refusal();

        $error = \WPMCP\MCP\Not_In_Build::refusal('wpmcp/write-file');
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('not_in_this_build', $error->get_error_code());
        $this->assertStringContainsString('is not in this build', $error->get_error_message());
        $this->assertStringContainsString('file writes', $error->get_error_message());
        $this->assertSame('wpmcp/write-file', $error->get_error_data()['ability']);

        // The name an MCP client sees in tools/list, not only the ability name.
        $tool = \WPMCP\MCP\Stdio_Transport::tool_name('wpmcp/query');
        $this->assertNotSame('wpmcp/query', $tool);
        $by_tool = \WPMCP\MCP\Not_In_Build::refusal($tool);
        $this->assertInstanceOf(\WP_Error::class, $by_tool);
        $this->assertSame('wpmcp/query', $by_tool->get_error_data()['ability']);

        $this->assertNull(\WPMCP\MCP\Not_In_Build::refusal('wpmcp/get-post'));
        $this->assertNull(\WPMCP\MCP\Not_In_Build::refusal(''));

        foreach (['wpmcp/read-file', 'wpmcp/list-directory', 'wpmcp/search-files', 'wpmcp/rewrite-site-urls', 'wpmcp/find-replace-content', 'wpmcp/restore-site-backup'] as $name) {
            $this->assertSame('not_in_this_build', \WPMCP\MCP\Not_In_Build::refusal($name)->get_error_code(), $name);
        }
    }

    public function test_a_stripped_action_of_a_kept_tool_gets_the_build_refusal(): void
    {
        self::load_refusal();
        $tool = \WPMCP\MCP\Stdio_Transport::tool_name('wpmcp/incident-response');

        foreach (['rotate-salts', 'quarantine-file', 'reinstall-core-files', 'restore-quarantined-file'] as $action) {
            $error = \WPMCP\MCP\Not_In_Build::refusal($tool, ['action' => $action, 'confirm' => true]);
            $this->assertInstanceOf(\WP_Error::class, $error, $action);
            $this->assertSame('not_in_this_build', $error->get_error_code());
            $this->assertStringContainsString('"' . $action . '" action', $error->get_error_message());
        }
        $this->assertStringContainsString(
            'wp-config.php',
            \WPMCP\MCP\Not_In_Build::refusal('wpmcp/incident-response', ['action' => 'rotate-salts'])->get_error_message()
        );
        // The actions it keeps, and a call with no action, pass through.
        $this->assertNull(\WPMCP\MCP\Not_In_Build::refusal($tool, ['action' => 'list-sessions']));
        $this->assertNull(\WPMCP\MCP\Not_In_Build::refusal($tool));
        $this->assertNull(\WPMCP\MCP\Not_In_Build::for_message(['method' => 'tools/list']));
        $this->assertInstanceOf(
            \WP_Error::class,
            \WPMCP\MCP\Not_In_Build::for_message(['method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => ['action' => 'rotate-salts']]])
        );
    }

    public function test_both_transports_answer_with_the_build_refusal(): void
    {
        $stage = self::stage();

        $this->assertStringContainsString(
            '$not_in_build = Not_In_Build::for_message($message);',
            (string) file_get_contents($stage . '/src/MCP/Hidden_Tool_Refusal.php')
        );
        $this->assertStringContainsString(
            '$not_in_build = Not_In_Build::refusal($tool_name, ',
            (string) file_get_contents($stage . '/src/MCP/Stdio_Transport.php')
        );
    }

    public function test_the_build_script_runs_the_guard_on_the_stage_and_on_the_zip(): void
    {
        $script = (string) file_get_contents(self::repo_root() . '/scripts/build-woo-release.sh');

        $this->assertStringContainsString('php "$ROOT/' . self::GUARD . '" "$STAGE"', $script);
        $this->assertStringContainsString('php "$ROOT/' . self::GUARD . '" "$BUILD_DIR/$SLUG"', $script);
    }

    public function test_the_full_plugin_keeps_every_stripped_ability(): void
    {
        $manifest = require self::repo_root() . '/tests/support/ability-manifest.php';

        foreach (array_keys(self::narrowed()['abilities']) as $name) {
            $this->assertArrayHasKey($name, $manifest['abilities'], "$name left the full plugin");
        }
    }

    // ---------------------------------------------------------- helpers

    /** @return array{abilities: array<string,string>, classes: string[], paths: string[], methods: string[], actions: array<string,array<string,string>>} */
    private static function narrowed(): array
    {
        return require self::repo_root() . '/' . self::NARROWED;
    }

    /** @return string[] ability names registered under $stage/src. */
    private static function registered(string $stage): array
    {
        $names    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            preg_match_all(self::ABILITY_RE, (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                $names[ $name ] = true;
            }
        }

        return array_keys($names);
    }

    private static function lint(string $file): int
    {
        $output = [];
        $code   = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        return $code;
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private static function guard(string $stage): array
    {
        $output = [];
        $code   = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::repo_root() . '/' . self::GUARD) . ' ' . escapeshellarg($stage) . ' 2>&1',
            $output,
            $code
        );

        return [$code, implode("\n", $output)];
    }

    private static function load_refusal(): void
    {
        if (! class_exists(\WPMCP\MCP\Not_In_Build::class, false)) {
            require_once self::stage() . '/src/MCP/Not_In_Build.php';
        }
    }

    /**
     * The file set scripts/build-woo-release.sh stages, stripped with the
     * WooCommerce manifest, once per class. Composer and the text-domain
     * rewrite are left out: neither changes what is registered or shipped
     * under src/ beyond the i18n domain.
     */
    private static function stage(): string
    {
        if (null !== self::$stage) {
            return self::$stage;
        }

        $root   = self::repo_root();
        $flavor = $root . '/scripts/flavors/woocommerce';
        $parent = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-woo-narrow-' . uniqid('', true);
        $stage  = $parent . '/safe-mcp-for-woocommerce';
        if (! mkdir($stage, 0777, true)) {
            self::fail('could not create the staging directory');
        }
        foreach (['LICENSE', 'composer.json', 'composer.lock', 'uninstall.php'] as $file) {
            copy($root . '/' . $file, $stage . '/' . $file);
        }
        self::copy_tree($root . '/src', $stage . '/src');
        foreach (['safe-mcp-for-woocommerce.php', 'readme.txt'] as $rendered) {
            file_put_contents(
                $stage . '/' . $rendered,
                str_replace('{{VERSION}}', '0.0.0-test', (string) file_get_contents($flavor . '/' . $rendered))
            );
        }

        $output = [];
        $code   = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/flavors/wporg/strip.php') . ' '
                . escapeshellarg($stage) . ' ' . escapeshellarg($root . '/' . self::MANIFEST) . ' 2>&1',
            $output,
            $code
        );
        if (0 !== $code) {
            self::remove_tree($parent);
            self::fail("WooCommerce strip failed:\n" . implode("\n", $output));
        }

        return self::$stage = $stage;
    }

    private static function copy_of_stage(): string
    {
        $copy = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-woo-narrow-copy-' . uniqid('', true);
        self::$copies[] = $copy;
        self::copy_tree(self::stage(), $copy);

        return $copy;
    }

    private static function repo_root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function copy_tree(string $from, string $to): void
    {
        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            self::fail("could not create $to");
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;
            if (is_dir($source)) {
                self::copy_tree($source, $target);
                continue;
            }
            if (! copy($source, $target)) {
                self::fail("could not stage $source");
            }
        }
    }

    private static function remove_tree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::remove_tree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
