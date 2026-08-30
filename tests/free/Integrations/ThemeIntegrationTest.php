<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;

/**
 * Phase 1 theme dispatcher (#144): context reads plus reversible,
 * allowlist-gated theme-mod writes. Follows AcfIntegrationTest conventions.
 *
 * The two refusal tests are the TDD anchors from the issue: structural keys
 * must be refused even when a filter adds them to the allowlist, and
 * non-allowlisted keys must be refused with a structured per-key report.
 */
class ThemeIntegrationTest extends \WP_UnitTestCase
{
    private Theme_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->integration = new Theme_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    private function withWritesEnabled(callable $fn)
    {
        $enable = static fn () => true;
        add_filter('wpmcp_enable_theme_write', $enable);
        try {
            return $fn();
        } finally {
            remove_filter('wpmcp_enable_theme_write', $enable);
        }
    }

    public function test_structural_keys_refused_even_when_filter_allowlists_them(): void
    {
        $open_everything = static fn (array $allowlist): array => array_merge(
            $allowlist,
            [ 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id' ]
        );
        add_filter('wpmcp_theme_mod_allowlist', $open_everything);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [
                    'values' => [
                        'nav_menu_locations' => [ 'primary' => 999 ],
                        'sidebars_widgets'   => [],
                        'custom_css_post_id' => 999,
                    ],
                ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_allowlist', $open_everything);
        }

        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame([], $result['updated']);
        $this->assertCount(3, $result['refused']);
        foreach ($result['refused'] as $refusal) {
            $this->assertSame('structural', $refusal['reason']);
        }
        $this->assertNotSame(999, get_theme_mod('custom_css_post_id'));
    }

    public function test_non_allowlisted_key_refused_with_structured_report(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [
                'values' => [
                    'header_textcolor'    => '336699',
                    'some_random_mod_key' => 'nope',
                ],
            ],
        ]));

        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame([ 'header_textcolor' ], $result['updated']);
        $this->assertCount(1, $result['refused']);
        $this->assertSame('some_random_mod_key', $result['refused'][0]['key']);
        $this->assertSame('not_allowlisted', $result['refused'][0]['reason']);
        $this->assertFalse(get_theme_mod('some_random_mod_key'));
        $this->assertSame('336699', get_theme_mod('header_textcolor'));
    }

    public function test_set_mods_disabled_by_default(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => '336699' ] ],
        ]);

        $this->assertSame('operation_disabled', $out['error']['code']);
    }

    public function test_get_theme_context_reports_core_shape(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-theme-context' ]);

        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame(get_stylesheet(), $result['stylesheet']);
        $this->assertSame(get_template(), $result['template']);
        $this->assertIsBool($result['is_child']);
        $this->assertIsBool($result['is_block_theme']);
        $this->assertIsArray($result['theme_supports']);
        $this->assertIsArray($result['menu_locations']);
        $this->assertIsBool($result['child_theme_exists']);
    }

    public function test_get_mods_annotates_writable_keys(): void
    {
        set_theme_mod('header_textcolor', 'aabbcc');
        set_theme_mod('nav_menu_locations', []);

        $out = $this->integration->handle_read([ 'operation' => 'get-mods' ]);

        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertContains('header_textcolor', $result['writable']);
        $this->assertNotContains('nav_menu_locations', $result['writable']);
    }

    public function test_set_mods_write_is_snapshotted_and_recoverable(): void
    {
        set_theme_mod('header_textcolor', 'before');

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => 'after' ] ],
        ]));

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('after', get_theme_mod('header_textcolor'));
        // TODO(#144): round-trip through rollback-operation and assert the
        // prior theme_mods option state is restored, mirroring
        // AcfIntegrationTest's rollback coverage.
    }
}
