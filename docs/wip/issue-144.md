# Issue #144: theme-read/theme-write dispatcher (phase 1 of #69)

Status: WIP. Core implementation and first tests are in place; remaining work
listed at the bottom.

## Scope

Phase 1 only: theme context reads plus reversible, allowlist-gated theme-mod
writes. No file I/O. create-child-theme (phase 2) and framework packs
(phases 3-4) follow in separate issues and hook the filter seam added here.

## What is implemented

- `src/Integrations/Theme_Integration.php` extending `Integration_Dispatcher`
  - slug `theme`, `is_available()` always true, `capability()` =
    `edit_theme_options` for both halves.
  - `get-theme-context` (read): stylesheet/template, parent info, `is_child`,
    detected framework (known-slug map: astra, kadence, generatepress,
    oceanwp, blocksy, neve, hello-elementor; `twenty*` maps to `core`),
    `is_block_theme`, probed `theme_supports`, registered menu locations,
    theme name/version, `child_theme_exists`.
  - `get-mods` (read): all theme_mod values plus a `writable` annotation and
    the effective allowlist.
  - `set-mods` (write): `{ values: { key: value } }`. Allowlist = core
    presentation mods (`custom_logo`, `header_textcolor`, `header_image`,
    `background_*` by prefix) extended via the `wpmcp_theme_mod_allowlist`
    filter. Structural keys (`nav_menu_locations`, `sidebars_widgets`,
    `custom_css_post_id`) are hard-refused before the filter is consulted, so
    no filter can open them. Default-off behind `wpmcp_enable_theme_write`.
    Snapshot targets `object_type: option`,
    `object_id: theme_mods_{stylesheet}`, so `rollback-operation` restores
    the prior state. Response reports `updated` and `refused` (per-key
    reason: `structural` or `not_allowlisted`).
- Registered from `Plugin::register_integration_abilities()`.
- `tests/free/Integrations/IntegrationAbilitiesRegistrationTest.php` extended
  with `wpmcp/theme-read` and `wpmcp/theme-write`.
- `tests/free/Integrations/ThemeIntegrationTest.php` with the two TDD anchor
  tests from the issue (structural-key refusal even when filter-allowlisted;
  non-allowlisted-key refusal with structured report) plus default-off,
  context shape, writable annotation, and snapshot/recoverable coverage.

## Remaining work

- Run the targeted phpunit suite against a WP test install and make it green
  (not run yet in this branch).
- Add the rollback round-trip assertion in
  `test_set_mods_write_is_snapshotted_and_recoverable` (restore prior
  theme_mods state via rollback-operation, mirroring AcfIntegrationTest).
- Confirm framework detection against a child theme of each mapped framework
  (detection uses the template slug, so children inherit detection).
- Coverage check against the 90.3 floor.
