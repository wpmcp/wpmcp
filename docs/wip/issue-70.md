# WIP plan: standalone theme-builder subsystem (issue #70)

Demand-gated per the issue. **No demand evidence has been gathered yet**, and
the issue's first line asks for it before starting. This branch is therefore
the engine and its safety wiring, sized so the subsystem can be evaluated (and
finished quickly) if evidence lands, rather than the full
product-inside-the-product. Rendering is deliberately scoped to the 404 part
for the same reason.

## What exists on this branch

- `src/Tools/ThemeBuilder/Template_Store.php`: `wpmcp_template` CPT storage.
  Part type (`header`, `footer`, `404`), include/exclude conditions, and
  priority in postmeta; content as block markup in `post_content`, run through
  `wp_kses_post()` on the way in (explicitly, because `kses_init_filters()` is
  skipped for the `unfiltered_html` administrator this tool requires). The
  per-part-type cap has one definition, `cap_per_type()`.
- The CPT is on `Content_Guard::INTERNAL_TYPES`, so the generic `edit_posts`
  content tools cannot rewrite markup that renders on every page.
- `src/Tools/ThemeBuilder/Condition_Schema.php`: strict condition-set
  validation (unknown keys, missing values and values on value-less rule types
  are all rejected, because an accepted-but-unmatchable rule produces a
  permanently invisible template), per-rule matching, and specificity scoring.
  Rule types: `entire_site`, `archive`, `search`, `error_404`, `front_page`,
  `post_type`, `singular`. Malformed rules read back from postmeta are skipped
  rather than raising a TypeError on the front end.
- `src/Tools/ThemeBuilder/Template_Resolver.php`: deterministic winner
  resolution, specificity desc > priority desc > lowest id, returning the
  winner plus the full considered list for the resolve tool's report.
- `src/Tools/ThemeBuilder/Render/`: `Template_Renderer` (live-query context,
  winner markup via `do_blocks()`), the `Adapter` interface, the shared
  `Renders_Document` mechanism, and `Block_Adapter` / `Classic_Adapter`.
  `Adapters::boot()` is wired from `register_builder_runtime_hooks()` on `wp`,
  so the adapters are reachable code, not files that only ship.
- Abilities in `src/Plugin.php` under the `theme_builder` group, domain
  `theme`, `manage_options`, tier free: `create-site-part`, `list-site-parts`,
  `resolve-site-part`, `set-site-part-status`, `delete-site-part`. Named
  `site-part` rather than `template` so they do not read as the Elementor
  group's `create-theme-template` / `apply-template`.
- Tests: `tests/free/ThemeBuilder/SitePartEngineTest.php` (conditions,
  resolver ordering, the cap, the tools, the adapters) and
  `tests/pro/ThemeBuilder/SitePartCapTest.php` (uncapped licensed path).

## Tier and flavor wiring

- The engine is free. The only tier line is the per-part-type cap in
  `Template_Store::cap_per_type()`, which the wp.org directory build rewrites
  to a flat filterable `0` (unlimited) in `scripts/flavors/wporg/strip.php`,
  the same shape snapshot retention already uses. That build ships the whole
  engine with no quota and no upsell copy, per guideline 5.
- `theme_builder` is not in `FLAVOR_GROUPS['woocommerce']`, and
  `scripts/build-woo-release.sh` prunes `src/Tools/ThemeBuilder`, so the gate
  and the artifact stay in sync. Asserted in `tests/free/FlavorTest.php`.

## Acceptance criteria status

1. Templates assignable via include/exclude conditions with a deterministic
   winner order (specificity > priority > id): **done**, tested.
2. A resolve tool reports which template wins for a context: **done**,
   `wpmcp/resolve-site-part` returns the winner plus every considered
   template with its match, specificity and priority.
3. Rendering integrates with classic and block themes via adapters:
   **partial**. Both adapters exist, `supports()` picks one, and the 404 part
   renders through a `template_include` document swap in either theme type.
   Header and footer do not render yet (see below).
4. Free cap enforced and tested; all template writes snapshot-first:
   **done for the cap** and for the writes that have prior state.
   `set-site-part-status` and `delete-site-part` go through
   `Safety\Safe_Mutation`. `create-site-part` does not, taking the same
   create-only exemption as every other create tool: there is no prior state
   to snapshot, and its undo is `delete-site-part`, which is snapshot-first.

## Remaining work

1. Header and footer rendering. `get_header()` / `get_footer()` call
   `locate_template()` with nothing filterable in between, so a classic theme
   cannot have them swapped from a hook; the next slice is a full document
   composition (the route Elementor Pro takes) plus the native block-theme
   integration through `get_block_templates` /
   `pre_get_block_file_template`. Until then the adapters deliberately
   register nothing for those two part types rather than shipping an
   output-buffer guess.
2. An update tool for content, conditions and priority, snapshot-first.
   Note the condition reader already tolerates malformed stored sets, which
   is the state an update tool could otherwise create.
3. Granular PRO conditions (taxonomy term, specific post, user role) behind
   `Pro\Gate::can_use()`.
4. Demand evidence. Nothing above justifies finishing the subsystem on its
   own; the issue's gate still applies to slices 1 to 3.
