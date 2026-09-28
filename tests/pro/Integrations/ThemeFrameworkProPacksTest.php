<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The GeneratePress and Blocksy settings packs (issue #288), exercised
 * against each theme's own storage (the generate_settings option and the
 * active theme's theme_mods) with the template filtered to that family.
 */
class ThemeFrameworkProPacksTest extends \WP_UnitTestCase
{
    /** @var callable|null */
    private $template = null;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
    }

    protected function tearDown(): void
    {
        if (null !== $this->template) {
            remove_filter('template', $this->template);
        }
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('generate_settings');
        delete_option($this->mods_option());
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function activate(string $family): void
    {
        $this->template = static fn () => $family;
        add_filter('template', $this->template);
    }

    private function mods_option(): string
    {
        return 'theme_mods_' . get_option('stylesheet');
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function write(string $family, array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => "set-{$family}-settings", 'args' => [ 'settings' => $settings ] ]);
    }

    private function read(string $family): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => "get-{$family}-settings" ])['result'];
    }

    public function test_packs_register_per_family_and_need_the_paid_tier(): void
    {
        $this->activate('generatepress');
        $this->assertContains('set-generatepress-settings', $this->names());
        $this->assertNotContains('set-blocksy-settings', $this->names());

        Gate::set_pro_for_tests(false);
        $this->assertNotContains('get-generatepress-settings', $this->names());
        $this->assertSame('unknown_operation', $this->write('generatepress', [ 'container_width' => 1100 ])['error']['code']);
        Gate::set_pro_for_tests(true);

        remove_filter('template', $this->template);
        $this->activate('blocksy');
        $this->assertContains('get-blocksy-settings', $this->names());
        $this->assertNotContains('get-generatepress-settings', $this->names());
    }

    public function test_generatepress_write_updates_its_option_and_rolls_back_exactly(): void
    {
        $this->activate('generatepress');
        $before = [ 'container_width' => 1200, 'hide_title' => '1' ];
        update_option('generate_settings', $before);
        update_option('generate_dynamic_css_output', 'body{}');

        $out = $this->write('generatepress', [
            'accent'                => '#ff0000',
            'link_color'            => 'var(--accent)',
            'container_width'       => 1000,
            'body_font_family'      => 'Arial, sans-serif',
            'body_font_size'        => 18,
            'heading_font_family'   => 'Georgia',
            'footer_widget_setting' => 4,
            'nav_position_setting'  => 'nav-below-header',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $stored = get_option('generate_settings');
        $this->assertSame('1', $stored['hide_title']);
        $this->assertSame(1000, $stored['container_width']);
        $this->assertSame('var(--accent)', $stored['link_color']);
        $this->assertSame('4', $stored['footer_widget_setting']);
        $this->assertCount(7, $stored['global_colors'], 'The default palette is seeded whole');
        $accent = array_values(array_filter($stored['global_colors'], static fn ($c) => 'accent' === $c['slug']));
        $this->assertSame('#ff0000', $accent[0]['color']);
        $this->assertSame([ 'selector' => 'body', 'module' => 'core', 'group' => 'base', 'fontFamily' => 'Arial, sans-serif', 'fontSize' => 18 ], $stored['typography'][0]);
        $this->assertSame('all-headings', $stored['typography'][1]['selector']);
        $this->assertFalse(get_option('generate_dynamic_css_output'), 'The cached dynamic CSS is dropped');

        $read = $this->read('generatepress');
        $this->assertSame('#ff0000', $read['settings']['accent']);
        $this->assertSame(18, $read['settings']['body_font_size']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('generate_settings'));
    }

    public function test_generatepress_edits_an_existing_typography_rule_in_place(): void
    {
        $this->activate('generatepress');
        update_option('generate_settings', [ 'typography' => [ [ 'selector' => 'body', 'module' => 'core', 'fontSize' => 15, 'fontWeight' => '400' ] ] ]);

        $this->write('generatepress', [ 'body_font_family' => 'Verdana' ]);

        $this->assertSame(
            [ [ 'selector' => 'body', 'module' => 'core', 'fontSize' => 15, 'fontWeight' => '400', 'fontFamily' => 'Verdana' ] ],
            get_option('generate_settings')['typography']
        );
    }

    public function test_generatepress_refuses_bad_values_without_a_snapshot(): void
    {
        $this->activate('generatepress');
        global $wpdb;
        $count = static fn (): int => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
        $before = $count();

        foreach ([ [ 'link_color' => 'var(--x);}' ], [ 'footer_widget_setting' => 9 ], [ 'header_layout_setting' => 'wide' ], [ 'accent' => 'var(--accent)' ] ] as $bad) {
            $this->assertSame('invalid_setting_value', $this->write('generatepress', $bad)['error']['code'], (string) wp_json_encode($bad));
        }
        $this->assertSame('key_not_allowlisted', $this->write('generatepress', [ 'font_manager' => 'x' ])['error']['code']);
        $this->assertSame($before, $count());
        $this->assertFalse(get_option('generate_settings'));
    }

    public function test_blocksy_write_updates_theme_mods_and_rolls_back_exactly(): void
    {
        $this->activate('blocksy');
        $before = [ 'colorPalette' => [ 'color1' => [ 'color' => '#2872fa' ] ], 'other' => 1 ];
        update_option($this->mods_option(), $before);
        $refreshed = 0;
        $spy       = static function () use (&$refreshed): void {
            $refreshed++;
        };
        add_action('blocksy:dynamic-css:refresh-caches', $spy);

        $out = $this->write('blocksy', [
            'color2'         => '#111111',
            'linkHoverColor' => 'var(--theme-palette-color-3)',
            'rootFontSize'   => '18px',
            'maxSiteWidth'   => 1400,
        ]);

        remove_action('blocksy:dynamic-css:refresh-caches', $spy);
        $this->assertArrayNotHasKey('error', $out);
        $mods = get_option($this->mods_option());
        $this->assertSame([ 'color1' => [ 'color' => '#2872fa' ], 'color2' => [ 'color' => '#111111' ] ], $mods['colorPalette']);
        $this->assertSame([ 'hover' => [ 'color' => 'var(--theme-palette-color-3)' ] ], $mods['linkColor']);
        $this->assertSame('18px', $mods['rootTypography']['size']);
        $this->assertSame('System Default', $mods['rootTypography']['family'], 'The base font is seeded with the theme default');
        $this->assertSame(1400, $mods['maxSiteWidth']);
        $this->assertSame(1, $refreshed);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option($this->mods_option()));
    }

    public function test_blocksy_header_and_footer_edit_the_saved_builder_placements(): void
    {
        $this->activate('blocksy');
        $this->assertSame('setting_unavailable', $this->write('blocksy', [ 'footer_copyright_text' => 'Hi' ])['error']['code'], 'Never-saved placements are refused');

        update_option($this->mods_option(), [
            'header_placements' => [ 'current_section' => 'type-1', 'sections' => [ [ 'id' => 'type-1', 'items' => [ [ 'id' => 'logo', 'values' => [ 'logoMaxHeight' => 50 ] ] ] ] ] ],
            'footer_placements' => [ 'current_section' => 'type-1', 'sections' => [ [ 'id' => 'type-1', 'items' => [] ] ] ],
        ]);

        $out = $this->write('blocksy', [ 'header_logo_height' => 70, 'footer_copyright_text' => '&copy; {current_year} Acme' ]);

        $this->assertArrayNotHasKey('error', $out);
        $mods = get_option($this->mods_option());
        $this->assertSame([ 'logoMaxHeight' => 70 ], $mods['header_placements']['sections'][0]['items'][0]['values']);
        $this->assertSame([ 'id' => 'copyright', 'values' => [ 'copyright_text' => '&copy; {current_year} Acme' ] ], $mods['footer_placements']['sections'][0]['items'][0]);
        $read = $this->read('blocksy');
        $this->assertSame(70, $read['settings']['header_logo_height']);
    }
}
