# Platform & capability program: waves 0-2 (issue #86)

Working plan for the program epic. It records what this branch contributes and
where each wave item currently stands, with the standard of evidence stated
per row rather than implied.

## Program principles (from the epic)

- Demand-gated building: several Wave 2 items do not start until there is
  real traction evidence. They carry the `demand-gated` label.
- Safety is never gated: every mutating tool ships snapshot-first through
  `Safe_Mutation` or is honestly flagged; risky surfaces default off behind
  governance; no safety or recovery feature moves to the pro tier.
- 100% coverage goal: TDD for every feature (tests/free + tests/pro), and
  adversarial security review for anything touching exec, DB writes, file
  writes, or licensing.

## This branch

`bin/check-ability-drift.php`, the WordPress-free half of the registration
drift guard from Wave 0 item #55, plus its wiring and its tests.

The guard reads a tree (`php bin/check-ability-drift.php [--strict]
[--manifest PATH] [--no-manifest] [root]`, default root: the repository) and
answers three questions:

1. **Named class drift.** Every WPMCP class named anywhere under `src/` or in
   a root entry file, in any of the forms code actually uses (plain import,
   aliased import, group import with or without a sub-namespace segment,
   comma-separated import, `new \WPMCP\...`, `Foo::` static, `::class`,
   string callable), has a declaration on disk. The entry file is scanned as
   well as seeded from: it is the one file where a dangling class fatals at
   load, and the wp.org flavor regenerates it. A `use WPMCP\...` statement the
   parser cannot consume is itself a failure, so the count is never quietly
   short. Classes named only behind a `class_exists()` probe are exempt in
   both spellings, `::class` and the string literal: the guard is the code
   saying the class is optional.
2. **Unreachable tools.** A class file under `src/Tools` that no registration
   root reaches. Roots are the plugin entry files plus anything named from
   outside `src/` (`scripts/` including the flavor builds, `tools/`, `bin/`);
   `tests/` is deliberately not a root, because a tool referenced only by its
   own unit test is still not wired into the product. Reachability is a graph
   walk, so a dead cluster of tools that only reference each other is still
   reported. References are matched on PHP tokens with comments discarded, so
   a docblock mention is not wiring and `Get_Post_Meta` does not satisfy
   `Get_Post`. Only the last segment of a qualified name counts as a class
   reference, so the `Content` in `WPMCP\Tools\Content\Get_Post` does not
   keep a dead `WPMCP\Tools\Legacy\Content` alive. An import with no call
   site in the importing file is not an edge either: a tool dropped from the
   registrar and left with a stale `use` line is the drift, not the alibi.
   The check is still a reference graph rather than a registration graph, so
   a class reached only through a live call site that never registers it
   passes; what it rules out is the mention-only and import-only shapes.
3. **Ability drift.** The ability name/tier literals in `new Ability(...)`
   diffed against `tests/support/ability-manifest.php`: a rename or a silent
   free/pro re-tier fails without a WordPress install. 251 of the 304 pinned
   abilities are registered with literal name and tier and are checked this
   way; the rest are table-driven or built from a slug and stay the runtime
   manifest test's job. The check is one directional by construction: it
   walks the names it finds in code, so an ability still pinned in the
   manifest but no longer registered anywhere passes here and is the runtime
   test's job too. A manifest that is missing or unreadable is a failure
   under `--strict`, never a silent skip.

Known exemptions live in two documented allowlists at the top of the script
(`src/Tools/Backup/Url_Rewriter.php`, landed ahead of its consumers under
test; the three multisite-only abilities the single-site manifest cannot
pin). The unreachable allowlist is keyed on the src-relative path, not the
short name, so it cannot silence a future class that happens to share a name,
and it expires on its own: an entry that becomes reachable, or whose file is
gone, fails the guard. Anything else has to be wired or deleted.

Wiring: `composer drift:check` (strict) runs in CI next to `composer lint`,
before the WP test environment is installed, and again as gate 4b inside
`scripts/build-wporg-release.sh` against the stripped staging tree, which is
where a removed tool most plausibly leaves a fatal behind. Gate 4 of that
script asks "does every named class autoload"; this asks "is it still wired".
All three checks run there: the stage ships no `tests/` directory (gate 5
fails if it does), so gate 4b passes `--manifest "$ROOT/tests/...php"`
explicitly rather than letting the ability third skip itself. Verified on a
stripped stage: 416 class references, 269 tool classes, 184 literal ability
registrations diffed against the 304 pinned.

Tests: `tests/free/Platform/AbilityDriftGuardTest.php` runs the real script
against fixture trees (missing class imported from a non-Plugin file, aliased
imports, group imports with and without a sub-namespace segment,
comma-separated imports, a `class_exists()` probe in both spellings, a
missing class named from the entry file, a prefix-colliding tool pair, a
docblock-only mention, an import-only tool, a tool named like a namespace
segment, a dead cluster, a flavor-only registration, a reachable allowlist
entry, a re-tiered ability, a renamed ability, a missing manifest, an
explicit out-of-tree manifest, a corrupt manifest) and against this
repository in strict mode. 25 tests, 56 assertions.

## Wave status (evidence: code present, not acceptance-verified)

Each row below records only that code exists at the named path. None of these
rows is a claim that the sub-issue's acceptance criteria are met: that check
(ability registered + tests in tests/free or tests/pro + criteria walked) is
the per-sub-issue pass still listed under Remaining work, and no epic box
should be ticked before it runs.

### Wave 0 - Platform foundations
- #54 licensing / pro activation: code present (src/Freemius/). Unverified.
- #55 coverage tooling + registration drift guard: coverage floor gate
  (bin/check-coverage.php, composer test:coverage, enforced in CI) and both
  halves of the drift guard now exist: runtime
  (tests/free/Platform/AbilityManifestTest.php against
  tests/support/ability-manifest.php) and static (this branch, wired into CI
  and the wp.org build, tested in
  tests/free/Platform/AbilityDriftGuardTest.php).
- #80 site context in handshake: code present
  (src/Tools/Context/Get_Site_Context.php). Unverified.
- #82 reversible DB writes: code present (src/Safety/,
  tests/free/Database/ReversibleDbWritesTest.php). Unverified.

### Wave 1 - Product leverage
Code present at: Tools/Blocks (surgical block edits), Tools/Compose
(build-page), Tools/Elementor/Catalog (widget catalog), Tools/Dispatch
(dispatcher framework), Governance (ability toggle grid), Tools/Media.
Unverified against each sub-issue's criteria.

### Wave 2 - Vertical depth
Code present for Woo, SEO, forms, theme, design tokens, CSS/JS injection, CLI
jobs, skills over MCP, search index, and the snippet store. Unverified. The
demand-gated items (#70, #72, #73, #75) start only on demand evidence.

## Remaining work for the epic

- [ ] Per-sub-issue verification pass: for each wave item, confirm the
      abilities register and the tests exist in tests/free or tests/pro, walk
      the issue's acceptance criteria, and only then tick the epic box.
- [ ] Extend the static ability check past literal `new Ability(...)`: the
      table-driven registrations (cloud, integrations) are 53 abilities the
      static half currently cannot see.
- [ ] Empty the `$knownUnreachable` allowlist by wiring Url_Rewriter into
      restore/migration, which is what it was landed for.
- [ ] Apply the demand-gated label audit to #70, #72, #73, #75.
- [ ] Coverage ratchet: raise the check-coverage.php floor as suites grow.
