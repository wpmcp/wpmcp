# Issue #160: prune pro abilities at build time instead of gating at runtime

Finding B-04, guidelines 5 and 9. A reviewer who greps the directory zip must
not find withheld ability code that a tier check unlocks: the paid tier has to
be absent from the artifact, not disabled in it.

## Done in this branch

- `scripts/flavors/wporg/strip.php` no longer rewrites the Registrar tier
  branch into a belt-and-braces refusal. Both tier branches are deleted
  outright: the `register()` skip and the `('pro' !== $a->tier || Gate::is_pro())`
  term in `is_permitted()`. The strip already removes every pro-tier
  registration and aborts if one survives, so the runtime check carried no
  safety and read exactly like "code disabled pending payment".
- The declared-catalog comment and the `is_permitted()` docblock replacements
  no longer mention tiers or licensing.
- `scripts/build-wporg-release.sh` gains gate 3b: the build fails if the
  staged `src/MCP/Registrar.php` contains the string `tier` at all
  (case-insensitive), so a future edit cannot quietly reintroduce a branch.
- Verified locally: staged `src/`, ran the strip (196 edits, 9 inline pro
  abilities removed, 53 unreferenced files swept), zero `tier` matches in the
  stripped Registrar, `php -l` clean across the whole stripped tree.

## Remaining work

- Ability-count gate: assert the directory artifact registers exactly the
  free count pinned in `tests/support/ability-manifest.php` (currently 213
  free of 304; the issue's 187/260 figures predate later additions). Likely
  shape: a manifest-driven check in `build-wporg-release.sh` step 6 that
  boots the extracted zip under the test harness and diffs registered names
  against the manifest's free list, the way the drift-guard test does.
- Move the prune list itself onto the manifest (the way
  `scripts/flavors/woocommerce/` prunes by group) so `REMOVED_METHODS` and
  the inline-pro sweep in `strip.php` cannot drift from
  `tests/support/ability-manifest.php` when an ability is re-tiered.
- Confirm the full release build still yields free plus pro unchanged
  (`scripts/build-release.sh`), and run the drift-guard tests untouched.
- Sweep remaining `tier` mentions in other staged files only where they
  imply payment (the ability grid and skills library keep their per-document
  tier metadata per the existing strip comments; re-read those against
  guideline 9 wording).
