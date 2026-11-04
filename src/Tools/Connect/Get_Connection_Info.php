<?php

namespace WPMCP\Tools\Connect;

use WPMCP\Connect\Client_Config_Generator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return everything an operator needs to connect an MCP client
 * (Claude Code, Cursor, Claude Desktop) to THIS site's wpmcp server, without
 * ever including a real credential.
 *
 * The endpoint is this site's REST base plus the route the WordPress 6.9+
 * Abilities/MCP integration mounts for a plugin named "wpmcp"
 * (wp-json/mcp/wpmcp-server), matching what this plugin's own README
 * documents as the connection URL. The route lives in one place -
 * Client_Config_Generator::ROUTE - shared with the Connection admin screen.
 *
 * Authorization is always a WordPress Application Password sent as HTTP
 * Basic auth (base64 of "username:application-password"); this tool never
 * generates, stores, or returns one. Every snippet below carries only a
 * placeholder string.
 */
class Get_Connection_Info
{
    public function handle(array $args): array
    {
        $endpoint          = Client_Config_Generator::endpoint();
        $auth_header_value = 'Basic BASE64_OF_username:application-password';

        return [
            'endpoint' => $endpoint,
            'auth'     => [
                'type' => 'WordPress Application Password (HTTP Basic)',
                'note' => 'Never share or paste a real Application Password into chat; generate the base64 value locally and store it only in your MCP client\'s own config file.',
                'steps' => [
                    'In wp-admin, go to Users -> Profile (your own user, or the user the agent should act as).',
                    'Scroll to the "Application Passwords" section.',
                    'Enter a name for the application (e.g. "Claude Code") and click "Add New Application Password".',
                    'Copy the generated password immediately; WordPress shows it only once.',
                    'Base64-encode "username:application-password" (with the literal space-separated password WordPress generated) to build the Authorization header value, e.g.: echo -n "your-username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64',
                ],
                'docs' => 'https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/',
            ],
            'clients'  => [
                'claude-code'    => [
                    'label'       => 'Claude Code',
                    'config_file' => '.mcp.json',
                    'snippet'     => $this->json_snippet($endpoint, $auth_header_value),
                    'note'        => 'Paste this block into your project\'s .mcp.json under "mcpServers".',
                ],
                'cursor'         => [
                    'label'       => 'Cursor',
                    'config_file' => '.cursor/mcp.json',
                    'snippet'     => $this->json_snippet($endpoint, $auth_header_value, false),
                    'note'        => 'Cursor reads the same "mcpServers" shape; the "type" field is optional for HTTP servers.',
                ],
                'claude-desktop' => [
                    'label'       => 'Claude Desktop',
                    'config_file' => 'claude_desktop_config.json',
                    'snippet'     => Client_Config_Generator::desktop_snippet($endpoint, $auth_header_value),
                    'note'        => 'Easiest: in wp-admin, open wpmcp -> Connection, create an application password, then download the wpmcp.mcpb bundle there and double-click it; it asks for the username and password and needs nothing else installed. For a manual setup, add this block to claude_desktop_config.json under "mcpServers", then fully quit and reopen Claude Desktop. Claude Desktop\'s config file only runs local servers and skips an entry with "url" or "headers", so this block starts the mcp-remote bridge through npx, which needs Node.js installed. Replace the placeholder in env with the base64 value from the auth steps.',
                ],
            ],
            'note'     => 'This tool never returns a real credential. The wpmcp -> Connection screen in wp-admin creates an application password and shows the same snippets filled in, plus the Claude Desktop bundle (.mcpb).',
        ];
    }

    private function json_snippet(string $endpoint, string $auth_header_value, bool $with_type = true): string
    {
        $server = $with_type
            ? [
                'type'    => 'http',
                'url'     => $endpoint,
                'headers' => ['Authorization' => $auth_header_value],
            ]
            : [
                'url'     => $endpoint,
                'headers' => ['Authorization' => $auth_header_value],
            ];

        return (string) wp_json_encode(
            ['mcpServers' => ['wpmcp' => $server]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }
}
