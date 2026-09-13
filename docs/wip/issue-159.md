# Issue #159: remove Pro\Gate from the directory build

Findings B-03, R-04, R-05. Guidelines 5 and 9. Working notes for the branch,
kept short on purpose: the reasoning that outlives the branch lives next to
the code it explains, in `scripts/flavors/wporg/policy.php` and in the gate
comments in `scripts/build-wporg-release.sh`.

## Where things stand

The build-time pruning the issue depends on already existed before this
branch. `scripts/flavors/wporg/strip.php` deletes `src/Pro` and
`src/Freemius` outright, rewrites every `Gate::` call site, and drops the
`'pro_active' => Gate::is_pro()` line from
`src/Tools/Context/Get_Site_Context.php`; `scripts/build-wporg-release.sh`
re-derives every answer from the staged tree, and CI runs it on every push.

So this branch does not remove `Pro\Gate`. It closes the holes that let
pay-to-unlock surface back into the zip past those gates, and puts the whole
thing under test.

## What the branch changes

**One policy, three consumers.** `scripts/flavors/wporg/policy.php` now holds
the removed paths and every scan pattern. The strip, the build script's gates
and `tests/free/Platform/WporgStripTest.php` all read it, so the release build
and CI cannot disagree about what counts as a finding, and a malformed entry
is a PHP error rather than a pattern silently skipped by a shell parser.
Sharing the vocabulary is not the same as trusting the strip: every gate still
re-scans the staged tree itself.

**Non-PHP surface.** Every gate was `--include='*.php'`, and the bundled
playbooks under `src/Skills/library/*/SKILL.md` ship inside the zip. Three of
them told the agent a capability was withheld (a 20-operation snapshot cap "on
an unlicensed site", pro-tier tools re-checking a licence, the Elementor tools
being pro tier). They are exact-string edits in the strip now, gated by
`document_copy_patterns`. `tier: pro` in frontmatter is in that list and is
also asserted by the strip itself, because it is the one machine-readable
pay-to-unlock marker in a non-PHP file that the shipped code parses.

**Agent-facing strings.** `wpmcp/build-page` described its Elementor dialect
as `(PRO, requires Elementor)` in the description sent with every tools/list
response, while the strip was rewriting the same class's docblock to say both
dialects are free. Gate 3 could not see it (no `Gate::`/`is_pro` token) and
gate 3b skips PHP. Gate 3d scans PHP string tokens on their own, so the
docblocks that legitimately discuss third-party paid plugins (WPML, Elementor
Pro, and Elementor's own free/pro widget tier in `list-widgets`) are not swept
in with them.

**The skills tier, collapsed rather than answered.** `is_locked()` was
rewritten to return `false`, which left two dead branches, a `locked` key in
the catalog projection and a Tier column on the admin screen that could only
ever print "free". The strip now removes the predicate, both call sites, the
projection key, the parsed tier and the admin column.

**readme.txt has its own, narrower list.** Guideline 5 recommends "add-on
plugins, hosted outside of WordPress.org, in order to exclude the premium
code", so a factual pointer at the off-directory add-on is the recommended
remedy, not a finding. `[Pp]remium` and a bare `licence` would both block
copy the readme is expected to carry.

**`remove_path()` reports.** It ignored the return value of
`unlink()`/`rmdir()`, so a partial removal was silent until a later grep in a
different script happened to notice. It returns the paths it could not delete
and the strip aborts naming them.

## Definition of done

- [x] `src/Pro/Gate.php` is absent from the directory zip: `assert_pruned` on
      the stage and again on the extracted zip, plus
      `WporgStripTest::test_pro_gate_and_licensing_bootstrap_are_absent`.
- [x] No `Gate::` reference survives in the staged source: gate 3 and
      `WporgStripTest::test_staged_source_has_no_paid_predicate`.
- [x] `pro_active` no longer reported by `get-site-context`: the strip drops
      the line and `\bpro_active\b` is a `paid_source_patterns` entry.
- [x] Full and pro builds still produce their current ability counts:
      `AbilityManifestTest` pins the names, tiers and counts and passes on
      this branch. Gate 7 backs it up by proving the build did not modify the
      checkout the manifest is derived from; it compares a before/after
      snapshot rather than testing for a clean tree, so an unrelated work in
      progress under `src/` does not fail a local build.

## Remaining work

- [ ] Internal docblocks in files the strip keeps still use pro-tier
      vocabulary (`src/MCP/Registrar.php:35`, `src/MCP/Server.php:113`,
      `src/Plugin.php:3531`,
      `src/Integrations/Integration_Dispatcher.php:58`,
      `src/Tools/Security/Software_Audit.php:158`). None is an agent-facing
      string, but the script's own gate-3 comment says a docblock that talks
      about licensing is a finding too. Deliberately not bundled here so the
      copy edits stay reviewable.
- [ ] `src/Plugin.php` carries an orphaned docblock for a method that no
      longer exists (`:2214` in the source tree, describing the cloud group
      as "All PRO"). It predates this branch and survives the strip because
      it is not attached to anything the strip removes.
- [ ] The policy is not yet a compliance rule. `tools/compliance/src/Rules/`
      is the repo's abstraction for this, and a rule would run under
      `composer compliance:wporg` and land in the CI compliance artifact;
      `Rule_Context` exposes only `php_files()` today, so the non-PHP scan
      cannot move there without widening it.
- [ ] Once the Freemius add-on split lands, re-confirm on the built zip that
      the licence check unlocks nothing that ships.
