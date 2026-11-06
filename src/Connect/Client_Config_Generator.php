<?php

namespace WPMCP\Connect;

use WPMCP\Auth\OAuth_Config;

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

    /** The line that switches OAuth on in wp-config.php. */
    public const OAUTH_CONSTANT_LINE = "define('WPMCP_OAUTH_ENABLED', true);";

    /**
     * How to switch OAuth on, in words that read the same on the Connection
     * screen, in get-connection-info and on the authorization address while
     * OAuth is off (issues #720 and #581). It names the real switch and
     * where it lives, never a key of the tool's result.
     */
    public static function oauth_switch_on(): string
    {
        return sprintf(
            /* translators: %s: the PHP line to add, define('WPMCP_OAUTH_ENABLED', true); */
            __('There is no OAuth switch in wp-admin: a site administrator switches it on by adding %s to wp-config.php, above the line "That\'s all, stop editing!" (the steps to switch OAuth on are listed under "To switch OAuth on" in WP MCP, Connection, in wp-admin). Or use the desktop bundle or config file from that screen instead, which use an application password.', 'wpmcp'),
            self::OAUTH_CONSTANT_LINE
        );
    }

    /**
     * The Claude Desktop / claude.ai custom connector (issue #581).
     *
     * The connector signs in with OAuth and cannot send an Authorization
     * header, and OAuth is off unless the site opts in, so on a default
     * install it fails with "Couldn't register with WP MCP's sign-in
     * service". OAuth stays off by default; this states the requirement,
     * the current state, and exactly how to switch it on, for the
     * get-connection-info tool and the Connection screen alike.
     *
     * @return array{label: string, url: string, oauth_enabled: bool, note: string, enable_oauth: string[], steps: string[]}
     */
    public static function custom_connector(): array
    {
        $enabled = OAuth_Config::is_enabled();

        return [
            'label'         => __('Claude Desktop and claude.ai: custom connector', 'wpmcp'),
            'url'           => self::endpoint(),
            'oauth_enabled' => $enabled,
            'note'          => $enabled
                ? __('OAuth is on for this site, so the custom connector can sign in: add it with the steps below and approve the sign-in when your browser opens.', 'wpmcp')
                : __('OAuth is off for this site (the default). The custom connector requires OAuth: it cannot send an application password, so on this site it fails with "Couldn\'t register with WP MCP\'s sign-in service".', 'wpmcp') . ' ' . self::oauth_switch_on(),
            'enable_oauth'  => [
                /* translators: %s: the PHP line to add, define('WPMCP_OAUTH_ENABLED', true); */
                sprintf(__('Add %s to wp-config.php, above the line "That\'s all, stop editing!".', 'wpmcp'), self::OAUTH_CONSTANT_LINE),
                __('Or, from a plugin or mu-plugin: add_filter(\'wpmcp_oauth_enabled\', \'__return_true\');', 'wpmcp'),
                __('Then reload the Connection screen: it shows "OAuth is on" and the OAuth discovery addresses clients use.', 'wpmcp'),
            ],
            'steps'         => [
                __('In Claude Desktop or on claude.ai, open Settings, then Connectors (under Customize in current versions), and choose "Add custom connector".', 'wpmcp'),
                /* translators: %s: the MCP endpoint URL */
                sprintf(__('Enter %s as the URL. Leave the OAuth Client ID and secret empty.', 'wpmcp'), self::endpoint()),
                __('Click Connect, sign in to WordPress in the browser window that opens, and approve the access.', 'wpmcp'),
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
