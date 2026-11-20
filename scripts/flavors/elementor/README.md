# Elementor MCP Server (Safe MCP for Elementor)

Let Claude, ChatGPT, Cursor or any AI agent edit your Elementor pages and widgets over MCP (Model Context Protocol), with a snapshot before every write and one-click undo. Free and GPL. Installs as the WordPress plugin **Safe MCP for Elementor**.

Not affiliated with or endorsed by Elementor Ltd. Elementor is a trademark of its owner.

## Download

[safe-mcp-for-elementor.zip](https://github.com/wpmcp/wordpress-mcp-for-elementor/releases/latest/download/safe-mcp-for-elementor.zip) (latest release)

Requires WordPress 6.9+, PHP 8.1+ and Elementor (free). Upload the zip under **Plugins > Add New > Upload Plugin**, then activate it. The licensing opt-in screen that appears on activation can be skipped; the plugin works either way.

## What your agent can do (free)

- **Read Elementor pages**: the element tree, one element by id or search, a full page export, the Elementor version, registered widgets with their settings schemas, dynamic tags and the kit's global colors and typography.
- **Edit what is already on the page**: update an element, a widget, a container or a label, update page settings, or apply many settings updates in one atomic batch. Every write checks a hash of the page first, so an edit made against a stale copy is refused.
- **Undo anything it did**: every write is snapshotted. Roll back one change or a whole agent session from the History screen, or let the agent call `rollback-operation`.
- **The WP MCP safety core**: audit log, governance, scoped identities, OAuth 2.1 or application passwords, plus content, Gutenberg, menus, SEO, redirects and backups.

## With WP MCP Pro

A [WP MCP Pro](https://wpmcp-pro.com/pricing.html) licence unlocks everything in place, in the same plugin:

- Add, remove, move, reorder and duplicate elements, containers and Elementor 4 atomic elements
- Build whole Elementor pages from one spec
- Templates, theme builder, popups and dynamic tags
- Global colors, typography, classes and variables, brand kits, custom CSS and code snippets
- The custom widget builder and the Elementor add-on packs
- The full WP MCP toolset and unlimited snapshot history

On a free install, a call to a Pro tool answers with what it needs and the upgrade link, so your agent can tell you instead of guessing.

## Connect your AI client

The endpoint is `https://your-site.com/wp-json/mcp/wpmcp-server`. The **wpmcp > Connection** screen in wp-admin creates an application password and shows a ready-to-paste snippet for each client.

Claude Code, in your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "elementor": {
      "type": "http",
      "url": "https://your-site.com/wp-json/mcp/wpmcp-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_username:application-password"
      }
    }
  }
}
```

Claude Desktop, claude.ai and ChatGPT connect through OAuth: turn on **Allow OAuth sign-in** on the Connection screen, then add the endpoint as a custom connector. The full guide is in the [WP MCP README](https://github.com/wpmcp/wordpress-mcp#connect-your-ai-client).

## Try it

> On the Home page, change the hero heading to "Spring sale" and make the button say "Shop now".

The agent reads the page with `get-elementor-data`, finds the two widgets, and edits each with `update-widget`. Both changes appear on the History screen; one click puts the old text back.

## Already using WP MCP?

You do not need both. Safe MCP for Elementor is a focused build of [WP MCP](https://github.com/wpmcp/wordpress-mcp). If the full plugin is active, this one stands down and says so; both share the same settings, snapshots and history. Deleting this plugin while another WP MCP build is installed leaves that data in place.

## Source and issues

This repository publishes the releases. The source lives in [wpmcp/wordpress-mcp](https://github.com/wpmcp/wordpress-mcp) (built by `scripts/build-elementor-release.sh`); please file issues there.

Homepage: https://wpmcp-pro.com/
