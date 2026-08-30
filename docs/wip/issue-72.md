# Issue #72: Spec-compiled custom widget builder (WIP plan)

Demand-gated PRO feature. The agent submits a structured spec; the plugin,
never the AI, compiles it into a real builder widget class. This document
tracks the implementation plan; the skeleton lives in
`src/Tools/WidgetBuilder/Compiler/`.

## Relationship to the existing widget builder

`src/Tools/WidgetBuilder/` already ships a data-driven builder: specs stored
as `wpmcp_widget` posts, rendered at runtime by a single `Dynamic_Widget`
(no code generation). Issue #72 layers a compilation path on top of the same
spec store: the CPT stays the single source of truth, and `compile-custom-widget`
turns a stored spec into a standalone PHP class.

## Architecture (as landed so far)

- `Widget_Compiler` is the SOLE PHP emitter. Escaping is determined by the
  declared control type via a closed `ESCAPERS` map; a control type without
  an escaper cannot compile. `compile()` currently validates and returns
  `wpmcp_compiler_incomplete`; no emission yet.
- `Generated_Code_Lint` (implemented): token-parse lint that every emitted
  source must pass before anything touches disk. Rejects parse failures,
  eval, backticks, include/require, close tags / inline HTML, complex string
  interpolation, and an exec/filesystem/network/obfuscation function list.
- `Compiled_Widget_Manifest` (implemented read/write/enable): compiled files
  live in `uploads/wpmcp-widgets/` and load ONLY via `manifest.json`; a file
  without a manifest entry is inert. `load_enabled()` is intentionally inert
  until emission exists.
- `Compile_Custom_Widget` tool handler: PRO via `Pro\Gate::can_use()`,
  registered in the `widget_builder` group in `src/Plugin.php`.

## Remaining work (ordered, TDD)

1. Failing tests first in `tests/pro`: hostile-spec corpus for
   `validate-widget-spec` extensions (conditionals/loops), and
   generated-code safety proofs (every interpolated value escaped per
   declared control type; lint rejects seeded-hostile emissions).
2. Template compiler: `{{name}}` placeholders plus minimal conditionals and
   loops, compiled to PHP with the per-type escaper applied at every
   interpolation site.
3. Emission in `Widget_Compiler::compile()`: meta, typed control sections,
   `render()`; class name from `class_name_for()`.
4. Sandbox write path in `Compile_Custom_Widget`: lint, then write, then
   manifest entry (file, hash, spec_id, enabled).
5. Loader: `Compiled_Widget_Manifest::load_enabled()` with hash verification
   before require; hook into Elementor widget registration.
6. History integration: create/update/delete as operations, generated-file
   changes tracked via `Safety`, delete restorable from the spec.
7. Adversarial security review before any release (exec-adjacent feature).

## Non-goals

- The AI never authors PHP; no eval, no dynamic includes outside the
  manifest loader.
- No compilation on the free tier.
