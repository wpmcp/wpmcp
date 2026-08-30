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
* Elementor widget inspection and global class reads
* Store, custom field, and SEO plugin integrations, read and write
* Forms, events, donations and memberships, read
* Media library plus stock image search and import
* REST passthrough for anything else, still snapshotted

= Add-on =

A separate add-on plugin, distributed by the author rather than through this
directory, adds Elementor page composition and deep Elementor editing, the
custom widget and block builders, the Bricks and Divi write tools, WP-CLI and
PHP snippet execution, and cloud sync. Nothing in this plugin is locked,
reduced or switched off by it: the add-on's code is not in this download at
all.

= Privacy =

This plugin collects nothing about you and sends nothing anywhere on its own.
It has no scheduled jobs and no activation-time requests. Every outbound
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
* Block editor composition, Elementor widget inspection, store, custom field, SEO and forms integrations.

== Upgrade Notice ==

= {{VERSION}} =
First directory release.
