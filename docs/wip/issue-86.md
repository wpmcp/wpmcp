# Platform & capability program: waves 0-2 (issue #86)

Working plan for the program epic. This maps each wave item to its current
state in the codebase and lists what this branch contributes.

## Program principles (from the epic)

- Demand-gated building: several Wave 2 items do not start until there is
  real traction evidence. They carry the `demand-gated` label.
- Safety is never gated: every mutating tool ships snapshot-first through
  `Safe_Mutation` or is honestly flagged; risky surfaces default off behind
  governance; no safety or recovery feature moves to the pro tier.
- 100% coverage goal: TDD for every feature (tests/free + tests/pro), and
  adversarial security review for anything touching exec, DB writes, file
  writes, or licensing.

## Wave status audit (as of this branch)

### Wave 0 - Platform foundations
- #54 licensing / pro activation: Freemius wiring present (src/Freemius/).
- #55 coverage tooling + registration drift guard: coverage floor gate
  exists (bin/check-coverage.php, composer test:coverage); runtime drift
  guard exists (tests/free/Platform/AbilityManifestTest.php against
  tests/support/ability-manifest.php). This branch adds the static
  WordPress-free half: bin/check-ability-drift.php (composer drift:check).
- #80 site context in handshake: Get_Site_Context tool present.
- #82 reversible DB writes: tests/free/Database/ReversibleDbWritesTest.php
  present alongside src/Safety/.

### Wave 1 - Product leverage
Largely landed: surgical block edits (Tools/Blocks), build-page
(Tools/Compose), widget catalog (Tools/Elementor/Catalog), dispatcher
framework (Tools/Dispatch), ability toggle grid (Governance), media surface
(Tools/Media), compact tool-surface mode.

### Wave 2 - Vertical depth
Many items have code (Woo, SEO, forms, theme, design tokens, CSS/JS
injection, CLI jobs, skills over MCP, search index, snippet store). The
demand-gated items (#70, #72, #73, #75) start only on demand evidence.

## This branch (first slice)

1. bin/check-ability-drift.php: static drift guard. Fails CI when a
   `use WPMCP\Tools\...` import in src/Plugin.php has no class file on
   disk; with --strict also fails on tool class files nothing references.
   Runs in milliseconds with no WordPress install, so it can gate every
   job, not only the WP test matrix.
2. composer drift:check script running the guard in strict mode.

## Remaining work for the epic

- [ ] Wire drift:check into the CI workflow next to lint.
- [ ] Per-sub-issue verification pass: confirm each checked wave item has
      its tests in tests/free or tests/pro and close it on the epic.
- [ ] Apply the demand-gated label audit to #70, #72, #73, #75.
- [ ] Coverage ratchet: raise the check-coverage.php floor as suites grow.
