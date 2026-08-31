# WIP plan: get-page-snapshot (issue #81)

One-call normalized page digest so agents stop chaining read calls to
understand a page.

LIFECYCLE: this file is scaffolding for the branch, not documentation. The
durable reasoning lives in the class docblock and the README row; this file
is deleted when #81 merges.

## Where it lives, and why that matters

`src/Tools/Context/Get_Page_Snapshot.php`, registered from
`Plugin::register_context_abilities` next to `get-site-context`.

The first cut of this work put the class in `src/Tools/Analysis` and
registered it in `register_analysis_abilities`. Both are deleted wholesale by
`scripts/flavors/wporg/strip.php` (`REMOVED_PATHS`, `REMOVED_METHODS`), so a
free-tier tool placed there ships to nobody: the wp.org zip is the only build
free users install, and `prune_unused_imports()` quietly drops the orphaned
`use` line so the build stays green while the tool vanishes. Its
`Content_Extractor` dependency moved to `src/Tools/Content` for the same
reason. `tests/free/Platform/WporgFreeSurfaceTest.php` is the gate, and it covers
both halves of the strip plus the second build:

- `REMOVED_METHODS`: no `free` ability may be registered inside a group
  method the wp.org strip deletes.
- `REMOVED_PATHS`: no free ability's handler class, nor any WPMCP class that
  handler imports, may resolve to a file under a path the strip deletes.
  This is the half that forced `Content_Extractor` out of `src/Tools/Analysis`
  and it was ungated until now. `src/Pro` and `src/Freemius` are excluded
  because the strip rewrites those call sites with exact-string edits that
  fail the build when they drift, which is a harder gate than this one.
- `scripts/build-woo-release.sh`: same check against the WooCommerce zip's
  prune list, for every ability that flavor still registers. That build had
  no dangling-reference gate at all, which is how this branch shipped
  `get-page-snapshot` (group `context`, kept) depending on `Builder_Detector`
  (`src/Tools/Builders`, pruned): registered in the zip, fatal on the first
  call. The build script now keeps `Builder_Detector.php` and removes the
  paid builder wrappers file by file, exactly as the wp.org strip already
  does. One pre-existing offender is recorded in the test rather than hidden:
  `wpmcp/build-page` reads Elementor page data in a build that prunes
  `src/Tools/Elementor`.

## What the digest contains

- Core sections, always rendered: `structure` (word/heading counts, post
  type/status, recursive Gutenberg block counts capped at 50 names, element
  counts off the builder tree for Elementor/Bricks), `outline` (heading level
  + text, in DOCUMENT ORDER), `media`, `links` (internal/external split +
  inventory), `builder` (via `Builder_Detector::detect`), `seo_lite`,
  `content_coverage`, `truncated`.
- `content_coverage` is the honesty section. Extraction reads stored
  `post_content`, so for Elementor/Bricks (body in postmeta) and Divi
  (shortcode soup) the content-derived numbers are not measurements. The
  block names the source, whether coverage is complete, exactly which
  sections were not measured, and why, instead of returning a zero an agent
  would read as "no images on this page". Password-protected posts report the
  same way and their body is never extracted.
- Heavy sections, opt-in via `sections`: `global_tokens` reports the
  theme preset tokens (`--wp--preset--*`, `has-*-color`) and Elementor global
  color/typography ids the stored content references, plus whether the theme
  has a theme.json; `responsive_overrides` counts per-breakpoint override
  keys in the stored builder tree and says plainly that the concept does not
  apply to non-builder pages rather than reporting zero.
- Pro overlay seam: `wpmcp_page_snapshot_sections` receives the digest, the
  post id, and EVERY section name the caller asked for, including names the
  core does not know, which is how an overlay gets its own opt-in signal
  (`sections: ["seo_audit"]`). The registered schema deliberately has no
  `enum` on `sections` for that reason. A callback that returns a non-array
  is ignored rather than allowed to replace the digest, and the core keys are
  re-asserted over whatever it returns.

## Bounds

Three of them, because an item cap alone is not a size bound:

1. Per-inventory item cap (200) with a `truncated` flag.
2. Per-string clipping (300 chars of text, 500 of URL) and a 50-name cap on
   `structure.block_counts`.
3. A 256 KB byte budget (`Get_Page_Snapshot::MAX_BYTES`, public so the test
   asserts the real bound) enforced AFTER the overlay filter runs. It sheds
   the inventories worst-first, then drops whole NON-CORE sections biggest
   first, which is the step that actually bounds an overlay or a heavy
   section: with only the three inventory shedders the loop ran out of things
   to cut and returned an over-budget digest. Every drop is named in
   `sections.dropped` and sets `truncated`.

## Access

Registered at `edit_posts` like the other read tools, then two per-post
checks:

- `current_user_can('read_post', $post_id)` for any post that is not
  published, mirroring `Search_Content`.
- `current_user_can('edit_post', $post_id)` whenever the post TYPE is not
  viewable, published or not. `read_post` on a published post maps to the
  type's plain `read` cap, which every contributor holds, so the status check
  alone did not cover the case the docs claimed it did: agent memory
  guardrail entries are PUBLISHED posts of a `public => false` CPT whose own
  abilities are all `manage_options`.

Password protection is applied as `post_password_required()` AND not
`edit_post`: the password prompt is a visitor-facing cookie check, not a
capability, and applying it to someone who can edit the post added no
boundary (`wpmcp/get-post` returns the same body) while making the digest
disagree with the surface it summarizes. Where it does apply, nothing reads
the stored body: not the extractor, not the block parser, not the builder
tree, not the token regexes, and the overlay seam does not run at all, so an
overlay cannot invoke another handler to read back what this call withheld.
An overlay that calls another ability's handler must re-check that ability
through `Registrar::is_permitted()` first; the pro test carries that pattern.

## Tests

- `tests/free/Context/GetPageSnapshotTest.php`: digest shape, document-order
  outline, Gutenberg block counts, the 200-item cap on a 260-item
  pathological fixture, per-string clipping, the byte budget against an
  overlay that appends 400 KB after the cap, heavy sections excluded by
  default and rendered when requested, Elementor breakpoint tallies, the
  builder coverage marker, the per-post read gate, password protection, and
  four overlay-seam cases (opt-in name passthrough, no callbacks attached,
  non-array return, core-key overwrite).
- `tests/free/Context/GetPageSnapshotAbilityRegistrationTest.php`: registered
  free, described, and gated.
- `tests/free/Platform/WporgFreeSurfaceTest.php`: the packaging gate above.
- `tests/pro/Analysis/PageSnapshotOverlayTest.php`: analyze-seo attaching as
  an overlay section through the filter with no free-code change.
- `tests/free/Content/ContentExtractorTest.php`: moved with the class from
  `tests/pro/Analysis`, since the extractor is now free-tier code.

## Fixes from adversarial review

- Byte budget now drops non-core sections, so it is a real bound; the test
  asserts `MAX_BYTES` instead of an arbitrary 600 KB that could not fail.
- `content_coverage` branches on the builder, not on whether extraction
  returned rows, and reports `stale_post_content`. Divi lists `seo_lite` as
  unmeasured too, since it comes from the same unparsed shortcodes.
- Preset-class regex is lazy with a longest-first alternation:
  `has-pale-pink-background-color` is the `pale-pink` colour preset, not a
  `pale-pink-background` one.
- Bricks colon-suffixed responsive keys (`_padding:tablet_portrait`) are
  tallied; a Bricks page no longer reports an empty breakpoint list.
- The post-filter re-assertion covers the requested heavy sections too, and
  an overlay's own `truncated` is ORed in rather than reset.
- Unknown/withheld/dropped section names are reported in `sections`.
- Packaging gates extended (see above) and the WooCommerce build fixed.

## Remaining work

- Builder-native content extraction. `content_coverage` currently DECLARES
  that Elementor/Bricks bodies are not measured; routing them through the
  builder readers so outline/media/links are populated is the follow-up.
- `global_tokens` reports the tokens the page references. Resolving each
  token to its theme.json/global-styles value is not done.
- Wire the pro audit tools into `wpmcp_page_snapshot_sections` in the pro
  layer itself. The seam and its test exist; the production wiring does not.
- `wpmcp/build-page` depends on `src/Tools/Elementor` in the WooCommerce
  build that prunes it (pre-existing, now recorded by the new gate rather
  than fixed here).
