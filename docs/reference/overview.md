# WordPress MCP server overview: what WP MCP is and why undo comes first

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

Give an AI agent (Claude, Cursor, or any MCP client) the keys to your WordPress site and the real risk is a bad edit on a live client site that you cannot take back. **wpmcp is built on the assumption that the agent will sometimes get it wrong, so every supported write can be undone.**

Every supported write is snapshotted before it happens. If a post-write check fails (for example, block markup that no longer parses), the change rolls back on its own. If you do not like what the agent did, you undo one operation, or the agent's entire session, from the AI or with one click in wp-admin. A few kinds of change cannot be snapshotted, and the safety model page lists them in [What cannot be undone](safety-model.md#what-cannot-be-undone) instead of leaving you to find out.

> **Status:** for WordPress 6.9 to 7.1 and PHP 8.1 or later. The pre-push test gate runs the PHPUnit suite on the minimum and the current WordPress, on multisite, and against real installs of the third-party plugins wpmcp integrates with. Known bugs are tracked as open issues in this repo. What is next is in the [Roadmap](roadmap-and-limitations.md#roadmap).

**What is in it.** 407 abilities (MCP tools) across 61 domains: 274 in the free plugin and 133 Pro. Which abilities a site has depends on which integrations are active: tools for optional plugins (ACF, SEO and translation plugins, form plugins and the other integrations) register only where that plugin is active, and a single site with none of them sees about 350, about 240 of them free. `list-tool-catalog` lists exactly what your site has. The count is pinned by the ability manifest (`tests/support/ability-manifest.php`), which the test suite checks on every run.

## Why wpmcp

- **Recoverable by design.** Every supported write goes through one wrapper that snapshots first. This is enforced in code, not by convention, and the writes that cannot be snapshotted are [listed](safety-model.md#what-cannot-be-undone) and say so in their response.
- **Builder-agnostic safety.** Snapshots are taken at the WordPress data layer (post content, meta, options), so rollback works for any page builder without parsing its format.
- **Runs inside WordPress.** A single plugin on the official [WordPress Abilities API](https://developer.wordpress.org/), not a separate local proxy process. One install, works with any MCP client.
- **Free and open.** GPL-2.0. The whole safety engine and the Gutenberg tooling are free.
