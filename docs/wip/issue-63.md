# Issue #63: custom CSS/JS injection tools - WIP plan

Status: page-scoped CSS and gated site-wide JS on `wip/issue-63`, with the
adversarial payload corpus the issue's TDD notes ask for. Element-scoped
(builder-settings) CSS is the remaining acceptance-criteria gap.

## What this slice ships

New `custom_code` ability group (PRO tier, `src/Tools/CustomCode/`):

- `wpmcp/add-scoped-css` (`Add_Scoped_Css`): stores sanitized CSS scoped to
  one post/page; rendered in `wp_head` only on that page. Accepts either a
  full CSS fragment or `selector` + bare declarations (wrapped as
  `selector { declarations }`). APPENDS to the page's existing block by
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
  render. Matching happens against a CANONICAL form (comments stripped, CSS
  escape sequences decoded), because `@im\port`, `expres\sion(`,
  `java\script:` and `expression/**/(` are all valid CSS that a raw-text
  blacklist walks past. The stored value is still the author's original text.
  Rejects markup/element breakout, `expression()`, `behavior:`,
  `-moz-binding:`, `javascript:`/`vbscript:`, `@import`/`@charset`, `data:`
  URLs in `url()`, unterminated comments, unbalanced braces, and non-UTF-8
  input; selector alphabet is allowlisted. Ordinary CSS that the previous
  blanket "any `\XX` escape" rule refused - `content: "\f105"` icon fonts,
  `content: "\201C"`, `@media (400px < width)` - is accepted again.
- `Custom_Code_Store`: ONE OPTION PER INDEPENDENTLY-ROLLED-BACK BLOCK. The JS
  snippet lives in `wpmcp_custom_code`; each page's CSS lives in its own
  `wpmcp_custom_code_post_<id>` option. `Rollback_Service` restores an entire
  option value, so a single shared option would have made every snapshot a
  whole-store before-image and let a rollback of one page's write delete
  another page's CSS as collateral.
- `Custom_Code_Renderer`: booted from `Plugin::register_builder_runtime_hooks()`
  (gated on the ability group, not on the license), NOT from ability
  registration - `wp_abilities_api_init` never fires on a plain front-end
  page view, so the previous wiring meant stored code never rendered for a
  visitor at all. Page CSS is scoped with `is_singular()` + a `WP_Post`
  check, not bare `get_queried_object_id()`, which returns term and user ids
  on archives and leaked post CSS onto same-id archives. A stored block that
  no longer passes the sanitizer is dropped AND logged.

## Tests

`tests/pro/CustomCode/`:

- `CssSanitizerTest`: the adversarial payload corpus, in two halves. Every
  vector appears in plain, escape-obfuscated and comment-split spellings; the
  must-accept half pins the ordinary CSS an over-strict sanitizer would push
  agents away from.
- `AddScopedCssTest`: write path, append/replace contract, `edit_css` bar,
  rollback isolation between two pages, and the archive-id scoping
  regression.
- `AddCustomJsTest`: guard-chain ORDER (gate before capability), both audit
  outcomes with reasons, `</script>` breakout, and that closing the gate
  stops rendering an already-stored snippet.
- `CustomCodeAbilitiesRegistrationTest`: PRO tiering, that registering the
  abilities does NOT hook front-end output, and that the runtime hook path
  does.

## Remaining work

- [ ] Element-scoped CSS written into builder settings (Elementor page/element
      settings `custom_css`) through the builder snapshot path. This is the
      one acceptance criterion still open; this slice covers page scope in
      the plugin's own store.
- [ ] Read/remove counterparts (`get-custom-code`, `remove-scoped-css`,
      `remove-custom-js`) so agents can inspect and clear stored code without
      a raw option edit.
- [ ] Adversarial security review (stored XSS via CSS/JS) by a second pair of
      eyes; the corpus above is the evidence to review against.
- [ ] Ability grid / docs entries for the new group.
