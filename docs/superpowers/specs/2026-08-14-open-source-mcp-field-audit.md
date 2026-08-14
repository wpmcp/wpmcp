# The free WordPress MCP field: audit and parity plan

Measured 2026-08-14. Scope: 54 GitHub projects and 60+ wordpress.org plugins
that genuinely register MCP tools (chatbots, llms.txt publishers and agentic
-commerce feeds were enumerated and excluded, with reasons). Roughly **340
distinct capabilities** were observed across the field.

This document exists so "build parity" is a decidable question rather than an
open-ended one. It records what the field actually has, what we already
exceed, what we are genuinely missing, and what we are deliberately not
building.

## The headline: the official baseline is tiny

Verified against WordPress trunk, not documentation:

| Layer | Ships | Count |
|---|---|---|
| WP core Abilities API | `core/get-site-info`, `get-user-info`, `get-environment-info` | **3** |
| `WordPress/mcp-adapter` (separate plugin, not core) | `discover-abilities`, `get-ability-info`, `execute-ability` | **3** |
| `WordPress/ai` plugin | `ai/title-generation`, `ai/summarization`, … | ~20 |
| WooCommerce 10.9, behind a feature flag | products/orders query + mutate | 7 |

Everything official is **~33 tools**, and only with two extra plugins
installed and a feature flag flipped. Core alone is three. The adapter is a
socket, not a competitor: it exposes whatever abilities happen to be
registered. Positioning stays "the adapter is the socket, we are the
appliance and the fuse box."

## Where we actually stand

At 302 abilities we are among the largest surfaces in the field. Larger
counts exist (mountdev 388, acrossai 354, stonewright 318, bcs 316,
miniorange 265, easy-mcp-ai 242, stifli 214), but tool count is a poor
metric: several of those decompose one operation into a tool per field
(`update-user-first-name`, `tag-update-slug`), and the largest have **no undo
and no dry-run at all**.

Two findings define our position, and both survived adversarial checking:

1. **Reversibility is rare.** Of the whole free field, roughly seven projects
   have any real undo: stifli-flex-mcp (session rollback + redo), royal-mcp
   (`mcp_undo_last_operation`), iato-mcp (field-level change receipts),
   webo-mcp (checkpoints), cowboy-mcp (checkpoints), superdav (change log),
   and fluent-cart (dry-run plus confirm token, no undo). Everyone else
   relies on WordPress post revisions, or on nothing.
2. **Dry-run is rarer.** Default-on in exactly one plugin (webo-mcp).
   Entirely absent from every large surface: mountdev (388 tools), miniorange
   (265), easy-mcp-ai (242), invizo (144), immens (132).

Snapshot-before-every-write across all 302 abilities remains the thing nobody
else does at scale. That is the moat, and it is worth more than closing the
tool-count gap.

**Builder depth is the other edge.** Elementor is well covered by several
competitors, but Bricks, Beaver Builder and Breakdance are essentially
unserved in the free field (only vibe-ai touches four builders; only bcs
reads Divi/Bricks). We ship Elementor v3 + v4 atomic, Bricks, Divi and
Gutenberg.

## Genuine gaps, ranked

### Tier 1 — foundational, shipped in this PR
Term CRUD, duplicate-post, revision diff, content counts. Term CRUD in
particular is in nearly every competitor; without it an agent could file a
post under a category but never create one.

### Tier 2 — strategically load-bearing, not yet built
- **WooCommerce depth.** We ship ~12 Woo abilities; stifli ships ~75 and
  mountdev 74. Missing: variations, attributes, coupons, customers, refunds,
  stock, reviews, shipping zones, tax rates, webhooks, system status. This is
  the biggest strategic gap because the planned wp.org wedge is an
  "MCP for WooCommerce" listing, and we would ship it thinner than free
  competitors.
- **Third-party ability bridge.** Expose abilities registered by *other*
  plugins under our governed endpoint. One feature buys parity with an entire
  category (enable-abilities-for-mcp, agent-abilities, abilities-bridge,
  acrossai) and turns every plugin adopting the Abilities API into surface we
  cover for free. Highest leverage item on this list.
- **FSE and site editing.** `theme.json`, global styles, style variations,
  templates and template parts. Core site editing, entirely absent from us.
- **Site Health tests**, permalink structure, rewrite flush, front-page
  selection.
- **Security and incident response.** Malware scan/clean, quarantine, core
  reinstall, salt regeneration, app-password revocation, hardening, failed
  logins. A whole domain where we ship only `scan-security`.

### Tier 3 — breadth
Block suites (Kadence, Spectra, GenerateBlocks: 27.8% of pros and a known
growth gap), Beaver Builder and Breakdance, media operations (WebP
conversion, unused/unattached media, bulk optimise), SEO schema/sitemap/
robots.txt/llms.txt, ACF field-group authoring, form-definition CRUD,
user roles and capabilities, application-password lifecycle.

## Deliberate non-goals

Recorded so they are not repeatedly rediscovered as "gaps":

- **Unguarded code execution as a first-class tool.** angie ships
  `execute-php`; soflyy's connector ships `shell-exec`, `php-eval` and
  `process-exec`. We already have guarded WP-CLI and PHP-snippet execution,
  default-off and dev-environment-only, each shipped after an adversarial
  review. Matching the ungoverned versions would trade the moat for a
  headline.
- **Node/proxy architecture.** Much of the GitHub field runs outside
  WordPress. We are MCP-Adapter-native by design.
- **Cloud-relay tool hosting** (WPVibe's model): tools defined on a vendor's
  server rather than in the plugin. Incompatible with running inside the
  user's site.
- **Paid third-party SEO data APIs** (Semrush, Ahrefs, DataForSEO,
  SE Ranking). BYO-key vendor wrappers, not core capability. Reconsider only
  if a specific customer asks.
- **Per-field tool decomposition.** Inflates tool count and burns the
  tools/list budget for no capability gain.

## The constraint this audit surfaced

At 302 tools the tools/list payload is ~161KB. Every added ability now costs
client context, and the byte budget has been raised six times. Compact mode
(`list-tools` + `call-tool`) keeps capped clients at ~2.8KB and is the
structural answer, but the default payload cannot keep growing indefinitely.
Tier 2 and 3 work should assume compact mode is the primary discovery path,
not an escape hatch.
