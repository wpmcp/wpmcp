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
  options on first read, deleting the plaintext copies.
- `src/Cloud/Cloud_Config.php`: now a thin facade over the vault, so
  `cloud-connect` and the other phase A tools are untouched.
- `src/Cloud/Token_Refresher.php`: rotation-safe refresh engine with
  injectable lock and transport seams. Implements: GET_LOCK mutex,
  double-checked re-read after acquiring the lock, bail-without-presenting the
  refresh token on lock timeout, race-loser detection on auth rejection
  (rotated stored token or fresh access token counts as success), untouched
  bundle on network errors and 5xx, merge-onto-freshest on success. Only an
  un-raced auth rejection marks the connection unhealthy
  (`wpmcp_cloud_unhealthy`).
- `src/Cloud/Cloud_Client.php`: auth resolution order is fresh vault token,
  then `Token_Refresher`, then API-key fallback.
- `tests/pro/Cloud/CloudCredentialsTest.php`: crypto round-trip (plaintext
  never in the options table), corrupted ciphertext, plaintext migration with
  deletion, merge semantics.

## Remaining work

- Token_Refresher branch tests: winner, race loser via rotated stored token,
  race loser via fresh access token, lock-timeout bail, 5xx untouched bundle,
  genuine auth rejection marks unhealthy, success merge.
- Client auth-resolution tests: fresh token, stale-then-refreshed token,
  API-key fallback.
- Confirm the token endpoint path with the phase 2 PKCE connect flow
  (`/oauth/token` is the placeholder; see the TODO in `Token_Refresher`).
- Surface the `wpmcp_cloud_unhealthy` marker in `cloud-status` output.
- Coverage check against the 90.3 floor.
