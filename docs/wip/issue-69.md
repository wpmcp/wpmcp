# WIP plan: theme integration tools (issue #69)

Built on top of PR #234 (issue #144, phase 1), which delivers the
`wpmcp/theme-read` / `wpmcp/theme-write` pair with `get-theme-context`,
`get-mods` and `set-mods`. This branch adds the rest of #69 as further ops on
that same pair; it adds no new abilities.

## What #144 already delivers (not re-implemented here)

- Theme context: framework, parent/child, block-theme flag, probed theme
  supports, registered menu locations (`get-theme-context`).
- Allowlisted, snapshot-first, reversible theme-mod writes with structural keys
  refused (`get-mods` / `set-mods`, `wpmcp_theme_mod_allowlist`,
  `wpmcp_theme_mod_value_rules`).

## What this branch adds

- `create-child-theme` (destructive, `edit_themes`, default off behind
  `wpmcp_enable_theme_write`, `confirm`-gated), in
  `src/Integrations/Child_Theme_Scaffolder.php`: slug reduced by
  `sanitize_key` and re-confined to `get_theme_root()` through
  `Filesystem_Guard::resolve_path()`, gated on `Filesystem_Guard::writes_allowed()`
  (edit_files + DISALLOW_FILE_EDIT), audited through `Filesystem_Guard::log()`.
  Idempotent, refuses grandchildren (active child or explicit child parent),
  accepts an optional explicit mixed-case-safe `parent`, writes through
  `WP_Filesystem` (functions.php first, marker-bearing style.css last, partial
  scaffold removed on failure), calls `wp_clean_themes_cache()` so the child is
  activatable, does not activate it.
- Snapshot-first scaffold: new `theme_scaffold` snapshot type
  (`Safety\Snapshot::capture_theme_scaffold()`,
  `Safety\Rollback_Service::apply_theme_scaffold_snapshot()`). The directory's
  prior state (existed or not, prior bytes of style.css/functions.php) is
  captured through `Safe_Mutation` before any write. Rollback removes the
  created files (and the directory when the scaffold made it and nothing else
  was added), restores overwritten files, refuses to delete an active theme,
  and a session rollback undoes scaffolds last so a same-session switch-theme
  is reverted first. A re-run against a complete scaffold burns no rollback
  slot.
- Framework pack (Astra), in `src/Integrations/Theme_Framework_Pack.php`:
  `get-astra-settings` / `set-astra-settings`, in the catalog only while the
  detected framework is `astra` (an Astra child reports template `astra`, so
  the family is covered). Allowlisted and sanitized per key (hex, rgb/rgba,
  Astra palette `var(--ast-global-color-N)`, content width 300 to 3000 px),
  snapshotted on the `astra-settings` option, followed by
  `astra_clear_all_assets_cache()` and the `wpmcp_theme_framework_cache_refresh`
  action.
- `Integration_Dispatcher`: optional per-op `validate` callable (after schema
  validation, before `run_write()` snapshots) and `Operation_Refused` for
  mid-write failures, so refusals return the top-level error envelope with no
  snapshot row.

## Acceptance criteria

- [x] Theme context (delivered by #144's `get-theme-context`).
- [x] Theme-mod writes allowlisted, structural refused, snapshotted, reversible
      (delivered by #144's `set-mods`).
- [x] Child-theme creation idempotent, grandchild-refusing, confirm-gated,
      activatable, snapshot-first and undoable.
- [x] Framework pack registers only when that family is active, with CSS-cache
      refresh.

## Remaining work

- Widen the Astra pack, and add a second family (Kadence or GeneratePress).
- Live verification of the Astra pack against a real Astra install.
- Astra CSS cache is refreshed after a pack write but not after a rollback of
  one.
