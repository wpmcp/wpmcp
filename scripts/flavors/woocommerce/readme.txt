=== WP MCP for WooCommerce - AI Store Management with Snapshot Safety ===
Contributors: fahdi
Tags: woocommerce, mcp, ai, ai agent, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: {{VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI agents run your WooCommerce store over MCP: products, orders, content. A snapshot before every write, one-click rollback after.

== Description ==

WP MCP for WooCommerce turns your store into an MCP (Model Context Protocol) server, built on the official WordPress Abilities API. Connect Claude, Cursor, or any MCP client and let an AI agent manage products, look after orders, and edit your store's pages and content.

The difference: **every mutating operation takes a snapshot first.** A wrong bulk price update, a bad product edit, a page the agent got wrong: each is one click from undone in the History screen. The agent physically cannot make an unrecoverable change.

= Safety model =

* Automatic snapshot before every write, with browsable history and one-click restore
* Session rollback: undo everything an agent did in one conversation
* Layered governance where permissions can only be narrowed, never widened
* Full audit log of every tool call
* No remote code execution tools of any kind in this plugin

= What agents can do =

* Products: create, update, list, categories, stock and pricing
* Orders: list, read, add order notes
* Store content: pages, posts, Gutenberg blocks, menus, media
* SEO metadata through Yoast SEO, Rank Math, or SEOPress
* Site context, diagnostics, exports, and scheduled maintenance
* Everything snapshotted, logged, and reversible

= Looking for more? =

This plugin is complete for WooCommerce stores. The full WP MCP plugin adds page-builder depth (Elementor, Bricks, Divi), more integrations, and custom widget/block building: https://wpmcp-pro.com

= Privacy =

This plugin collects nothing about you and sends nothing anywhere on its own.
Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing scheduled ever makes a network request, and activation makes no requests. Every outbound
request listed under "External services" below happens only while you or your
agent are running the specific tool that needs it.

== External services ==

= WordPress.org core checksums API (api.wordpress.org) =

Used by the `scan-security` ability to compare this site's core files against
the official checksums for its version, so modified core files can be
reported. It is contacted only when an administrator or an agent runs that
ability. What is sent: the WordPress version and the site locale, plus a
`WPMCP-Security-Scanner/1.0` user agent. No site URL, no content, no personal
data. Terms of use: https://wordpress.org/about/privacy/ Privacy policy:
https://wordpress.org/about/privacy/

= Openverse (api.openverse.org) =

Used by the `search-stock-images` ability when the Openverse provider is
chosen. It is contacted only when you or an agent run that search. What is
sent: your search terms, the page number and the results-per-page count. No
key and no account are required. Terms of use:
https://openverse.org/terms Privacy policy: https://wordpress.org/about/privacy/

= Pexels (api.pexels.com) =

Used by the `search-stock-images` ability when the Pexels provider is chosen,
which requires you to save a Pexels API key first. It is contacted only when
you or an agent run that search. What is sent: your search terms, the page
number, the results-per-page count, and your own API key in the Authorization
header. Licence terms for the returned images live at
https://www.pexels.com/license/ Terms of use: https://www.pexels.com/terms-of-service/
Privacy policy: https://www.pexels.com/privacy-policy/

= Unsplash (api.unsplash.com) =

Used by the `search-stock-images` ability when the Unsplash provider is
chosen, which requires you to save an Unsplash API key first. It is contacted
only when you or an agent run that search. What is sent: your search terms,
the page number, the results-per-page count, and your own API key in the
Authorization header. Licence terms for the returned images live at
https://unsplash.com/license Terms of use: https://unsplash.com/terms Privacy
policy: https://unsplash.com/privacy

= Image downloads from stock providers =

`import-stock-image` downloads the image you picked. The download target is
restricted to the image hosts of the providers above (images.pexels.com,
images.unsplash.com, plus.unsplash.com, upload.wikimedia.org and
staticflickr.com); nothing but the image is requested, and no data about you
is sent.

= Pages you ask the plugin to measure =

`analyze-performance` fetches the URL you give it so it can measure the
response. It is normally this site's own address. Private, loopback and
reserved addresses are refused and redirects are not followed. Nothing is sent
beyond an ordinary GET and a `WPMCP-Performance-Analyzer/1.0` user agent.

= This site's own REST API =

The connection self-test and the analytics abilities call this site's own URL
over HTTP. These are loopback requests to your server. The analytics abilities
read data through Google Site Kit's REST routes when that plugin is active and
already connected; this plugin holds no analytics credentials of its own and
talks to no analytics provider directly.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Open the WP MCP admin menu and follow the connection wizard to pair your MCP client (Claude Code, Claude Desktop, Cursor, and others).
3. Ask your agent to update a product. Check the History screen and restore any change with one click.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use tools. This plugin exposes your store as a set of MCP tools so agents can operate it safely.

= Can the AI destroy my store? =

Every mutating tool snapshots the affected data first, and this build ships no code-execution tools at all. You can restore any snapshot, or roll back an entire agent session.

= Does this replace my backup plugin? =

No. Snapshots are fine-grained, per-operation undo, not full-site backups. Keep your backup solution.

= How is this different from the full WP MCP plugin? =

Same safety core, same snapshot engine, scoped to what a WooCommerce store needs. If you later want page-builder depth or more integrations, the full plugin replaces this one in place.

== Changelog ==

= {{VERSION}} =
* First release: WooCommerce products and orders, store content, blocks, menus, SEO metadata, and the full snapshot/rollback safety core.

== Upgrade Notice ==

= {{VERSION}} =
First release.
