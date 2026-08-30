# Local-live sync (issue #192): implementation plan

Status: WIP. Phase 1 (change-set export) has a first slice in
`src/Tools/Sync/`; phases 2-4 are design only.

## Design constraints (from the issue)

- A sync is not a repeated migration. The live site holds data the local copy
  has never seen (orders, comments, form entries, registrations); the unit of
  sync is a set of explicitly selected objects, never the database.
- The snapshot ledger is the change-set source. Every mutating tool already
  snapshots before writing, so the local site knows exactly which objects a
  build session touched. No database diffing.
- `Rollback_Service` makes a bad sync undoable on the live side; the apply
  path must be snapshot-first through the existing safety core.
- Deletions are reported, never applied automatically.
- No silent last-writer-wins, ever.

## Phase 1: change-set export (this branch)

Implemented:

- `Change_Set_Builder`: reads `wpmcp_snapshots` since a marker (session_id or
  ledger row id), dedupes newest-first to one entry per object, exports each
  post's current state, meta and terms. Non-syncable object types (users,
  comments, wc_order) are excluded by construction and reported as excluded.
  Locally deleted objects are flagged `deleted`, not dropped.
- Attachment dependency resolution: featured image, `wp-image-N` classes and
  block attribute ids in post_content, listed as a manifest with checksums.
- `Build_Change_Set` tool: writes the artifact as JSON into the protected
  site-backup directory (random filename suffix, same exposure rules as
  `Site_Archive_Builder`).
- `Get_Change_Set` tool: summary inspection of an artifact before it is
  applied anywhere; path traversal outside the backup dir refused.
- Registration in `Plugin::register_sync_abilities()`, group `sync`,
  manage_options, free tier for now (Pro placement is an open issue question).

Remaining in phase 1:

- Option export: decode option name from the snapshot blob, allowlist of
  syncable option families (theme mods, widget/block specs, menus).
- Template/pattern references (`wp:pattern`, template part refs) and
  Elementor global classes as dependencies.
- Attachment bytes in the artifact with checksum dedup vs. manifest-only
  (open question on the issue; current slice is manifest-only).
- Menu and term objects as first-class change-set entries.
- Tests: builder dedup, dependency extraction, path refusal in Get_Change_Set.

## Phase 2: apply to a target

- Push the artifact over the connect layer.
- Apply snapshot-first on the live side via `Safe_Mutation`/`Rollback_Service`.
- Rewrite URLs with `Tools\Backup\Url_Rewriter` from `origin.site_url` to the
  target's.
- Per-object outcome report: applied, skipped, conflicted.

## Phase 3: conflict policy

- Compare target `post_modified` against the change set's base revision.
- Unmodified target: apply. Modified both sides: refuse and report, with
  explicit per-object force.

## Phase 4 (maybe): scheduled sync

Only after 1-3 are proven in the field.
