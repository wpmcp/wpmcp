# Issue #72: Spec-compiled custom widget builder

Demand-gated PRO feature. The agent submits a structured spec; the plugin,
never the AI, compiles it into a real builder widget class. Code lives in
`src/Tools/WidgetBuilder/Compiler/`, tests in
`tests/pro/WidgetBuilder/CompiledWidgetBuilderTest.php`.

## Relationship to the existing widget builder

`src/Tools/WidgetBuilder/` ships a data-driven builder: specs stored as
`wpmcp_widget` posts, rendered at runtime by a single `Dynamic_Widget`. Issue
#72 layers a compilation path on top of the same spec store: the CPT stays the
single source of truth, and `compile-custom-widget` turns a published spec into
a standalone PHP class. A spec with an enabled compiled class registers from
that class; every other spec keeps registering as a `Dynamic_Widget`, so the
two forms never both claim one widget name.

## Architecture

- `Widget_Spec::CONTROL_TYPES` now carries an `escaper` key and is the SINGLE
  source of truth for output escaping. `Widget_Renderer` (runtime) and
  `Widget_Compiler` (emitted PHP) both read it, so a spec escapes identically
  whichever way it renders. Every declared control type is compilable;
  `list-control-types` reports the escaper and `compilable` explicitly.
- `Widget_Compiler` is the SOLE PHP emitter. Two properties make it safe by
  construction: every spec-supplied literal is emitted through `var_export()`
  (spec text is always a string literal, never syntax), and every placeholder
  is wrapped in its control type's declared escaper (a placeholder with no
  matching control emits nothing at all). The class name and the file name both
  carry the spec post id, because nothing makes a machine name unique and PHP
  class names are case-insensitive.
- `Generated_Code_Lint` runs before a byte reaches disk. The call surface is an
  ALLOWLIST (`ALLOWED_CALLS`), not a denylist: the emitter is the only producer,
  so the set of functions generated code may call is closed and tiny, and the
  lint fails closed on everything else. That is what catches the whole
  string-callable dispatch family (`array_map('system', ...)`,
  `add_action('init', 'system')`) that a name denylist structurally cannot see.
  On top of the allowlist it rejects `eval`, backticks, include/require,
  `__halt_compiler`, close tags / inline HTML, variable functions, variable
  variables, dynamic method / static / class references, and complex string
  interpolation, and it matches fully qualified and qualified names (PHP 8
  tokenizes `\exec` as one `T_NAME_FULLY_QUALIFIED`). It catches `\Throwable`,
  not just `\ParseError`, because `TOKEN_PARSE` also raises `CompileError`.
- `Compiled_Widget_Manifest` is the integrity record. It lives in the
  `wpmcp_compiled_widgets` OPTION, not in a file beside the code it vouches for:
  hashes stored next to the files they hash prove nothing. The sandbox is
  `wp-content/wpmcp-widgets`, NOT uploads, with the repo's standard deny
  hardening (`.htaccess` + `index.php` + a README naming the nginx caveat).
  Loading is manifest-only and hash-verified, entry shapes are validated on
  read, and a `file` that is not a plain basename resolving inside the sandbox
  is dropped.
- `Compile_Custom_Widget` is the only tool in the plugin that writes executable
  PHP, so it is gated like one: PRO via `Pro\Gate`, OFF by default behind the
  `wpmcp_enable_widget_compiler` filter (the shape `wpmcp_enable_fs_writes` uses
  for the filesystem tools), and `edit_files` + `DISALLOW_FILE_EDIT` via
  `Filesystem_Guard`. It refuses a draft or trashed spec, writes via temp file
  plus rename, and records the manifest entry through `Safe_Mutation` so the
  compile is an operation in history with the previous manifest restorable.
  Failures return `WP_Error`, like every sibling in the group.

## Acceptance criteria

- [x] validate-spec rejects malformed/hostile specs without side effects
      (`Widget_Spec::validate()` plus the hostile-spec corpus, which also proves
      hostile spec text compiles to inert literals).
- [x] Generated PHP always passes a token-parse lint pre-write; every
      interpolated value is escaped per declared control type.
- [x] Widgets load only via the manifest from an isolated directory, hash
      verified; disabling removes the widget from the builder without deleting
      the spec or the file (`set-widget-status`, `delete-custom-widget`).
- [~] History: the compile is an operation (`Safe_Mutation` snapshots the
      manifest option) and delete disables the class while staying restorable
      from the spec via the trash. `create-custom-widget` and
      `update-custom-widget` still write the spec post directly, so those two
      are not yet operations in history; that is the one criterion still
      partially open.

## Remaining work

1. Template conditionals and loops. Today's template language is `{{name}}`
   interpolation only, identical to `Widget_Renderer`'s. Anything richer needs
   its own hostile corpus before it is emitted.
2. Elementor style/advanced control sections (the compiled class registers one
   content section, same as the dynamic widget).
3. A `recompile-all` path for when a spec changes: `update-custom-widget`
   currently leaves the compiled class stale until the widget is compiled again.
4. Route `create-custom-widget` and `update-custom-widget` through
   `Safe_Mutation` so spec create/update are operations in history too.
5. Adversarial security review before release, per the issue.

## Compliance stance (wp.org build)

The compiler adds a THIRD code-execution path to the plugin, after
`Php_Snippet_Runner`'s `eval` (COMPLIANCE.md B-16) and `Wp_Cli_Executor`'s
`proc_open` (B-17): `Compiled_Widget_Manifest::load_enabled()` requires
plugin-generated PHP from a protected `wp-content` sandbox. It contains none of
the constructs `WPORG-09-EXEC` scans for, so the rule does not fire, but the
stance is deliberate and recorded as B-28: the compiler is PRO-only and
default-off, and it never reaches the directory zip, because
`scripts/flavors/wporg/strip.php:60` removes `src/Tools/WidgetBuilder` whole and
the compiler lives inside it. That is a property of the strip list's paths, so
moving the compiler out of `WidgetBuilder/` would silently start shipping it.

## Non-goals

- The AI never authors PHP; no eval, no dynamic includes outside the
  hash-verified manifest loader.
- No compilation on the free tier, and none at all unless the site opts in.
