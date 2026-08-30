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
- `Cloud_Client::request()`: a 401 with a bundle present triggers one
  `Cloud_Oauth::refresh()` and exactly one replay.

Remaining:
- Cloud backend `/oauth/authorize` and `/oauth/token` endpoints. Everything
  plugin-side is written against the contract and covered by tests with faked
  HTTP, but there is no server answering it yet.
- Admin callback page (`wpmcp-cloud-callback`) that calls `exchange()`; the
  redirect_uri names it but no `add_submenu_page()` registers it yet.
- cloud-connect tool upgrade: start the OAuth flow instead of accepting a raw
  key, keeping the API key as the fallback until the backend ships.

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
- `wpmcp/cloud-sync-settings` tool (read-only preview) registered in
  `Plugin::register_cloud_abilities()`, with the ability manifest regenerated.

Remaining:
- Cloud `/settings` GET/PUT contract in Cloud_Client callers, and the push tool
  that drives it.
- An apply tool exposing `Settings_Sync::apply()` over MCP (the engine and its
  gating are done; only the ability registration is missing).
- Identity sync minus secrets (names/roles only).

## Step 3: marketplace (not started)

Browse and install only, after artifact supply exists. Installs land as
inactive drafts re-validated through validate-widget-spec and
validate-block-spec, the same gate cloud-pull-assets enforces. Publish and
moderation are cloud backend scope.

## Tests

`tests/pro/Cloud/CloudPhaseBTest.php` covers the vault (round trip, ciphertext
is not plaintext, tampered blob, rotated key fingerprint, clear), the refresh
mutex (rotation, loser-with-a-finished-winner, loser-while-in-flight, stale
lock steal), credential selection (live token, expired bundle, foreign issuer,
reconnect clears the vault), the OAuth flow (authorize request shape, state
mismatch, expired state, exchange seals the bundle, refresh rotates), and
settings sync (allowlist contents, export, apply filtering/coercion/rollback/
entitlement/capability) plus the preview tool.

## Definition of done tracking

- [ ] PKCE OAuth connect end to end (vault, mutex, authorize/exchange/refresh
      and the 401 retry landed and tested; cloud endpoints, admin callback page
      and the cloud-connect tool upgrade pending)
- [ ] Settings sync push/apply (allowlist engine, validation, Safe_Mutation and
      preview tool landed; `/settings` transport and the apply ability pending)
- [ ] Marketplace browse/install as inactive validated drafts
- [x] Settings sync gated as the paid-cloud entitlement (`Pro\Gate` plus
      `manage_options` enforced in `Settings_Sync::apply()`)
