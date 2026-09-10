# WIP plan: multi-site gateway with per-call routing and gateway credential provisioning (#130)

Status: plugin-side provisioning landed and enforced. Proxy-side routing and
the admin surface are still open. The hosted gateway service is cloud backend
scope and out of this repo.

## What exists after this slice

- `src/Cloud/Gateway_Credential.php`: provisioning, once-only plaintext
  return, upload seam through `Cloud_Client`, locally-first revoke, and
  bookkeeping option (`wpmcp_gateway_credential`, no secrets stored).
  - Client registration is idempotent via `Client_Store::create` fingerprint
    dedup (stable name + registrar key `wpmcp-gateway`). Idempotency holds in
    the live case because the revoke happens first and clears the access
    tokens too: `Client_Store::find_reusable()` refuses to recycle a row that
    still holds any token, so a revoke that swept only refresh tokens would
    mint a new client row on every re-provision.
  - Refresh token minted via `Refresh_Token_Store::issue`, bound to an
    administrator and to a scope that carries the Identity name
    (`wpmcp:gateway identity:<rawurlencoded name>`).
  - The chain carries its own ten-year TTL (`Gateway_Credential::TTL_SECONDS`,
    filterable via `wpmcp_gateway_refresh_ttl`) through the new per-record
    `$ttl` argument on `Refresh_Token_Store::issue()`. The site-wide
    `wpmcp_oauth_refresh_ttl` filter was the wrong instrument: it would
    lengthen every ordinary user session too. `Token_Grant` carries the
    record's TTL forward on rotation so the gateway chain does not silently
    drop back to 30 days the first time it refreshes.
  - Revoke calls `Refresh_Token_Store::revoke_chain()` on the recorded
    chain_id, which cascades into `Token_Store::revoke_chain()`, then sweeps
    anything else bound to the client. Access tokens die with the refresh
    chain, so the kill switch is total, and it makes no network call, so it
    works with the cloud unreachable.
  - `record()` is a pure read: it reports no credential when `Oauth_Gc`'s
    orphan sweep has reaped the client row, but the clearing itself is
    `prune()`, called from the status and provisioning paths. A read that
    wrote to the options table was both a surprise inside
    `Registrar::is_permitted()` and the ordering bug that made `revoke()` a
    no-op for a credential whose client row was already gone.
  - Provisioning refuses, before minting or destroying anything: without
    explicit consent, from a caller without `manage_options`, while the OAuth
    subsystem is disabled (the credential could never authenticate), for a
    user id that does not exist or is not an administrator, for an identity
    name that is not in `Identity_Store`, when a credential is already live
    and `replace` was not passed, and when the `Client_Store` registration
    cap is already reached. The cap is pre-checked rather than caught,
    because the destructive step (killing the old chain, which is what frees
    the client row for reuse) runs first by design.
  - Every refusal is audited, not just the successes, and the audit's
    identity column carries the ACTING identity like the rest of the log,
    with the bound identity in the reason.
  - `revoke()` re-checks `manage_options` rather than trusting the tool
    layer, returns the number of token records it actually killed, and is
    also reachable as `wp wpmcp gateway-revoke`. The WP-CLI path exists
    because `cloud-gateway-revoke` is a pro-tier ability and
    `Registrar::register()` drops pro abilities on a lapsed licence: a
    ten-year credential whose only off switch expires with the subscription
    is not an off switch.
  - The upload goes out with `redirection => 0` and `sslverify => true`
    (per-request args, new third parameter on `Cloud_Client::post`), because
    the Requests library re-sends a POST body on a 30x and an http `Location`
    would replay the client secret and the refresh token in cleartext. A
    disconnected site gets `gateway_cloud_not_configured`, not the misleading
    non-https refusal.

- Blast radius: the identity allowlist only narrows abilities inside
  `Registrar::is_permitted()`, and `Bearer_Auth` resolves tokens on the global
  `determine_current_user` filter, so on its own the credential was a plain
  administrator on `/wp/v2/users?context=edit`, `/wp/v2/plugins`, admin-ajax
  and everything else. `Bearer_Auth::resolve()` now runs a
  `wpmcp_bearer_token_accepted` filter on an otherwise valid token (a
  listener may only refuse, never grant, because it runs after
  `Token_Store::validate()`), and `Gateway_Credential` refuses its own token
  anywhere except the MCP and OAuth routes. It fails closed: a request whose
  REST route cannot be determined is not the gateway's surface.

- Identity enforcement: `Bearer_Auth` remembers the record of the token that
  authenticated the request, and `Gateway_Credential::filter_current_identity()`
  hooks `wpmcp_current_identity`. The authoritative binding is the token's
  **client_id** matched against the stored credential; the token's own scope
  is the fallback, and only for a token already established as the gateway's,
  so losing the mutable bookkeeping option cannot silently promote a live
  gateway token to the connecting admin's full grid. When neither resolves a
  name the request gets `UNBOUND_IDENTITY`, a sentinel `Identity_Store`
  cannot match, which `Governance::is_within_identity_scope()` already
  default-denies. A forged gateway scope on a foreign client is harmless in
  both directions: an identity only narrows what `Registrar::is_permitted()`
  allows, and claiming the scope also drags the forger inside the gateway's
  surface restriction. `Bearer_Auth::resolve()` also clears its request-scoped
  record on entry, so a second resolution in the same process (WP-CLI, a
  `wp_set_current_user()` re-resolution, a batch run) cannot inherit the
  previous caller's token.

- MCP surface: `cloud-gateway-provision` (create), `cloud-gateway-revoke`
  (delete), `cloud-gateway-status` (read), all pro, all `manage_options`.
  `consent` is required on provision and defaults to false; `replace` is
  required to overwrite a live credential; `confirm` is required on revoke,
  matching the repo's confirm gate on every irrecoverable write
  (`delete-post`, `delete-redirect`). `gateway-provision` reports
  `upload_status` (`skipped` / `ok` / `failed`) rather than a bare boolean
  plus an empty warning string, and `gateway-revoke` reports how many token
  records it killed rather than restating whether bookkeeping existed.

- Tests: `tests/pro/Cloud/GatewayCredentialTest.php` (44 tests) covering
  idempotency with a live access token, the offline kill switch including
  access-token death, the surface restriction (core REST and admin-ajax
  refused, MCP accepted, ordinary OAuth tokens unaffected), identity
  resolution surviving the loss of the bookkeeping option, the deny sentinel,
  every refusal path with its audit row, the cap-reached re-provision leaving
  the previous credential intact, the long TTL end to end through
  `Token_Grant::exchange()` including a rotated access token still resolving
  the bound identity, upload guards (redirect refusal, TLS, not-connected),
  and the audit trail. Plus three in
  `tests/free/Auth/BearerAuthTest.php` for the cleared request record and the
  new refusal filter.

## Remaining work

1. Consent UX
   - The consent gate exists and defaults to off, but it is an argument on
     the provision tool. The disclosed checkbox on the cloud connect admin
     screen is not built yet: there is no Cloud settings screen in
     `src/Admin` at all, so that item is an admin-page slice of its own.
2. Cloud contract
   - Finalize `/wpmcp-cloud/v1` gateway endpoints with the backend:
     `POST /gateway/credential` (upload once, https required) and
     `DELETE /gateway/credential` (best-effort cleanup after the local
     revoke). Add a `delete()` helper to `Cloud_Client` when the contract
     lands. Until then the upload target does not exist server-side.
3. Proxy-side routing (MCPB Node proxy)
   - Accept a per-call `site` alias argument; `all` broadcasts return a
     per-site status array.
   - Broadcast writes double-gated: workspace opt-in plus per-call confirm.
   - Coordinate with issue #77 (self-hosted stdio-to-HTTP proxy with named
     site env config); #77 does not cover credential provisioning.
4. Admin surface
   - Provision/revoke UI on the Cloud settings screen with the once-only
     plaintext display and copy affordance.

## Definition of done (from the issue)

- [x] Idempotent gateway client registration plus self-issued scoped refresh
      token bound to the connecting admin, uploaded once through Cloud_Client
      (the plugin side is done and tested; the cloud endpoint it uploads to
      is not built yet)
- [x] Locally-first revoke that works with cloud unreachable
- [x] Gateway credential bound to an Identity with per-identity ability
      allowlist; all gateway calls pass Registrar::is_permitted and are
      recorded in Governance_Audit_Log with the identity
- [ ] Proxy accepts a per-call site alias and an `all` broadcast returning
      per-site status; broadcast writes gated by workspace opt-in plus
      per-call confirm
- [ ] Consent checkbox on cloud connect, default off (the gate landed and
      defaults off, and is enforced twice: the tool argument and
      `Gateway_Credential::provision()` re-checking it. The admin checkbox
      itself is not built, because there is no Cloud settings screen yet)
