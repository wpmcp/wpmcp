# WP MCP roadmap and known limitations

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Roadmap

- [ ] `preview-change` dry-run diffs before applying
- [ ] Visual before / after regression on edited pages
- [ ] Multi-site fleet management
- [ ] wp.org listing

## Known limitations

Snapshot history keeps the last 20 operations by default; raise it with the `wpmcp_snapshot_history_limit` filter (minimum 1). Pro keeps unlimited history while the licence is active. Pruning works on whole sessions: single writes in the `default` session are pruned one by one to the limit, but a named session (a bulk run such as a background optimize-media run, a product import or a multi-call database cleanup) is kept or dropped whole, never cut. The newest session and the newest session larger than the limit (the last bulk run) are always kept, so the table holds at most the limit plus those two sessions. A session that has been dropped makes `rollback-session` refuse with the reason rather than undo part of it. A post snapshot captures the full post row (including excerpt, parent, slug, dates and menu order), its meta, terms and, for a force-delete, its comments. Deleting media without `MEDIA_TRASH` backs up the attachment's files first, so rollback restores both the record and the file bytes ([#24](https://github.com/wpmcp/wordpress-mcp/issues/24)); a file that cannot be copied at delete time is left out of the backup. Details and mitigations are in the [design spec](../superpowers/specs/2026-07-12-wpmcp-mvp-design.md#known-limitations-mvp).
