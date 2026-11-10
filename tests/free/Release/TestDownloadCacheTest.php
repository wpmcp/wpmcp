<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * Follow-up to the test cache budget: rebuilding an install must not download
 * anything again. bin/install-wp-tests.sh and bin/install-test-plugins.sh
 * read WordPress core, the WordPress test library and every plugin zip from
 * a shared download cache (WPMCP_DOWNLOAD_CACHE, which bin/test-local.sh sets
 * to $WPMCP_TEST_CACHE/downloads), keyed by exact version, fetch on a miss
 * only, under a lock, through a .tmp file renamed into place, and check each
 * archive before use. bin/test-local.sh asks WordPress.org for a plugin's
 * latest release at most once per WPMCP_TEST_LATEST_TTL_HOURS, and prunes the
 * downloads by their own budget.
 *
 * curl, wget and svn are stand-ins on PATH that log every call, build small
 * fake archives, and fail when STUB_OFFLINE is set.
 */
class TestDownloadCacheTest extends TestCase
{
    private string $tmp;
    private string $downloads;

    /** The default plugin set, at the versions a run would pass. */
    private const PINS = 'elementor=4.3.4 woocommerce=9.1.0 advanced-custom-fields=6.3.0 wordpress-seo=22.1 polylang=3.6';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp       = sys_get_temp_dir() . '/wpmcp-dl-' . bin2hex(random_bytes(4));
        $this->downloads = $this->tmp . '/c/downloads';
        mkdir($this->tmp . '/bin', 0777, true);
        mkdir($this->tmp . '/c', 0777, true);
        mkdir($this->tmp . '/info');

        file_put_contents($this->tmp . '/bin/curl', <<<'SH'
#!/usr/bin/env bash
out="" url=""
while [ $# -gt 0 ]; do
	case "$1" in
		-o) shift; out=$1 ;;
		http*|file*) url=$1 ;;
	esac
	shift
done
echo "curl $url" >>"$STUB_LOG"
[ -z "${STUB_OFFLINE:-}" ] || exit 7
[ -z "${STUB_DELAY:-}" ] || sleep "$STUB_DELAY"
work=$(mktemp -d)
case "$url" in
	file://*) cat "${url#file://}" >"$work/a" 2>/dev/null || exit 37 ;;
	*wordpress.org/wordpress-*.tar.gz)
		mkdir -p "$work/wordpress/wp-content/plugins" "$work/wordpress/wp-content/themes"
		echo '<?php // core' >"$work/wordpress/wp-load.php"
		tar -czf "$work/a" -C "$work" wordpress ;;
	*/plugin/*.zip)
		f=${url##*/plugin/}; f=${f%.zip}; slug=${f%%.*}; version=${f#*.}
		case "$slug" in
			advanced-custom-fields) main=acf.php ;;
			wordpress-seo) main=wp-seo.php ;;
			*) main=$slug.php ;;
		esac
		if [ "$slug" = "${STUB_CORRUPT:-}" ]; then
			echo garbage >"$work/a"
		else
			mkdir "$work/$slug"
			printf '<?php\n/*\n * Plugin Name: %s\n * Version: %s\n */\n' "$slug" "$version" >"$work/$slug/$main"
			(cd "$work" && zip -qr a.zip "$slug" && mv a.zip a)
		fi ;;
	*wp-tests-config-sample.php)
		printf "<?php\ndefine( 'DB_NAME', 'youremptytestdbnamehere' );\ndefine( 'DB_USER', 'yourusernamehere' );\n" >"$work/a" ;;
	*/db.php) echo '<?php // db dropin' >"$work/a" ;;
	*) rm -rf "$work"; exit 22 ;;
esac
if [ -n "$out" ]; then cp "$work/a" "$out"; else cat "$work/a"; fi
rm -rf "$work"
SH);
        file_put_contents($this->tmp . '/bin/svn', <<<'SH'
#!/usr/bin/env bash
echo "svn $*" >>"$STUB_LOG"
[ -z "${STUB_OFFLINE:-}" ] || exit 1
dest=${!#}
mkdir -p "$dest"
echo '<?php // tests lib' >"$dest/functions.php"
SH);
        file_put_contents($this->tmp . '/bin/wget', "#!/usr/bin/env bash\necho \"wget \$*\" >>\"\$STUB_LOG\"\nexit 1\n");
        foreach (['curl', 'svn', 'wget'] as $tool) {
            chmod($this->tmp . "/bin/$tool", 0755);
        }
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

    /** @param array<string, string> $env */
    private function environment(array $env): array
    {
        return $env + [
            'PATH'                  => $this->tmp . '/bin:' . (string) getenv('PATH'),
            'HOME'                  => (string) getenv('HOME'),
            'TMPDIR'                => $this->tmp,
            'STUB_LOG'              => $this->tmp . '/calls.log',
            'WPMCP_TEST_PHP'        => PHP_BINARY,
            'WPMCP_TEST_CACHE'      => $this->tmp . '/c',
            'WPMCP_DOWNLOAD_CACHE'  => $this->downloads,
            'WPMCP_PLUGIN_VERSIONS' => self::PINS,
        ];
    }

    /**
     * @param string[]              $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string}
     */
    private function run_script(string $script, array $args, array $env = []): array
    {
        $command = array_merge(['bash', $this->repository() . '/' . $script], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp, $this->environment($env));
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }

    /** Builds a full install (core, test library, plugins) into $dir the way bin/test-local.sh does. */
    private function build(string $dir, array $env = []): void
    {
        $env += ['WP_CORE_DIR' => "$dir/wordpress/", 'WP_TESTS_DIR' => "$dir/wordpress-tests-lib"];
        [$status, $out] = $this->run_script('bin/install-wp-tests.sh', ['wpmcp_unused', 'u', 'p', '127.0.0.1:1', '7.1', 'true'], $env);
        $this->assertSame(0, $status, "install-wp-tests.sh failed:\n$out");
        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', [], $env);
        $this->assertSame(0, $status, "install-test-plugins.sh failed:\n$out");
    }

    /** @return string[] */
    private function calls(): array
    {
        $log = $this->tmp . '/calls.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    private function forget_calls(): void
    {
        @unlink($this->tmp . '/calls.log');
    }

    private function assert_install(string $dir): void
    {
        $this->assertFileExists("$dir/wordpress/wp-load.php");
        $this->assertFileExists("$dir/wordpress/wp-content/db.php");
        $this->assertFileExists("$dir/wordpress-tests-lib/includes/functions.php");
        $this->assertFileExists("$dir/wordpress-tests-lib/data/functions.php");
        $this->assertStringContainsString("'wpmcp_unused'", (string) file_get_contents("$dir/wordpress-tests-lib/wp-tests-config.php"));
        $this->assertStringContainsString('Version: 9.1.0', (string) file_get_contents("$dir/wordpress/wp-content/plugins/woocommerce/woocommerce.php"));
        $this->assertFileExists("$dir/wordpress/wp-content/plugins/advanced-custom-fields/acf.php");
    }

    public function test_a_second_install_of_the_same_version_makes_no_network_calls(): void
    {
        $this->build($this->tmp . '/one');
        $this->assert_install($this->tmp . '/one');
        $this->assertNotEmpty($this->calls(), 'the first install downloads');
        $this->assertFileExists($this->downloads . '/core/wordpress-7.1.tar.gz');
        $this->assertFileExists($this->downloads . '/plugins/woocommerce-9.1.0.zip');

        $this->forget_calls();
        $this->build($this->tmp . '/two', ['STUB_OFFLINE' => '1']);

        $this->assert_install($this->tmp . '/two');
        $this->assertSame([], $this->calls(), 'a second install of the same versions must not touch the network');
    }

    public function test_a_corrupt_cached_archive_is_fetched_again_once(): void
    {
        $this->build($this->tmp . '/one');
        file_put_contents($this->downloads . '/plugins/woocommerce-9.1.0.zip', 'truncated');
        $this->forget_calls();

        $this->build($this->tmp . '/two');

        $this->assert_install($this->tmp . '/two');
        $this->assertSame(['curl https://downloads.wordpress.org/plugin/woocommerce.9.1.0.zip'], $this->calls());
    }

    public function test_a_download_that_stays_corrupt_fails_and_leaves_nothing_behind(): void
    {
        $dir = $this->tmp . '/one';
        [$status, $out] = $this->run_script('bin/install-test-plugins.sh', [], ['WP_CORE_DIR' => "$dir/wordpress/", 'STUB_CORRUPT' => 'woocommerce']);

        $this->assertNotSame(0, $status, $out);
        $woo = array_values(array_filter($this->calls(), static fn (string $c): bool => str_contains($c, '/woocommerce.')));
        $this->assertCount(2, $woo, 'one download and one retry: ' . implode("\n", $woo));
        $this->assertSame([], glob($this->downloads . '/plugins/woocommerce*') ?: [], 'no half-written or corrupt file is kept');
    }

    public function test_parallel_installs_fetch_each_file_once(): void
    {
        $processes = [];
        foreach (['one', 'two'] as $name) {
            $env         = $this->environment(['WP_CORE_DIR' => $this->tmp . "/$name/wordpress/", 'STUB_DELAY' => '0.3']);
            $command     = ['bash', $this->repository() . '/bin/install-test-plugins.sh'];
            $processes[] = proc_open($command, [1 => ['file', $this->tmp . "/$name.log", 'w'], 2 => ['file', $this->tmp . "/$name.log", 'a']], $pipes, $this->tmp, $env);
        }
        foreach ($processes as $i => $process) {
            $this->assertSame(0, proc_close($process), (string) file_get_contents($this->tmp . '/' . (0 === $i ? 'one' : 'two') . '.log'));
        }

        $calls = $this->calls();
        $this->assertCount(5, $calls, implode("\n", $calls));
        $this->assertSame($calls, array_values(array_unique($calls)));
        $this->assertSame([], glob($this->downloads . '/plugins/*.tmp*') ?: []);
    }

    private function release(string $version): void
    {
        foreach (['woocommerce', 'advanced-custom-fields', 'wordpress-seo', 'polylang'] as $slug) {
            file_put_contents($this->tmp . "/info/$slug.json", json_encode(['slug' => $slug, 'version' => $slug === 'woocommerce' ? $version : '1.0.0']));
        }
    }

    /** @return string[] the slug=version lines of --resolve-plugins */
    private function resolve(array $env = []): array
    {
        [$status, $out] = $this->run_script('bin/test-local.sh', ['--resolve-plugins'], $env + [
            'WPMCP_PLUGIN_INFO_URL' => 'file://' . $this->tmp . '/info/%s.json',
            'WPMCP_PLUGIN_VERSIONS' => '',
        ]);
        $this->assertSame(0, $status, $out);

        return array_values(array_filter(explode("\n", $out), static fn (string $l): bool => str_contains($l, '=') && ! str_contains($l, ' ')));
    }

    public function test_the_latest_release_is_asked_for_at_most_once_per_ttl(): void
    {
        $this->release('1.0.0');
        $this->assertContains('woocommerce=1.0.0', $this->resolve());
        $this->forget_calls();

        $this->release('1.0.1');
        $this->assertContains('woocommerce=1.0.0', $this->resolve(), 'inside the default 24 hours the answer is reused');
        $this->assertSame([], $this->calls(), 'and WordPress.org is not asked');

        $this->assertContains('woocommerce=1.0.1', $this->resolve(['WPMCP_TEST_LATEST_TTL_HOURS' => '0']));
        $this->assertNotEmpty($this->calls());
    }

    /** A fake cached download used $age days ago, of $kb KB. */
    private function cached(string $name, float $age, int $kb = 4): string
    {
        $path = $this->downloads . '/' . $name;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, str_repeat('x', $kb * 1024));
        touch($path, (int) (time() - $age * 86400));

        return $path;
    }

    /** @return string[] */
    private function downloads_left(): array
    {
        $left = array_map(fn (string $p): string => substr($p, strlen($this->downloads) + 1), glob($this->downloads . '/{core,tests-lib,plugins,themes,misc}/*', GLOB_BRACE) ?: []);
        sort($left);

        return $left;
    }

    public function test_prune_drops_downloads_unused_for_the_ttl_of_thirty_days(): void
    {
        $this->cached('plugins/old-1.0.zip', 31);
        $this->cached('plugins/recent-1.0.zip', 29);

        [$status, $out] = $this->run_script('bin/test-local.sh', ['--prune']);

        $this->assertSame(0, $status, $out);
        $this->assertSame(['plugins/recent-1.0.zip'], $this->downloads_left(), $out);
    }

    public function test_prune_keeps_downloads_within_their_size_budget_oldest_used_first(): void
    {
        $this->cached('plugins/a-1.zip', 1, 1024);
        $this->cached('plugins/b-1.zip', 2, 1024);
        $this->cached('core/wordpress-7.1.tar.gz', 3, 1024);
        $this->cached('plugins/c-1.zip', 4, 1024);
        // In use: an installer holds its lock.
        mkdir($this->downloads . '/.locks/plugins__c-1.zip', 0777, true);
        file_put_contents($this->downloads . '/.locks/plugins__c-1.zip/pid', (string) getmypid());

        [$status, $out] = $this->run_script('bin/test-local.sh', ['--prune'], ['WPMCP_TEST_DOWNLOAD_MAX_GB' => (string) (2.5 / 1024)]);

        $this->assertSame(0, $status, $out);
        $this->assertSame(['plugins/a-1.zip', 'plugins/b-1.zip', 'plugins/c-1.zip'], $this->downloads_left(), $out);
    }

    public function test_the_header_documents_the_download_cache_variables(): void
    {
        [$status, $out] = $this->run_script('bin/test-local.sh', ['--help']);

        $this->assertSame(0, $status, $out);
        foreach (['WPMCP_TEST_DOWNLOAD_TTL_DAYS', 'WPMCP_TEST_DOWNLOAD_MAX_GB', 'WPMCP_TEST_LATEST_TTL_HOURS', 'downloads'] as $name) {
            $this->assertStringContainsString($name, $out);
        }
    }
}
