<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Security\Hardening_Audit;

/**
 * Issue #605. scan-security read WP_DEBUG_DISPLAY on its own, and core
 * defines it as true by default, so a site with WP_DEBUG off was told debug
 * output was on (a warning, and a lower score, on production) while Site
 * Health said it was off. Core only shows errors when WP_DEBUG is on too.
 *
 * Constants cannot be redefined in the test process (the harness runs with
 * WP_DEBUG on), so each case runs the live constant read in a fresh PHP
 * process with exactly the constants a wp-config.php would give it.
 */
class HardeningDebugDisplayTest extends \WP_UnitTestCase
{
    /** @var string[] */
    private array $scripts = [];

    public function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
        parent::tearDown();
    }

    /**
     * Run the live read with the given constants. null leaves a constant
     * undefined; WP_DEBUG_DISPLAY then gets core's default, exactly as
     * wp_initial_constants() gives it (true), unless $core_default is false.
     *
     * @return array<string, mixed> the harden_debug_display finding
     */
    private function live_finding(?bool $wp_debug, ?bool $wp_debug_display, string $environment, bool $core_default = true): array
    {
        $src      = dirname(__DIR__, 3) . '/src/';
        $lines    = [
            '<?php',
            "define('ABSPATH', " . var_export(ABSPATH, true) . ');',
        ];
        if (null !== $wp_debug) {
            $lines[] = "define('WP_DEBUG', " . var_export($wp_debug, true) . ');';
        }
        if (null !== $wp_debug_display) {
            $lines[] = "define('WP_DEBUG_DISPLAY', " . var_export($wp_debug_display, true) . ');';
        }
        if ($core_default) {
            // What wp_initial_constants() does for an undefined WP_DEBUG_DISPLAY.
            $lines[] = "if (! defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', true); }";
        }
        $lines[] = 'function wp_get_environment_type() { return ' . var_export($environment, true) . '; }';
        // Only the plugin's own classes, as the plugin's PSR-4 map loads them.
        $lines[] = 'spl_autoload_register(function ($class) { $rel = substr($class, strlen("WPMCP\\\\")); '
            . 'require ' . var_export($src, true) . ' . str_replace("\\\\", "/", $rel) . ".php"; });';
        $lines[] = 'echo json_encode((new \WPMCP\Tools\Security\Hardening_Audit())->debug_display_finding());';

        $base   = wp_tempnam('wpmcp-605');
        $script = $base . '.php';
        file_put_contents($script, implode("\n", $lines) . "\n");
        $this->scripts[] = $base;
        $this->scripts[] = $script;

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $finding = json_decode((string) $out, true);
        $this->assertIsArray($finding, 'The live read did not run: ' . $out);

        return $finding;
    }

    /** @return array<string, array{0: string}> */
    public static function environments(): array
    {
        return [
            'production'  => ['production'],
            'staging'     => ['staging'],
            'development' => ['development'],
            'local'       => ['local'],
        ];
    }

    /** @dataProvider environments */
    public function test_wp_debug_off_with_core_default_display_passes(string $environment): void
    {
        $finding = $this->live_finding(false, null, $environment);

        $this->assertSame('harden_debug_display', $finding['id']);
        $this->assertSame('pass', $finding['status'], wp_json_encode($finding));
        $this->assertFalse($finding['value']);
    }

    /** @dataProvider environments */
    public function test_wp_debug_undefined_passes(string $environment): void
    {
        $this->assertSame('pass', $this->live_finding(null, null, $environment)['status']);
    }

    /** @dataProvider environments */
    public function test_wp_debug_off_with_display_explicitly_true_passes(string $environment): void
    {
        $this->assertSame('pass', $this->live_finding(false, true, $environment)['status']);
    }

    public function test_wp_debug_on_with_display_on_in_production_still_warns(): void
    {
        $finding = $this->live_finding(true, true, 'production');

        $this->assertSame('warning', $finding['status']);
        $this->assertTrue($finding['value']);
    }

    public function test_wp_debug_on_with_display_at_core_default_in_production_warns(): void
    {
        $this->assertSame('warning', $this->live_finding(true, null, 'production')['status']);
    }

    public function test_wp_debug_on_with_display_off_passes(): void
    {
        $this->assertSame('pass', $this->live_finding(true, false, 'production')['status']);
    }

    public function test_wp_debug_on_with_display_on_off_production_is_info(): void
    {
        $this->assertSame('info', $this->live_finding(true, true, 'local')['status']);
    }

    /** run() reports the same finding as the live read, in this process too. */
    public function test_run_uses_the_live_read(): void
    {
        $block = static fn () => new \WP_Error('blocked', 'no loopback in tests');
        add_filter('pre_http_request', $block);
        $findings = (new Hardening_Audit())->run()['findings'];
        remove_filter('pre_http_request', $block);

        $by_id = array_column($findings, null, 'id');
        $this->assertSame((new Hardening_Audit())->debug_display_finding(), $by_id['harden_debug_display']);
        $expected = (defined('WP_DEBUG') && WP_DEBUG) && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
        $this->assertSame($expected, $by_id['harden_debug_display']['value']);
    }
}
