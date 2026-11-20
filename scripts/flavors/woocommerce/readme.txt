=== Safe MCP for WooCommerce ===
Contributors: fahdi
Tags: ecommerce, mcp, ai, ai agent, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: {{VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI agents run your WooCommerce store over MCP, from products and orders to content. A snapshot before every write, one-click rollback after.

== Description ==

Safe MCP for WooCommerce turns your store into an MCP (Model Context Protocol) server, built on the official WordPress Abilities API. Connect Claude, Cursor, or any MCP client and let an AI agent manage products, look after orders, and edit your store's pages and content.

The difference: **every mutating operation takes a snapshot first.** A wrong bulk price update, a bad product edit, a page the agent got wrong: each is one click from undone in the History screen. The agent physically cannot make an unrecoverable change.

= Safety model =

* Automatic snapshot before every write, with browsable history and one-click restore
* Session rollback: undo everything an agent did in one conversation
* Layered governance where permissions can only be narrowed, never widened
* Full audit log of every tool call
* No remote code execution tools of any kind in this plugin
* No file browser or editor, no SQL console and no code snippets

= What agents can do =

* Products: create, update, list, categories, stock and pricing
* Orders: list, read, add order notes
* Store content: pages, posts, Gutenberg blocks, menus, media
* SEO metadata through Yoast SEO, Rank Math, or SEOPress
* Site context, diagnostics, exports, and scheduled maintenance
* Media library work, including stock image search and import
* Security scans, performance analysis, and plugin/theme installs and updates from WordPress.org
* Everything snapshotted, logged, and reversible

= What this plugin does not do =

It has no tool to read, list, search, write, edit, delete or quarantine files, run SQL or write database rows directly, search and replace across the whole site, store or create code snippets, move a site to or from another server, restore the whole site from a backup archive, reinstall core files, or rewrite wp-config.php. An agent that asks for one of those tools by name is told it is not in this build.

= Looking for more? =

This plugin is complete for WooCommerce stores. The full WP MCP plugin adds page-builder depth (Elementor, Bricks, Divi), more integrations, and custom widget/block building: https://wpmcp-pro.com

= Privacy =

The plugin collects nothing about you and sends nothing to the author. Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing on the schedule ever makes a network request, and neither does activation. Every host it can reach is listed under "External services" below, and each request happens only while you or your agent are running the specific ability that needs it, except, with OAuth switched on, the client metadata document fetch described below, which runs while an MCP client signs in.

This plugin keeps copies of site data it needs to work: undo snapshots of what an agent changed (which can include users, comments, orders and form entries), the request and governance audit logs, agent memory and the content search index. All of it stays on your site, and all of it takes part in WordPress's own personal data tools: Tools > Export Personal Data lists a person's records from each store, and Tools > Erase Personal Data redacts them from undo snapshots (a redacted snapshot can no longer be rolled back, and says so), anonymizes them in the logs and deletes the memory and index entries that hold them. WP MCP's erasure runs after your other plugins' own, so their copies in WP MCP's snapshots are covered too. Site backup archives made by WP MCP are full database copies and are left unchanged; the erasure says so when any exist.

== External services ==

= WordPress.org core checksums API (api.wordpress.org) =

Used by the `scan-security` ability to compare this site's core files against the official checksums for its version, so modified core files can be reported. It is contacted only when an administrator or an agent runs it. What is sent: the WordPress version and the site locale, under a `WPMCP-Security-Scanner/1.0` user agent. No content and no personal data. Privacy policy: https://wordpress.org/about/privacy/

= WordPress.org plugin directory API, abandoned-plugin check (api.wordpress.org) =

The same `scan-security` ability also asks the plugin directory whether any of your active plugins has been closed, so an abandoned plugin can be reported. What is sent: the directory slug of each active plugin it looks up (a capped number per run, then cached), through WordPress core's own `plugins_api()`. Core sends its standard user agent with those requests, which contains the WordPress version and this site's address. Privacy policy: https://wordpress.org/about/privacy/

= WPVulnerability database (www.wpvulnerability.net) =

Used by the `scan-security` ability when it is run with `vulnerabilities:true`, and only then, to look up WordPress core, each installed plugin and each installed theme in this free, public vulnerability database. It needs no API key and no account. What is sent: the WordPress version (for core) and each plugin or theme slug, nothing else. Your plugin and theme versions and your site address are not sent: versions are compared on this site, and requests go out under a `WPMCP-Security-Scanner/1.0` user agent with redirects off. Answers are cached on this site for a day (filterable with `wpmcp_vulnerability_cache_ttl`), so a repeat scan sends nothing, and a lookup that fails is reported as unchecked rather than clean. The service states that it stores no request data. Terms: https://www.wpvulnerability.com/license/ Privacy policy: https://www.wpvulnerability.com/privacy/

= WordPress.org plugin and theme directory (api.wordpress.org, downloads.wordpress.org) =

Used by the `search-plugins`, `get-plugin-info`, `install-plugin`, `update-plugin`, `search-themes`, `install-theme` and `update-theme` abilities, only when you or your agent run one of them. What is sent to api.wordpress.org: your search terms, or the directory slug of the plugin or theme in question, through core's `plugins_api()` and `themes_api()`, with core's standard user agent (WordPress version and this site's address). Installs and updates then download the package archive from downloads.wordpress.org. Only directory slugs are accepted; these abilities cannot be pointed at an arbitrary zip URL. `manage-updates` with type=core downloads the WordPress package that core's own update check offered, from downloads.wordpress.org. Privacy policy: https://wordpress.org/about/privacy/

= WordPress.org language packs (api.wordpress.org, downloads.wordpress.org) =

Used by the `update-settings` ability when it sets `WPLANG` to a locale that is not installed yet, and only then. It fetches the list of available core translations for this WordPress version from api.wordpress.org through core's `translations_api()`, then downloads that one locale's core language pack from downloads.wordpress.org through core's language pack upgrader, with core's standard user agent (WordPress version and this site's address). A locale that is already installed sends nothing. Privacy policy: https://wordpress.org/about/privacy/

= Openverse (api.openverse.org) =

Used by the `search-stock-images` ability. Openverse is the default provider, used whenever no other provider is named, and needs no key or setup, so it is not opt-in: it is contacted only when you or an agent run that search, and running it is what sends the request. What is sent: your search terms, the page number and the results-per-page count, under a `WPMCP-Stock-Search/1.0` user agent. No key and no account are required. Terms of use: https://openverse.org/terms Privacy policy: https://openverse.org/privacy

= Pexels (api.pexels.com) =

Used by the `search-stock-images` ability when the Pexels provider is chosen, which requires you to save a Pexels API key first. What is sent: your search terms, paging, your own API key in the Authorization header, and a `WPMCP-Stock-Search/1.0` user agent. Licence terms for the returned images live at https://www.pexels.com/license/ Terms of use: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/

= Unsplash (api.unsplash.com) =

Used by the `search-stock-images` ability when the Unsplash provider is chosen, which requires you to save an Unsplash API key first. What is sent: your search terms, paging, your own API key in the Authorization header, and a `WPMCP-Stock-Search/1.0` user agent. Licence terms for the returned images live at https://unsplash.com/license Terms of use: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy

= Image and SVG downloads from allowlisted hosts =

`import-stock-image` and `upload-svg` download the file you picked. The download target must be on an allowlist, which by default is images.pexels.com, images.unsplash.com and plus.unsplash.com (Pexels and Unsplash, above), upload.wikimedia.org (Wikimedia Commons, terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use privacy policy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and staticflickr.com (Flickr, terms: https://www.flickr.com/help/terms privacy policy: https://www.flickr.com/help/privacy), each matched on the host itself or a subdomain of it. The site owner can widen or narrow that list with the `wpmcp_remote_media_allowed_hosts` filter. Nothing but the file is requested; the request carries WordPress's standard user agent, which contains the WordPress version and this site's address.

= Downloads you point the plugin at yourself (any host) =

`sideload-image` hands the URL you or your agent supply to WordPress core's `media_sideload_image()`, so it can fetch an image from any host on the internet. It is not restricted by the allowlist above, and the destination is whatever you asked for; the plugin never picks one. Disable the ability in the WP MCP ability grid if you do not want that reach. As with any core download, the request carries WordPress's standard user agent.

= The AI provider the site owner configured (WordPress 7.0+) =

`sideload-image` with a `prompt` generates the image through WordPress core's AI client, with whichever provider and image model an administrator connected under Settings > Connectors and that connection's own credentials. This plugin holds no AI key and adds no provider. Nothing is sent when AI is disabled or no connected provider can make images. Otherwise the prompt, and the ratio if given, go to that provider; the image comes back as data, or is downloaded from the provider's https link with private addresses and redirects refused. That provider's own terms and privacy policy apply.

= Pages you ask the plugin to measure =

`analyze-performance` fetches the URL you give it so it can measure the response. It is normally this site's own address. Private, loopback and reserved addresses are refused and redirects are not followed. Nothing is sent beyond an ordinary GET and a `WPMCP-Performance-Analyzer/1.0` user agent.

= MCP client metadata documents (the client's own https URL) =

Used only when OAuth is switched on (it is off by default) and an MCP client signs in with an https URL as its client ID, a Client ID Metadata Document. While a signed-in user authorizes that client, this site fetches that one URL to read the client's name and allowed redirect addresses. Nothing about your site or its users is sent: it is a plain GET under a WPMCP-OAuth-Client-Metadata/1.0 user agent. Private, loopback and reserved addresses are refused, redirects are not followed, the document is capped at 5 KB, and it is cached per its Cache-Control header (an hour by default), so a repeat sign-in sends nothing. Set the wpmcp_oauth_cimd_enabled filter to false to turn this off, or require approval of new clients on the WP MCP Connection screen. The client's publisher's own terms and privacy policy apply to the document it hosts.

= Loopback requests to this site itself =

The connection self-test calls this site's own REST route, and `scan-security` fetches this site's front page to read its security headers. Both are requests to your own server, not calls to any third party. With `checks:["compromise"]`, `scan-security` also fetches up to three of this site's pages as a browser, as a search crawler (a Googlebot user agent) and with a www.google.com Referer header, to spot content shown only to search engines, and sends HEAD requests for backup or dump files it finds in public folders. Nothing is sent to www.google.com: the Referer is only a header on the request to your own site.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Open the WP MCP admin menu and follow the connection wizard to pair your MCP client (Claude Code, Claude Desktop, Cursor, and others).
3. Ask your agent to update a product. Check the History screen and restore any change with one click.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use tools. This plugin exposes your store as a set of MCP tools so agents can operate it safely.

= Can the AI destroy my store? =

Every mutating tool snapshots the affected data first, and this build ships no code-execution, file-writing or raw database tools at all. You can restore any snapshot, or roll back an entire agent session.

= What if my AI client skips permission prompts? =

This plugin asks before destructive actions: through your MCP client's confirmation prompt when the client supports one, otherwise by refusing until the agent passes confirm:true. A client set to skip its own permission prompts (for example Claude Code started with --dangerously-skip-permissions, or any auto-approve mode) also skips those WP MCP confirmations, because it answers them itself without asking you. Actions that cannot be undone are never confirmed through the client: they run only when the agent's own call carries confirm:true, and the refusal says exactly what that will do. Keep permission prompts on when an agent can reach those tools.

= Does this replace my backup plugin? =

No. Snapshots are fine-grained, per-operation undo, not full-site backups. Keep your backup solution.

= Where are site backup archives stored? =

In wp-content/uploads/wpmcp-site-backups by default, behind a deny rule that Apache honours. On nginx and other servers that ignore .htaccess, define WPMCP_BACKUP_DIR in wp-config.php with a folder outside the web root, for example define( 'WPMCP_BACKUP_DIR', '/home/example/wpmcp-backups' ); archives then go to its wpmcp-site-backups subfolder, and archives made earlier are moved there after the next backup. This build makes and manages these archives but has no tool to restore one over the site; restore an archive with your host's tools. When the folder is under the web root, trigger-backup and get-backup-status request a test file placed in it from this site's own URL (at most once a day) and warn on the Dashboard if the server returns it; get-backup-status reports the folder in use and the last result.

= How is this different from the full WP MCP plugin? =

Same safety core, same snapshot engine, scoped to what a WooCommerce store needs. If you later want page-builder depth or more integrations, the full plugin replaces this one in place.

== Changelog ==

= {{VERSION}} =
* First release: WooCommerce products and orders, store content, blocks, menus, SEO metadata, and the full snapshot/rollback safety core.

== Upgrade Notice ==

= {{VERSION}} =
First release.
