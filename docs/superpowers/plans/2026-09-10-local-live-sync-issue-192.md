# Local-live sync (issue #192): implementation plan

Status: phases 1-3 implemented in `src/Tools/Sync/`; phase 4 is design only.

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
  rows. A session longer than that has already lost its earliest rows. For
  `since_id` / `operation_id` markers the builder compares the marker
  against the surviving `MIN(id)`. For a `session_id` marker the surviving
  rows cannot say what was lost (prune deletes by id, sessions interleave,
  and on the free tier the ledger sits at its cap permanently after the
  twentieth mutation), so `Snapshot_Store::prune()` records a per-session
  pruned-row count in the `wpmcp_pruned_sessions` option (bounded to the
  last 100 sessions) at the moment it deletes, and the builder reads that.
  Either way the artifact says `truncated` rather than handing back a
  silently partial change set.
- Locally deleted objects are flagged `deleted`, never dropped, and counted
  separately from exported objects so "objects: 12" cannot mean zero
  pushable pages. A trashed post is flagged `deleted` plus `trashed`: the
  apply side must never publish on the target what was just removed locally.
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

Delivered in the phase 1 completion (format version 2):

- Explicit selection: `objects` refs (`post:ID`, `option:theme_mods_X`,
  `term:TAX:SLUG`) alone or on top of a ledger marker. This is also how an
  object created by a tool that writes no ledger row (create-post,
  duplicate-post, widget/block spec creation) enters a change set.
- Base revision per object: the before-image of the OLDEST ledger row in
  range, hashed over a URL-normalized projection (synced columns, carried
  meta keys, term slugs). `unchanged` flags an object edited and reverted,
  so it is never pushed over live. Explicit selections carry base
  `unknown`.
- Theme mods as per-key changes (`changed_keys` / `removed_keys`); every
  other option is excluded by design (site configuration).
- Term objects from `term` ledger rows.
- Dependencies: attachments carried as bytes (8MB per file, 64MB per
  artifact, over-cap media listed with `bytes_omitted`), terms with their
  parent chain, synced patterns (`core/block` ref), navigation menus
  (`core/navigation` ref), database template parts, Elementor templates
  (`template_id` / `templateID`) resolved transitively, Elementor global
  classes, and file-based theme patterns / template parts listed under
  `external`. Each object lists what it `requires`.
- Live-side post types (WooCommerce orders, refunds, subscriptions,
  coupons, scheduled actions, plus `wpmcp_sync_non_syncable_post_types`)
  are excluded from export and refused on apply.
- Deterministic artifact: sorted entries, key-sorted maps, and a SHA-256
  `checksum` over the canonical form minus `origin.created_at`. Validated
  on inspect and on apply.
- `build-change-set dry_run=true` lists what would be pushed and writes
  nothing. `get-change-set raw=true` returns the artifact verbatim for
  transport; the summary never carries media bytes.

Still open after phase 1:

- Creation ledger rows: create-post and friends write no ledger row, so a
  session-derived change set misses what they created unless it is
  selected explicitly. Recording a creation row (page_build semantics)
  in those tools would close it, at the cost of consuming history slots.
- Artifact lifecycle: change sets accumulate in the site-backup directory
  with no job record and no retention.
- Classic widgets (`sidebars_widgets`, `widget_*`) and other option
  families are not syncable.

## Phase 2 and 3: apply to a target, conflict policy (delivered)

`apply-change-set` (dry_run by default) runs `Change_Set_Applier`:

- Transport is the connect layer: `get-change-set raw=true` on the origin,
  `apply-change-set change_set=...` on the target (one agent connected to
  both, e.g. through `bin/wpmcp-proxy.php` with a named site each), or
  `path` for an artifact already in the target's backup directory.
- Identity, not ids: an object is matched on the target by id plus post
  type plus creation date (plus slug for an unpublished draft), or by
  type + slug + creation date under another id. An id held by a different
  object is never written; the incoming object is created under a new id
  and references (featured image, media blocks, wp-image-N, Elementor media
  and template ids, synced pattern refs, menu item targets, parents) are
  remapped.
- Conflict policy per object: target hash equal to the new state is
  skipped (already in sync); equal to the base is applied; anything else
  is conflicted and nothing is written unless the key is in `force`. Theme
  mods merge per key on the same rule. Never a silent last-writer-wins.
- Dependencies are only ever added (created from carried bytes / term
  definitions / template exports, or reused when already present); an
  object whose required dependency cannot be placed is skipped, not pushed
  broken.
- Snapshot-first: updates via `Safe_Mutation`, creations via a creation
  row (`page_build`, `media_import`, `term` not-existed) whose rollback
  deletes exactly what was created, global classes via
  `Global_Classes_Store::write`. One session per apply, so
  `rollback-session` undoes a whole sync.
- URLs are rewritten from the origin to the target via `Url_Rewriter`
  (through neutral tokens, so a target URL containing the origin URL is
  never rewritten twice).
- Deletions are reported, never applied. Serialized meta is decoded with
  `allowed_classes => false`; a value holding an object is refused.

## Phase 4 (maybe): scheduled sync

Only after 1-3 are proven in the field.
