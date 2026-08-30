# WIP plan: next-generation (atomic/v4) builder elements (#62)

## Already in place before this branch

- `wpmcp/detect-elementor-version`: reports core/pro version, `supports_atomic`,
  and a recommended mode (read-only, always registered).
- Atomic containers (`add-flexbox`, `add-div-block`) and universal atomic
  widget tools (`add-atomic-widget`, `update-atomic-widget`) with typed
  `$$type` props, friendly-param mapping (`Atomic_Widget_Map`,
  `Atomic_Props::map`), snapshot-first writes via `Safe_Mutation`
  (`Atomic_Element::write`), and pro-tier registration.

## Added on this branch

- Conditional registration: the four atomic write tools register only when
  `Atomic_Element::registration_supported()` is true (Elementor >= 4.0 with the
  atomic-widgets module). The predicate is filterable
  (`wpmcp_elementor_atomic_supported`) so tests and site code can force either
  side, and the whole block lives in
  `Plugin::register_atomic_elementor_abilities()` so the registration itself,
  not just the predicate, is testable against a fresh `Registrar`.
- Every atomic write path (`Add_Atomic_Widget`, `Update_Atomic_Widget`,
  `Atomic_Layout`) re-checks `Atomic_Element::require_supported()`, so a
  forced-open gate on a legacy builder returns a `WP_Error` instead of writing
  elements the installed Elementor cannot render.
- `detect-elementor-version` now also reports `atomic_tools_registered`, the
  predicate that actually gated registration, so the discoverability path
  cannot disagree with the tool list when the filter is in use.
- Shared style-class builder `WPMCP\Tools\Elementor\Atomic_Styles`: a flat
  `style` object becomes one local style class in the v4 `styles` blob shape,
  attached to the element through the `classes` settings ref. Wired into
  `add-atomic-widget`, `update-atomic-widget`, `add-flexbox` and
  `add-div-block`.
  - Props are built by `Global_Class_Schema`, the same authoring layer
    `create-global-class` uses: one key set, colors through
    `sanitize_hex_color()`, lengths through `Atomic_Props::build_size()`
    (number, `"1.5rem"`, or `{"size":18,"unit":"rem"}`), and an unknown key or
    unusable value is a `WP_Error` rather than a warning.
  - The finished class is validated by Elementor's own `Style_Parser`
    (`Global_Class_Schema::validate_item()`) before it is attached, so a prop
    the style schema would silently drop fails the call.
  - `attach()` tolerates a non-list `classes` value (previously a fatal
    `array_merge()` TypeError) and replaces the class it generated earlier for
    the same element, so repeated style updates leave exactly one class behind.
- `Element_Tree::normalize()` now carries a non-empty `styles` blob, so the
  post-write verification covers the style class rather than only the settings.
- The ability descriptions enumerate the accepted style keys programmatically
  (`Atomic_Styles::style_keys()`), and `style` is declared in the input schema
  of all four tools.
- Tests: `tests/pro/Elementor/AtomicConditionalRegistrationTest.php` (registrar
  -level absence/presence of the four tools under a forced gate, the gate
  predicate, the write-path guard, and the style builder's success and failure
  paths) and style round-trip cases in
  `tests/pro/Elementor/AtomicElementsTest.php`.
- `AbilityManifestTest` skips with an explanatory message when the atomic tools
  do not register, instead of reporting the conditional surface as drift.

## Remaining work

- Grow style coverage beyond the `Global_Class_Schema` key set: box-shadow,
  typography family, and responsive variants per breakpoint (one variant,
  desktop, is generated today).
- Verify generated structures open cleanly in a real v4 editor session (manual
  QA pass against Elementor 4.x with the Editor-V4 experiment on). The
  automated substitute is Elementor's own `Style_Parser` validating every
  generated class before it is written.
