# WIP plan: get-page-snapshot (issue #81)

One-call normalized page digest so agents stop chaining read calls to
understand a page.

## Done in this slice

- `src/Tools/Analysis/Get_Page_Snapshot.php`: free-tier read-only tool.
  - Core sections always rendered: `structure` (word/heading counts, post
    type/status, recursive Gutenberg block counts), `outline` (heading
    levels + text), `media` (image inventory), `links` (internal/external
    split + inventory), `builder` (via `Builder_Detector::detect`),
    `seo_lite` (title length, H1 count, images missing alt, excerpt flag).
  - Heavy sections (`global_tokens`, `responsive_overrides`) excluded by
    default, requested via the `sections` param; currently render
    `not_implemented` stubs so the contract is exercisable.
  - Pro overlay seam: `wpmcp_page_snapshot_sections` filter receives the
    digest, post id, and requested sections; pro audit tools append overlay
    sections there. Free build renders with zero callbacks attached.
  - Size bound: per-inventory cap of 200 items with a `truncated` flag.
- Registered as `wpmcp/get-page-snapshot` (tier `free`, domain `analysis`,
  operation `read`) in `Plugin::register_analysis_abilities`.

## Remaining work

- Implement `global_tokens`: report theme.json / builder global style tokens
  the page references.
- Implement `responsive_overrides`: summarize per-breakpoint overrides for
  Elementor/Bricks/Divi structures.
- Builder structure summaries: element counts from the stored builder tree
  (currently only Gutenberg block counts; builder pages get the
  rendered-HTML-derived counts from Content_Extractor).
- Pro overlay wiring: hook analyze-seo / analyze-accessibility into
  `wpmcp_page_snapshot_sections` in the pro layer.
- Tests: core digest shape in `tests/free`, overlay attachment in
  `tests/pro`, size-cap test on a pathological fixture (per the issue's TDD
  notes).
- Consider a byte-level response bound in addition to the item cap.
