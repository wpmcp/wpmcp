=== Safe MCP for Elementor ===
Contributors: fahdi
Tags: elementor, mcp, ai agent, undo, page builder
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: elementor
Stable tag: {{VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elementor MCP server: let Claude, ChatGPT, Cursor or any AI agent edit your Elementor pages and widgets, with a snapshot before every write and one-click undo.

== Description ==

Safe MCP for Elementor turns your WordPress site into an MCP (Model Context Protocol) server that knows Elementor. Connect Claude, ChatGPT, Cursor or any MCP client, and your AI agent can read your Elementor pages and edit the elements already on them: change a heading, rewrite a text widget, adjust a container, update page settings, or apply many edits in one batch.

Every write takes a snapshot first. If the agent gets something wrong, roll it back in one click from the History screen, or undo a whole agent session at once.

Not affiliated with or endorsed by Elementor Ltd. Elementor is a trademark of its owner.

= Free =

* Read Elementor pages: the element tree, one element, a whole page export, the Elementor version, registered widgets and their settings schemas, dynamic tags and the kit's global settings
* Edit existing elements: update an element, a widget, a container or a label, update page settings, and apply many settings updates atomically in one batch
* Regenerate Elementor CSS
* The WP MCP safety core: snapshots before every write, History, one-click rollback, session rollback, audit log, governance, scoped identities, OAuth 2.1 or application passwords
* Content and Gutenberg tools, menus, SEO, redirects, internal links, taxonomies, exports and site backups

= With WP MCP Pro =

A WP MCP Pro licence unlocks everything in place, in this same plugin, with nothing else to install:

* Build layouts: add, remove, move, reorder and duplicate elements, containers and Elementor 4 atomic elements
* Build whole Elementor pages from one spec
* Templates, theme builder, popups and dynamic tags
* Global colors, typography, classes and variables, brand kits, custom CSS and code snippets
* The custom widget builder and the Elementor add-on packs
* The full WP MCP toolset (WooCommerce, page builders, forms, LMS and more) and unlimited snapshot history

When your agent calls a Pro tool on a free install, the answer says so and carries the upgrade link, so the agent can pass it on instead of guessing. Pricing: https://wpmcp-pro.com/pricing.html

= Already using WP MCP? =

You do not need both. Safe MCP for Elementor is a focused build of WP MCP. If the full WP MCP plugin is active, this plugin stands down and shows a notice; both share the same settings, snapshots and history, so nothing is lost either way. Deleting this plugin while another WP MCP build is installed leaves all of that data in place.

= Privacy =

The plugin collects nothing about you and sends nothing to us. Licensing (Freemius) is opt-in: on activation Freemius shows its stock opt-in screen, which defaults to off and carries a Skip link. Skip it and no connection is made and the plugin keeps working. The External services section below lists every host the plugin can reach and when.

== External services ==

{{EXTERNAL_SERVICES}}

== Installation ==

1. Install and activate Elementor.
2. Download safe-mcp-for-elementor.zip from https://github.com/wpmcp/wordpress-mcp-for-elementor/releases/latest and upload it under Plugins > Add New > Upload Plugin.
3. Open the wpmcp admin menu, Connection screen, and follow the steps to pair your MCP client (Claude Code, Claude Desktop, ChatGPT, Cursor and others).
4. Ask your agent to change something on an Elementor page. Check the History screen to see the snapshot; restore it with one click.

== Frequently Asked Questions ==

= Does it work with Elementor free? =

Yes. It needs Elementor (free) active; Elementor Pro is not required.

= Can the AI break my pages? =

Every Elementor write checks a hash of the page first, so an edit made against a stale copy is refused, and every write is snapshotted. Roll back one change or a whole session from the History screen.

= How do I upgrade? =

Use Upgrade on the plugin's row in Plugins, or the pricing link in the banner. Activate your WP MCP Pro licence (Activate License on the same row) and the Pro tools appear in this plugin straight away. Reconnect your MCP client so it reloads the tool list.

= Will it update itself? =

Updates come from the GitHub releases page. The plugin carries an Update URI header, so WordPress never replaces it with a different plugin of the same name.

== Changelog ==

= {{VERSION}} =

* First release of Safe MCP for Elementor, built from WP MCP {{VERSION}}.
