# WordPress AI agent safety model: snapshots, rollback and what cannot be undone

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## How it works

Every snapshotted write routes through one orchestrator, `Safe_Mutation::run()`:

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

**Audit Log** in wp-admin has two tabs. *Mutations* lists agent writes with Restore. *Requests* is the MCP request outcome log: one row per tool call, reads included, with the calling client, ok or the error code, how long it took, and, when the call took a snapshot, a link straight to that operation's undo point in History. It is a capped ring buffer (200 rows by default, `wpmcp_request_log_cap`), and tool arguments are not recorded unless you switch on the `wpmcp_request_log_capture_args` option or filter, which still redacts secret-looking values and truncates long ones.

## What cannot be undone

Snapshots cover WordPress data: posts and their content, meta and terms, options, users, and the plugin data the integrations snapshot. Some changes fall outside that, and wpmcp tells you rather than claiming an undo it cannot do. Most of these are off by default, and each says so in its response (`recoverable: false`, `files_recoverable: false` or `reversible: false`):

- **Package updates and deletes.** `update-plugin`, `update-theme`, the core update in `manage-updates`, `delete-plugin` and `delete-theme` do not back up package files. Off by default, and each needs `confirm: true`.
- **Raw database row writes.** `update-rows` and `delete-rows` write a before-image to a capped audit log, not a restorable snapshot. Off by default.
- **A `.php` file undo whose site check cannot run.** File writes and deletes are undoable since [#602](https://github.com/wpmcp/wordpress-mcp/issues/602), but a `.php` restore is refused when the site check cannot run, and the response gives the backup path for a manual copy. File tools are off by default.
- **Other one-way actions.** Deleting a menu, importing content, the security incident tools (ending sessions, rotating salts), and other plugins' abilities run through the abilities bridge (`execute-site-ability`).
- **Snapshot history depth.** History keeps the last 20 operations by default, so older writes can no longer be undone (see [Known limitations](roadmap-and-limitations.md#known-limitations)).

Rollback bugs are tracked as open issues in this repo.
