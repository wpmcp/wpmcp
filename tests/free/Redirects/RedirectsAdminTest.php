<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Admin\Redirect_Suggestion_Controller;
use WPMCP\Admin\Redirects_Page;
use WPMCP\Plugin;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

/**
 * The Redirects admin screen and the confirm/dismiss controller behind it
 * (issue #128) - the "a human confirms" half of suggest-only redirects.
 *
 * Confirming does not write a row itself: it calls the create-redirect tool,
 * so the confirmed redirect goes through the same validation, flattening and
 * Safe_Mutation snapshot as an agent's call and shows up in history as an
 * ordinary, reversible create-redirect.
 */
class RedirectsAdminTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        delete_option(Redirect_Suggestions::OPTION);
        $this->set_permalink_structure('/%postname%/');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function render(): string
    {
        ob_start();
        (new Redirects_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_the_submenu_is_registered_under_the_management_capability(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        Plugin::instance()->register_admin_menu();

        $found = null;
        foreach ($submenu['wpmcp'] as $item) {
            if (Redirects_Page::SLUG === $item[2]) {
                $found = $item;
                break;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-redirects submenu entry.');
        $this->assertSame(\WPMCP\Admin\Management_Access::CAPABILITY, $found[1]);
    }

    public function test_the_page_says_so_when_there_is_nothing_to_show(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('No redirects yet', $html);
        $this->assertStringContainsString('No pending suggestions', $html);
    }

    public function test_the_page_lists_redirects_with_their_resolved_target(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/old', 'target_post_id' => $post_id, 'status_code' => 302]);

        $html = $this->render();

        $this->assertStringContainsString('/old', $html);
        $this->assertStringContainsString(esc_html(get_permalink($post_id)), $html);
        $this->assertStringContainsString('302', $html);
        $this->assertStringContainsString('active', $html);
    }

    public function test_the_page_flags_a_redirect_whose_target_is_gone(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/old', 'target_post_id' => $post_id]);
        wp_delete_post($post_id, true);

        $html = $this->render();

        $this->assertStringContainsString('target missing', $html);
        $this->assertStringContainsString('inactive', $html);
    }

    public function test_the_page_offers_create_and_dismiss_for_a_pending_suggestion(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        Redirect_Suggestions::push([
            'source'         => '/moved',
            'reason'         => 'slug-changed',
            'target_post_id' => $post_id,
        ]);

        $html = $this->render();

        $this->assertStringContainsString('/moved', $html);
        $this->assertStringContainsString('slug-changed', $html);
        $this->assertStringContainsString('Create redirect', $html);
        $this->assertStringContainsString('Dismiss', $html);
        $this->assertStringNotContainsString('disabled="disabled"', $html);
    }

    public function test_a_suggestion_with_no_proposed_target_cannot_be_created_from_the_screen(): void
    {
        Redirect_Suggestions::push(['source' => '/gone', 'reason' => 'post-deleted', 'target_post_id' => 0]);

        $html = $this->render();

        $this->assertStringContainsString('choose a target with create-redirect', $html);
        $this->assertStringContainsString('disabled="disabled"', $html);
    }

    public function test_confirming_creates_a_reversible_redirect_and_clears_the_suggestion(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        Redirect_Suggestions::push([
            'source'         => '/moved',
            'reason'         => 'slug-changed',
            'target_post_id' => $post_id,
        ]);

        $out = (new Redirect_Suggestion_Controller())->confirm('/moved', 0);

        $this->assertNotEmpty($out['operation_id'], 'A confirmed suggestion must be snapshotted like any other write.');
        $this->assertSame($post_id, $out['redirect']['target_post_id']);
        $this->assertSame([], Redirect_Suggestions::all());
        $this->assertNotNull(Redirect_Store::find_by_source('/moved'));
    }

    public function test_confirming_an_unknown_suggestion_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No pending redirect suggestion');

        (new Redirect_Suggestion_Controller())->confirm('/never-suggested', 0);
    }

    public function test_confirming_can_supply_a_target_the_suggestion_did_not_have(): void
    {
        Redirect_Suggestions::push(['source' => '/gone', 'reason' => 'post-deleted', 'target_post_id' => 0]);
        $post_id = self::factory()->post->create(['post_name' => 'replacement', 'post_status' => 'publish']);

        $out = (new Redirect_Suggestion_Controller())->confirm('/gone', $post_id);

        $this->assertSame($post_id, $out['redirect']['target_post_id']);
    }

    public function test_dismissing_drops_the_suggestion_without_creating_anything(): void
    {
        Redirect_Suggestions::push(['source' => '/moved', 'reason' => 'slug-changed']);

        $this->assertTrue((new Redirect_Suggestion_Controller())->dismiss('/moved'));
        $this->assertFalse((new Redirect_Suggestion_Controller())->dismiss('/moved'));
        $this->assertSame(0, Redirect_Store::count());
    }
}
