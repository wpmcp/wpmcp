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
  token. `Token_Grant::refresh()` maps the new status onto the same flat
  `invalid_grant`, so there is no oracle. Two properties that took a
  second pass to get right:
  - **The upgrade window is closed, not left open.** An unbound record
    (pre-#142, or one whose user did not resolve at issuance) is not
    exempt forever: it ADOPTS the current fingerprint on its first
    successful redeem. Nobody is logged out by the deploy, and every
    password change after that point kills the token like any other.
  - **Reuse detection is evaluated BEFORE the binding check.** A burned
    token replayed after the owner reacts to a leak by changing their
    password satisfies both conditions; reporting `credential_changed`
    would take the dedicated `oauth/refresh-reuse` audit row (#133) out of
    the governance log in exactly the scenario it exists for. Both
    outcomes revoke the chain, so reporting the more serious one is free.
- `Refresh_Token_Store::ttl()` takes a scope: gateway-scoped tokens get a
  second filter, `wpmcp_gateway_refresh_ttl`, layered over the store-wide
  `wpmcp_oauth_refresh_ttl` (issue task 3). `redeem()` and `gc()` resolve
  the TTL per record, so a short gateway credential and a long interactive
  session coexist.
- `WPMCP\Gateway\Gateway_Credential` (src/Gateway/Gateway_Credential.php):
  - `ensure_client()` idempotent: resolves by stored option, then by
    non-creating fingerprint lookup, and only then creates; the clients
    store never grows from re-provisioning. Throws rather than returning
    `create()`'s `{client_id, client_secret}` payload on an impossible
    read-back, so plaintext cannot escape a method documented never to
    return it.
  - `issue_for_user(int $user_id)` returns
    `{client_id, client_secret, refresh_token}`, each plaintext appearing
    exactly once. Rotation is total: any DUPLICATE gateway client row is
    revoked first (the same convergence `deprovision()` does, and for the
    same reason: `create()`'s dedup can leave two rows, and rotating only
    the resolved one would leave the twin alive with its old secret and
    its own live tokens), then access tokens (`Token_Store`), refresh
    tokens, and the client secret are all replaced. The previous
    credential is dead immediately rather than surviving until its access
    tokens lapse.
  - `deprovision()` local-only and convergent: it sweeps the client id
    recorded in the option BEFORE deleting the option (that pointer is the
    only way to reach tokens left behind in a half-revoked state), then
    every remaining row carrying the gateway fingerprint.
- MCP tools in src/Tools/Gateway/, registered as their own free-tier
  `gateway` group in Plugin.php (manage_options, Governance + audit apply):
  - `gateway-provision` (requires `confirm: true`, credential once,
    refuses with `oauth_disabled` when `OAuth_Config::is_enabled()` is
    false, returns a `client_cap_reached` WP_Error when the clients store
    is full and a distinct `gateway_provision_failed` for a broken store
    invariant)
  - `gateway-status` (provisioned flag + client_id + `oauth_enabled` +
    `usable`, never token material)
  - `gateway-revoke` (requires `confirm: true` like every other
    destructive tool, idempotent, local-only, deliberately NOT gated on
    OAuth being enabled, and reports `provisioned` re-evaluated after the
    teardown rather than assumed)
  - Both confirm gates throw `\InvalidArgumentException`, the repo
    convention (Delete_Post, Delete_Plugin, Delete_File), so one class of
    refusal produces one `Request_Log` outcome shape.
  - Annotation hints are overridden rather than derived: `create` would
    publish `destructiveHint: false` for a call that irreversibly kills
    the previous secret and every token bound to it, and `delete` would
    publish `idempotentHint: false` for a tool documented and tested as
    idempotent. MCP clients use these for auto-approval.
- `Client_Cap_Reached extends \RuntimeException`, thrown by
  `Client_Store::create()`, so the one ordinary operational failure is
  distinguishable from a broken invariant without message matching. Every
  existing `catch (\RuntimeException)` keeps working.
- `Gateway_Credential` lives in **src/Gateway/**, not src/Cloud.
  `scripts/build-woo-release.sh` deletes `src/Cloud` wholesale from the
  WooCommerce zip while the `gateway` group is whitelisted for that
  flavor, so the class would have been a class-not-found fatal on every
  gateway tool call in that build. `FlavorTest` now pins both halves: the
  three tools register under `woocommerce`, and no directory the woo
  prune list removes contains a class a woo-registered handler reaches.
- Tests: tests/free/Gateway/GatewayCredentialTest.php,
  tests/free/Gateway/GatewayToolsTest.php and
  tests/free/Auth/RefreshTokenBindingTest.php, plus the two new
  `FlavorTest` cases, covering redeemability end to end through
  `Token_Grant` and on through `Bearer_Auth::resolve()` and
  `Registrar::is_permitted()`, idempotency, total rotation including a
  duplicate row, password-change and account-deletion invalidation,
  pre-#142 adoption, reuse-beats-binding ordering, the gateway TTL filter,
  the OAuth-disabled refusal, lookalike-client rejection, gc survival,
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
- **`gateway-provision` is NOT seeded off via `Default_Seeder`.** It was
  the obvious answer to "a credential-minting tool ships enabled to every
  upgrading install", and it was tried and backed out. Two reasons. First,
  it is already gated by something stronger: the tool refuses outright
  unless `OAuth_Config::is_enabled()`, which is OFF by default and can
  only be turned on by a site owner editing wp-config.php or adding a
  filter. On a default install the tool is inert, and the opt-in is a
  deliberate act, not a checkbox an agent can flip. Second, a governance
  disable removes the ability from `Registrar::all()`, which is the same
  surface `AbilityManifestTest` and `PluginAbilitiesTest` pin; seeding one
  off makes the registered-ability surface depend on whether the seeder
  has run yet in a given process, which is exactly the "no ability-
  manifest churn from seeding" property `Default_Seeder`'s own docblock
  says it is preserving. If a governance default is still wanted, it
  belongs with `Governance\Opt_In_Gates` (where the exec/db/fs write tools
  live) rather than with the seeder.
- **`Safe_Mutation` does not wrap the gateway tools.** A snapshot of
  `wpmcp_oauth_clients` holds only a secret hash, so restoring it cannot
  restore a usable credential; and undoing a revoke would resurrect token
  rows a site owner just killed. Re-provisioning is the recovery path.
  Documented at the tool class docblocks. The cost is real and worth
  naming: these writes carry no `operation_id`, so they do not appear as
  an undo point in the history UI. The tool CALL is still recorded by
  `Request_Log` like every other dispatch, so the forensic trail exists;
  what is absent is a restore point, and a restore point for this data
  would be actively harmful.

## Remaining work

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
