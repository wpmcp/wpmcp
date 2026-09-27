# WIP plan: multi-site gateway with per-call routing and gateway credential provisioning (#130)

Status: every plugin-side and proxy-side DoD item is in, layered on #142
(PR #237). The hosted gateway service is cloud backend scope and out of this
repo.

## Layering: one gateway client, one set of token rules

#142 owns the credential: `WPMCP\Gateway\Gateway_Credential` (one protected
gateway client, rotate-on-use refresh chain, password-change revocation via
`Refresh_Token_Store`'s fingerprint binding, grant policy in `Token_Grant`,
the free `gateway-provision` / `gateway-status` / `gateway-revoke`
abilities). #130 adds, on top and without a second client:

- `src/Gateway/Gateway_Binding.php`: binds the live credential to a named
  scoped Identity. A binding belongs to one issuance: it carries a tag of
  the client's current secret hash, and `issue_for_user()` re-mints the
  secret, so a credential re-provisioned by the free tool (which mints an
  unscoped credential and says so) does not inherit an old binding.
- `src/Gateway/Gateway_Guard.php` (every flavor):
  - MCP connection only. A token whose client is the protected gateway
    client is refused off the MCP and OAuth routes, judged early on the path
    and query string (`wpmcp_bearer_token_accepted`, a refuse-only filter
    added to `Bearer_Auth::resolve()`), and again on `parse_request`
    (priority 1, before `rest_api_loaded`) against the route WordPress
    resolved. The second check closes a form-encoded POST `rest_route` that
    WordPress dispatches instead of the path. Both fail closed.
  - Identity. `wpmcp_current_identity` resolves a gateway request to its
    bound identity, so `Registrar::is_permitted()` narrows every ability to
    that allowlist and `Governance_Audit_Log` records each decision under
    the identity's name. A deleted identity denies everything. An unbound
    credential keeps #142's documented semantics (the user's capabilities),
    but still only on the MCP connection.
  - Kill switch: `kill()` = `Gateway_Credential::deprovision()` plus the
    binding, local and offline, also as `wp wpmcp gateway-revoke`.
- `src/Cloud/Gateway_Cloud.php` + `cloud-gateway-provision` /
  `cloud-gateway-status` (pro): mint the one credential bound to an
  identity (through `Safe_Mutation`, snapshotting only the non-secret
  binding option, as #142 does) and upload it once through `Cloud_Client`
  (https only, no redirects, no stale or repeat uploads, cloud consent
  required). Revocation stays the free `gateway-revoke`.

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
- It gates the cloud half: `Gateway_Cloud::upload()` refuses with
  `gateway_cloud_consent_required` without it, and `cloud-gateway-provision`
  reports `upload_status: consent_required` while still handing over the
  once-only plaintext for local use. Minting keeps its own per-call
  `consent` argument.
- Withdrawal goes through `Gateway_Guard::kill()`.
- There is no Cloud admin screen in `src/Admin` yet, so the checkbox is the
  tool input. When a Cloud settings screen lands (#135 adds the OAuth
  callback page), it should render this same `Gateway_Consent` state as an
  unticked-by-default checkbox.

## Remaining work (outside this PR)

- Finalize `/wpmcp-cloud/v1` gateway endpoints with the backend (`POST` and
  `DELETE /gateway/credential`) and add `Cloud_Client::delete()` for
  best-effort cloud cleanup after the local revoke.
- Gateway refresh lifetime is #142's (`wpmcp_gateway_refresh_ttl` over the
  30-day default). EMCP ships ten years; lengthening it is a one-line
  filter, deliberately not changed here.
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
