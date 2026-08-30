=== WP MCP - MCP Server with Snapshot Undo for AI Agents ===
Contributors: fahdi
Tags: mcp, mcp server, ai agent, automation, undo
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: {{VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn this site into an MCP server for AI agents. Every write takes a snapshot first, so you can roll any change back from the History screen.

== Description ==

WP MCP exposes this site to AI agents over the Model Context Protocol, built on the Abilities API in core 6.9. Pair any MCP client and let an agent build pages, edit content, manage plugins and configure the site.

The difference is the undo. Every mutating operation captures a snapshot before it runs, so a change you did not want is one click away from being reversed on the History screen.

= Safety model =

* A snapshot before every write, with a browsable history and one-click restore
* Session rollback: undo everything an agent did in one conversation
* Six governance layers, each of which can only narrow permissions, never widen them
* An audit log of every tool call and every governance decision
* Scoped identities, so each agent gets exactly the capabilities it needs
* OAuth 2.1 with PKCE, or application passwords

= What agents can do =

Around 180 abilities across content, structure, media and site management:

* Posts, pages, custom post types, taxonomies, menus, users, options
* Block editor: surgical block edits and full page composition
* Elementor page composition and widget inspection
* Store, custom field, and SEO plugin integrations, read and write
* Forms, events, donations and memberships, read
* Media library plus stock image search and import
* REST passthrough for anything else, still snapshotted

= Add-on =

A separate add-on plugin, distributed by the author rather than through this
directory, adds deep Elementor editing, the custom widget and block builders,
the Bricks and Divi write tools, WP-CLI and PHP snippet execution, and cloud
sync. Nothing in this plugin is locked, reduced or switched off by it: the
add-on's code is not in this download at all.

= Privacy =

This plugin collects nothing about you and sends nothing to the author. Its
only scheduled task is a daily local cleanup of expired OAuth tokens;
nothing on the schedule ever makes a network request, and neither does
activation. Every host it can reach is listed under "External services"
below, and each request happens only while you or your agent are running the
specific ability that needs it.

== External services ==

= WordPress.org core checksums API (api.wordpress.org) =

Used by the `scan-security` ability to compare this site's core files
against the official checksums for its version, so modified core files can
be reported. It is contacted only when an administrator or an agent runs
that ability. What is sent: the WordPress version and the site locale, under
a `WPMCP-Security-Scanner/1.0` user agent. No content and no personal data.
Terms of use: https://wordpress.org/about/privacy/ Privacy policy:
https://wordpress.org/about/privacy/

= WordPress.org plugin directory API, abandoned-plugin check (api.wordpress.org) =

The same `scan-security` ability also asks the plugin directory whether any
of your active plugins has been closed, so an abandoned plugin can be
reported. What is sent: the directory slug of each active plugin it looks up
(a capped number per run, then cached), through WordPress core's own
`plugins_api()`. Core sends its standard user agent with those requests,
which contains the WordPress version and this site's address. Terms of use:
https://wordpress.org/about/privacy/ Privacy policy:
https://wordpress.org/about/privacy/

= WordPress.org plugin and theme directory (api.wordpress.org, downloads.wordpress.org) =

Used by the `search-plugins`, `get-plugin-info`, `install-plugin`,
`update-plugin`, `install-theme` and `update-theme` abilities, only when you
or your agent run one of them. What is sent to api.wordpress.org: your
search terms, or the directory slug of the plugin or theme in question,
through core's `plugins_api()` and `themes_api()`, with core's standard user
agent (WordPress version and this site's address). Installs and updates then
download the package archive from downloads.wordpress.org. Only directory
slugs are accepted; these abilities cannot be pointed at an arbitrary zip
URL. Terms of use: https://wordpress.org/about/privacy/ Privacy policy:
https://wordpress.org/about/privacy/

= Openverse (api.openverse.org) =

Used by the `search-stock-images` ability when the Openverse provider is
chosen. It is contacted only when you or an agent run that search. What is
sent: your search terms, the page number and the results-per-page count,
under a `WPMCP-Stock-Search/1.0` user agent. No key and no account are
required. Terms of use: https://openverse.org/terms Privacy policy:
https://wordpress.org/about/privacy/

= Pexels (api.pexels.com) =

Used by the `search-stock-images` ability when the Pexels provider is
chosen, which requires you to save a Pexels API key first. It is contacted
only when you or an agent run that search. What is sent: your search terms,
the page number, the results-per-page count, your own API key in the
Authorization header, and a `WPMCP-Stock-Search/1.0` user agent. Licence
terms for the returned images live at https://www.pexels.com/license/ Terms
of use: https://www.pexels.com/terms-of-service/ Privacy policy:
https://www.pexels.com/privacy-policy/

= Unsplash (api.unsplash.com) =

Used by the `search-stock-images` ability when the Unsplash provider is
chosen, which requires you to save an Unsplash API key first. It is
contacted only when you or an agent run that search. What is sent: your
search terms, the page number, the results-per-page count, your own API key
in the Authorization header, and a `WPMCP-Stock-Search/1.0` user agent.
Licence terms for the returned images live at https://unsplash.com/license
Terms of use: https://unsplash.com/terms Privacy policy:
https://unsplash.com/privacy

= Image and SVG downloads from allowlisted hosts =

`import-stock-image` and `upload-svg` download the file you picked. The
download target must be on an allowlist, which by default is
images.pexels.com, images.unsplash.com and plus.unsplash.com (Pexels and
Unsplash, above), upload.wikimedia.org (Wikimedia Commons,
https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and
staticflickr.com (Flickr, https://www.flickr.com/help/terms and
https://www.flickr.com/help/privacy), each matched on the host itself or a
subdomain of it. The site owner can widen or narrow that list with the
`wpmcp_remote_media_allowed_hosts` filter. Nothing but the file is
requested; the request carries WordPress's standard user agent, which
contains the WordPress version and this site's address.

= Downloads you point the plugin at yourself (any host) =

`sideload-image` hands the URL you or your agent supply to WordPress core's
`media_sideload_image()`, so it can fetch an image from any host on the
internet. It is not restricted by the allowlist above, and the destination
is whatever you asked for; the plugin never picks one. Disable the ability
in the WP MCP ability grid if you do not want that reach. As with any core
download, the request carries WordPress's standard user agent.

= Pages you ask the plugin to measure =

`analyze-performance` fetches the URL you give it so it can measure the
response. It is normally this site's own address. Private, loopback and
reserved addresses are refused and redirects are not followed. Nothing is
sent beyond an ordinary GET and a `WPMCP-Performance-Analyzer/1.0` user
agent.

= Loopback requests to this site itself =

The connection self-test calls this site's own REST route, `scan-security`
fetches this site's front page to read its security headers, and the
analytics abilities call this site's own URL. These are requests to your own
server. The analytics abilities read data through Google Site Kit's REST
routes when that plugin is active and already connected; this plugin holds
no analytics credentials of its own and talks to no analytics provider
directly.

== Installation ==

1. Install and activate the plugin.
2. Open the WP MCP menu and follow the connection wizard to pair your MCP client.
3. Ask your agent to build something, then open the History screen to see the snapshots and restore any of them.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use tools. This plugin exposes the site as a set of MCP tools so an agent can operate it.

= Can an agent destroy my site? =

Every mutating tool snapshots the data it is about to change, and you can restore any snapshot or roll back a whole agent session. This build contains no code-execution tools at all.

= Does this replace my backup plugin? =

No. Snapshots are fine-grained, per-operation undo, not full-site backups. Keep your backup solution.

= Which clients work? =

Any MCP client, over OAuth 2.1 or application passwords.

= Does it need an account or a key? =

No. Nothing here requires registration. Stock image search with Pexels or Unsplash needs an API key from those services, which you supply; the Openverse provider needs nothing.

== Changelog ==

= {{VERSION}} =
* First directory release.
* MCP server on the core Abilities API, with a snapshot before every write and one-click or whole-session rollback.
* Six governance layers, an audit log, scoped identities and OAuth 2.1.
* Block editor, Elementor composition, store, custom field, SEO and forms integrations.

== Upgrade Notice ==

= {{VERSION}} =
First directory release.
