# Issue #142: gateway credential core (phase 1 of #130)

Mint, hold, and kill a site-local gateway credential with no cloud
dependency: a stable gateway OAuth client plus a rotate-on-use refresh
token, plaintext shown once, immediately usable by the self-hosted proxy
(#77). Identity binding is phase 2; cloud upload and consent are phase 3.

## Done in this slice

- `Client_Store::find_by_registration(string $name, array $redirect_uris): ?array`
  non-creating lookup on client_name + redirect_uris (order-insensitive),
  used by every teardown path so a revoke can never re-provision.
- `Client_Store::revoke(string $client_id): bool` removes the client record
  and evicts every access and refresh token bound to it. Token eviction is
  unconditional so a half-revoked state still converges on a repeat call.
- `Token_Store::revoke_for_client()` added to mirror the existing
  `Refresh_Token_Store::revoke_for_client()`, so `Client_Store::revoke`
  can evict both stores.
- `WPMCP\Cloud\Gateway_Credential` (src/Cloud/Gateway_Credential.php):
  - `ensure_client()` idempotent: resolves by stored option, then by
    non-creating registration lookup, and only then creates; the clients
    store never grows from re-provisioning.
  - `issue_for_user(int $user_id)` returns `{client_id, refresh_token}`
    with the plaintext appearing exactly once; prior gateway refresh
    tokens are revoked first (rotate rather than accumulate).
  - `deprovision()` local-only, idempotent, works with cloud unreachable.
- MCP tools in src/Tools/Cloud/, wired through the standard cloud ability
  loop in Plugin.php (manage_options, Governance + audit apply):
  - `gateway-provision` (requires `confirm: true`, plaintext once)
  - `gateway-status` (provisioned flag + client_id, never token material)
  - `gateway-revoke` (idempotent, local-only)

## Remaining work

- Refresh token hardening in `Refresh_Token_Store` for the gateway case:
  - TTL filterable via `wpmcp_gateway_refresh_ttl` (currently the store
    has a single `wpmcp_oauth_refresh_ttl`); needs per-issue TTL support.
  - Bind the record to the user's password fingerprint so the credential
    dies on password change or account deletion; validate the fingerprint
    at redemption time.
- `Token_Grant`: add the `refresh_token` grant type, restricted to the
  gateway client id; mint a standard 1h access token plus the rotated
  refresh token; every rejection returns generic `invalid_grant` (no
  error oracle); record outcomes to `Governance_Audit_Log` under
  `oauth/token` like the code grant. Note `Refresh_Token_Store::redeem`
  already implements rotate-on-use, grace, and replay (chain revocation);
  the grant layer just has to consume it.
- Confirm access tokens minted via the refresh grant authenticate through
  `Bearer_Auth` and pass `Registrar::is_permitted`.
- Decide the ability tier for the gateway tools (currently registered in
  the cloud block, which is `pro`; the issue's test plan references the
  free suites).
- Tests (free tier, same dirs/style as the existing Auth and Cloud
  suites): store lookup/revoke, refresh issue/rotate/replay/expiry/
  fingerprint invalidation, grant restriction to the gateway client,
  credential lifecycle idempotency, tool behavior including the confirm
  gate. Keep coverage in line with the 90.3 floor.
