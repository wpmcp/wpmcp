<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Kadence settings pack (issue #288) on the theme integration pair,
 * exercised against Kadence's own storage (the kadence_global_palette JSON
 * option and the active theme's theme_mods) with the template filtered to
 * kadence, so the theme itself need not be installed.
 */
class ThemeFrameworkPacksTest extends \WP_UnitTestCase
{
    private \Closure $kadence;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->kadence = static fn () => 'kadence';
        add_filter('template', $this->kadence);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->kadence);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('kadence_global_palette');
        delete_option($this->mods_option());
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function mods_option(): string
    {
        return 'theme_mods_' . get_option('stylesheet');
    }

    private function write(array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-kadence-settings', 'args' => [ 'settings' => $settings ] ]);
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    public function test_pack_registers_only_while_kadence_is_the_active_family(): void
    {
        $this->assertContains('get-kadence-settings', $this->names());
        $this->assertContains('set-kadence-settings', $this->names());
        $this->assertNotContains('get-astra-settings', $this->names());

        remove_filter('template', $this->kadence);
        $this->assertNotContains('get-kadence-settings', $this->names());
        add_filter('template', $this->kadence);
    }

    public function test_other_family_packs_are_skipped_while_kadence_is_active(): void
    {
        Gate::set_pro_for_tests(true);
        $names = $this->names();
        $this->assertNotContains('get-generatepress-settings', $names);
        $this->assertNotContains('get-blocksy-settings', $names);
    }

    public function test_read_reports_stored_values_and_null_for_theme_defaults(): void
    {
        update_option($this->mods_option(), [ 'content_width' => [ 'size' => 1100, 'unit' => 'px' ], 'header_sticky' => 'main' ]);
        update_option('kadence_global_palette', wp_json_encode([
            'palette'        => [ [ 'color' => '#000000', 'slug' => 'palette1', 'name' => 'Palette Color 1' ] ],
            'second-palette' => [ [ 'color' => '#abcdef', 'slug' => 'palette1', 'name' => 'Palette Color 1' ] ],
            'active'         => 'second-palette',
        ]));

        $out = (new Theme_Integration())->handle_read([ 'operation' => 'get-kadence-settings' ]);

        $settings = $out['result']['settings'];
        $this->assertSame('kadence', $out['result']['framework']);
        $this->assertSame(1100, $settings['content_width']);
        $this->assertSame('main', $settings['header_sticky']);
        $this->assertSame('#abcdef', $settings['palette1'], 'The ACTIVE palette list is the one read');
        $this->assertNull($settings['palette2']);
        $this->assertNull($settings['base_font_family']);
        $this->assertContains('footer_html_content', $out['result']['allowlist']);
    }

    public function test_mod_write_seeds_the_theme_default_and_rolls_back_exactly(): void
    {
        $before = [ 'unrelated' => 'keep', 'header_sticky' => 'no' ];
        update_option($this->mods_option(), $before);

        $out = $this->write([
            'base_font_family'          => 'Georgia, serif',
            'content_width'             => '1400',
            'transparent_header_enable' => true,
            'footer_background'         => 'palette3',
            'footer_html_content'       => '<strong>Hi</strong><script>x()</script>',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $mods = get_option($this->mods_option());
        $this->assertSame('Georgia, serif', $mods['base_font']['family']);
        $this->assertSame([ 'desktop' => 17 ], $mods['base_font']['size'], 'A sub-field write keeps the rest of Kadence\'s default base font');
        $this->assertSame([ 'size' => 1400, 'unit' => 'px' ], $mods['content_width']);
        $this->assertTrue($mods['transparent_header_enable']);
        $this->assertSame('palette3', $mods['footer_wrap_background']['desktop']['color']);
        $this->assertSame('<strong>Hi</strong>x()', $mods['footer_html_content']);
        $this->assertSame('keep', $mods['unrelated']);
        $this->assertFalse(get_option('kadence_global_palette'), 'A mods-only batch leaves the palette option alone');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option($this->mods_option()));
    }

    public function test_palette_write_spans_both_stores_and_rollback_removes_the_created_option(): void
    {
        update_option($this->mods_option(), [ 'header_sticky' => 'no' ]);

        $out = $this->write([ 'palette2' => '#123456', 'header_sticky' => 'top_main' ]);

        $this->assertArrayNotHasKey('error', $out);
        $palette = json_decode((string) get_option('kadence_global_palette'), true);
        $this->assertSame('palette', $palette['active']);
        $this->assertSame('#123456', $palette['palette'][1]['color']);
        $this->assertSame('#2B6CB0', $palette['palette'][0]['color'], 'Unwritten entries keep Kadence\'s defaults');
        $this->assertCount(15, $palette['third-palette']);
        $this->assertSame('top_main', get_option($this->mods_option())['header_sticky']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('kadence_global_palette'));
        $this->assertSame([ 'header_sticky' => 'no' ], get_option($this->mods_option()));
    }

    public function test_palette_write_targets_the_active_palette_list(): void
    {
        $stored = [
            'palette'        => [ [ 'color' => '#000000', 'slug' => 'palette1', 'name' => 'A' ] ],
            'second-palette' => [ [ 'color' => '#111111', 'slug' => 'palette1', 'name' => 'A' ] ],
            'active'         => 'second-palette',
        ];
        update_option('kadence_global_palette', wp_json_encode($stored));

        $out = $this->write([ 'palette1' => 'rgba(1,2,3,0.5)' ]);

        $palette = json_decode((string) get_option('kadence_global_palette'), true);
        $this->assertSame('rgba(1,2,3,0.5)', $palette['second-palette'][0]['color']);
        $this->assertSame('#000000', $palette['palette'][0]['color']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(wp_json_encode($stored), get_option('kadence_global_palette'));
    }

    public function test_invalid_batches_are_refused_before_any_write_or_snapshot(): void
    {
        update_option($this->mods_option(), [ 'header_sticky' => 'no' ]);
        $before = $this->snapshot_count();

        $cases = [
            'key_not_allowlisted'   => [ 'header_desktop_items' => 'x' ],
            'invalid_setting_value' => [ 'content_width' => 1200, 'palette1' => 'red;}body{display:none' ],
        ];
        foreach ($cases as $code => $settings) {
            $this->assertSame($code, $this->write($settings)['error']['code']);
        }
        foreach ([ [ 'header_sticky' => 'sometimes' ], [ 'transparent_header_enable' => 'yes' ], [ 'palette1' => 'palette2' ], [ 'base_font_family' => 'x;}' ], [ 'content_width' => 99 ] ] as $bad) {
            $this->assertSame('invalid_setting_value', $this->write($bad)['error']['code'], (string) wp_json_encode($bad));
        }

        $this->assertSame($before, $this->snapshot_count());
        $this->assertSame([ 'header_sticky' => 'no' ], get_option($this->mods_option()));
        $this->assertFalse(get_option('kadence_global_palette'));
    }

    public function test_allowlist_filter_narrows_but_cannot_widen(): void
    {
        $narrow = static function (array $keys, string $family): array {
            return 'kadence' === $family ? [ 'content_width' => $keys['content_width'], 'made_up' => [] ] : $keys;
        };
        add_filter('wpmcp_theme_framework_pack_allowlist', $narrow, 10, 2);

        $read    = (new Theme_Integration())->handle_read([ 'operation' => 'get-kadence-settings' ]);
        $refused = $this->write([ 'header_sticky' => 'main' ]);
        $unknown = $this->write([ 'made_up' => 'x' ]);

        remove_filter('wpmcp_theme_framework_pack_allowlist', $narrow, 10);
        $this->assertSame([ 'content_width' ], $read['result']['allowlist']);
        $this->assertSame('key_not_allowlisted', $refused['error']['code']);
        $this->assertSame('key_not_allowlisted', $unknown['error']['code']);
    }

    public function test_write_is_default_off_and_needs_edit_theme_options(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $off = $this->write([ 'content_width' => 1200 ]);
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('operation_disabled', $off['error']['code']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $read = (new Theme_Integration())->handle_read([ 'operation' => 'get-kadence-settings' ]);
        $this->assertSame('operation_denied', $read['error']['code']);
    }

    public function test_write_fires_the_framework_cache_refresh_action(): void
    {
        $seen = [];
        $spy  = static function (string $family) use (&$seen): void {
            $seen[] = $family;
        };
        add_action('wpmcp_theme_framework_cache_refresh', $spy);
        $this->write([ 'content_narrow_width' => 900 ]);
        remove_action('wpmcp_theme_framework_cache_refresh', $spy);

        $this->assertSame([ 'kadence' ], $seen);
    }
}
