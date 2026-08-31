<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\MCP\Request_Log;
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

        $this->assertContains('background_color', $advertised['writable']);
        $this->assertNotContains('nav_menu_locations', $advertised['writable']);
        $this->assertNotContains('sidebars_widgets', $advertised['writable']);
        $this->assertNotContains('custom_css_post_id', $advertised['writable']);
        // `writable` is the single advertised name for the effective policy;
        // a byte-identical `allowlist` twin would only double the payload.
        $this->assertArrayNotHasKey('allowlist', $advertised);
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

    public function test_governance_can_disable_set_mods_without_touching_the_read_half(): void
    {
        $narrow = fn ($enabled, $name) => 'wpmcp/theme-set-mods' === $name ? false : $enabled;
        add_filter('wpmcp_ability_enabled', $narrow, 10, 2);

        try {
            $denied = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => 'aabbcc' ] ],
            ]));
            $read   = $this->integration->handle_read([ 'operation' => 'get-theme-context' ]);
        } finally {
            remove_filter('wpmcp_ability_enabled', $narrow);
        }

        $this->assertSame('operation_denied', $denied['error']['code']);
        $this->assertSame('governance', $denied['error']['data']['reason']);
        $this->assertArrayNotHasKey('error', $read);
        $this->assertFalse(get_theme_mod('header_textcolor'));
    }

    public function test_both_halves_demand_edit_theme_options(): void
    {
        $this->assertSame('edit_theme_options', $this->integration->capability());
        foreach ($this->integration->abilities() as $ability) {
            $this->assertSame('edit_theme_options', $ability->capability);
        }
    }

    /**
     * set_theme_mod()/get_theme_mods() key the option off the UNFILTERED
     * get_option('stylesheet'), while get_stylesheet() runs the `stylesheet`
     * filter. On a site that filters it the snapshot must still name the
     * option the write actually lands on, or rollback silently reverts
     * nothing (or clobbers an unrelated option) while reporting success.
     */
    public function test_snapshot_targets_the_option_set_theme_mod_actually_writes(): void
    {
        set_theme_mod('header_textcolor', 'aabbcc');

        $rename = static fn (): string => 'some-other-stylesheet';
        add_filter('stylesheet', $rename);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => 'ddeeff' ] ],
            ]));

            $this->assertTrue($out['recoverable']);
            $this->assertSame('ddeeff', get_theme_mod('header_textcolor'));

            $snapshot = Snapshot_Store::get_by_operation($out['operation_id']);
            // Snapshot_Store's object_id COLUMN is a BIGINT, so an option
            // snapshot persists 0 there; the option name lives in the
            // captured payload.
            $this->assertSame('theme_mods_' . get_option('stylesheet'), $snapshot['snapshot']['object_id']);

            (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        } finally {
            remove_filter('stylesheet', $rename);
        }

        $this->assertSame('aabbcc', get_theme_mod('header_textcolor'));
    }

    /**
     * A Closure rule is the documented shape of the callable contract. Its
     * refusal must produce an explanation, not an "Object of class Closure
     * could not be converted to string" Error that the registrar re-throws
     * mid-batch, after earlier keys were already written.
     */
    public function test_a_closure_rule_refusal_explains_itself_instead_of_fataling(): void
    {
        $rules = static function (array $rules): array {
            $rules['header_textcolor'] = static fn ($value) => 'ok' === $value ? 'aabbcc' : null;
            return $rules;
        };
        add_filter('wpmcp_theme_mod_value_rules', $rules);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => 'nope' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $rules);
        }

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);
        $this->assertNotSame('', $out['result']['refused'][0]['detail']);
    }

    public function test_a_closure_rule_that_accepts_stores_the_value_it_returns(): void
    {
        $rules = static function (array $rules): array {
            $rules['my_pack_mod'] = static fn ($value) => 'ok' === $value ? 'coerced' : null;
            return $rules;
        };
        $allow = static fn (array $keys): array => array_merge($keys, [ 'my_pack_mod' ]);
        add_filter('wpmcp_theme_mod_value_rules', $rules);
        add_filter('wpmcp_theme_mod_allowlist', $allow);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'my_pack_mod' => 'ok' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $rules);
            remove_filter('wpmcp_theme_mod_allowlist', $allow);
        }

        $this->assertSame([ 'my_pack_mod' ], $out['result']['updated']);
        $this->assertSame('coerced', get_theme_mod('my_pack_mod'));
    }

    /**
     * Guard layer 3 must not fail open for exactly the keys guard layer 2 was
     * widened to admit. A filter-added key with no registered rule is refused,
     * not waved through on a markup sniff.
     */
    public function test_a_filter_added_key_with_no_registered_rule_is_refused(): void
    {
        $allow = static fn (array $keys): array => array_merge($keys, [ 'pack_untyped_mod' ]);
        add_filter('wpmcp_theme_mod_allowlist', $allow);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'pack_untyped_mod' => '" onmouseover=alert(1) x="' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_allowlist', $allow);
        }

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('no_validator', $out['result']['refused'][0]['reason']);
        $this->assertStringContainsString('wpmcp_theme_mod_value_rules', $out['result']['refused'][0]['detail']);
        $this->assertFalse(get_theme_mod('pack_untyped_mod'));
        $this->assertFalse($out['recoverable']);
    }

    public function test_a_rule_given_as_a_function_name_string_is_applied_not_ignored(): void
    {
        $rules = static function (array $rules): array {
            $rules['pack_text_mod'] = 'sanitize_text_field';
            return $rules;
        };
        $allow = static fn (array $keys): array => array_merge($keys, [ 'pack_text_mod' ]);
        add_filter('wpmcp_theme_mod_value_rules', $rules);
        add_filter('wpmcp_theme_mod_allowlist', $allow);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'pack_text_mod' => '<b>hello</b>' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $rules);
            remove_filter('wpmcp_theme_mod_allowlist', $allow);
        }

        $this->assertSame([ 'pack_text_mod' ], $out['result']['updated']);
        $this->assertSame('hello', get_theme_mod('pack_text_mod'));
    }

    public function test_an_unrecognised_string_rule_refuses_rather_than_falling_through(): void
    {
        $rules = static function (array $rules): array {
            $rules['pack_typo_mod'] = 'no_such_rule_name';
            return $rules;
        };
        $allow = static fn (array $keys): array => array_merge($keys, [ 'pack_typo_mod' ]);
        add_filter('wpmcp_theme_mod_value_rules', $rules);
        add_filter('wpmcp_theme_mod_allowlist', $allow);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'pack_typo_mod' => 'x' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $rules);
            remove_filter('wpmcp_theme_mod_allowlist', $allow);
        }

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('unknown_rule', $out['result']['refused'][0]['reason']);
    }

    /**
     * A pack that returns its own map instead of array_merge-ing must not be
     * able to strip the sanitizer off a core key: header_textcolor is echoed
     * bare inside a <style> block.
     */
    public function test_the_value_rules_filter_cannot_strip_a_core_validator(): void
    {
        $replace = static fn (array $rules): array => [ 'pack_only' => 'attachment_id' ];
        add_filter('wpmcp_theme_mod_value_rules', $replace);

        try {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => '</style><script>alert(1)</script>' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $replace);
        }

        $this->assertSame([], $out['result']['updated']);
        $this->assertSame('invalid_value', $out['result']['refused'][0]['reason']);
        $this->assertFalse(get_theme_mod('header_textcolor'));
    }

    /**
     * Arrays are enums in BOTH the check and the explanation. The old
     * rule_detail() guard skipped array-callables and then cast the array to
     * string, emitting an "Array to string conversion" warning.
     */
    public function test_an_array_rule_is_an_enum_in_the_check_and_in_the_explanation(): void
    {
        $rules = static function (array $rules): array {
            $rules['pack_enum_mod'] = [ 'WP_Query', 'get_posts' ];
            return $rules;
        };
        $allow = static fn (array $keys): array => array_merge($keys, [ 'pack_enum_mod' ]);
        add_filter('wpmcp_theme_mod_value_rules', $rules);
        add_filter('wpmcp_theme_mod_allowlist', $allow);

        try {
            $ok  = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'pack_enum_mod' => 'WP_Query' ] ],
            ]));
            $bad = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'pack_enum_mod' => 'nope' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_theme_mod_value_rules', $rules);
            remove_filter('wpmcp_theme_mod_allowlist', $allow);
        }

        $this->assertSame([ 'pack_enum_mod' ], $ok['result']['updated']);
        $this->assertSame('invalid_value', $bad['result']['refused'][0]['reason']);
        $this->assertStringContainsString('WP_Query', $bad['result']['refused'][0]['detail']);
    }

    /**
     * rule_detail() and the refusal text both promise an http(s) URL, so
     * scheme-relative, root-relative and fragment values must not sneak past
     * esc_url_raw()'s protocol-only check.
     */
    public function test_image_url_refuses_values_that_are_not_http_urls(): void
    {
        foreach ([ '//evil.tld/x.png', '/x.png', '#x' ] as $candidate) {
            $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'background_image' => $candidate ] ],
            ]));

            $this->assertSame([], $out['result']['updated'], $candidate);
            $this->assertSame('invalid_value', $out['result']['refused'][0]['reason'], $candidate);
        }

        $ok = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'background_image' => 'https://example.org/x.png' ] ],
        ]));
        $this->assertSame([ 'background_image' ], $ok['result']['updated']);
    }

    /**
     * Core's Custom_Image_Header always writes header_image_data alongside
     * header_image; get_custom_header() and the_header_image_tag() read the
     * companion for width/height/attachment_id. Leaving the old one in place
     * makes them describe the previous image against the new URL.
     */
    public function test_writing_header_image_clears_the_stale_header_image_data(): void
    {
        set_theme_mod('header_image_data', (object) [ 'attachment_id' => 42, 'width' => 10, 'height' => 5 ]);

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_image' => 'https://example.org/banner.png' ] ],
        ]));

        $this->assertSame([ 'header_image' ], $out['result']['updated']);
        $data = get_theme_mod('header_image_data');
        $this->assertNotSame(42, is_object($data) ? ($data->attachment_id ?? null) : null);
    }

    /**
     * The widget and Additional-CSS routes are pro-tier abilities, so naming
     * them unconditionally dead-ends a free-tier agent on exactly the refusal
     * that promised not to.
     */
    public function test_structural_refusals_name_free_tier_routes_and_flag_the_pro_ones(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [
                'nav_menu_locations' => [ 'primary' => 1 ],
                'sidebars_widgets'   => [],
                'custom_css_post_id' => 1,
            ] ],
        ]));

        $detail = [];
        foreach ($out['result']['refused'] as $refusal) {
            $detail[ $refusal['key'] ] = $refusal['detail'];
        }

        $this->assertStringContainsString('wpmcp/assign-menu-to-location', $detail['nav_menu_locations']);
        $this->assertStringContainsString('wpmcp/list-sidebar-widgets', $detail['sidebars_widgets']);
        $this->assertStringContainsString('pro tier', $detail['sidebars_widgets']);
        $this->assertStringContainsString('pro tier', $detail['custom_css_post_id']);
    }

    /**
     * The read half must use the shared Request_Log redaction primitive, not
     * a hand-copied fork of it: a stored object handed to the model unfiltered
     * (and an unbounded string) is exactly what that convention prevents.
     */
    public function test_get_mods_follows_the_shared_request_log_redaction_convention(): void
    {
        set_theme_mod('vendor_license', 'lic-should-never-be-echoed');
        set_theme_mod('some_object_mod', new \stdClass());
        set_theme_mod('long_mod', str_repeat('a', 500));

        $result = $this->integration->handle_read([ 'operation' => 'get-mods' ])['result'];

        $this->assertSame(Request_Log::REDACTED, $result['mods']['vendor_license']);
        $this->assertContains('vendor_license', $result['redacted']);
        $this->assertSame('[object]', $result['mods']['some_object_mod']);
        $this->assertLessThan(500, strlen($result['mods']['long_mod']));
    }

    /**
     * The Customizer lets an operator clear a mod; without a clear path an
     * agent can set custom_logo but never remove it. null is the explicit
     * clear sentinel (distinct from image_url's meaningful empty string).
     */
    public function test_null_clears_an_allowlisted_mod_and_is_reversible(): void
    {
        set_theme_mod('background_color', 'aabbcc');

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'background_color' => null ] ],
        ]));

        $this->assertSame([ 'background_color' ], $out['result']['cleared']);
        $this->assertFalse(get_theme_mod('background_color'));
        $this->assertTrue($out['recoverable']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertSame('aabbcc', get_theme_mod('background_color'));
    }

    public function test_clearing_a_non_allowlisted_key_is_still_refused(): void
    {
        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'nav_menu_locations' => null, 'nope_key' => null ] ],
        ]));

        $this->assertSame([], $out['result']['cleared']);
        $this->assertSame('structural', $out['result']['refused'][0]['reason']);
        $this->assertSame('not_allowlisted', $out['result']['refused'][1]['reason']);
    }

    /**
     * Definition of Done: dispatcher-level audit behavior verified, not just
     * governance and capability. Both the denied and the allowed synthetic
     * per-op decision must reach Governance_Audit_Log.
     */
    public function test_the_synthetic_set_mods_decision_is_recorded_in_the_audit_log(): void
    {
        $narrow = fn ($enabled, $name) => 'wpmcp/theme-set-mods' === $name ? false : $enabled;
        add_filter('wpmcp_ability_enabled', $narrow, 10, 2);

        try {
            $this->withWritesEnabled(fn () => $this->integration->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => 'aabbcc' ] ],
            ]));
        } finally {
            remove_filter('wpmcp_ability_enabled', $narrow);
        }

        $denied = $this->auditEntriesFor('wpmcp/theme-set-mods');
        $this->assertNotEmpty($denied);
        $this->assertFalse($denied[0]['allowed']);

        $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'set-mods',
            'args'      => [ 'values' => [ 'header_textcolor' => 'aabbcc' ] ],
        ]));

        $allowed = $this->auditEntriesFor('wpmcp/theme-set-mods');
        $this->assertTrue($allowed[0]['allowed']);
    }

    /** @return array<int, array<string, mixed>> newest-first entries for one ability. */
    private function auditEntriesFor(string $ability): array
    {
        return array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn (array $entry): bool => $ability === $entry['ability']
        ));
    }
}
