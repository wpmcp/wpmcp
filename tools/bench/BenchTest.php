<?php

namespace WPMCP\Bench;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Surface.php';
require_once __DIR__ . '/wire.php';

/**
 * The in-process mode of the code mode benchmark (issue #1130).
 *
 * Not part of the test gate: it lives outside tests/, so no suite picks it
 * up. bin/bench-code-mode.sh --mode=inprocess runs it through
 * bin/test-local.sh as a targeted run, which gives it the cached WordPress
 * install (WooCommerce, Elementor, Yoast SEO from the test plugins), a
 * database of its own and the harness bootstrap, and drops the database
 * when it exits.
 *
 * Each (scenario, surface) pair is one test, so the harness's transaction
 * rollback gives every replay fresh fixtures. A call goes through
 * WP_Ability::execute(), the path call-tool uses: input validation, the
 * permission chain, the rate limiter and Safe_Mutation all run. The result
 * bytes are the JSON of what execute() returned, one copy, which is what a
 * client hands the model as the text block; the MCP wire also repeats it as
 * structuredContent (HTTP mode measures that).
 *
 * The results go to WPMCP_BENCH_OUT as JSON when the class is done.
 *
 * @group bench
 */
class BenchTest extends \WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private static array $results = [];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
        self::register_as_pro_site();
        self::$results = [
            'tools_list' => [],
            'scenarios'  => [],
        ];
    }

    /**
     * The harness boots the plugin unlicensed, so its live registry holds the
     * free tools only. The scenarios measure a licensed site, so the wpmcp
     * abilities are registered again with the Pro gate open, the way
     * Plugin::register_abilities() runs on such a site: integrations register
     * for the plugins that are really active (WooCommerce, Elementor, Yoast
     * SEO, ACF, Polylang from the test plugins), not forced on as the
     * manifest test does. The bench process exits afterwards, so nothing is
     * put back.
     */
    private static function register_as_pro_site(): void
    {
        Gate::set_pro_for_tests(true);
        foreach (array_keys(wp_get_abilities()) as $name) {
            if (str_starts_with((string) $name, 'wpmcp/')) {
                wp_unregister_ability((string) $name);
            }
        }

        $plugin = Plugin::instance();
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue($plugin, new Registrar());
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', [$plugin, 'register_abilities']);
        do_action('wp_abilities_api_init');
    }

    public static function wpTearDownAfterClass(): void
    {
        $out = getenv('WPMCP_BENCH_OUT');
        if (! $out) {
            return;
        }

        $plugins = [];
        foreach (['WooCommerce' => 'WC_VERSION', 'Elementor' => 'ELEMENTOR_VERSION', 'Yoast SEO' => 'WPSEO_VERSION'] as $name => $const) {
            if (defined($const)) {
                $plugins[ $name ] = constant($const);
            }
        }
        ksort(self::$results['scenarios']);

        $doc = [
            'issue'        => 1130,
            'mode'         => 'inprocess',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'commit'       => (string) getenv('WPMCP_BENCH_COMMIT'),
            'wordpress'    => get_bloginfo('version'),
            'php'          => PHP_VERSION,
            'wpmcp'        => defined('WPMCP_VERSION') ? WPMCP_VERSION : '',
            'site'         => 'single site, Pro licence open, wpmcp integrations registered for the active plugins',
            'plugins'      => $plugins,
            'tokens'       => Wire::token_factor(__DIR__),
            'methods'      => [
                'tools_list_bytes' => 'the JSON-RPC tools/list response, sent once per session',
                'calls'            => 'tool calls the scripted sequence makes',
                'turns'            => 'model round trips: a step whose calls are independent (a fan-out) is one turn',
                'result_bytes'     => 'JSON of each WP_Ability::execute() result, summed: what goes back to the model as text (the MCP wire repeats it as structuredContent)',
                'ms'               => 'wall time of the replay in-process, without the tools/list',
            ],
            'tools_list'   => self::$results['tools_list'],
            'scenarios'    => array_values(self::$results['scenarios']),
        ];

        file_put_contents($out, wp_json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        Snapshot_Store::install();
        add_filter('wpmcp_rate_limit', static fn() => 1000000);
        if (class_exists('WC_Install')) {
            \WC_Install::create_roles();
            $GLOBALS['wp_roles'] = null;
            wp_roles();
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_all_filters('wpmcp_rate_limit');
        delete_option(Tool_Exposure::OPTION);
        Governance::reset_for_tests();
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        $only  = (string) getenv('WPMCP_BENCH_SCENARIO');
        $cases = [];
        foreach (self::scenario_files() as $file) {
            $id = (int) basename($file);
            if ('' !== $only && (int) $only !== $id) {
                continue;
            }
            foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
                $cases[ basename($file, '.php') . ' ' . $surface ] = [$file, $surface];
            }
        }
        return $cases;
    }

    /** @return string[] */
    private static function scenario_files(): array
    {
        $files = glob(__DIR__ . '/scenarios/*.php') ?: [];
        sort($files, SORT_NATURAL);
        return $files;
    }

    public function test_tools_list_on_each_surface(): void
    {
        $this->assertSame(Tool_Exposure::COMPACT_CORE, Surface::COMPACT_CORE, 'Surface::COMPACT_CORE drifted from Tool_Exposure::COMPACT_CORE');

        $dump = (string) getenv('WPMCP_BENCH_SAVE_TOOLS_LIST');
        foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
            $wire = Wire::tools_list($surface);
            if ('' !== $dump && is_dir($dump)) {
                file_put_contents($dump . '/tools-list-' . $surface . '.json', $wire['payload']);
            }
            unset($wire['payload']);
            self::$results['tools_list'][ $surface ] = $wire;
            $this->assertGreaterThan(0, $wire['tools']);
        }
        $this->assertLessThan(self::$results['tools_list']['full']['bytes'], self::$results['tools_list']['compact']['bytes']);
    }

    /**
     * @dataProvider cases
     */
    public function test_scenario(string $file, string $surface): void
    {
        $scenario = require $file;
        $key      = sprintf('%02d', (int) $scenario['id']);
        self::$results['scenarios'][ $key ] ??= [
            'id'       => (int) $scenario['id'],
            'slug'     => (string) $scenario['slug'],
            'title'    => (string) $scenario['title'],
            'surfaces' => [],
        ];

        $missing = [];
        foreach ((array) $scenario['requires'] as $name => $check) {
            if (! $check()) {
                $missing[] = $name;
            }
        }
        if ([] !== $missing) {
            self::$results['scenarios'][ $key ]['surfaces'][ $surface ] = [
                'status' => 'skipped',
                'reason' => 'needs ' . implode(', ', $missing),
            ];
            $this->markTestSkipped('needs ' . implode(', ', $missing));
        }

        $fixtures = ($scenario['seed'])();
        $steps    = ($scenario['surfaces'])($fixtures)[ $surface ];
        $out      = (new Runner([self::class, 'execute']))->run($steps);

        if ('ok' === $out['status'] && isset($scenario['verify'])) {
            $problem = ($scenario['verify'])($fixtures, $out['state']);
            if (null !== $problem) {
                $out['status'] = 'failed';
                $out['reason'] = 'verify: ' . $problem;
            }
        }
        unset($out['state']);
        self::$results['scenarios'][ $key ]['surfaces'][ $surface ] = $out;

        $this->assertSame('ok', $out['status'], (string) $out['reason']);
    }

    /**
     * The in-process transport: the ability's own execute(), with its result
     * measured as the JSON the model would read.
     *
     * @return array{value: mixed, bytes: int, error: ?string}
     */
    public static function execute(string $tool, array $args): array
    {
        $ability = wp_has_ability($tool) ? wp_get_ability($tool) : null;
        if (null === $ability) {
            return ['value' => null, 'bytes' => 0, 'error' => $tool . ' is not registered'];
        }

        try {
            $value = $ability->execute($args);
        } catch (\Throwable $e) {
            $text = $e->getMessage();
            return ['value' => null, 'bytes' => strlen($text), 'error' => $text];
        }

        if (is_wp_error($value)) {
            $body = ['code' => $value->get_error_code(), 'message' => $value->get_error_message()];
            return ['value' => null, 'bytes' => strlen((string) wp_json_encode($body)), 'error' => $value->get_error_code() . ': ' . $value->get_error_message()];
        }

        $bytes = strlen((string) wp_json_encode($value));
        $error = null;
        if (is_array($value) && ! empty($value['error'])) {
            $error = is_array($value['error'])
                ? (string) ($value['error']['code'] ?? '') . ': ' . (string) ($value['error']['message'] ?? wp_json_encode($value['error']))
                : (string) $value['error'];
        } elseif (is_array($value) && isset($value['status']) && is_int($value['status']) && $value['status'] >= 400) {
            $error = 'HTTP ' . $value['status'] . ': ' . substr((string) wp_json_encode($value['body'] ?? $value), 0, 300);
        }

        return ['value' => $value, 'bytes' => $bytes, 'error' => $error];
    }
}
