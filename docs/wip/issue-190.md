# WIP: One-click restore from a site backup archive (issue #190)

Phase 1: restore in place, same site. Consumes the archive format from
`docs/superpowers/specs/2026-08-13-site-backup-archive-format.md` (issue #189).

## What exists in this branch

- `src/Tools/Backup/Restore_Site_Backup.php`: the `wpmcp/restore-site-backup`
  handler. The dry-run path is fully implemented: it resolves the archive
  through `Archive_Locator` (same containment rules as reading or deleting an
  archive), reads `manifest.json` without extracting, and returns a
  compatibility report. A truncated or non-archive zip is refused inside
  `Archive_Locator::read_manifest()` before anything else happens.
- Registration in `Plugin::register_backup_abilities()`: free tier,
  `manage_options`, domain `backup`, operation `update`, explicit annotation
  hints `read_only=false, destructive=true, idempotent=true`.
- `dry_run` defaults to true: an agent firing this ability from a vague
  instruction hits a report, not a restore.

## Compatibility gate (implemented)

Hard refusals (restore never starts, mismatch named in the message):

- manifest `format` is not `wpmcp-site-backup`
- `format_version` newer than this build understands (currently 1)
- `site.table_prefix` differs from the target `$wpdb->prefix`
- `site.multisite` differs from the target

Warnings (surfaced in the result, not refusals):

- target WordPress older than `versions.wordpress` (database downgrade)
- non-empty `database.blob_tables` (BLOBs round-tripped through escaped
  string literals; spot-check after restore)

## Remaining work (execution path, currently refuses with a clear message)

1. Pre-restore safety archive, always: build a `database`-scope archive of
   the current state synchronously via `Site_Archive_Builder` before any
   write; abort if it fails; report its path even when the restore itself
   later fails.
2. Maintenance mode for the duration via the `wpmcp_maintenance` option
   (enforced by `Maintenance\Maintenance_Guard`), guaranteed off on every
   exit path including a mid-import throw.
3. Statement-by-statement SQL import with a bounded stream read of `db.sql`
   out of the zip (`ZipArchive::getStream`, never `getFromString` on the
   whole dump). Track the last statement executed; a mid-import failure
   reports where it stopped.
4. Session survival: restoring `wp_users`/`wp_usermeta` invalidates the
   acting user's session; preserve it or report clearly that re-login is
   required.
5. `include_files`: extract `wp-content` to a staging directory and swap,
   never extract over the live tree.
6. Tests covering the definition of done in the issue: dry-run touches
   nothing, round-trip restore, truncated zip refused before maintenance
   mode, prefix mismatch named, mid-import failure leaves maintenance mode
   off, safety archive reported on failed restore.
