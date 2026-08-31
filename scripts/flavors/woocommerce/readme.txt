=== WP MCP for WooCommerce - AI Store Management with Snapshot Safety ===
Contributors: fahdi
Tags: woocommerce, mcp, ai, ai agent, claude
Requires at least: 6.9
Tested up to: 6.9
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

The plugin collects nothing about you and sends nothing anywhere on its own. It has no scheduled jobs and no activation-time requests. Every outbound request it can make is listed under "External services" below, and each one happens only while you or your agent are running the tool that needs it.

== External services ==

* api.wordpress.org - core file checksums, fetched by scan-security so modified core files can be reported. Sends the WordPress version and site locale. Privacy policy: https://wordpress.org/about/privacy/
* api.openverse.org - stock image search, when the Openverse provider is used. Sends the search terms and paging. No key needed. Terms: https://openverse.org/terms
* api.pexels.com - stock image search, when the Pexels provider is used and you have saved a Pexels key. Sends the search terms, paging and your key. Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/
* api.unsplash.com - stock image search, when the Unsplash provider is used and you have saved an Unsplash key. Sends the search terms, paging and your key. Terms: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy
* import-stock-image downloads from a fixed allowlist of image CDNs (images.pexels.com, images.unsplash.com, plus.unsplash.com, upload.wikimedia.org, staticflickr.com). analyze-performance fetches the URL you give it, refusing private, loopback and reserved addresses. The connection self-test calls this site's own URL.

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
