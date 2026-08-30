=== WP MCP - AI Agents for WordPress with Snapshot Safety ===
Contributors: fahdi
Tags: mcp, ai, ai agent, claude, automation
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The AI agent that builds your WordPress site and physically can't wreck it. MCP server with a snapshot before every write and one-click rollback.

== Description ==

WP MCP turns your WordPress site into an MCP (Model Context Protocol) server, built on the official WordPress Abilities API. Connect Claude, Cursor, or any MCP client and let an AI agent build pages, edit content, manage plugins, and configure your site.

The difference: **every mutating operation takes a snapshot first.** If the agent gets something wrong, you roll it back in one click from the History screen. The AI physically cannot make an unrecoverable change.

= Safety model =

* Automatic snapshot before every write, with a browsable history and one-click restore
* Session rollback: undo everything an agent did in one conversation
* Six-layer governance where each layer can only narrow permissions, never widen them
* Full audit log of every tool call and every governance decision
* Scoped identities: give each agent exactly the capabilities it needs
* OAuth 2.1 with PKCE, or application passwords

= What agents can do =

200+ abilities across content, structure, media, and site management:

* Posts, pages, custom post types, taxonomies, menus, users, options
* Gutenberg: surgical block edits, custom block building, full page composition
* Elementor: widgets, templates, theme builder, popups, global styles, custom widget building
* Bricks and Divi structural editing
* WooCommerce, ACF, Meta Box, Yoast, Rank Math, SEOPress, The SEO Framework, SureRank
* Forms (Gravity Forms, WPForms, Contact Form 7, Formidable, Ninja Forms, Fluent Forms, Forminator, SureForms, MetForm), events, donations, memberships (read)
* Media library plus stock image imports
* REST passthrough for anything else, still snapshotted

= Free vs Pro =

The free plugin is fully functional: the MCP server, the safety core, snapshots and rollback (last 20 snapshots), Gutenberg building, and the integration read tools.

WP MCP Pro adds unlimited snapshot history, deep Elementor editing and building, custom widget/block builders, cloud sync for your widget and block specs, and priority support. See https://wpmcp-pro.com/pricing.html

= Privacy =

The plugin collects nothing about you and sends nothing to us. Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing on the schedule ever makes a network request, and neither does activation. Every host it can reach is listed under "External services" below, and each request happens only while you or your agent are running the ability that needs it. Licensing (Freemius) and WP MCP Cloud sync are opt-in and inactive until you connect them.

== External services ==

* api.wordpress.org, core checksums - fetched by scan-security so modified core files can be reported. Sends the WordPress version and site locale under a WPMCP-Security-Scanner/1.0 user agent. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org, plugin directory - scan-security also asks whether any active plugin has been closed, sending the directory slug of each plugin it looks up (capped per run, then cached) through core's plugins_api(). Core's standard user agent goes with it, which carries the WordPress version and this site's address. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org and downloads.wordpress.org, plugin and theme directory - search-plugins, get-plugin-info, install-plugin, update-plugin, install-theme and update-theme send your search terms or a directory slug through core's plugins_api()/themes_api(), again with core's standard user agent, and installs and updates download the package archive from downloads.wordpress.org. Only directory slugs are accepted, never an arbitrary zip URL. Privacy policy: https://wordpress.org/about/privacy/
* api.openverse.org - search-stock-images, when the Openverse provider is used. Sends the search terms and paging under a WPMCP-Stock-Search/1.0 user agent. No key needed. Terms: https://openverse.org/terms
* api.pexels.com - search-stock-images, when the Pexels provider is used and you have saved a Pexels key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/
* api.unsplash.com - search-stock-images, when the Unsplash provider is used and you have saved an Unsplash key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy
* api.freemius.com - licensing, only after you opt in to the Freemius activation screen. Terms: https://freemius.com/terms/ Privacy policy: https://freemius.com/privacy/
* WP MCP Cloud - widget and block spec sync, only after you run cloud-connect with a url and key you supply. Sends the specs you push. Terms and privacy policy: https://wpmcp-pro.com/
* Allowlisted media hosts - import-stock-image and upload-svg download the file you picked from a default allowlist of images.pexels.com, images.unsplash.com, plus.unsplash.com, upload.wikimedia.org (Wikimedia Commons) and staticflickr.com (Flickr), matched on the host or a subdomain of it. The site owner can change that list with the wpmcp_remote_media_allowed_hosts filter. The download carries WordPress's standard user agent.
* Any host you name yourself - sideload-image passes the URL you or your agent supply to core's media_sideload_image(), so it can fetch an image from anywhere. It is not covered by the allowlist above; disable the ability if you do not want that reach.
* Any URL you measure - analyze-performance fetches the URL you give it under a WPMCP-Performance-Analyzer/1.0 user agent, refusing private, loopback and reserved addresses and following no redirects.
* This site itself - the connection self-test calls this site's own REST route, scan-security fetches this site's front page to read its security headers, and the analytics abilities call this site's own URL. These are loopback requests to your own server.

== Installation ==

1. Install and activate the plugin.
2. Open the WP MCP admin menu and follow the connection wizard to pair your MCP client (Claude Code, Claude Desktop, Cursor, and others).
3. Ask your agent to build something. Check the History screen to see snapshots accumulate; restore any of them with one click.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use tools. WP MCP exposes your WordPress site as a set of MCP tools so agents can operate it safely.

= Can the AI destroy my site? =

Every mutating tool snapshots the affected data first, and destructive escape hatches (WP-CLI, PHP execution) ship disabled by default and only run in development environments. You can restore any snapshot, or roll back an entire agent session.

= Does this replace my backup plugin? =

No. Snapshots are fine-grained, per-operation undo, not full-site backups. Keep your backup solution.

= Which AI clients work? =

Any MCP client: Claude Code, Claude Desktop, Cursor, Windsurf, and others. Authentication works via OAuth 2.1 or WordPress application passwords.

= Is it really free? =

Yes. The safety core and the MCP server are free and GPL. Pro adds convenience and depth (unlimited history, Elementor deep editing, builders, cloud sync), not safety.

== Changelog ==

= 0.8.0 =
* Launch release.
* 200+ abilities across content, builders, and integrations.
* Snapshot-before-every-write safety core with one-click and session rollback.
* Six-layer governance, audit log, scoped identities, OAuth 2.1.
* Elementor, Gutenberg, Bricks, Divi, WooCommerce, ACF, Meta Box, and major SEO/forms/events plugin integrations.
* WP MCP Cloud sync client for widget and block specs (Pro).

== Upgrade Notice ==

= 0.8.0 =
First public release.
