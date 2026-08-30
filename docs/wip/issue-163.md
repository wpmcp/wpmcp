# Issue #163: remove insert-stock-image from the directory build

Finding B-07, guideline 5. `src/Tools/Media/Stock/Insert_Stock_Image.php:27`
gates the composite insert flow behind `Gate::can_use()`, the same
included-and-locked shape as the Elementor dialect (B-06). The fix is the
same shape too: the ability leaves the directory build at build time, so
the finding stops existing rather than being defended.

## What the build already did

`scripts/flavors/wporg/strip.php` on main already carried the three pieces:

- `src/Tools/Media/Stock/Insert_Stock_Image.php` is in `REMOVED_PATHS`
- the `$insert_stock_image = new Insert_Stock_Image();` local is an exact
  string edit
- the `wpmcp/insert-stock-image` registration is tier `'pro'`, so
  `remove_pro_abilities()` sweeps the whole `$registrar->register(...)`
  statement, and `prune_unused_imports()` drops the orphaned `use` line

Nothing asserted any of that, so a rename, a re-tier to `'free'` with the
runtime gate kept, or a new free-tier reference to the class would all have
shipped silently.

## What this branch adds

### A leak the first cut of this branch missed

The original gate only scanned `--include='*.php'`, and the staged tree
ships non-PHP files that name abilities. Running the real strip over a real
staging showed the cut shipping `src/Skills/library/wpmcp-elementor-editing/
SKILL.md`, a skill declared `tier: free` whose entire `requires:` list is
14 pro-tier abilities this build does not contain, plus two escape-hatch
mentions (`wpmcp/run-wp-cli`, `wpmcp/run-php-snippet`) in the safe-writes
playbook. A free skill instructing an agent to call tools that are not
there is both broken and, read by a reviewer, guideline 9's "implying users
must pay to unlock included features".

`strip.php` now removes the Elementor playbook whole and edits the two
escape-hatch bullets out of the safe-writes playbook.

### Two derived gates, not a per-ability one

Hardcoding `insert-stock-image` as its own numbered gate contradicted the
script's own stance that each gate "re-derives its answer from the staged
tree", and did not scale past one finding. Both new gates derive their
answer instead:

- **gate 3b**: no ability that `tests/support/ability-manifest.php` tiers
  `'pro'` (91 of them) may be *registered* in the staged tree. Registration
  is the invariant rather than string mentions, because a comment or an
  opt-in guard may legitimately still name an absent ability
  (`src/Governance/Opt_In_Gates.php` deliberately does). This is the
  manifest-level half of the definition of done.
- **gate 3c**: every ability named in a document the cut ships
  (`readme.txt`, `src/Skills/library/**/SKILL.md`) must be an ability the
  cut registers. No allowlist, and it is what caught the leak above.

Gate 3's textual paid-surface scan also lost its `--include='*.php'`, so it
now covers `readme.txt` and the skill library too.

### Tests

`tests/free/Build/WporgStripTest.php` stages the same file set the build
script stages, runs the real `scripts/flavors/wporg/strip.php` over it once
per class, and makes the same four assertions the gates make, so a
regression is caught by `composer test:free` rather than at submission
time. The full-build half is asserted through the `Registrar`
(`register_abilities_into()`, name present with tier `pro`), not by
grepping `Plugin.php`.

Verified to have teeth: reverting the Elementor playbook removal fails
`test_no_shipped_document_names_an_ability_the_cut_lacks` with all 14
names; re-registering `wpmcp/insert-stock-image` as `'free'` in a staged
tree fails gate 3b, which is a state gate 3's `^\s*'pro',\s*$` pattern
cannot see.

## State

- Definition of done, "absent from the directory zip and its manifest":
  covered by gate 3b (registration-level, derived from the manifest) and by
  `WporgStripTest`.
- Definition of done, "still present in the full build with tests green":
  covered by
  `WporgStripTest::test_the_full_build_still_registers_the_ability_as_pro`
  and the pre-existing `tests/pro/Media/InsertStockImageTest.php`.
- COMPLIANCE.md row B-07 marked resolved.

## Remaining work

- `bash scripts/build-wporg-release.sh` end to end has not been run on this
  branch (it needs composer network access for the staged
  `composer install`). The gates were exercised directly against a real
  stripped stage instead, positively and negatively.
- The cut still ships `src/Tools/Cli/Dispatch_Cli_Job.php` and
  `Run_Cli_Job.php`, whose docblocks name `wpmcp/dispatch-cli-job`, an
  ability the cut does not register. Not a leak the gates fire on (source
  comments are not shipped documents) but it is dead code in the zip. Out
  of scope here; worth its own issue.
