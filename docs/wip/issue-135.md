# Issue #135: EMCP parity, cloud phase B (OAuth connect, settings sync, marketplace)

Status: WIP. This branch lands the phase B skeleton behind the existing
Cloud_Client seam. Sequenced per the issue: OAuth connect, then settings sync,
then marketplace.

## Step 1: PKCE OAuth connect (partially landed)

Landed:
- `src/Cloud/Token_Vault.php`: token bundle (access, refresh, expires_at)
  encrypted at rest with sodium secretbox, key derived from AUTH_KEY via
  generichash; `with_refresh_lock()` implements the rotation-race mutex
  (lock via atomic add_option, re-read under the lock, losers adopt the
  winner's bundle as success).
- `src/Cloud/Cloud_Oauth.php`: begin() generates S256 verifier/challenge and
  the authorize URL; exchange() validates state and is stubbed pending the
  cloud /oauth/token endpoint.
- `Cloud_Config::bearer_token()`: Cloud_Client now presents the vault access
  token when present, falling back to the phase A API key, so both connect
  modes work through one request path.

Remaining:
- Cloud backend /oauth/authorize and /oauth/token endpoints (reuse Auth
  primitives: PKCE, Code_Store, Token_Grant patterns).
- Admin callback page (wpmcp-cloud-callback) completing exchange().
- cloud-connect tool upgrade: start OAuth flow instead of accepting a raw key;
  keep API key as fallback until backend ships.
- Automatic refresh on 401 through Token_Vault::with_refresh_lock().

## Step 2: settings sync over a curated allowlist (partially landed)

Landed:
- `src/Cloud/Settings_Sync.php`: hand-curated allowlist of governance options
  (safety toggles, tool/domain/operation enablement, exposure mode, wp-cli
  allowlist); identities and any secret-bearing options are excluded by
  construction. export() omits unset options; apply() re-filters the incoming
  payload against the allowlist so tampered blobs cannot write arbitrary
  options.
- `wpmcp/cloud-sync-settings` tool (read-only preview) registered in
  Plugin::register_cloud_abilities().

Remaining:
- Cloud /settings GET/PUT contract in Cloud_Client callers.
- Push and apply tools, apply gated as the paid-cloud entitlement (Pro\Gate).
- Identity sync minus secrets (names/roles only).

## Step 3: marketplace (not started)

Browse and install only, after artifact supply exists. Installs land as
inactive drafts re-validated through validate-widget-spec and
validate-block-spec, the same gate cloud-pull-assets enforces. Publish and
moderation are cloud backend scope.

## Definition of done tracking

- [ ] PKCE OAuth connect end to end (vault + mutex landed; endpoints, callback, tool upgrade pending)
- [ ] Settings sync push/apply (allowlist engine + preview tool landed; wire + gating pending)
- [ ] Marketplace browse/install as inactive validated drafts
- [ ] Settings sync gated as the paid-cloud entitlement
