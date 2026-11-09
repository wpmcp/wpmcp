<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Integrations\Theme_Layouts_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #656: the theme layout ops against the real premium add-ons.
 *
 * It runs only in the local gate's live theme add-on leg
 * (bin/test-local.sh --live-theme-pro), which sets WPMCP_LIVE_THEME_PRO to
 * astra, kadence or generatepress, copies that add-on from
 * WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC or WPMCP_GP_PREMIUM_SRC (they
 * are commercial, not on wordpress.org) beside the free theme, and makes the
 * theme the active one in tests/bootstrap.php. Each run selects its own
 * group, so only that add-on's tests run, with --fail-on-skipped.
 *
 * What the ops write must be what the add-on's own code reads: the hook a
 * layout is placed on through the add-on's hook reader, and whether it
 * shows on a page through the add-on's own display rule evaluation. Rollback
 * must leave the add-on reading the old state.
 */
class ThemeLayoutsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        add_filter('wpmcp_enable_theme_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function need(string $family, string $constant, string $class): void
    {
        if (getenv('WPMCP_LIVE_THEME_PRO') !== $family || ! defined($constant) || ! class_exists($class)) {
            $this->markTestSkipped("Needs the real {$family} add-on (bin/test-local.sh --live-theme-pro).");
        }
    }

    private function call(string $half, string $op, array $args = []): array
    {
        $integration = new Theme_Integration();
        $out         = 'read' === $half
            ? $integration->handle_read([ 'operation' => $op, 'args' => $args ])
            : $integration->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function assert_available(string $family): void
    {
        $this->assertTrue(Theme_Layouts_Pack::requirement(Theme_Layouts_Pack::adapter($family)));
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $this->assertTrue($ops["create-{$family}-layout"]['dependency_met']);
    }

    /** @return string[] every hook name list-<family>-hooks reports for $type */
    private function hook_names(string $family, string $type): array
    {
        $groups = $this->call('read', "list-{$family}-hooks", [ 'type' => $type ])['result']['hooks'];
        return array_merge(...array_map(static fn ($g) => array_column($g['hooks'], 'name'), $groups));
    }

    /** @group astra-pro-live */
    public function test_astra_layout_is_placed_and_shown_by_astra_and_rolls_back(): void
    {
        $this->need('astra', 'ASTRA_EXT_VER', 'Astra_Target_Rules_Fields');
        $this->assert_available('astra');
        $this->assertContains('astra_header_before', $this->hook_names('astra', 'hooks'));

        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->call('write', 'create-astra-layout', [
            'title'  => 'Promo',
            'type'   => 'hooks',
            'hook'   => 'astra_header_before',
            'status' => 'publish',
            'rules'  => [ 'location' => [ 'rule' => [ 'basic-global' ] ], 'exclusion' => [ 'specific' => [ 'post-' . $page ] ], 'users' => [ 'all' ] ],
        ]);
        $id = (int) $out['result']['layout']['id'];

        $this->assertSame([ 'astra_header_before' ], \Astra_Ext_Advanced_Hooks_Markup::get_hook_actions($id));
        $rules = \Astra_Target_Rules_Fields::get_instance();
        $post  = self::factory()->post->create();
        $this->go_to(get_permalink($post));
        $this->assertTrue($rules->parse_layout_display_condition($post, get_post_meta($id, 'ast-advanced-hook-location', true)));
        $this->assertTrue($rules->parse_layout_display_condition($page, get_post_meta($id, 'ast-advanced-hook-exclusion', true)), 'The excluded page is matched by the exclusion');

        $set = $this->call('write', 'set-astra-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'location' => [ 'rule' => [ 'special-404' ] ] ] ]);
        $this->assertFalse($rules->parse_layout_display_condition($post, get_post_meta($id, 'ast-advanced-hook-location', true)));
        $this->assertTrue(Rollback_Service::restore_operation((string) $set['operation_id']));
        $this->assertTrue($rules->parse_layout_display_condition($post, get_post_meta($id, 'ast-advanced-hook-location', true)));

        $view = $this->call('read', 'get-astra-layout', [ 'layout_id' => $id ])['result']['layout'];
        $this->assertSame('astra_header_before', $view['hook']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    /** @group kadence-pro-live */
    public function test_kadence_element_is_read_and_shown_by_kadence_and_rolls_back(): void
    {
        $this->need('kadence', 'KTP_VERSION', 'Kadence_Pro\Elements_Controller');
        $this->assert_available('kadence');
        $this->assertContains('kadence_before_header', $this->hook_names('kadence', 'default'));
        $this->assertContains('replace_404', $this->hook_names('kadence', 'template'));

        $post = self::factory()->post->create();
        $out  = $this->call('write', 'create-kadence-layout', [
            'title'    => 'Banner',
            'type'     => 'default',
            'hook'     => 'kadence_before_header',
            'priority' => 15,
            'status'   => 'publish',
            'content'  => '<p>Hi</p>',
            'rules'    => [ 'show' => [ [ 'rule' => 'singular|post', 'select' => 'ids', 'ids' => [ $post ] ] ], 'device' => [ 'desktop' ] ],
        ]);
        $id         = (int) $out['result']['layout']['id'];
        $controller = \Kadence_Pro\Elements_Controller::get_instance();
        $meta       = $controller->get_post_meta_array(get_post($id));
        $this->assertSame('kadence_before_header', $meta['hook']);
        $this->assertSame(15, (int) $meta['priority']);
        $this->assertSame('desktop', $meta['device'][0]['value']);

        // Kadence renders published elements on 'wp'; the suite's page
        // builder doubles are not complete enough for its builder checks,
        // and only the condition evaluation is under test here.
        remove_action('wp', [ $controller, 'init_frontend_hooks' ], 99);
        $this->go_to(get_permalink($post));
        $this->reset_kadence_conditions();
        $this->assertTrue($controller->check_element_conditionals(get_post($id), $meta));

        $set = $this->call('write', 'set-kadence-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'show' => [ [ 'rule' => 'general|404' ] ] ] ]);
        $this->assertFalse($controller->check_element_conditionals(get_post($id), $controller->get_post_meta_array(get_post($id))));
        $this->assertTrue(Rollback_Service::restore_operation((string) $set['operation_id']));
        $this->assertTrue($controller->check_element_conditionals(get_post($id), $controller->get_post_meta_array(get_post($id))));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    /** @group generatepress-pro-live */
    public function test_generatepress_element_is_shown_by_gp_premium_and_rolls_back(): void
    {
        $this->need('generatepress', 'GP_PREMIUM_VERSION', 'GeneratePress_Conditions');
        $this->assert_available('generatepress');
        $this->assertContains('generate_before_header', $this->hook_names('generatepress', 'hook'));

        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->call('write', 'create-generatepress-layout', [
            'title'   => 'Notice',
            'type'    => 'hook',
            'hook'    => 'generate_before_header',
            'status'  => 'publish',
            'content' => '<p>Notice</p>',
            'rules'   => [ 'display' => [ [ 'rule' => 'general:site' ] ], 'exclude' => [ [ 'rule' => 'post:page', 'object' => $page ] ], 'users' => [ 'general:all' ] ],
        ]);
        $id = (int) $out['result']['layout']['id'];

        $show = static fn (): bool => \GeneratePress_Conditions::show_data(
            get_post_meta($id, '_generate_element_display_conditions', true),
            get_post_meta($id, '_generate_element_exclude_conditions', true),
            get_post_meta($id, '_generate_element_user_conditions', true)
        );
        $this->go_to(get_permalink(self::factory()->post->create()));
        $this->assertTrue($show());
        $this->go_to(get_permalink($page));
        $this->assertFalse($show(), 'The excluded page is excluded');

        $up = $this->call('write', 'update-generatepress-layout', [ 'layout_id' => $id, 'hook' => 'generate_after_footer', 'content' => '<p>New</p>' ]);
        $this->assertSame('generate_after_footer', get_post_meta($id, '_generate_hook', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $up['operation_id']));
        $this->assertSame('generate_before_header', get_post_meta($id, '_generate_hook', true));
        $this->assertSame('<p>Notice</p>', get_post_meta($id, '_generate_element_content', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    /** Kadence caches the current page's conditions for the request; a test moves between pages. */
    private function reset_kadence_conditions(): void
    {
        $prop = new \ReflectionProperty(\Kadence_Pro\Elements_Controller::class, 'current_condition');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
