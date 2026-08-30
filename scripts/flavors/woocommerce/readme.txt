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
* Media library work, including stock image search and import
* Security scans, performance analysis, and plugin/theme installs and updates
* Everything snapshotted, logged, and reversible

= Looking for more? =

This plugin is complete for WooCommerce stores. The full WP MCP plugin adds page-builder depth (Elementor, Bricks, Divi), more integrations, and custom widget/block building: https://wpmcp-pro.com

= Privacy =

The plugin collects nothing about you and sends nothing to the author. Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing on the schedule ever makes a network request, and neither does activation. Every host it can reach is listed under "External services" below, and each request happens only while you or your agent are running the specific ability that needs it.

== External services ==

= WordPress.org core checksums API (api.wordpress.org) =

Used by the `scan-security` ability to compare this site's core files against the official checksums for its version, so modified core files can be reported. It is contacted only when an administrator or an agent runs that ability. What is sent: the WordPress version and the site locale, under a `WPMCP-Security-Scanner/1.0` user agent. No content and no personal data. Privacy policy: https://wordpress.org/about/privacy/

= WordPress.org plugin directory API, abandoned-plugin check (api.wordpress.org) =

The same `scan-security` ability also asks the plugin directory whether any of your active plugins has been closed, so an abandoned plugin can be reported. What is sent: the directory slug of each active plugin it looks up (a capped number per run, then cached), through WordPress core's own `plugins_api()`. Core sends its standard user agent with those requests, which contains the WordPress version and this site's address. Privacy policy: https://wordpress.org/about/privacy/

= WordPress.org plugin and theme directory (api.wordpress.org, downloads.wordpress.org) =

Used by the `search-plugins`, `get-plugin-info`, `install-plugin`, `update-plugin`, `install-theme` and `update-theme` abilities, only when you or your agent run one of them. What is sent to api.wordpress.org: your search terms, or the directory slug of the plugin or theme in question, through core's `plugins_api()` and `themes_api()`, with core's standard user agent (WordPress version and this site's address). Installs and updates then download the package archive from downloads.wordpress.org. Only directory slugs are accepted; these abilities cannot be pointed at an arbitrary zip URL. Privacy policy: https://wordpress.org/about/privacy/

= Openverse (api.openverse.org) =

Used by the `search-stock-images` ability when the Openverse provider is chosen. It is contacted only when you or an agent run that search. What is sent: your search terms, the page number and the results-per-page count, under a `WPMCP-Stock-Search/1.0` user agent. No key and no account are required. Terms of use: https://openverse.org/terms Privacy policy: https://wordpress.org/about/privacy/

= Pexels (api.pexels.com) =

Used by the `search-stock-images` ability when the Pexels provider is chosen, which requires you to save a Pexels API key first. What is sent: your search terms, paging, your own API key in the Authorization header, and a `WPMCP-Stock-Search/1.0` user agent. Licence terms for the returned images live at https://www.pexels.com/license/ Terms of use: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/

= Unsplash (api.unsplash.com) =

Used by the `search-stock-images` ability when the Unsplash provider is chosen, which requires you to save an Unsplash API key first. What is sent: your search terms, paging, your own API key in the Authorization header, and a `WPMCP-Stock-Search/1.0` user agent. Licence terms for the returned images live at https://unsplash.com/license Terms of use: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy

= Image and SVG downloads from allowlisted hosts =

`import-stock-image` and `upload-svg` download the file you picked. The download target must be on an allowlist, which by default is images.pexels.com, images.unsplash.com and plus.unsplash.com (Pexels and Unsplash, above), upload.wikimedia.org (Wikimedia Commons, https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and staticflickr.com (Flickr, https://www.flickr.com/help/terms and https://www.flickr.com/help/privacy), each matched on the host itself or a subdomain of it. The site owner can widen or narrow that list with the `wpmcp_remote_media_allowed_hosts` filter. Nothing but the file is requested; the request carries WordPress's standard user agent, which contains the WordPress version and this site's address.

= Downloads you point the plugin at yourself (any host) =

`sideload-image` hands the URL you or your agent supply to WordPress core's `media_sideload_image()`, so it can fetch an image from any host on the internet. It is not restricted by the allowlist above, and the destination is whatever you asked for; the plugin never picks one. Disable the ability in the WP MCP ability grid if you do not want that reach. As with any core download, the request carries WordPress's standard user agent.

= Pages you ask the plugin to measure =

`analyze-performance` fetches the URL you give it so it can measure the response. It is normally this site's own address. Private, loopback and reserved addresses are refused and redirects are not followed. Nothing is sent beyond an ordinary GET and a `WPMCP-Performance-Analyzer/1.0` user agent.

= Loopback requests to this site itself =

The connection self-test calls this site's own REST route, and `scan-security` fetches this site's front page to read its security headers. Both are requests to your own server, not calls to any third party.

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
