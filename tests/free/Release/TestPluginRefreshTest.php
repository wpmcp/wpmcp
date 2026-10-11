<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * Issue #532: the gate installed every unpinned test plugin as latest-stable,
 * but keyed its cached install only on the installer scripts, so a new
 * release was never tested until someone edited them. The install is now
 * keyed on the resolved plugin versions too, falls back to the newest cached
 * install offline, and the installer reinstalls a plugin present at another
 * version than the one asked for.
 *
 * Everything here runs without a database or the network: the plugins info
 * API is replaced by file:// JSON (WPMCP_PLUGIN_INFO_URL) and the download
 * by a curl stand-in on PATH.
 */
class TestPluginRefreshTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/wpmcp-refresh-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/info', 0777, true);
        mkdir($this->tmp . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Runs a repository script with only the given environment (plus PATH and
     * HOME), so no WPMCP_LIVE_* or ELEMENTOR_VERSION of the calling run leaks in.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private function run_script(string $script, array $args, array $env = []): array
    {
        $command = 'bash ' . escapeshellarg($this->repository() . '/' . $script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $env += [
            'PATH'              => (string) getenv('PATH'),
            'HOME'              => (string) getenv('HOME'),
            'TMPDIR'            => $this->tmp,
            'WPMCP_TEST_PHP'    => PHP_BINARY,
            'WPMCP_TEST_CACHE'  => $this->tmp . '/cache',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repository(), $env);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function elementor_pin(): string
    {
        $script = (string) file_get_contents($this->repository() . '/bin/install-test-plugins.sh');
        $this->assertSame(1, preg_match('/ELEMENTOR_VERSION:-([0-9.]+)\}/', $script, $m));

        return $m[1];
    }

    /** Publishes $version as the latest release of $slug on the stand-in info API. */
    private function release(string $slug, string $version): void
    {
        file_put_contents($this->tmp . "/info/$slug.json", json_encode(['slug' => $slug, 'version' => $version]));
    }

    /** @return array{0: string, 1: string[]} the install directory name and the slug=version lines. */
    private function resolve(bool $online = true): array
    {
        [$status, $out, $err] = $this->run_script('bin/test-local.sh', ['--resolve-plugins'], [
            'WPMCP_PLUGIN_INFO_URL' => 'file://' . $this->tmp . ($online ? '/info' : '/offline') . '/%s.json',
        ]);
        $this->assertSame(0, $status, "--resolve-plugins failed:\n$out$err");
        $lines = array_values(array_filter(explode("\n", trim($out))));
        $this->assertStringStartsWith('install: ', $lines[0], $out);

        return [substr($lines[0], strlen('install: ')), array_slice($lines, 1)];
    }

    public function test_the_installer_lists_its_plugin_set_without_installing(): void
    {
        $core = $this->tmp . '/core';
        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', ['--list'], ['WP_CORE_DIR' => $core]);

        $this->assertSame(0, $status, $out);
        $lines = explode("\n", trim($out));
        $this->assertContains('elementor elementor.php ' . $this->elementor_pin(), $lines);
        $this->assertContains('woocommerce woocommerce.php latest', $lines);
        $this->assertDirectoryDoesNotExist($core, '--list must not install anything');

        [, $crm] = $this->run_script('bin/install-test-plugins.sh', ['--list'], ['WP_CORE_DIR' => $core, 'WPMCP_LIVE_CRM' => '1']);
        $this->assertSame(['fluent-crm fluent-crm.php latest', 'mailpoet mailpoet.php latest'], explode("\n", trim($crm)));
    }

    public function test_a_new_release_of_an_unpinned_plugin_keys_a_new_install(): void
    {
        foreach (['woocommerce', 'advanced-custom-fields', 'wordpress-seo', 'polylang'] as $slug) {
            $this->release($slug, '1.0.0');
        }

        [$first, $versions] = $this->resolve();
        $this->assertContains('elementor=' . $this->elementor_pin(), $versions, 'a pinned plugin keeps its pin');
        $this->assertContains('woocommerce=1.0.0', $versions);
        $this->assertSame($first, $this->resolve()[0], 'the same releases must reuse the same install');

        $this->release('woocommerce', '1.0.1');
        [$second, $versions] = $this->resolve();

        $this->assertContains('woocommerce=1.0.1', $versions);
        $this->assertNotSame($first, $second, 'a new release must get a fresh install without editing either script');
        $this->assertSame(
            preg_replace('/-plugins-[0-9a-f]+$/', '', $first),
            preg_replace('/-plugins-[0-9a-f]+$/', '', $second),
            'only the plugin key moves'
        );
    }

    public function test_offline_the_newest_cached_install_is_used(): void
    {
        foreach (['woocommerce', 'advanced-custom-fields', 'wordpress-seo', 'polylang'] as $slug) {
            $this->release($slug, '1.0.0');
        }
        [$online] = $this->resolve();
        $base = preg_replace('/-plugins-[0-9a-f]+$/', '', $online);

        $older = $this->tmp . "/cache/$base-plugins-00000001";
        $newer = $this->tmp . "/cache/$base-plugins-00000002";
        $unready = $this->tmp . "/cache/$base-plugins-00000003";
        foreach ([$older, $newer, $unready] as $dir) {
            mkdir($dir, 0777, true);
        }
        touch($older . '/.ready', time() - 3600);
        touch($newer . '/.ready', time() - 60);

        [$status, $out, $err] = $this->run_script('bin/test-local.sh', ['--resolve-plugins'], [
            'WPMCP_PLUGIN_INFO_URL' => 'file://' . $this->tmp . '/offline/%s.json',
        ]);

        $this->assertSame(0, $status, $out . $err);
        $this->assertStringContainsString("install: $base-plugins-00000002", $out, 'offline runs use the newest ready install');
        $this->assertStringContainsString('WordPress.org did not answer', $err);
    }

    public function test_offline_without_a_cached_install_fails_plainly(): void
    {
        [$status, $out, $err] = $this->run_script('bin/test-local.sh', ['--resolve-plugins'], [
            'WPMCP_PLUGIN_INFO_URL' => 'file://' . $this->tmp . '/offline/%s.json',
        ]);

        $this->assertNotSame(0, $status, $out);
        $this->assertStringContainsString('no wp-', $err);
        $this->assertStringContainsString('install is cached', $err);
    }

    /**
     * The installer with WPMCP_PLUGIN_VERSIONS: a plugin present at another
     * version is replaced by that exact release, one already at it is kept,
     * and a release with no versioned zip falls back to latest-stable.
     */
    public function test_the_installer_reinstalls_a_plugin_at_another_version(): void
    {
        $bin = $this->tmp . '/bin';
        mkdir($bin);
        $log = $this->tmp . '/downloads.log';
        // A curl stand-in: logs the URL and serves a TablePress zip whose
        // Version header is the version in the URL; 404s on "missing".
        $stub = <<<'SH'
#!/usr/bin/env bash
out="" url=""
while [ $# -gt 0 ]; do
	case "$1" in
		-o) shift; out=$1 ;;
		http*) url=$1 ;;
	esac
	shift
done
echo "$url" >>"$STUB_LOG"
version=${url##*/tablepress.}
version=${version%.zip}
[ "$version" != missing ] || exit 22
[ "$version" != latest-stable ] || version=9.9
work=$(mktemp -d)
mkdir "$work/tablepress"
printf '<?php\n/*\n * Plugin Name: TablePress\n * Version: %s\n */\n' "$version" >"$work/tablepress/tablepress.php"
(cd "$work" && zip -qr "$out" tablepress)
rm -rf "$work"
SH;
        file_put_contents($bin . '/curl', $stub);
        chmod($bin . '/curl', 0755);

        $core   = $this->tmp . '/core';
        $plugin = $core . '/wp-content/plugins/tablepress/tablepress.php';
        mkdir(dirname($plugin), 0777, true);
        file_put_contents($plugin, "<?php\n/*\n * Version: 1.0\n */\n");

        $env = [
            'PATH'                  => $bin . ':' . (string) getenv('PATH'),
            'WP_CORE_DIR'           => $core,
            'WPMCP_LIVE_TABLEPRESS' => '1',
            'STUB_LOG'              => $log,
        ];

        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', [], $env + ['WPMCP_PLUGIN_VERSIONS' => 'tablepress=2.0']);
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('Version: 2.0', (string) file_get_contents($plugin));
        $this->assertSame(['https://downloads.wordpress.org/plugin/tablepress.2.0.zip'], file($log, FILE_IGNORE_NEW_LINES));

        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', [], $env + ['WPMCP_PLUGIN_VERSIONS' => 'tablepress=2.0']);
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('already installed, skipping', $out);
        $this->assertCount(1, file($log, FILE_IGNORE_NEW_LINES), 'a plugin already at the wanted version is not downloaded again');

        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', [], $env + ['WPMCP_PLUGIN_VERSIONS' => 'tablepress=missing']);
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('Version: 9.9', (string) file_get_contents($plugin), 'no versioned zip falls back to latest-stable');
    }

    public function test_the_gate_summary_names_the_plugin_versions_each_leg_ran_against(): void
    {
        $script = (string) file_get_contents($this->repository() . '/bin/test-local.sh');

        $this->assertTrue(str_contains($script, 'plugin_note "$dir"'), 'every install a leg uses adds its plugin versions to the leg note');
        $this->assertTrue(str_contains($script, 'SUMMARY+=("pass  $(leg_label "$leg")${note:+ ($note)}")'), 'the summary line carries the leg note');
    }
}
