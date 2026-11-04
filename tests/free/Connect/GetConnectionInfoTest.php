<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Tools\Connect\Get_Connection_Info;

class GetConnectionInfoTest extends \WP_UnitTestCase
{
    public function test_endpoint_contains_the_site_host(): void
    {
        $out = (new Get_Connection_Info())->handle([]);

        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        $this->assertStringContainsString($host, $out['endpoint']);
        $this->assertStringContainsString('/wp-json/mcp/wpmcp-server', $out['endpoint']);
    }

    public function test_claude_code_snippet_has_an_authorization_placeholder_and_no_real_secret(): void
    {
        $out = (new Get_Connection_Info())->handle([]);

        $snippet = $out['clients']['claude-code']['snippet'];

        $this->assertStringContainsString('Authorization', $snippet);
        $this->assertStringContainsString('Basic', $snippet);
        $this->assertStringContainsString($out['endpoint'], $snippet);

        // Only placeholder text stands in for the credential; no concrete
        // base64-looking Basic auth value is ever emitted.
        $this->assertStringContainsString('BASE64_OF_username:application-password', $snippet);
        $this->assertDoesNotMatchRegularExpression(
            '/Basic [A-Za-z0-9+\/]{8,}={0,2}/',
            (string) wp_json_encode($out)
        );
    }

    /**
     * Issue #580: claude_desktop_config.json runs local (stdio) servers
     * only, and skips an entry with "type"/"url"/"headers" as not a valid
     * MCP server configuration. The snippet must be a command entry that
     * bridges to the HTTP endpoint, and must still carry only a placeholder.
     */
    public function test_claude_desktop_snippet_is_a_stdio_entry_desktop_accepts(): void
    {
        $out     = (new Get_Connection_Info())->handle([]);
        $desktop = $out['clients']['claude-desktop'];
        $server  = json_decode($desktop['snippet'], true)['mcpServers']['wpmcp'] ?? null;

        $this->assertIsArray($server, $desktop['snippet']);
        $this->assertArrayNotHasKey('url', $server, 'Claude Desktop skips url entries in its config file.');
        $this->assertArrayNotHasKey('type', $server);
        $this->assertArrayNotHasKey('headers', $server);
        $this->assertSame('npx', $server['command']);
        $this->assertContains('mcp-remote', $server['args']);
        $this->assertContains($out['endpoint'], $server['args']);
        $this->assertContains('Authorization:${WPMCP_AUTH_HEADER}', $server['args'], 'No space in the header argument: mcp-remote splits it on Windows.');
        $this->assertSame('Basic BASE64_OF_username:application-password', $server['env']['WPMCP_AUTH_HEADER']);

        $this->assertStringContainsString('Node.js', $desktop['note']);
        $this->assertStringContainsString('.mcpb', $desktop['note'], 'The note must point at the bundle the Connection screen offers.');
        $this->assertStringNotContainsString('deferred', strtolower((string) wp_json_encode($out)), 'The bundle ships (issue #76); nothing about it is deferred.');
    }

    /** Claude Code and Cursor read url entries, so their snippets keep the HTTP shape. */
    public function test_claude_code_and_cursor_snippets_keep_the_http_shape(): void
    {
        $out = (new Get_Connection_Info())->handle([]);

        foreach (['claude-code', 'cursor'] as $client) {
            $server = json_decode($out['clients'][ $client ]['snippet'], true)['mcpServers']['wpmcp'];
            $this->assertSame($out['endpoint'], $server['url'], $client);
            $this->assertArrayNotHasKey('command', $server, $client);
        }
    }
}
