<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Delete_Theme;

class DeleteThemeTest extends \WP_UnitTestCase
{
    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Delete_Theme())->handle(['stylesheet' => 'twentytwentythree', 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_theme', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Theme())->handle(['stylesheet' => 'twentytwentythree']);
    }

    public function test_refuses_active_theme_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_theme', '__return_true');
        $active = get_stylesheet();

        $this->expectException(\RuntimeException::class);
        (new Delete_Theme())->handle(['stylesheet' => $active, 'confirm' => true]);
    }

    public function test_requires_stylesheet_argument_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_theme', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Theme())->handle(['confirm' => true]);
    }

    public function test_unknown_theme_errors_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_theme', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Delete_Theme())->handle(['stylesheet' => 'ghost-theme', 'confirm' => true]);
    }
}
