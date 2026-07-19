<div align="center">

<img src="assets/wpmcp-icon.svg" width="112" alt="wpmcp logo">

# wpmcp

### The AI agent that builds and edits your WordPress site, and physically cannot wreck it.

An MCP server for WordPress with snapshot-before-every-write and one-click rollback baked into the core.

[![CI](https://github.com/fahdi/wpmcp/actions/workflows/ci.yml/badge.svg)](https://github.com/fahdi/wpmcp/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.9-21759B.svg)](https://wordpress.org)
[![PRs welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

</div>

---

Give an AI agent (Claude, Cursor, or any MCP client) the keys to your WordPress site and one thing becomes terrifying: a bad edit on a live client site that you cannot take back. Every other WordPress AI tool asks you to trust the model. **wpmcp does the opposite: it assumes the agent will get something wrong, and makes sure nothing it does is permanent.**

Every write is snapshotted before it happens. If a change breaks the page, it rolls back automatically. If you do not like what the agent did, you undo one operation, or the agent's entire session, with one click. That is the whole product: an AI site builder you can actually let near a live site.

> **Status:** early MVP. The safety engine and the first tools are shipped and tested. See the [Roadmap](#roadmap) for what is next.

## Why wpmcp

- **Recoverable by design.** No mutating tool can touch the database except through a wrapper that snapshots first. This is enforced in code, not by convention.
- **Builder-agnostic safety.** Snapshots are taken at the WordPress data layer (post content, meta, options), so rollback works for any page builder without parsing its format.
- **Runs inside WordPress.** A single plugin on the official [WordPress Abilities API](https://developer.wordpress.org/), not a separate local proxy process. One install, works with any MCP client.
- **Free and open.** GPL-2.0. The whole safety engine and the Gutenberg tooling are free.

## How it works

Every mutating tool routes through one orchestrator, `Safe_Mutation::run()`:

```
snapshot (before)  ->  apply the change  ->  verify  ->  ok?
     |                                          |         |
 stored in                              on failure:    return
 wp_wpmcp_snapshots                     auto-rollback   operation id
 (keyed by operation + session)         + raise error
```

1. **Snapshot.** Before any write, wpmcp captures the target's before-image (post content, title, status, and all meta) and stores it, compressed, in a dedicated table keyed by an operation id and an agent session id.
2. **Apply.** The tool performs its edit.
3. **Verify.** A lightweight check runs (for Gutenberg, the block markup must still parse). If it fails, the change is rolled back immediately and an error is raised.
4. **Rollback, on demand.** Undo a single operation, or unwind an agent's entire session back to its pre-session state, including purging any meta the agent added.

You can drive rollback from the AI (the `rollback-operation` and `rollback-session` tools) or from the **wpmcp** screen in wp-admin, where every agent operation is listed with a one-click Restore button.

## Requirements

| Dependency | Version |
| --- | --- |
| WordPress | >= 6.9 (bundles the Abilities API) |
| PHP | >= 8.1 |
| Composer | for installing from source |

## Installation

wpmcp is not yet on the wp.org plugin directory (planned). For now, install from source:

```bash
git clone https://github.com/fahdi/wpmcp.git wp-content/plugins/wpmcp
cd wp-content/plugins/wpmcp
composer install --no-dev
```

Then activate **wpmcp** from the Plugins screen in wp-admin.

## Connect your AI client

wpmcp exposes its tools over the Model Context Protocol. Authenticate with a WordPress [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (Users -> Profile -> Application Passwords).

Example for Claude Code, in your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "wpmcp": {
      "type": "http",
      "url": "https://your-site.com/wp-json/mcp/wpmcp-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_username:application-password"
      }
    }
  }
}
```

Generate the credential with:

```bash
echo -n "your-username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64
```

The same endpoint works with Cursor, Claude Desktop, and any MCP-compatible client.

## Available tools

| Tool | Type | What it does |
| --- | --- | --- |
| `get-page` | read | Read a page's title, content, and whether it is an Elementor page |
| `update-blocks` | write (safe) | Replace a page's Gutenberg block markup, snapshotted and verified |
| `list-operations` | safety | List recent agent operations (no snapshot payload leaked) |
| `rollback-operation` | safety | Undo a single operation by id |
| `rollback-session` | safety | Unwind an entire agent session to its pre-session state |
| `list-post-types` | read | List registered post types (posts, pages, custom post types) |
| `list-taxonomies` | read | List registered taxonomies (categories, tags, custom taxonomies) |
| `create-post` | write | Create a post, page, or custom post type, with terms and meta |
| `get-post` | read | Read a post's full content, status, terms, meta, and featured image |
| `update-post` | write (safe) | Partially update a post's fields, terms, meta, or featured image |
| `delete-post` | write (safe on force) | Trash a post by default, or permanently delete with `force: true` |
| `list-posts` | read | Search/list posts by type, status, search text, author, or parent |
| `set-post-terms` | write (safe) | Assign taxonomy terms to a post: replace, append, or remove |
| `get-media` | read | Read a Media Library attachment's full detail: sizes, dimensions, metadata, alt text, caption, description |
| `update-media` | write (safe) | Update an attachment's title, alt text, caption, and/or description |
| `delete-media` | write (safe) | Delete a Media Library attachment. Disabled by default, requires `confirm: true` |
| `sideload-image` | write | Download an image from a URL into the Media Library as a new attachment |
| `get-settings` | read | Read site settings (general, reading, writing, discussion, media, permalinks) with group/type/writable metadata |
| `update-settings` | write (safe) | Update allowlisted site settings; validates/coerces each value and applies the valid subset even if some keys fail |
| `list-users` | read | List users as safe summary rows (id, username, display name, email, roles); never returns password hashes |
| `get-user` | read | Read one user's profile detail plus an `is_admin` flag derived from live capabilities; never returns the password hash |
| `create-user` | write | Create a non-admin user; auto-generates a strong password (never returned) and emails the user; rejects admin and unknown roles |
| `update-user` | write (safe) | Update a non-admin user's profile fields; refuses admin-capable users; never changes role or password |
| `list-plugins` | read | List installed plugins with active status, protected-package flag, and pending update info |
| `activate-plugin` | write (safe) | Activate an installed plugin; snapshots the prior `active_plugins` option |
| `deactivate-plugin` | write (safe) | Deactivate a plugin; refuses protected packages (wpmcp, Elementor); snapshots the prior `active_plugins` option |
| `install-plugin` | write | Install a plugin from wordpress.org by slug, optionally activating it |
| `search-plugins` | read | Search the wordpress.org plugin directory by keyword, with optional tag/author filters and a capped `per_page` |
| `get-plugin-info` | read | Fetch full wordpress.org plugin directory info for a slug: version, rating, installs, homepage, download link, and compatibility |
| `update-plugin` | write (irreversible) | Update an installed plugin from wordpress.org. Disabled by default, requires `confirm: true`; not rollback-able |
| `delete-plugin` | write (irreversible) | Permanently delete an installed plugin's files. Disabled by default, requires `confirm: true`; refuses protected or active plugins; not rollback-able |
| `list-themes` | read | List installed themes with active status, parent theme, and pending update info |
| `switch-theme` | write (safe) | Activate (switch to) an installed theme; snapshots the prior `template`/`stylesheet` options |
| `install-theme` | write | Install a theme from wordpress.org by slug, optionally activating it |
| `update-theme` | write (irreversible) | Update an installed theme from wordpress.org. Disabled by default, requires `confirm: true`; not rollback-able |
| `delete-theme` | write (irreversible) | Permanently delete an installed theme's files. Disabled by default, requires `confirm: true`; refuses the active theme (or its active parent); not rollback-able |
| `list-tables` | read | List database tables with estimated row counts and sizes |
| `describe-table` | read | Return the columns, types, and keys of a database table |
| `query` | read | Run a read-only SQL query (`SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN`/`WITH`); writes, DDL, stacked statements, and file-access SQL are rejected before execution; results are capped |
| `insert-row` | write | Insert a row into a table via `$wpdb->insert()`. Disabled by default; refuses protected tables |
| `update-rows` | write (irreversible) | Update rows matching a mandatory equality WHERE via `$wpdb->update()`. Disabled by default; refuses protected tables; captures a before-image to the write audit log but is honest that it is not rollback-able (`recoverable: false`) |
| `delete-rows` | write (irreversible) | Delete rows matching a mandatory equality WHERE via `$wpdb->delete()`. Disabled by default, requires `confirm: true`; refuses protected tables; captures a before-image to the write audit log but is honest that it is not rollback-able (`recoverable: false`) |
| `read-file` | read | Read a file inside the WordPress install (core, plugins, themes, uploads); path confined to the install root |
| `list-directory` | read | List a directory's entries (files/dirs with size and mtime); optional bounded recursive listing |
| `search-files` | read | Search file contents for a substring across a directory tree; filterable by extension, results capped |
| `write-file` | write (safe) | Create or overwrite a file. Disabled by default; backs up an existing file first so the change is restorable |
| `edit-file` | write (safe) | Replace an exact string in a file (must match once unless `replace_all`). Disabled by default; backs up the original first |
| `delete-file` | write (safe) | Delete a file. Disabled by default, requires `confirm: true`; backs up the file first so the deletion is restorable |
| `analyze-performance` | read | Scan server config, WordPress internals (database size, autoloaded options, cron backlog, object cache, OPcache, plugin count), and a target page (defaults to the frontpage) for performance issues; returns a scored report (0-100, graded A-F) with ranked recommendations |
| `get-cache-status` | read | Report which caching layers are active: persistent object cache backend, OPcache, and any active page-cache plugin (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache) |
| `clear-cache` | write (safe) | Flush the object cache, delete all transients, reset OPcache when enabled, and clear any detected page-cache plugin; returns a per-layer summary. Safe and idempotent, so not snapshotted |
| `get-connection-info` | read | Return this site's MCP endpoint URL and ready-to-paste connection snippets for Claude Code, Cursor, and Claude Desktop, each using an Application Password placeholder (never a real credential) |
| `list-tool-catalog` | read | List every wpmcp ability registered on this site, grouped by domain, with each entry's tier, operation, capability, and read-only/destructive hints, plus a per-domain summary count |
| `list-tools` | read | List every registered tool with a curated summary, domain, operation, and tier; `full: true` adds complete descriptions and MCP annotations. The discovery entry point in compact mode |
| `get-tool-schema` | read | Read one tool's full contract by name: the exact input schema it was registered with, its complete description, and its MCP annotations |
| `call-tool` | dispatch | Invoke any wpmcp-registered tool by name with an arguments object. The target's own permission checks, rate limit, validation, and snapshot safety apply exactly as for a direct call |

Every write tool is wrapped in the safety engine, except `create-post`, `sideload-image`, `create-user`, `install-plugin`, `install-theme`, and `insert-row`: each only ever creates a brand new object, so there is nothing pre-existing to snapshot or roll back. Reads and rollbacks are gated by the `edit_posts` capability. `create-user` and `update-user` are additionally gated by the `create_users` and `edit_users` capabilities; the package tools are gated by their matching WordPress capability (`activate_plugins`, `install_plugins`, `update_plugins`, `delete_plugins`, `switch_themes`, `install_themes`, `update_themes`, `delete_themes`); all six database tools (reads included) are gated by `manage_options`, since raw table/row access is equivalent to phpMyAdmin-level access to the site. `delete-media` additionally requires a site to opt in via the `wpmcp_enable_delete_media` filter before it will run at all; `update-plugin`, `delete-plugin`, `update-theme`, and `delete-theme` are similarly disabled by default via their own `wpmcp_enable_*` filters and always require `confirm: true`, since updating or deleting package files is not rollback-able (no file backup; see issue #24) and each response says so honestly (`files_recoverable: false`). `update-settings` snapshots each changed option individually, `update-user` snapshots the user's profile fields and usermeta, and `activate-plugin`/`deactivate-plugin`/`switch-theme` snapshot the option(s) they change (the safety engine's snapshot/rollback supports WordPress options and users as well as posts), so any subset of a batch write can be undone via `rollback-operation`. There is deliberately no delete-user or role-change tool. `insert-row`, `update-rows`, and `delete-rows` are disabled by default via the `wpmcp_enable_db_writes` filter; `update-rows` and `delete-rows` additionally require a non-empty `where` (and `delete-rows` requires `confirm: true`) and refuse the `wp_users`/`wp_usermeta` tables (extend via the `wpmcp_db_protected_tables` filter). Because a generic arbitrary-table rollback is out of scope for the safety engine's snapshot/restore logic, these two capture a before-image to a capped audit log instead and report `recoverable: false` rather than claiming an undo they cannot perform. All six filesystem tools (reads included) are gated by `manage_options`, same reasoning as the database tools: raw filesystem access is at least as sensitive as raw table access. Every filesystem path is confined to the WordPress install root (`ABSPATH`) via `Filesystem_Guard::resolve_path()`, which canonicalizes through `realpath()` so path traversal (`../`), a symlink pointing outside the root, a null byte, and an absolute path escaping the root are all rejected the same way; `wp-config.php` and `.htaccess` are refused for every write/edit/delete regardless of path (extend via the `wpmcp_fs_protected_paths` filter). `write-file`, `edit-file`, and `delete-file` are disabled by default via the `wpmcp_enable_fs_writes` filter, additionally require the `edit_files` capability, and honor `DISALLOW_FILE_EDIT`; unlike the database write tools, a filesystem write's before-image is a full byte-for-byte backup copy (not just an audit-log record), so `recoverable: true` in the response is a real, tested guarantee: the original file can be restored exactly, not merely logged. `analyze-performance` is read-only and gated by `manage_options`, same reasoning as the database and filesystem tools: it exposes server, database, and page internals. Its optional page fetch is SSRF-safe: the target must resolve to this site's own host, any hostname whose resolved IP falls in a private, loopback, link-local, or reserved range is refused before any HTTP request is attempted, and the actual request goes through `wp_safe_remote_get()` as a second layer of defense. `get-cache-status` and `clear-cache` are gated by `manage_options`. `clear-cache` is deliberately not routed through the safety engine and does not touch the safety core: clearing a cache has no meaningful before-image to restore (the data is regenerated on demand) and the operation is safe and idempotent, so it carries `destructiveHint: false` rather than being treated as a destructive write. Each third-party page-cache plugin is detected by its signature functions/constants and cleared only through its own public API (`rocket_clean_domain()`, `w3tc_flush_all()`, `wp_cache_clear_cache()`, the `litespeed_purge_all` action, `wpfc_clear_all_cache()`), every call guarded by `function_exists`/`defined` so an absent plugin never fatals. `get-connection-info` and `list-tool-catalog` are both read-only, gated by `manage_options`, and never touch the safety core: the former only ever emits placeholder credential text (never a real Application Password), and the latter only reads metadata already public on each registered `Ability` object, never invoking a tool's handler. Generating a one-click `.mcpb` install bundle for Claude Desktop is deliberately out of scope for `get-connection-info` (see issue #18); it documents the manual `claude_desktop_config.json` path instead.

## Compact tool surface

wpmcp registers 160+ tools. Some MCP clients cap tool counts outright, and all of them pay token cost for every `tools/list`. **Compact mode** collapses the advertised surface to five tools — `list-tools`, `get-tool-schema`, `call-tool`, plus the connection basics `get-connection-info` and `get-site-context` — while every other tool stays reachable through `call-tool`.

Compact mode is exposure-only and off by default. It never changes which abilities are registered and it is not a permission boundary: a dispatched call runs the target tool's own capability check, governance, identity scope, license check, rate limit, input validation, and snapshot/rollback behavior exactly as a direct call would (a conformance suite sweeps the entire surface in both modes to prove it). `call-tool` refuses abilities that were not registered by wpmcp.

Choose the mode site-wide with the `wpmcp_tool_exposure_mode` option (`full` or `compact`), per scoped identity via the identity's `exposure` field (`create-identity` accepts it; it overrides the site setting for that agent), or in code via the `wpmcp_tool_exposure_mode` filter. The `wpmcp_compact_exposed_abilities` filter can add tools to the compact core (the three meta-tools are always included).

Measured `tools/list` payload (test environment, all optional plugins active): **full** 72,897 bytes across 154 tools; **compact** 2,790 bytes across 5 tools — a **96.2% reduction**. The numbers are pinned by a checked-in budget test (`tests/free/MCP/ToolsListBudgetTest.php`) and re-measured on every CI run.

## Free vs Pro

The free plugin (this repo) is fully functional: the safety engine, Gutenberg editing, one-click rollback, and the last 20 operations of history.

Pro (planned, via [Freemius](https://freemius.com/)) will add unlimited history and session rollback, Elementor deep editing, change previews, and priority support. The Pro gate (`WPMCP\Pro\Gate`) and Freemius bootstrap are wired from day one; the plugin degrades gracefully when the Pro SDK is absent.

Freemius is opt-in only at the integration level: `anonymous_mode` is enabled by default (see `WPMCP\Freemius\Bootstrap::config()`), so no telemetry opt-in gate is forced on activation. Going live on Pro requires two steps: register the plugin on freemius.com and fill `WPMCP_FS_ID` / `WPMCP_FS_PUBLIC_KEY` in `wpmcp.php`, then vendor the Freemius SDK at `vendor/freemius/start.php`.

## Roadmap

- [ ] Elementor deep editing (Pro)
- [ ] `preview-change` dry-run diffs before applying
- [ ] Session-aware retention so large agent sessions stay fully reversible on the free tier
- [ ] Broader snapshot capture (excerpt, parent)
- [ ] Visual before / after regression on edited pages
- [ ] Multi-site fleet management
- [ ] wp.org listing

## Known limitations

Free-tier history keeps the last 20 operations, which can bound how far `rollback-session` reaches on very large agent runs. Snapshot capture currently covers post content, title, status, meta, and taxonomy terms, but not every post field (e.g. excerpt, parent). Force-deleting media (or deleting without `MEDIA_TRASH` enabled) restores the media record on rollback but not the physical file bytes, until issue #24 lands; media force-delete is disabled by default. Details and mitigations are in the [design spec](docs/superpowers/specs/2026-07-12-wpmcp-mvp-design.md#known-limitations-mvp).

## Development

```bash
composer install       # install dev dependencies
composer test          # run the PHPUnit + WordPress integration suite
composer lint          # PSR-12 with WordPress-idiomatic naming
```

The test suite needs a MySQL or MariaDB database for the WordPress integration harness. See `.github/workflows/ci.yml` for the exact setup CI uses.

## Contributing

Issues and pull requests are welcome. Please keep the safety invariant intact: no tool may write to the database except through `Safe_Mutation::run()`, and every change ships with a test. See [CONTRIBUTING.md](CONTRIBUTING.md) if present, or open an issue to discuss larger changes first.

## Security

wpmcp edits live sites and executes agent instructions, so security is a first-class concern: admin actions are capability-checked and nonce-protected, all output is escaped, and all database access is parameterized. If you find a vulnerability, please open a private security advisory rather than a public issue.

## License

[GPL-2.0-or-later](LICENSE). Study it, fork it, ship it.
