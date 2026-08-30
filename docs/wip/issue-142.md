# Issue #142: gateway credential core (phase 1 of #130)

Mint, hold, and kill a site-local gateway credential with no cloud
dependency: a stable gateway OAuth client plus a rotate-on-use refresh
token, plaintext shown once, immediately usable by the self-hosted proxy
(#77). Cloud upload and consent are phase 3.

## What a gateway credential actually is

`{client_id, client_secret, refresh_token}`, not `{client_id,
refresh_token}`. `Token_Grant::exchange()` authenticates EVERY grant type,
`refresh_token` included, with `Client_Store::verify_secret()`, because
`Client_Store` has no public-client mode. A credential handed out without
the secret can never be redeemed at the token endpoint. All three plaintext
values are returned exactly once, from `Gateway_Credential::issue_for_user()`,
and none is recoverable afterwards.

## Done in this slice

- `Client_Store::find_by_registration(name, uris, registrar_key)` and
  `Client_Store::find_all_by_registration(...)`: non-creating lookups used
  by every teardown path so a revoke can never re-provision. Matching is on
  the registration FINGERPRINT, not on client_name + redirect_uris: name
  and redirect URI are attacker-supplied through the unauthenticated DCR
  endpoint, and `urn:wpmcp:gateway` passes `Redirect_Uri_Validator`, so
  name matching alone would let anyone pre-register a "WPMCP Gateway"
  client and have the site adopt it (their secret included) on first
  provision. The fingerprint folds in the registrar key
  `wpmcp-gateway-local`, a server-side constant no DCR caller can produce.
- `Client_Store::revoke(client_id)` removes the client record and evicts
  every access and refresh token bound to it. Token eviction is
  unconditional, and the return value is true when EITHER the record or
  any token was removed, so a half-revoked state converges and is not
  reported as a no-op.
- `Client_Store::rotate_secret(client_id)`: re-mints the secret for an
  existing client. `create()`'s dedup path refuses to rotate a row holding
  tokens (correctly: it would break a live connection); the gateway needs
  the opposite, because re-provisioning deliberately kills the previous
  credential.
- `Client_Store::protect(client_id)` plus a `protected` skip in
  `Client_Store::gc()`: the gateway row is durable identity, and it
  legitimately holds no tokens between a chain revocation and the next
  refresh, which is exactly the shape the orphan sweep reaps.
- `Token_Store::revoke_for_client()` added to mirror the refresh store's,
  and `Token_Store::pass_fingerprint()` made public so there is one
  definition of "credential fingerprint" rather than two that can drift.
- `Refresh_Token_Store`: records now carry `pass_fingerprint`, and
  `redeem()` returns `credential_changed` (revoking the chain) when the
  bound user was deleted or changed their password. Without this a 30-day
  refresh token would keep minting access tokens across a password change,
  since `Token_Store`'s own fingerprint check only covers the 1h access
  token. Records predating this keep their old behaviour rather than being
  invalidated wholesale by an upgrade. `Token_Grant::refresh()` maps the
  new status onto the same flat `invalid_grant`, so there is no oracle.
- `WPMCP\Cloud\Gateway_Credential` (src/Cloud/Gateway_Credential.php):
  - `ensure_client()` idempotent: resolves by stored option, then by
    non-creating fingerprint lookup, and only then creates; the clients
    store never grows from re-provisioning. Throws rather than returning
    `create()`'s `{client_id, client_secret}` payload on an impossible
    read-back, so plaintext cannot escape a method documented never to
    return it.
  - `issue_for_user(int $user_id)` returns
    `{client_id, client_secret, refresh_token}`, each plaintext appearing
    exactly once. Rotation is total: access tokens (`Token_Store`),
    refresh tokens, and the client secret are all replaced, so the
    previous credential is dead immediately rather than surviving until
    its access tokens lapse.
  - `deprovision()` local-only and convergent: it sweeps the client id
    recorded in the option BEFORE deleting the option (that pointer is the
    only way to reach tokens left behind in a half-revoked state), then
    every remaining row carrying the gateway fingerprint.
- MCP tools in src/Tools/Gateway/, registered as their own free-tier
  `gateway` group in Plugin.php (manage_options, Governance + audit apply):
  - `gateway-provision` (requires `confirm: true`, credential once,
    returns a `client_cap_reached` WP_Error instead of a fatal when the
    clients store is full)
  - `gateway-status` (provisioned flag + client_id, never token material)
  - `gateway-revoke` (requires `confirm: true` like every other
    destructive tool, idempotent, local-only, and reports
    `provisioned` re-evaluated after the teardown rather than assumed)
- Tests: tests/free/Gateway/GatewayCredentialTest.php and
  tests/free/Gateway/GatewayToolsTest.php (19 tests), covering redeemability
  end to end through `Token_Grant`, idempotency, total rotation,
  password-change invalidation, lookalike-client rejection, gc survival,
  half-revoked convergence, and both confirm gates.

## Deliberate departures from the issue text

- **The tools are free tier, in their own `gateway` group, not in the
  `cloud` group.** `cloud` is stripped from the wp.org build
  (`scripts/flavors/wporg/strip.php` drops src/Tools/Cloud and
  `register_cloud_abilities` outright) and excluded from the WooCommerce
  vertical's `FLAVOR_GROUPS`. Registering the gateway tools there would
  mean builds where a credential can be minted but not revoked, which
  contradicts the issue's "kill it with no network" requirement.
- **The refresh grant is NOT restricted to the gateway client id.**
  `Token_Grant` already implements the refresh grant (from #133) for every
  DCR client, and ordinary MCP clients depend on it to survive past the 1h
  access token. Restricting the grant itself would break them. What the
  gateway needs is a policy layered on top, not a narrowing of the shared
  grant.
- **`Safe_Mutation` does not wrap the gateway tools.** A snapshot of
  `wpmcp_oauth_clients` holds only a secret hash, so restoring it cannot
  restore a usable credential; and undoing a revoke would resurrect token
  rows a site owner just killed. Re-provisioning is the recovery path.
  Documented at the tool class docblocks.

## Remaining work

- Per-issue TTL so the gateway refresh token can honor
  `wpmcp_gateway_refresh_ttl` independently of the store-wide
  `wpmcp_oauth_refresh_ttl`. `Refresh_Token_Store` currently has one TTL
  for all refresh tokens.
- A gateway-specific policy on top of the existing refresh grant (see
  above): what the gateway client may do that an ordinary client may not,
  and vice versa.
- Scope enforcement. The `gateway` scope is recorded on the token and
  carried onto the access tokens minted from it, but nothing in the
  request path consults it: `Bearer_Auth` performs no ability or domain
  scope check, so a gateway access token authorises whatever its bound
  user can do. Do not read `Gateway_Credential::SCOPE` as a restriction
  until that lands.
- The gateway client still counts against `Client_Store::MAX_CLIENTS`; it
  is exempt from `gc()` but not from the cap.
