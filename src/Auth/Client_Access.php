<?php

namespace WPMCP\Auth;

use WPMCP\Gateway\Gateway_Guard;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The access level an OAuth connection was approved with (issue #454), and
 * its enforcement.
 *
 * Two OAuth scopes are defined. `mcp` is full access: the token can do what
 * its user can do. `mcp:read` is read-only: the token may run abilities
 * whose registered operation is `read`, and every other ability is refused
 * with `insufficient_scope`, whatever the user could otherwise do.
 *
 * The level lives in two places, and the narrower one wins:
 *
 *  - the token's scope string, fixed when the client is approved and
 *    carried unchanged along every refresh (a refresh may narrow it, never
 *    widen it), so a read-only grant stays read-only even if this store is
 *    lost;
 *  - a row in this store per client and approving user, written by the
 *    consent screen and editable by the site owner on the Connection
 *    screen. It is read on every request, so lowering a connection takes
 *    effect on the next call without the client reconnecting. A row can
 *    also bind the connection to a scoped identity, which then narrows
 *    every call exactly as that identity does (domains, operations,
 *    abilities, allowed IPs) through the wpmcp_current_identity filter.
 *
 * Connections made before this existed have no row and a scope string that
 * is not `mcp:read`, so they keep full access until the owner changes them.
 * The gateway credential is out of scope here: it is bound to its identity
 * by Gateway_Guard.
 *
 * Enforcement is one call in Registrar::denial_reason(), the gate every
 * ability passes through, so no ability can opt out and tools/list is not
 * touched.
 */
class Client_Access
{
    public const OPTION = 'wpmcp_oauth_client_access';

    public const SCOPE_FULL = 'mcp';
    public const SCOPE_READ = 'mcp:read';

    /** Advertised in both metadata documents. */
    public const SCOPES_SUPPORTED = [self::SCOPE_FULL, self::SCOPE_READ];

    public const FULL     = 'full';
    public const READ     = 'read';
    public const IDENTITY = 'identity';

    /** Access values are 'full', 'read' or 'identity:<name>'. */
    private const IDENTITY_PREFIX = 'identity:';

    public static function register(): void
    {
        add_filter('wpmcp_current_identity', [self::class, 'filter_current_identity']);
    }

    /**
     * Parse an access value into [level, identity]. Null when it is not one
     * of the three shapes.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $access): ?array
    {
        if (self::FULL === $access || self::READ === $access) {
            return [$access, ''];
        }
        if (str_starts_with($access, self::IDENTITY_PREFIX)) {
            $name = substr($access, strlen(self::IDENTITY_PREFIX));
            return '' === $name ? null : [self::IDENTITY, $name];
        }

        return null;
    }

    /** Whether a space-delimited scope string grants only reading. */
    public static function is_read_only_scope(string $scope): bool
    {
        $tokens = self::tokens($scope);

        return in_array(self::SCOPE_READ, $tokens, true) && ! in_array(self::SCOPE_FULL, $tokens, true);
    }

    /**
     * The scope a new grant carries: read-only when the client asked only
     * for `mcp:read` or the approving user chose read-only, `mcp` otherwise.
     * Unknown scope values a client sends are dropped rather than refused,
     * so clients that send their own scope names keep connecting.
     */
    public static function granted_scope(string $requested, string $level): string
    {
        return self::READ === $level || self::is_read_only_scope($requested) ? self::SCOPE_READ : self::SCOPE_FULL;
    }

    /**
     * Whether a refresh may carry $requested for a chain granted $original
     * (RFC 6749 section 6: never more than was originally granted). An
     * empty request means "the same".
     */
    public static function refresh_scope_allowed(string $original, string $requested): bool
    {
        if ([] === self::tokens($requested)) {
            return true;
        }
        if (self::is_read_only_scope($original)) {
            return self::is_read_only_scope($requested);
        }

        return true;
    }

    /**
     * The scope a refresh mints: the original, or `mcp:read` when a full
     * chain asks to be narrowed.
     */
    public static function refreshed_scope(string $original, string $requested): string
    {
        return self::is_read_only_scope($requested) ? self::SCOPE_READ : $original;
    }

    /**
     * The stored level for one client and approving user, or null when
     * none was recorded (a connection made before issue #454).
     *
     * @return array{client_id: string, user_id: int, level: string, identity: string, set_by: int, set_at: int}|null
     */
    public static function get(string $client_id, int $user_id): ?array
    {
        $row = self::load()[ self::key($client_id, $user_id) ] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * Record the level for one client and user. Refuses a level that is not
     * full, read or identity, and an identity that does not exist.
     */
    public static function set(string $client_id, int $user_id, string $level, string $identity = ''): bool
    {
        if ('' === $client_id || $user_id <= 0 || ! in_array($level, [self::FULL, self::READ, self::IDENTITY], true)) {
            return false;
        }
        if (self::IDENTITY === $level && null === Identity_Store::get($identity)) {
            return false;
        }

        $stored                                   = self::load();
        $stored[ self::key($client_id, $user_id) ] = [
            'client_id' => $client_id,
            'user_id'   => $user_id,
            'level'     => $level,
            'identity'  => self::IDENTITY === $level ? $identity : '',
            'set_by'    => get_current_user_id(),
            'set_at'    => time(),
        ];
        update_option(self::OPTION, $stored, false);

        return true;
    }

    /** Drop every row for a client (the client was forgotten or blocked). */
    public static function forget_client(string $client_id): void
    {
        $stored = self::load();
        $before = count($stored);
        $stored = array_filter($stored, static fn ($row): bool => ! is_array($row) || (string) ($row['client_id'] ?? '') !== $client_id);
        if (count($stored) !== $before) {
            update_option(self::OPTION, $stored, false);
        }
    }

    /**
     * Every live OAuth connection, one per client and user, for the
     * Connection screen: every client and user holding a refresh or access
     * token. The gateway credential is left out.
     *
     * @return array<int, array{client_id: string, user_id: int, level: string, identity: string, scope: string, stored: bool}>
     */
    public static function connections(): array
    {
        $out = [];
        foreach ([Refresh_Token_Store::OPTION, Token_Store::OPTION] as $option) {
            $records = get_option($option, []);
            foreach (is_array($records) ? $records : [] as $record) {
                if (! is_array($record) || ! empty($record['gateway'])) {
                    continue;
                }
                $client_id = (string) ($record['client_id'] ?? '');
                $user_id   = (int) ($record['user_id'] ?? 0);
                if ('' === $client_id || $user_id <= 0 || Client_Store::is_protected($client_id)) {
                    continue;
                }
                $key = self::key($client_id, $user_id);
                $out[ $key ] ??= ['client_id' => $client_id, 'user_id' => $user_id, 'scope' => (string) ($record['scope'] ?? '')];
                if (self::is_read_only_scope((string) ($record['scope'] ?? ''))) {
                    $out[ $key ]['scope'] = self::SCOPE_READ;
                }
            }
        }
        foreach ($out as $key => $connection) {
            $row                 = self::get($connection['client_id'], $connection['user_id']);
            $out[ $key ]['stored']   = null !== $row;
            $out[ $key ]['level']    = null !== $row ? (string) $row['level'] : self::FULL;
            $out[ $key ]['identity'] = null !== $row ? (string) $row['identity'] : '';
        }

        return array_values($out);
    }

    /**
     * The effective restriction on the current request: null when the
     * request was not authenticated by an ordinary OAuth token (cookie,
     * Application Password, WP-CLI, the gateway credential), otherwise
     * { read_only, identity }.
     *
     * @return array{read_only: bool, identity: string}|null
     */
    public static function current(): ?array
    {
        $token = Bearer_Auth::current_token();
        if (! is_array($token) || Gateway_Guard::is_gateway_token($token)) {
            return null;
        }

        $row       = self::get((string) $token['client_id'], (int) $token['user_id']);
        $level     = null !== $row ? (string) $row['level'] : self::FULL;
        $read_only = self::is_read_only_scope((string) ($token['scope'] ?? '')) || self::READ === $level;

        return [
            'read_only' => $read_only,
            'identity'  => self::IDENTITY === $level ? (string) $row['identity'] : '',
        ];
    }

    /**
     * Null when the current request's access level allows $a, otherwise the
     * insufficient_scope error naming the scope the ability needs.
     */
    public static function denial(Ability $a): ?\WP_Error
    {
        if ('read' === $a->operation) {
            return null;
        }

        $current = self::current();
        if (null === $current || ! $current['read_only']) {
            return null;
        }

        return new \WP_Error(
            'insufficient_scope',
            sprintf(
                /* translators: 1: ability name, 2: its operation, 3: the OAuth scope it needs. */
                __('This connection is read-only: "%1$s" is a %2$s ability and needs the "%3$s" scope.', 'wpmcp'),
                $a->name,
                $a->operation,
                self::SCOPE_FULL
            ),
            ['status' => 403, 'scope' => self::SCOPE_FULL]
        );
    }

    /**
     * wpmcp_current_identity: a connection bound to a scoped identity acts
     * as that identity. Anything else passes through unchanged.
     *
     * @param string|null $identity
     * @return string|null
     */
    public static function filter_current_identity($identity)
    {
        $current = self::current();
        if (null === $current || '' === $current['identity']) {
            return $identity;
        }

        return $current['identity'];
    }

    /** @return string[] */
    private static function tokens(string $scope): array
    {
        $tokens = preg_split('/\s+/', trim($scope));

        return is_array($tokens) ? array_values(array_filter($tokens, 'strlen')) : [];
    }

    private static function key(string $client_id, int $user_id): string
    {
        return $client_id . '|' . $user_id;
    }

    /** @return array<string, array> */
    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
