<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Plugin;

/**
 * Issue #879: the top-level wpmcp menu carried no icon, so WordPress drew the
 * default gear. The icon is a one-colour SVG data URI so the admin colour
 * scheme recolours it (wp-admin/js/svg-painter.js rewrites every fill="..."
 * attribute, and leaves strokes alone).
 */
class AdminMenuIconTest extends \WP_UnitTestCase
{
    /** @return array<int, mixed> */
    private function top_level_entry(): array
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Plugin::instance()->register_admin_menu();

        foreach ((array) $menu as $item) {
            // $item = [menu_title, capability, menu_slug, page_title, classes, hookname, icon_url]
            if ('wpmcp' === $item[2]) {
                return $item;
            }
        }
        $this->fail('Expected a top-level wpmcp menu entry.');
    }

    public function test_top_level_menu_passes_a_base64_svg_icon_not_the_default_gear(): void
    {
        $item = $this->top_level_entry();
        $icon = (string) ($item[6] ?? '');

        $this->assertNotSame('', $icon);
        $this->assertNotSame('dashicons-admin-generic', $icon);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $icon);

        $svg = base64_decode(substr($icon, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($svg);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('viewBox=', $svg);
    }

    public function test_icon_is_single_colour_and_recolourable_by_the_admin_scheme(): void
    {
        $item = $this->top_level_entry();
        $svg  = (string) base64_decode(substr((string) $item[6], strlen('data:image/svg+xml;base64,')), true);

        // Every shape carries a fill attribute svg-painter can rewrite, and
        // nothing relies on strokes, gradients, styles or scripts it cannot.
        $shapes = preg_match_all('/<path\b[^>]*>/', $svg, $paths);
        $this->assertGreaterThan(0, $shapes);
        foreach ($paths[0] as $path) {
            $this->assertMatchesRegularExpression('/\bfill="[^"]+"/', $path);
        }
        foreach (['stroke', 'Gradient', '<style', 'style=', '<script', 'url('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $svg);
        }
        preg_match_all('/\bfill="([^"]+)"/', $svg, $fills);
        $this->assertCount(1, array_unique($fills[1]), 'The icon must use one colour.');
    }

    public function test_menu_title_capability_and_slug_are_unchanged(): void
    {
        $item = $this->top_level_entry();

        $this->assertStringStartsWith(Plugin::BRAND, (string) $item[0]);
        $this->assertSame(\WPMCP\Admin\Management_Access::CAPABILITY, $item[1]);
        $this->assertSame(Plugin::BRAND, $item[3]);
    }
}
