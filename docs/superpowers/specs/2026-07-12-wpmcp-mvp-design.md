# wpmcp — MVP Design Doc

**Date:** 2026-07-12
**Status:** Draft for review
**One-liner:** *The AI agent that builds and edits your WordPress site — and physically can't wreck it.*

---

## 1. Positioning

An MCP-Adapter-native WordPress plugin that lets an AI agent (Claude, Cursor, any MCP client) build and edit pages, with **snapshot-before-every-write + one-click rollback** as the headline trust feature.

The demand we ride is what's already hot: *AI building WordPress sites.* The wedge that makes ours the one people trust — and the thing no incumbent leads with — is **recoverability**. Respira's own blog admits a bug that wrote changes to the wrong site; every reckless MCP is a support ticket waiting to happen. Our entire pitch is: *nothing an agent does here is unrecoverable.*

**We are not** a Node proxy (Respira's model, with its local-process friction). We are a single WordPress plugin riding the official `WordPress/mcp-adapter`. Single install, no separate process, works with any MCP client — the legitimacy play.

## 2. Strategic frame (decided)

- **Independent, MCP-Adapter-native.** Not tied to EMCP; Shahzad stays a friend, not a dependency.
- **Target:** $10–30k ARR in year one. Self-serve, high-volume, low price point. This is the realistic ceiling for the category today, and a genuine win.
- **Velocity is a weapon.** Incumbents are slow solo/non-dev builders. We ship at an aggressive cadence; the changelog itself is marketing. This is only safe with airtight CI (see §7) — hourly shipping on a "we won't break your site" product is fatal without it.
- **Differentiator stack:** recoverability (safety) + velocity + legitimacy (official adapter).
- **Reuse ethics:** study/improve others' open source respecting licenses (Respira's MIT wrapper, Royal MCP, the official adapter). Do **not** copy Shahzad's code or test files — derive our own test coverage from observed behavior. Own the CI/test suite outright.

## 3. Architecture

```
MCP client (Claude/Cursor/…)
        │  HTTPS + Application Password
        ▼
WordPress  ──  official WordPress/mcp-adapter  ──  MCP server @ /wp-json/mcp/wpmcp-server
        │
        ├─ Abilities layer      → MCP tools (read + build/edit), registered via Abilities API
        ├─ Safe-write engine    → wraps EVERY mutation: snapshot → apply → verify → (rollback)  ← the moat
        ├─ Snapshot store       → before-images in a custom table, keyed by operation + session
        ├─ History/restore UI   → wp-admin: list agent operations, diff summary, one-click restore
        └─ Guardrails           → capability checks, dry-run preview, non-destructive defaults, audit log
```

- **Stack:** PHP ≥ 8.1, WordPress ≥ 6.9 (bundles Abilities API), builds on the official MCP Adapter.
- **Auth:** Application Passwords (same proven path EMCP/adapter use).
- **Builder-agnostic core:** the safe-write engine snapshots at the *WordPress data layer* (post content, builder meta, options, terms), so rollback works for any builder without parsing its syntax. Builder-specific *editing* is additive on top.

## 4. Safe-write engine (the core primitive — build this first, build it hard)

Every mutating ability routes through one `Safe_Mutation` wrapper:

1. **Snapshot (before):** capture a before-image — post content + a serialized copy of builder meta (`_elementor_data`, block markup), plus any touched options/terms. Store compressed.
2. **Apply:** run the mutation.
3. **Verify (lightweight):** post still loads, builder data still parses. On failure → auto-rollback + return error.
4. **Rollback (on demand):** restore the before-image. Two scopes: single operation, or **whole session** ("undo everything this agent just did").

**Snapshot table** `wp_wpmcp_snapshots`: `id, operation_id, session_id, object_type, object_id, tool_name, args_hash, before_blob (gzipped), user_id, created_at`.

**Retention:** configurable; free tier keeps last N operations, paid keeps unlimited + longer window.

This primitive is non-negotiable and gets exhaustive tests — it *is* the product promise.

## 5. MCP tool surface (MVP — kept deliberately small, ~12–15 tools)

**Read:** `list-pages`, `get-page`, `get-blocks`, `get-elementor-data`
**Write (all safe-wrapped):** `create-page`, `update-blocks` (Gutenberg), `update-elementor-data` / `add-elementor-section`, `sideload-image`
**Safety (the differentiator, exposed as tools):** `list-operations` (history), `preview-change` (dry-run diff, no write), `rollback-operation`, `rollback-session`

**Builder scope for MVP:** Gutenberg editing is full (open standard, easiest to write *correct* syntax, platform-safe). Elementor gets read + structural writes now, with deep widget editing as the Phase 2 paid hook (biggest market, and the area you know cold). The safety engine covers both from day one regardless.

## 6. Free vs paid split

- **Free (wp.org — the funnel & trust hook):** safe-write engine + one-click rollback + Gutenberg editing + history (last ~20 ops). Genuinely useful, *not* crippled bait (deliberate contrast with Respira's ~30-edit trial).
- **Paid (Freemius, ~$9–19/mo entry to drive volume):** Elementor deep editing, unlimited history + session rollback, preview/diff, priority. Agency/multi-site tier later.

## 7. CI & test discipline (what makes hourly cadence survivable)

- **PHPUnit + WP integration tests**; the snapshot/rollback engine carries the heaviest coverage.
- **GitHub Actions** on every push: unit + integration across a WP/PHP matrix; red blocks release.
- **wp-env / WordPress Playground** for integration + live demos.
- **Release automation:** tag → build zip → GitHub release + Freemius deploy. Trunk-based, feature-flagged so half-built features ship dark.

## 8. Naming, domain & GTM

**Domain (checked 2026-07-12 via DNS/RDAP):**
- ❌ `wpmcp.com` **taken/squatted** (Cloudflare parked; same owner also holds `wpmcp.ai`, `wpmcp.org`).
- ✅ Available: `wpmcp.io`, `wpmcp.co`, `wpmcp.app`, `wpmcp.net`, `wpmcp.tools`, plus `getwpmcp.com`, `trywpmcp.com`, `usewpmcp.com`.
- **Recommendation:** **`wpmcp.io`** as primary (exact name, dev-native TLD), grab `getwpmcp.com` + `trywpmcp.com` as redirects and `.co/.app` defensively. Skip paying the `.com` squatter early — not worth it at this stage.
- **Naming tradeoff:** "wpmcp" is generic/descriptive → strong SEO for "wordpress mcp", weak as a trademark. Acceptable given the SEO-discovery + fast-money goals; revisit a brandable later if it scales.

**Marketing:** new YouTube channel as the engine — build-in-public + "watch an AI build a WordPress site (and watch me one-click undo it)" demos. The rollback/save-your-site moment is the hook. Weekly recap of the hourly changelog. wp.org free tier feeds organic installs the whole category lacks (near-zero reviews everywhere = wide-open distribution).

## 9. Out of scope for MVP (YAGNI)

Multi-site fleet management, human-approval queue UI, visual-regression diffing, builders beyond Elementor/Gutenberg, our own AI provider/chat panel (users bring their own MCP client). These are the Phase 3+ moat expansions, not the first shippable.

## 10. Risks & open questions

- **Distribution is the real bottleneck**, not features. wp.org review lead time + no-telemetry rules need planning early.
- **Elementor going native (Angie)** could absorb its niche → mitigated by the builder-agnostic safety engine (native progress = more changes needing a seatbelt).
- **`wpmcp.com` squatter** may try to sell; `.io` sidesteps it.
- **Willingness-to-pay for safety is unproven** — mitigated by leading with the *building* capability (proven demand) and using safety as differentiator, not the whole sell.

## 11. Build sequence

- **Phase 0:** repo + CI + plugin skeleton on the MCP Adapter; `hello-world` ability round-trips through an MCP client.
- **Phase 1 (MVP / free tier):** safe-write engine + snapshot store + Gutenberg edit tools + history/restore UI + rollback tools. Ship to wp.org.
- **Phase 2 (paid):** Elementor deep editing + Freemius + session rollback + preview/diff. Turn on monetization.
- **Phase 3 (moat):** visual-regression diff, multi-site, approval queue.

## Known limitations (MVP)

1. **Free-tier retention bounds session rollback.** Free tier keeps only the last 20 snapshot operations (`Gate::history_limit()`), pruned after every write. An agent session performing >20 operations can lose its earliest snapshots, so `rollback-session` on free tier restores to the earliest *surviving* snapshot, not necessarily the true pre-session state. Pro (unlimited history) is not affected. TOP fast-follow backlog item: make pruning session-aware (never prune snapshots of a session still within the retention window).
2. **Snapshot capture scope.** `Snapshot::capture()` records `post_content`, `post_title`, `post_status`, and all post meta. It does NOT capture excerpt, parent, menu_order, or taxonomy terms — mutations to those are not rolled back. Free-tier `update-blocks` only edits content, so no live gap today.
3. **`rollback-session` return value** counts snapshot operations processed, not distinct objects restored.
