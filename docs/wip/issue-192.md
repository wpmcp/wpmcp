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

- `Change_Set_Builder`: reads `wpmcp_snapshots` for a marker (session_id,
  operation_id, or a ledger row since_id), dedupes newest-first to one entry
  per object, exports each post's current state, meta and terms. Ledger rows
  are read through `Snapshot_Store::index_by_session()` /
  `index_since()`, which project only the identifying columns: `SELECT *`
  would drag every row's `before_blob` LONGBLOB into memory, and a Pro
  history limit is `PHP_INT_MAX`. Reads are capped at
  `MAX_LEDGER_ROWS` (5000).
- Object-type routing, not raw-string filtering: `page_build` (Build_Page's
  composite snapshot) and `media_import` are post-backed with numeric ids
  and route to the post/attachment exporters, so an agent build session
  exports exactly the pages it built.
- Honest exclusion reporting. `user`, `comment` and `wc_order` are excluded
  BY DESIGN (they are the live-side data a sync must never overwrite);
  everything else unhandled (`option`, `term`, `redirect`, `db_rows`) is
  reported as **not implemented in this slice**, so an operator is never
  told a gap is a policy. Excluded rows are never deduped: `object_id` is 0
  for every string-keyed type, so collapsing them would report three touched
  options as a single `object_id: 0` entry.
- Truncation detection. `Safe_Mutation::run()` prunes the ledger to the
  licence's history limit after every write, and the free tier (and the
  wp.org build, where `strip.php` flattens the limit for everyone) keeps 20
  rows. A session longer than that has already lost its earliest rows, so
  the builder compares the marker against the surviving `MIN(id)` and
  reports `truncated` in the artifact rather than handing back a silently
  partial change set.
- Locally deleted objects are flagged `deleted`, never dropped, and counted
  separately from exported objects so "objects: 12" cannot mean zero
  pushable pages.
- Attachment dependency resolution: featured image, parsed blocks
  (`parse_blocks()` walking `id` / `ids` / `mediaId`, so wp:video, wp:audio,
  wp:file, wp:media-text and galleries count), classic `wp-image-N` markup,
  and the Elementor `_elementor_data` element tree, where media lives as
  `['id' => N, 'url' => '...']` in postmeta rather than in post_content.
  Ids that do not resolve to a local attachment are reported under
  `excluded`, not emitted as phantom manifest entries with null everything.
  Checksums are skipped above 64MB instead of stalling on `md5_file()`.
- `Build_Change_Set` tool: writes the artifact as JSON into the protected
  site-backup directory (random filename suffix, same exposure rules as
  `Site_Archive_Builder`). Exactly one marker, non-empty; every failure
  throws `\RuntimeException` so `Registrar` records ok:false rather than
  logging a refusal as a successful call.
- `Get_Change_Set` tool: summary inspection of an artifact before it is
  applied anywhere. Containment is delegated to `Archive_Locator::resolve()`,
  the single security boundary for path-taking backup tools; the artifact is
  then validated (format version, per-object shape) rather than trusted.
- Registration in `Plugin::register_sync_abilities()`, group `sync`,
  manage_options, free tier for now (Pro placement is an open issue
  question). `scripts/build-woo-release.sh` prunes `src/Tools/Sync` because
  the group is not in `FLAVOR_GROUPS['woocommerce']`.
- Tests: `tests/free/Sync/` covers session scoping, dedup, `page_build`
  routing, design-vs-gap exclusion reasons, per-row excluded reporting,
  deletions, block/classic/Elementor attachment resolution, stale
  references, terms, truncation, marker validation, artifact containment and
  malformed-artifact handling.

Remaining in phase 1:

- Option export: decode the option name from the snapshot blob
  (`Snapshot::unserialize` then `data.name`), allowlist of syncable option
  families (theme mods, widget/block specs, menus). Until then option rows
  are reported as excluded/not implemented, never as objects.
- Term, menu and redirect objects as first-class change-set entries.
- Template/pattern references (`wp:pattern`, template part refs) and
  Elementor global classes as dependencies. Elementor *media* is resolved;
  Elementor *global classes* are not.
- Attachment bytes in the artifact with checksum dedup vs. manifest-only
  (open question on the issue; current slice is manifest-only).
- Changed vs. touched: export is keyed on "the session touched this", so an
  object mutated and then rolled back still enters the change set and phase
  2 would push the reverted state. Needs a comparison against the oldest
  before-image in the marker range, or at minimum an `unchanged` flag the
  apply side skips by default.
- Artifact lifecycle: change sets accumulate in the site-backup directory
  with no list, retention or delete path, unlike site archives
  (`Delete_Backup_Archive` plus a job record). Either give sync its own
  list/delete counterpart or make change sets first-class in
  `Backup_Job_Store`.

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
