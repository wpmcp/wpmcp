<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Guarded_Handler;
use WPMCP\Admin\Management_Access;
use WPMCP\Connect\Exposure;
use WPMCP\Memory\Memory_Store;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * Issue #479: every WP MCP management entry point goes through the one
 * management check. These tests enumerate what is actually registered
 * (menus, admin-post and AJAX handlers, REST routes, options.php groups),
 * so an entry point added later without the check fails the suite instead
 * of quietly reopening management to every administrator.
 *
 * Deliberately NOT gated, and listed here so the exemption is visible:
 * the MCP endpoint, the OAuth registration/authorize/token routes and the
 * consent screen (end users connecting their own account), and the
 * single-use media upload link (its token is the credential).
 */
class ManagementAccessEntryPointsTest extends \WP_UnitTestCase
{
    /** REST routes outside plugin management, by path prefix. */
    private const UNGATED_REST_PREFIXES = [
        '/wpmcp/v1/oauth/',
        '/wpmcp/v1/media-upload/',
    ];

    private int $listed;
    private int $off_list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listed   = self::factory()->user->create(['role' => 'administrator']);
        $this->off_list = self::factory()->user->create(['role' => 'administrator']);
        // The allowlist comes from the constant seam, not the option: firing
        // rest_api_init below can run DDL, whose implicit commit would keep a
        // stored allowlist past this test's rollback.
        Management_Access::set_constant_for_tests(true, (string) $this->listed);
    }

    protected function tearDown(): void
    {
        Management_Access::set_constant_for_tests(false);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    /** @return array<int, array{0: string, 1: string}> [menu slug, capability] for every WP MCP menu entry. */
    private function wpmcp_menu_entries(): array
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];
        Gate::set_pro_for_tests(true);
        // add_submenu_page() skips an entry the registering user cannot
        // open, so register as a listed administrator.
        wp_set_current_user($this->listed);

        Plugin::instance()->register_admin_menu();

        $entries = [];
        foreach ($menu as $item) {
            if ('wpmcp' === $item[2]) {
                $entries[] = [$item[2], $item[1]];
            }
        }
        foreach ($submenu['wpmcp'] ?? [] as $item) {
            $entries[] = [$item[2], $item[1]];
        }

        return $entries;
    }

    public function test_every_wpmcp_menu_entry_requires_the_management_capability(): void
    {
        $entries = $this->wpmcp_menu_entries();

        $this->assertGreaterThan(5, count($entries), 'Expected the WP MCP menu and its screens.');
        foreach ($entries as [$slug, $cap]) {
            $this->assertSame(Management_Access::CAPABILITY, $cap, "Menu entry {$slug} skips the management check.");
        }
    }

    public function test_an_off_list_administrator_can_open_no_wpmcp_screen(): void
    {
        $entries = $this->wpmcp_menu_entries();

        wp_set_current_user($this->off_list);
        foreach ($entries as [$slug, $cap]) {
            $this->assertFalse(current_user_can($cap), "Off-list administrator can open {$slug}.");
        }

        wp_set_current_user($this->listed);
        foreach ($entries as [$slug, $cap]) {
            $this->assertTrue(current_user_can($cap), "Listed administrator cannot open {$slug}.");
        }
    }

    public function test_the_memory_list_table_needs_the_management_capability(): void
    {
        Memory_Store::ensure_post_type();
        $type = get_post_type_object(Memory_Store::POST_TYPE);

        wp_set_current_user($this->off_list);
        $this->assertFalse(current_user_can($type->cap->edit_posts));
        $this->assertFalse(current_user_can($type->cap->publish_posts));

        wp_set_current_user($this->listed);
        $this->assertTrue(current_user_can($type->cap->edit_posts));
        $this->assertTrue(current_user_can($type->cap->publish_posts));
    }

    /**
     * Every admin-post and AJAX handler WP MCP registers, as
     * [hook, callback]. Ownership is decided by the callback (a WPMCP class
     * or a closure defined under src/), not the action name, so a handler
     * with an unprefixed action is still found.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function wpmcp_admin_handlers(): array
    {
        global $wp_filter;
        $src      = realpath(__DIR__ . '/../../../src');
        $handlers = [];

        foreach ($wp_filter as $hook => $filter) {
            if (! preg_match('/^(admin_post_|admin_post_nopriv_|wp_ajax_|wp_ajax_nopriv_)/', (string) $hook)) {
                continue;
            }
            foreach ($filter->callbacks as $callbacks) {
                foreach ($callbacks as $entry) {
                    $callback = $entry['function'];
                    if ($callback instanceof Guarded_Handler || $this->owned_by_wpmcp($callback, (string) $src)) {
                        $handlers[] = [(string) $hook, $callback];
                    }
                }
            }
        }

        return $handlers;
    }

    /** @param mixed $callback */
    private function owned_by_wpmcp($callback, string $src): bool
    {
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return 0 === strpos($class, 'WPMCP\\');
        }
        if (is_string($callback)) {
            return 0 === strpos($callback, 'WPMCP\\');
        }
        if ($callback instanceof \Closure) {
            $file = (string) (new \ReflectionFunction($callback))->getFileName();
            return '' !== $src && 0 === strpos((string) realpath($file), $src);
        }
        if (is_object($callback)) {
            return 0 === strpos(get_class($callback), 'WPMCP\\');
        }

        return false;
    }

    public function test_every_wpmcp_admin_post_and_ajax_handler_goes_through_the_check(): void
    {
        $handlers = $this->wpmcp_admin_handlers();
        $this->assertGreaterThan(4, count($handlers), 'Expected the WP MCP admin-post and AJAX handlers.');

        $guarded = 0;
        foreach ($handlers as [$hook, $callback]) {
            if (in_array($hook, Management_Access::UNGUARDED_HOOKS, true)) {
                $this->assertNotInstanceOf(Guarded_Handler::class, $callback, "{$hook} is exempt but wrapped.");
                continue;
            }
            $this->assertInstanceOf(
                Guarded_Handler::class,
                $callback,
                "{$hook} is a WP MCP handler registered without the management check. Register it with Management_Access::add_handler()."
            );
            $guarded++;
        }

        foreach (['wp_ajax_wpmcp_restore', 'admin_post_wpmcp_download_bundle'] as $known) {
            $this->assertContains($known, array_column($handlers, 0));
        }
        $this->assertGreaterThan(4, $guarded);
    }

    public function test_every_guarded_handler_refuses_an_off_list_administrator_with_a_403(): void
    {
        wp_set_current_user($this->off_list);

        foreach ($this->wpmcp_admin_handlers() as [$hook, $callback]) {
            if (! $callback instanceof Guarded_Handler) {
                continue;
            }
            $inner_ran = false;
            $probe     = new Guarded_Handler(static function () use (&$inner_ran): void {
                $inner_ran = true;
            });

            try {
                $callback();
                $this->fail("{$hook} did not refuse an off-list administrator.");
            } catch (\WPDieException $e) {
                $this->assertSame(403, $e->getCode(), "{$hook} refused without a 403.");
                $this->assertSame(Management_Access::refusal_message(), $e->getMessage(), "{$hook} was refused by something other than the management check.");
            }

            try {
                $probe();
            } catch (\WPDieException $e) {
                // Expected: the same guard, with an inner handler we can observe.
            }
            $this->assertFalse($inner_ran, 'The guard ran the inner handler for an off-list administrator.');
        }
    }

    public function test_a_guarded_handler_runs_for_a_listed_administrator(): void
    {
        wp_set_current_user($this->listed);
        $ran     = false;
        $handler = new Guarded_Handler(static function () use (&$ran): void {
            $ran = true;
        });

        $handler();

        $this->assertTrue($ran);
    }

    public function test_every_wpmcp_rest_route_with_an_admin_permission_callback_is_gated(): void
    {
        global $wp_rest_server;
        // The abilities registry initializes lazily, and rest_api_init can be
        // the first thing to touch it. Initialize it before forcing Pro on, or
        // it would fill with Pro abilities and leak them into later tests.
        if (function_exists('wp_get_abilities')) {
            wp_get_abilities();
        }
        $wp_rest_server = new \WP_REST_Server();
        // Pro on so the chat routes register and their permission check can
        // pass for a listed administrator; tearDown turns it back off.
        Gate::set_pro_for_tests(true);
        do_action('rest_api_init', $wp_rest_server);

        $checked = 0;
        foreach ($wp_rest_server->get_routes() as $route => $endpoints) {
            // The namespace index route is core's, with no callback of ours.
            if (0 !== strpos($route, '/wpmcp/') || 1 === preg_match('#^/wpmcp/v[0-9]+$#', $route)) {
                continue;
            }
            foreach (self::UNGATED_REST_PREFIXES as $prefix) {
                if (0 === strpos($route, $prefix)) {
                    continue 2;
                }
            }
            foreach ($endpoints as $endpoint) {
                $permission = $endpoint['permission_callback'] ?? null;
                $this->assertIsCallable($permission, "{$route} has no permission callback.");
                $request = new \WP_REST_Request(array_key_first((array) $endpoint['methods']) ?? 'GET', $route);

                wp_set_current_user($this->off_list);
                $verdict = call_user_func($permission, $request);
                $this->assertTrue(false === $verdict || is_wp_error($verdict), "{$route} admits an off-list administrator.");

                wp_set_current_user($this->listed);
                $this->assertTrue(true === call_user_func($permission, $request), "{$route} refuses a listed administrator.");
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'Expected the WP MCP admin REST routes (chat) to be registered.');
    }

    public function test_every_wpmcp_options_page_group_needs_the_management_capability(): void
    {
        // Only WP MCP's own admin_init callbacks: the whole action also runs
        // core's update checks and header-sending code.
        global $wp_filter;
        $src = (string) realpath(__DIR__ . '/../../../src');
        foreach ($wp_filter['admin_init']->callbacks as $callbacks) {
            foreach ($callbacks as $entry) {
                // Redirect_Store::maybe_install is schema, not settings, and
                // its DDL would commit this test's transaction.
                if ($this->owned_by_wpmcp($entry['function'], $src) && [\WPMCP\Tools\Redirects\Redirect_Store::class, 'maybe_install'] !== $entry['function']) {
                    call_user_func($entry['function']);
                }
            }
        }

        $groups = [];
        foreach (get_registered_settings() as $name => $args) {
            if (0 === strpos((string) $name, 'wpmcp')) {
                $groups[] = (string) $args['group'];
            }
        }
        $groups = array_unique($groups);

        $this->assertNotEmpty($groups);
        foreach ($groups as $group) {
            $this->assertSame(
                Management_Access::CAPABILITY,
                apply_filters("option_page_capability_{$group}", 'manage_options'),
                "options.php saves for {$group} skip the management check."
            );
        }
    }

    public function test_the_admin_bar_indicator_is_hidden_from_an_off_list_administrator(): void
    {
        require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

        wp_set_current_user($this->off_list);
        $bar = new \WP_Admin_Bar();
        Exposure::admin_bar($bar);
        $this->assertNull($bar->get_node('wpmcp-exposure'));

        wp_set_current_user($this->listed);
        $bar = new \WP_Admin_Bar();
        Exposure::admin_bar($bar);
        $this->assertNotNull($bar->get_node('wpmcp-exposure'));
    }

    public function test_agent_execution_is_not_part_of_the_policy(): void
    {
        wp_set_current_user($this->off_list);

        // Abilities keep checking their own capability; the off-list
        // administrator still holds manage_options for agent work.
        $this->assertTrue(current_user_can('manage_options'));
    }
}
