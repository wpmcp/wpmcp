<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Issues and redeems OAuth 2.1 authorization codes (RFC 6749 4.1, PKCE-bound
 * per RFC 7636). Backed by a single wpmcp_oauth_codes option, a map of a
 * SHA-256 hash of the code to its bound record: { client_id, user_id,
 * redirect_uri, code_challenge, code_challenge_method, scope, issued_at }.
 *
 * Security properties:
 *  - the code itself is never stored in plaintext (only its hash), matching
 *    Client_Store's secret handling, so a leaked option row cannot be
 *    replayed as a valid code;
 *  - consume() redeems a code at most once, atomically (issue #43 C3): the
 *    whole store lives in a single wp_options row, and update_option() is
 *    unconditional last-write-wins, so a plain load -> unset -> save (the
 *    original implementation) has a TOCTOU window -- two concurrent
 *    exchanges for the same code can both read the record before either
 *    writes, and both would then mint a token from one code. consume()
 *    closes this with a compare-and-swap directly against wp_options: it
 *    reads the row's current serialized value, then issues an
 *    `UPDATE wp_options SET option_value = <new> WHERE option_name = ...
 *    AND option_value = <value just read>` via $wpdb, which MySQL executes
 *    under a row lock. Only the caller whose read matches what is still in
 *    the row at UPDATE time gets to write (rows-affected = 1); every other
 *    concurrent caller's UPDATE affects 0 rows because the row changed out
 *    from under it, so it re-reads the fresh row and retries. Whichever
 *    caller's retry finds the code already gone loses cleanly (null),
 *    exactly like today's expired/unknown-code path -- no caller can ever
 *    observe a code as present after another caller has already claimed it;
 *  - codes expire after TTL_SECONDS (short-lived, per OAuth 2.1 guidance);
 *    consume() rejects an expired code even if it was never consumed, and
 *    also evicts it so expired entries do not linger in the option forever.
 */
class Code_Store
{
    public const OPTION      = 'wpmcp_oauth_codes';
    public const TTL_SECONDS = 60;

    private static $clock_override = null;

    public static function set_clock_override(?callable $clock): void
    {
        self::$clock_override = $clock;
    }

    private static function now(): int
    {
        return null !== self::$clock_override ? (int) (self::$clock_override)() : time();
    }

    /** Bounded retry count for the consume() compare-and-swap loop. */
    private const MAX_CAS_ATTEMPTS = 10;

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    /**
     * @param array $fields client_id, user_id, redirect_uri, code_challenge,
     *                      code_challenge_method, scope.
     * @return string The plaintext code, returned exactly once.
     */
    public static function issue(array $fields): string
    {
        $code = 'ac_' . bin2hex(random_bytes(32));

        $stored              = self::load();
        $stored[ self::hash($code) ] = [
            'client_id'             => (string) ($fields['client_id'] ?? ''),
            'user_id'               => (int) ($fields['user_id'] ?? 0),
            'redirect_uri'          => (string) ($fields['redirect_uri'] ?? ''),
            'code_challenge'        => (string) ($fields['code_challenge'] ?? ''),
            'code_challenge_method' => (string) ($fields['code_challenge_method'] ?? ''),
            'scope'                 => (string) ($fields['scope'] ?? ''),
            'issued_at'             => self::now(),
        ];
        self::save($stored);

        return $code;
    }

    /**
     * Redeem $code: returns its bound record and removes it (single-use) if
     * it exists and has not expired, otherwise returns null. An expired
     * match is also evicted so it cannot be found again.
     *
     * Atomic by compare-and-swap (see class doc comment): the remove step
     * is a conditional $wpdb UPDATE keyed on the option row's current
     * value, not an unconditional update_option(). If another caller wins
     * the race and changes the row first, this retries against the fresh
     * row rather than silently overwriting it.
     */
    public static function consume(string $code): ?array
    {
        $key = self::hash($code);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $before = get_option(self::OPTION, []);
            $before = is_array($before) ? $before : [];

            if (! isset($before[ $key ])) {
                return null;
            }

            $record = $before[ $key ];
            $after  = $before;
            unset($after[ $key ]);

            if (self::compare_and_swap($before, $after)) {
                if (self::now() > $record['issued_at'] + self::TTL_SECONDS) {
                    return null;
                }

                return $record;
            }

            // Another caller changed the row between our read and our
            // write attempt (rows-affected was 0): loop and retry against
            // the now-current row rather than overwriting it.
        }

        return null;
    }

    /**
     * Atomically replace the wpmcp_oauth_codes option's value from $before
     * to $after, but ONLY if the row still holds exactly $before at write
     * time. Returns true if this caller's write won (rows-affected === 1),
     * false if another caller changed the row first (rows-affected === 0),
     * in which case the caller must re-read and retry.
     *
     * Deliberately bypasses update_option() (which is unconditional
     * last-write-wins) in favor of a direct $wpdb UPDATE ... WHERE
     * option_value = <expected>, which MySQL executes under a row lock: at
     * most one concurrent caller's UPDATE can match the WHERE clause and
     * affect a row, which is exactly the "redeemable at most once"
     * guarantee consume() needs.
     */
    private static function compare_and_swap(array $before, array $after): bool
    {
        global $wpdb;

        $before_value = maybe_serialize($before);
        $after_value  = maybe_serialize($after);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional UPDATE on wp_options implements the compare-and-swap guarantee described in the docblock; update_option() cannot express the WHERE guard.
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $after_value,
                self::OPTION,
                $before_value
            )
        );

        if (false === $affected) {
            return false;
        }

        if ($affected > 0) {
            wp_cache_delete(self::OPTION, 'options');
            return true;
        }

        return false;
    }

    /**
     * Sweep expired authorization codes (issue #133). Eviction used to be
     * lazy and on-touch: a code that was issued and then abandoned (the
     * user closed the consent tab) was never redeemed, so nothing ever
     * looked at it and it stayed in the option forever. Codes live 60
     * seconds, so under a browser-abandoned authorize flow this is the
     * fastest-growing of the three OAuth stores.
     *
     * Uses a plain update_option rather than consume()'s compare-and-swap:
     * this only ever removes records that are already unredeemable, so a
     * lost race costs nothing but a deferred sweep.
     *
     * @return int Number of codes removed.
     */
    public static function gc(): int
    {
        $stored  = self::load();
        $now     = self::now();
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ($now > (int) ($record['issued_at'] ?? 0) + self::TTL_SECONDS) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            self::save($stored);
        }

        return $removed;
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
