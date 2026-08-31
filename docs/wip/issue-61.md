# Issue #61: builder template, theme-part, popup, and dynamic-tag tools

Status: WIP. Most of the surface from the issue already exists in
`src/Tools/Elementor/` and is registered in `src/Plugin.php`
(save-as-template, apply-template, import-template, create-theme-template,
set-template-conditions, get/list/delete-theme-template, create-popup,
set-popup-settings, list-dynamic-tags, set-dynamic-tag). This branch closes
the remaining acceptance-criteria gaps.

## Done in this slice

- `export-template` (`src/Tools/Elementor/Export_Template.php`): exports a saved
  `elementor_library` template to the same portable envelope `export-page`
  produces and `import-template` accepts, page settings and display conditions
  included. Registered as `wpmcp/export-template` (read). A template that is not
  published needs a per-object `read_post` check before its tree is dumped, the
  same guard `Search_Content` applies.
- `import-template` now:
  - validates the element tree before touching it
    (`Elementor_Template_Data::is_element_list`), so malformed untrusted JSON
    fails as a `WP_Error` (`invalid_content`) instead of a `TypeError` fatal;
  - regenerates every element id, satisfying the "round-trips as JSON with id
    regeneration" criterion;
  - re-applies the envelope's `page_settings` (where popup triggers live) and
    routes its `conditions` through `Template_Conditions::save()`, so a
    round-tripped theme part keeps its display rules.
- `resolve-theme-template` (`src/Tools/Elementor/Resolve_Theme_Template.php`):
  resolver-style read reporting which theme part wins for a location and an
  optional context. It now:
  - expands a location to the template types that serve it (`single` covers
    `single`, `single-post`, `single-page`; `archive` covers `archive`,
    `search-results`, `error-404`), so real sites are not reported as having
    zero candidates;
  - matches `include`/`exclude` conditions against `post_type` / `post_id`
    instead of ignoring the context, and only lets an exclude disqualify a
    candidate when the context actually confirms it, so the standard
    `include/general` + `exclude/singular/page` pair still wins off pages;
  - bounds the query at 200 candidates and reports `total` / `truncated`,
    matching the windowing convention the other Elementor read tools use.
- Tests: `tests/pro/Elementor/TemplateRoundTripTest.php` (round trip with id
  regeneration, conditions and page settings surviving, an envelope carrying
  neither, an explicit `template_type` overriding the envelope's, unreadable
  draft refusal) and `tests/pro/Elementor/ResolveThemeTemplateTest.php` (16
  cases covering conditionless candidates, specificity ordering and its exact
  scale, exact-id includes, exclude behavior with and without context and the
  `excluded_by` report, post_type derived from post_id alone, part-array
  conditions, location-to-type expansion, draft candidates, truncation
  reporting). `TemplatesTest` gained three malformed envelope cases and its id
  assertion now reflects regeneration.
- Specificity is the number of condition parts after the verb
  (`include/general` = 1, `include/singular/post` = 2,
  `include/singular/post/12` = 3), which is what the class docblock always
  claimed; the scoring was one higher than that and is now pinned by a test.
- `tests/support/ability-manifest.php` regenerated (304/91 -> 306/93) and the
  Elementor tool ceiling in `ToolsListBudgetTest` raised 60 -> 62 deliberately
  for the two new per-feature read tools.

## Remaining work

- Conditional registration on the builder tier: theme-part, popup and
  dynamic-tag abilities register through `Pro\Gate` like the rest of the pro
  tier rather than on Elementor Pro being active. The tools are deliberately
  usable on free Elementor (`Template_Conditions` writes `_elementor_conditions`
  directly when Pro is absent), so tightening this is a design decision for
  review, and it would make the ability manifest depend on which plugins the
  test environment has installed.
- Resolver: delegate to Elementor Pro's ThemeBuilder conditions manager when it
  is loaded, keeping the meta scoring as the free-Elementor fallback.
  `Template_Conditions` already has the Pro accessor for the write side.
  Deliberately not done in this slice: Pro's `get_documents_for_location()`
  resolves against the *global* query, so delegating without first standing up
  a query for the requested `post_id` would answer for whatever request the
  MCP call happens to run inside. Doing that safely is its own slice, and it
  cannot be covered here (the test environment has free Elementor only).
- Verify "applying a template reproduces the structure" end to end against a
  live Elementor install (apply-template exists, is snapshotted and regenerates
  ids, and is covered by TemplatesTest; what is missing is the library-UI pass).
- Popup trigger coverage: `set-popup-settings` handles settings writes and the
  round trip now carries `_elementor_page_settings`; audit trigger/timing keys
  against the current Elementor Pro popup schema.

## Acceptance criteria status

- Save as template / apply reproduces structure: covered by tests, pending the
  live-install pass. Partial.
- Export/import round-trips as JSON with id regeneration: met.
- Theme part with conditions plus a resolver read for a context: met for the
  meta-based resolver; Pro conditions-manager delegation still outstanding.
- Writes reversible via rollback: met (every mutation goes through
  `Safe_Mutation`; creates are deliberately not snapshotted). Conditional
  registration on the builder tier: unmet, see above.
