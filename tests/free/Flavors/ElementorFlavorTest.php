<?php

namespace WPMCP\Tests\Free\Flavors;

use WPMCP\Freemius\Bootstrap;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * Safe MCP for Elementor (owner decision, 21 Nov 2026, scope B): the
 * WooCommerce flavor's safety core with Elementor in place of the store
 * tools. Unlicensed, it reads Elementor pages and edits the elements already
 * on them; adding, removing, moving and the rest stay Pro and answer with the
 * upgrade link. A WP MCP Pro licence lifts the flavor in place, so the same
 * folder then serves exactly what the full plugin serves. The main plugin's
 * tiers do not change.
 */
class ElementorFlavorTest extends \WP_UnitTestCase
{
    /** Pro in the full plugin, free in this flavor. */
    private const FREE_IN_FLAVOR = [
        'wpmcp/get-elementor-data',
        'wpmcp/find-element',
        'wpmcp/export-page',
        'wpmcp/detect-elementor-version',
        'wpmcp/list-dynamic-tags',
        'wpmcp/update-element',
        'wpmcp/update-widget',
        'wpmcp/set-element-label',
        'wpmcp/update-container',
        'wpmcp/update-page-settings',
        'wpmcp/batch-update',
    ];

    /** Free everywhere already, and kept in this flavor. */
    private const ALREADY_FREE = [
        'wpmcp/list-widgets',
        'wpmcp/get-widget-schema',
        'wpmcp/get-global-settings',
        'wpmcp/regenerate-elementor-css',
    ];

    /** Elementor tools that stay Pro in this flavor. */
    private const STAYS_PRO = [
        'wpmcp/add-widget',
        'wpmcp/add-container',
        'wpmcp/remove-element',
        'wpmcp/move-element',
        'wpmcp/reorder-elements',
        'wpmcp/duplicate-element',
        'wpmcp/apply-template',
        'wpmcp/save-as-template',
        'wpmcp/create-theme-template',
        'wpmcp/create-popup',
        'wpmcp/update-global-colors',
        'wpmcp/add-custom-css',
        'wpmcp/apply-brand-kit',
    ];

    /** Free in the full plugin, Pro in this flavor (file access and raw SQL). */
    private const PRO_IN_FLAVOR = [
        'wpmcp/read-file',
        'wpmcp/list-directory',
        'wpmcp/search-files',
        'wpmcp/write-file',
        'wpmcp/edit-file',
        'wpmcp/delete-file',
        'wpmcp/query',
        'wpmcp/insert-row',
        'wpmcp/update-rows',
        'wpmcp/delete-rows',
    ];

    protected function tearDown(): void
    {
        Plugin::set_flavor_for_tests(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /**
     * Declared and registered ability names for a flavor and licence state.
     *
     * @return array{declared: array<string, string>, registered: string[]}
     */
    private function surface(?string $flavor, bool $pro): array
    {
        Plugin::set_flavor_for_tests($flavor);
        Gate::set_pro_for_tests($pro);
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $declared = [];
        foreach ($registrar->declared() as $ability) {
            $declared[ $ability->name ] = $ability->tier;
        }

        return [
            'declared'   => $declared,
            'registered' => array_map(fn ($a) => $a->name, $registrar->all()),
        ];
    }

    public function test_unlicensed_it_registers_the_scope_b_free_set(): void
    {
        $s = $this->surface('elementor', false);

        foreach (array_merge(self::FREE_IN_FLAVOR, self::ALREADY_FREE) as $name) {
            $this->assertSame('free', $s['declared'][ $name ] ?? null, "{$name} is not free in the flavor");
            $this->assertContains($name, $s['registered'], "{$name} is not registered unlicensed");
        }
    }

    public function test_unlicensed_the_layout_tools_stay_declared_pro_so_a_call_gets_the_upgrade_link(): void
    {
        $s = $this->surface('elementor', false);

        foreach (self::STAYS_PRO as $name) {
            $this->assertSame('pro', $s['declared'][ $name ] ?? null, "{$name} is not declared Pro in the flavor");
            $this->assertNotContains($name, $s['registered'], "{$name} registered without a licence");
        }
    }

    public function test_file_access_and_raw_sql_move_to_pro_but_the_schema_reads_stay_free(): void
    {
        $s = $this->surface('elementor', false);

        foreach (self::PRO_IN_FLAVOR as $name) {
            $this->assertSame('pro', $s['declared'][ $name ] ?? null, "{$name} is not Pro in the flavor");
            $this->assertNotContains($name, $s['registered']);
        }
        $this->assertContains('wpmcp/list-tables', $s['registered']);
        $this->assertContains('wpmcp/describe-table', $s['registered']);
    }

    public function test_unlicensed_it_leaves_out_the_groups_outside_its_surface(): void
    {
        $s       = $this->surface('elementor', false);
        $domains = [];
        Plugin::set_flavor_for_tests('elementor');
        Gate::set_pro_for_tests(false);
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);
        foreach ($registrar->declared() as $ability) {
            $domains[ $ability->domain ] = true;
        }

        foreach (['woocommerce', 'cli', 'code', 'migration', 'bridge'] as $domain) {
            $this->assertArrayNotHasKey($domain, $domains, "the {$domain} domain registers unlicensed");
        }
        $this->assertArrayHasKey('elementor', $domains);
        $this->assertContains('wpmcp/rollback-operation', $s['registered']);
        $this->assertContains('wpmcp/build-page', $s['registered']);
    }

    public function test_a_licence_turns_the_flavor_into_the_full_plugin_in_place(): void
    {
        $flavor = $this->surface('elementor', true);
        $full   = $this->surface(null, true);

        sort($flavor['registered']);
        sort($full['registered']);
        $this->assertSame($full['registered'], $flavor['registered']);
        foreach (array_merge(self::STAYS_PRO, self::PRO_IN_FLAVOR) as $name) {
            $this->assertContains($name, $flavor['registered'], "{$name} is still locked with a licence");
        }
    }

    public function test_the_main_plugin_tiers_are_unchanged(): void
    {
        $s = $this->surface(null, false);

        foreach (array_merge(self::FREE_IN_FLAVOR, self::STAYS_PRO) as $name) {
            $this->assertSame('pro', $s['declared'][ $name ] ?? null, "{$name} changed tier in the full plugin");
        }
        foreach (self::PRO_IN_FLAVOR as $name) {
            $this->assertSame('free', $s['declared'][ $name ] ?? null, "{$name} changed tier in the full plugin");
        }
    }

    /**
     * The licensing SDK must treat the flavor's folder as the whole product:
     * no "download the Pro version" step and no updater offering the full
     * plugin's package for this folder. The full plugin keeps its config.
     */
    public function test_the_licensing_config_upgrades_in_place_only_in_the_flavor(): void
    {
        $this->assertFalse(Bootstrap::upgrades_in_place());
        $this->assertTrue(Bootstrap::config()['has_premium_version']);

        // WPMCP_FLAVOR is a constant, so the flavor's side runs in a child
        // process that loads only Bootstrap.php, as the flavor's main file
        // defines it before the SDK starts.
        $bootstrap = dirname(__DIR__, 3) . '/src/Freemius/Bootstrap.php';
        $script    = 'define("ABSPATH", "/"); define("WPMCP_FS_ID", 34955); define("WPMCP_FS_PUBLIC_KEY", "pk_x");'
            . ' define("WPMCP_FLAVOR", "__FLAVOR__"); require "__FILE__";'
            . ' $c = WPMCP\\Freemius\\Bootstrap::config();'
            . ' echo json_encode([WPMCP\\Freemius\\Bootstrap::upgrades_in_place(), $c["has_premium_version"], $c["is_premium"], $c["slug"], $c["id"]]);';
        foreach (['elementor' => [true, false], 'woocommerce' => [false, true]] as $flavor => [$in_place, $premium_version]) {
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(str_replace(['__FLAVOR__', '__FILE__'], [$flavor, $bootstrap], $script)));
            $this->assertSame([$in_place, $premium_version, false, 'wpmcp', 34955], json_decode((string) $out, true), "licensing config for the {$flavor} flavor");
        }
    }
}
