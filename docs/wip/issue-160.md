# Issue #160: prune pro abilities at build time instead of gating at runtime

Finding B-04, guidelines 5 and 9. A reviewer who greps the directory zip must
not find withheld ability code that a tier check unlocks: the paid tier has to
be absent from the artifact, not disabled in it. The same reviewer reads the
docblocks, and `get-skill` hands the bundled markdown straight to a connecting
agent, so prose that narrates a licence gate is a finding even when the gate
itself is gone.

## Done in this branch

### The runtime tier check is deleted, not rewritten

- `scripts/flavors/wporg/strip.php` deletes both tier branches in
  `src/MCP/Registrar.php` outright: the `register()` skip and the
  `('pro' !== $a->tier || Gate::is_pro())` term in `is_permitted()`.
- `Integration_Dispatcher::tier()` is deleted in this build and the three
  Ability constructions that called it take the literal `'free'`. That was the
  only ability tier in the tree that was not a source literal, so it was the
  one thing a `^\s*'pro',\s*$` text scan could never see.

### Dead paid surface the prune used to leave behind

Removing a group's registration method used to orphan a private method that
called it, and a private method with no caller still holds every class it
instantiates out of the unreferenced-file sweep. Two of those existed:

- `register_cli_job_abilities()` (called only from `register_cli_abilities()`)
  kept the whole async WP-CLI package in the zip: `Dispatch/Get/List/Cancel_Cli_Job`,
  `Cli_Job_Store` and `Run_Cli_Job`. `Plugin.php` also hooked
  `add_action(Run_Cli_Job::HOOK, [new Run_Cli_Job(), 'handle'])`, and
  `Run_Cli_Job` defaults its executor to `Wp_Cli_Executor::class`, a class the
  strip deletes. That was a live cron hook onto a fatal.
- `register_global_class_write_abilities()` (called only from
  `register_elementor_pro_abilities()`) kept the Elementor global-class write
  handlers.

Both method names now go with the group, and the sweep takes 61 unreferenced
files instead of 53.

### Prose and runtime text that named a tier

Files that stopped describing licence- or tier-dependent behaviour: `Registrar`,
`Integration_Dispatcher`, `MCP\Server`, `Tool_Exposure`, `Call_Tool`,
`List_Tools`, `List_Tool_Catalog`, `Memory_Config`, `Snapshot_Store`,
`Widget_Catalog`, `Ability_Grid_Page`, `Skills_Settings_Page`, `Page_Spec`,
`Elementor_Composer`, `Get_Site_Context` and several `Plugin.php` docblocks
(including one that enumerated four withheld paid abilities by name and cited
`register_analysis_abilities()`, a method this build deletes).

Two of these are not comments at all:

- `Handshake_Instructions::memory_block()` appended
  "Call memory-recall for the full set and session history." to the
  instructions every connecting client reads. `src/Tools/Memory` is deleted in
  this build, so that line pointed an agent at a tool that does not exist.
- Three bundled skill documents, whose bodies `get-skill` returns verbatim:
  `wpmcp-governance/SKILL.md` ("pro-tier tools re-check the licence on every
  call"), `wpmcp-safe-writes/SKILL.md` ("On an unlicensed site the snapshot
  history is capped...", plus a paragraph pointing at `wpmcp/run-wp-cli` and
  `wpmcp/run-php-snippet`, both removed here) and
  `wpmcp-elementor-editing/SKILL.md` ("The Elementor tools are pro tier").

`Memory_Config` keeps the enforcement switch, which `Registrar` reads on every
call, and loses `tools_enabled()`, whose three tools left with
`src/Tools/Memory` and which had no caller left in the artifact.

The `is_permitted()` docblock says what the method does: capability +
Governance + identity scope, then the project-memory guardrail. It used to say
"there is no fourth", which the paragraph two lines below it and the method
body both contradicted.

### One enforcing copy of the invariants

`scripts/flavors/wporg/assert-free-tier.php` re-derives all of it from the
staged tree. `scripts/build-wporg-release.sh` runs it as a gate and
`tests/free/WporgStripTest.php` shells out to the same file, so the copy CI
proves non-vacuous is the copy that blocks a release. It used to be written
twice, inline in the build script and again in the test, and the two had
already drifted.

It checks:

1. `src/MCP/Registrar.php` exists and names no tier.
2. Every `new Ability(...)` passes the literal `'free'`, at token level. An
   argument list the scanner cannot read is a finding, not a skip: the previous
   splitter did `continue` on any call it could not split in two, and its depth
   counter did not increment for `T_DOLLAR_OPEN_CURLY_BRACES` (source text
   `${`) while the matching `}` still decremented, so `new Ability("a${$b}c",
   $this->tier(), ...)` drove depth to zero early and escaped the gate.
   Named `tier:` arguments are resolved rather than assumed positional.
3. No shipped file claims licence gating. PHP, markdown AND `readme.txt`: the
   old gate passed `--include='*.php'`, which is how three skill documents got
   through. Patterns have no innocent reading, and the `PRO` one is
   case-sensitive so "Elementor Pro" (a third-party plugin) does not match.
4. No file names a withheld registration method, as a call or a cross-reference.
5. No private `register_*_abilities()` survives without a caller.
6. Against `tests/support/ability-manifest.php`: no ability the manifest tiers
   as paid is registered under any name, and no free one went missing. The
   dispatcher pairs are resolved from each integration's own `integration()`
   slug rather than being reported as 32 false absences.

Gate 3d's old `grep -rqiE` also read a missing path's exit status of 2 as
"clean". A PHP script with an explicit "no PHP files, refusing to report a
clean tree" guard cannot fail that way.

### Verified locally

Strip applies 246 edits, removes 1 inline pro ability (the rest now leave with
their methods), sweeps 61 unreferenced files; `php -l` clean across the
stripped tree; `assert-free-tier.php` exits 0 on the stripped stage with the
real manifest; `tests/free/WporgStripTest.php` is 18 tests / 46 assertions
green, including seven negative cases that each build a deliberately bad tree
and assert the enforcing script fails on it.

## Definition of done

- **`Registrar.php` has no tier branch in the directory build** - done, gated.
- **Directory zip contains the free set and no pro ability source files** -
  done statically: check 6 asserts the set both ways against the drift-guard
  manifest, and check 5 catches the orphaned-method shape that used to keep
  paid handlers in the zip. Not an equality check on the counts: the manifest
  pins one environment (single site with the optional test plugins), so the
  three multisite-only abilities are absent from it while present in every
  build's source. The two directional checks are the part that is true
  independent of the environment.
- **Full release build still yields free plus pro unchanged** - NOT confirmed.
  Needs `scripts/build-release.sh` on CI, where composer and the WP fixtures
  are available.
- **`tests/support/ability-manifest.php` and the drift-guard tests untouched** -
  done; the manifest is read by the new gate, never written.

The issue quotes 187 free of 260. The manifest now pins 213 free of 304: the
surface grew between the audit and this branch (issues #83, #84, #131, #136,
#137 and others each added abilities). The gate reads the manifest rather than
a hard-coded number, so it does not need updating when that happens again.

## Remaining work

- Confirm the full release build on CI (the third DoD item above).
- Move the prune list onto the manifest (the way `scripts/flavors/woocommerce/`
  prunes by group) so `REMOVED_METHODS` and the inline-pro sweep cannot drift
  from `tests/support/ability-manifest.php` when an ability is re-tiered. The
  new check 6 detects that drift; it does not yet prevent it.
- Gate 4 (dangling class references) resolves only `WPMCP\`-qualified names,
  not same-namespace ones. The concrete instance this branch found,
  `Run_Cli_Job` -> `Wp_Cli_Executor`, is fixed by deleting the package. A
  general same-namespace resolver needs to skip comments and strings to avoid
  a wall of false positives, so it is its own change.

## Deliberately left alone

`List_Tools`, `Get_Tool_Schema`, `List_Tool_Catalog` and `Ability_Grid_Page`
still report each ability's `tier` and still filter on it. In this build every
value is `'free'`, so the field withholds nothing and implies nothing about
payment; removing it would change the tool contract between the two builds for
no compliance gain. The copy that named a paid tier is gone.

Likewise `free-tier` in `Plugin.php` group docblocks ("registered as free-tier
abilities"): it states that something IS free, which is true here, and it has
no paid counterpart in the artifact to point at.
