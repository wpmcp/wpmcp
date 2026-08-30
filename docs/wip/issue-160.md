# Issue #160: prune pro abilities at build time instead of gating at runtime

Finding B-04, guidelines 5 and 9. A reviewer who greps the directory zip must
not find withheld ability code that a tier check unlocks: the paid tier has to
be absent from the artifact, not disabled in it. The same reviewer reads the
docblocks, so a comment that still narrates a licence gate is a finding even
when the gate itself is gone.

## Done in this branch

- `scripts/flavors/wporg/strip.php` no longer rewrites the Registrar tier
  branch into a belt-and-braces refusal. Both tier branches are deleted
  outright: the `register()` skip and the `('pro' !== $a->tier || Gate::is_pro())`
  term in `is_permitted()`.
- `Integration_Dispatcher::tier()` is deleted in this build and the three
  Ability constructions that called it take the literal `'free'`. That was the
  only ability tier in the tree that was not a source literal, so it was the
  one thing gate 3's `^\s*'pro',\s*$` scan and the strip's own
  `remove_pro_abilities()` assertion could never see. Removing the runtime
  refusal without this would have left a real (if currently unexercised) hole:
  any concrete integration could have overridden `tier()` to `'pro'`.
- Eleven shipped files stopped describing licence- or tier-dependent behaviour
  that this build does not have: `Registrar` (the `declared()` docblock's
  "the pro gate or governance", and the `is_permitted()` replacement, which no
  longer narrates payment at all), `Integration_Dispatcher`, `MCP\Server`,
  `Tool_Exposure`, `Call_Tool`, `List_Tools`, `List_Tool_Catalog`,
  `Memory_Config`, `Snapshot_Store`, `Widget_Catalog`, `Ability_Grid_Page`,
  `Skills_Settings_Page` and two `Plugin.php` comments. The
  `list-tool-catalog` registered description no longer says "(free/pro)".
- `scripts/build-wporg-release.sh` gains three gates, each re-derived from the
  staged tree rather than trusted from the strip:
  - **3b** the staged `src/MCP/Registrar.php` must not contain the string
    `tier`. It now asserts the file exists first: `grep -qi` exits 2 on a
    missing file and `if` reads that as clean, so a rename would have made the
    gate pass silently.
  - **3c** every `new Ability(...)` in the staged tree must pass the literal
    `'free'` as its tier, at token level. This is the invariant the deleted
    runtime refusal used to hold, and unlike a text scan it sees a tier that is
    computed, inherited or overridden.
  - **3d** a tree-wide scan for prose that claims licence gating: `pro-tier`,
    `pro gate`, `pro-licen[cs]e`, `unlicensed`, `without a (live) licen[cs]e`,
    `licen[cs]e laps...`, `payment to unlock`, `licen[cs]e gate`. Patterns were
    chosen to have no innocent reading, so the GPL header, the stock-image
    `license` fields, the "WPML is a paid plugin" notes and Paid Memberships
    Pro do not match.
- `tests/free/WporgStripTest.php` runs the real strip over a staged copy of
  `src/` and asserts the same three invariants from the produced tree, plus a
  negative case proving the tier scan reports a non-literal tier rather than
  passing vacuously.
- Verified locally: strip applies 213 edits, removes 9 inline pro abilities and
  sweeps 53 unreferenced files; `php -l` clean across the stripped tree; gates
  3b/3c/3d pass on the stripped stage and 3c fails as intended when one
  `'free'` is put back to `$this->tier()`.

## Remaining work

- Ability-count gate: assert the directory artifact registers exactly the free
  count pinned in `tests/support/ability-manifest.php` (currently 213 free of
  304; the issue's 187/260 figures predate later additions). Gate 3c proves
  every registered ability is free, which is the safety half; it does not
  prove none went missing. The count half needs the extracted zip booted under
  the test harness in step 6, diffing registered names against the manifest's
  free list the way the drift-guard test does, and the dispatcher pairs are
  built at runtime so a static name scan cannot substitute.
- Move the prune list itself onto the manifest (the way
  `scripts/flavors/woocommerce/` prunes by group) so `REMOVED_METHODS` and the
  inline-pro sweep in `strip.php` cannot drift from
  `tests/support/ability-manifest.php` when an ability is re-tiered.
- Confirm the full release build still yields free plus pro unchanged
  (`scripts/build-release.sh`) on CI, where composer and the WP fixtures are
  available.
- Deliberately left alone: `List_Tools`, `Get_Tool_Schema`, `List_Tool_Catalog`
  and `Ability_Grid_Page` still report each ability's `tier` and still filter
  on it. In this build every value is `'free'`, so the field withholds nothing
  and implies nothing about payment; removing it would change the tool contract
  between the two builds for no compliance gain. The copy that named a paid
  tier is gone.
