<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Install_Plugin;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

class InstallPluginTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
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

    protected function tearDown(): void
    {
        deactivate_plugins(['hello.php'], true);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_requires_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Install_Plugin())->handle([]);
    }

    /**
     * Only bare wordpress.org plugin slugs are accepted (e.g. "akismet"),
     * never a URL, a path, or a slug containing a directory file component:
     * this tool must not become an arbitrary-zip-URL installer.
     */
    public function test_rejects_non_wordpress_org_slug_formats(): void
    {
        foreach ([
            'https://example.com/evil.zip',
            '../../etc/passwd',
            'some/plugin.php',
            'plugin with spaces',
        ] as $bad_slug) {
            try {
                (new Install_Plugin())->handle(['slug' => $bad_slug]);
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
        (new Install_Plugin())->handle(['slug' => 'contact-form-7']);
    }

    /**
     * install-plugin is gated on install_plugins, but the optional
     * activate: true step performs the work the Plugins screen gates on
     * activate_plugins. The extra capability is checked before anything is
     * downloaded, so a caller who may install but not activate gets a clean
     * refusal rather than a half-applied request.
     */
    public function test_activate_true_requires_activate_plugins_capability(): void
    {
        wp_set_current_user(0);

        try {
            (new Install_Plugin())->handle(['slug' => 'akismet', 'activate' => true]);
            $this->fail('Expected a refusal without the activate_plugins capability.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('activate_plugins', $e->getMessage());
        }
    }

    public function test_activate_step_snapshots_active_plugins(): void
    {
        $this->become_user_who_can('activate_plugins');

        $before = (array) get_option('active_plugins', []);
        $this->assertNotContains('hello.php', $before);

        $out = (new Install_Plugin())->activate_installed('hello.php', []);

        $this->assertTrue($out['activated']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertContains('hello.php', (array) get_option('active_plugins', []));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_activate_step_is_rollbackable(): void
    {
        $this->become_user_who_can('activate_plugins');

        $before = (array) get_option('active_plugins', []);

        $out = (new Install_Plugin())->activate_installed('hello.php', []);
        $this->assertContains('hello.php', (array) get_option('active_plugins', []));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($restored['restored']);
        $this->assertSame($before, (array) get_option('active_plugins', []));
    }
}
