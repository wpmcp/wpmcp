# Issue #159: remove Pro\Gate from the directory build

Findings B-03, R-04, R-05. Guidelines 5 and 9.

## Where things stand

The build-time pruning the issue depends on already exists:

- `scripts/flavors/wporg/strip.php` deletes `src/Pro` and `src/Freemius`
  outright (REMOVED_PATHS), rewrites every `Gate::` call site
  (Registrar tier skip, `Snapshot_Store::prune(Gate::history_limit())`,
  `Build_Page`, `Insert_Stock_Image`, `Ability_Grid_Page`,
  `Skill_Library`), and drops the `'pro_active' => Gate::is_pro()` line
  from `src/Tools/Context/Get_Site_Context.php`.
- `scripts/build-wporg-release.sh` re-derives every answer from the staged
  tree with independent gates, and CI (`.github/workflows/ci.yml`,
  `compliance` job) runs the full build plus the wporg-free compliance
  profile on every push and PR.

Verified locally on this branch: strip runs clean against a fresh stage
(195 edits, 9 inline pro abilities removed, 53 unreferenced files swept),
`php -l` passes on every staged file, and no `Gate::`, `is_pro`,
`pro_active`, or Freemius surface survives in the staged source.

## What this branch adds

Gate hardening in `scripts/build-wporg-release.sh` so each definition of
done bullet is checked directly rather than implied:

1. `\bpro_active\b` and `\bset_pro_for_tests\b` added to the gate 3
   pattern list. Before this, `pro_active` was only caught transitively
   through `is_pro` on the same line; if the strip edit for
   `Get_Site_Context` ever drifted to remove the call but keep the key,
   nothing would have flagged the key itself.
2. Explicit path checks that `src/Pro` and `src/Freemius` are absent from
   the stage, matching the first definition of done bullet as a path
   assertion, not just a text grep.

## Remaining work

- [ ] Ability-count parity gate: record the expected ability counts for
      the full and wporg builds (the strip currently reports "9 inline
      pro abilities removed") and fail the build when either count
      drifts, per the last definition of done bullet.
- [ ] Once the Freemius add-on split lands, re-confirm on the built zip
      that the licence check unlocks nothing that ships (issue text:
      "re-confirm the licence check unlocks nothing that ships").
- [ ] Close the loop on the issue with a pointer to the CI compliance
      artifact for the release that first ships the pruned zip.
