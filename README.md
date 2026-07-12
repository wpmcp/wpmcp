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

Every write tool is wrapped in the safety engine, except `create-post`, `sideload-image`, and `create-user`: each only ever creates a brand new object, so there is nothing pre-existing to snapshot or roll back. Reads and rollbacks are gated by the `edit_posts` capability. `create-user` and `update-user` are additionally gated by the `create_users` and `edit_users` capabilities. `delete-media` additionally requires a site to opt in via the `wpmcp_enable_delete_media` filter before it will run at all. `update-settings` snapshots each changed option individually, and `update-user` snapshots the user's profile fields and usermeta (the safety engine's snapshot/rollback now supports WordPress options and users as well as posts), so any subset of a batch write can be undone via `rollback-operation`. There is deliberately no delete-user or role-change tool.

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
