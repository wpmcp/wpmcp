# WIP plan: multi-site gateway with per-call routing and gateway credential provisioning (#130)

Status: every plugin-side and proxy-side DoD item is in. The hosted gateway
service is cloud backend scope and out of this repo.

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

## Per-call routing in the self-hosted proxy

`bin/wpmcp-proxy.php` (the #77 stdio proxy) gained a `Router`. There is one
proxy, not a second one: #77's single-site pump now delegates to it.

- Every message goes to the default site (`WPMCP_SITE`, or the only one)
  unless a `tools/call` carries `site`. `"site": "<alias>"` runs the call on
  that site only; `"site": "all"` broadcasts and answers with one status per
  configured site, in config order: `ok`, `error`, `site_unavailable`,
  `tool_unavailable`. A dead or locked-down site never breaks the call.
- `site` is stripped before forwarding. An unknown alias is refused and sent
  nowhere: a typo never falls back to the default site.
- Identity and governance are the target site's own: each site is reached
  with its own configured credential and its own `Mcp-Session-Id` (a new
  site gets its own handshake, replaying the client's initialize params),
  never another site's. The proxy adds no authority of its own.
- Broadcast writes are double-gated: `WPMCP_BROADCAST_WRITES=1` (workspace
  opt-in, default off) AND `confirm: true` on the call. A tool is a read only
  when every site exposing it says `readOnlyHint: true` in that site's own
  `tools/list`; a missing hint is a write. The gate is decided once for the
  whole broadcast before any site is called, so a refused write reaches no
  site. `confirm` is forwarded only to a tool that declares it.
- With several sites, `tools/list` advertises the `site` argument on every
  tool and adds `wpmcp_proxy_list_sites` (names and URLs, never credentials).
- Tests: `tests/free/Proxy/ProxyGatewayRoutingTest.php` (plain PHPUnit, a
  fake transport that records which site got which credential and session).

## Consent on cloud connect

- `cloud-connect` takes `gateway_consent` (boolean, default false), stored by
  `Gateway_Consent`. It is recorded on every successful connect, so leaving
  it unticked withdraws consent, and withdrawing it kills a credential the
  cloud already holds (locally, offline). A credential that was never
  uploaded is the self-hosted proxy's and is left alone.
- It gates the cloud half: `Gateway_Credential::upload()` refuses with
  `gateway_cloud_consent_required` without it, and `cloud-gateway-provision`
  reports `upload_status: consent_required` while still handing over the
  once-only plaintext for local use. Minting keeps its own per-call
  `consent` argument.
- There is no Cloud admin screen in `src/Admin` yet, so the checkbox is the
  tool input. When a Cloud settings screen lands (#135 adds the OAuth
  callback page), it should render this same `Gateway_Consent` state as an
  unticked-by-default checkbox.

## Surface restriction, second half

`filter_bearer_token_accepted()` judges the path and query string whenever
the user is first resolved, which can be before WordPress parses the
request. WordPress then dispatches what `WP::parse_request()` resolves, and a
form-encoded POST `rest_route` outranks both, so a POST to `/wp-json/mcp/...`
carrying `rest_route=/wp/v2/users` passed the early check and ran core REST.
`enforce_dispatched_route()` now re-judges on `parse_request` (priority 1,
before `rest_api_loaded`) against the resolved route and logs a gateway
request out when it is not headed for the MCP or OAuth routes.

## Remaining work (outside this PR)

- Finalize `/wpmcp-cloud/v1` gateway endpoints with the backend (`POST` and
  `DELETE /gateway/credential`) and add `Cloud_Client::delete()` for
  best-effort cloud cleanup after the local revoke.
- Let the proxy authenticate with a gateway credential (refresh grant, with
  somewhere writable to persist the rotated refresh token) instead of an
  application password.
- Admin provision/revoke UI with once-only plaintext display, and the
  consent checkbox on a Cloud settings screen.

## Definition of done (from the issue)

- [x] Idempotent gateway client registration plus self-issued scoped refresh
      token bound to the connecting admin, uploaded once through Cloud_Client
      (plugin side done and tested; the cloud endpoint is backend scope)
- [x] Locally-first revoke that works with cloud unreachable
- [x] Gateway credential bound to an Identity with per-identity ability
      allowlist; all gateway calls pass Registrar::is_permitted and are
      recorded in Governance_Audit_Log with the identity
- [x] Proxy accepts a per-call site alias and an `all` broadcast returning
      per-site status; broadcast writes gated by workspace opt-in plus
      per-call confirm
- [x] Consent checkbox on cloud connect, default off (`gateway_consent` on
      cloud-connect; no Cloud admin screen exists yet)
