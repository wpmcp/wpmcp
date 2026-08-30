# Issue #165: wp.org compliance, licensing SDK vs locally present code

Findings B-09 and R-06, guideline 6 (and the `Plugin_Updater_Check` angle of
guideline 8). Tracking doc for the WIP branch `wip/issue-165`.

## State of the tree before this branch

The wporg build (`scripts/build-wporg-release.sh` plus
`scripts/flavors/wporg/strip.php`) already:

- removes `src/Pro` and `src/Freemius` from the stage (strip.php list),
- rewrites every `Pro\Gate` call site so no licence predicate ships,
- runs `composer remove freemius/wordpress-sdk` in the stage so the SDK,
  including its updater files, is not vendored,
- gates on paid/licensing strings surviving into `src/` and the main file.

What it did NOT do:

- assert that no updater marker survives anywhere in the staged tree,
  `vendor/` included. `Plugin_Updater_Check` is a file-content regex with no
  Freemius carve-out and wp.org does not exclude `vendor/`, so a dependency
  other than the (removed) SDK could still trip it with no gate noticing;
- give the compliance engine any view of `vendor/` beyond a hardcoded
  `vendor/freemius` probe, so `Updater_Rule` was blind to exactly that case;
- run the official Plugin Check anywhere. There was no `plugin-check` job in
  `.github/workflows/ci.yml` at all, only the in-house compliance engine.

## Done on this branch

- `scripts/lib/updater-gate.sh`: the updater gate, split out of the build
  script so it is executable against a synthetic tree by tests. It reads its
  pattern list from `Updater_Rule::UPDATER_PATTERNS` at run time rather than
  carrying a second copy, greps case-insensitively (as `Plugin_Updater_Check`
  and `Updater_Rule` both do), distinguishes grep's exit 2 from exit 1 so a
  scan failure cannot read as "clean", and adds the
  `plugin-update-checker.php` basename match that no content grep can catch.
  Wired in as gate 6 of `scripts/build-wporg-release.sh`, after the packaging
  prune, so the tree it scans is the zip's contents.
- `Updater_Rule` now applies the error-level patterns to every vendored PHP
  file when the scan is an artifact scan (`vendor_updater_hits()`). A
  development checkout still skips it: its `vendor/` is full of dev
  dependencies that never ship. This is what makes the claim in
  `WPORG-SUBMISSION.md` 5.6 true rather than vacuous, since the engine's only
  previous vendor coverage was a hardcoded `vendor/freemius` loop that this
  build has already deleted.
- New `plugin-check` job in `.github/workflows/ci.yml`: builds the zip and
  runs `WordPress/plugin-check-action@v1` over the extracted tree with
  `exclude-directories: '.git,node_modules'`. The CLI's default excludes
  `vendor/` and the wp.org side does not, which is the documented reason R-06
  never appeared in a local run (COMPLIANCE.md), so the override is the point
  of the job.
- Submission notes (`WPORG-SUBMISSION.md` 5.6): the written explanation, now
  naming all three checks and scoped to what they actually cover (PHP files,
  which is what `Plugin_Updater_Check` inspects).
- COMPLIANCE.md: R-06 and B-09 carry their resolution for the directory build,
  and the Plugin Check CLI notes say how to put `vendor/` back in scope.
- Tests: `tests/free/Compliance/UpdaterGateTest.php` runs the gate script
  against fixture trees (clean tree, vendored updater, case variant, a host
  that only the rule's `\w` pattern matches, a vendored
  `plugin-update-checker.php`, a missing tree) and asserts the build script
  does not carry a duplicate pattern list.
  `tests/free/Compliance/EdgeCasesTest.php` covers the rule's new
  artifact-only vendor scan in both directions.

## Definition of done

- **No licence check in the directory build gates any included code path:**
  met. Every `Gate::is_pro()` / `Gate::can_use()` caller in `src/` is either
  removed by path (`src/Tools/Media/Stock/Insert_Stock_Image.php`) or
  rewritten by `strip.php` (`src/MCP/Registrar.php`,
  `src/Tools/Compose/Build_Page.php`, `src/Tools/Context/Get_Site_Context.php`,
  `src/Admin/Ability_Grid_Page.php`, `src/Skills/Skill_Library.php`,
  `src/Plugin.php`), each as an exact-string edit that aborts the build if the
  string it expects has moved. Gate 3 then re-derives the answer from the
  staged tree and fails on any surviving `Gate::`, `is_pro`,
  `can_use_premium_code`, `freemius`, `WPMCP_FS_` or `fs_dynamic_init`.
- **SDK updater files stripped from the directory zip, or a written
  explanation in the submission notes:** met, both arms. The SDK leaves the
  build entirely and `WPORG-SUBMISSION.md` 5.6 explains it.
- **A Plugin Check run against the assembled zip, not the working tree, shows
  no updater finding:** the run now exists in CI and is vendor-inclusive.
  Whether it is green has to be read off the first CI run of this branch: the
  build needs composer and Docker and was not executed locally.

## Remaining work

- Confirm the `plugin-check` job is green on the first CI run, and that
  `scripts/build-wporg-release.sh` passes gate 6 on a real stage. Neither was
  run locally (both need composer with network, and the Plugin Check action
  needs Docker).
- Consider adding the warning-level `Plugin_Updater_Check` patterns
  (`pre_set_site_transient_update_*`, `auto_update_plugin`) to the build gate.
  `Updater_Rule` already reports them at best-practice severity, so today they
  are visible in the engine's report without failing the build.
