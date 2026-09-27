# WIP plan: standalone theme-builder subsystem (issue #70)

Demand-gated per the issue. **No demand evidence has been gathered yet**, and
the issue's first line asks for it before starting. The branch is finished
to the issue's scoped v1 (header, footer and 404 parts); nothing past that
scope is built.

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
  `post_type`, `singular`, plus the licensed `term` and `user_role`. Malformed rules read back from postmeta are skipped
  rather than raising a TypeError on the front end.
- `src/Tools/ThemeBuilder/Template_Resolver.php`: deterministic winner
  resolution, specificity desc > priority desc > lowest id, returning the
  winner plus the full considered list for the resolve tool's report.
- `src/Tools/ThemeBuilder/Render/`: `Template_Renderer` (live-query context,
  winner markup via `do_blocks()`), the `Adapter` interface, and
  `Block_Adapter` / `Classic_Adapter`.
  `Adapters::boot()` is wired from `register_builder_runtime_hooks()` on `wp`,
  so the adapters are reachable code, not files that only ship.
- Abilities in `src/Plugin.php` under the `theme_builder` group, domain
  `theme`, `manage_options`, tier free: `create-site-part`, `list-site-parts`,
  `resolve-site-part`, `update-site-part`, `set-site-part-status`,
  `delete-site-part`. Named
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
   winner order (specificity > priority > id): **done**, tested in
   `SitePartEngineTest` and, for the granular rule types, in
   `tests/pro/ThemeBuilder/SitePartGranularConditionsTest.php`.
2. A resolve tool reports which template wins for a context: **done**,
   `wpmcp/resolve-site-part` returns the winner plus every considered
   template with its match, specificity and priority. Given only `post_id`
   it derives `post_type` and `term_ids` the way the live renderer does.
3. Rendering integrates with classic and block themes via adapters: **done**,
   tested in `SitePartRenderTest`.
   - Block themes: header and footer short-circuit the `core/template-part`
     blocks in those areas on `pre_render_block`, keeping the wrapper element
     and `wp-block-template-part` class; the area is read from the block, the
     theme's part file, or the slug. A part that embeds its own area renders
     once (recursion guard). The 404 part rewrites
     `$_wp_current_template_content` on `template_include` so the page keeps
     the block canvas framed by the header and footer parts.
   - Classic themes: header and footer print from the `get_header` /
     `get_footer` actions (document head plus `wp_head()` for the header,
     `wp_footer()` plus the closing tags for the footer), then require the
     theme's own file into a discarded buffer with those hooks emptied, so
     `get_header()`'s `require_once` is a no-op. This is the mechanism the
     established theme builders use; the trade-off is that wrapper markup the
     theme's header.php opens is dropped with it. The 404 part swaps the
     document on `template_include`, and its `get_header()` / `get_footer()`
     come back through the hooks above.
4. Free cap enforced and tested; all template writes snapshot-first:
   **done**. `update-site-part`, `set-site-part-status` and
   `delete-site-part` go through `Safety\Safe_Mutation`; the snapshot covers
   `post_content` and every `_wpmcp_template_*` meta row, and rollback is
   tested. `create-site-part` takes the create-only exemption every other
   create tool takes: no prior state, and its undo is the snapshot-first
   delete.

## Tier split

- Free: the engine, every location rule type, one template per part type.
- Licensed (`Pro\Gate`): unlimited templates (`Template_Store::cap_per_type()`)
  and the granular `term` and `user_role` rule types
  (`Condition_Schema::granular_rules_allowed()`). Both are checked on write
  only, so a stored template keeps rendering if a licence lapses.
- wp.org directory build: both methods are rewritten by
  `scripts/flavors/wporg/strip.php` to "unlimited" and "allowed", so that
  build has no quota and no locked rule types.

## Relationship to the Elementor theme-template tools (#61, PR #251)

`create-theme-template` / `resolve-theme-template` work on Elementor library
documents and need Elementor. The site-part tools need no page builder and
store their own `wpmcp_template` CPT. The names are distinct on purpose and
each surface's descriptions point away from the other; nothing is shared
because the storage, condition grammar and renderer differ.

## Known limits

- Pages served from a full-page cache see the `user_role` rule resolved for
  whoever warmed the cache, like any per-user output.
- Demand evidence is still the issue's gate for anything past this scope.
