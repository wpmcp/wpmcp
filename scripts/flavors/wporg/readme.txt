=== WP MCP - MCP Server with Snapshot Undo for AI Agents ===
Contributors: fahdi
Tags: mcp, mcp server, ai agent, automation, undo
Requires at least: 6.9
Tested up to: 7.1
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

This plugin collects nothing about you and sends nothing to the author. Its
only scheduled task is a daily local cleanup of expired OAuth tokens;
nothing on the schedule ever makes a network request, and neither does
activation. Every host it can reach is listed under "External services"
below, and each request happens only while you or your agent are running the
specific ability that needs it, except the cloud announcements check
described under WP MCP Cloud, which runs only if a cloud connection was
saved, and, with OAuth switched on, the client metadata document fetch
described below, which runs while an MCP client signs in.

== External services ==

= WordPress.org core checksums API (api.wordpress.org) =

Used by the `scan-security` ability to compare this site's core files
against the official checksums for its version, so modified core files can
be reported. It is contacted only when an administrator or an agent runs
that ability. What is sent: the WordPress version and the site locale, under
a `WPMCP-Security-Scanner/1.0` user agent. No content and no personal data.
Privacy policy: https://wordpress.org/about/privacy/

= WordPress.org core package, core file reinstall (api.wordpress.org, downloads.wordpress.org) =

Used by the `incident-response` ability, only when you or your agent run its
`reinstall-core-files` action. It fetches the same core checksums as above,
then downloads the official WordPress package for this site's version from
downloads.wordpress.org through core's `download_url()`, with core's standard
user agent (WordPress version and this site's address). Only files matching
the official checksums are written. Privacy policy:
https://wordpress.org/about/privacy/

= WordPress.org plugin directory API, abandoned-plugin check (api.wordpress.org) =

The same `scan-security` ability also asks the plugin directory whether any
of your active plugins has been closed, so an abandoned plugin can be
reported. What is sent: the directory slug of each active plugin it looks up
(a capped number per run, then cached), through WordPress core's own
`plugins_api()`. Core sends its standard user agent with those requests,
which contains the WordPress version and this site's address. Privacy
policy: https://wordpress.org/about/privacy/

= WordPress.org plugin and theme directory (api.wordpress.org, downloads.wordpress.org) =

Used by the `search-plugins`, `get-plugin-info`, `install-plugin`,
`update-plugin`, `search-themes`, `install-theme` and `update-theme`
abilities, only when you or your agent run one of them. What is sent to
api.wordpress.org: your search terms, or the directory slug of the plugin or
theme in question, through core's `plugins_api()` and `themes_api()`, with
core's standard user agent (WordPress version and this site's address). Installs and updates then
download the package archive from downloads.wordpress.org. Only directory
slugs are accepted; these abilities cannot be pointed at an arbitrary zip
URL. Privacy policy: https://wordpress.org/about/privacy/

= Openverse (api.openverse.org) =

Used by the `search-stock-images` ability. Openverse is the default
provider, used whenever no other provider is named, and needs no key or
setup, so it is not opt-in: it is contacted only when you or an agent run
that search, and running it is what sends the request. What is
sent: your search terms, the page number and the results-per-page count,
under a `WPMCP-Stock-Search/1.0` user agent. No key and no account are
required. Terms of use: https://openverse.org/terms Privacy policy:
https://openverse.org/privacy

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
Unsplash, above), upload.wikimedia.org (Wikimedia Commons, terms:
https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use privacy policy:
https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and
staticflickr.com (Flickr, terms: https://www.flickr.com/help/terms privacy
policy: https://www.flickr.com/help/privacy), each matched on the host itself or a
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

= MCP client metadata documents (the client's own https URL) =

Used only when OAuth is switched on (it is off by default) and an MCP client
signs in with an https URL as its client ID, a Client ID Metadata Document.
While a signed-in user authorizes that client, this site fetches that one
URL to read the client's name and allowed redirect addresses. Nothing about
your site or its users is sent: it is a plain GET under a
WPMCP-OAuth-Client-Metadata/1.0 user agent. Private, loopback and reserved
addresses are refused, redirects are not followed, the document is capped at
5 KB, and it is cached per its Cache-Control header (an hour by default), so
a repeat sign-in sends nothing. Set the wpmcp_oauth_cimd_enabled filter to
false to turn this off, or require approval of new clients on the WP MCP
Connection screen. The client's publisher's own terms and privacy policy
apply to the document it hosts.

= Loopback requests to this site itself =

The connection self-test calls this site's own REST route, `scan-security`
fetches this site's front page to read its security headers, and the
analytics abilities call this site's own URL. These are requests to your own
server. The analytics abilities read data through Google Site Kit's REST
routes when that plugin is active and already connected; this plugin holds
no analytics credentials of its own and talks to no analytics provider
directly.

= WP MCP Cloud (only if a cloud connection was saved elsewhere) =

This build ships no tool that can connect to the author's optional cloud
service, but it keeps the announcements feed, which reads a cloud URL and
API key if one was saved earlier by the separate add-on plugin on this site.
Only then, when an administrator opens a WP MCP admin screen, it fetches
notices from that saved URL, at most once a day, or once an hour after a
failed fetch. What is sent: a GET request for /announcements with your saved
API key in the Authorization header; no site content. With no saved
connection, nothing is sent. Terms: https://wpmcp-pro.com/terms.html
Privacy policy: https://wpmcp-pro.com/privacy.html

== Installation ==

1. Install and activate the plugin.
2. Open the WP MCP menu and follow the connection wizard to pair your MCP client.
3. Ask your agent to build something, then open the History screen to see the snapshots and restore any of them.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use tools. This plugin exposes the site as a set of MCP tools so an agent can operate it.

= Can an agent destroy my site? =

Every mutating tool snapshots the data it is about to change, and you can restore any snapshot or roll back a whole agent session. This build contains no code-execution tools at all. It can store PHP snippets as text for review (inactive, never run), but nothing in this build executes or activates them.

= Does this replace my backup plugin? =

No. Snapshots are fine-grained, per-operation undo, not full-site backups. Keep your backup solution.

= Which clients work? =

Any MCP client, over OAuth 2.1 or application passwords.

= Does it need an account or a key? =

No. Nothing here requires registration. Stock image search with Pexels or Unsplash needs an API key from those services, which you supply; the Openverse provider needs nothing.

== Changelog ==

= {{VERSION}} =
* First directory release.
* Snapshot retention is one flat number for every install (20), raisable or lowerable for free through the `wpmcp_snapshot_history_limit` filter.
* MCP server on the core Abilities API, with a snapshot before every write and one-click or whole-session rollback.
* Six governance layers, an audit log, scoped identities and OAuth 2.1.
* Block editor composition, Elementor widget inspection, store, custom field, SEO and forms integrations.

== Upgrade Notice ==

= {{VERSION}} =
First directory release.
