<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Redirection_Item_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Operations status adapters (issue #300, second slice): read-only status
 * for the common backup, security, analytics and caching plugins, plus the
 * W3 Total Cache purge, as free ops on the theme dispatcher pair so they add
 * no top-level tools:
 *  - get-updraftplus-status (UpdraftPlus_Status)
 *  - get-duplicator-status (Duplicator_Status)
 *  - get-solid-security-status (Solid_Security_Status)
 *  - get-monsterinsights-status (MonsterInsights_Status)
 *  - get-w3tc-status and purge-w3tc-cache (W3TC_Status)
 *
 * Every op runs at manage_options, the capability each plugin gates its own
 * settings behind, and declares a 'requires' check, so an inactive plugin is
 * skipped cleanly: its ops stay documented in list-operations
 * (dependency_met:false) and answer <plugin>_inactive without touching
 * anything. Presence is filterable per plugin (wpmcp_updraftplus_active,
 * wpmcp_duplicator_active, wpmcp_solid_security_active,
 * wpmcp_monsterinsights_active, wpmcp_w3tc_active).
 *
 * The reads build their answers from allowlisted fields only, never echoing
 * a plugin's stored settings wholesale, so remote storage credentials, OAuth
 * tokens, license keys, scan site keys, cache server passwords, file names
 * and IP addresses stay out. As a second line, every answer passes through
 * redact(), which masks any secret-shaped key that might still slip in.
 */
final class Ops_Status_Packs
{
    public const REDACTED = '[redacted]';

    /** Word tokens that mark a key as naming a secret. */
    private const SECRET_TOKENS = [
        'key', 'apikey', 'token', 'secret', 'password', 'passwd', 'pass', 'pwd',
        'auth', 'credential', 'credentials', 'nonce', 'hash', 'license', 'signature',
        'private', 'cookie', 'salt',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return array_merge(
            UpdraftPlus_Status::operations(),
            Duplicator_Status::operations(),
            Solid_Security_Status::operations(),
            MonsterInsights_Status::operations(),
            W3TC_Status::operations()
        );
    }

    /**
     * A read op definition shared by every status adapter.
     *
     * @param callable(array):array $handler
     * @return array<string,mixed>
     */
    public static function read_op(string $description, callable $requires, callable $handler, array $properties = []): array
    {
        return [
            'mode'         => 'read',
            'capability'   => 'manage_options',
            'description'  => $description,
            'input_schema' => [ 'type' => 'object', 'properties' => $properties ],
            'requires'     => $requires,
            'handler'      => static fn (array $args): array => self::redact($handler($args)),
        ];
    }

    /**
     * The 'requires' answer for a plugin that is or is not loaded.
     *
     * @return true|array{code:string,message:string}
     */
    public static function presence(bool $active, string $code, string $plugin)
    {
        if ($active) {
            return true;
        }
        return [
            'code'    => $code,
            'message' => sprintf('The %s plugin is not active on this site.', $plugin),
        ];
    }

    /**
     * Copy of $value with the value under every secret-shaped key replaced by
     * REDACTED, at any depth. A key is split into word tokens (snake_case,
     * kebab-case, dotted and camelCase all split), so "keywords" never
     * matches while api_key, apiKey, access_token and client_secret do.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function redact(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::is_secret_key($key)) {
                $out[ $key ] = self::REDACTED;
                continue;
            }
            $out[ $key ] = is_array($item) ? self::redact($item) : $item;
        }
        return $out;
    }

    private static function is_secret_key(string $key): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return [] !== array_intersect(is_array($tokens) ? $tokens : [], self::SECRET_TOKENS);
    }

    /** A Unix timestamp as an ISO 8601 UTC string, or null when unset. */
    public static function iso_time($timestamp): ?string
    {
        $timestamp = is_numeric($timestamp) ? (int) $timestamp : 0;
        return $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }

    /** A 'Y-m-d H:i:s' UTC datetime column as ISO 8601, or null when empty or zero. */
    public static function iso_datetime($datetime): ?string
    {
        if (! is_string($datetime) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $datetime, $m) || '0000-00-00' === $m[1]) {
            return null;
        }
        return $m[1] . 'T' . $m[2] . 'Z';
    }

    /** Whether a table exists in this database. */
    public static function table_exists(string $table): bool
    {
        return Redirection_Item_Snapshot::table_exists($table);
    }
}
