# WIP plan: issue #67, deepen SEO adapters and add schema generation

Status: first slice landed on `wip/issue-67`. This file tracks the plan
derived from the issue body and what remains; delete it when #67 closes.

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

## Landed in this slice

- `src/Tools/SEO/Schema_Generator.php`: JSON-LD assembly for Article and
  WebPage from the post's own data (title, dates, author, excerpt or the
  plugin-curated SEO description, featured image, permalink, publisher).
- `src/Tools/SEO/Generate_Schema_Markup.php`: proposal (read) tool over the
  generator; returns the structure plus encoded JSON-LD, writes nothing.
- Registration in `Plugin::register_seo_abilities()` as
  `wpmcp/generate-schema-markup`, tier `pro`, domain `seo`, op `read`.
  Registered unconditionally (like `get-seo-status`) because it does not
  need an SEO plugin to be active.
- `SEO_Adapter::get_social_meta()`: per-post OG/Twitter reads for Yoast and
  RankMath, with structured `supported: false` responses for plugins whose
  social maps are not verified yet.

## Remaining work

- [ ] Schema types: LocalBusiness (needs a site-profile source for
      address/geo) and Product (WooCommerce-aware), validated in tests.
- [ ] Merge-awareness: report when the active plugin already emits a graph
      so agents do not double-emit conflicting JSON-LD.
- [ ] Social write path: `update_social_meta()` through `Safe_Mutation`,
      plus social maps for SEOPress, The SEO Framework, SureRank.
- [ ] Expose social reads/writes as abilities (extended vocabulary, PRO).
- [ ] Term-level SEO read/write with structured "unsupported" fallbacks.
- [ ] `generate-meta-tags` proposal tool; `set-social-image` snapshot-first
      write.
- [ ] Additional adapters (2 to 4 plugins) via the dispatcher framework.
- [ ] Cross-plugin conformance suite (failing tests first, per the issue's
      TDD notes; free surface in tests/free, pro additions in tests/pro).
