# WIP plan: get-page-snapshot (issue #81)

One-call normalized page digest so agents stop chaining read calls to
understand a page.

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
reason. `tests/free/Platform/WporgFreeSurfaceTest.php` is the gate: it reads
the strip script's own `REMOVED_METHODS` list and fails if any `free` ability
is registered inside one of those methods.

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
3. A 256 KB byte budget enforced AFTER the overlay filter runs, shedding the
   inventories worst-first, so an overlay cannot append past the bound.

## Access

Registered at `edit_posts` like the other read tools, then re-checks
`current_user_can('read_post', $post_id)` for any post that is not published,
mirroring `Search_Content`. A contributor cannot digest another author's
draft, a private post, or a private CPT such as the memory guardrail posts.

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

## Remaining work

- Builder-native content extraction. `content_coverage` currently DECLARES
  that Elementor/Bricks bodies are not measured; routing them through the
  builder readers so outline/media/links are populated is the follow-up.
- `global_tokens` reports the tokens the page references. Resolving each
  token to its theme.json/global-styles value is not done.
- Wire the pro audit tools into `wpmcp_page_snapshot_sections` in the pro
  layer itself. The seam and its test exist; the production wiring does not.
