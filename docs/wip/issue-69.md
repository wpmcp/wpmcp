# WIP plan: theme integration tools (issue #69)

Status: first slice implemented as `WPMCP\Integrations\Theme_Integration`
(src/Integrations/Theme_Integration.php), registered in
`Plugin::register_integration_abilities()` as the `wpmcp/theme-read` /
`wpmcp/theme-write` dispatcher pair on the issue #65 integration dispatcher
framework.

## Implemented in this slice

- `get-context` (read): active theme name/version, stylesheet/template,
  parent/child relationship, block-theme flag (`wp_is_block_theme`),
  framework family detection from the parent slug (map filterable via
  `wpmcp_theme_framework_map`), registered nav menus, and declared theme
  supports.
- `list-theme-mods` (read): current mods with per-key writability class
  (allowlisted / structural / other) plus the effective allowlist.
- `set-theme-mods` (write): presentation-only allowlist (filterable via
  `wpmcp_theme_mod_allowlist`, with structural keys stripped even if a
  filter tries to add them); structural keys (`nav_menu_locations`,
  `sidebars_widgets`, `custom_css_post_id`) refused with a dedicated error;
  the whole batch validates before any write; snapshot-first on the
  `theme_mods_{stylesheet}` option via the dispatcher's `Safe_Mutation`
  routing, so writes are reversible with rollback-operation.
- `create-child-theme` (destructive, confirm-gated by the dispatcher):
  scaffolds style.css + functions.php (parent stylesheet enqueue), slug is
  `sanitize_key`-confined to a single path segment under `get_theme_root()`,
  refuses when the active theme is already a child (no grandchildren),
  idempotent against its own marker comment, refuses to touch a directory it
  did not scaffold, does not activate the theme.

## Remaining work

- [ ] Route scaffolder file writes through `WPMCP\Safety\File_Backup` so the
      child theme participates in the standard undo story (TODO marker in
      the handler).
- [ ] First curated framework settings pack (family chosen by install-base
      data; Astra is the leading candidate), registering ops only when that
      family is active, with CSS-cache refresh after writes. Hook point:
      `Theme_Integration::framework_pack_operations()`.
- [ ] Failing-first tests in tests/free: allowlist refusal, structural-key
      refusal, scaffolder idempotency, grandchild refusal, path confinement.
- [ ] Adversarial security review of the scaffolder (file writes, path
      confinement, marker spoofing on the idempotency check).
- [ ] Decide whether `set-theme-mods` should be default-off behind an opt-in
      filter like the Meta Box write op, or stays default-on given the
      allowlist.
