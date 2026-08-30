<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Pro\Gate;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * The deep WooCommerce operations catalog (issue #68): the declarative op
 * map, the discovery tool, and the read dispatcher.
 *
 * Acceptance criterion 1 of the issue is the route-catalog integrity test:
 * every catalog row must resolve to a route the installed WooCommerce
 * actually registers, so a typo or an upstream rename fails the suite rather
 * than surfacing as a runtime 404 in an agent session.
 */
class WooCatalogTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);

        // WooCommerce's store capabilities live on the administrator role, and
        // the suite's per-test rollback plus the cached WP_Roles singleton
        // leave them missing depending on test order. Re-seed them and rebuild
        // the singleton so the user created next actually carries them.
        if (class_exists('WC_Install')) {
            \WC_Install::create_roles();
            $GLOBALS['wp_roles'] = null;
            wp_roles();
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Rebuild the REST server so rest_api_init fires again and
        // WooCommerce re-registers its wc/v3 controllers. Without this the
        // suite's route table depends on which test happened to build the
        // server first, and the integrity test reads an empty wc/v3 surface.
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Acceptance criterion 1: every row maps onto a registered wc/v3 route. */
    public function test_every_catalog_row_resolves_to_a_registered_wc_v3_route(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        $registered = array_keys(rest_get_server()->get_routes());
        $this->assertNotEmpty($registered);

        foreach (Op_Catalog::ops() as $name => $def) {
            $pattern = $def['route'];
            // Turn the catalog's {param} template into the regex-ish shape
            // WordPress registers, then match structurally on segment count
            // and literal segments.
            $found = false;
            foreach ($registered as $route) {
                if ($this->route_matches($pattern, $route)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Op {$name} maps to unregistered route {$pattern}");
        }
    }

    /** No template placeholder may survive resolution with every param supplied. */
    public function test_resolve_route_leaves_no_unsubstituted_placeholder(): void
    {
        foreach (Op_Catalog::ops() as $name => $def) {
            $params = [];
            foreach ($def['path_params'] as $param) {
                $params[ $param ] = 42;
            }
            [ $route ] = Op_Catalog::resolve_route($name, $params);
            $this->assertStringNotContainsString('{', $route, "Op {$name} left a placeholder in {$route}");
            $this->assertStringNotContainsString('}', $route, "Op {$name} left a placeholder in {$route}");
        }
    }

    public function test_resolve_route_substitutes_path_params_and_keeps_the_remainder_as_query(): void
    {
        [ $route, $query ] = Op_Catalog::resolve_route('orders.notes', [
            'order_id' => 12,
            'type'     => 'customer',
        ]);

        $this->assertSame('/wc/v3/orders/12/notes', $route);
        $this->assertSame(['type' => 'customer'], $query);
    }

    public function test_resolve_route_rawurlencodes_path_params(): void
    {
        [ $route ] = Op_Catalog::resolve_route('settings.options', ['group_id' => 'a b/c']);
        $this->assertSame('/wc/v3/settings/a%20b%2Fc', $route);
    }

    public function test_resolve_route_reports_every_missing_path_param_at_once(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order_id, id');
        Op_Catalog::resolve_route('refunds.get', []);
    }

    public function test_resolve_route_rejects_a_non_scalar_path_param(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a scalar');
        Op_Catalog::resolve_route('products.get', ['id' => ['nested' => 1]]);
    }

    public function test_unknown_op_names_the_discovery_tool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('woo-ops');
        Op_Catalog::get('nope');
    }

    /** Order, note, refund and customer ops keep the narrower free-tier caps. */
    public function test_order_and_customer_ops_require_narrower_capabilities(): void
    {
        $ops = Op_Catalog::ops();

        $this->assertSame('edit_shop_orders', $ops['orders.list']['capability']);
        $this->assertSame('edit_shop_orders', $ops['orders.get']['capability']);
        $this->assertSame('edit_shop_orders', $ops['orders.notes']['capability']);
        $this->assertSame('edit_shop_orders', $ops['refunds.list']['capability']);
        $this->assertSame('edit_shop_orders', $ops['refunds.get']['capability']);
        $this->assertSame('list_users', $ops['customers.list']['capability']);
        $this->assertSame('list_users', $ops['customers.get']['capability']);
        $this->assertSame('manage_woocommerce', $ops['products.list']['capability']);
    }

    /** Op names must not collide with the free tools' ability names. */
    public function test_op_names_are_namespaced_away_from_the_free_ability_names(): void
    {
        $free = ['list-products', 'get-product', 'list-orders', 'get-order', 'list-product-categories'];

        foreach (array_keys(Op_Catalog::ops()) as $name) {
            $this->assertNotContains($name, $free, "Op {$name} collides with a free ability name");
            $this->assertStringContainsString('.', $name, "Op {$name} should be domain-namespaced");
        }
    }

    public function test_woo_ops_groups_by_domain_and_can_filter_to_one(): void
    {
        $out = (new Woo_Ops())->handle([]);

        $this->assertArrayHasKey('domains', $out);
        $this->assertArrayHasKey('orders', $out['domains']);
        $this->assertSame(count(Op_Catalog::ops()), $out['total']);

        $filtered = (new Woo_Ops())->handle(['domain' => 'coupons']);
        $this->assertSame(['coupons'], array_keys($filtered['domains']));
        $this->assertSame(2, $filtered['total']);
    }

    public function test_woo_ops_reports_availability_and_per_op_capability(): void
    {
        $out = (new Woo_Ops())->handle(['domain' => 'orders']);

        $this->assertSame(class_exists('WooCommerce'), $out['available']);
        $this->assertSame('edit_shop_orders', $out['domains']['orders'][0]['capability']);
    }

    public function test_woo_read_requires_an_op(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('op is required');
        (new Woo_Read())->handle([]);
    }

    public function test_woo_read_refuses_a_non_get_catalog_row(): void
    {
        $read = new Woo_Read();
        $method = new \ReflectionMethod($read, 'assert_read_op');
        $this->expectException(\RuntimeException::class);
        $method->invoke($read, 'orders.create', ['method' => 'POST']);
    }

    public function test_woo_read_denies_an_op_whose_capability_the_user_lacks(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $out = (new Woo_Read())->handle(['op' => 'orders.list']);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
    }

    public function test_woo_read_dispatches_a_real_wc_v3_read(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        $out = (new Woo_Read())->handle(['op' => 'products.list']);

        $this->assertSame('products.list', $out['op']);
        $this->assertSame('/wc/v3/products', $out['route']);
        $this->assertSame(200, $out['status']);
        $this->assertIsArray($out['body']);
    }

    /** Raw wc/v3 bodies are capped so a list op cannot flood a model context. */
    public function test_woo_read_applies_a_default_per_page_and_caps_the_requested_one(): void
    {
        $read   = new Woo_Read();
        $method = new \ReflectionMethod($read, 'apply_query_defaults');

        $this->assertSame(
            Woo_Read::DEFAULT_PER_PAGE,
            $method->invoke($read, ['method' => 'GET', 'path_params' => []], [])['per_page']
        );
        $this->assertSame(
            Woo_Read::MAX_PER_PAGE,
            $method->invoke($read, ['method' => 'GET', 'path_params' => []], ['per_page' => 500])['per_page']
        );
        $this->assertSame(
            5,
            $method->invoke($read, ['method' => 'GET', 'path_params' => []], ['per_page' => 5])['per_page']
        );
    }

    /** Single-resource ops take no paging params. */
    public function test_single_resource_ops_get_no_per_page_default(): void
    {
        [ , $query ] = Op_Catalog::resolve_route('products.get', ['id' => 7]);
        $read   = new Woo_Read();
        $method = new \ReflectionMethod($read, 'apply_query_defaults');

        $this->assertArrayNotHasKey('per_page', $method->invoke($read, Op_Catalog::get('products.get'), $query));
    }

    public function test_both_catalog_abilities_register_pro_tier_in_the_woocommerce_domain(): void
    {
        $found = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if (in_array($ability->name, ['wpmcp/woo-ops', 'wpmcp/woo-read'], true)) {
                $found[ $ability->name ] = [$ability->tier, $ability->domain, $ability->capability];
            }
        }

        $this->assertSame(
            [
                'wpmcp/woo-ops'  => ['pro', 'woocommerce', 'manage_woocommerce'],
                'wpmcp/woo-read' => ['pro', 'woocommerce', 'manage_woocommerce'],
            ],
            $found
        );
    }

    /**
     * The vertical wpmcp-for-woocommerce build prunes src/Tools/Rest and
     * src/Integrations, so nothing the woocommerce flavor registers may
     * reference a class from either tree.
     */
    public function test_the_catalog_never_references_a_tree_the_woo_build_prunes(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Tools/WooCommerce';
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            // Collapse the escaped-backslash spelling used inside PHP
            // strings so one needle covers both `use WPMCP\Tools\Rest\X`
            // and '\\WPMCP\\Tools\\Rest\\X'.
            $source = str_replace('\\\\', '\\', file_get_contents($file->getPathname()));

            foreach (['WPMCP\\Tools\\Rest\\', 'WPMCP\\Integrations\\'] as $pruned) {
                $this->assertStringNotContainsString(
                    $pruned,
                    $source,
                    $file->getFilename() . ' references a tree the woocommerce build prunes'
                );
            }
        }
    }

    /**
     * Acceptance criterion 4: per-domain coverage. Nine of the ten domains
     * the issue names are seeded; variations is deliberately held back until
     * PR #203's dedicated free-tier variation tools merge, so the two
     * surfaces can share one description of the shape. This test pins both
     * halves of that statement so the gap cannot be forgotten.
     */
    public function test_the_catalog_covers_nine_of_the_ten_domains_the_issue_names(): void
    {
        $covered = [];
        foreach (Op_Catalog::ops() as $def) {
            $covered[ $def['domain'] ] = true;
        }
        ksort($covered);

        $this->assertSame(
            ['coupons', 'customers', 'orders', 'products', 'refunds', 'settings', 'shipping', 'taxes', 'webhooks'],
            array_keys($covered)
        );

        // The known gap, tracked in docs/wip/issue-68.md.
        $this->assertArrayNotHasKey('variations', $covered);
    }

    /**
     * Acceptance criterion 3: the dispatch is internal. rest_do_request()
     * runs the endpoint in-process, so no outbound HTTP request may fire.
     */
    public function test_dispatch_is_in_process_with_no_http_loopback(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        $outbound = 0;
        $spy = static function ($preempt) use (&$outbound) {
            $outbound++;
            return $preempt;
        };
        add_filter('pre_http_request', $spy);

        try {
            $out = (new Woo_Read())->handle(['op' => 'coupons.list']);
        } finally {
            remove_filter('pre_http_request', $spy);
        }

        $this->assertSame(200, $out['status']);
        $this->assertSame(0, $outbound, 'woo-read must not make an HTTP loopback request');
    }

    /** Loose structural match of a catalog template against a WP route regex. */
    private function route_matches(string $template, string $route): bool
    {
        $template_parts = explode('/', trim($template, '/'));
        $route_parts    = explode('/', trim($route, '/'));

        if (count($template_parts) !== count($route_parts)) {
            return false;
        }

        foreach ($template_parts as $i => $part) {
            $is_placeholder = (bool) preg_match('/^\{.+\}$/', $part);
            $is_capture     = (bool) preg_match('/^\(\?P?</', $route_parts[ $i ]);

            if ($is_placeholder !== $is_capture) {
                return false;
            }
            if (! $is_placeholder && $part !== $route_parts[ $i ]) {
                return false;
            }
        }

        return true;
    }
}
