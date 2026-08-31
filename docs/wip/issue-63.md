# Issue #63: custom CSS/JS injection tools - WIP plan

Status: page-scoped AND element-scoped CSS, plus gated site-wide JS, on
`wip/issue-63`, with the adversarial payload corpus the issue's TDD notes ask
for. The remaining gap is the read/remove counterparts and writing element CSS
into the builder's OWN settings document rather than this plugin's store.

## What this slice ships

New `custom_code` ability group (PRO tier, `src/Tools/CustomCode/`):

- `wpmcp/add-scoped-css` (`Add_Scoped_Css`): stores sanitized CSS scoped to
  one post/page; rendered in `wp_head` only on that page. Accepts either a
  full CSS fragment or `selector` + bare declarations (wrapped as
  `selector { declarations }`). An `element_id` narrows the scope from the
  page to ONE element on it, by prefixing the `.elementor-element-<id>` class
  the builder already renders; a `selector` given alongside reads as a
  descendant of that element. Both brace characters are refused in the
  declarations form, because a `}` would close the block early and let the
  rest apply outside the scope the ability advertises. APPENDS to the page's existing block by
  default, `replace=true` overwrites, matching the sibling
  `wpmcp/add-custom-css`. Requires `edit_css` (unfiltered_html) on top of the
  ability's `manage_options` gate, the bar core applies to Additional CSS.
  Site-wide CSS deliberately stays with `wpmcp/add-custom-css` (Elementor
  group, core Additional CSS storage), so there is one path per scope.
- `wpmcp/add-custom-js` (`Add_Custom_Js`): site-wide JS snippet rendered in
  `wp_footer`. Default OFF, XSS-class surface. Requires BOTH the
  `WPMCP_ALLOW_JS_INJECTION` constant or `wpmcp_allow_js_injection` filter
  (via `Custom_Js_Guard::is_enabled()`) AND `unfiltered_html` on top of the
  ability's `manage_options` gate. Every attempt is audited through
  `Governance_Audit_Log` with a machine-readable reason and without logging
  the payload. Listed in `Governance\Opt_In_Gates` like every other
  default-off dangerous ability, so the ability grid marks the row and
  refuses to write an enabling toggle while the gate is shut.
- `Css_Sanitizer`: pure, reject-not-clean validator run on write AND again at
  render. Matching happens against the RAW text and against a CANONICAL form
  of it (comments stripped, CSS escape sequences and line continuations
  decoded). The canonical pass is there because `@im\port`, `expres\sion(`,
  `java\script:` and `expression/**/(` are all valid CSS that a raw-text
  blacklist walks past. The raw pass is there because canonicalization only
  ever DELETES, so a payload parked inside a comment (or between two comment
  markers hidden in CSS strings) comes out of it spotless while still sitting
  in the bytes that get stored and echoed - and the HTML tokenizer that closes
  a `<style>` element has never heard of CSS comments or CSS strings. Checking
  only the canonical form let
  `/* </style><script>alert(1)</script> */ .a { color: red; }` through.
  The stored value is still the author's original text.
  A backslash+newline line continuation decodes to nothing, not to a literal
  newline: the CSS parser removes it, so it splits a keyword exactly the way a
  comment does, and decoding it wrongly left `java\<newline>script:` and
  `expres\<newline>sion(` accepted.
  Rejects markup/element breakout, `expression()`, `behavior:`,
  `-moz-binding:`, `javascript:`/`vbscript:`, `@import`/`@charset`, `data:`
  URLs in `url()`, unterminated comments, unbalanced braces, and non-UTF-8
  input; selector alphabet is allowlisted. Ordinary CSS that the previous
  blanket "any `\XX` escape" rule refused - `content: "\f105"` icon fonts,
  `content: "\201C"`, `@media (400px < width)` - is accepted again.
- `Custom_Code_Store`: ONE OPTION PER INDEPENDENTLY-ROLLED-BACK BLOCK.
  `delete_css()` is wired to `deleted_post`, because post ids are REUSED: an
  orphaned `wpmcp_custom_code_post_<id>` option does not sit idle after a
  restore or a WXR import, it re-attaches itself to whatever post takes the id
  next. `set_css()` appends, so it caps the block at `MAX_CSS_BYTES` (256 KB)
  and REFUSES past it rather than truncating - half a stylesheet still parses,
  which is worse than none. The JS
  snippet lives in `wpmcp_custom_code`; each page's CSS lives in its own
  `wpmcp_custom_code_post_<id>` option. `Rollback_Service` restores an entire
  option value, so a single shared option would have made every snapshot a
  whole-store before-image and let a rollback of one page's write delete
  another page's CSS as collateral.
- `Custom_Code_Renderer`: booted from
  `Plugin::register_custom_code_runtime_hooks()`, which
  `register_builder_runtime_hooks()` calls (gated on the ability group, not on
  the license), NOT from ability registration - `wp_abilities_api_init` never fires on a plain front-end
  page view, so the previous wiring meant stored code never rendered for a
  visitor at all. Page CSS is scoped with `is_singular()` + a `WP_Post`
  check, not bare `get_queried_object_id()`, which returns term and user ids
  on archives and leaked post CSS onto same-id archives. A stored block that
  no longer passes the sanitizer is dropped AND logged. The render-time
  sanitizer pass is a SECOND CHANCE at a value that arrived by another route
  (a direct DB edit, another plugin, an older build), not an independent
  barrier: it is the same decision run again, so anything the write path would
  accept it accepts too. The earlier wording claimed otherwise, and that claim
  is exactly what let the comment-hiding bypass sit unnoticed.

Own method rather than another branch in `register_builder_runtime_hooks()`
for two reasons: the wp.org flavor deletes whole methods by name, so a method
is the unit that build can remove cleanly; and a test asserting the renderer
got wired can call it without replaying the whole runtime hook set, which
re-registered the search-index and memory-page hooks on fresh instances and
leaked duplicated `save_post`/`deleted_post` handlers into the rest of the
suite. The renderer is named as a STRING callable there, matching the widget
and block builder branches, so no shipped build statically references a class
its zip does not contain.

## Build flavors

Both vertical builds now drop the group, not just the WooCommerce one:

- `scripts/build-woo-release.sh` deletes the handlers, the sanitizer, the
  store and the renderer; `custom_code` is absent from that flavor's
  `FLAVOR_GROUPS` allowlist, so nothing registers and nothing hooks.
- `scripts/flavors/wporg/strip.php` removes the same five files, deletes
  `register_custom_code_abilities` and `register_custom_code_runtime_hooks`
  from `Plugin.php` by name, and removes the group-map line and the call site.
  Guideline 5 asks the directory build not to CONTAIN the paid code, not
  merely not to reach it, so shipping the handlers as dead weight with a live
  `wp_head`/`wp_footer` hook would have missed the point.

`Custom_Js_Guard.php` stays in both, exactly as the wp-cli and PHP-snippet
guards do: `Governance\Opt_In_Gates` reports the JS opt-in gate's state on
every build.

## Tests

`tests/pro/CustomCode/`:

- `CssSanitizerTest`: the adversarial payload corpus, in two halves. Every
  vector appears in plain, escape-obfuscated and comment-split spellings; the
  must-accept half pins the ordinary CSS an over-strict sanitizer would push
  agents away from.
- `AddScopedCssTest`: write path, append/replace contract, `edit_css` bar,
  rollback isolation between two pages, the archive-id scoping regression,
  element scope (wrap, composition with a selector, rendering, the id
  allowlist), and an end-to-end assertion that nothing the write path accepts
  can make the printed `<style>` element close early.
- `CustomCodeStoreTest`: the store's lifecycle contract - a block does not
  outlive its post, and an appending writer cannot grow one option past the
  cap.
- `AddCustomJsTest`: guard-chain ORDER (gate before capability AND before
  input validation), both audit outcomes with reasons, `</script>` breakout,
  the `<!--`/`<script` script-data double-escape that stops the renderer's own
  `</script>` closing the element, and that closing the gate stops rendering
  an already-stored snippet.
- `CustomCodeAbilitiesRegistrationTest`: PRO tiering, that registering the
  abilities does NOT hook front-end output, and that the runtime hook path
  does.
- `tests/free/FlavorTest.php`: the woo flavor registers neither ability and
  hooks neither `wp_head`, `wp_footer` nor `deleted_post`, and the branch
  names the renderer as a string rather than statically.

## Acceptance criteria

- [x] Element- and page-level CSS persists and renders; selectors are
      validated/sanitized (no stored XSS via CSS). Element scope is the
      `element_id` parameter; the selector alphabet is allowlisted, and the
      sanitizer corpus now covers the comment-wrapped and string-hidden
      breakout spellings that the canonical-form-only matching missed.
- [x] JS injection requires `unfiltered_html` and an explicit governance
      enable; off by default.
- [x] All writes snapshotted and reversible, one option per independently
      rolled-back block.
- [x] Sanitization has dedicated adversarial tests (payload corpus).

## Remaining work

- [ ] Element CSS written into the BUILDER's own settings document (Elementor
      page/element settings `custom_css`), so it shows up in the Elementor UI
      too. Deliberately not done here: that postmeta is one serialized
      document for the whole page, so a snapshot of it is a whole-page
      before-image and rolling back one element's CSS would revert every other
      edit made to the page in between. The acceptance criterion is met by the
      plugin's own store; this is the UI-integration follow-up.
- [ ] Read/remove counterparts (`get-custom-code`, `remove-scoped-css`,
      `remove-custom-js`) so agents can inspect and clear stored code without
      a raw option edit. `delete_css()` exists and is wired to `deleted_post`,
      but there is still no agent-facing way to clear a block deliberately.
- [ ] Ability grid / docs entries for the new group.
