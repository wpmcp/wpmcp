<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Connect\Client_Config_Generator;

/**
 * Issue #76: the pure config generator behind the Connection admin screen.
 * Given a username and a freshly created Application Password (both held in
 * memory only — the generator never reads or writes storage), it produces
 * ready-to-paste configs for Claude Code, Claude Desktop, Cursor, VS Code,
 * and a generic MCP JSON block, all pointing at this site's MCP endpoint
 * with HTTP Basic auth.
 */
class ClientConfigGeneratorTest extends \WP_UnitTestCase
{
    public function test_endpoint_is_the_mcp_adapter_route_on_this_site(): void
    {
        $this->assertSame(
            home_url('/wp-json/mcp/wpmcp-server'),
            Client_Config_Generator::endpoint()
        );
    }

    public function test_auth_header_is_basic_base64_of_login_colon_password(): void
    {
        $this->assertSame(
            'Basic ' . base64_encode('alice:abcd efgh ijkl mnop qrst uvwx'),
            Client_Config_Generator::auth_header('alice', 'abcd efgh ijkl mnop qrst uvwx')
        );
    }

    public function test_configs_cover_all_five_clients_with_valid_credentialed_snippets(): void
    {
        $configs = (new Client_Config_Generator())->configs('alice', 'secret-pass');
        $encoded = base64_encode('alice:secret-pass');

        foreach (['claude-code', 'claude-desktop', 'cursor', 'vscode', 'generic'] as $id) {
            $this->assertArrayHasKey($id, $configs, "Missing config for client '$id'.");
            $client = $configs[$id];
            $this->assertNotEmpty($client['label']);
            $this->assertNotEmpty($client['config_file']);
            $this->assertNotEmpty($client['note']);

            $decoded = json_decode($client['snippet'], true);
            $this->assertIsArray($decoded, "Snippet for '$id' must be valid JSON.");
            $this->assertStringContainsString(Client_Config_Generator::endpoint(), $client['snippet']);
            $this->assertStringContainsString($encoded, $client['snippet']);
            $this->assertStringNotContainsString(
                'secret-pass',
                $client['snippet'],
                'Snippets carry the credential only in its encoded Basic form.'
            );
        }

        $this->assertArrayHasKey('mcpServers', json_decode($configs['generic']['snippet'], true));
        $this->assertArrayHasKey('mcpServers', json_decode($configs['claude-code']['snippet'], true));
        $this->assertArrayHasKey('mcpServers', json_decode($configs['claude-desktop']['snippet'], true));
        $this->assertArrayHasKey('mcpServers', json_decode($configs['cursor']['snippet'], true));
        $this->assertArrayHasKey(
            'servers',
            json_decode($configs['vscode']['snippet'], true),
            'VS Code reads .vscode/mcp.json with a top-level "servers" key.'
        );
    }

    public function test_claude_code_config_includes_a_one_line_cli_command(): void
    {
        $configs = (new Client_Config_Generator())->configs('alice', 'secret-pass');

        $this->assertArrayHasKey('command', $configs['claude-code']);
        $this->assertStringContainsString('claude mcp add', $configs['claude-code']['command']);
        $this->assertStringContainsString(Client_Config_Generator::endpoint(), $configs['claude-code']['command']);
    }
}
