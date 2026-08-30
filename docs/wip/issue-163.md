# Issue #163: remove insert-stock-image from the directory build

Finding B-07, guideline 5. `src/Tools/Media/Stock/Insert_Stock_Image.php:27`
gates the composite insert flow behind `Gate::can_use()`, the same
included-and-locked shape as the Elementor dialect (B-06). The fix is the
same shape too: the ability leaves the directory build at build time, so
the finding stops existing rather than being defended.

## What the build already does

`scripts/flavors/wporg/strip.php` on main already carries the three pieces:

- `src/Tools/Media/Stock/Insert_Stock_Image.php` is in `REMOVED_PATHS`
- the `$insert_stock_image = new Insert_Stock_Image();` local is an exact
  string edit
- the `wpmcp/insert-stock-image` registration is tier `'pro'`, so
  `remove_pro_abilities()` sweeps the whole `$registrar->register(...)`
  statement, and `prune_unused_imports()` drops the orphaned `use` line

Verified against a staged copy of the current tree: after the strip, the
file is gone and no `insert-stock-image` / `Insert_Stock_Image` string
remains anywhere under the staged `src/`.

## What this branch adds

Nothing asserted the definition of done, so a refactor could silently
regress it (a rename of the ability, a tier change to `'free'` with the
runtime gate kept, or a new free-tier reference to the class would all
have shipped). Two guards:

- `tests/free/Build/WporgStripInsertStockImageTest.php`: stages the real
  `src/` tree, runs the real strip, and asserts the file is gone and no
  residual string survives, and that the full build still registers the
  ability.
- gate 3b in `scripts/build-wporg-release.sh`: the artifact build fails if
  either string survives into the staged tree, independent of the strip
  script having done its job, matching the stance of the other gates.

## Remaining work

- Run the full free suite in the CI environment (the new test shells out
  to `scripts/flavors/wporg/strip.php`, so it needs the scripts directory
  present, which is true for a checkout but worth confirming in CI).
- Definition of done mentions the directory zip's manifest: there is no
  per-flavor ability manifest today (`tests/support/ability-manifest.php`
  pins the full build only). Decide whether the wporg cut gets its own
  generated manifest asserting the 213 free abilities, or whether gate 3b
  plus the compliance engine run in step 6 of the build script is the
  manifest-level guarantee. If a wporg manifest is added, assert
  `wpmcp/insert-stock-image` absent there.
- Run `bash scripts/build-wporg-release.sh` end to end (needs composer
  network access) and confirm the zip builds with gate 3b in place.
- Close out COMPLIANCE.md row B-07 once verified.
