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
  - `get-mods` (read): every theme_mod value passed through the SHARED
    `Request_Log::redact()` primitive (themes park API keys and license keys
    in theme mods), the masked top-level keys listed in `redacted`, plus
    `writable` (the effective write policy, including keys that have never
    been set) and `writable_present` (the intersection with what is stored).
  - `set-mods` (write): `{ values: { key: value } }`, capped at 50 keys per
    batch. Three guard layers per key:
    1. structural keys (`nav_menu_locations`, `sidebars_widgets`,
       `custom_css_post_id`) hard-refused before the filter is consulted, and
       the refusal names the supported route, marking the ones that need the
       pro tier as such instead of dead-ending a free-tier agent;
    2. the effective allowlist, every key enumerated (no hidden prefix rule),
       so the `writable` get-mods reports is exactly what set-mods accepts
       and the `wpmcp_theme_mod_allowlist` filter both widens AND narrows;
    3. a per-key validator that FAILS CLOSED: a key with no registered rule
       is refused (`no_validator`), a string rule that is neither a built-in
       name nor a callable is refused (`unknown_rule`), and a value that
       fails its validator is refused (`invalid_value`), never coerced.
    Passing `null` as a value is an explicit clear routed through
    `remove_theme_mod()` and reported in `cleared`.
    Default-off behind `wpmcp_enable_theme_write`. Snapshot targets
    `object_type: option`, `object_id: theme_mods_{get_option('stylesheet')}`
    (the UNFILTERED slug core keys the option on, not `get_stylesheet()`) and
    is taken only when at least one key will really be written, so a fully
    refused batch writes nothing, takes no snapshot slot, and honestly
    reports `recoverable: false`.
- Registered from `Plugin::register_integration_abilities()`;
  `tests/support/ability-manifest.php` regenerated (total 304 to 306, free
  213 to 215).
- `tests/free/Integrations/ThemeIntegrationTest.php`: 41 tests covering the
  two TDD anchors from the issue, the sanitizer refusals, allowlist
  narrowing, rule-shape dispatch (enum, Closure, function-name string,
  unknown string, missing rule), the value-rules re-hardening, redaction,
  the no-op-no-snapshot rule, the clear path, the audit-log record for the
  synthetic per-op decision, and the full rollback-operation round trip
  (including a filtered `stylesheet`, and the branch where the theme_mods
  option did not exist before and must be deleted on rollback).

## Fixes applied after review

- Snapshot target derived from `get_option('stylesheet')`, matching how
  `set_theme_mod()`/`get_theme_mods()` key the option. `get_stylesheet()`
  runs the `stylesheet` filter, so on a site that hooks it the old code
  snapshotted a different option than the one it mutated and rollback
  reported success having reverted nothing. The identical latent bug in
  `src/Tools/Menus/Assign_Menu_To_Location.php` is fixed with it.
- Validation and its agent-facing explanation are produced by one method
  (`evaluate()`) instead of two that had already drifted: a Closure rule used
  to reach `(string) $rule` and fatal, and an array rule used to emit an
  "Array to string conversion" warning.
- Guard layer 3 fails closed. The permissive `is_inert_value()` fallback is
  gone: it accepted `javascript:alert(1)` and `" onmouseover=..." ` for
  exactly the filter-added keys guard layer 2 was widened to admit.
- `wpmcp_theme_mod_value_rules` can no longer strip a core validator: the
  core map is merged back on top of whatever the filter returns.
- `image_url` asserts an http(s) scheme after `esc_url_raw()`, which only
  vets protocols and so let `//evil.tld/x.png`, `/x.png` and `#x` through.
- `header_image` writes keep `header_image_data` in step, so
  `get_custom_header()` stops describing the previous image.
- `get-mods` uses `Request_Log::redact()` rather than a local fork of it, and
  `licen` moved into `Request_Log::SECRET_KEY_PARTS` so it is defined once.
- Dropped the `allowlist` response field, which was byte-identical to
  `writable`.

## Filter contracts

- `wpmcp_enable_theme_write` (bool, default false): opts set-mods in.
- `wpmcp_theme_mod_allowlist` (string[]): the effective set of writable mod
  keys. Returning `[]` closes set-mods entirely; structural keys are stripped
  from whatever it returns.
- `wpmcp_theme_mod_value_rules` (array): key => validator. A rule is a
  built-in rule name, a LIST of literal allowed values, a Closure, or a
  function-name string. Array-callables are not a supported shape: every
  array is an enum. Core entries are re-merged on top and cannot be removed.
- `wpmcp_theme_is_block_theme` (bool): overrides `wp_is_block_theme()` when
  deciding which written mods to report in `ineffective`.

## Remaining work

- Coverage check against the 90.3 floor (not measured in this branch).
- Framework detection is only exercised against the test theme; confirm the
  mapped framework slugs against real child themes of each framework.
