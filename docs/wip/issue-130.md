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
  - `record()` self-heals: `Oauth_Gc`'s orphan sweep can reap the client row,
    so a record naming a client that no longer exists is cleared rather than
    reported as a live credential.
  - Provisioning refuses, before minting or destroying anything: without
    explicit consent, from a caller without `manage_options`, for a user id
    that does not exist or is not an administrator, and for an identity name
    that is not in `Identity_Store`. A `Client_Store` cap throw becomes a
    `WP_Error`, not a fatal.
  - Both provision and revoke record `cloud/gateway-credential-*` entries in
    `Governance_Audit_Log` (identity and outcome only, never plaintext).

- Identity enforcement: `Bearer_Auth` now remembers the record of the token
  that authenticated the request, and `Gateway_Credential::filter_current_identity()`
  hooks `wpmcp_current_identity` to resolve the bound Identity by matching
  that token's **client_id** against the stored credential. The scope string
  is never trusted for this: `Authorization_Grant` stores whatever scope a
  client asks for, so a scope-reading implementation would let any
  self-registered DCR client assume any identity. Once the identity is
  active, `Registrar::is_permitted()` narrows every call through
  `Governance::is_within_identity_scope()` and records the decision with the
  identity name.

- MCP surface: `cloud-gateway-provision` (create), `cloud-gateway-revoke`
  (delete), `cloud-gateway-status` (read), all pro, all `manage_options`.
  `consent` is a required argument on provision and defaults to false.

- Tests: `tests/pro/Cloud/GatewayCredentialTest.php` (23 tests) covering
  idempotency with a live access token, the offline kill switch including
  access-token death, forged-scope rejection, every refusal path, the long
  TTL, upload guards, self-healing bookkeeping, and the audit trail.

## Remaining work

1. Consent UX
   - The consent gate exists and defaults to off, but it is an argument on
     the provision tool. The disclosed checkbox on the cloud connect admin
     screen is not built yet.
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
- [ ] Consent checkbox on cloud connect, default off (gate landed and
      defaults off; the admin checkbox itself is not built)
