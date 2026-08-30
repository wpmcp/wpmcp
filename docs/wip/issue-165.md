# Issue #165: wp.org compliance, licensing SDK vs locally present code

Findings B-09 and R-06, guideline 6 (and the Plugin_Updater_Check angle of
guideline 8). Tracking doc for the WIP branch `wip/issue-165`.

## State of the tree before this branch

The wporg build (`scripts/build-wporg-release.sh` plus
`scripts/flavors/wporg/strip.php`) already:

- removes `src/Pro` and `src/Freemius` from the stage (strip.php list),
- rewrites every `Pro\Gate` call site so no licence predicate ships,
- runs `composer remove freemius/wordpress-sdk` in the stage so the SDK,
  including its updater files, is not vendored,
- gates on paid/licensing strings surviving into `src/` and the main file.

What it did NOT do: assert that no updater marker survives anywhere in the
staged tree including `vendor/`. `Plugin_Updater_Check` is a file-content
regex with no Freemius carve-out and wp.org does not necessarily exclude
`vendor/`, so a dependency other than the (removed) SDK could still trip it
without any gate noticing.

## Done on this branch

- New gate 4 in `scripts/build-wporg-release.sh`: greps the whole staged
  tree, `vendor/` included, for Plugin_Updater_Check's error-level markers
  (`site_transient_update_plugins`, `plugin-update-checker`,
  `WP_GitHub_Updater`, `PucFactory::buildUpdateChecker`, ...) and fails the
  build on any hit. Later gates renumbered.
- Submission notes (`WPORG-SUBMISSION.md` section 5.6): written explanation
  that the SDK removal takes the updater with it and that the build gates on
  the markers directly, satisfying the "or a written explanation in the
  submission notes" arm of the definition of done.

## Remaining work

- Run `scripts/build-wporg-release.sh` and confirm the new gate passes on a
  real stage (needs composer and the full vendor tree; not run here).
- Run the official Plugin Check CLI against the assembled zip (extracted
  `dist/wpmcp-<version>.zip`), not the working tree, and record that it shows
  no updater finding.
- Re-confirm after the `Pro\Gate` removal that the Freemius licence check in
  the full (non-wporg) build unlocks nothing that ships in the directory zip:
  audit every `Gate::is_pro()` / `can_use()` caller against the strip list.
- Consider adding the warning-level Plugin_Updater_Check patterns
  (`pre_set_site_transient_update_*`, `auto_update_plugin`) to the gate or
  documenting why they are acceptable.
