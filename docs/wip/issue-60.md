# WIP notes: global design token tools (issue #60)

Scratch notes for the branch. Delete this file in the commit that takes the
PR out of draft; the durable record is the PR description and issue #60.

## Where the issue already stood

Most of the issue's surface was already on main:

- `wpmcp/get-global-settings` (read): active kit colors and typography with
  `settings_hash` for optimistic locking (src/Tools/Elementor/Get_Global_Settings.php).
- `wpmcp/update-global-colors` and `wpmcp/update-global-typography` (pro
  writes): per-slot patching through `Elementor_Kit_Data::write()`, which
  routes through `Safe_Mutation` so kit writes are snapshotted and undoable
  via rollback-operation.
- CSS regeneration is triggered after every kit write
  (`files_manager->clear_cache()` in `Elementor_Kit_Data::write()`).

## What this branch adds

- `wpmcp/replace-system-colors` (src/Tools/Elementor/Replace_System_Colors.php):
  atomic replacement of the four system color slots. The whole set is
  validated before the single write: every slot present exactly once, valid
  hex colors, unknown or duplicate slots rejected. All four slots or none.
  An entry that omits `title` keeps the slot's current title, so replacing a
  palette does not silently rename a slot the user renamed in the builder.
- `wpmcp/replace-system-typography` (src/Tools/Elementor/Replace_System_Typography.php):
  same atomic contract for the four system typography slots. Each entry may
  carry only `_id`, `title` and `typography_*` fields, and must carry at
  least one `typography_*` field: an unrecognized key (a misspelled
  `font_family`, say) is refused rather than silently dropped, because under
  replace semantics a silently emptied entry wipes the slot. Setting a font
  flips `typography_typography` to `custom` (same idiom as
  update-global-typography), and array-valued fields are sanitized to their
  leaves rather than stored verbatim.
- `wpmcp/get-global-settings` re-tiered to **free**, per the issue's
  "read free / writes pro". The four kit writes stay pro.
- `spacing` and `layout` groups on the read: `spacing` is the genuine
  spacing tokens (`space_between_widgets`, `container_padding`); the
  breakpoint and content-width settings (`container_width`, `viewport_lg`,
  `viewport_md`) are reported separately under `layout` rather than
  mislabelled as spacing.
- Tests: tests/free/Elementor/GlobalSettingsReadTest.php (free read, group
  shape, tiering) and tests/pro/Elementor/ReplaceSystemTokensTest.php
  (rollback restores the exact prior kit state; the atomicity matrix).
- tests/support/ability-manifest.php updated for the two new abilities and
  the read re-tier (total 306, free 214, pro 92).

## Remaining work

- [ ] Run the suite. The tests above have not been executed on this machine:
      the local MySQL data directory is on an incompatible server version, so
      no WordPress test database could be brought up. CI is the first real
      run.
- [ ] Regenerate the manifest with `composer manifest:regenerate` in CI and
      confirm it is byte-identical to the committed file (it was produced by
      applying the generator's own format and `ksort` order by hand, for the
      same reason).
- [ ] Decide whether `spacing` should also gain a write tool
      (update-global-spacing) or stay read-only for this issue; the read
      helper carries a TODO tying breakpoint variants to that decision.
- [ ] Builder-visibility check on a real Elementor install (kit CSS
      regeneration after replace).
