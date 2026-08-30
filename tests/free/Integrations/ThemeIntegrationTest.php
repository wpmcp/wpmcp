<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

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
        set_theme_mod('header_textcolor', 'aabbcc');

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => 'ddeeff' ] ],
        ]));

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('ddeeff', get_theme_mod('header_textcolor'));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_rollback_restores_the_prior_theme_mods_state(): void
    {
        set_theme_mod('header_textcolor', 'aabbcc');

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => 'ddeeff' ] ],
        ]));
        $this->assertSame('ddeeff', get_theme_mod('header_textcolor'));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertSame('aabbcc', get_theme_mod('header_textcolor'));
    }

    public function test_rollback_deletes_a_theme_mods_option_that_did_not_exist_before(): void
    {
        delete_option('theme_mods_' . get_stylesheet());

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => 'ddeeff' ] ],
        ]));
        $this->assertNotFalse(get_option('theme_mods_' . get_stylesheet()));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertFalse(get_option('theme_mods_' . get_stylesheet()));
    }

    public function test_set_mods_rejects_malformed_args_before_any_write(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [],
        ]));

        $this->assertSame('invalid_args', $out['error']['code']);

        $empty = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [] ],
        ]));

        $this->assertSame('invalid_args', $empty['error']['code']);
    }

    /**
     * Values reach set_theme_mod(), and core echoes header_textcolor and
     * background_color straight into a <style> block, so an unsanitized value
     * is stored XSS. Every allowlisted key carries a validator and a value
     * that fails it is refused, never coerced and never stored.
     */
    public function test_header_textcolor_refuses_a_value_that_is_not_a_hex_color(): void
    {
        set_theme_mod('header_textcolor', 'aabbcc');

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => '</style><script>alert(1)</script>' ] ],
        ]));

        $result = $out['result'];
        $this->assertSame([], $result['updated']);
        $this->assertSame('invalid_value', $result['refused'][0]['reason']);
        $this->assertSame('aabbcc', get_theme_mod('header_textcolor'));
    }

    public function test_background_color_refuses_a_value_that_is_not_a_hex_color(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'background_color' => 'red;} body { display:none' ] ],
        ]));

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);
        $this->assertFalse(get_theme_mod('background_color'));
    }

    public function test_hex_colors_and_enums_that_pass_their_validator_are_stored_normalized(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [
                'background_color'  => '#AABBCC',
                'background_repeat' => 'no-repeat',
                'header_textcolor'  => 'blank',
            ] ],
        ]));

        $this->assertSame([], $out['result']['refused']);
        $this->assertSame('AABBCC', get_theme_mod('background_color'));
        $this->assertSame('no-repeat', get_theme_mod('background_repeat'));
        $this->assertSame('blank', get_theme_mod('header_textcolor'));
    }

    public function test_enum_backed_mod_refuses_a_value_outside_the_core_enum(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'background_repeat' => 'sideways' ] ],
        ]));

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);
    }

    public function test_custom_logo_refuses_anything_but_an_existing_attachment_id(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'custom_logo' => 999999 ] ],
        ]));

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);

        $attachment = self::factory()->post->create([ 'post_type' => 'attachment' ]);
        $ok         = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'custom_logo' => (string) $attachment ] ],
        ]));

        $this->assertSame([ 'custom_logo' ], $ok['result']['updated']);
        $this->assertSame($attachment, get_theme_mod('custom_logo'));
    }

    public function test_header_image_refuses_a_javascript_url(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_image' => 'javascript:alert(1)' ] ],
        ]));

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);
    }

    public function test_a_filter_that_narrows_the_allowlist_can_close_background_keys(): void
    {
        $close_everything = static fn (array $allowlist): array => [];
        add_filter('wpmcp_theme_mod_allowlist', $close_everything);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'background_color' => 'aabbcc' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_allowlist', $close_everything);
        }

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('not_allowlisted', $out['result']['refused'][0]['reason']);
        $this->assertFalse(get_theme_mod('background_color'));
    }

    public function test_invented_background_keys_are_not_writable(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'background_anything_at_all' => 'x' ] ],
        ]));

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('not_allowlisted', $out['result']['refused'][0]['reason']);
    }

    public function test_advertised_allowlist_matches_what_set_mods_accepts(): void
    {
        $open_everything = static fn (array $allowlist): array => array_merge(
            $allowlist,
            [ 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id' ]
        );
        add_filter('wpmcp_theme_mod_allowlist', $open_everything);

        try {
            $advertised = $this->integration->handle_read([ 'operation' => 'get-mods' ])['result'];
        } finally {
            remove_filter('wpmcp_theme_mod_allowlist', $open_everything);
        }

        $this->assertContains('background_color', $advertised['allowlist']);
        $this->assertNotContains('nav_menu_locations', $advertised['allowlist']);
        $this->assertNotContains('sidebars_widgets', $advertised['allowlist']);
        $this->assertNotContains('custom_css_post_id', $advertised['allowlist']);
        $this->assertSame($advertised['allowlist'], $advertised['writable']);
    }

    public function test_writable_annotation_covers_allowlisted_keys_that_are_not_set_yet(): void
    {
        remove_theme_mod('custom_logo');

        $result = $this->integration->handle_read([ 'operation' => 'get-mods' ])['result'];

        $this->assertContains('custom_logo', $result['writable']);
        $this->assertNotContains('custom_logo', $result['writable_present']);
    }

    public function test_get_mods_redacts_secret_looking_theme_mod_values(): void
    {
        set_theme_mod('some_theme_api_key', 'sk-live-should-never-be-echoed');
        set_theme_mod('vendor', [ 'license_secret' => 'nope', 'label' => 'fine' ]);

        $result = $this->integration->handle_read([ 'operation' => 'get-mods' ])['result'];

        $this->assertNotSame('sk-live-should-never-be-echoed', $result['mods']['some_theme_api_key']);
        $this->assertNotSame('nope', $result['mods']['vendor']['license_secret']);
        $this->assertSame('fine', $result['mods']['vendor']['label']);
        $this->assertContains('some_theme_api_key', $result['redacted']);
    }

    public function test_a_fully_refused_batch_writes_no_snapshot_and_reports_unrecoverable(): void
    {
        $before = count(Snapshot_Store::recent(100));

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [
                'nav_menu_locations'  => [ 'primary' => 1 ],
                'some_random_mod_key' => 'nope',
                'header_textcolor'    => '<script>',
            ] ],
        ]));

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([], $out['result']['updated']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertCount($before, Snapshot_Store::recent(100));
    }

    public function test_is_child_is_derived_from_stylesheet_template_divergence(): void
    {
        $template = static fn (): string => 'twentytwentythree';
        add_filter('template', $template);

        try {
            $result = $this->integration->handle_read([ 'operation' => 'get-theme-context' ])['result'];
        } finally {
            remove_filter('template', $template);
        }

        $this->assertNotSame($result['stylesheet'], $result['template']);
        $this->assertTrue($result['is_child']);
        $this->assertTrue($result['parent_missing']);
        $this->assertNull($result['parent']);
    }

    public function test_block_theme_inert_mods_are_annotated_as_ineffective(): void
    {
        $block = static fn (): bool => true;
        add_filter('wpmcp_theme_is_block_theme', $block);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => 'aabbcc' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_is_block_theme', $block);
        }

        $this->assertSame([ 'header_textcolor' ], $out['result']['updated']);
        $this->assertSame([ 'header_textcolor' ], $out['result']['ineffective']);
    }

    public function test_structural_refusal_names_the_supported_alternative_tool(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'nav_menu_locations' => [ 'primary' => 1 ] ] ],
        ]));

        $this->assertStringContainsString(
            'wpmcp/assign-menu-to-location',
            $out['result']['refused'][0]['detail']
        );
    }
}
