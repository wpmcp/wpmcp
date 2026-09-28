<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Runner;
use WPMCP\Compliance\Severity;

/**
 * Both WordPress.org submissions, pinned to zero blockers under the strict
 * wporg-free profile with every rule pack running (issue #257).
 *
 * The directory cut and the WooCommerce vertical run the same strip
 * (scripts/flavors/wporg/strip.php over the shared policy.php), each with its
 * own flavor manifest. The release builds gate the real zips, but only when a
 * release is built; this runs the real strip over a staging of this checkout
 * on every test run, then the whole engine over the result, so an upstream
 * change that brings back a paid predicate, a licensing call or a restricted
 * tag in either flavor fails here first.
 *
 * The stage mirrors what the build scripts produce, running the same strip
 * and, for the vertical, the same text-domain rewrite
 * (scripts/flavors/woocommerce/text-domain.php). Composer is the one step not
 * reproduced: there is no vendor/, so nothing third-party is scanned here,
 * which the build's own engine run over the real zip covers. composer.json is
 * staged with the licensing SDK removed, as `composer remove` leaves it in
 * both builds.
 */
class FlavorBuildBlockersTest extends Compliance_Test_Case
{
    private const WOO_MANIFEST = 'scripts/flavors/woocommerce/manifest.php';

    /** @var array<string,string> flavor => stripped stage, built once per class */
    private static array $stages = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$stages as $stage) {
            self::remove_stage($stage);
        }
        self::$stages = [];
        parent::tearDownAfterClass();
    }

    /** @return array<string,array{0:string}> */
    public function flavors(): array
    {
        return [
            'directory cut'         => ['wporg'],
            'WooCommerce vertical'  => ['woocommerce'],
        ];
    }

    /**
     * @dataProvider flavors
     */
    public function test_the_stripped_flavor_has_no_blockers_under_any_pack(string $flavor): void
    {
        $report = Runner::with_default_rules()->run(
            Rule_Context::for_path(self::stripped_stage($flavor), Profile::wporg_free())
        );

        $blockers = array_values(array_filter(
            $report->findings(),
            static fn (Finding $finding) => Severity::BLOCKER === $finding->severity()
        ));

        $this->assertSame(
            [],
            array_map(static fn (Finding $finding) => $finding->rule_id() . ' ' . $finding->location(), $blockers),
            "the $flavor build has wp.org blockers:\n" . implode("\n", array_map(
                static fn (Finding $finding) => $finding->rule_id() . ' ' . $finding->location() . '  ' . $finding->message(),
                $blockers
            ))
        );
        // Guard against a vacuous pass: every pack ran, not a subset.
        $this->assertGreaterThanOrEqual(20, $report->rule_count());
    }

    public function test_the_woocommerce_build_drops_the_paid_tier_and_keeps_the_store_abilities(): void
    {
        $stage  = self::stripped_stage('woocommerce');
        $policy = require self::repo_root() . '/scripts/flavors/wporg/policy.php';
        $woo    = require self::repo_root() . '/' . self::WOO_MANIFEST;

        foreach (array_merge($policy['removed_paths'], $woo['removed_paths']) as $relative) {
            $this->assertFileDoesNotExist($stage . '/' . $relative, "$relative survived the WooCommerce strip");
        }

        // The abilities this flavor exists for.
        $this->assertDirectoryExists($stage . '/src/Tools/WooCommerce');
        $plugin = (string) file_get_contents($stage . '/src/Plugin.php');
        $this->assertMatchesRegularExpression(
            "/'woocommerce'\\s*=>\\s*fn \\(\\) => \\\$this->register_woocommerce_abilities\\(/",
            $plugin
        );
        $this->assertStringContainsString('function register_woocommerce_abilities(', $plugin);

        // Both are directory submissions served by language packs, so the
        // self-hosted loader leaves both, through the shared strip.
        foreach (['woocommerce', 'wporg'] as $flavor) {
            $this->assertStringNotContainsString(
                'load_textdomain',
                (string) file_get_contents(self::stripped_stage($flavor) . '/src/Plugin.php'),
                "the $flavor build still ships the self-hosted translation loader"
            );
        }

        // build-page's builder dialect goes with the shared strip, so the
        // three Elementor imports WporgFreeSurfaceTest::WOO_STRIP_REWRITTEN
        // exempts are really gone from the vertical.
        $this->assertStringNotContainsString(
            'Tools\\Elementor',
            (string) file_get_contents($stage . '/src/Tools/Compose/Build_Page.php')
        );
    }

    /**
     * The manifest may only add to the shared policy. An entry the policy
     * already removes, or one that no longer exists, would otherwise hide a
     * drift between the two lists; the strip fails on both.
     */
    public function test_a_woocommerce_manifest_entry_the_shared_policy_covers_fails_the_strip(): void
    {
        $policy   = require self::repo_root() . '/scripts/flavors/wporg/policy.php';
        $woo      = require self::repo_root() . '/' . self::WOO_MANIFEST;
        $manifest = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-woo-manifest-' . uniqid('', true) . '.php';
        $woo['removed_paths'][] = $policy['removed_paths'][0];
        file_put_contents($manifest, '<?php return ' . var_export($woo, true) . ';');

        $stage = self::staged_tree('woocommerce');
        try {
            [$code, $output] = self::run_strip($stage, $manifest);
        } finally {
            unlink($manifest);
            self::remove_stage($stage);
        }

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('already removed by the shared policy', $output);
    }

    /**
     * The gate itself: the WooCommerce build runs the shared strip with its
     * own manifest, and runs the engine over the extracted zip with no pack
     * or rule restriction, exactly as the directory build does.
     */
    public function test_the_woocommerce_build_script_runs_the_shared_strip_and_the_unscoped_engine(): void
    {
        $script = (string) file_get_contents(self::repo_root() . '/scripts/build-woo-release.sh');

        $this->assertStringContainsString(
            'php "$ROOT/scripts/flavors/wporg/strip.php" "$STAGE" "$ROOT/' . self::WOO_MANIFEST . '"',
            $script
        );
        $this->assertStringContainsString(
            'php "$ROOT/scripts/flavors/woocommerce/text-domain.php" "$STAGE" "$SLUG"',
            $script
        );
        $this->assertSame(1, preg_match('/tools\/compliance\/bin\/compliance\.php"(.*?)\|\|/s', $script, $call), 'no engine run in the WooCommerce build');
        $this->assertStringContainsString('--profile=wporg-free', $call[1]);
        $this->assertStringContainsString('--artifact', $call[1]);
        $this->assertStringNotContainsString('--pack', $call[1]);
        $this->assertStringNotContainsString('--rule', $call[1]);
        $this->assertStringNotContainsString('--fail-on', $call[1]);
        // composer.json ships beside vendor/ (Plugin Check File_Type_Check).
        $this->assertDoesNotMatchRegularExpression('/rm -f[^\n]*\$STAGE\/composer\.json/', $script);
    }

    // ------------------------------------------------------------ staging

    private static function stripped_stage(string $flavor): string
    {
        if (isset(self::$stages[$flavor])) {
            return self::$stages[$flavor];
        }

        $stage = self::staged_tree($flavor);
        [$code, $output] = self::run_strip(
            $stage,
            'woocommerce' === $flavor ? self::repo_root() . '/' . self::WOO_MANIFEST : null
        );
        if (0 !== $code) {
            self::remove_stage($stage);
            self::fail("the $flavor strip failed:\n" . $output);
        }
        if ('woocommerce' === $flavor) {
            $output = [];
            exec(
                escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(self::repo_root() . '/scripts/flavors/woocommerce/text-domain.php') . ' '
                    . escapeshellarg($stage) . ' wpmcp-for-woocommerce 2>&1',
                $output,
                $code
            );
            if (0 !== $code) {
                self::remove_stage($stage);
                self::fail("the WooCommerce text-domain rewrite failed:\n" . implode("\n", $output));
            }
        }

        return self::$stages[$flavor] = $stage;
    }

    /**
     * The file set the flavor's build script stages before the strip runs.
     * The caller owns the returned directory.
     */
    private static function staged_tree(string $flavor): string
    {
        $root  = self::repo_root();
        $slug  = 'woocommerce' === $flavor ? 'wpmcp-for-woocommerce' : 'wpmcp';
        $stage = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-flavor-' . $flavor . '-' . uniqid('', true) . '/' . $slug;
        if (! mkdir($stage, 0777, true)) {
            self::fail('could not create the staging directory');
        }

        copy($root . '/LICENSE', $stage . '/LICENSE');
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        unset($composer['require']['freemius/wordpress-sdk']);
        file_put_contents($stage . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::copy_tree($root . '/src', $stage . '/src');
        // Only the translation files, as the build scripts' find(1) selects them.
        mkdir($stage . '/languages');
        foreach (glob($root . '/languages/*') ?: [] as $file) {
            if (preg_match('/\.(po|mo|l10n\.php)$/', $file)) {
                copy($file, $stage . '/languages/' . basename($file));
            }
        }

        foreach ([$slug . '.php', 'readme.txt'] as $rendered) {
            $body = str_replace(
                '{{VERSION}}',
                '0.0.0',
                (string) file_get_contents($root . '/scripts/flavors/' . $flavor . '/' . $rendered)
            );
            file_put_contents($stage . '/' . $rendered, $body);
        }

        return $stage;
    }

    /** @return array{0:int,1:string} exit code, combined output */
    private static function run_strip(string $stage, ?string $manifest): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::repo_root() . '/scripts/flavors/wporg/strip.php') . ' '
            . escapeshellarg($stage)
            . (null === $manifest ? '' : ' ' . escapeshellarg($manifest))
            . ' 2>&1';
        $output = [];
        $code   = 1;
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
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
            // Dotfiles (.DS_Store and friends) never reach either zip: the
            // wporg build deletes them and both zip steps exclude .DS_Store,
            // so staging them would fail File_Hygiene_Rule on a developer
            // machine for a file no build ships.
            if ('.' === $entry[0]) {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source)) {
                self::copy_tree($source, $to . '/' . $entry);
                continue;
            }
            if (! copy($source, $to . '/' . $entry)) {
                self::fail("could not stage $source");
            }
        }
    }

    /** Removes the per-stage parent directory staged_tree() created. */
    private static function remove_stage(string $stage): void
    {
        self::remove_dir(dirname($stage));
    }

    private static function remove_dir(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::remove_dir($path . '/' . $entry);
        }
        rmdir($path);
    }
}
