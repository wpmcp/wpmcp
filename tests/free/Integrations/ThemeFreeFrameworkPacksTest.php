<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The OceanWP, Neve, Hestia and Hello Elementor settings packs (issue #655)
 * on the theme integration pair, exercised against each theme's own storage
 * (its theme_mods, or Hello's per-setting options) with the template
 * filtered to the family, so the themes themselves need not be installed.
 */
class ThemeFreeFrameworkPacksTest extends \WP_UnitTestCase
{
    private const HELLO_OPTIONS = [
        'hello_elementor_settings_description_meta_tag',
        'hello_elementor_settings_skip_link',
        'hello_elementor_settings_header_footer',
        'hello_elementor_settings_page_title',
        'hello_elementor_settings_hello_style',
        'hello_elementor_settings_hello_theme',
    ];

    private string $family = 'oceanwp';

    private \Closure $template;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->template = fn () => $this->family;
        add_filter('template', $this->template);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option($this->mods_option());
        foreach (self::HELLO_OPTIONS as $name) {
            delete_option($name);
        }
        parent::tearDown();
    }

    private function mods_option(): string
    {
        return 'theme_mods_' . get_option('stylesheet');
    }

    private function write(array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => "set-{$this->family}-settings", 'args' => [ 'settings' => $settings ] ]);
    }

    private function read(): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => "get-{$this->family}-settings" ]);
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    public function test_each_pack_registers_only_while_its_family_is_active(): void
    {
        $families = [ 'oceanwp', 'neve', 'hestia', 'hello-elementor' ];
        foreach ($families as $family) {
            $this->family = $family;
            $names        = $this->names();
            $this->assertContains("get-{$family}-settings", $names, $family);
            $this->assertContains("set-{$family}-settings", $names, $family);
            foreach (array_diff($families, [ $family ]) as $other) {
                $this->assertNotContains("get-{$other}-settings", $names, "{$other} on {$family}");
            }
        }
        $this->family = 'twentytwentyfour';
        $this->assertNotContains('get-oceanwp-settings', $this->names());
    }

    public function test_oceanwp_read_write_and_exact_rollback(): void
    {
        $before = [ 'ocean_header_style' => 'minimal', 'body_typography' => [ 'font-weight' => '400' ], 'unrelated' => 'keep' ];
        update_option($this->mods_option(), $before);

        $read = $this->read();
        $this->assertSame('oceanwp', $read['result']['framework']);
        $this->assertSame('minimal', $read['result']['settings']['header_style']);
        $this->assertNull($read['result']['settings']['primary_color']);

        $out = $this->write([
            'primary_color'         => '#112233',
            'body_font_family'      => 'Georgia, serif',
            'body_font_size'        => 16,
            'container_width'       => '1300px',
            'header_style'          => 'center',
            'layout_style'          => 'boxed',
            'footer_widgets_columns' => 3,
            'footer_copyright_text' => '<em>Hi</em><script>x()</script>',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $mods = get_option($this->mods_option());
        $this->assertSame('#112233', $mods['ocean_primary_color']);
        $this->assertSame([ 'font-weight' => '400', 'font-family' => 'Georgia, serif', 'font-size' => 16 ], $mods['body_typography']);
        $this->assertSame(1300, $mods['ocean_main_container_width']);
        $this->assertSame('center', $mods['ocean_header_style']);
        $this->assertSame('boxed', $mods['ocean_main_layout_style']);
        $this->assertSame(3, $mods['ocean_footer_widgets_columns']);
        $this->assertSame('<em>Hi</em>x()', $mods['ocean_footer_copyright_text']);
        $this->assertSame('keep', $mods['unrelated']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option($this->mods_option()));
    }

    public function test_oceanwp_invalid_values_are_refused_before_any_snapshot(): void
    {
        $before = $this->snapshot_count();
        foreach ([ [ 'header_style' => 'sideways' ], [ 'footer_widgets_columns' => 5 ], [ 'primary_color' => 'red;}' ], [ 'container_width' => 50 ] ] as $bad) {
            $this->assertSame('invalid_setting_value', $this->write($bad)['error']['code'], (string) wp_json_encode($bad));
        }
        $this->assertSame('key_not_allowlisted', $this->write([ 'ocean_custom_css' => 'x' ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
        $this->assertFalse(get_option($this->mods_option()));
    }

    public function test_neve_palette_write_seeds_defaults_and_targets_the_active_palette(): void
    {
        $this->family = 'neve';
        update_option($this->mods_option(), [ 'neve_body_font_family' => 'Arial' ]);

        $out = $this->write([ 'nv-primary-accent' => '#ff0000', 'headings_font_family' => 'Inter', 'default_container_style' => 'full-width' ]);

        $this->assertArrayNotHasKey('error', $out);
        $mods = get_option($this->mods_option());
        $this->assertSame('base', $mods['neve_global_colors']['activePalette']);
        $this->assertSame('#ff0000', $mods['neve_global_colors']['palettes']['base']['colors']['nv-primary-accent']);
        $this->assertSame('#121212', $mods['neve_global_colors']['palettes']['base']['colors']['nv-dark-bg'], 'Unwritten colors keep the theme defaults');
        $this->assertSame('#00c2ff', $mods['neve_global_colors']['palettes']['darkMode']['colors']['nv-primary-accent']);
        $this->assertSame('Inter', $mods['neve_headings_font_family']);
        $this->assertSame('full-width', $mods['neve_default_container_style']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'neve_body_font_family' => 'Arial' ], get_option($this->mods_option()));

        $colors = [ 'activePalette' => 'darkMode', 'palettes' => [ 'base' => [ 'colors' => [ 'nv-site-bg' => '#ffffff' ] ], 'darkMode' => [ 'colors' => [ 'nv-site-bg' => '#000000' ] ] ] ];
        update_option($this->mods_option(), [ 'neve_global_colors' => $colors ]);
        $this->assertSame('#000000', $this->read()['result']['settings']['nv-site-bg'], 'The ACTIVE palette is the one read');

        $this->write([ 'nv-site-bg' => '#0a0a0a' ]);
        $mods = get_option($this->mods_option());
        $this->assertSame('#0a0a0a', $mods['neve_global_colors']['palettes']['darkMode']['colors']['nv-site-bg']);
        $this->assertSame('#ffffff', $mods['neve_global_colors']['palettes']['base']['colors']['nv-site-bg']);
        $this->assertSame('invalid_setting_value', $this->write([ 'default_container_style' => 'wide' ])['error']['code']);
    }

    public function test_hestia_read_write_and_rollback(): void
    {
        $this->family = 'hestia';
        update_option($this->mods_option(), [ 'accent_color' => '#e91e63' ]);
        $this->assertSame('#e91e63', $this->read()['result']['settings']['accent_color']);

        $out = $this->write([ 'accent_color' => '#00ff00', 'boxed_layout' => false, 'page_sidebar_layout' => 'sidebar-left', 'body_font' => 'Roboto' ]);

        $this->assertArrayNotHasKey('error', $out);
        $mods = get_option($this->mods_option());
        $this->assertSame('#00ff00', $mods['accent_color']);
        $this->assertFalse($mods['hestia_general_layout']);
        $this->assertSame('sidebar-left', $mods['hestia_page_sidebar_layout']);
        $this->assertSame('Roboto', $mods['hestia_body_font']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'accent_color' => '#e91e63' ], get_option($this->mods_option()));
        $this->assertSame('invalid_setting_value', $this->write([ 'blog_sidebar_layout' => 'top' ])['error']['code']);
    }

    public function test_hello_toggles_write_its_own_options_and_roll_back_across_them(): void
    {
        $this->family = 'hello-elementor';
        update_option('hello_elementor_settings_skip_link', 'true');

        $read = $this->read();
        $this->assertTrue($read['result']['settings']['disable_skip_link']);
        $this->assertFalse($read['result']['settings']['disable_page_title'], 'An unset toggle reads as off');

        $out = $this->write([ 'disable_skip_link' => false, 'disable_page_title' => true, 'disable_theme_style' => true ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('', get_option('hello_elementor_settings_skip_link'));
        $this->assertSame('true', get_option('hello_elementor_settings_page_title'));
        $this->assertSame('true', get_option('hello_elementor_settings_hello_theme'));
        $this->assertFalse(get_option('hello_elementor_settings_header_footer'), 'Options outside the batch are untouched');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('true', get_option('hello_elementor_settings_skip_link'));
        $this->assertFalse(get_option('hello_elementor_settings_page_title'));
        $this->assertFalse(get_option('hello_elementor_settings_hello_theme'));

        $this->assertSame('invalid_setting_value', $this->write([ 'disable_skip_link' => 'yes' ])['error']['code']);
    }

    public function test_hello_toggles_need_manage_options_like_the_theme_settings_page(): void
    {
        $this->family = 'hello-elementor';
        $user         = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($user)->add_cap('edit_theme_options');
        wp_set_current_user($user);

        $this->assertSame('operation_denied', $this->read()['error']['code']);
        $this->assertSame('operation_denied', $this->write([ 'disable_skip_link' => true ])['error']['code']);
    }
}
