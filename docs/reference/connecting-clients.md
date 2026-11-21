# Connect Claude, Claude Code, ChatGPT and Cursor to the WordPress MCP server

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Connect your AI client

wpmcp exposes its tools over the Model Context Protocol. Authenticate with a WordPress [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (Users -> Profile -> Application Passwords).

Example for Claude Code, in your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "wpmcp": {
      "type": "http",
      "url": "https://your-site.com/wp-json/mcp/wpmcp-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_username:application-password"
      }
    }
  }
}
```

Generate the credential with:

```bash
echo -n "your-username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64
```

The same endpoint works with Cursor and any MCP-compatible client that connects over HTTP. The wpmcp -> Connection screen in wp-admin creates the application password and shows every snippet filled in; the `get-connection-info` tool returns the same snippets with a placeholder.

### Claude Desktop

Claude Desktop does not read `url` entries from `claude_desktop_config.json`: that file only runs local (stdio) servers, and an entry with `"type": "http"`, `"url"` or `"headers"` is skipped with "not valid MCP server configurations". Use one of these instead:

- **Desktop bundle (easiest).** On wpmcp -> Connection, create an application password and download `wpmcp.mcpb`. Double-click it and enter the username and password when asked. The bundle contains no credentials and needs nothing else installed.
- **Config file.** Add an `mcp-remote` bridge entry under `mcpServers` (needs Node.js), then fully quit and reopen Claude Desktop:

```json
{
  "mcpServers": {
    "wpmcp": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://your-site.com/wp-json/mcp/wpmcp-server", "--header", "Authorization:${WPMCP_AUTH_HEADER}"],
      "env": { "WPMCP_AUTH_HEADER": "Basic BASE64_OF_username:application-password" }
    }
  }
}
```

Keep `Authorization:${WPMCP_AUTH_HEADER}` without a space; the value with its space goes in `env`.

- **Custom connector (needs OAuth).** Settings -> Connectors -> "Add custom connector" in Claude Desktop or on claude.ai, with the endpoint as the URL. This route signs in with OAuth and cannot send an application password. See the next section.

**If a Claude Desktop chat reports 0 WP MCP tools** while Settings lists them (issue #582): update the plugin first. Before this fix, `tools/list` on MCP `2026-07-28` sent no-argument tools with `"properties":[]` (#586, #591), in compact mode too (`get-site-context` and `get-connection-info` are two of its five tools), and a client that validates the whole list can drop all of it. If it persists on a fixed version, the cause is on the client side: check the web server log for requests from Claude while the chat lists tools (if none arrive, the app is not asking the site), and note the Claude Desktop version when reporting it. The desktop bundle and the `mcp-remote` entry above are a working route meanwhile: Claude Desktop listed and called all tools through `mcp-remote` in the #582 test.

### OAuth and the custom connector

OAuth 2.1 (with dynamic client registration) is **off by default**, and stays off until the site owner opts in. Clients that use an application password do not need it. The Claude Desktop and claude.ai custom connector does: with OAuth off, the discovery addresses (`/.well-known/oauth-protected-resource/...`, `/.well-known/oauth-authorization-server`) return 404, the endpoint's 401 carries no `WWW-Authenticate` header, and the connector fails with "Couldn't register with WP MCP's sign-in service".

Switch OAuth on from wp-admin: **WP MCP, Connection, Allow OAuth sign-in** (issue #842). Developers can also use either of these, which win over the switch:

```php
// wp-config.php, above "That's all, stop editing!"
define('WPMCP_OAUTH_ENABLED', true);
```

```php
// a plugin or mu-plugin
add_filter('wpmcp_oauth_enabled', '__return_true');
```

Then add the connector: Settings -> Connectors (under Customize in current versions) -> "Add custom connector", enter `https://your-site.com/wp-json/mcp/wpmcp-server`, leave the OAuth Client ID and secret empty, click Connect, and approve the sign-in in the browser. The wpmcp -> Connection screen and the `get-connection-info` tool (`clients.custom-connector`) show whether OAuth is on and repeat these steps.

ChatGPT signs in the same way: switch OAuth on, then add the endpoint as a custom MCP connector in ChatGPT's Developer mode. See [docs/connect-chatgpt.md](../connect-chatgpt.md); `get-connection-info` returns the steps under `clients.chatgpt`.

### Protocol revisions and the MCP adapter

Both transports (the HTTP endpoint above and `wp mcp-stdio serve`) speak MCP `2026-07-28` and `2025-11-25`. A `2025-11-25` client runs the usual `initialize` session; a `2026-07-28` client calls the sessionless `server/discover` and then tags each request with the revision, with no session at all. `server/discover` lists both revisions and returns the same handshake instructions `initialize` does.

Clients that advertise the newer protocol features get them; everyone else keeps the existing contract:

- **Elicitation.** A destructive tool's `confirm: true` gate asks the person instead of refusing when the client supports form elicitation: on `2026-07-28` (both transports) as an `input_required` result the client answers and retries, and on a `2025-11-25` stdio session as an `elicitation/create` request mid-call. The answer is bound to that exact call. Without the capability, and on a `2025-11-25` HTTP session (the adapter's HTTP transport has no server-to-client channel), `confirm: true` works as before, and an agent that passes it is not asked again.
- **Tasks.** With the `io.modelcontextprotocol/tasks` extension on `2026-07-28`, `trigger-backup` and `dispatch-cli-job` return a task handle, polled with `tasks/get` and withdrawn with `tasks/cancel`. Tasks are the existing job records, so they survive the stateless HTTP route and reconnects.
- **Output schemas.** Every tool declares an `outputSchema`: the backup and CLI job tools a detailed one, the rest the JSON-object contract every `structuredContent` result meets.
- **Tool list changes.** A `wp mcp-stdio serve` session advertises `tools.listChanged: true` and sends one `notifications/tools/list_changed` when the tools this site can offer change (a plugin activated or deactivated, a WP MCP Pro license added or lapsed, a governance or compact-mode toggle). The HTTP route is stateless and keeps `listChanged: false`. Which clients act on the notification, and when a reconnect is needed instead, is in [docs/mcp-clients.md](../mcp-clients.md).

The HTTP route is mounted by the official [WordPress MCP adapter](https://github.com/WordPress/mcp-adapter). wpmcp bundles adapter 0.6.x so it works on its own, and prefers the canonical MCP Adapter plugin when that is active: the bundled copy is only a fallback and never shadows another copy's classes (see `src/adapter-guard.php`). Adapter 0.7.0 and later answer `2026-07-28` requests natively; with the bundled 0.6.x, wpmcp answers them itself, so clients see the same behavior either way.
