# Issue #159: remove Pro\Gate from the directory build

Findings B-03, R-04, R-05. Guidelines 5 and 9.

## Where things stand

The build-time pruning the issue depends on already existed before this
branch:

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

So this branch does not remove `Pro\Gate`; that was already done. What it
does is close the holes that let pay-to-unlock surface back into the zip
past those gates, and put the whole thing under test.

## What this branch adds

### Non-PHP surface is now stripped and gated

Every gate was `--include='*.php'`, and the bundled agent playbooks under
`src/Skills/library/*/SKILL.md` ship inside the zip. Three of them still told
the agent that a capability was withheld:

- `wpmcp-safe-writes/SKILL.md` promised a 20-operation snapshot cap "on an
  unlicensed site", which is exactly the guideline 5 "quota that is lifted by
  payment", even though the strip had already rewritten retention to the flat
  filterable `Snapshot_Store::history_limit()`.
- `wpmcp-governance/SKILL.md` described pro-tier tools re-checking a licence
  on every call, and a missing licence as a reason a tool is absent.
- `wpmcp-elementor-editing/SKILL.md` described the Elementor tools as pro
  tier.

All three are now exact-string edits in `strip.php`, and a new gate 3b greps
every non-PHP file under `$STAGE/src` plus `readme.txt` for pay-to-unlock
copy. `vendor/` is deliberately out of scope (third-party licence files) and
so is `readme.txt`'s required `License: GPLv2 or later` header, which is why
the document patterns are the narrow ones (`pro licen[sc]e`, `pro tier`,
`premium`, `unlicensed`) rather than a bare `licence`.

### Admin copy the token patterns could not see

`src/Admin/Skills_Settings_Page.php` rendered
`'Listed, body needs a Pro licence'`. The branch was dead only because
`Skill_Library::is_locked()` is rewritten to return false, and no gate-3
pattern can match a string with no `Gate::`/`is_pro` token in it. The branch
is now collapsed by the strip, the same way `Ability_Grid_Page.php` is
handled, along with that page's class docblock describing locked PRO rows.

### Gate patterns that can actually fire

- `\bpro_locked\b` and `'tier' => 'pro'` added. Both live in files the strip
  edits in place rather than deletes, so if one edit drifted while the others
  still applied there would be no `Gate::`/`is_pro` token left on the
  surviving line to catch it.
- `\bset_pro_for_tests\b` removed. It only ever occurs as
  `Gate::set_pro_for_tests(...)` under `tests/`, which gate 3 does not scan
  and gate 5 already refuses to package, so it was dead weight that made the
  pattern list read broader than it was.

### Path assertions read from the strip, and run on the zip

The two hand-written `src/Pro` / `src/Freemius` checks are replaced by a loop
over the strip's own `REMOVED_PATHS`, parsed out of `strip.php` so the two
lists cannot drift. It runs against the stage and again against the extracted
zip, which is what the definition of done is worded about. This matters
because `remove_path()` ignores the return value of `unlink()`/`rmdir()`, so a
partial removal is otherwise silent.

### Tests

`tests/free/Platform/WporgStripTest.php` runs the strip against a throwaway
stage on every test run and asserts the post-conditions directly: every
declared removed path is gone (and still exists upstream), no paid predicate
in the staged PHP, no pay-to-unlock copy in any staged non-PHP file, and
snapshot retention is one flat filterable number. Previously the strip was
only exercised by the release build, which runs in one CI job.

## Definition of done

- [x] `src/Pro/Gate.php` is absent from the directory zip: asserted on the
      stage and on the extracted zip by `assert_pruned`, and by
      `WporgStripTest::test_pro_gate_and_licensing_bootstrap_are_absent`.
- [x] No `Gate::` reference survives in the staged source: gate 3, plus
      `WporgStripTest::test_staged_source_has_no_paid_predicate`.
- [x] `pro_active` no longer reported by `get-site-context`: the strip drops
      the line and `\bpro_active\b` is a gate-3 pattern.
- [x] Full and pro builds still produce their current ability counts: the
      counts are pinned by `tests/support/ability-manifest.php` and asserted
      every CI run by `AbilityManifestTest`, which reads the checkout. The
      only way this build could move them is by editing the checkout instead
      of the throwaway stage, so gate 7 fails the build if
      `git status --porcelain -- src wpmcp.php` is non-empty after it runs.

## Remaining work

- [ ] Internal docblocks in files the strip keeps still use pro-tier
      vocabulary (`src/MCP/Registrar.php:34`, `src/MCP/Server.php:113`,
      `src/Plugin.php:2036` and `:3082`,
      `src/Integrations/Integration_Dispatcher.php:58`,
      `src/Tools/Security/Software_Audit.php:158`). None of them describe a
      lock that exists in this build, but the script's own gate-3 comment
      says a docblock that talks about licensing is a finding too. Worth a
      pass, deliberately not bundled here so the copy edits stay reviewable.
- [ ] Once the Freemius add-on split lands, re-confirm on the built zip
      that the licence check unlocks nothing that ships (issue text:
      "re-confirm the licence check unlocks nothing that ships").
- [ ] Close the loop on the issue with a pointer to the CI compliance
      artifact for the release that first ships the pruned zip.
