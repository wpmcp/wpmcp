=== WP MCP - MCP Server with Snapshot Undo for AI Agents ===
Contributors: fahdi
Tags: mcp, ai, ai agent, automation, undo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.94
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
* WooCommerce, ACF, Meta Box, JetEngine, Pods, TranslatePress, Yoast, Rank Math, SEOPress, The SEO Framework, SureRank, Slim SEO, Redirection; with Pro, All in One SEO
* Forms: Contact Form 7 (forms, fields, notifications, and Flamingo-stored entries), Forminator, SureForms, MetForm; with Pro, the forms adapter pack for Gravity Forms, WPForms, Formidable, Ninja Forms and Fluent Forms
* Events, donations, memberships (read)
* BuddyPress: groups, group members, activity moderation and extended profile fields
* Site operations status (read): UpdraftPlus, Duplicator, Solid Security, MonsterInsights and W3 Total Cache, plus W3 Total Cache purge
* With Pro, FunnelKit funnels and their steps (read), with linked pages, products and step counts
* Media library plus stock image imports
* REST passthrough for anything else, still snapshotted

= Free vs Pro =

The free plugin is fully functional: the MCP server, the safety core, snapshots and rollback, Gutenberg building, and the integration read tools. Snapshot history keeps the last 20 operations on every install, free and Pro alike, and the `wpmcp_snapshot_history_limit` filter raises or lowers that number on any site at no cost.

WP MCP Pro adds deep Elementor editing and building, custom widget/block builders, the forms adapter pack (Gravity Forms, WPForms, Formidable, Ninja Forms, Fluent Forms: forms, fields, notifications, entries and entry status), cloud sync for your widget and block specs, and priority support. See https://wpmcp-pro.com/pricing.html

= Privacy =

The plugin collects nothing about you and sends nothing to us. Its only scheduled task is a daily local cleanup of expired OAuth tokens; nothing on the schedule ever makes a network request, and neither does activation. Every host it can reach is listed under "External services" below, and each request happens only while you or your agent are running the ability that needs it, with one exception: once you have connected WP MCP Cloud, the announcements feed checks it for notices when an administrator opens a WP MCP screen (see the WP MCP Cloud entry below). Licensing (Freemius) and WP MCP Cloud sync are opt-in and inactive until you connect them. On activation Freemius shows its stock opt-in screen, which defaults to off and carries a Skip link. Skip or decline it and no connection is made, no licence data is exchanged, and the plugin keeps working; the one path that can still reach Freemius afterwards is the optional deactivation feedback form on the Plugins screen: if you submit it, the reason you enter is stored locally and sent to Freemius when the plugin is deleted, and if you untick "anonymous feedback" on that form your display name and email are sent as well. The api.freemius.com entry below spells this out.

== External services ==

* api.wordpress.org, core checksums - fetched by scan-security so modified core files can be reported. Sends the WordPress version and site locale under a WPMCP-Security-Scanner/1.0 user agent. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org, plugin directory - scan-security also asks whether any active plugin has been closed, sending the directory slug of each plugin it looks up (capped per run, then cached) through core's plugins_api(). Core's standard user agent goes with it, which carries the WordPress version and this site's address. Privacy policy: https://wordpress.org/about/privacy/
* api.wordpress.org and downloads.wordpress.org, plugin and theme directory - search-plugins, get-plugin-info, install-plugin, update-plugin, search-themes, install-theme and update-theme send your search terms or a directory slug through core's plugins_api()/themes_api(), again with core's standard user agent, and installs and updates download the package archive from downloads.wordpress.org. Only directory slugs are accepted, never an arbitrary zip URL. Privacy policy: https://wordpress.org/about/privacy/
* api.openverse.org - search-stock-images. Openverse is the default provider, used whenever no other provider is named, and needs no key or setup, so it is not opt-in: running the search is what sends the request. Sends the search terms and paging under a WPMCP-Stock-Search/1.0 user agent. Terms: https://openverse.org/terms Privacy policy: https://openverse.org/privacy
* api.pexels.com - search-stock-images, when the Pexels provider is used and you have saved a Pexels key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://www.pexels.com/terms-of-service/ Privacy policy: https://www.pexels.com/privacy-policy/
* api.unsplash.com - search-stock-images, when the Unsplash provider is used and you have saved an Unsplash key. Sends the search terms, paging and your key, under the same pinned user agent. Terms: https://unsplash.com/terms Privacy policy: https://unsplash.com/privacy
* api.freemius.com - licensing through the Freemius SDK. This is the one entry not tied to a tool. On activation the SDK shows its stock opt-in screen, which defaults to off and carries a Skip link; skip or decline it and the SDK sends nothing. Once you have opted in, there or later from the WP MCP > Account page, the SDK talks to Freemius during admin page loads and its own periodic sync. One path is independent of that choice: the optional deactivation feedback form on the Plugins screen. If you submit it, the reason you enter is stored locally and sent here when the plugin is deleted (uninstalled), whether or not you opted in; if you also untick "anonymous feedback" on that form, the SDK's opt-in call sends your display name and email along with the site details the opt-in screen lists. Terms: https://freemius.com/terms/ Privacy policy: https://freemius.com/privacy/
* api.anthropic.com - the in-admin AI chat (Pro), opt-in and bring-your-own-key. Nothing is sent until an administrator saves their own Anthropic API key on the WP MCP > Chat screen and sends a message; there is no shared or built-in key. Each chat step sends, from this server, that administrator's key, a system prompt carrying the site name, site URL, their username and the names of the tools the chat may use, the tool schemas it has loaded, and the conversation so far: their messages, the assistant's replies, and the results of the tools it ran, which can include site content those tools read. The key is stored encrypted per user and is never sent to the browser. Terms: https://www.anthropic.com/legal/commercial-terms Privacy policy: https://www.anthropic.com/legal/privacy
* api.dataforseo.com - keyword and backlink data (Pro), opt-in and bring-your-own-key. Nothing is sent until an administrator saves their own DataForSEO API login and password with set-seo-data-key; there is no shared or built-in account. After that, the analyze-seo keywords op sends the keywords you ask about with the location and language codes, and the backlinks op sends the domain or URL you name, each with your credentials in the Authorization header under a WPMCP-SEO-Data/1.0 user agent. Answers are cached on this site for a day (filterable with wpmcp_seo_data_cache_ttl), so a repeat lookup sends nothing. The credentials are stored encrypted and never returned by any tool. Terms: https://dataforseo.com/terms-of-service Privacy policy: https://dataforseo.com/privacy-policy
* api.wordpress.org/patterns and wordpress.org/patterns/wp-json, the WordPress.org Pattern Directory (Pro) - the block-suites list-patterns and import-pattern ops. Nothing is sent unless you or your agent ask for this source: list-patterns with source "directory" sends the search terms, category, paging and your locale, and turns a category name into its directory id with one lookup of the directory's category list on wordpress.org/patterns/wp-json; import-pattern with a directory:<id> name fetches that one pattern. Requests go out under a WPMCP-Pattern-Directory/1.0 user agent, so your site address is not sent, and answers are cached on this site for an hour (filterable with wpmcp_pattern_directory_cache_ttl), so a repeat browse or import sends nothing. A directory import also downloads the pattern's images from pd.w.org and s.w.org (WordPress.org's pattern image hosts) or from the allowlisted media hosts below; images on any other host are left at their URLs and reported. Add hosts with the wpmcp_pattern_directory_image_hosts filter. Privacy policy: https://wordpress.org/about/privacy/
* websitedemos.net, Spectra's pattern library (Pro) - the block-suites list-patterns and import-pattern ops. Nothing is sent unless you or your agent ask for this source while Spectra is active: list-patterns with source "spectra" sends the search terms, category and paging to the library's public WordPress REST API, after looking up the library's category and filter terms there; import-pattern with a spectra:<id> name fetches that one pattern. Only free Gutenberg block patterns are offered, and no license key, purchase key or site address is sent: requests go out under a WPMCP-Spectra-Library/1.0 user agent. Answers are cached on this site for a day (filterable with wpmcp_spectra_library_cache_ttl), so a repeat browse or import sends nothing. An import also downloads the pattern's images from websitedemos.net or from the allowlisted media hosts below; images on any other host are left at their URLs and reported. Add hosts with the wpmcp_spectra_library_image_hosts filter. Terms: https://store.brainstormforce.com/terms-and-conditions/ Privacy policy: https://store.brainstormforce.com/privacy-policy/
* WP MCP Cloud (the cloud URL you configure) - nothing is sent until you run cloud-connect with a cloud URL and API key you supply, and every request after that goes only to that URL with your API key in the Authorization header. cloud-connect verifies the key by fetching your account, cloud-push-assets sends the widget and block specs you push, and cloud-list-assets and cloud-pull-assets fetch the specs saved in your account. cloud-push-settings sends this site's governance, exposure and skills settings and identity scopes (never passwords, keys or tokens), cloud-apply-settings fetches the settings saved in your account, cloud-marketplace-browse fetches marketplace listings, and cloud-marketplace-install fetches the one listing you install. The announcements feed also fetches notices (GET /announcements, no site content) when an administrator opens a WP MCP admin screen, at most once a day, or once an hour after a failed fetch. Once connected, it also renews its sign-in token with that same URL over https. Terms: https://wpmcp-pro.com/terms.html Privacy policy: https://wpmcp-pro.com/privacy.html
* Your own migration target - push-site-archive sends a site-backup archive (the full database, including password hashes, and wp-content if the archive has it) to the target_url you or your agent supply, signed in with credentials for that site, and only after you have allowed outgoing migrations with WPMCP_ALLOW_OUTGOING_MIGRATIONS. It contacts no other host. The receiving site must allow incoming migrations itself, and its owner's terms and privacy policy apply to what it stores.
* Allowlisted media hosts - import-stock-image and upload-svg download the file you picked from a default allowlist of images.pexels.com, images.unsplash.com, plus.unsplash.com, upload.wikimedia.org (Wikimedia Commons, terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use privacy policy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) and staticflickr.com (Flickr, terms: https://www.flickr.com/help/terms privacy policy: https://www.flickr.com/help/privacy), matched on the host or a subdomain of it. The site owner can change that list with the wpmcp_remote_media_allowed_hosts filter. The download carries WordPress's standard user agent.
* Any host you name yourself - sideload-image passes the URL you or your agent supply to core's media_sideload_image(), so it can fetch an image from anywhere. It is not covered by the allowlist above; disable the ability if you do not want that reach.
* Any URL you measure - analyze-performance fetches the URL you give it under a WPMCP-Performance-Analyzer/1.0 user agent, refusing private, loopback and reserved addresses and following no redirects.
* This site itself - the connection self-test calls this site's own REST route, scan-security fetches this site's front page to read its security headers, get-rendered-html fetches a page of this site (never another host, and redirects off the site are refused), and the analytics abilities call this site's own URL. These are loopback requests to your own server.

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

= 0.8.94 =
* Ready for the next WordPress MCP adapter: both the HTTP endpoint and the stdio transport now speak MCP protocol 2026-07-28 (sessionless discovery via server/discover) alongside 2025-11-25.
* When the canonical MCP Adapter plugin is active, WP MCP now uses it instead of its bundled copy, so the two never conflict.
* The server card lists every protocol revision the endpoint answers.

= 0.8.93 =
* New: get-site-health runs the Site Health tests, including those other plugins add, and returns each result's status, label, badge, description and recommended action as plain text.
* Async Site Health tests run within a time bound (default 10 seconds, up to 30); any that do not finish in time are reported as not completed instead of holding the call open.
* get-site-health can run a subset of tests, or return the last full run from cache without rerunning anything.

= 0.8.92 =
* Pro: Browse and import Spectra's remote pattern library through the block suite pattern tools while Spectra is active. Only free Gutenberg block patterns are offered, each import is one undoable write, and its images are brought into the Media Library through the same guarded download as other remote media.
* The library is contacted only when an agent asks for it, with no license key and no site address sent, and answers are cached for a day. See External services in the readme.

= 0.8.91 =
* FunnelKit funnel reads now give a stable reason code with every count or list they cannot read, so an unavailable value is never mistaken for zero or none.
* FunnelKit upsell steps find their offers through the upsell add-on itself when it is active, with fallbacks to the stored offer list, and each offer reports its views and accepts.

= 0.8.90 =
* New: classic Oxygen (4.x and earlier) pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole tree; untouched elements, including ones wpmcp does not know, keep their exact bytes, and every write is undoable with rollback.
* Classic Oxygen writes keep the JSON tree and Oxygen's signed shortcode copy in step through Oxygen itself, add an Oxygen revision, and refresh the page's generated CSS after writes and rollbacks. Pages still in the pre-4.0 format are read-only until saved in Oxygen 4.
* The content mirror now exports and restores classic Oxygen pages.

= 0.8.89 =
* Fix: rolling back a BuddyPress write now restores only the rows it saved, by id, so membership requests, notifications and replies that other members add after the write are kept.
* Fix: rolling back an activity change keeps the thread's reply numbering consistent when someone replied after the write.

= 0.8.88 =
* Fixed: rolling back a BuddyPress write no longer removes activity, notifications or other rows that someone else added while the write was running. Only the rows the write itself created are removed.
* Rollback now leaves in place, and reports, any row the write created that has since changed hands.

= 0.8.87 =
* New: Thrive Architect pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole layout; untouched markup, including elements wpmcp does not know, keeps its exact bytes, and every write is undoable with rollback.
* Thrive writes keep the Read More excerpt in step and make the page load Thrive's full styles and scripts until it is optimized again, so edited elements never render unstyled.
* The content mirror now exports and restores Avada, Oxygen 6 and Thrive Architect pages.

= 0.8.86 =
* Fix: BuddyPress writes now run through BuddyPress itself, so its hooks fire. A group created through the tools gets its "created the group" activity item, and add-ons listening for group, profile field and activity changes now hear about them.
* Rolling back a BuddyPress write also removes what BuddyPress added in response (activity items, notifications, accepted memberships) and puts back membership requests and thread numbering it changed.

= 0.8.85 =
* FunnelKit funnel reads now say why a count or offer list is unavailable, such as a paid FunnelKit add-on not being active, instead of returning zero or an empty list.
* FunnelKit step views are no longer reported as zero on sites where nothing records them.

= 0.8.84 =
* New: Avada (Fusion Builder) pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole layout; untouched elements keep their exact markup.
* Avada's per-page dynamic CSS is invalidated after every Avada write and rollback, so the page recompiles its styles instead of serving the old ones.

= 0.8.83 =
* Pro: Browse and import patterns from the WordPress.org Pattern Directory through the block suite pattern tools. Each import is one undoable write, and its images are brought into the Media Library through the same guarded download as other remote media.
* The Pattern Directory is contacted only when an agent asks for it. Answers are cached for an hour, and the site address is not sent. See External services in the readme.

= 0.8.82 =
* Security: rolling back a deleted post no longer restores commenters' email addresses, IP addresses or user agents for users without the moderate_comments capability. The post and its comments still come back, with those fields blank, and the rollback response says so.

= 0.8.81 =
* New: Oxygen 6 pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.
* Oxygen writes keep the stored layout byte for byte outside the edited nodes, including elements wpmcp does not know, and refresh the page's generated CSS through Oxygen when it is active.
* Page snapshots of Oxygen 6 pages now count elements and responsive overrides from the stored layout.

= 0.8.80 =
* Pro: Spectra blocks inserted by an agent now render with their styles on the front end. New blocks get the same selector settings Spectra's editor gives them.
* Block suite support is now checked against the real Kadence Blocks, GenerateBlocks, Spectra and Otter Blocks: each inserted block renders with markup and generated styles that match its unique id, and edits and rollbacks show up on the page right away.

= 0.8.79 =
* Pro: Read FunnelKit funnels and their steps. Agents can list funnels and see each step in order with its linked page, linked products, status and basic view and conversion counts, and no customer data is ever returned.

= 0.8.78 =
* New: BuddyPress integration. Read groups, group members, the activity stream and extended profile fields; create and update groups, edit profile field definitions, and hide or delete activity items, all snapshotted and undoable with rollback. The tools appear only while BuddyPress (or another supported data plugin) is active.
* Member email addresses are only shown to users who can list users, and activity meta such as IP addresses is never returned.

= 0.8.77 =
* get-rendered-html can now continue a read from a byte offset, returns the next offset to read from, and includes a content hash so agents can tell when a page changed between reads.
* get-rendered-html now checks that you can read the requested post, and explains that drafts cannot be rendered because the page is fetched as a logged-out visitor.
* get-rendered-html keeps every request pinned to this site's own address and never forwards cookies or login credentials.

= 0.8.76 =
* Pro: Spectra, Otter Blocks and Blocksy companion blocks join the block suite support. Agents can read their block schemas and insert or update their blocks with correct unique ids, and each suite's cached styles are rebuilt after writes and rollbacks.
* Pro: Browse block patterns by the block suite they use and import one into a post in a single undoable step. Remote images are brought into the Media Library through the same safe download checks as other remote media.

= 0.8.75 =
* WooCommerce payment gateways: list and read gateway settings with every key, secret, password and token masked, and change a gateway's enabled state, title, description, order or non-secret settings, with each change snapshotted for exact rollback.
* WooCommerce system status: read the status report by section with secrets masked, list the status tools, and run allowlisted maintenance tools such as clearing transients or regenerating lookup tables. Tools that delete data need confirm, and every run says why it cannot be rolled back.

= 0.8.74 =
* Security: undoing a change to a comment or product review now requires the capability to moderate comments (and, for a review, to edit its product), because the saved copy holds the commenter's email and IP address.
* Change sets and history never show a commenter's email, IP address or browser details.

= 0.8.73 =
* New: read-only status for UpdraftPlus, Duplicator, Solid Security, MonsterInsights and W3 Total Cache (last backups and schedules, backup packages, enabled security modules and last scan, analytics connection and tracking settings, enabled caches), with credentials and IP addresses never returned.
* New: purge every W3 Total Cache cache with purge-w3tc-cache (needs confirm).

= 0.8.72 =
* Internal: the bundled compliance checker no longer reports superglobal names that appear only in code comments or docblocks.

= 0.8.71 =
* New (Pro): analyze-seo can look up keyword search volume, difficulty, CPC and intent, and backlink counts for a domain or URL, from your own DataForSEO account. Save the credentials once with set-seo-data-key; they are stored encrypted and never returned.
* Lookups are cached for a day and back off automatically when the provider rate-limits, and without saved credentials nothing is sent.
* The External services section discloses the new, opt-in call to the DataForSEO API.

= 0.8.70 =
* New: product review moderation on the WooCommerce ops. List reviews by product, rating and status, approve, hold, mark as spam, trash, edit the text or reply as the store; every change can be rolled back exactly, rating and verified flag included.
* New: read-only WooCommerce totals reports for sales (by day, week or month), top sellers, orders by status, customers and coupons. They use the store's analytics tables when those are up to date and fall back to order queries otherwise, and return aggregate figures only.

= 0.8.69 =
* New: a git-friendly content mirror. export-content with mirror:true writes builder page data (Elementor, blocks, Bricks, Divi, WPBakery, Beaver Builder, Breakdance) as stable, pretty-printed JSON files under uploads, one per page.
* New: import-content with mirror:true restores a page from its mirror file as a snapshotted write that rollback-operation undoes.
* Fix: backslashes in Divi shortcode content are kept when the content is written.

= 0.8.68 =
* Fixed: empty cells in the admin ability grid show a plain hyphen.

= 0.8.67 =
* Internal: code comments and documentation cleanup, plus a shared cache helper for global variables. No change to plugin behavior.

= 0.8.66 =
* New: JetEngine, Pods and TranslatePress integrations. Read field definitions and read or write post field values for JetEngine and Pods (including Pods table storage), and read or write TranslatePress string translations, all snapshotted and undoable with rollback. The tools appear only while one of these plugins is active.
* Field value writes for JetEngine and Pods are off until a site opts in, the same as ACF and Meta Box.

= 0.8.65 =
* New: Breakdance pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.
* Breakdance writes keep the stored layout byte for byte outside the edited nodes and refresh the page's generated CSS through Breakdance when it is active.
* Page snapshots of Breakdance pages now count elements and responsive overrides from the stored layout.

= 0.8.64 =
* Fix: rolling back a shipping zone creation now removes the zone only while it is still the zone that was created; a different zone that reuses the id is left in place and the skipped restore is reported.
* Fix: rolling back a session that created and then edited or deleted a shipping zone now unwinds the edits first, newest first, before undoing the create.

= 0.8.63 =
* New: single, archive and search templates with dynamic data bindings. Bind post title, content, excerpt, featured image, author, date, permalink, terms, site name, tagline and logo, plus ACF fields when ACF is active, with display conditions and escaping per field type.
* New: list-dynamic-sources shows every bindable field for a template context, and template updates are snapshotted so one rollback restores them exactly.

= 0.8.62 =
* New: agents and directories can discover this site's MCP server before connecting, through a public server card, an AI catalog at /.well-known/ai-catalog.json and an agent skills index at /.well-known/agent-skills/index.json.
* Discovery documents carry public metadata only: no user data, and no disabled, paid or site-custom tools or skills are advertised. A filter turns them off.

= 0.8.61 =
* Fixed: a Redirection redirect create that is refused at the last moment no longer leaves an undo point behind, and undoing a create never removes a different redirect that took its place.
* Rolling back a whole session that created a Redirection redirect and then edited it now removes the redirect as expected.

= 0.8.60 =
* New: Redirection plugin support. Agents can list its groups and redirects and create, edit, enable, disable or delete redirects, with every change undoable.
* Redirects written through the Redirection plugin refuse loops, duplicate sources and unsafe targets before anything is saved.

= 0.8.59 =
* Custom widgets can now show live data: post, product and term lists, a navigation menu, breadcrumbs, the WooCommerce cart, and values from a remote JSON feed.
* Remote JSON is read only from https hosts you allow with the wpmcp_remote_json_allowed_hosts filter, never from private addresses, with a timeout, a size cap and caching.

= 0.8.58 =
* Pro: Kadence Blocks and GenerateBlocks support. Agents can read each suite's block schemas and insert or update its blocks with valid attributes and correct unique ids, and the blocks render with their generated CSS.
* Every block suite write is snapshotted and rolls back exactly, and GenerateBlocks' cached per-post CSS is rebuilt after both writes and rollbacks.

= 0.8.57 =
* Fix: the WooCommerce build no longer crashes when undoing a settings change. Any site on that build should update.
* The release builds now refuse to ship a hook that points at code the build leaves out.

= 0.8.56 =
* New: Elementor addon suite packs for Essential Addons, Premium Addons and Ultimate Addons. Agents can list each suite's widgets with their controls and switch its modules on or off, with every toggle undoable.
* Module toggles write the suite's own settings in its own format and are skipped cleanly when a suite is not active.

= 0.8.55 =
* New: extract-content can return a post's top keywords and 2 to 3 word phrases (pass keywords=N), ranked by frequency with the title and headings weighted higher.
* Keyword extraction reads builder content (blocks, shortcodes, Elementor and Bricks) as plain text, keeps non-English words whole and drops stopwords for the site language.

= 0.8.54 =
* New: manage WooCommerce shipping zones and methods (flat rate, free shipping, local pickup) and store webhooks through the woo-write tool. Every change can be rolled back exactly.
* Webhook signing secrets are never shown in responses, and delivery URLs must be public https addresses.

= 0.8.53 =
* Installing a plugin or theme from an uploaded ZIP no longer waits on the WordPress.org update check, so installs and their rollbacks work on sites without outbound network access.
* The test suite now fails any test that makes an unmocked HTTP request.

= 0.8.52 =
* New: export stored PHP snippets, custom blocks and custom widgets to one portable, checksummed JSON bundle and import it on another site, no cloud account needed. Every imported item lands inactive and is re-checked by its own store.
* Import refuses name collisions unless you ask it to rename, and the whole import can be undone with one rollback-session.
* Fix: a custom block keeps its name when its stored spec is saved again, instead of gaining a doubled "wpmcp-" prefix.

= 0.8.51 =
* New: create and edit WooCommerce orders through the woo-write tool (orders.create and orders.update): line items, addresses, shipping and fee lines, coupons, status and notes, with totals recalculated. No payment gateway is ever charged.
* Every order edit takes a full snapshot of the order, its items and totals, so rollback restores it exactly on both HPOS and legacy order storage; rolling back a created order moves it to the trash and returns its stock.

= 0.8.50 =
* Rolling back a post, page or ACF field group, post type or taxonomy now restores its last-modified date exactly, instead of resetting it to the time of the rollback.

= 0.8.49 =
* SEO support now covers Slim SEO, and with Pro, All in One SEO: titles, descriptions, canonical, robots and social fields per post and per term through the existing SEO tools.
* All in One SEO edits are snapshotted from its own tables and roll back exactly.

= 0.8.48 =
* WooCommerce brands: create, update, delete and assign product brands through the WooCommerce catalog ops, with every change snapshotted so rollback restores it exactly.
* Brand images must be an existing Media Library image or pass the remote media guard, and deleting a brand needs confirmation and reports how many products use it.

= 0.8.47 =
* The Requests tab of the audit log can now be filtered by date, user, tool and outcome, and exported as CSV for review.
* The CSV export is administrator-only and redacts tokens, secret-looking arguments and arguments a tool marks as secret.

= 0.8.46 =
* Undoing a theme settings change for Astra, Kadence, GeneratePress or Blocksy now refreshes the theme's generated CSS, so the site shows the restored styles right away.
* The refresh only runs for the active theme; rolling back settings of an inactive theme leaves its caches untouched.

= 0.8.45 =
* New: Beaver Builder pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.
* Beaver Builder writes keep the editor's draft in step with the live layout, refuse pages with unpublished editor changes, and clear the page's layout CSS and JS cache.
* Fix: rolling back a post now keeps backslashes inside object-valued post meta exactly as they were.

= 0.8.44 =
* New theme settings packs for Kadence, and for GeneratePress and Blocksy (Pro): read and change global colors, typography, container width and basic header and footer settings through each theme's own storage. Every change is snapshotted and restorable with rollback, and a pack only appears while its theme is active.

= 0.8.43 =
* New: create-comment and reply-to-comment post a comment or reply as the current user, approved (for moderators) or held for moderation.
* Comments created this way are part of the undo history: rollback-operation and rollback-session move them to the trash, so they can still be restored.

= 0.8.42 =
* New: WPBakery page support in the builder tools. Detect WPBakery pages, read their layout as an element tree, and update, add, remove or move elements, with every change undoable through rollback.

= 0.8.41 =
* New: create, update, move and delete classic sidebar widgets (including block widgets) with create-sidebar-widget, update-sidebar-widget, move-sidebar-widget and delete-sidebar-widget. Settings are sanitized by each widget's own update routine.
* Every widget change saves the widget's settings and the sidebar layout as one undo point, so a single rollback restores both.

= 0.8.40 =
* New: get-preview-link returns the preview URL for a draft, pending or scheduled post you can edit, so an agent can share a link to review before publishing.

= 0.8.39 =
* New: install a plugin or theme from a ZIP uploaded to the Media Library (off by default, requires confirm and the file's SHA-256; unsafe archives are refused before extraction, and rollback restores the previous version or removes the new one).
* New: search the wordpress.org theme directory.

= 0.8.38 =
* New: author ACF structure from an agent. Create and update field groups and their fields, register ACF post types and taxonomies, read and write options pages, list field types and validate values, all through the existing ACF tools with snapshot-first rollback.
* New: batch-update ACF field values across many posts in one call; the whole batch is validated first and runs under one session, so a single session rollback undoes it.
* Fix: rolling back a custom post type now restores its GUID exactly.

= 0.8.37 =
* New: the skills library is now available as MCP prompts and site context as MCP resources, so clients that support prompts and resources can use them directly. Both follow the same permissions and governance as the matching tools.
* New: prompts and resources work over both the HTTP and the WP-CLI stdio connection.

= 0.8.36 =
* Rolling back a session now moves posts it created to the trash: create-post, duplicate-post, create-custom-widget and create-custom-block record their creations in the undo history, so rollback-session and rollback-operation trash a created post (restore it from the trash) and deactivate a created widget or block spec. Nothing is permanently deleted.
* A change set built from a session now lists the posts, pages and widget or block specs that session created, not only the ones it edited.

= 0.8.35 =
* Verified on WordPress 7.1: each release package (directory, WooCommerce and full) was installed on a fresh 7.1 site and passed an MCP session with a snapshot and one-step rollback.

= 0.8.34 =
* New multi-site gateway: a gateway credential can be bound to a scoped identity, is accepted only on the MCP connection, and every call it makes is narrowed to that identity and recorded in the audit log.
* New cloud gateway provisioning and status, with an explicit gateway consent on cloud connect (off by default) and a kill switch (`wp wpmcp gateway-revoke`) that revokes the credential locally, even offline.
* The self-hosted proxy can route a tool call to one named site or broadcast it to every site, with a separate credential and session per site and a confirm gate for broadcast writes.

= 0.8.33 =
* New: import WooCommerce products in two steps. plan-product-import previews every row (create, update with a field-level diff, skip or error) without writing anything, and apply-product-import writes the approved plan, including variable products, variations and images, refusing if the store changed since.
* A whole product import is one undo session: rollback-session restores the updated products and removes the products, variations and images it created.

= 0.8.32 =
* New: push a site backup archive to another WordPress install over a secure connection, with a dry run by default, resumable chunked uploads and a restore plus URL rewrite on the target. Both sides must opt in before any migration can run.
* Improved: rewriting site URLs now takes a database safety archive first and reports how to undo the pass.

= 0.8.31 =
* New: Elementor v4 global variables (color, font and size design tokens) can now be listed, created, updated and deleted, with strict type and value checks, a usage report before any delete, and one-step rollback.
* Fix: a breakpoint-only font size in Elementor global typography now enables custom typography, so tablet and mobile sizes actually render.

= 0.8.30 =
* New find-replace-content ability: site-wide find and replace across post content, titles, excerpts and selected post meta, preview-only by default, with every applied pass undoable in one step via rollback-session.
* Serialized and JSON meta, block attributes and page-builder data are never corrupted; unsafe items are skipped and reported.
* Fixed: rolling back a post change no longer strips backslashes from content or meta.

= 0.8.29 =
* New forms tools for Contact Form 7: list forms, fields and mail notifications, and read, trash, restore or delete Flamingo entries, with every status change snapshotted for rollback. Entry deletion needs confirmation and is off by default.
* Forms submissions are now treated as user data: every forms integration requires an administrator-level capability to read or change entries, and forms tools register only while their forms plugin is active.
* Pro: a forms adapter pack for WPForms, Gravity Forms, Formidable, Ninja Forms and Fluent Forms, covering forms, fields, notifications, entries and reversible entry status changes. These integrations move from the free tier to Pro.

= 0.8.28 =
* New WooCommerce operations catalog (Pro): discover, read and write store data across products, variations, orders, refunds, coupons, customers, shipping, taxes, webhooks and settings through WooCommerce's own REST API, with WooCommerce's permission checks as the final gate.
* Every change to existing store data is snapshotted and reversible, batches of up to 25 changes share one session for a single rollback, and changes a rollback could not undo are refused.
* Destructive store operations such as deletes and refunds are off by default, need an explicit per-site opt-in and a confirmation on every call, and refunds never contact the payment gateway unless asked.

= 0.8.27 =
* New get-rendered-html ability returns the rendered front-end HTML a visitor sees for a published page on this site, in chunks, with optional script stripping or text-only output, so agents can verify edits against what visitors actually get.
* get-rendered-html only ever fetches this site's own address: other hosts, IP addresses, non-web schemes and redirects that leave the site are refused, and drafts, private and password-protected posts are not fetched.

= 0.8.26 =
* New custom widget builder (Pro): describe an Elementor widget as a data spec and the plugin compiles it into a real widget class. The generated PHP is linted against a closed allowlist before it touches disk, and it loads only from a hardened sandbox through a hash-verified manifest, so a tampered file stops loading.
* Compiling is opt-in through a filter and refuses without the edit_files capability or when DISALLOW_FILE_EDIT is set. Every control type escapes the same way in compiled and runtime rendering.

= 0.8.25 =
* New: sync your governance, exposure, skills and identity settings with WP MCP Cloud, with every synced change recorded and undoable through rollback.
* New: browse the WP MCP Cloud marketplace and install widget and block specs as inactive drafts, validated and sanitized before they are stored.

= 0.8.24 =
* Security: MCP OAuth access tokens are now bound to the MCP endpoint and no longer authenticate any other route on the site (the WordPress REST API, admin-ajax and so on). If a connected MCP client stops working after this update, reconnect it.
* OAuth discovery now names the MCP endpoint as the protected resource, lists the supported scope, is also served at the endpoint-specific well-known address, and unauthenticated MCP responses point clients to it.
* Refresh token redemption is now atomic, so two simultaneous refreshes with the same token can no longer both succeed.

= 0.8.23 =
* Deeper SEO support: SEOPress is now detected alongside Yoast, Rank Math, The SEO Framework and SureRank, and SEO edits now require permission to edit the target post.
* New Pro SEO tools: read and write term-level SEO, set OpenGraph and Twitter images, read resolved social meta, and generate meta tags and JSON-LD schema (Article, WebPage, LocalBusiness, Product). Every SEO write is snapshotted and reversible.

= 0.8.22 =
* New gateway credential tools: provision, inspect and revoke a site-local gateway credential with no cloud dependency. The client secret and refresh token are shown exactly once, re-provisioning rotates everything, and provision and revoke both require explicit confirmation.
* Refresh tokens now stop working when the user they belong to changes their password or is deleted. Existing refresh tokens keep working and pick up this protection on their next use.

= 0.8.21 =
* Fixed: undoing an Elementor edit could leave the page showing the undone styling, because the generated CSS file was not refreshed on rollback. Rollback of pages, the site kit and global classes, duplicated posts, and popup and import settings writes now clear Elementor's generated CSS and element render cache.
* New regenerate-elementor-css ability: rebuild Elementor's generated CSS for one page, or for the whole site with an explicit confirmation.
* Editing a page's raw Elementor data now clears only that page's caches instead of every page on the site.

= 0.8.20 =
* New: upload-media adds a file to the Media Library straight from base64 bytes, for agents that hold the file itself rather than a URL. Supports title, alt text, caption and a parent post.
* The file type is checked from the actual bytes against the site's allowed upload types, executables and SVG are refused, and the size is capped at the site upload limit (filterable with wpmcp_upload_media_max_bytes). Rolling the operation back deletes the upload and its files.

= 0.8.19 =
* Front-end redirects now read from the object cache instead of querying the database on every page view, and the cache refreshes automatically whenever a redirect is added, changed, removed or rolled back.
* Rolling back a set of database rows now refreshes the cache for every row it restored, even when a later row fails.
* Every remaining direct database query in the plugin now carries a documented reason, clearing the last WordPress coding standards warnings for direct and slow queries.

= 0.8.18 =
* The WooCommerce edition now ships the same WordPress.org-ready build as the main directory plugin, with no upgrade or licensing code in the package.
* Fixed a fatal error at boot in the WooCommerce edition caused by a missing cloud client class.
* The WooCommerce edition readme now discloses the optional cloud connection under External services.

= 0.8.17 =
* New: WooCommerce variations can be created and deleted, and up to 50 products or variations can be updated in one call, with the whole batch undoable as a single session.
* New: manage WooCommerce coupons (list, get, create, update, delete, and validate against store rules) and tax rates (list, create, update, delete), with snapshots so every change can be rolled back.
* Deletes of variations, coupons and tax rates stay off until the site opts in, and always require confirmation.

= 0.8.16 =
* Cloud credentials are now stored encrypted: the cloud URL, API key and tokens are sealed in one authenticated-encryption vault keyed to your site's salts, existing plaintext settings are migrated automatically, and nothing is ever stored unencrypted if encryption is unavailable.
* Cloud access tokens refresh safely: refreshes are serialized across requests, a lost race never discards a working token, and cloud-status now reports a read-only token status.
* Secrets are scrubbed from cloud and transport error messages before they reach an MCP client, and every cloud option is protected from generic option writes.

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

= 0.8.94 =
Ready for the next WordPress MCP adapter: both the HTTP endpoint and the stdio transport now speak MCP protocol 2026-07-28 (sessionless discovery via server/discover) alongside 2025-11-25.

= 0.8.93 =
New: get-site-health runs the Site Health tests, including those other plugins add, and returns each result's status, label, badge, description and recommended action as plain text.

= 0.8.92 =
Pro: Browse and import Spectra's remote pattern library through the block suite pattern tools while Spectra is active. Only free Gutenberg block patterns are offered, each import is one undoable write, and its images are brought into the Media Library through the same guarded download as other remote media.

= 0.8.91 =
FunnelKit funnel reads now give a stable reason code with every count or list they cannot read, so an unavailable value is never mistaken for zero or none.

= 0.8.90 =
New: classic Oxygen (4.x and earlier) pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole tree; untouched elements, including ones wpmcp does not know, keep their exact bytes, and every write is undoable with rollback.

= 0.8.89 =
Fix: rolling back a BuddyPress write now restores only the rows it saved, by id, so membership requests, notifications and replies that other members add after the write are kept.

= 0.8.88 =
Fixed: rolling back a BuddyPress write no longer removes activity, notifications or other rows that someone else added while the write was running. Only the rows the write itself created are removed.

= 0.8.87 =
New: Thrive Architect pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole layout; untouched markup, including elements wpmcp does not know, keeps its exact bytes, and every write is undoable with rollback.

= 0.8.86 =
Fix: BuddyPress writes now run through BuddyPress itself, so its hooks fire. A group created through the tools gets its "created the group" activity item, and add-ons listening for group, profile field and activity changes now hear about them.

= 0.8.85 =
FunnelKit funnel reads now say why a count or offer list is unavailable, such as a paid FunnelKit add-on not being active, instead of returning zero or an empty list.

= 0.8.84 =
New: Avada (Fusion Builder) pages can now be read and edited structurally through get-builder-content and update-builder-content. Update, add, remove or move one element by path, or replace the whole layout; untouched elements keep their exact markup.

= 0.8.83 =
Pro: Browse and import patterns from the WordPress.org Pattern Directory through the block suite pattern tools. Each import is one undoable write, and its images are brought into the Media Library through the same guarded download as other remote media.

= 0.8.82 =
Security: rolling back a deleted post no longer restores commenters' email addresses, IP addresses or user agents for users without the moderate_comments capability. The post and its comments still come back, with those fields blank, and the rollback response says so.

= 0.8.81 =
New: Oxygen 6 pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.

= 0.8.80 =
Pro: Spectra blocks inserted by an agent now render with their styles on the front end. New blocks get the same selector settings Spectra's editor gives them.

= 0.8.79 =
Pro: Read FunnelKit funnels and their steps. Agents can list funnels and see each step in order with its linked page, linked products, status and basic view and conversion counts, and no customer data is ever returned.

= 0.8.78 =
New: BuddyPress integration. Read groups, group members, the activity stream and extended profile fields; create and update groups, edit profile field definitions, and hide or delete activity items, all snapshotted and undoable with rollback. The tools appear only while BuddyPress (or another supported data plugin) is active.

= 0.8.77 =
get-rendered-html can now continue a read from a byte offset, returns the next offset to read from, and includes a content hash so agents can tell when a page changed between reads.

= 0.8.76 =
Pro: Spectra, Otter Blocks and Blocksy companion blocks join the block suite support. Agents can read their block schemas and insert or update their blocks with correct unique ids, and each suite's cached styles are rebuilt after writes and rollbacks.

= 0.8.75 =
WooCommerce payment gateways: list and read gateway settings with every key, secret, password and token masked, and change a gateway's enabled state, title, description, order or non-secret settings, with each change snapshotted for exact rollback.

= 0.8.74 =
Security: undoing a change to a comment or product review now requires the capability to moderate comments (and, for a review, to edit its product), because the saved copy holds the commenter's email and IP address.

= 0.8.73 =
New: read-only status for UpdraftPlus, Duplicator, Solid Security, MonsterInsights and W3 Total Cache (last backups and schedules, backup packages, enabled security modules and last scan, analytics connection and tracking settings, enabled caches), with credentials and IP addresses never returned.

= 0.8.72 =
Internal: the bundled compliance checker no longer reports superglobal names that appear only in code comments or docblocks.

= 0.8.71 =
New (Pro): analyze-seo can look up keyword search volume, difficulty, CPC and intent, and backlink counts for a domain or URL, from your own DataForSEO account. Save the credentials once with set-seo-data-key; they are stored encrypted and never returned.

= 0.8.70 =
New: product review moderation on the WooCommerce ops. List reviews by product, rating and status, approve, hold, mark as spam, trash, edit the text or reply as the store; every change can be rolled back exactly, rating and verified flag included.

= 0.8.69 =
New: a git-friendly content mirror. export-content with mirror:true writes builder page data (Elementor, blocks, Bricks, Divi, WPBakery, Beaver Builder, Breakdance) as stable, pretty-printed JSON files under uploads, one per page.

= 0.8.68 =
Fixed: empty cells in the admin ability grid show a plain hyphen.

= 0.8.67 =
Internal: code comments and documentation cleanup, plus a shared cache helper for global variables. No change to plugin behavior.

= 0.8.66 =
New: JetEngine, Pods and TranslatePress integrations. Read field definitions and read or write post field values for JetEngine and Pods (including Pods table storage), and read or write TranslatePress string translations, all snapshotted and undoable with rollback. The tools appear only while one of these plugins is active.

= 0.8.65 =
New: Breakdance pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.

= 0.8.64 =
Fix: rolling back a shipping zone creation now removes the zone only while it is still the zone that was created; a different zone that reuses the id is left in place and the skipped restore is reported.

= 0.8.63 =
New: single, archive and search templates with dynamic data bindings. Bind post title, content, excerpt, featured image, author, date, permalink, terms, site name, tagline and logo, plus ACF fields when ACF is active, with display conditions and escaping per field type.

= 0.8.62 =
New: agents and directories can discover this site's MCP server before connecting, through a public server card, an AI catalog at /.well-known/ai-catalog.json and an agent skills index at /.well-known/agent-skills/index.json.

= 0.8.61 =
Fixed: a Redirection redirect create that is refused at the last moment no longer leaves an undo point behind, and undoing a create never removes a different redirect that took its place.

= 0.8.60 =
New: Redirection plugin support. Agents can list its groups and redirects and create, edit, enable, disable or delete redirects, with every change undoable.

= 0.8.59 =
Custom widgets can now show live data: post, product and term lists, a navigation menu, breadcrumbs, the WooCommerce cart, and values from a remote JSON feed.

= 0.8.58 =
Pro: Kadence Blocks and GenerateBlocks support. Agents can read each suite's block schemas and insert or update its blocks with valid attributes and correct unique ids, and the blocks render with their generated CSS.

= 0.8.57 =
Fix: the WooCommerce build no longer crashes when undoing a settings change. Any site on that build should update.

= 0.8.56 =
New: Elementor addon suite packs for Essential Addons, Premium Addons and Ultimate Addons. Agents can list each suite's widgets with their controls and switch its modules on or off, with every toggle undoable.

= 0.8.55 =
New: extract-content can return a post's top keywords and 2 to 3 word phrases (pass keywords=N), ranked by frequency with the title and headings weighted higher.

= 0.8.54 =
New: manage WooCommerce shipping zones and methods (flat rate, free shipping, local pickup) and store webhooks through the woo-write tool. Every change can be rolled back exactly.

= 0.8.53 =
Installing a plugin or theme from an uploaded ZIP no longer waits on the WordPress.org update check, so installs and their rollbacks work on sites without outbound network access.

= 0.8.52 =
New: export stored PHP snippets, custom blocks and custom widgets to one portable, checksummed JSON bundle and import it on another site, no cloud account needed. Every imported item lands inactive and is re-checked by its own store.

= 0.8.51 =
New: create and edit WooCommerce orders through the woo-write tool (orders.create and orders.update): line items, addresses, shipping and fee lines, coupons, status and notes, with totals recalculated. No payment gateway is ever charged.

= 0.8.50 =
Rolling back a post, page or ACF field group, post type or taxonomy now restores its last-modified date exactly, instead of resetting it to the time of the rollback.

= 0.8.49 =
SEO support now covers Slim SEO, and with Pro, All in One SEO: titles, descriptions, canonical, robots and social fields per post and per term through the existing SEO tools.

= 0.8.48 =
WooCommerce brands: create, update, delete and assign product brands through the WooCommerce catalog ops, with every change snapshotted so rollback restores it exactly.

= 0.8.47 =
The Requests tab of the audit log can now be filtered by date, user, tool and outcome, and exported as CSV for review.

= 0.8.46 =
Undoing a theme settings change for Astra, Kadence, GeneratePress or Blocksy now refreshes the theme's generated CSS, so the site shows the restored styles right away.

= 0.8.45 =
New: Beaver Builder pages can be read and edited through the builder tools: detection, a node tree read, and node updates, adds, removes and moves or a whole-layout write, each undoable with rollback.

= 0.8.44 =
New theme settings packs for Kadence, and for GeneratePress and Blocksy (Pro): read and change global colors, typography, container width and basic header and footer settings through each theme's own storage. Every change is snapshotted and restorable with rollback, and a pack only appears while its theme is active.

= 0.8.43 =
New: create-comment and reply-to-comment post a comment or reply as the current user, approved (for moderators) or held for moderation.

= 0.8.42 =
New: WPBakery page support in the builder tools. Detect WPBakery pages, read their layout as an element tree, and update, add, remove or move elements, with every change undoable through rollback.

= 0.8.41 =
New: create, update, move and delete classic sidebar widgets (including block widgets) with create-sidebar-widget, update-sidebar-widget, move-sidebar-widget and delete-sidebar-widget. Settings are sanitized by each widget's own update routine.

= 0.8.40 =
New: get-preview-link returns the preview URL for a draft, pending or scheduled post you can edit, so an agent can share a link to review before publishing.

= 0.8.39 =
New: install a plugin or theme from a ZIP uploaded to the Media Library (off by default, requires confirm and the file's SHA-256; unsafe archives are refused before extraction, and rollback restores the previous version or removes the new one).

= 0.8.38 =
New: author ACF structure from an agent. Create and update field groups and their fields, register ACF post types and taxonomies, read and write options pages, list field types and validate values, all through the existing ACF tools with snapshot-first rollback.

= 0.8.37 =
New: the skills library is now available as MCP prompts and site context as MCP resources, so clients that support prompts and resources can use them directly. Both follow the same permissions and governance as the matching tools.

= 0.8.36 =
Rolling back a session now moves posts it created to the trash: create-post, duplicate-post, create-custom-widget and create-custom-block record their creations in the undo history, so rollback-session and rollback-operation trash a created post (restore it from the trash) and deactivate a created widget or block spec. Nothing is permanently deleted.

= 0.8.35 =
Verified on WordPress 7.1: each release package (directory, WooCommerce and full) was installed on a fresh 7.1 site and passed an MCP session with a snapshot and one-step rollback.

= 0.8.34 =
New multi-site gateway: a gateway credential can be bound to a scoped identity, is accepted only on the MCP connection, and every call it makes is narrowed to that identity and recorded in the audit log.

= 0.8.33 =
New: import WooCommerce products in two steps. plan-product-import previews every row (create, update with a field-level diff, skip or error) without writing anything, and apply-product-import writes the approved plan, including variable products, variations and images, refusing if the store changed since.

= 0.8.32 =
New: push a site backup archive to another WordPress install over a secure connection, with a dry run by default, resumable chunked uploads and a restore plus URL rewrite on the target. Both sides must opt in before any migration can run.

= 0.8.31 =
New: Elementor v4 global variables (color, font and size design tokens) can now be listed, created, updated and deleted, with strict type and value checks, a usage report before any delete, and one-step rollback.

= 0.8.30 =
New find-replace-content ability: site-wide find and replace across post content, titles, excerpts and selected post meta, preview-only by default, with every applied pass undoable in one step via rollback-session.

= 0.8.29 =
New forms tools for Contact Form 7: list forms, fields and mail notifications, and read, trash, restore or delete Flamingo entries, with every status change snapshotted for rollback. Entry deletion needs confirmation and is off by default.

= 0.8.28 =
New WooCommerce operations catalog (Pro): discover, read and write store data across products, variations, orders, refunds, coupons, customers, shipping, taxes, webhooks and settings through WooCommerce's own REST API, with WooCommerce's permission checks as the final gate.

= 0.8.27 =
New get-rendered-html ability returns the rendered front-end HTML a visitor sees for a published page on this site, in chunks, with optional script stripping or text-only output, so agents can verify edits against what visitors actually get.

= 0.8.26 =
New custom widget builder (Pro): describe an Elementor widget as a data spec and the plugin compiles it into a real widget class. The generated PHP is linted against a closed allowlist before it touches disk, and it loads only from a hardened sandbox through a hash-verified manifest, so a tampered file stops loading.

= 0.8.25 =
New: sync your governance, exposure, skills and identity settings with WP MCP Cloud, with every synced change recorded and undoable through rollback.

= 0.8.24 =
Security: MCP OAuth access tokens are now bound to the MCP endpoint and no longer authenticate any other route on the site (the WordPress REST API, admin-ajax and so on). If a connected MCP client stops working after this update, reconnect it.

= 0.8.23 =
Deeper SEO support: SEOPress is now detected alongside Yoast, Rank Math, The SEO Framework and SureRank, and SEO edits now require permission to edit the target post.

= 0.8.22 =
New gateway credential tools: provision, inspect and revoke a site-local gateway credential with no cloud dependency. The client secret and refresh token are shown exactly once, re-provisioning rotates everything, and provision and revoke both require explicit confirmation.

= 0.8.21 =
Fixed: undoing an Elementor edit could leave the page showing the undone styling, because the generated CSS file was not refreshed on rollback. Rollback of pages, the site kit and global classes, duplicated posts, and popup and import settings writes now clear Elementor's generated CSS and element render cache.

= 0.8.20 =
New: upload-media adds a file to the Media Library straight from base64 bytes, for agents that hold the file itself rather than a URL. Supports title, alt text, caption and a parent post.

= 0.8.19 =
Front-end redirects now read from the object cache instead of querying the database on every page view, and the cache refreshes automatically whenever a redirect is added, changed, removed or rolled back.

= 0.8.18 =
The WooCommerce edition now ships the same WordPress.org-ready build as the main directory plugin, with no upgrade or licensing code in the package.

= 0.8.17 =
New: WooCommerce variations can be created and deleted, and up to 50 products or variations can be updated in one call, with the whole batch undoable as a single session.

= 0.8.16 =
Cloud credentials are now stored encrypted: the cloud URL, API key and tokens are sealed in one authenticated-encryption vault keyed to your site's salts, existing plaintext settings are migrated automatically, and nothing is ever stored unencrypted if encryption is unavailable.

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
