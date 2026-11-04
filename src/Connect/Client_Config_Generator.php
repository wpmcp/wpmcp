<?php

namespace WPMCP\Connect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure generator for ready-to-paste MCP client configs (issue #76).
 *
 * Given a username and an Application Password, produces filled configs for
 * Claude Code, Claude Desktop, Cursor, VS Code, and a generic MCP JSON
 * block, all pointing at this site's MCP endpoint with HTTP Basic auth.
 *
 * Credential handling: both inputs live only in this call's memory. The
 * generator never reads or writes options, files, or logs; snippets carry
 * the credential solely as the encoded Basic header value. The Connection
 * admin screen calls this exactly once, in the same request that created
 * the password, to render the one-time reveal.
 *
 * The endpoint is this site's REST base plus the route the WordPress 6.9+
 * Abilities/MCP integration mounts for a plugin named "wpmcp"
 * (wp-json/mcp/wpmcp-server), matching the README's documented connection
 * URL. If a future core version mounts a different route, this constant is
 * the one place to update (Get_Connection_Info reuses it).
 */
class Client_Config_Generator
{
    public const ROUTE = '/wp-json/mcp/wpmcp-server';

    /** The env var the Claude Desktop entry hands its Authorization value in. */
    public const DESKTOP_AUTH_ENV = 'WPMCP_AUTH_HEADER';

    public static function endpoint(): string
    {
        return home_url(self::ROUTE);
    }

    public static function auth_header(string $username, string $password): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7617 HTTP Basic Authorization header encoding, not obfuscation.
        return 'Basic ' . base64_encode($username . ':' . $password);
    }

    /**
     * @return array<string, array{label: string, config_file: string, snippet: string, note: string, command?: string}>
     */
    public function configs(string $username, string $password): array
    {
        $endpoint = self::endpoint();
        $auth     = self::auth_header($username, $password);

        return [
            'claude-code'    => [
                'label'       => 'Claude Code',
                'config_file' => '.mcp.json',
                'snippet'     => $this->mcp_servers_snippet($endpoint, $auth),
                'command'     => sprintf(
                    'claude mcp add --transport http wpmcp %s --header "Authorization: %s"',
                    $endpoint,
                    $auth
                ),
                'note'        => __('Run the one-line command in your project, or paste the JSON block into .mcp.json.', 'wpmcp'),
            ],
            'claude-desktop' => [
                'label'       => 'Claude Desktop',
                'config_file' => 'claude_desktop_config.json',
                'snippet'     => self::desktop_snippet($endpoint, $auth),
                'note'        => __('Easiest: download the desktop bundle below and double-click it. Alternatively, merge this block into claude_desktop_config.json under "mcpServers", then fully quit and reopen Claude Desktop. Claude Desktop\'s config file only runs local servers, so this block starts the mcp-remote bridge to the endpoint, which needs Node.js installed.', 'wpmcp'),
            ],
            'cursor'         => [
                'label'       => 'Cursor',
                'config_file' => '.cursor/mcp.json',
                'snippet'     => $this->mcp_servers_snippet($endpoint, $auth),
                'note'        => __('Paste into .cursor/mcp.json (project) or ~/.cursor/mcp.json (global).', 'wpmcp'),
            ],
            'vscode'         => [
                'label'       => 'VS Code',
                'config_file' => '.vscode/mcp.json',
                'snippet'     => (string) wp_json_encode(
                    [
                        'servers' => [
                            'wpmcp' => [
                                'type'    => 'http',
                                'url'     => $endpoint,
                                'headers' => ['Authorization' => $auth],
                            ],
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ),
                'note'        => __('VS Code reads a top-level "servers" key from .vscode/mcp.json.', 'wpmcp'),
            ],
            'generic'        => [
                'label'       => __('Any MCP client (generic JSON)', 'wpmcp'),
                'config_file' => 'mcp.json',
                'snippet'     => $this->mcp_servers_snippet($endpoint, $auth),
                'note'        => __('The common "mcpServers" shape: a streamable-HTTP server with an Authorization header.', 'wpmcp'),
            ],
        ];
    }

    /**
     * The claude_desktop_config.json entry for this site (issue #580).
     *
     * Claude Desktop's config file only runs local stdio servers: an entry
     * with "type", "url" and "headers" is skipped as not a valid MCP server
     * configuration. Remote servers are added in the app as a custom
     * connector, which signs in with OAuth and cannot send an Authorization
     * header. So the config-file route runs mcp-remote, the stdio-to-HTTP
     * bridge, through npx. The header value travels in env and the argument
     * names it without a space: mcp-remote expands ${VAR} in --header, and a
     * space inside an argument is split on Windows.
     *
     * @param string $auth The Authorization header value (or a placeholder).
     */
    public static function desktop_snippet(string $endpoint, string $auth): string
    {
        return (string) wp_json_encode(
            [
                'mcpServers' => [
                    'wpmcp' => [
                        'command' => 'npx',
                        'args'    => [ '-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${' . self::DESKTOP_AUTH_ENV . '}' ],
                        'env'     => [ self::DESKTOP_AUTH_ENV => $auth ],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    private function mcp_servers_snippet(string $endpoint, string $auth): string
    {
        return (string) wp_json_encode(
            [
                'mcpServers' => [
                    'wpmcp' => [
                        'type'    => 'http',
                        'url'     => $endpoint,
                        'headers' => ['Authorization' => $auth],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }
}
