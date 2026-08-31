<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provisions and revokes the site's WP MCP Gateway credential (issue #130).
 *
 * The gateway credential is a self-issued OAuth refresh token, bound to a
 * named Identity and to an administrator, riding a stable "WP MCP Gateway"
 * confidential client that is registered idempotently through Client_Store
 * (whose registration fingerprint makes repeated provisioning reuse the same
 * client row instead of accumulating dead ones). The plaintext material
 * exists exactly once, at provision time: it is handed to the caller for a
 * once-only display and a single upload through Cloud_Client, then only
 * hashes remain on the site.
 *
 * THE BLAST RADIUS IS THE MCP SURFACE, NOT THE WHOLE SITE. Bearer_Auth
 * resolves tokens on WordPress's global determine_current_user filter, so
 * without a second gate this credential would be a plain administrator on
 * /wp/v2/users?context=edit, /wp/v2/plugins, admin-ajax and every other
 * non-MCP entry point, and the identity allowlist (which only narrows
 * abilities inside Registrar::is_permitted) would be advertising a
 * restriction it does not enforce. filter_bearer_token_accepted() refuses
 * the token outright anywhere except the MCP and OAuth routes, and it fails
 * CLOSED: a request whose REST route cannot be determined is not the
 * gateway's surface, because the gateway only ever speaks MCP.
 *
 * THE KILL SWITCH IS TOTAL AND LOCAL. Revocation goes through
 * Refresh_Token_Store::revoke_chain() on the chain_id recorded at provision
 * time, which cascades into Token_Store::revoke_chain(), so the access
 * tokens the gateway already minted die in the same call. That cascade is
 * the whole point: sweeping only the refresh tokens would leave a working
 * bearer token in the gateway's hands for the rest of its hour, which is
 * not a kill switch. Nothing here touches the network, so the switch works
 * with the cloud unreachable; cloud-side deletion is best-effort cleanup,
 * not the mechanism of revocation. The switch is also reachable without the
 * pro ability surface: register() adds a `wp wpmcp gateway-revoke` WP-CLI
 * command, so a lapsed licence (which drops the pro-tier ability) cannot
 * strand a live ten-year credential with no way to kill it.
 *
 * Killing the chain first is also what keeps provisioning idempotent:
 * Client_Store::find_reusable() refuses to recycle a client row that still
 * holds tokens, so a re-provision that left live tokens behind would mint a
 * fresh client row every time and walk the store toward MAX_CLIENTS. That
 * ordering is why provision() pre-checks the registration cap: destroying
 * the live credential and only then discovering the store is full would
 * brick a working gateway.
 *
 * THE IDENTITY BINDING IS SERVER-SIDE STATE FIRST AND FAILS CLOSED. The
 * request's authenticated client_id is looked up against the client_id in
 * this site's own credential record, which is the authoritative binding.
 * The scope string is the fallback, and only ever for a token that is
 * already known to be a gateway token, because the bookkeeping option is
 * mutable: losing it must not silently promote a live gateway token from
 * identity-scoped to the connecting admin's full ability grid. When neither
 * resolves a usable name the request gets UNBOUND_IDENTITY, a sentinel that
 * Identity_Store cannot match, which Governance::is_within_identity_scope()
 * already default-denies. A forged gateway scope on some other client is
 * harmless in both directions: an identity only ever narrows what
 * Registrar::is_permitted() allows, it never grants.
 *
 * Once the identity is active, Governance::is_within_identity_scope()
 * narrows every ability to that identity's allowlist inside
 * Registrar::is_permitted(), and the decision lands in
 * Governance_Audit_Log with the identity name recorded, so the gateway
 * token does not inherit the connecting admin's full enabled-tool grid.
 */
class Gateway_Credential
{
    /** Option holding the provisioned credential's bookkeeping (no secrets). */
    public const OPTION = 'wpmcp_gateway_credential';

    /** Stable client name; part of the Client_Store dedup fingerprint. */
    public const CLIENT_NAME = 'WP MCP Gateway';

    /** Registrar key namespacing the fingerprint away from DCR clients. */
    public const REGISTRAR_KEY = 'wpmcp-gateway';

    /** Scope prefix carrying the bound identity name. */
    public const SCOPE_PREFIX = 'wpmcp:gateway identity:';

    /**
     * The identity a gateway token gets when nothing resolves a real one.
     *
     * Deliberately a name Identity_Store::create() cannot produce a match
     * for, so Governance::is_within_identity_scope() returns false for every
     * ability: a gateway token with broken bookkeeping is denied, not
     * promoted to unrestricted.
     */
    public const UNBOUND_IDENTITY = 'wpmcp-gateway-unbound';

    /**
     * Gateway refresh token lifetime: ten years, filterable via
     * wpmcp_gateway_refresh_ttl.
     *
     * This is a machine credential for an always-on proxy, not a user
     * session, and a gateway that goes quiet for a month must not come back
     * to a dead credential. Refresh_Token_Store's ordinary 30-day ceiling is
     * right for sessions and wrong here, and the site-wide
     * wpmcp_oauth_refresh_ttl filter is the wrong instrument because it
     * would lengthen every user session too, so the lifetime rides on the
     * record itself.
     */
    public const TTL_SECONDS = 315360000; // 10 years.

    /**
     * Wire the server-side identity resolution and the surface restriction
     * onto the gateway's bearer tokens, plus the licence-independent kill
     * switch.
     */
    public static function register(): void
    {
        add_filter('wpmcp_current_identity', [self::class, 'filter_current_identity']);
        add_filter('wpmcp_bearer_token_accepted', [self::class, 'filter_bearer_token_accepted'], 10, 2);

        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('wpmcp gateway-revoke', [self::class, 'cli_revoke']);
        }
    }

    /**
     * Provision (or re-provision) the gateway credential, bound to $user_id
     * and to a named Identity.
     *
     * Order matters and is deliberate: everything that can refuse refuses
     * BEFORE anything is minted or destroyed, and that includes the
     * registration cap, because the destructive part (killing the previous
     * chain, which is what frees the client row for reuse) runs before
     * Client_Store::create(). Every refusal is audited, including the
     * security-relevant one: a non-administrator trying to mint a gateway
     * credential.
     *
     * @param int    $user_id   The administrator the credential acts as.
     * @param string $identity  Name of an existing Identity_Store identity.
     * @param bool   $consented The disclosed cloud-connect consent checkbox,
     *                          default off at every caller.
     * @param bool   $replace   Destroy a credential that is already live.
     *                          Default off: re-provisioning is irreversible
     *                          (the old secret is unrecoverable and the
     *                          gateway stops working until the new one is
     *                          installed), so it is confirm-gated like every
     *                          other irrecoverable write in this plugin.
     * @return array{client_id: string, client_secret: string, refresh_token: string, scope: string, chain_id: string}|\WP_Error
     *               Plaintext material, returned exactly once. The caller
     *               owns the once-only display and the upload().
     */
    public static function provision(int $user_id, string $identity, bool $consented = false, bool $replace = false)
    {
        $identity = trim($identity);

        if (! $consented) {
            return self::refuse(
                $identity,
                'gateway_consent_required',
                'Provisioning a gateway credential requires explicit consent: it mints a long-lived credential that lets WP MCP Cloud act on this site.'
            );
        }

        if (! current_user_can('manage_options')) {
            return self::refuse($identity, 'gateway_forbidden', 'Only an administrator can provision a gateway credential.');
        }

        // A credential the site cannot honour is worse than none: with OAuth
        // off, Endpoints::register() no-ops (there is no /oauth/token to
        // refresh against) and Bearer_Auth::resolve() returns before it ever
        // looks at a header, so the identity binding never engages either.
        if (! OAuth_Config::is_enabled()) {
            return self::refuse(
                $identity,
                'gateway_oauth_disabled',
                'The OAuth 2.1 subsystem is disabled on this site, so a gateway credential could never authenticate. Enable it first (WPMCP_OAUTH_ENABLED or the wpmcp_oauth_enabled filter).'
            );
        }

        if ($user_id <= 0 || false === get_userdata($user_id)) {
            return self::refuse($identity, 'gateway_unknown_user', 'The user the gateway credential would be bound to does not exist.');
        }

        if (! user_can($user_id, 'manage_options')) {
            return self::refuse($identity, 'gateway_user_not_admin', 'A gateway credential can only be bound to an administrator.');
        }

        if ('' === $identity || null === Identity_Store::get($identity)) {
            return self::refuse(
                $identity,
                'gateway_unknown_identity',
                'The gateway credential must be bound to an existing identity; create it first with identity-create.'
            );
        }

        $existing = self::raw_record();

        if (! $replace && self::is_provisioned()) {
            return self::refuse(
                $identity,
                'gateway_already_provisioned',
                'This site already has a gateway credential. Re-provisioning destroys it irreversibly (the old secret cannot be recovered and the gateway stops working until the new one is installed); pass replace=true to do it anyway.'
            );
        }

        // The cap check has to happen HERE, before revoke_locally(), because
        // the destructive step runs first by design. Refusing after it would
        // leave the site with no working gateway and no way back.
        $reusable = null !== $existing && null !== Client_Store::get((string) ($existing['client_id'] ?? ''));
        if (! $reusable && Client_Store::count() >= Client_Store::max_clients()) {
            return self::refuse($identity, 'gateway_client_cap', 'OAuth client registration cap reached.');
        }

        $killed = self::revoke_locally();
        if ($killed > 0) {
            self::audit('revoke', (string) ($existing['identity'] ?? ''), true, 'superseded_by_reprovision');
        }

        try {
            $client = Client_Store::create([ self::CLIENT_NAME ], [], self::REGISTRAR_KEY);
        } catch (\RuntimeException $e) {
            delete_option(self::OPTION);
            return self::refuse($identity, 'gateway_client_cap', $e->getMessage());
        }

        $chain_id      = Refresh_Token_Store::new_chain_id();
        $scope         = self::scope_for($identity);
        $refresh_token = Refresh_Token_Store::issue(
            $client['client_id'],
            $user_id,
            $scope,
            $chain_id,
            self::ttl()
        );

        // Provisioning reuses the client row by design, so client_id alone
        // cannot tell two provisions apart. The chain_id can, and it is not
        // a secret (it names a grant, it does not authenticate one), so it
        // travels back with the payload purely as a version stamp for
        // upload()'s staleness check.
        update_option(self::OPTION, [
            'client_id'      => $client['client_id'],
            'user_id'        => $user_id,
            'identity'       => $identity,
            'chain_id'       => $chain_id,
            'provisioned_at' => time(),
            'uploaded_at'    => 0,
        ]);

        self::audit('provision', $identity, true);

        return [
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'refresh_token' => $refresh_token,
            'scope'         => $scope,
            'chain_id'      => $chain_id,
        ];
    }

    /**
     * Upload the freshly provisioned credential to the cloud, once.
     *
     * Refuses over plaintext HTTP: this payload is a long-lived,
     * admin-bound site credential, which is a different class of secret
     * from the /me and /assets traffic, and Cloud_Config::set() does not
     * restrict the scheme. It also goes out with redirection disabled and
     * TLS verification forced: WordPress follows up to five redirects by
     * default and the Requests library re-sends the POST body on a 30x, so
     * an http Location from the cloud host would replay the client secret
     * and the ten-year refresh token in cleartext. Also refuses a
     * credential that is not the one currently on record, or one that has
     * already been uploaded, so a stale array cannot stamp a newer
     * provision's bookkeeping.
     *
     * @param array $credential The provision() return value.
     * @return true|\WP_Error
     */
    public static function upload(Cloud_Client $client, array $credential)
    {
        $record = self::record();
        if (null === $record) {
            return new \WP_Error('gateway_not_provisioned', 'There is no gateway credential to upload.');
        }

        $same_client = (string) ($credential['client_id'] ?? '') === (string) $record['client_id'];
        $same_chain  = (string) ($credential['chain_id'] ?? '') === (string) ($record['chain_id'] ?? '');
        if (! $same_client || ! $same_chain) {
            return new \WP_Error('gateway_stale_credential', 'That credential is not the one currently provisioned for this site.');
        }

        if ((int) ($record['uploaded_at'] ?? 0) > 0) {
            return new \WP_Error('gateway_already_uploaded', 'This gateway credential has already been uploaded; re-provision to issue a new one.');
        }

        // Distinct from the scheme refusal below: with no cloud connected at
        // all, base_url() is '' and a bare scheme check would report a
        // non-https cloud url, which sends the operator looking for the
        // wrong problem.
        if (! Cloud_Config::is_configured()) {
            return new \WP_Error(
                'gateway_cloud_not_configured',
                'This site is not connected to WP MCP Cloud; connect it with cloud-connect before uploading a gateway credential.'
            );
        }

        if (! self::cloud_url_is_secure()) {
            return new \WP_Error(
                'gateway_insecure_cloud_url',
                'Refusing to send a gateway credential to a non-https cloud url.'
            );
        }

        // TODO(#130): finalize the /wpmcp-cloud/v1 gateway contract (POST
        // /gateway/credential) with the backend; for now this is the only
        // seam that knows the wire shape, mirroring Cloud_Client's rule.
        $result = $client->post(
            '/gateway/credential',
            [
                'client_id'     => $credential['client_id'],
                'client_secret' => $credential['client_secret'],
                'refresh_token' => $credential['refresh_token'],
                'scope'         => $credential['scope'],
                'site_url'      => home_url('/'),
            ],
            [
                'redirection' => 0,
                'sslverify'   => true,
            ]
        );

        if (is_wp_error($result)) {
            return $result;
        }

        $record['uploaded_at'] = time();
        update_option(self::OPTION, $record);

        return true;
    }

    /**
     * Locally-first revoke: the credential is dead the moment this returns,
     * regardless of cloud reachability. Cloud-side cleanup is the caller's
     * best-effort follow-up (TODO(#130): DELETE /gateway/credential).
     *
     * The capability is re-checked here rather than trusted from the tool
     * layer, exactly as provision() does. The one caller that legitimately
     * has no WordPress user is WP-CLI, which is already root-equivalent on
     * the box, so it passes $trusted = true.
     *
     * @param bool $trusted Skip the capability check (WP-CLI only).
     * @return int|\WP_Error Number of token records actually killed.
     */
    public static function revoke(bool $trusted = false)
    {
        if (! $trusted && ! current_user_can('manage_options')) {
            return new \WP_Error('gateway_forbidden', 'Only an administrator can revoke the gateway credential.');
        }

        // Read the raw option and kill the chain BEFORE any self-healing
        // delete: pruning first would drop the bookkeeping that names the
        // chain, and the access tokens would go on authenticating as the
        // bound administrator for the rest of their TTL while this reported
        // that there had been nothing to revoke.
        $record = self::raw_record();
        $killed = self::revoke_locally();
        delete_option(self::OPTION);

        if (null !== $record) {
            self::audit('revoke', (string) ($record['identity'] ?? ''), true, 'killed:' . $killed);
        }

        return $killed;
    }

    /**
     * The WP-CLI kill switch: `wp wpmcp gateway-revoke`.
     *
     * Exists because cloud-gateway-revoke is a pro-tier ability, and
     * Registrar::register() drops pro abilities the moment a licence lapses.
     * A ten-year credential whose only off switch expires with the
     * subscription is not an acceptable failure mode, so the switch also
     * lives here, outside the pro gate and outside the MCP surface.
     */
    public static function cli_revoke(array $args = [], array $assoc = []): void
    {
        $killed = self::revoke(true);
        \WP_CLI::success(sprintf('Gateway credential revoked (%d token records killed).', (int) $killed));
    }

    /**
     * Bookkeeping record (never contains secrets), or null when there is no
     * usable credential.
     *
     * A PURE READ. Oauth_Gc's orphan sweep (Client_Store::gc) reaps a client
     * row that holds no tokens past the grace window, so the option can
     * outlive the client it names; reporting a credential that cannot
     * authenticate anything is worse than reporting none, so this returns
     * null for one. Clearing it is prune()'s job, not a read's: this method
     * runs on every wpmcp_current_identity evaluation, which is once per
     * Registrar::is_permitted() call, and a read that writes to the options
     * table from inside the enforcement path is both a surprise and the
     * ordering hazard that used to make revoke() a no-op.
     *
     * @return array|null
     */
    public static function record(): ?array
    {
        $record = self::raw_record();
        if (null === $record) {
            return null;
        }

        if (null === Client_Store::get((string) $record['client_id'])) {
            return null;
        }

        return $record;
    }

    /**
     * Clear bookkeeping that names a client row which no longer exists,
     * killing anything still bound to it on the way out. The explicit
     * write half of record()'s old self-heal; called from the status and
     * provisioning paths, never from a read.
     */
    public static function prune(): void
    {
        $record = self::raw_record();
        if (null === $record) {
            return;
        }

        if (null !== Client_Store::get((string) $record['client_id'])) {
            return;
        }

        self::revoke_locally();
        delete_option(self::OPTION);
    }

    public static function is_provisioned(): bool
    {
        return null !== self::record();
    }

    /**
     * Refuse a validated bearer token that belongs to the gateway when the
     * request is not on the MCP transport surface, for the
     * wpmcp_bearer_token_accepted filter in Bearer_Auth::resolve().
     *
     * Without this the identity allowlist is a claim rather than a control:
     * it narrows abilities inside Registrar::is_permitted(), and nothing
     * else on the site goes through Registrar. Fails closed, because a
     * request whose REST route cannot be determined is by definition not one
     * of the two route families the gateway speaks.
     *
     * @param bool  $accepted Whether the token authenticates this request.
     * @param array $record   The validated token record.
     */
    public static function filter_bearer_token_accepted($accepted, $record = []): bool
    {
        if (! $accepted) {
            return false;
        }

        if (! is_array($record) || ! self::is_gateway_token($record)) {
            return true;
        }

        return self::request_is_gateway_surface();
    }

    /**
     * Resolve the identity for the current request, for the
     * wpmcp_current_identity filter Identity_Context reads.
     *
     * Authoritative source is the authenticated client_id matched against
     * the stored credential record. The token's own scope is the fallback,
     * used only once the token is already known to be a gateway token, so
     * losing the option cannot fail open. Returns $identity unchanged (so
     * other listeners keep working) whenever this request is not the
     * gateway's.
     *
     * @param string|null $identity Whatever a higher-priority listener resolved.
     * @return string|null
     */
    public static function filter_current_identity($identity)
    {
        $token = Bearer_Auth::current_token();
        if (! is_array($token) || ! self::is_gateway_token($token)) {
            return $identity;
        }

        $record = self::raw_record();
        $bound  = '';
        if (null !== $record && (string) $record['client_id'] === (string) ($token['client_id'] ?? '')) {
            $bound = (string) ($record['identity'] ?? '');
        }

        if ('' === $bound) {
            $bound = (string) (self::identity_from_scope((string) ($token['scope'] ?? '')) ?? '');
        }

        return '' !== $bound ? $bound : self::UNBOUND_IDENTITY;
    }

    /** The scope string for a bound identity. The name round-trips exactly. */
    public static function scope_for(string $identity): string
    {
        return self::SCOPE_PREFIX . rawurlencode($identity);
    }

    /**
     * The identity name a gateway scope carries, or null when the scope is
     * not a gateway scope.
     *
     * A scope is never a grant here: filter_current_identity() reads it only
     * for a token that is already established as the gateway's, and an
     * identity narrows what Registrar::is_permitted() allows rather than
     * widening it, so a forged gateway scope on a foreign client buys
     * nothing.
     */
    public static function identity_from_scope(string $scope): ?string
    {
        if (! str_starts_with($scope, self::SCOPE_PREFIX)) {
            return null;
        }

        $name = rawurldecode(substr($scope, strlen(self::SCOPE_PREFIX)));

        return '' !== $name ? $name : null;
    }

    /** Gateway refresh token lifetime in seconds, filterable. Floored at one day. */
    public static function ttl(): int
    {
        return max(DAY_IN_SECONDS, (int) apply_filters('wpmcp_gateway_refresh_ttl', self::TTL_SECONDS));
    }

    /** The stored bookkeeping exactly as written, with no liveness judgement. */
    private static function raw_record(): ?array
    {
        $record = get_option(self::OPTION, null);
        if (! is_array($record) || empty($record['client_id'])) {
            return null;
        }

        return $record;
    }

    /**
     * Whether a validated token record is the gateway's: the recorded
     * client_id is authoritative, the gateway scope prefix is the fallback
     * for a token whose bookkeeping is gone. Both are needed, because the
     * consequence of a false negative is a token that escapes both the
     * surface restriction and the identity narrowing.
     */
    private static function is_gateway_token(array $record): bool
    {
        $client_id = (string) ($record['client_id'] ?? '');
        if ('' === $client_id) {
            return false;
        }

        $stored = self::raw_record();
        if (null !== $stored && (string) $stored['client_id'] === $client_id) {
            return true;
        }

        return null !== self::identity_from_scope((string) ($record['scope'] ?? ''));
    }

    /**
     * Whether the current request is on a route the gateway credential is
     * allowed to use: the MCP transport, or this plugin's OAuth routes.
     * Anything else (core REST, admin-ajax, admin-post, a front-end hit, a
     * request with no determinable route at all) is not.
     */
    private static function request_is_gateway_surface(): bool
    {
        $route = self::current_rest_route();
        $on    = null !== $route && Transport_Guard::is_guarded_route($route);

        /**
         * Whether a gateway credential may authenticate this request. Only
         * for setups that mount the MCP surface somewhere this cannot see;
         * widening it hands the credential the rest of the site.
         *
         * @param bool        $on    Whether the request is on the gateway surface.
         * @param string|null $route The resolved REST route, or null.
         */
        return (bool) apply_filters('wpmcp_gateway_surface', $on, $route);
    }

    /**
     * The REST route of the current request, or null when this is not a
     * REST request at all. Derived from the request itself because
     * determine_current_user can fire before WP_REST_Server has a request
     * object to ask.
     */
    private static function current_rest_route(): ?string
    {
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $route = (string) wp_unslash($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '/' . ltrim($route, '/');
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ('' === $uri) {
            return null;
        }

        $path   = (string) wp_parse_url($uri, PHP_URL_PATH);
        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
        $at     = strpos($path, $prefix);
        if (false === $at) {
            return null;
        }

        return '/' . ltrim(substr($path, $at + strlen($prefix)), '/');
    }

    /**
     * Kill the recorded chain (refresh tokens AND the access tokens issued
     * along it), then sweep anything else still bound to the client, which
     * covers records provisioned before chain_id was tracked.
     *
     * @return int Number of token records removed.
     */
    private static function revoke_locally(): int
    {
        $record = self::raw_record();
        if (null === $record) {
            return 0;
        }

        $killed   = 0;
        $chain_id = (string) ($record['chain_id'] ?? '');
        if ('' !== $chain_id) {
            $killed += Refresh_Token_Store::revoke_chain($chain_id);
        }

        $client_id = (string) $record['client_id'];
        $killed   += Refresh_Token_Store::revoke_for_client($client_id);
        $killed   += self::revoke_access_tokens_for_client($client_id);

        return $killed;
    }

    /**
     * Drop every access token bound to a client. Token_Store revokes by
     * chain, which is the right granularity for reuse detection but not for
     * a per-client kill switch: an access token minted before chain_id was
     * recorded, or along a chain the option no longer names, would survive.
     *
     * @return int Number of access tokens removed.
     */
    private static function revoke_access_tokens_for_client(string $client_id): int
    {
        $stored  = get_option(Token_Store::OPTION, []);
        $stored  = is_array($stored) ? $stored : [];
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ((string) ($record['client_id'] ?? '') === $client_id) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            update_option(Token_Store::OPTION, $stored);
        }

        return $removed;
    }

    private static function cloud_url_is_secure(): bool
    {
        return 'https' === strtolower((string) wp_parse_url(Cloud_Config::base_url(), PHP_URL_SCHEME));
    }

    /** Audit a provisioning refusal and return it. Every refusal path goes through here. */
    private static function refuse(string $identity, string $code, string $message): \WP_Error
    {
        self::audit('provision', $identity, false, $code);

        return new \WP_Error($code, $message);
    }

    /**
     * Record the provision/revoke decision. Minting and killing a
     * long-lived admin-bound credential is at least as audit-worthy as the
     * oauth/token and oauth/validate events already logged.
     *
     * The identity column carries the ACTING identity, matching
     * Registrar::record_audit() everywhere else in the log; the identity the
     * credential is (or was) bound to is a property of the event, so it
     * rides in the reason. No plaintext ever reaches the log.
     */
    private static function audit(string $action, string $identity, bool $allowed, string $reason = ''): void
    {
        try {
            $bound = '' !== $identity ? ' identity:' . $identity : '';

            Governance_Audit_Log::record(
                'cloud/gateway-credential-' . $action,
                Identity_Context::current() ?? 'none',
                $allowed,
                trim($reason . $bound)
            );
        } catch (\Throwable $e) {
            // Auditing must never break the outcome it is observing.
        }
    }
}
