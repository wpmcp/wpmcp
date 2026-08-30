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

No telemetry and no calls home. Every outbound request this build can make is listed under "External services" below, and each one happens only while you or your agent are running the tool that needs it. This build contains no licensing SDK and no cloud sync.

== External services ==

The plugin never phones home. Each request below fires only while you or your agent run the tool that needs it.

Fixed hosts:

* api.wordpress.org - core file checksums, fetched only when the scan-security ability runs its integrity check (requires the manage_options capability). Sends the WordPress version and site locale with the user agent WPMCP-Security-Scanner/1.0. Terms: https://wordpress.org/about/license/ Privacy policy: https://wordpress.org/about/privacy/
* api.openverse.org - stock image search. Openverse is the default provider for search-stock-images and needs no key or configuration, so this request is unconditional whenever that ability runs without another provider selected. Sends the search terms and paging only. Terms: https://openverse.org/terms Privacy policy: https://openverse.org/privacy
* api.pexels.com - stock image search, only when the Pexels provider is selected and you have saved a Pexels key via set-stock-key. Sends the search terms, paging and your key. Results link to www.pexels.com license pages; those links are displayed, never requested. Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/
* api.unsplash.com - stock image search, only when the Unsplash provider is selected and you have saved an Unsplash key via set-stock-key. Sends the search terms, paging and your key. Results link to unsplash.com license pages; those links are displayed, never requested. Terms: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy

Dynamic destinations:

* import-stock-image downloads the image you picked from a fixed allowlist of five image CDNs (images.pexels.com, images.unsplash.com, plus.unsplash.com, upload.wikimedia.org, staticflickr.com); any other host is refused before a request is made.
* analyze-performance fetches the page URL you give it (defaulting to this site's front page), refusing private, loopback and reserved addresses.
* The connection self-test requests this site's own home URL to verify the MCP endpoint is reachable.

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
