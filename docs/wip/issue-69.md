# WIP plan: theme integration tools (issue #69)

Status: implemented as `WPMCP\Integrations\Theme_Integration`
(src/Integrations/Theme_Integration.php), registered in
`Plugin::register_integration_abilities()` as the `wpmcp/theme-read` /
`wpmcp/theme-write` dispatcher pair on the issue #65 integration dispatcher
framework. Covered by tests/free/Integrations/ThemeIntegrationTest.php.

## Operations

- `get-context` (read): active theme name/version, stylesheet/template,
  parent/child relationship, block-theme flag, framework family detection
  from the parent slug (case-insensitive, map filterable via
  `wpmcp_theme_framework_map`), registered nav menus, declared theme supports.
- `list-theme-mods` (read, `edit_theme_options`): current mods with per-key
  writability class (allowlisted / structural / other) plus the effective
  allowlist. Gated at `edit_theme_options` because raw mod values include
  `nav_menu_locations` and `sidebars_widgets`.
- `set-theme-mods` (write, `edit_theme_options`, default off): presentation
  allowlist with a per-key VALUE sanitizer (hex colors, attachment ids, URLs,
  enums), because `set_theme_mod()` bypasses the Customizer sanitizers
  entirely. Structural keys (`nav_menu_locations`, `sidebars_widgets`,
  `custom_css_post_id`) refused and stripped even from a widening filter.
  Snapshot-first on `theme_mods_{stylesheet}`, reversible with
  rollback-operation.
- `create-child-theme` (destructive, `edit_themes`, default off): scaffolds
  style.css + functions.php, slug `sanitize_key`-reduced to one segment and
  re-confined to `get_theme_root()` through `Filesystem_Guard::resolve_path()`,
  gated additionally on `Filesystem_Guard::writes_allowed()` (edit_files +
  DISALLOW_FILE_EDIT), audited through `Filesystem_Guard::log()`, refuses to
  build a child of a child, accepts an optional explicit `parent`, calls
  `wp_clean_themes_cache()` so the result is actually activatable, and does
  not activate the theme.
- `get-astra-settings` / `set-astra-settings` (framework pack): registered
  only while the Astra family is the active theme, allowlisted and sanitized
  per key, snapshot-first on the `astra-settings` option, and followed by an
  Astra CSS-cache refresh plus the `wpmcp_theme_framework_cache_refresh`
  action.

## Safety posture

Both write ops are default off behind `wpmcp_enable_theme_write`, matching the
ACF and Meta Box write ops. Every refusal decidable from the args lives in the
op's `validate` callable, which the dispatcher runs after schema validation and
BEFORE `run_write()` captures a snapshot, so a rejected call writes no snapshot
row, burns no rollback-history slot and returns the ordinary top-level error
envelope (no `operation_id`, no `recoverable: true`). Failures only discoverable
mid-write throw `Operation_Refused` for the same envelope. The scaffolder writes
functions.php first and the marker-bearing style.css last and removes a partial
scaffold on failure, so a half-written theme can never be reported as done.
Caller-supplied names are stripped of newlines and of `*/` before being
interpolated into the style.css comment block.

## Acceptance criteria

- [x] Theme context reports framework, parent/child, block-theme support,
      registered menus/supports.
- [x] Theme-mod writes hit only allowlisted keys; structural keys refused;
      snapshotted and reversible.
- [x] Child-theme creation is idempotent, refuses creating a grandchild,
      requires confirm, and produces an activatable theme.
- [x] Framework pack registers only when that theme (or its family) is active.

## Remaining work

- [ ] Widen the Astra pack beyond colors and content width, and add a second
      family (Kadence or GeneratePress) once install-base data picks one.
- [ ] Live verification of the Astra pack against a real Astra install; the
      unit tests cover registration and refusal, not Astra's own behavior.

## Notes

`File_Backup` (issue #69's original "file writes tracked via File_Backup"
bullet) backs up files that ALREADY exist before an overwrite or delete. The
scaffolder only ever creates new files inside a directory that did not exist,
so there is nothing to back up; the equivalent guarantee is delivered by
removing the partial scaffold on failure, which is what the tests assert.
