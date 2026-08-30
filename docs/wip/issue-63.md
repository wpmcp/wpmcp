# Issue #63: custom CSS/JS injection tools - WIP plan

Status: first slice on `wip/issue-63`. Tracks what this slice covers and what
remains before the issue's acceptance criteria are met.

## What this slice ships

New `custom_code` ability group (PRO tier, `src/Tools/CustomCode/`):

- `wpmcp/add-scoped-css` (`Add_Scoped_Css`): stores sanitized CSS scoped to
  one post/page; rendered in `wp_head` only on that page. Accepts either a
  full CSS fragment or `selector` + bare declarations (wrapped as
  `selector { declarations }`). Site-wide CSS deliberately stays with the
  existing `wpmcp/add-custom-css` ability (Elementor group, core Additional
  CSS storage), so there is one path per scope.
- `wpmcp/add-custom-js` (`Add_Custom_Js`): site-wide JS snippet rendered in
  `wp_footer`. Default OFF, XSS-class surface. Requires BOTH the
  `WPMCP_ALLOW_JS_INJECTION` constant or `wpmcp_allow_js_injection` filter
  (via `Custom_Js_Guard::is_enabled()`) AND `unfiltered_html` on top of the
  ability's `manage_options` gate. Attempts are audited through
  `Governance_Audit_Log` without logging the payload.
- `Css_Sanitizer`: pure, reject-not-clean validator run on write AND again at
  render (defense in depth). Rejects markup/element breakout, `expression()`,
  `behavior:`, `-moz-binding:`, `javascript:`/`vbscript:` schemes,
  `@import`/`@charset`, `data:` URLs in `url()`, hex escape obfuscation,
  unbalanced braces; selector alphabet is allowlisted.
- `Custom_Code_Store`: single option (`wpmcp_custom_code`) holding site CSS,
  per-post CSS, and the JS snippet. One option means every write goes through
  the existing `Safe_Mutation` option snapshot path unchanged, so all writes
  are reversible via `rollback-operation`. This is the "safe storage
  mechanism" the issue requires.
- `Custom_Code_Renderer`: prints CSS in `wp_head` (re-sanitized; invalid
  stored blocks are dropped) and JS in `wp_footer` only while the governance
  gate is enabled, so disabling the gate also stops rendering stored JS.

## Remaining work

- [ ] Adversarial payload-corpus tests in `tests/pro` for `Css_Sanitizer`
      (per the issue's TDD notes these come before any relaxation of the
      rejection rules) and for the `Add_Custom_Js` guard chain.
- [ ] Element-scoped CSS written into builder settings (Elementor page
      settings `custom_css`) through the builder snapshot path; this slice
      covers page scope in the plugin's own store only.
- [ ] Move `Custom_Code_Renderer::boot()` out of
      `register_custom_code_abilities()` into the main plugin bootstrap:
      today, disabling the ability group would also stop rendering stored
      CSS, which conflates tool availability with output.
- [ ] Read/remove counterparts (`get-custom-code`, `remove-scoped-css`,
      `remove-custom-js`) so agents can inspect and clear stored code
      without a raw option edit.
- [ ] Adversarial security review (stored XSS via CSS/JS) before merge.
- [ ] Ability grid / docs entries for the new group.
