<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/blocksy-companion-stubs.php';

/**
 * Blocksy Companion extensions on the theme pair (issue #650): listed with
 * their on/off state, switched through the companion's own extension
 * manager, snapshotted on blocksy_active_extensions so rollback-operation
 * puts the set back exactly.
 */
class ThemeBlocksyExtensionsTest extends \WP_UnitTestCase
{
    /** @var \WPMCP_Test_Blocksy_Extensions_Manager|null */
    private $manager = null;

    /** @var callable */
    private $template;

    /** @var callable */
    private $provide;

    private int $refreshes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->template = static fn () => 'blocksy';
        add_filter('template', $this->template);
        $this->manager = new \WPMCP_Test_Blocksy_Extensions_Manager();
        $this->provide = fn () => $this->manager;
        add_filter('wpmcp_blocksy_extensions_manager', $this->provide);
        add_action('blocksy:dynamic-css:refresh-caches', [ $this, 'count_refresh' ]);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('wpmcp_blocksy_extensions_manager', $this->provide);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        remove_action('blocksy:dynamic-css:refresh-caches', [ $this, 'count_refresh' ]);
        delete_option('blocksy_active_extensions');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function count_refresh(): void
    {
        $this->refreshes++;
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function set(array $extensions): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-blocksy-extensions', 'args' => [ 'extensions' => $extensions ] ]);
    }

    private function list(): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => 'list-blocksy-extensions' ]);
    }

    public function test_ops_register_for_blocksy_only_and_need_the_paid_tier(): void
    {
        $this->assertContains('list-blocksy-extensions', $this->names());
        $this->assertContains('set-blocksy-extensions', $this->names());

        Gate::set_pro_for_tests(false);
        $this->assertNotContains('list-blocksy-extensions', $this->names());
        Gate::set_pro_for_tests(true);

        remove_filter('template', $this->template);
        $this->template = static fn () => 'kadence';
        add_filter('template', $this->template);
        $this->assertNotContains('list-blocksy-extensions', $this->names());
    }

    public function test_list_reports_every_extension_with_its_state(): void
    {
        update_option('blocksy_active_extensions', [ 1 => 'trending' ]);

        $out = $this->list();

        $this->assertArrayNotHasKey('error', $out);
        $rows = array_column($out['result']['extensions'], null, 'id');
        $this->assertSame([ 'cookies-consent', 'mega-menu', 'newsletter-subscribe', 'product-reviews', 'trending' ], array_keys($rows));
        $this->assertTrue($rows['trending']['active']);
        $this->assertFalse($rows['cookies-consent']['active']);
        $this->assertTrue($rows['cookies-consent']['available']);
        $this->assertSame('Trending Posts', $rows['trending']['name']);
        $this->assertTrue($rows['mega-menu']['pro']);
        $this->assertFalse($rows['mega-menu']['available'], 'A Pro extension the site has no code for cannot be switched on');
        $this->assertArrayNotHasKey('icon', $rows['trending']);
        $this->assertSame([ 'trending' ], $out['result']['active']);
    }

    public function test_set_switches_extensions_through_the_manager_and_rolls_back_exactly(): void
    {
        update_option('blocksy_active_extensions', [ 'trending' ]);

        $out = $this->set([ 'cookies-consent' => true, 'trending' => false, 'newsletter-subscribe' => false ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'activate:cookies-consent', 'deactivate:trending' ], $this->manager->calls, 'Only real changes reach the manager');
        $this->assertSame([ 'cookies-consent' ], array_values(get_option('blocksy_active_extensions')));
        $this->assertSame([ 'cookies-consent' ], $out['result']['active']);
        $this->assertSame([ 'cookies-consent' => true, 'trending' => false ], $out['result']['changed']);

        $this->refreshes = 0;
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'trending' ], get_option('blocksy_active_extensions'));
        $this->assertGreaterThan(0, $this->refreshes, 'The dynamic CSS is rebuilt after the undo too');
    }

    public function test_undo_on_a_site_that_never_saved_the_option_removes_it(): void
    {
        $out = $this->set([ 'product-reviews' => true ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'product-reviews' ], array_values(get_option('blocksy_active_extensions')));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], (array) get_option('blocksy_active_extensions', []));
    }

    public function test_set_with_no_change_writes_nothing(): void
    {
        update_option('blocksy_active_extensions', [ 'trending' ]);

        $out = $this->set([ 'trending' => true ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([], $this->manager->calls);
        $this->assertSame([], $out['result']['changed']);
    }

    public function test_unknown_or_unavailable_extension_refuses_the_whole_batch(): void
    {
        update_option('blocksy_active_extensions', [ 'trending' ]);

        $unknown = $this->set([ 'cookies-consent' => true, 'no-such-ext' => true ]);
        $this->assertSame('unknown_extension', $unknown['error']['code']);

        $pro = $this->set([ 'cookies-consent' => true, 'mega-menu' => true ]);
        $this->assertSame('extension_unavailable', $pro['error']['code']);

        $this->assertSame([], $this->manager->calls);
        $this->assertSame([ 'trending' ], get_option('blocksy_active_extensions'));
    }

    public function test_writes_stay_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');

        $out = $this->set([ 'cookies-consent' => true ]);

        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString('wpmcp_enable_theme_write', $out['error']['message']);
        $this->assertSame([], $this->manager->calls);
    }

    public function test_missing_companion_names_the_plugin(): void
    {
        remove_filter('wpmcp_blocksy_extensions_manager', $this->provide);

        $out = $this->list();

        $this->assertSame('blocksy_companion_inactive', $out['error']['code']);
        $this->assertStringContainsString('Install and activate Blocksy Companion', $out['error']['message']);
        $this->assertSame('blocksy-companion/blocksy-companion.php', $out['error']['data']['required_plugin']);
    }
}
