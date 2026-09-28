# wpmcp, MVP Design Doc

**Date:** 2026-07-12
**Status:** Draft for review

This is the public copy of the original design. It keeps the technical design; product and commercial planning notes were removed.

---

## 1. Overview

An MCP-Adapter-native WordPress plugin that lets an AI agent (Claude, Cursor, any MCP client) build and edit pages, with **snapshot-before-every-write + one-click rollback** as the headline trust feature: nothing an agent does through the plugin should be unrecoverable.

It is a single WordPress plugin built on the official `WordPress/mcp-adapter`, not a separate Node proxy process. Single install, no separate process, works with any MCP client.

## 2. Architecture

```
MCP client (Claude/Cursor/…)
        │  HTTPS + Application Password
        ▼
WordPress  ──  official WordPress/mcp-adapter  ──  MCP server @ /wp-json/mcp/wpmcp-server
        │
        ├─ Abilities layer      → MCP tools (read + build/edit), registered via Abilities API
        ├─ Safe-write engine    → wraps EVERY mutation: snapshot → apply → verify → (rollback)
        ├─ Snapshot store       → before-images in a custom table, keyed by operation + session
        ├─ History/restore UI   → wp-admin: list agent operations, diff summary, one-click restore
        └─ Guardrails           → capability checks, dry-run preview, non-destructive defaults, audit log
```

- **Stack:** PHP ≥ 8.1, WordPress ≥ 6.9 (bundles Abilities API), builds on the official MCP Adapter.
- **Auth:** Application Passwords (the path the official adapter uses).
- **Builder-agnostic core:** the safe-write engine snapshots at the *WordPress data layer* (post content, builder meta, options, terms), so rollback works for any builder without parsing its syntax. Builder-specific *editing* is additive on top.

## 3. Safe-write engine (the core primitive, build this first, build it hard)

Every mutating ability routes through one `Safe_Mutation` wrapper:

1. **Snapshot (before):** capture a before-image, post content + a serialized copy of builder meta (`_elementor_data`, block markup), plus any touched options/terms. Store compressed.
2. **Apply:** run the mutation.
3. **Verify (lightweight):** post still loads, builder data still parses. On failure → auto-rollback + return error.
4. **Rollback (on demand):** restore the before-image. Two scopes: single operation, or **whole session** ("undo everything this agent just did").

**Snapshot table** `wp_wpmcp_snapshots`: `id, operation_id, session_id, object_type, object_id, tool_name, args_hash, before_blob (gzipped), user_id, created_at`.

**Retention:** one flat cap for every install (`Snapshot_Store::DEFAULT_HISTORY_LIMIT`, 20), filterable for free via `wpmcp_snapshot_history_limit`. Superseded the original free/paid split in issue #158: a quota lifted by payment is what wp.org guideline 5 rejects.

This primitive is non-negotiable and gets exhaustive tests.

## 4. MCP tool surface (MVP, kept deliberately small, ~12-15 tools)

**Read:** `list-pages`, `get-page`, `get-blocks`, `get-elementor-data`
**Write (all safe-wrapped):** `create-page`, `update-blocks` (Gutenberg), `update-elementor-data` / `add-elementor-section`, `sideload-image`
**Safety (exposed as tools):** `list-operations` (history), `preview-change` (dry-run diff, no write), `rollback-operation`, `rollback-session`

**Builder scope for MVP:** Gutenberg editing is full (open standard, easiest to write *correct* syntax, platform-safe). Elementor gets read + structural writes now, with deep widget editing in Phase 2. The safety engine covers both from day one regardless.

## 5. CI & test discipline

- **PHPUnit + WP integration tests**; the snapshot/rollback engine carries the heaviest coverage.
- **GitHub Actions** on every push: unit + integration across a WP/PHP matrix; red blocks release.
- **wp-env / WordPress Playground** for integration + live demos.
- **Release automation:** tag → build zip → GitHub release. Trunk-based, feature-flagged so half-built features ship dark.

## 6. Out of scope for MVP (YAGNI)

Multi-site fleet management, human-approval queue UI, visual-regression diffing, builders beyond Elementor/Gutenberg, our own AI provider/chat panel (users bring their own MCP client). These are Phase 3+ items, not the first shippable.

## 7. Build sequence

- **Phase 0:** repo + CI + plugin skeleton on the MCP Adapter; `hello-world` ability round-trips through an MCP client.
- **Phase 1:** safe-write engine + snapshot store + Gutenberg edit tools + history/restore UI + rollback tools.
- **Phase 2:** Elementor deep editing + session rollback + preview/diff.
- **Phase 3:** visual-regression diff, multi-site, approval queue.

## Known limitations (MVP)

1. **Free-tier retention bounds session rollback.** Every install keeps only the last 20 snapshot operations (`Snapshot_Store::history_limit()`), pruned after every write. An agent session performing >20 operations can lose its earliest snapshots, so `rollback-session` restores to the earliest *surviving* snapshot, not necessarily the true pre-session state. Raising `wpmcp_snapshot_history_limit` widens the window on any site, free or paid. TOP fast-follow backlog item: make pruning session-aware (never prune snapshots of a session still within the retention window).
2. **Snapshot capture scope.** `Snapshot::capture()` records `post_content`, `post_title`, `post_status`, and all post meta. It does NOT capture excerpt, parent, menu_order, or taxonomy terms, mutations to those are not rolled back. Free-tier `update-blocks` only edits content, so no live gap today.
3. **`rollback-session` return value** counts snapshot operations processed, not distinct objects restored.
4. **`delete-media` file recovery.** Resolved (issue #24). Force-deleting media (or deleting without `MEDIA_TRASH` enabled) backs up the physical file plus every intermediate size via `File_Backup` before unlinking them; rollback restores both the media record and the file bytes at their original paths, and the response reports `files_recoverable: true`. Backups live under `wp-content/uploads/.wpmcp-backups/<operation_id>/` (protected from direct web access) and are deleted when their snapshot is pruned. Media force-delete is disabled by default (`wpmcp_enable_delete_media` filter must be explicitly enabled).
