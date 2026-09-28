# WordPress MCP capabilities: gap review and non-goals

Measured 2026-08-14 across the free WordPress MCP ecosystem (GitHub projects
and wordpress.org plugins that register MCP tools; chatbots, llms.txt
publishers and agentic-commerce feeds were excluded). This public copy keeps
the capability findings and the decisions; the per-project comparison was
removed.

The purpose is to make "what are we missing" a decidable question rather than
an open-ended one: what exists, what we are genuinely missing, and what we are
deliberately not building.

## The official baseline is small

Verified against WordPress trunk, not documentation:

| Layer | Ships | Count |
|---|---|---|
| WP core Abilities API | `core/get-site-info`, `get-user-info`, `get-environment-info` | **3** |
| `WordPress/mcp-adapter` (separate plugin, not core) | `discover-abilities`, `get-ability-info`, `execute-ability` | **3** |
| `WordPress/ai` plugin | `ai/title-generation`, `ai/summarization`, ... | ~20 |
| WooCommerce 10.9, behind a feature flag | products/orders query + mutate | 7 |

Everything official is **~33 tools**, and only with two extra plugins
installed and a feature flag flipped. Core alone is three. The adapter is a
socket: it exposes whatever abilities happen to be registered.

## Observations

- **Tool count is a poor metric.** Some large surfaces decompose one
  operation into a tool per field (`update-user-first-name`,
  `tag-update-slug`).
- **Reversibility is rare.** Only a handful of projects have any real undo
  (session rollback, undo-last-operation, change receipts, checkpoints or a
  change log). Most rely on WordPress post revisions, or on nothing.
- **Dry-run is rarer still**, and absent from every large surface.
- **Builder depth varies.** Elementor is well covered; Bricks, Beaver Builder
  and Breakdance are barely served. We ship Elementor v3 + v4 atomic, Bricks,
  Divi and Gutenberg.

## Genuine gaps, ranked

### Tier 1: foundational, shipped in this PR
Term CRUD, duplicate-post, revision diff, content counts. Without term CRUD an
agent could file a post under a category but never create one.

### Tier 2: load-bearing, not yet built
- **WooCommerce depth.** Missing: variations, attributes, coupons, customers,
  refunds, stock, reviews, shipping zones, tax rates, webhooks, system status.
- **Third-party ability bridge.** Expose abilities registered by *other*
  plugins under our governed endpoint, so every plugin adopting the Abilities
  API becomes covered surface. Highest leverage item on this list.
- **FSE and site editing.** `theme.json`, global styles, style variations,
  templates and template parts.
- **Site Health tests**, permalink structure, rewrite flush, front-page
  selection.
- **Security and incident response.** Malware scan/clean, quarantine, core
  reinstall, salt regeneration, app-password revocation, hardening, failed
  logins. Today only `scan-security` exists.

### Tier 3: breadth
Block suites (Kadence, Spectra, GenerateBlocks), Beaver Builder and
Breakdance, media operations (WebP conversion, unused/unattached media, bulk
optimise), SEO schema/sitemap/robots.txt/llms.txt, ACF field-group authoring,
form-definition CRUD, user roles and capabilities, application-password
lifecycle.

## Deliberate non-goals

Recorded so they are not repeatedly rediscovered as "gaps":

- **Unguarded code execution as a first-class tool** (`execute-php`,
  `shell-exec`, `php-eval`, `process-exec`). We already have guarded WP-CLI
  and PHP-snippet execution, default-off and dev-environment-only, each
  shipped after an adversarial review. Ungoverned versions would undo the
  safety model.
- **Node/proxy architecture.** We are MCP-Adapter-native by design.
- **Cloud-relay tool hosting**: tools defined on a vendor's server rather than
  in the plugin. Incompatible with running inside the user's site.
- **Paid third-party SEO data APIs.** BYO-key vendor wrappers, not core
  capability. Reconsider only if a specific customer asks.
- **Per-field tool decomposition.** Inflates tool count and burns the
  tools/list budget for no capability gain.

## The constraint this review surfaced

At 302 tools the tools/list payload is ~161KB. Every added ability now costs
client context, and the byte budget has been raised six times. Compact mode
(`list-tools` + `call-tool`) keeps capped clients at ~2.8KB and is the
structural answer, but the default payload cannot keep growing indefinitely.
Tier 2 and 3 work should assume compact mode is the primary discovery path,
not an escape hatch.
