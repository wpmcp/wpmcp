# Issue #135: cloud phase B (OAuth connect, settings sync, marketplace)

The delivery plan on #135 splits phase B into four phases:

| Phase | Scope | Where it lands |
|---|---|---|
| 1 | Encrypted credential vault + rotation-safe token lifecycle | #141, PR #235 (`Cloud_Credentials`, `Token_Refresher`) |
| 2 | PKCE OAuth connect flow | Follow-up on top of #235 |
| 3 | Settings sync over the governance allowlist | This branch |
| 4 | Marketplace browse + install as inactive drafts | This branch |

Earlier passes of this branch also built phase 1 and 2 (`Token_Vault`,
`Cloud_Oauth`, `Admin\Cloud_Callback_Page`). They duplicated #235's vault and
refresher under different names and edited the same lines of `Cloud_Client`,
`Cloud_Config` and `Cloud_Connect`, so both could not merge. They were removed
from this branch; the last commit that contains them is `cca9cec`, which the
phase 2 follow-up can mine for the authorize/exchange/callback code once it is
rebuilt on `Cloud_Credentials`.

## Phase 3: settings sync

- `src/Cloud/Settings_Sync.php`: allowlist built from the owning classes'
  `::OPTION` constants: `wpmcp_governance_settings`, `wpmcp_tool_exposure_mode`,
  `wpmcp_mcp_exposure`, `wpmcp_skills_enabled`, `wpmcp_identities`.
- Identities minus secrets: every identity record is projected onto exactly the
  fields `Identity_Store::create()` writes (name, domains, operations,
  abilities, mode, exposure) on export and on apply, so a stray field never
  leaves the source or lands on the target. Identities merge by name.
- Governance toggles merge per dimension. Sync never changes the MCP exposure
  kill switch (off would block rollback-operation over MCP, on would undo an
  operator's decision) and drops any governance "off" for
  wpmcp/rollback-operation, domain core or operation update, reporting each in
  skipped.
- `applied[i]` pairs with `operation_ids[i]`; options whose value already
  matches (compared order-insensitively) are listed in `unchanged` and not
  written.
- `Identity_Store::normalize()` is the one identity shape, used by
  `create()` and by sync; digits-only identity names (int keys) are kept.
- `apply()` re-filters against the allowlist, re-checks `Option_Guard`, coerces
  per option and writes each option through `Safe_Mutation`, so a sync is one
  `rollback-operation` away.
- Entitlement: `Settings_Sync::entitlement_error()` (Pro\Gate plus
  manage_options) runs before push, pull and apply, so a site without it never
  talks to the cloud about settings.
- Abilities: `cloud-sync-settings` (preview, read), `cloud-push-settings`
  (POST /settings), `cloud-apply-settings` (a pasted payload, or with no
  payload GET /settings then apply).
- The wporg build removes `src/Cloud/Settings_Sync.php` by path
  (`scripts/flavors/wporg/policy.php`), since its gate is Pro\Gate.

## Phase 4: marketplace

- `cloud-marketplace-browse` (read): GET /marketplace with optional type and
  search, projected onto scalar listing fields; specs are not returned.
- `cloud-marketplace-install` (create): GET /marketplace/{slug} with a strict
  slug pattern (no path or query characters), spec validated with
  `Widget_Spec::validate()` / `Block_Spec::validate()` (the validate-*-spec
  gate), template always run through `wp_kses_post` even for unfiltered_html
  users, name collisions refused via a targeted `find_by_name()` lookup on
  each store (not the 200-row `all()`), stored as a draft via the new `$status`
  argument on `Widget_Spec_Store::create()` / `Block_Spec_Store::create()`
  (anything but publish or draft is an error),
  provenance in `_wpmcp_marketplace_source`. Not snapshotted, like every other
  create-only path (Create_Post, create-custom-widget, cloud-pull-assets).
- Publish and moderation are cloud backend scope.

## Cloud REST contract added (relative to /wpmcp-cloud/v1)

- `POST /settings { settings }` returns `{ updated_at? }`
- `GET /settings` returns `{ settings }`
- `GET /marketplace[?type&search]` returns `{ listings: [ { slug, type, title, description?, author?, version? } ] }`
- `GET /marketplace/{slug}` returns `{ listing: { slug, type, title, version?, spec } }`

## Tests

- `tests/pro/Cloud/CloudSettingsSyncTest.php`: allowlist, export, apply
  filtering and coercion, governance merge, the exposure and rollback-path refusals, unchanged reporting, identities
  (projection, merge, normalization, malformed map, rollback), provenance,
  entitlement and capability, push, pull, and the option guard.
- `tests/pro/Cloud/CloudMarketplaceTest.php`: browse projection and filters,
  install as draft for both types, both validators, listing type and slug
  checks, hostile slugs, name collision, forced kses, capability.

## Definition of done tracking

- [ ] PKCE OAuth connect: phase 1 is #141 / PR #235; phase 2 is a follow-up
- [x] Settings sync over the curated allowlist (governance, tool toggles,
      exposure mode, identities minus secrets), re-filtered on apply
- [x] Marketplace browse/install as inactive drafts re-validated through the
      widget/block spec validators
- [x] Settings sync gated as the paid-cloud entitlement
