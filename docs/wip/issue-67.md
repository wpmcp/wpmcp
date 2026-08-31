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
- Tiering: current post-meta surface stays free; extended vocabulary and
  generation tools are PRO via `Pro\Gate` (enforced centrally by the
  Registrar tier check).

## Landed so far

- `src/Tools/SEO/Schema_Generator.php`: JSON-LD assembly for Article,
  WebPage, LocalBusiness and Product from the post's own record (raw
  post_title, dates, author, excerpt or the plugin-curated SEO description,
  featured image, permalink).
  - `publisher` is emitted on Article and WebPage only. schema.org scopes it
    to CreativeWork, so attaching it to LocalBusiness or Product would put an
    out-of-domain property in the graph.
  - LocalBusiness describes the business, not the page it renders on: `name`
    and `url` come from the site profile, falling back to the site name and
    home URL, and the post supplies only `mainEntityOfPage`. Address, phone,
    price range and opening hours come from the `wpmcp_site_profile` option
    and fall back to the WooCommerce store settings, so the branch is
    reachable on a stock commerce site with no profile written. Profile
    values that are not scalars are treated as absent rather than cast, which
    would emit the literal "Array".
  - Product reads the WooCommerce record (sku, Offer with price, currency,
    availability, url) when the post is a product and the plugin is active,
    and degrades to the plain post fields otherwise.
- `src/Tools/SEO/Generate_Schema_Markup.php`: proposal (read) tool over the
  generator. Returns the encoded JSON-LD only, hex-escaping the
  HTML-significant characters so an author-controlled title carrying
  `</script>` cannot break out of the block the payload is pasted into.
  Writes nothing.
- `src/Tools/SEO/Post_Access.php`: the one per-post read gate the SEO group
  shares. `edit_posts` says the caller edits posts somewhere on the site, not
  that they may read this particular post, and every SEO read returns
  post-derived strings. It refuses non-published posts the caller cannot
  `read_post`, and password-protected posts the caller cannot `edit_post`:
  a protected post is published, so a status check alone lets it through,
  while these reads take the raw `post_excerpt` and the curated SEO
  description and so bypass the blanking `post_password_required()` gives.
  `Get_SEO_Meta` had no per-post check at all, which made the same draft
  readable through one ability in the group and refused through its sibling.
- `src/Tools/SEO/Get_Social_Meta.php` and `SEO_Adapter::get_social_meta()`:
  per-post OG/Twitter reads in one neutral field set for Yoast, RankMath and
  SEOPress, with a structured `supported: false` for plugins whose per-post
  social storage is not mapped yet.
  - `fields` is the resolved state, not the raw postmeta. All three mapped
    plugins render the Twitter card from the OpenGraph values when the
    Twitter fields are empty (RankMath gates this on
    `rank_math_twitter_use_facebook`, which defaults on), so returning the
    bare meta would report `twitter_title: ''` for a post whose card does
    have a title.
  - `sources` marks each field `override`, `inherited` or `absent`, so an
    agent can tell an explicit value from a mirrored one before writing.
  - This group answers "unsupported" with a payload rather than the
    `WP_Error` / `unsupported_*` code the builder tools use, because the
    issue asks for structured unsupported responses instead of errors. The
    divergence is deliberate and documented on `get_social_meta()`; folding
    both vocabularies into one shared helper is follow-up work.
- `SEO_Adapter::active_plugin()` now detects SEOPress, which the existing
  `SEOPRESS_KEYS` map had been unreachable without. **This makes SEOPress a
  fourth free adapter**: the free `get-seo-meta` and `update-seo-meta` now
  register on a SEOPress site where they previously did not. The map and its
  `yes` robots encoding were already in the tree with `SeoPressAdapterTest`
  covering them in `tests/free`, so the tier placement follows what the repo
  already treated as free; the issue's "extra adapters are PRO" line applies
  to adapters added from scratch. CI still installs Yoast only, so the
  SEOPress leg is covered through the `WPMCP_TESTING` seam rather than
  against a real install.
- `SEO_Adapter::detect_active_plugin()` is the seam-free entry point the test
  harness's `wpmcp_seo_plugin()` now delegates to. The helper used to restate
  the checks and had drifted to knowing only Yoast and RankMath, which makes
  a test skip itself on a SEOPress or SureRank leg while the code it covers
  is live: a silent green.
- Registration in `Plugin::register_seo_abilities()` as
  `wpmcp/generate-schema-markup` (unconditional, like `get-seo-status`,
  because it needs no SEO plugin) and `wpmcp/get-social-meta` (conditional on
  a detected plugin). Both tier `pro`, domain `seo`, op `read`. The
  `schema_type` enum reads `Schema_Generator::SUPPORTED_TYPES` rather than a
  hand-copied literal.
- wp.org build: both handlers, `Schema_Generator` and `Post_Access` are
  handled by the strip, and exact-string edits remove `get_social_meta()`,
  the three `*_SOCIAL_KEYS` maps, `TWITTER_OG_FALLBACKS` and
  `twitter_mirrors_og()` from `SEO_Adapter.php`, so the free build carries no
  unreachable pro logic. Verified: `php scripts/flavors/wporg/strip.php` on a
  staged copy leaves no `get_social_meta` reference and the stripped files
  lint clean.

## Tests

- `tests/free/SEO/SeoConformanceTest.php`: the cross-plugin conformance
  suite. One input schema replayed against all five adapters, with the
  results compared adapter to adapter rather than against per-plugin
  expectations, plus fixed key set and types, partial-write isolation, the
  empty-plugin shape, and the same guarantee through `wpmcp/get-seo-meta`.
- `tests/pro/SEO/SchemaGeneratorTest.php`: per-type emitted key sets (which
  is what catches an out-of-domain `publisher`), the LocalBusiness site
  profile and its assembled `PostalAddress`, the WooCommerce store fallback
  and its precedence, non-scalar profile values, opening hours as a scalar or
  a list, and a real WooCommerce Product asserting sku and the Offer.
- `tests/pro/SEO/SocialMetaTest.php`: RankMath and SEOPress round-tripped
  through their real postmeta keys, OG inheritance and the `sources` markers,
  the RankMath `use_facebook` switch in both states, and the protected-post
  refusal.
- `tests/free/SEO/GetSeoMetaTest.php`: draft and protected-post refusals on
  the free read, and an editor still getting through.
- `tests/free/SEO/SeoPluginDetectionTest.php`: asserts the helper against
  whatever the leg actually installs rather than skipping on anything past
  RankMath, and that it ignores the adapter test seam.
- `tests/free/Capabilities/SeoCapabilityTest.php`: declared capability for
  every SEO tool including the two pro ones (asserted against `declared()`,
  so it does not evaporate on a free-tier run), permission outcomes, and a
  sweep that no ability in the `seo` domain sits below `edit_posts`.

## Remaining work

- [ ] Term-level SEO read/write with structured "unsupported" fallbacks.
      Not started, and the largest remaining gap: Yoast stores term SEO in
      the `wpseo_taxonomy_meta` option while the other four use term meta, so
      it needs its own storage branch rather than a key map.
- [ ] Merge-awareness: report when the active plugin already emits a graph
      so agents do not double-emit conflicting JSON-LD.
- [ ] Social write path: `update_social_meta()` through `Safe_Mutation`,
      plus social maps for The SEO Framework and SureRank.
- [ ] `set-social-image` snapshot-first write; `generate-meta-tags`
      proposal tool.
- [ ] A writer (tool or admin field) for `wpmcp_site_profile`. It is readable
      and writable today only through the generic option tools; the
      WooCommerce fallback is what keeps the LocalBusiness branch usable
      meanwhile.
- [ ] A shared "unsupported" helper so the SEO payload shape and the builder
      tools' `WP_Error` code stop being two vocabularies for one condition.
- [ ] CI: install SEOPress on a matrix leg so the newly reachable adapter is
      exercised against a real install rather than through the test seam.

## Acceptance criteria status

- Same input schema writes correctly on every supported plugin
  (conformance suite proves identical agent-facing behavior): **met**.
  `SeoConformanceTest` replays one payload across all five adapters and
  compares them to each other; the per-adapter suites still back the key
  strings themselves.
- Term-level read/write, structured "unsupported" otherwise: **partial**.
  The structured-unsupported half is done and covered (`get_social_meta()`
  reports unmapped plugins rather than throwing); term-level read/write is
  not started.
- JSON-LD valid for Article, WebPage, LocalBusiness, Product, validated in
  tests: **met**. Per-type key sets are asserted, `publisher` is
  CreativeWork-only, and both the LocalBusiness address branch and the
  WooCommerce Product branch have real coverage.
- All SEO writes snapshotted and reversible: **met** for the existing
  `update-seo-meta` path through `Safe_Mutation`; this slice adds no writes.
