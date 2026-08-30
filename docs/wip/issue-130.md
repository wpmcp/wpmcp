# WIP plan: multi-site gateway with per-call routing and gateway credential provisioning (#130)

Status: skeleton landed, plugin-side provisioning first. Hosted gateway service
is cloud backend scope and out of this repo.

## What exists after this slice

- `src/Cloud/Gateway_Credential.php`: provisioning, once-only plaintext
  return, upload seam through `Cloud_Client`, locally-first revoke, and
  bookkeeping option (`wpmcp_gateway_credential`, no secrets stored).
  - Client registration is idempotent via `Client_Store::create` fingerprint
    dedup (stable name + registrar key `wpmcp-gateway`).
  - Refresh token minted via `Refresh_Token_Store::issue`, bound to the
    connecting admin's user id and to a scope that embeds the Identity name
    (`wpmcp:gateway identity:<name>`).
  - Revoke kills the client's token chains locally before any network call,
    so the kill switch works with the cloud unreachable.

## Remaining work

1. Identity binding enforcement
   - When a bearer token whose scope starts with `wpmcp:gateway identity:` is
     presented, `Bearer_Auth` (or a thin adapter) must set the identity via
     the `wpmcp_current_identity` filter so `Identity_Context::current()`
     resolves it.
   - Every gateway call then flows through `Registrar::is_permitted` against
     the per-identity ability allowlist and is recorded in
     `Governance_Audit_Log::record()` with the identity name.
2. Consent UX
   - Explicit disclosed checkbox on the cloud connect screen, default OFF.
   - `Gateway_Credential::provision()` must refuse without consent (TODO
     marker in place).
3. Cloud contract
   - Finalize `/wpmcp-cloud/v1` gateway endpoints with the backend:
     `POST /gateway/credential` (upload once), `DELETE /gateway/credential`
     (best-effort cleanup after local revoke). Add a `delete()` helper to
     `Cloud_Client` when the contract lands.
4. Proxy-side routing (MCPB Node proxy)
   - Accept a per-call `site` alias argument; `all` broadcasts return a
     per-site status array.
   - Broadcast writes double-gated: workspace opt-in plus per-call confirm.
   - Coordinate with issue #77 (self-hosted stdio-to-HTTP proxy with named
     site env config); #77 does not cover credential provisioning.
5. Admin surface
   - Provision/revoke UI on the Cloud settings screen with the once-only
     plaintext display and copy affordance.
6. Tests
   - Idempotency (same client_id across provisions, secret rotated).
   - At most one live credential (old chain revoked on re-provision).
   - Revoke works with `Cloud_Client` erroring (offline kill switch).
   - Scope carries the sanitized identity name.

## Definition of done (from the issue)

- [ ] Idempotent gateway client registration plus self-issued scoped refresh
      token bound to the connecting admin, uploaded once through Cloud_Client
      (provisioning half landed here; upload contract pending)
- [ ] Locally-first revoke that works with cloud unreachable (landed,
      needs tests)
- [ ] Gateway credential bound to an Identity with per-identity ability
      allowlist; all gateway calls pass Registrar::is_permitted and are
      recorded in Governance_Audit_Log with the identity
- [ ] Proxy accepts a per-call site alias and an `all` broadcast returning
      per-site status; broadcast writes gated by workspace opt-in plus
      per-call confirm
- [ ] Consent checkbox on cloud connect, default off
