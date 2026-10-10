<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Privacy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Finds one person inside an undo snapshot and redacts them (issue #478).
 *
 * Snapshots hold whole rows of many shapes (users, comments, posts with
 * their comments, orders, form entries, raw plugin table rows), so this
 * works on the decoded payload generically rather than per object type:
 *
 *  - A RECORD is the nearest keyed array (a row, a meta map) around a value
 *    that identifies the person: a string holding their email address, a
 *    user id under a user key (user_id, customer_id, _customer_user), or
 *    the whole payload of a user snapshot of their account.
 *  - Redacting a record replaces every string in it with REDACTED, except
 *    structural values (ids, types, statuses, dates) that say what the row
 *    was without saying who, and sets their user id to 0. A string anywhere
 *    that holds the email is replaced even when its key looks structural.
 *
 * A redacted snapshot is marked with MARKER, and Rollback_Service refuses
 * to restore it: putting placeholders back over live data is not an undo.
 */
class Snapshot_Redactor
{
    /** Top-level snapshot key recording that personal data was erased from it. */
    public const MARKER = 'privacy_erased';

    /** What an erased value reads as. */
    public const REDACTED = '[erased]';

    /** Keys whose integer value is a user id that ties a row to a person. */
    private const USER_KEYS = [ 'user_id', 'customer_id', 'customer_user', '_customer_user' ];

    /** Keys that describe a row without identifying anyone. */
    private const STRUCTURAL = '/(^id$|_id$|^type$|_type$|status$|^date|_date$|_gmt$|^comment_approved$|^comment_karma$|^comment_parent$|^menu_order$|^post_parent$|^taxonomy$)/i';

    /** Keys whose value is a client address. */
    private const IP_KEYS = '/(^|_)ip(_address)?$/i';

    /**
     * The records in $snapshot that identify the person, keyed by their
     * path inside the snapshot (segments joined with " > ").
     *
     * @return array<string, array> path => record
     */
    public static function records(array $snapshot, string $email, int $user_id): array
    {
        $out = [];
        foreach (self::record_paths($snapshot, $email, $user_id) as $path) {
            $node = self::at($snapshot, $path);
            if (is_array($node)) {
                $out[ implode(' > ', $path) ] = $node;
            }
        }
        return $out;
    }

    /**
     * $snapshot with the person redacted and MARKER set, or null when it
     * holds nothing of theirs (or held it and was already redacted). Client
     * addresses found in the redacted records are appended to $ips, so the
     * logs that only recorded an address can be anonymized too.
     *
     * @param string[] $ips
     */
    public static function redact(array $snapshot, string $email, int $user_id, array &$ips = []): ?array
    {
        $changed = false;
        foreach (self::record_paths($snapshot, $email, $user_id) as $path) {
            $node = &self::ref($snapshot, $path);
            if (is_array($node)) {
                self::scrub($node, (string) end($path), $email, $user_id, $ips, $changed);
            }
            unset($node);
        }
        if (! $changed) {
            return null;
        }
        $snapshot[ self::MARKER ] = [ 'erased_at' => gmdate('Y-m-d H:i:s') ];
        return $snapshot;
    }

    /** Whether personal data was erased from this snapshot. */
    public static function is_erased(array $snapshot): bool
    {
        return is_array($snapshot[ self::MARKER ] ?? null);
    }

    /** Why a redacted snapshot cannot be restored, for the rollback refusal. */
    public static function refusal(array $snapshot): string
    {
        $when = (string) ($snapshot[ self::MARKER ]['erased_at'] ?? '');
        return sprintf(
            'its snapshot had personal data removed by a personal data erasure request%s, so restoring it would write placeholders back over real data. Nothing was restored.',
            '' === $when ? '' : ' on ' . $when . ' UTC'
        );
    }

    /** Whether any string inside $value holds $email (case-insensitive). */
    public static function contains_email($value, string $email): bool
    {
        if ('' === $email) {
            return false;
        }
        if (is_string($value)) {
            return false !== stripos($value, $email);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains_email($item, $email)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** $value with every string that holds $email replaced by REDACTED. */
    public static function without_email($value, string $email)
    {
        if (is_string($value)) {
            return self::contains_email($value, $email) ? self::REDACTED : $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[ $key ] = self::without_email($item, $email);
            }
        }
        return $value;
    }

    /**
     * Paths of the records that identify the person, outermost only.
     *
     * @return array<int, array<int, int|string>>
     */
    private static function record_paths(array $snapshot, string $email, int $user_id): array
    {
        $paths = [];
        if (
            $user_id > 0
            && 'user' === ($snapshot['object_type'] ?? '')
            && (int) ($snapshot['object_id'] ?? 0) === $user_id
            && is_array($snapshot['data'] ?? null)
        ) {
            $paths[] = [ 'data' ];
        }
        $data = $snapshot;
        unset($data[ self::MARKER ]);
        self::scan($data, [], [], '', $email, $user_id, $paths);

        // Keep the outermost record of any nested pair, once.
        usort($paths, static fn (array $a, array $b): int => count($a) <=> count($b));
        $kept = [];
        foreach ($paths as $path) {
            foreach ($kept as $outer) {
                if (array_slice($path, 0, count($outer)) === $outer) {
                    continue 2;
                }
            }
            $kept[] = $path;
        }
        return $kept;
    }

    /**
     * @param array<int, int|string> $path        Where $node sits.
     * @param array<int, int|string> $record_path The keyed array a leaf of $node belongs to.
     * @param array<int, array<int, int|string>> $paths
     */
    private static function scan(array $node, array $path, array $record_path, string $key_context, string $email, int $user_id, array &$paths): void
    {
        foreach ($node as $key => $value) {
            $effective = is_string($key) ? $key : $key_context;
            if (is_array($value)) {
                $child_path = array_merge($path, [ $key ]);
                self::scan($value, $child_path, array_is_list($value) ? $record_path : $child_path, $effective, $email, $user_id, $paths);
                continue;
            }
            if (self::identifies($value, $effective, $email, $user_id)) {
                $paths[] = $record_path;
            }
        }
    }

    /** @param mixed $value */
    private static function identifies($value, string $key, string $email, int $user_id): bool
    {
        if (is_string($value) && self::contains_email($value, $email)) {
            return true;
        }
        return $user_id > 0
            && in_array(strtolower($key), self::USER_KEYS, true)
            && (is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value === $user_id;
    }

    /** @param string[] $ips */
    private static function scrub(array &$node, string $key_context, string $email, int $user_id, array &$ips, bool &$changed): void
    {
        foreach ($node as $key => &$value) {
            $effective = is_string($key) ? $key : $key_context;
            if (is_array($value)) {
                self::scrub($value, $effective, $email, $user_id, $ips, $changed);
                continue;
            }
            if (is_int($value)) {
                if ($user_id > 0 && $value === $user_id && in_array(strtolower($effective), self::USER_KEYS, true)) {
                    $value   = 0;
                    $changed = true;
                }
                continue;
            }
            if (! is_string($value) || '' === $value || self::REDACTED === $value) {
                continue;
            }
            if (1 === preg_match(self::STRUCTURAL, $effective) && ! self::contains_email($value, $email)) {
                continue;
            }
            if (1 === preg_match(self::IP_KEYS, $effective) && false !== filter_var($value, FILTER_VALIDATE_IP)) {
                $ips[] = $value;
            }
            $value   = self::REDACTED;
            $changed = true;
        }
        unset($value);
    }

    /**
     * @param array<int, int|string> $path
     * @return mixed
     */
    private static function at(array $tree, array $path)
    {
        foreach ($path as $segment) {
            if (! is_array($tree) || ! array_key_exists($segment, $tree)) {
                return null;
            }
            $tree = $tree[ $segment ];
        }
        return $tree;
    }

    /**
     * @param array<int, int|string> $path
     * @return mixed
     */
    private static function &ref(array &$tree, array $path)
    {
        $node = &$tree;
        foreach ($path as $segment) {
            $node = &$node[ $segment ];
        }
        return $node;
    }
}
