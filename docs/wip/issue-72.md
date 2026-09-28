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
  whichever way it renders. `list-control-types` reports the escaper and
  derives `compilable` from that same table rather than hardcoding it.

  Note this changed shipped behavior for three already-stored control types:
  `icon`, `color` and `switcher` now escape with `esc_attr`, where
  `Widget_Renderer` previously fell through to `esc_html`. That is a safe
  direction (attribute context), but it is a behavior change, not a pure
  refactor.
- `Widget_Compiler` is the SOLE PHP emitter. Two properties make it safe by
  construction: every spec-supplied literal is emitted through `var_export()`
  (spec text is always a string literal, never syntax), and every placeholder
  is wrapped in its control type's declared escaper (a placeholder with no
  matching control emits nothing at all). A missing setting falls back to the
  control's declared default, exactly as `Widget_Renderer` does, so the two
  render paths cannot diverge on a widget nothing has saved yet. The class name
  and the file name both carry the spec post id, because nothing makes a
  machine name unique and PHP class names are case-insensitive.
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

  Calls that are not free-function calls are allowlisted by RECEIVER as well as
  by name: `->` / `?->` only on a literal `$this`, `::` only on
  `self`/`static`/`parent`, the method named must be in `ALLOWED_METHODS`, and
  `new` is rejected outright (the emitter instantiates nothing). Checking only
  the operator's shape and calling the rest "not a call" is what previously let
  `Evil::system('id')`, `$o->system('id')` and `new Evil('id')` through, which
  is the same dispatch family the allowlist exists to close. `ALLOWED_CALLS` is
  trimmed to exactly what the emitter produces, and a test asserts every name
  in it appears in the output of compiling the all-control-types spec, so the
  list cannot drift wider than the emitter.
- `Compiled_Widget_Manifest` is the integrity record. It lives in the
  `wpmcp_compiled_widgets` OPTION, not in a file beside the code it vouches for:
  hashes stored next to the files they hash prove nothing. The sandbox is
  `wp-content/wpmcp-widgets`, NOT uploads, with the repo's standard deny
  hardening (`.htaccess` + `index.php` + a README naming the nginx caveat).
  Loading is manifest-only and hash-verified, entry shapes are validated on
  read, and a `file` that is not a plain basename resolving inside the sandbox
  is dropped. A stored `class` must be the emitter's own
  `WPMCP_Compiled_Widget_<spec_id>_...` shape carrying THIS entry's spec id, and
  after the require the class must actually have been declared by that file
  (checked by reflection), so a tampered option cannot bind a spec id to some
  class the site already declared. `sandbox_dir()` confines the
  `wpmcp_compiled_widgets_dir` filter to `WP_CONTENT_DIR`, and `protect()`
  returns false when the `.htaccess` / `index.php` hardening cannot be written,
  so a compile never proceeds into an unhardened directory.

### The execution gate

The gate that matters is on the READ path, not only the write. `PRO` +
`wpmcp_enable_widget_compiler` are checked in
`Compiled_Widget_Manifest::execution_allowed()`, which `load_enabled()` consults
before any `require`. Turning the opt-in back off, or letting a PRO licence
lapse, makes every already-compiled file inert immediately; it does not merely
stop new compiles. Gating only the write would have meant a widget compiled
while the feature was on kept executing on every Elementor editor load and
front-end render forever after, which would have made B-28's "PRO-only and
default-off" false for the execution site it describes.

The filesystem gates (`edit_files` / `DISALLOW_FILE_EDIT`) deliberately do NOT
apply to loading: they are write permissions, and requiring a file that is
already on disk is not a write. `set-widget-status` does refuse to re-ENABLE a
compiled entry on a site that has since turned the compiler opt-in off, so an
agent cannot undo an operator's deliberate shutdown of the feature; disabling is
never gated.
- `Compile_Custom_Widget` is the only tool in the plugin that writes executable
  PHP, so it is gated like one: PRO via `Pro\Gate`, OFF by default behind the
  `wpmcp_enable_widget_compiler` filter (the shape `wpmcp_enable_fs_writes` uses
  for the filesystem tools), and `edit_files` + `DISALLOW_FILE_EDIT` via
  `Filesystem_Guard`. It refuses a draft or trashed spec, writes via temp file
  plus rename, and records the manifest entry through `Safe_Mutation` so the
  compile is an operation in history. The snapshot carries the previous manifest
  ENTRY and the previous file BYTES (`extra_snapshot_data`), and the rollback
  restores them together and per widget, so undoing a recompile restores the
  previous widget rather than pointing the old hash at the new bytes, and
  undoing compile A does not revert compile B. The same capture is what the
  failure path restores from: a failed manifest write no longer unlinks a file
  the surviving manifest entry still vouches for. Failures return `WP_Error`,
  like every sibling in the group.

### Keeping the spec store the source of truth

A compiled class wins over the spec at registration time, so a stale compiled
class is what actually renders. Three things keep that visible and undoable:
`update-custom-widget` disables the compiled entry (the updated spec is what
renders; recompiling re-enables it), `get-custom-widget` reports
`compiled`/`stale`/`loading` and `list-custom-widgets` reports compiled state
per row, and permanently deleting a spec purges the manifest entry and unlinks
the generated file (`Widget_Registry::purge_on_delete()` on `before_delete_post`)
so generated PHP does not accumulate. Trashing does none of that on purpose:
the trash is reversible, so `restore-post` alone is a complete undo.

### Validation and render parity (after the merge with main)

- `Widget_Spec::validate()` type-checks every field BEFORE casting it (an
  array title, label, name, type, default, keyword or template used to become
  the literal "Array" plus a warning), bounds every field (`MAX_CONTROLS`,
  `MAX_KEYWORDS`, `MAX_NAME`, `MAX_TEXT`, `MAX_DEFAULT`, `MAX_TEMPLATE`), and
  holds control names and the icon class to a strict character set instead of
  silently sanitizing them. The malformed/hostile corpus in the suite proves
  every refusal is side-effect free (no post, option, manifest or sandbox).
- Main taught `Widget_Renderer` to unwrap Elementor's array-valued controls
  (URL and MEDIA return `['url' => ..]`, ICONS returns `['value' => ..]`, one
  level deeper for an svg icon). The compiler now emits the same reduction per
  control type, followed by an `is_scalar()` check before the escaper, so a
  compiled widget renders the URL where it used to print "Array". A data
  provider asserts the two render paths are byte-identical for those shapes,
  for hostile scalars and for junk.
- Every write that changes a generated file (compile, undo, purge) calls
  `opcache_invalidate()`, so a host with `opcache.validate_timestamps` off
  does not keep executing the previous class while the manifest vouches for
  the new bytes.

### Self-review hardening

- The lint matched allowed calls by their last name segment and never looked
  at a callee that is not an identifier. `use function system as esc_html;`,
  `namespace Evil;`, `\Evil\esc_html()`, `"system"('id')`,
  `('sys' . 'tem')('id')`, `$a[0]('id')` and `f()('id')` all passed. It now
  accepts only global names, rejects `use` / `namespace`, and rejects a call
  of any expression result; each case is in the seeded-hostile corpus.
- `Compiled_Widget_Manifest::restore()` (the undo path) writes bytes back only
  when they pass the same lint as a compile; otherwise the entry returns but
  nothing on disk hashes to it, so the widget is inert.
- `loadable()` holds every pre-require gate without requiring anything.
  `load_enabled()` requires from it, and `get-custom-widget` /
  `list-custom-widgets` report `loading` from it, so a read tool never
  executes generated PHP (and list no longer re-hashes every file per row).
- Registration uses `Widget_Spec::is_renderable()`, a structural check, so
  specs stored before the stricter write-time `validate()` keep rendering.

### Coordinator review fixes

- Session undo: compile snapshots and the compiled-entry half of status and
  spec-update snapshots are unwound newest first in `restore_session()`'s
  first pass (like `db_rows`), and a compile snapshot's identity is
  `compiled_widget:<spec_id>` rather than the shared option name, so every
  compile in a session is undone, not only the oldest.
- `set-widget-status` and `update-custom-widget` attach the manifest entry to
  their post snapshot (`compiled_widget_entry`); `Rollback_Service` restores
  the post, then the flag. `restore_entry()` only puts the flag back while the
  entry still vouches for the same hash, so it never re-enables a different
  build.
- `compile-custom-widget` writes the file INSIDE the `Safe_Mutation` closure,
  after the snapshot is persisted, and restores the captured file and entry
  if anything throws.
- `update-custom-widget` disables the stale compiled class first and leaves
  the spec untouched when that write fails; `Compiled_Widget_Manifest::write()`
  reads back and reports a refused write instead of trusting
  `update_option()`.
- Enabling while the compiler opt-in is off stores the entry as disabled.
- `Widget_Compiler::build()` returns the class and name it emitted; the
  manifest records those.
- `list-custom-widgets` computes `loadable()` once.
- `is_renderable()` keeps the duplicate-name check.
- Validation errors name their field (`field` in the error data and in the
  message); an update refused on a spec stored under the older rules says so.
  `cloud-pull-assets` reports every refused asset under `skipped` with the
  reason.

## Acceptance criteria

- [x] validate-spec rejects malformed/hostile specs without side effects
      (`Widget_Spec::validate()` plus the hostile-spec corpus, which also proves
      hostile spec text compiles to inert literals).
- [x] Generated PHP always passes a token-parse lint pre-write; every
      interpolated value is escaped per declared control type.
- [x] Widgets load only via the manifest from an isolated directory, hash
      verified; disabling removes the widget from the builder without deleting
      the spec or the file (`set-widget-status`, `delete-custom-widget`).
- [x] History: `compile-custom-widget`, `update-custom-widget`,
      `set-widget-status` and `delete-custom-widget` all run through
      `Safe_Mutation` and return an `operation_id`. Undoing a compile restores
      the previous entry AND the previous generated bytes together; undoing a
      spec update restores the previous spec. `create-custom-widget` is exempt
      by the repo's own convention (there is no prior state to snapshot; see
      `Create_Product`). Delete stays restorable from the spec through the
      trash, and because the loader reads post status, `restore-post` alone
      brings the compiled widget back.

## Remaining work

1. Template conditionals and loops. Today's template language is `{{name}}`
   interpolation only, identical to `Widget_Renderer`'s. Anything richer needs
   its own hostile corpus before it is emitted.
2. Elementor style/advanced control sections (the compiled class registers one
   content section, same as the dynamic widget).
3. A `recompile-all` path. `update-custom-widget` now DISABLES the stale
   compiled class rather than letting it keep rendering, and the read tools
   report staleness, but recompiling is still a separate explicit call and
   there is no bulk path.
4. No un-compile ABILITY. `Compiled_Widget_Manifest::purge()` exists and runs on
   permanent delete, but there is no MCP tool that discards a compiled class
   while keeping the spec; `set-widget-status` disabling is the closest thing.
5. The manifest is one option for all widgets. The compile's own undo is
   per-widget (it restores a single captured entry), but a bare
   `wpmcp_compiled_widgets` option restore from any other route is still
   all-or-nothing.
6. Adversarial security review before release, per the issue.

## Compliance stance (wp.org build)

The compiler adds a THIRD code-execution path to the plugin, after
`Php_Snippet_Runner`'s `eval` (COMPLIANCE.md B-16) and `Wp_Cli_Executor`'s
`proc_open` (B-17): `Compiled_Widget_Manifest::load_enabled()` requires
plugin-generated PHP from a protected `wp-content` sandbox. It contains none of
the constructs `WPORG-09-EXEC` scans for, so the rule does not fire, but the
stance is deliberate and recorded as B-28: the compiler is PRO-only and
default-off AT THE EXECUTION SITE as well as at the write (see the execution
gate above, and `Compiled_Widget_Manifest::execution_allowed()`; the earlier
draft of this doc claimed containment that only the write path actually had),
and it never reaches the directory zip, because
`scripts/flavors/wporg/policy.php` removes `src/Tools/WidgetBuilder` whole and
the compiler lives inside it. That is a property of the strip list's paths, so
moving the compiler out of `WidgetBuilder/` would silently start shipping it.

## Non-goals

- The AI never authors PHP; no eval, no dynamic includes outside the
  hash-verified manifest loader.
- No compilation on the free tier, and none at all unless the site opts in.
