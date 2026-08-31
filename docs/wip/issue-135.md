# Issue #135: EMCP parity, cloud phase B (OAuth connect, settings sync, marketplace)

Status: WIP. This branch lands the phase B engine behind the existing
Cloud_Client seam. Sequenced per the issue: OAuth connect, then settings sync,
then marketplace.

## Step 1: PKCE OAuth connect (engine landed, cloud endpoints pending)

Landed:
- `src/Cloud/Token_Vault.php`: token bundle (access, refresh, expires_at,
  issuer) sealed with a sodium secretbox, key derived from AUTH_KEY via
  generichash. The stored blob carries a `wpmcp_v1:<key fingerprint>:` envelope
  (the Pro\Chat\Key_Vault convention), so a rotated AUTH_KEY is recognizable
  rather than looking like tampering.
- The refresh mutex is a real mutex: `INSERT IGNORE` against the options
  table's unique `option_name` key, plus a compare-and-set steal for a lock
  older than the TTL. `add_option()` is deliberately NOT used, because core
  reads the option first and then runs `INSERT ... ON DUPLICATE KEY UPDATE`,
  which succeeds for every concurrent writer.
- Loser semantics: a worker that loses the lock adopts the winner's bundle only
  when the winner has actually finished rotating (measured against the token
  the caller presented and had refused). While the winner is still in flight,
  the loser gets a retryable `cloud_refresh_race` error instead of the stale
  access token it came in with.
- `src/Cloud/Cloud_Oauth.php`: `begin()` builds a conforming OAuth 2.1
  authorization request (response_type, client_id, scope, S256 challenge via
  the existing `Auth\PKCE`, state, redirect_uri). `exchange()` POSTs
  `/wpmcp-cloud/v1/oauth/token` and seals the bundle; the pending state record
  is single-use (deleted on every exit path) and expires after 10 minutes.
  `refresh()` rotates the bundle through the vault mutex.
- `Cloud_Config::bearer_token()`: Cloud_Client presents the vault access token
  only when it is unexpired AND was issued by the cloud the site currently
  points at, falling back to the phase A API key otherwise.
  `Cloud_Config::set()` clears the vault, so reconnecting never ships the
  previous cloud's token to a new host.
- `Cloud_Client::request()`: a 401 triggers one `Cloud_Oauth::refresh()` and
  exactly one replay, gated on `Cloud_Config::refreshable_bundle()` (a bundle
  whose issuer still matches the configured cloud), and forwarding the
  presented credential only when it IS the vault's token.
- `src/Admin/Cloud_Callback_Page.php`: the redirect target the redirect_uri
  names, registered as a hidden submenu, calling `exchange()` on the inbound
  `code`/`state`. The OAuth state parameter is the CSRF token for this hop
  (the authorization server has never seen a WordPress nonce); `manage_options`
  is still required.
- `cloud-connect` upgraded: url with no key starts the PKCE flow and returns an
  authorize URL a human must open; url + key keeps the phase A path.

Review fixes in this branch:
- `Option_Guard` now refuses `wpmcp_cloud_key`, `wpmcp_cloud_oauth_state`,
  `wpmcp_cloud_token_bundle` and `wpmcp_cloud_token_refresh_lock` by exact name,
  plus `refresh_token` / `token_bundle` / `oauth_state` by pattern. Before this
  the registered `wpmcp/get-option` ability read the live PKCE code_verifier and
  state straight out of the database.
- `exchange()` writes the cloud URL it authenticated against via the new
  `Cloud_Config::set_url()` (which, unlike `set()`, does not clear the vault),
  so an OAuth-only connect leaves the site configured and `live_bundle()`'s
  issuer check passes. Both sides of that comparison go through one
  `Cloud_Config::normalize()`.
- `refresh()` refuses a bundle whose issuer is not the configured cloud: the
  refresh token must never go to a host `live_bundle()` already declined to
  send the weaker access token to.
- The refresh mutex no longer deadlocks on a lock row stamped in the future.
  A future timestamp made `time() - $held` negative, which is always below the
  TTL, pinning the loser branch forever and making the steal unreachable.
  Non-timestamp rows are treated the same way, and the compare-and-set steal now
  matches on the row's raw bytes so it can actually reclaim them.
- A losing worker whose winner released the lock in the gap retries the acquire
  once instead of reporting a race that is over.
- `with_refresh_lock()` ignores the presented token when the entry bundle is not
  itself live. With a phase A API key present, `bearer_token()` falls back to
  that key whenever the bundle expires, so the "did somebody rotate for me?"
  comparison was measuring the vault's token against an API key: trivially
  different, reported as a completed rotation, and the expired bundle never
  rotated at all.
- `Token_Vault::status()` returns missing / valid / key_rotated / corrupted, so
  the fingerprint envelope's stated purpose (rotation is distinguishable from
  tampering) is realized rather than computed and discarded.
- `begin()` validates the cloud URL (absolute, https, no embedded credentials)
  before persisting the pending record. Structural validation rather than
  `wp_http_validate_url()`, which resolves the host and refuses private ranges
  and so would lock out a self-hosted cloud.
- The token endpoint is form encoded per RFC 6749 section 4.1.3, not JSON.

Remaining:
- Cloud backend `/oauth/authorize` and `/oauth/token` endpoints. Everything
  plugin-side is written against the contract and covered by tests with faked
  HTTP, but there is no server answering it yet, so the API key stays as the
  working fallback.

## Step 2: settings sync over a curated allowlist (engine landed, transport pending)

Landed:
- `src/Cloud/Settings_Sync.php`: the allowlist is now built from the `::OPTION`
  constants of the classes that own the state, so export/apply touch options
  that are actually read: `wpmcp_governance_settings` (ability/domain/operation
  toggle maps), `wpmcp_tool_exposure_mode`, `wpmcp_mcp_exposure`,
  `wpmcp_skills_enabled`.
- Explicitly NOT syncable, and documented as such: the code-level safety gates
  (`wpmcp_enable_db_writes`, `wpmcp_allow_php_exec`, `wpmcp_wp_cli_allowlist`,
  `wpmcp_remote_media_allowed_hosts`, and friends). Those are `apply_filters()`
  hooks with no stored option behind them; they live in a mu-plugin or
  wp-config per site and a cloud payload cannot set them. An earlier revision of
  this file listed them as options, which made export return an empty payload
  and apply write inert rows.
- `export()` omits unset options, so applying an export never silently resets a
  target site's choices.
- `apply()` is gated on `Pro\Gate` (paid-cloud entitlement) and
  `manage_options`, re-filters against the allowlist, re-checks
  `Option_Guard::is_denylisted()`, and coerces every value to the exact shape
  its owner expects (enum / flag / governance toggle map) so a tampered blob
  cannot poison a known option with a wrong-typed value. Each write goes
  through `Safe_Mutation::run()` with object_type `option`, so a synced posture
  is undoable with rollback-operation; the operation ids are returned.
- `wpmcp/cloud-sync-settings` (read-only preview) and `wpmcp/cloud-apply-settings`
  (the write half, driving `Settings_Sync::apply()`) registered in
  `Plugin::register_cloud_abilities()`, with the ability manifest regenerated.

Review fixes in this branch:
- `apply()` MERGES the governance toggle map per dimension instead of replacing
  it. `coerce_governance()` always emits all three dimensions, so a payload
  carrying only domain toggles used to wipe the target's ability- and
  operation-level disables, contradicting `export()`'s own promise and
  re-enabling abilities an operator had turned off.
- `wpmcp_mcp_exposure` narrows only: sync may switch the master MCP kill switch
  OFF across a fleet, never back on. A remote party may take agent access away,
  never hand it back.
- The Safe_Mutation context no longer misattributes provenance. `tool_name` is
  `cloud-apply-settings` (the applier) rather than `cloud-sync-settings` (a
  read-only preview tool that never writes), `session_id` is threaded in from
  the caller like every other Safe_Mutation caller, and the hashed args are the
  coerced value that was actually stored.
- `checkbox_flag` calls `Skills_Module::sanitize()` instead of restating its
  truthiness table.

Remaining:
- Cloud `/settings` GET/PUT contract in Cloud_Client callers, and the push tool
  that drives it (both halves of the local engine are now reachable over MCP).
- Identity sync minus secrets (names/roles only).

## Step 3: marketplace (not started)

Browse and install only, after artifact supply exists. Installs land as
inactive drafts re-validated through validate-widget-spec and
validate-block-spec, the same gate cloud-pull-assets enforces. Publish and
moderation are cloud backend scope.

## Tests

`tests/pro/Cloud/CloudPhaseBTest.php`, 55 tests, covers the vault (round trip,
ciphertext is not plaintext, tampered blob, rotated key fingerprint, the
status() accessor, clear), the refresh mutex (rotation, loser-with-a-finished-
winner, loser-while-in-flight, stale lock steal, a future-stamped lock, a
non-timestamp lock row), credential selection (live token, expired bundle,
foreign issuer, reconnect clears the vault, and the phase A regression where an
expired bundle must still rotate while an API key is present), the OAuth flow
(authorize request shape, URL validation, form encoding, state mismatch,
expired state, exchange seals the bundle and configures the site, refresh
rotates, refresh refuses a foreign issuer), the production entry points
(cloud-connect starting the flow, the admin callback completing it, the
redirect_uri naming a page that exists, the apply ability), the option guard
refusing every cloud credential option through the real `Get_Option` handler,
and settings sync (allowlist contents, export, apply filtering, coercion,
governance merge, exposure narrowing, provenance, rollback, entitlement,
capability) plus the preview tool.

Known unrelated noise: the shared suite has pre-existing order-dependent
pollution. `BrandKitsTest` and several Elementor structural tests fail on this
branch's merge base too, and the same combined filter fails a different subset
of tests on the base commit than it does here.

## Definition of done tracking

- [x] PKCE OAuth connect end to end, plugin-side: vault, mutex,
      authorize/exchange/refresh, the 401 retry, the admin callback page and the
      cloud-connect upgrade all landed and tested. The cloud's own
      `/oauth/authorize` and `/oauth/token` endpoints are backend scope and do
      not exist yet, so the flow cannot be exercised against a live server.
- [x] Settings sync over the curated allowlist, re-filtered on apply, reachable
      over MCP through `cloud-sync-settings` (read) and `cloud-apply-settings`
      (write). Identity sync minus secrets and the `/settings` transport are
      still open.
- [ ] Marketplace browse/install as inactive validated drafts (not started)
- [x] Settings sync gated as the paid-cloud entitlement (`Pro\Gate` plus
      `manage_options` enforced in `Settings_Sync::apply()`)
