<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Connect\Bundle_Builder;

/**
 * Issue #76: the downloadable Claude Desktop bundle (.mcpb). The bundle is
 * fully self-contained — a manifest plus an embedded Node stdio-to-HTTP
 * proxy that runs on Claude Desktop's own bundled runtime, so connecting
 * needs no PATH, npx, or package install. It is also secret-free by
 * construction: build() takes only the endpoint; credentials are requested
 * from the user at install time via the manifest's sensitive user_config
 * fields (OS keychain), never written into the archive.
 */
class BundleBuilderTest extends \WP_UnitTestCase
{
    private const ENDPOINT = 'https://example.test/wp-json/mcp/wpmcp-server';

    private function build_and_read(): array
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is unavailable.');
        }

        $path = (new Bundle_Builder())->build(
            self::ENDPOINT,
            get_temp_dir() . 'wpmcp-test-bundle-' . wp_generate_password(8, false) . '.mcpb'
        );

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.mcpb', $path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $manifest_json = $zip->getFromName('manifest.json');
        $proxy         = $zip->getFromName('server/index.js');
        $count         = $zip->numFiles;
        $zip->close();
        unlink($path);

        $this->assertNotFalse($manifest_json, 'Bundle must contain manifest.json.');
        $this->assertNotFalse($proxy, 'Bundle must contain the embedded proxy at server/index.js.');
        $this->assertSame(2, $count, 'Bundle contains exactly the manifest and the proxy — nothing else.');

        $manifest = json_decode($manifest_json, true);
        $this->assertIsArray($manifest, 'manifest.json must be valid JSON.');

        return [$manifest, $proxy];
    }

    public function test_manifest_declares_a_node_server_run_from_inside_the_bundle(): void
    {
        [$manifest] = $this->build_and_read();

        $this->assertSame('wpmcp', $manifest['name']);
        $this->assertNotEmpty($manifest['version']);
        $this->assertNotEmpty($manifest['description']);
        $this->assertSame('node', $manifest['server']['type']);
        $this->assertSame('server/index.js', $manifest['server']['entry_point']);

        $mcp_config = $manifest['server']['mcp_config'];
        $this->assertSame('node', $mcp_config['command'], 'Runs on the host runtime directly — no npx.');
        $this->assertContains('${__dirname}/server/index.js', $mcp_config['args']);
        $this->assertSame(self::ENDPOINT, $mcp_config['env']['WPMCP_ENDPOINT']);
    }

    public function test_credentials_come_from_sensitive_user_config_never_the_archive(): void
    {
        [$manifest, $proxy] = $this->build_and_read();

        $mcp_config = $manifest['server']['mcp_config'];
        $this->assertSame('${user_config.username}', $mcp_config['env']['WPMCP_USERNAME']);
        $this->assertSame('${user_config.app_password}', $mcp_config['env']['WPMCP_APP_PASSWORD']);

        $this->assertTrue($manifest['user_config']['username']['required']);
        $this->assertTrue($manifest['user_config']['app_password']['required']);
        $this->assertTrue(
            $manifest['user_config']['app_password']['sensitive'],
            'The app password must be marked sensitive so the client stores it in the OS keychain.'
        );

        $this->assertStringContainsString('process.env.WPMCP_USERNAME', $proxy);
        $this->assertStringContainsString('process.env.WPMCP_APP_PASSWORD', $proxy);
    }

    public function test_proxy_is_self_contained_with_no_package_manager_dependency(): void
    {
        [, $proxy] = $this->build_and_read();

        $this->assertStringContainsString('process.env.WPMCP_ENDPOINT', $proxy);
        $this->assertStringContainsString('Authorization', $proxy);
        $this->assertStringNotContainsString('npx', $proxy);
        $this->assertStringNotContainsString('node_modules', $proxy);
    }
}
