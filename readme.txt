=== WP MCP - MCP Server with Snapshot Undo for AI Agents ===
Contributors: fahdi
Tags: mcp, ai, ai agent, automation, undo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.15
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

The free plugin is fully functional: the MCP server, the safety core, snapshots and rollback, Gutenberg building, and the integration read tools. Snapshot history keeps the last 20 operations on every install, free and Pro alike, and the `wpmcp_snapshot_history_limit` filter raises or lowers that number on any site at no cost.

WP MCP Pro adds deep Elementor editing and building, custom widget/block builders, cloud sync for your widget and block specs, and priority support. See https://wpmcp-pro.com/pricing.html

= Privacy =

The plugin collects nothing about you and sends nothing to us. Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing on the schedule ever makes a network request, and neither does activation. Every host it can reach is listed under "External services" below, and each request happens only while you or your agent are running the ability that needs it, with one exception: once you have connected WP MCP Cloud, the announcements feed checks it for notices when an administrator opens a WP MCP screen (see the WP MCP Cloud entry below). Licensing (Freemius) and WP MCP Cloud sync are opt-in and inactive until you connect them. On activation Freemius shows its stock opt-in screen, which defaults to off and carries a Skip link. Skip or decline it and no connection is made, no licence data is exchanged, and the plugin keeps working; the one path that can still reach Freemius afterwards is the optional deactivation feedback form on the Plugins screen: if you submit it, the reason you enter is stored locally and sent to Freemius when the plugin is deleted, and if you untick "anonymous feedback" on that form your display name and email are sent as well. The api.freemius.com entry below spells this out.

== External services ==

* api.wordpress.org, core checksums - fetched by scan-security so modified core files can be reported. Sends the WordPress version and site locale under a WPMCP-Security-Scanner/1.0 user agent. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org, plugin directory - scan-security also asks whether any active plugin has been closed, sending the directory slug of each plugin it looks up (capped per run, then cached) through core's plugins_api(). Core's standard user agent goes with it, which carries the WordPress version and this site's address. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org and downloads.wordpress.org, plugin and theme directory - search-plugins, get-plugin-info, install-plugin, update-plugin, install-theme and update-theme send your search terms or a directory slug through core's plugins_api()/themes_api(), again with core's standard user agent, and installs and updates download the package archive from downloads.wordpress.org. Only directory slugs are accepted, never an arbitrary zip URL. Privacy policy: https://wordpress.org/about/privacy/
* api.openverse.org - search-stock-images. Openverse is the default provider, used whenever no other provider is named, and needs no key or setup, so it is not opt-in: running the search is what sends the request. Sends the search terms and paging under a WPMCP-Stock-Search/1.0 user agent. Terms: https://openverse.org/terms Privacy policy: https://openverse.org/privacy
* api.pexels.com - search-stock-images, when the Pexels provider is used and you have saved a Pexels key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/
* api.unsplash.com - search-stock-images, when the Unsplash provider is used and you have saved an Unsplash key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy
* api.freemius.com - licensing through the Freemius SDK. This is the one entry not tied to a tool. On activation the SDK shows its stock opt-in screen, which defaults to off and carries a Skip link; skip or decline it and the SDK sends nothing. Once you have opted in, there or later from the WP MCP > Account page, the SDK talks to Freemius during admin page loads and its own periodic sync. One path is independent of that choice: the optional deactivation feedback form on the Plugins screen. If you submit it, the reason you enter is stored locally and sent here when the plugin is deleted (uninstalled), whether or not you opted in; if you also untick "anonymous feedback" on that form, the SDK's opt-in call sends your display name and email along with the site details the opt-in screen lists. Terms: https://freemius.com/terms/ Privacy policy: https://freemius.com/privacy/
* api.anthropic.com - the in-admin AI chat (Pro), opt-in and bring-your-own-key. Nothing is sent until an administrator saves their own Anthropic API key on the WP MCP > Chat screen and sends a message; there is no shared or built-in key. Each chat step sends, from this server, that administrator's key, a system prompt carrying the site name, site URL, their username and the names of the tools the chat may use, the tool schemas it has loaded, and the conversation so far: their messages, the assistant's replies, and the results of the tools it ran, which can include site content those tools read. The key is stored encrypted per user and is never sent to the browser. Terms: https://www.anthropic.com/legal/commercial-terms Privacy policy: https://www.anthropic.com/legal/privacy
* WP MCP Cloud (the cloud URL you configure) - nothing is sent until you run cloud-connect with a cloud URL and API key you supply, and every request after that goes only to that URL with your API key in the Authorization header. cloud-connect verifies the key by fetching your account, cloud-push-assets sends the widget and block specs you push, and cloud-list-assets and cloud-pull-assets fetch the specs saved in your account. The announcements feed also fetches notices (GET /announcements, no site content) when an administrator opens a WP MCP admin screen, at most once a day, or once an hour after a failed fetch. Terms: https://wpmcp-pro.com/terms.html Privacy policy: https://wpmcp-pro.com/privacy.html
* Allowlisted media hosts - import-stock-image and upload-svg download the file you picked from a default allowlist of images.pexels.com, images.unsplash.com, plus.unsplash.com, upload.wikimedia.org (Wikimedia Commons, terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use privacy policy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and staticflickr.com (Flickr, terms: https://www.flickr.com/help/terms privacy policy: https://www.flickr.com/help/privacy), matched on the host or a subdomain of it. The site owner can change that list with the wpmcp_remote_media_allowed_hosts filter. The download carries WordPress's standard user agent.
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

Yes. The safety core and the MCP server are free and GPL. Pro adds convenience and depth (Elementor deep editing, builders, cloud sync), not safety. Snapshot retention is not part of that: it is the same flat, filterable number on every install.

== Changelog ==

= 0.8.15 =
* Hardened the release checks: an exception message that includes unescaped data now fails the automated plugin review, so this class of issue cannot return unnoticed.

= 0.8.14 =
* Internal: local test runs are now fully isolated from each other, even when two run in the same checkout. No change to the plugin itself.

= 0.8.13 =
* New: selective local-to-live sync. Build a change set of chosen pages, templates, patterns, menus and theme mods with their media, terms and template dependencies, inspect it, then apply it to another site with a dry run by default.
* Apply is snapshot-first and conflict-aware: objects changed on both sides are reported and left untouched unless explicitly forced, live-only data such as orders is never touched, and a whole sync can be undone with one session rollback.
* Objects created during a session (for example with create-post or duplicate-post) are not yet picked up automatically; list them explicitly when building the change set.

= 0.8.12 =
* Site backups can now be restored, not just checked: a real restore takes a safety archive first, puts the site in maintenance mode, validates every SQL statement before anything is written, and rolls back to the safety archive automatically if the import fails.
* Restores keep the acting administrator signed in when the same account exists in the restored database, can optionally swap in the backup's wp-content with a journalled rollback, and refuse to run twice at once.
* Database dumps no longer corrupt values containing a percent sign (such as permalink structures); older archives affected by this are repaired on restore with a warning.

= 0.8.11 =
* The External services section of the readme now names Openverse as the default, keyless stock image provider and links its privacy policy, plus the terms and privacy policy of every other listed service.
* search-stock-images falls back to Openverse when the provider is left empty, matching what the readme documents.
* The readme now discloses the WP MCP Cloud announcements check, which runs only after a cloud connection has been saved.

= 0.8.10 =
* New built-in theme builder: create header, footer and 404 site parts, show each one by include/exclude display conditions (entire site, front page, archives, search, 404, post types and single posts), and render them into both classic and block themes without a page builder.
* Site parts can be listed, previewed with a resolver that explains which part wins for a given page and why, edited, activated or deactivated, and deleted, with every change to an existing part snapshotted and reversible.

= 0.8.9 =
* Custom block builder: updating a block, changing its status and deleting it now take a snapshot first and return an operation ID, so each change can be undone with rollback.

= 0.8.8 =
* New custom code tools: add CSS scoped to a single page or a single Elementor element, sanitized when written and again when rendered, with a snapshot per page so each change can be rolled back on its own.
* Optional site-wide custom JavaScript, off by default. It works only when the site owner opens the `WPMCP_ALLOW_JS_INJECTION` gate and the caller can post unfiltered HTML, and every attempt is audited.
* The stored custom code options can no longer be read or changed through the generic option tools.

= 0.8.7 =
* Code quality: the remaining discouraged-function calls (serialization, base64 storage encoding and the opt-in PHP snippet runner) are now justified in place, and the coding-standards baseline is lower.

= 0.8.6 =
* Bridged third-party abilities are now governed one by one: each is checked against the same governance toggles, identity scope and block rules as built-in abilities before it runs, and refused abilities are hidden from discovery and logged.
* Sites can narrow the ability bridge to specific abilities or namespaces with the WPMCP_ABILITY_BRIDGE_ALLOWLIST constant or the wpmcp_ability_bridge_allowlist filter. The target ability's own permission check always still runs.

= 0.8.5 =
* New in-admin AI chat (Pro): talk to your site from wp-admin using your own Anthropic API key, stored encrypted per user and never sent to the browser. Chat runs every tool through the same permission, governance, rate-limit and snapshot path as any MCP client, under its own scoped identity that admins can narrow.
* Any chat action that is not a pure read is shown as a proposal with its exact arguments and runs only after you approve it, with a single-use, server-verified approval bound to that call.
* The External services section discloses the chat's call to the Anthropic API, which happens only when an admin opts in and sends a message.

= 0.8.4 =
* New child theme scaffolding in the theme tools: create a child of the active theme with one confirmed call. It is off by default, never activates the child, and is fully reversible from the rollback history.
* New Astra settings in the theme tools: read and update Astra's core colors and content width when Astra is the active theme, with a snapshot before every change.
* Session rollback undoes a child theme scaffold only after reverting any theme switch made in the same session.

= 0.8.3 =
* Internal: each local test run now uses its own clean WordPress install, so parallel test runs no longer interfere with each other. No change to the plugin itself.

= 0.8.2 =
* New PHP snippet store: create, list, get, update and delete PHP snippets as stored, validated objects. Snippets are always created inactive, critical validation findings block creation, and every change is snapshotted and reversible. Activation is a separate, governed Pro operation that never executes code, and rollback always restores a snippet inactive.
* New theme tools: read the active theme's context (framework, parent and child, block theme support, menus) and write allowlisted theme mods with a snapshot for rollback. Theme writes are off by default.
* Elementor atomic elements follow Elementor 4.3's schema, and the Elementor version the test suite installs is pinned.
* Option names are matched the way the database matches them, so case, accent and invisible-character variants cannot reach a protected option.
* The full test suite now runs locally before every push, on WordPress 7.1 and the 6.9 minimum, with a coverage floor.

= 0.8.1 =
* Snapshot retention is now one flat number for every install (`Snapshot_Store::DEFAULT_HISTORY_LIMIT`, 20), raisable or lowerable for free through the new `wpmcp_snapshot_history_limit` filter (any whole number of 1 or more; anything else falls back to 20). The paid unlimited-history branch is gone.
* Upgrading sites keep the history they already have. If your snapshot table is deeper than 20, nothing is deleted until you either set the filter or use the "trim history" button in the admin notice.
* Pruning now deletes at most 200 snapshots per write, so a site with a deep history catches up over several writes instead of inside one request.
* Tested up to WordPress 7.1. The test suite runs on 7.1 and on the 6.9 minimum on every change.
* Only one WP MCP build boots per request: the full plugin, the wp.org build and the WooCommerce build rank themselves by a flavor header, and a lower-ranked copy stands down with an admin notice instead of colliding on shared constants.
* Translations load from the plugin's own languages/ directory (Domain Path header).
* New abilities: rewrite-site-urls (URL migration), restore-site-backup (with a dry-run compatibility gate), WooCommerce variation and stock tools, and an opt-in bridge for third-party abilities that honour show_in_rest.
* Change sets are derived from the snapshot ledger; caches are invalidated after rollback, raw database writes and term writes.
* The External services section names every outbound host.
* Dropped the third-party trademark from the tag list.

= 0.8.0 =
* Launch release.
* 200+ abilities across content, builders, and integrations.
* Snapshot-before-every-write safety core with one-click and session rollback.
* Six-layer governance, audit log, scoped identities, OAuth 2.1.
* Elementor, Gutenberg, Bricks, Divi, WooCommerce, ACF, Meta Box, and major SEO/forms/events plugin integrations.
* WP MCP Cloud sync client for widget and block specs (Pro).
* Freemius licensing shows its stock, default-off opt-in screen on activation rather than deciding consent for you. Sites that ran a pre-release build under anonymous mode see that connect screen once after upgrading; Skip dismisses it and the plugin keeps working unchanged. The WordPress.org build ships no licensing SDK at all.

== Upgrade Notice ==

= 0.8.15 =
Hardened the release checks: an exception message that includes unescaped data now fails the automated plugin review, so this class of issue cannot return unnoticed.

= 0.8.14 =
Internal: local test runs are now fully isolated from each other, even when two run in the same checkout. No change to the plugin itself.

= 0.8.13 =
New: selective local-to-live sync. Build a change set of chosen pages, templates, patterns, menus and theme mods with their media, terms and template dependencies, inspect it, then apply it to another site with a dry run by default.

= 0.8.12 =
Site backups can now be restored, not just checked: a real restore takes a safety archive first, puts the site in maintenance mode, validates every SQL statement before anything is written, and rolls back to the safety archive automatically if the import fails.

= 0.8.11 =
The External services section of the readme now names Openverse as the default, keyless stock image provider and links its privacy policy, plus the terms and privacy policy of every other listed service.

= 0.8.10 =
New built-in theme builder: create header, footer and 404 site parts, show each one by include/exclude display conditions (entire site, front page, archives, search, 404, post types and single posts), and render them into both classic and block themes without a page builder.

= 0.8.9 =
Custom block builder: updating a block, changing its status and deleting it now take a snapshot first and return an operation ID, so each change can be undone with rollback.

= 0.8.8 =
New custom code tools: add CSS scoped to a single page or a single Elementor element, sanitized when written and again when rendered, with a snapshot per page so each change can be rolled back on its own.

= 0.8.7 =
Code quality: the remaining discouraged-function calls (serialization, base64 storage encoding and the opt-in PHP snippet runner) are now justified in place, and the coding-standards baseline is lower.

= 0.8.6 =
Bridged third-party abilities are now governed one by one: each is checked against the same governance toggles, identity scope and block rules as built-in abilities before it runs, and refused abilities are hidden from discovery and logged.

= 0.8.5 =
New in-admin AI chat (Pro): talk to your site from wp-admin using your own Anthropic API key, stored encrypted per user and never sent to the browser. Chat runs every tool through the same permission, governance, rate-limit and snapshot path as any MCP client, under its own scoped identity that admins can narrow.

= 0.8.4 =
New child theme scaffolding in the theme tools: create a child of the active theme with one confirmed call. It is off by default, never activates the child, and is fully reversible from the rollback history.

= 0.8.3 =
Internal: each local test run now uses its own clean WordPress install, so parallel test runs no longer interfere with each other. No change to the plugin itself.

= 0.8.2 =
New PHP snippet store: create, list, get, update and delete PHP snippets as stored, validated objects. Snippets are always created inactive, critical validation findings block creation, and every change is snapshotted and reversible. Activation is a separate, governed Pro operation that never executes code, and rollback always restores a snippet inactive.

= 0.8.1 =
Snapshot history is now the same 20 operations on every install, free and Pro alike. Nothing is deleted on upgrade: a site that already has a deeper history keeps it until you choose, either through the admin notice or the filter:
`add_filter( 'wpmcp_snapshot_history_limit', fn() => 500 );`
Tested up to WordPress 7.1. Adds the build coexistence guard (one WP MCP build per request), self-hosted translations, and new migration, backup and WooCommerce abilities. See the changelog.

= 0.8.0 =
First public release.
