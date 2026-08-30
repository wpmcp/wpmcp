# WIP: issue #141, phase 1 of #135 (cloud phase B)

Encrypted cloud credential vault and rotation-safe token lifecycle. No tool
surface changes; phase A tools keep working through the `Cloud_Config` facade.

## Landed in this branch

- `src/Cloud/Cloud_Credentials.php`: single-option encrypted vault. Seals the
  full credential set (base_url, api_key, access_token, refresh_token,
  access_expires_at, client_id) with libsodium secretbox under a key derived
  from `wp_salt('auth')`, mirroring `Tools\Media\Stock\Stock_Key_Store`.
  Corrupted or undecryptable ciphertext reads as an empty set. Transparent
  one-time migration of the plaintext `wpmcp_cloud_url` / `wpmcp_cloud_key`
  options on first read, which deletes the plaintext copies only after reading
  the sealed blob back and confirming it decrypts to the same values, so a
  write that does not land cannot destroy a connected site's only credentials.
  Reads are memoized per request, keyed on the raw sealed blob so any write is
  picked up automatically; `all(true)` forces a database re-read past the
  options object cache for the refresher's post-lock check.
- `src/Cloud/Cloud_Config.php`: thin facade over the vault. `set()` REPLACES
  the credential set (a re-run of `cloud-connect` may target a different cloud
  or account, so the previous connection's token bundle must not survive) and
  clears the unhealthy marker. `is_configured()` accepts a cloud URL plus
  either an API key or a token bundle, so an OAuth-only connection is usable.
- `src/Cloud/Token_Refresher.php`: rotation-safe refresh engine with
  injectable lock and transport seams. Implements: an install-scoped GET_LOCK
  mutex (the name is hashed from the table prefix and home URL, so installs
  sharing a MySQL server do not serialize on each other); a forced,
  cache-bypassing re-read of the bundle after acquiring the lock;
  bail-without-presenting the refresh token on lock timeout, returning the
  stored access token only when it is genuinely fresh; proceed unlocked when
  GET_LOCK is unusable (NULL) rather than never refreshing again; race-loser
  detection on auth rejection (rotated stored token or fresh access token
  counts as success); an untouched bundle on every non-definite failure
  (network error, 5xx, 429/403/404, and 2xx bodies that carry no usable
  grant); merge-onto-freshest on success. The refresh grant is form-encoded
  and only an OAuth `invalid_grant` (or a bare 401) counts as a revocation, so
  `invalid_client` / `invalid_request` / `unsupported_grant_type` cannot
  permanently disconnect a site. Only an un-raced rejection sets
  `wpmcp_cloud_unhealthy`, and while that marker is inside its backoff window
  the engine stops re-presenting the dead token.
- `src/Cloud/Cloud_Client.php`: auth resolution order is fresh vault token,
  then `Token_Refresher`, then API-key fallback. Owns `TOKEN_PATH` alongside
  `API_BASE` so it stays the only place that knows where the backend answers.
- `src/Tools/Cloud/Cloud_Status.php`: reports `token_status` (`ok` /
  `rejected`) so a revoked refresh token is visible to an MCP client.
- Tests: `tests/pro/Cloud/CloudCredentialsTest.php` (crypto round-trip with no
  plaintext in the options table, corrupted ciphertext, migration with
  deletion, migration refusing to delete when the sealed write does not land,
  forced re-read seeing another process's write),
  `tests/pro/Cloud/TokenRefresherTest.php` (lock winner, both race-loser
  shapes, lock-timeout bail in both stale and refreshed states, unusable lock,
  three transient shapes leaving the bundle untouched, un-raced rejection,
  backoff and its expiry, plus the real transport's status classification and
  wire format), `tests/pro/Cloud/CloudAuthResolutionTest.php` (fresh token,
  stale-then-refreshed, API-key fallback, token-only connection, reconnect
  dropping the previous tenant's bundle).

## Remaining work

- Confirm the token endpoint path against the phase 2 PKCE connect flow.
  `Cloud_Client::TOKEN_PATH` mirrors the plugin's own `/wp-json/wpmcp/v1/oauth/token`
  route, which is what the phase A WordPress-backed cloud exposes.
- Client authentication on the refresh grant: the phase 2 connect flow decides
  whether the site is a public PKCE client (no secret, current assumption) or a
  confidential one, at which point the grant gains `client_secret`.
- Coverage check against the 90.3 floor once phase 2 lands the connect flow
  that actually writes a token bundle.
