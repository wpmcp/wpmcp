# Issue #144: theme-read/theme-write dispatcher (phase 1 of #69)

Branch-local status file. It is not shipped documentation: delete it in the
merge commit. The durable parts (the filter contracts and the reasoning
behind the three guard layers) now live in the `Theme_Integration` class
docblock, which is where phase 2/3 authors will actually read them.

## Scope

Phase 1 only: theme context reads plus reversible, allowlist-gated theme-mod
writes. No file I/O. create-child-theme (phase 2) and framework packs
(phases 3-4) follow in separate issues and hook the filter seams added here.

## What is implemented

- `src/Integrations/Theme_Integration.php` extending `Integration_Dispatcher`
  - slug `theme`, `is_available()` always true, `capability()` =
    `edit_theme_options` for both halves.
  - `get-theme-context` (read): stylesheet/template, parent info, `is_child`
    (derived from stylesheet !== template, so a child whose parent theme is
    missing is reported as a child with `parent_missing: true` rather than as
    a standalone parent), detected framework (known-slug map: astra, kadence,
    generatepress, oceanwp, blocksy, neve, hello-elementor; `twenty*` maps to
    `core`), `is_block_theme`, probed `theme_supports`, registered menu
    locations, theme name/version, `child_theme_exists`.
  - `get-mods` (read): every theme_mod value with secret-looking keys masked
    the way `Request_Log::redact()` masks captured payloads (themes park API
    keys and license keys in theme mods), plus `writable` / `allowlist` (the
    effective write policy, including keys that have never been set) and
    `writable_present` (the intersection with what is actually stored).
  - `set-mods` (write): `{ values: { key: value } }`, capped at 50 keys per
    batch. Three guard layers per key:
    1. structural keys (`nav_menu_locations`, `sidebars_widgets`,
       `custom_css_post_id`) hard-refused before the filter is consulted, and
       the refusal names the supported tool for each one;
    2. the effective allowlist, every key enumerated (no hidden prefix rule),
       so the `allowlist` get-mods reports is exactly what set-mods accepts
       and the `wpmcp_theme_mod_allowlist` filter both widens AND narrows;
    3. a per-key validator mirroring how core registers these settings on the
       Customizer. A value that fails is refused with reason `invalid_value`,
       never coerced.
    Default-off behind `wpmcp_enable_theme_write`. Snapshot targets
    `object_type: option`, `object_id: theme_mods_{stylesheet}` and is taken
    only when at least one key will really be written, so a fully refused
    batch writes nothing, takes no snapshot slot, and honestly reports
    `recoverable: false`. Response reports `updated`, `refused` (per-key
    reason: `structural`, `not_allowlisted`, `invalid_value`), and
    `ineffective` (mods a block theme stores but never renders).
- Registered from `Plugin::register_integration_abilities()`;
  `tests/support/ability-manifest.php` regenerated (total 304 to 306, free
  213 to 215).
- `tests/free/Integrations/ThemeIntegrationTest.php`: 24 tests covering the
  two TDD anchors from the issue, the sanitizer refusals, allowlist
  narrowing, redaction, the no-op-no-snapshot rule, and the full
  rollback-operation round trip (including the branch where the theme_mods
  option did not exist before and must be deleted on rollback).

## Filter contracts

- `wpmcp_enable_theme_write` (bool, default false): opts set-mods in.
- `wpmcp_theme_mod_allowlist` (string[]): the effective set of writable mod
  keys. Returning `[]` closes set-mods entirely; structural keys are stripped
  from whatever it returns.
- `wpmcp_theme_mod_value_rules` (array): key => validator for filter-added
  keys. A rule is a built-in rule name, a list of literal allowed values, or
  a callable returning null to refuse.

## Remaining work

- Coverage check against the 90.3 floor (not measured in this branch).
- Framework detection is only exercised against the test theme; confirm the
  mapped framework slugs against real child themes of each framework.
