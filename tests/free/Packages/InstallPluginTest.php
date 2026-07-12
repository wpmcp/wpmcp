<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Install_Plugin;

class InstallPluginTest extends \WP_UnitTestCase
{
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
}
