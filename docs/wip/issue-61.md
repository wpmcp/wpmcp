# Issue #61: builder template, theme-part, popup, and dynamic-tag tools

Status: WIP. Most of the surface from the issue already exists in
`src/Tools/Elementor/` and is registered in `src/Plugin.php`
(save-as-template, apply-template, import-template, create-theme-template,
set-template-conditions, get/list/delete-theme-template, create-popup,
set-popup-settings, list-dynamic-tags, set-dynamic-tag). This branch closes
the remaining acceptance-criteria gaps.

## Done in this slice

- `export-template` (`src/Tools/Elementor/Export_Template.php`): exports a
  saved `elementor_library` template to the same portable envelope
  `export-page` produces and `import-template` accepts, including display
  conditions. Registered as `wpmcp/export-template` (read).
- `import-template` now regenerates every element id on import (via
  `Elementor_Template_Data::regenerate_ids`), satisfying the "round-trips as
  JSON with id regeneration" criterion.
- `resolve-theme-template` (`src/Tools/Elementor/Resolve_Theme_Template.php`):
  resolver-style read reporting which theme part wins for a location, listing
  every candidate with conditions and a specificity score. Registered as
  `wpmcp/resolve-theme-template` (read).
- `tests/pro/Elementor/TemplateRoundTripTest.php`: template JSON round-trip
  with id regeneration (structure preserved, ids fresh, unique, 7-char hex),
  plus a non-template rejection case.

## Remaining work

- Resolver: delegate to Elementor Pro's ThemeBuilder conditions manager when
  loaded; honor context args (post_type, post_id, term) in the meta-based
  fallback scoring (TODO markers in `Resolve_Theme_Template.php`).
- Conditional registration on builder tier: theme-part, popup, and dynamic-tag
  abilities should register only when the required premium builder tier is
  active (today they gate through `Pro\Gate` like the rest of the pro tier).
- Verify "applying a template reproduces the structure" end to end against a
  live Elementor install (apply-template exists and regenerates ids; needs an
  integration pass with the library UI).
- Popup trigger coverage: `set-popup-settings` handles settings writes; audit
  trigger/timing keys against current Elementor Pro popup schema.
