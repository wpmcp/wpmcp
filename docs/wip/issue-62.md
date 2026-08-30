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

- Conditional registration: the four atomic write tools now register only when
  `Atomic_Element::registration_supported()` is true (Elementor >= 4.0 with the
  atomic-widgets module). The predicate is filterable
  (`wpmcp_elementor_atomic_supported`) so tests and site code can force either
  side. `detect-elementor-version` stays registered so a caller can learn why
  the tools are absent.
- Shared style-class builder `WPMCP\Tools\Elementor\Atomic_Styles`: flat
  params (color, background_color, font_size, width, height, gap, padding,
  margin, text_align, font_weight, flex props, ...) become one local style
  class in the v4 `styles` blob shape, attached to the element through the
  `classes` settings ref. Wired into `add-atomic-widget` via a new `style`
  object param.
- Tests in `tests/pro/Elementor/AtomicConditionalRegistrationTest.php` for the
  version gate and the style builder.

## Remaining work

- Wire the `style` param into `update-atomic-widget` (merge into the existing
  local class rather than always minting a new one) and into the atomic
  container tools.
- Registrar-level test that the ability names are truly absent from the tool
  list when the gate is closed (the current test covers the predicate).
- Grow style coverage: border, box-shadow, typography family/line-height, and
  responsive variants per breakpoint.
- Verify generated structures open cleanly in a real v4 editor session
  (manual QA pass against Elementor 4.x with the Editor-V4 experiment on).
