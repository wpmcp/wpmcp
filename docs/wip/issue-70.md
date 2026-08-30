# WIP plan: standalone theme-builder subsystem (issue #70)

Demand-gated per the issue: this branch builds the engine skeleton so the
subsystem can be evaluated and finished quickly if demand evidence lands, but
does not commit to the full product-inside-the-product.

## What exists on this branch

- `src/Tools/ThemeBuilder/Template_Store.php`: `wpmcp_template` CPT storage.
  Part type (`header`, `footer`, `404`), include/exclude conditions, and
  priority in postmeta; content as block markup in `post_content`. Free-tier
  cap (one template per part type) enforced at create time via `Pro\Gate`.
- `src/Tools/ThemeBuilder/Condition_Schema.php`: condition-set validation,
  per-rule matching against a normalized context, and specificity scoring.
  v1 rule types: `entire_site`, `archive`, `search`, `error_404`,
  `front_page`, `post_type`, `singular`.
- `src/Tools/ThemeBuilder/Template_Resolver.php`: deterministic winner
  resolution, specificity desc > priority desc > lowest id, returning the
  winner plus the full considered list for the resolve tool's report.
- `src/Tools/ThemeBuilder/Render/`: `Adapter` interface plus `Block_Adapter`
  and `Classic_Adapter` stubs (mechanism documented, register() TODO).
- Abilities wired in `src/Plugin.php` under a new `theme_builder` group:
  `create-template`, `list-templates`, `resolve-template` (domain `theme`,
  `manage_options`, engine tier free). CPT registration hooked on `init` in
  `register_builder_runtime_hooks()`.

## Remaining work

1. TDD per the issue: failing tests for resolver ordering in `tests/free`
   (specificity > priority > id, include/exclude interplay, cap enforcement),
   then adapter tests; pro condition tests in `tests/pro`.
2. Update/delete/set-status tools, snapshot-first via `Safety\Safe_Mutation`.
3. Render adapters: block-theme template-part integration
   (`get_block_templates` / `pre_get_block_file_template`, 404 via
   `template_include`); classic via `get_header`/`get_footer` buffering and
   `do_blocks()`.
4. Granular PRO conditions behind `Pro\Gate::can_use()` (taxonomy term,
   specific post, user role) plus unlimited templates.
5. Vertical-flavor decision: whether `theme_builder` joins any
   `FLAVOR_GROUPS` allowlist or stays full-build only.
