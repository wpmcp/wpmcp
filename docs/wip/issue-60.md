# WIP plan: global design token tools (issue #60)

## Where the issue already stands

Most of the issue's surface exists on main:

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
- `wpmcp/replace-system-typography` (src/Tools/Elementor/Replace_System_Typography.php):
  same atomic contract for the four system typography slots, keeping any
  `typography_*` field and flipping `typography_typography` to `custom` when
  a font is set (same idiom as update-global-typography).
- `spacing` key in the get-global-settings read (subset of persisted kit
  layout settings), completing the "colors, typography, spacing" read in the
  acceptance criteria.
- Both new tools registered in src/Plugin.php next to the existing kit
  write tools (pro tier, `manage_options`, elementor/update).

## Remaining work

- [ ] TDD per the issue: failing test first that rollback restores the exact
      prior kit state after a replace; reads in tests/free, writes in
      tests/pro (mirroring the existing kit tool tests).
- [ ] Tests for the atomicity contract: incomplete set, duplicate slot,
      unknown slot, invalid hex all refuse before any write.
- [ ] Decide whether `spacing` should also gain a write tool
      (update-global-spacing) or stay read-only for this issue; the read
      helper carries a TODO tying breakpoint variants to that decision.
- [ ] Builder-visibility check on a real Elementor install (kit CSS
      regeneration after replace).
