# WIP plan: issue #67, deepen SEO adapters and add schema generation

Status: second slice on `wip/issue-67`. This file tracks the plan derived
from the issue body and what remains; delete it when #67 closes.

## Goal (from the issue)

One dependable SEO surface regardless of which plugin a site runs:

- Unified field vocabulary across the adapter: title, description,
  canonical, focus keyword, og/twitter fields, noindex.
- Term-level SEO read/write where the plugin supports it; structured
  "unsupported" responses otherwise (never errors).
- 2 to 4 more plugin adapters via the integration dispatcher framework.
- `generate-schema-markup` and `generate-meta-tags` as proposal (read)
  tools; `set-social-image` as a snapshot-first write via `Safe_Mutation`.
- Tiering: current 3-plugin post-meta surface stays free; extended
  vocabulary, extra adapters, and generation tools are PRO via `Pro\Gate`
  (enforced centrally by the Registrar tier check).

## Landed so far

- `src/Tools/SEO/Schema_Generator.php`: JSON-LD assembly for Article,
  WebPage, LocalBusiness and Product from the post's own record (raw
  post_title, dates, author, excerpt or the plugin-curated SEO description,
  featured image, permalink, publisher). LocalBusiness reads the
  `wpmcp_site_profile` option for address and phone and omits anything the
  profile does not supply; Product reads the WooCommerce record when the
  post is a product and the plugin is active, and degrades to the plain post
  fields otherwise.
- `src/Tools/SEO/Generate_Schema_Markup.php`: proposal (read) tool over the
  generator. Returns the encoded JSON-LD only, hex-escaping the
  HTML-significant characters so an author-controlled title carrying
  `</script>` cannot break out of the block the payload is pasted into.
  Re-checks `read_post` for anything not published, matching
  `Search_Content`. Writes nothing.
- `src/Tools/SEO/Get_Social_Meta.php` and `SEO_Adapter::get_social_meta()`:
  per-post OG/Twitter reads in one neutral field set for Yoast, RankMath and
  SEOPress, with a structured `supported: false` for plugins whose per-post
  social storage is not mapped yet.
- `SEO_Adapter::active_plugin()` now detects SEOPress, which the existing
  `SEOPRESS_KEYS` map had been unreachable without.
- Registration in `Plugin::register_seo_abilities()` as
  `wpmcp/generate-schema-markup` (unconditional, like `get-seo-status`,
  because it needs no SEO plugin) and `wpmcp/get-social-meta` (conditional on
  a detected plugin). Both tier `pro`, domain `seo`, op `read`.
- wp.org build: both handlers plus `Schema_Generator` are in
  `REMOVED_PATHS`, the inline locals and the tier-describing comments are
  deleted from `Plugin.php`, and `scripts/build-wporg-release.sh` passes with
  no blockers.
- Tests: `tests/pro/SEO/SchemaGeneratorTest.php`,
  `tests/pro/SEO/GenerateSchemaMarkupTest.php`,
  `tests/pro/SEO/SocialMetaTest.php` (22 tests).

## Remaining work

- [ ] Merge-awareness: report when the active plugin already emits a graph
      so agents do not double-emit conflicting JSON-LD.
- [ ] Social write path: `update_social_meta()` through `Safe_Mutation`,
      plus social maps for The SEO Framework and SureRank.
- [ ] `set-social-image` snapshot-first write; `generate-meta-tags`
      proposal tool.
- [ ] Term-level SEO read/write with structured "unsupported" fallbacks.
- [ ] Additional adapters (2 to 4 plugins) via the dispatcher framework.
- [ ] Cross-plugin conformance suite: one input schema replayed against every
      adapter, proving identical agent-facing behavior.

## Acceptance criteria status

- Same input schema writes correctly on every supported plugin: partial.
  Per-adapter suites exist (`SeoAdapterTest`, `SeoPressAdapterTest`,
  `SeoFrameworkAdapterTest`, `SureRankAdapterTest`); the single replayed
  conformance suite does not.
- Term-level read/write: not started.
- JSON-LD valid for Article, WebPage, LocalBusiness, Product, validated in
  tests: met.
- All SEO writes snapshotted and reversible: met for the existing
  `update-seo-meta` path; this slice adds no writes.
