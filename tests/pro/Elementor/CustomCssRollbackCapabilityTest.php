<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Add_Custom_Css;

/**
 * Issue #726: core's custom_css post type maps edit_post onto edit_css, a
 * meta capability current_user_can() does not map again, so the rollback's
 * edit_post check refused everyone, the administrator who made the write
 * included. The undo now takes edit_css, as the write did; a caller without
 * it is still refused.
 */
class CustomCssRollbackCapabilityTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        wpmcp_test_grant_network_admin($this->admin_id);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Creates the Additional CSS post, then replaces it through a snapshotted write. */
    private function replace_css(): string
    {
        (new Add_Custom_Css())->handle(['css' => 'body{outline:3px solid red}']);
        $out = (new Add_Custom_Css())->handle(['css' => '/* h6 */', 'replace' => true]);
        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('/* h6 */', trim((string) wp_get_custom_css()));
        return (string) $out['operation_id'];
    }

    public function test_the_administrator_who_wrote_the_css_can_undo_it(): void
    {
        $operation_id = $this->replace_css();

        $this->assertTrue(Rollback_Service::restore_operation($operation_id), implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('body{outline:3px solid red}', trim((string) wp_get_custom_css()));
    }

    public function test_an_author_without_edit_css_is_refused(): void
    {
        $operation_id = $this->replace_css();

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertFalse(current_user_can('edit_css'));

        $this->assertFalse(Rollback_Service::restore_operation($operation_id));
        $warnings = implode(' ', Rollback_Service::take_warnings());
        $this->assertStringContainsString('you cannot edit post', $warnings);
        wp_set_current_user($this->admin_id);
        $this->assertSame('/* h6 */', trim((string) wp_get_custom_css()));
    }

    public function test_an_administrator_whose_edit_css_is_withdrawn_is_refused(): void
    {
        $operation_id = $this->replace_css();

        // What DISALLOW_UNFILTERED_HTML does to edit_css, for this request only.
        add_filter('map_meta_cap', static function (array $caps, string $cap): array {
            return 'edit_css' === $cap ? [ 'do_not_allow' ] : $caps;
        }, 10, 2);
        $this->assertFalse(current_user_can('edit_css'));

        $this->assertFalse(Rollback_Service::restore_operation($operation_id));
        $this->assertStringContainsString('you cannot edit post', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('/* h6 */', trim((string) wp_get_custom_css()));
    }
}
