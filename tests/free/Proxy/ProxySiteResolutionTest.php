<?php

namespace WPMCP\Tests\Free\Proxy;

use PHPUnit\Framework\TestCase;

use function WPMCP\Proxy\describe_http_failure;
use function WPMCP\Proxy\resolve_sites;
use function WPMCP\Proxy\select_site;

/**
 * The stdio-to-HTTP proxy (issue #77) resolves N named sites from env
 * config and routes by site name; auth failures produce clear errors. The
 * script is zero-dependency, so this test includes it directly (guarded by
 * WPMCP_PROXY_NO_RUN) and exercises the pure functions with env snapshots,
 * never putenv().
 */
class ProxySiteResolutionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! defined('WPMCP_PROXY_NO_RUN')) {
            define('WPMCP_PROXY_NO_RUN', true);
        }
        require_once dirname(__DIR__, 3) . '/bin/wpmcp-proxy.php';
    }

    public function test_resolves_sites_from_json_env(): void
    {
        $sites = resolve_sites([
            'WPMCP_SITES' => json_encode([
                'prod'    => [ 'url' => 'https://a.example/', 'user' => 'admin', 'app_password' => 'pw-a' ],
                'staging' => [ 'url' => 'https://b.example', 'user' => 'bot', 'app_password' => 'pw-b' ],
            ]),
        ]);

        $this->assertCount(2, $sites);
        $this->assertSame('https://a.example', $sites['prod']['url'], 'trailing slash is normalized away');
        $this->assertSame('bot', $sites['staging']['user']);
    }

    public function test_resolves_sites_from_per_variable_env(): void
    {
        $sites = resolve_sites([
            'WPMCP_SITE_PROD_URL'          => 'https://a.example',
            'WPMCP_SITE_PROD_USER'         => 'admin',
            'WPMCP_SITE_PROD_APP_PASSWORD' => 'pw',
        ]);

        $this->assertSame([ 'prod' ], array_keys($sites));
        $this->assertSame('admin', $sites['prod']['user']);
    }

    public function test_single_site_is_selected_without_wpmcp_site(): void
    {
        $sites = [ 'only' => [ 'url' => 'https://a.example', 'user' => 'u', 'app_password' => 'p' ] ];

        $this->assertSame('https://a.example', select_site($sites, [])['url']);
    }

    public function test_ambiguous_selection_names_the_candidates(): void
    {
        $sites = [
            'a' => [ 'url' => 'https://a.example', 'user' => 'u', 'app_password' => 'p' ],
            'b' => [ 'url' => 'https://b.example', 'user' => 'u', 'app_password' => 'p' ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set WPMCP_SITE');
        select_site($sites, []);
    }

    public function test_unknown_site_name_is_a_clear_error(): void
    {
        $sites = [ 'prod' => [ 'url' => 'https://a.example', 'user' => 'u', 'app_password' => 'p' ] ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown site "nope"');
        select_site($sites, [ 'WPMCP_SITE' => 'nope' ]);
    }

    public function test_auth_failure_message_mentions_application_passwords(): void
    {
        $message = describe_http_failure(401, 'https://a.example');

        $this->assertStringContainsString('Authentication failed', $message);
        $this->assertStringContainsString('application password', $message);
    }
}
