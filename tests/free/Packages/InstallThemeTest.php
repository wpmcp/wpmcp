<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Install_Theme;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

class InstallThemeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * The capability is granted on the user rather than leaned on via the
     * administrator role: role capabilities live in a shared option that
     * other tests in the suite reshape, and this assertion is about the
     * handler's own check, not about role composition.
     */
    private function become_user_who_can(string $capability): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user    = new \WP_User($user_id);
        $user->add_cap($capability);
        wp_set_current_user($user_id);
    }

    private function other_installed_theme(): string
    {
        foreach (array_keys(wp_get_themes()) as $slug) {
            if ($slug !== get_stylesheet()) {
                return $slug;
            }
        }
        $this->markTestSkipped('Only one theme installed in this environment.');
    }

    public function test_requires_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Install_Theme())->handle([]);
    }

    public function test_rejects_non_wordpress_org_slug_formats(): void
    {
        foreach ([
            'https://example.com/evil.zip',
            '../../etc/passwd',
            'some/theme.zip',
            'theme with spaces',
        ] as $bad_slug) {
            try {
                (new Install_Theme())->handle(['slug' => $bad_slug]);
                $this->fail("Expected rejection for slug \"{$bad_slug}\".");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('slug', strtolower($e->getMessage()));
            }
        }
    }

    public function test_blocked_when_filesystem_not_direct(): void
    {
        add_filter('filesystem_method', fn () => 'ftpext');

        $this->expectException(\RuntimeException::class);
        (new Install_Theme())->handle(['slug' => 'astra']);
    }

    /**
     * install-theme is gated on install_themes, but the optional
     * activate: true step performs the work the Themes screen gates on
     * switch_themes. The extra capability is checked before anything is
     * downloaded.
     */
    public function test_activate_true_requires_switch_themes_capability(): void
    {
        wp_set_current_user(0);

        try {
            (new Install_Theme())->handle(['slug' => 'astra', 'activate' => true]);
            $this->fail('Expected a refusal without the switch_themes capability.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('switch_themes', $e->getMessage());
        }
    }

    public function test_activate_step_snapshots_template_and_stylesheet(): void
    {
        $this->become_user_who_can('switch_themes');
        $target = $this->other_installed_theme();

        $out = (new Install_Theme())->activate_installed($target, []);

        $this->assertTrue($out['activated']);
        $this->assertSame($target, get_stylesheet());
        $this->assertNotEmpty($out['operation_ids']);
        foreach ($out['operation_ids'] as $operation_id) {
            $this->assertNotNull(Snapshot_Store::get_by_operation($operation_id));
        }
    }

    public function test_activate_step_is_rollbackable(): void
    {
        $this->become_user_who_can('switch_themes');
        $target = $this->other_installed_theme();

        $original_stylesheet = get_option('stylesheet');
        $original_template   = get_option('template');

        $out = (new Install_Theme())->activate_installed($target, []);
        $this->assertSame($target, get_stylesheet());

        foreach ($out['operation_ids'] as $operation_id) {
            $restored = (new Rollback_Operation())->handle(['operation_id' => $operation_id]);
            $this->assertTrue($restored['restored']);
        }

        $this->assertSame($original_stylesheet, get_option('stylesheet'));
        $this->assertSame($original_template, get_option('template'));
    }
}
