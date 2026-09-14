# WIP: issue #141, phase 1 of #135 (cloud phase B)

Encrypted cloud credential vault and rotation-safe token lifecycle. The tool
surface is unchanged except for one new read-only field on `cloud-status`
(`token_status`); phase A tools keep working through the `Cloud_Config` facade.

## Landed in this branch

- `src/Cloud/Cloud_Credentials.php`: single-option encrypted vault. Seals the
  full credential set (base_url, api_key, access_token, refresh_token,
  access_expires_at, client_id, client_secret) with libsodium secretbox under a
  key derived from `wp_salt('auth')`, mirroring
  `Tools\Media\Stock\Stock_Key_Store`. Corrupted or undecryptable ciphertext
  reads as an empty set. `write()` reads the sealed blob back before reporting
  success, and every successful write deletes the phase A plaintext
  `wpmcp_cloud_url` / `wpmcp_cloud_key` options: the read-path migration alone
  was not enough, because `cloud-connect` writes the vault before anything
  reads it, so a legacy site that reconnected kept its plaintext API key
  forever. A write that does not land leaves the plaintext in place, since it
  is then the site's only copy. Reads are memoized per request, keyed on the
  raw sealed blob so any write is picked up automatically; `all(true)` forces a
  database re-read past both the options object cache and its `notoptions`
  entry, which the refresher's post-lock double-check depends on.
- `src/Cloud/Cloud_Config.php`: thin facade over the vault. `set()` REPLACES
  the credential set (a re-run of `cloud-connect` may target a different cloud
  or account, so the previous connection's token bundle must not survive).
  `is_configured()` accepts a cloud URL plus either an API key or a token
  bundle, so an OAuth-only connection is usable. Clearing the refresh health
  state now belongs to `Cloud_Credentials::replace()`, the credential-set
  primitive the phase 2 connect flow will call directly, so a new bundle can
  never inherit the previous one's backoff.
- `src/Cloud/Token_Refresher.php`: rotation-safe refresh engine with injectable
  lock and transport seams. Implements: an install-scoped GET_LOCK mutex (the
  name is hashed from the table prefix and home URL, so installs sharing a
  MySQL server do not serialize on each other); a forced, cache-bypassing
  re-read of the bundle after acquiring the lock; bail-without-presenting the
  refresh token on lock timeout, returning the stored access token only when it
  is genuinely fresh; proceed unlocked when GET_LOCK is unusable (NULL) rather
  than never refreshing again; race-loser detection on auth rejection; an
  untouched bundle on every non-definite failure; merge-onto-freshest on
  success, with an empty rotated `refresh_token` coalescing to the presented
  one rather than wiping it, and a failed persist reported as a failure rather
  than handing back a token nobody stored. Two backoffs, for two different
  failures: `wpmcp_cloud_unhealthy` (900s) after an un-raced rejection, and a
  short `wpmcp_cloud_refresh_retry` transient (60s, fingerprinted on the
  refresh token it failed for) after a transient failure, so a cloud that is
  down does not add a 5s lock wait plus a 20s HTTP timeout to every single
  cloud tool call.
- `src/Cloud/Cloud_Client.php`: auth resolution order is fresh vault token,
  then `Token_Refresher`, then API-key fallback, and a `cloud_not_authenticated`
  WP_Error naming `cloud-connect` when none of the three resolves, instead of
  an empty `Authorization: Bearer` and an opaque HTTP 401. Owns `TOKEN_PATH`
  alongside `API_BASE`; both are relative to the same base
  (`Cloud_Config::base_url()` is the cloud's REST root), so neither carries a
  `/wp-json` prefix of its own.
- `src/Tools/Cloud/Cloud_Connect.php`: the credentials still have to be stored
  before the `/me` probe can use them, but a failed probe now restores the
  previous credential set (or clears the partial connection when there was
  none). With a refresh token in the same option, a mistyped `cloud-connect`
  would otherwise destroy something the operator cannot retype.
- `src/Tools/Cloud/Cloud_Status.php`: `token_status` is `ok` / `rejected` /
  `unreadable` (a sealed vault that no longer decrypts, i.e. rotated salts) /
  `none` (never connected), so a salt rotation does not present as an ordinary
  disconnect.
- `src/Activator.php`: runs the plaintext import on activation, so on a
  normally updated site the migration write does not happen on the read-only
  `cloud-status` path. The lazy import stays as the backstop for sites whose
  activation hook never fires, and a failed import is not retried for the rest
  of the request.
- Tests: `tests/pro/Cloud/CloudCredentialsTest.php` (crypto round-trip with no
  plaintext in the options table, corrupted ciphertext, migration with
  deletion, migration refusing to delete when the sealed write does not land,
  connect-over-legacy deleting the plaintext options, `write()` reporting a
  failed seal, `replace()` clearing a stale health marker, forced re-read past
  a `notoptions` miss), `tests/pro/Cloud/TokenRefresherTest.php` (lock winner,
  both race-loser shapes, lock-timeout bail in both states, unusable lock,
  three transient shapes leaving the bundle untouched, un-raced rejection,
  both backoffs and their expiry, empty rotated refresh token, failed persist,
  client_secret present and absent, plus the real transport's status
  classification and wire format), `tests/pro/Cloud/CloudAuthResolutionTest.php`
  (fresh token, stale-then-refreshed, API-key fallback, token-only connection,
  reconnect dropping the previous tenant's bundle, and the unauthenticated
  error instead of an empty bearer), `tests/pro/Cloud/CloudStatusTest.php` (all
  four `token_status` values), `tests/pro/Cloud/CloudConnectTest.php` (failed
  probe restores the previous set, failed first connect leaves nothing behind).

## Remaining work

- Client authentication on the refresh grant is only half resolved in code. The
  refresher sends `client_secret` when the vault holds one and omits the field
  entirely when it does not, but nothing populates it yet: the phase A cloud
  runs this plugin's own token endpoint, and `Auth\Token_Grant::exchange()`
  verifies `client_secret` through `Auth\Client_Store::verify_secret()` before
  it dispatches to the refresh branch, with no public-client mode in
  `Client_Store::create()`. So until the phase 2 connect flow stores a client
  secret (or registers this site as a public client and the endpoint grows a
  public-client mode), a refresh against that endpoint returns `invalid_client`,
  which is deliberately classified transient and therefore fails softly.
- `Cloud_Client::TOKEN_PATH` is now `/wpmcp/v1/oauth/token`, relative to the
  same REST root as `API_BASE`. Confirm against the phase 2 PKCE connect flow
  that the cloud's REST root is what operators type into `cloud-connect`.
- Coverage check against the 90.3 floor once phase 2 lands the connect flow
  that actually writes a token bundle.
