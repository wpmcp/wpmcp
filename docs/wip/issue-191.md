# WIP: Site-to-site migration (issue #191)

Consumes the archive format from #189 and the restore engine from #190.
The hard problem is the URL rewrite: WordPress stores PHP-serialized data
as text, a naive SQL REPLACE breaks the declared byte lengths, and the
value silently reads back as false. `Tools\Backup\Url_Rewriter` (shipped
in #189, fully tested) already solves that; this issue puts it to work.

## Status

### Phase 1: rewrite pass over a restored site (in progress, this branch)

Done:
- `src/Tools/Migration/Rewrite_Site_Urls.php`: batched, primary-key-cursored
  walk of wp_options, wp_postmeta, wp_posts (content and excerpt, never
  GUIDs), wp_termmeta, wp_usermeta and wp_comments, pre-filtered with a
  LIKE on the scheme-relative host form so unaffected rows are never
  fetched. Each candidate value goes through `Url_Rewriter::rewrite_url()`
  (plain, JSON-escaped, percent-encoded and scheme-relative forms,
  longest-first, serialization-aware). Values the rewriter declines
  (decoded structure contains an object) are counted per table as
  `rows_skipped_object`, not silently passed over.
- `wpmcp/rewrite-site-urls` registered in `Plugin.php` as a new
  `migration` ability group. dry_run defaults to true; applying requires
  dry_run:false and confirm:true. manage_options, free tier.
- Object cache flushed after an applied pass touches wp_options.

Remaining for phase 1 (definition of done in the issue):
- Snapshot-first: the apply path must produce a database backup archive
  (Backup\Trigger_Backup, type=database) and refuse to run until that job
  completes, so the whole pass can roll back. Per-object Safety\Snapshot
  does not fit a full-DB rewrite.
- Time-bounded batching with a resumable cursor for very large tables;
  currently the pass runs to completion within one request.
- Integration tests: serialized options round-trip, object-bearing rows
  reported as skipped, widgets and theme mods intact after a rewrite.
- Wire the rewrite into the restore flow (#190) so a restore whose
  manifest origin URL differs from site_url offers or performs the pass.

### Phase 2: push to a target over the connect layer (not started)

Target authenticates the source over the existing OAuth or Application
Password path; source exposes the archive; target restores (#190) then
runs the phase 1 rewrite with the URL pair from the archive manifest and
its own site_url. Open decision leans pull with a signed, short-lived
URL (friendlier to PHP upload limits, target can retry); chunked with
resume either way. Automatic pre-migration archive on the target,
unconditionally.

### Phase 3: pull, initiated from the target (not started)

Same machinery, opposite initiator; the common real-world direction is
"set up the new host, pull production down onto it".

### Out of scope

Multisite (wp_blogs / wp_site rewriting) is tracked separately.
