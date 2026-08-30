<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Code_Store;

/**
 * Code_Store issues and redeems OAuth 2.1 authorization codes. Two security
 * properties are covered here:
 *  - a code is single-use: consume() returns the bound record exactly once,
 *    and a replayed consume() (or lookup after consumption) must fail;
 *  - a code is short-lived: consume() rejects an expired code even though it
 *    was never consumed.
 * The code itself is stored hashed (mirroring Client_Store's secret
 * handling) so a leaked options row cannot be replayed as a valid code.
 */
class CodeStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Code_Store::OPTION);
        Code_Store::set_clock_override(null);
    }

    protected function tearDown(): void
    {
        delete_option(Code_Store::OPTION);
        Code_Store::set_clock_override(null);
        parent::tearDown();
    }

    /** The store as actually persisted, bypassing the object cache. */
    private function stored_row(): array
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                Code_Store::OPTION
            )
        );

        return is_array(maybe_unserialize($value)) ? maybe_unserialize($value) : [];
    }

    private function issue(): string
    {
        return Code_Store::issue([
            'client_id'            => 'client_abc',
            'user_id'              => 42,
            'redirect_uri'         => 'https://example.com/cb',
            'code_challenge'       => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'scope'                => 'read',
        ]);
    }

    public function test_issue_returns_a_non_empty_code(): void
    {
        $code = $this->issue();

        $this->assertIsString($code);
        $this->assertNotSame('', $code);
    }

    public function test_stored_record_never_contains_the_plaintext_code(): void
    {
        $code = $this->issue();

        $stored = get_option(Code_Store::OPTION);
        $this->assertIsArray($stored);

        $serialized = wp_json_encode($stored);
        $this->assertStringNotContainsString($code, $serialized);
    }

    public function test_consume_returns_the_bound_record_for_a_valid_code(): void
    {
        $code   = $this->issue();
        $record = Code_Store::consume($code);

        $this->assertIsArray($record);
        $this->assertSame('client_abc', $record['client_id']);
        $this->assertSame(42, $record['user_id']);
        $this->assertSame('https://example.com/cb', $record['redirect_uri']);
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $record['code_challenge']);
        $this->assertSame('S256', $record['code_challenge_method']);
        $this->assertSame('read', $record['scope']);
    }

    public function test_consume_is_single_use_a_replay_is_rejected(): void
    {
        $code = $this->issue();

        $this->assertIsArray(Code_Store::consume($code));
        $this->assertNull(Code_Store::consume($code));
    }

    /**
     * consume() must be redeemable at most once even across many repeated
     * attempts against the same code (issue #43 C3). PHPUnit runs
     * single-threaded, so this cannot reproduce the original TOCTOU
     * interleave (two requests both reading the option before either
     * writes) deterministically -- that requires genuine concurrent
     * requests. What this test proves instead: hammering consume() with
     * the same code repeatedly yields the bound record exactly once and
     * null every other time, i.e. the claim is idempotent-safe under
     * repetition, not just under a single sequential replay. See
     * Code_Store::consume()'s doc comment for why the underlying $wpdb
     * compare-and-swap closes the race a plain get_option/update_option
     * pair could not.
     */
    public function test_consume_is_redeemable_at_most_once_under_repeated_attempts(): void
    {
        $code = $this->issue();

        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = Code_Store::consume($code);
        }

        $successes = array_filter($results, static fn($r) => null !== $r);
        $this->assertCount(1, $successes, 'Exactly one consume() call may claim the code.');
    }

    /**
     * White-box proof that consume() detects and rejects a stale write
     * attempt, which is the exact TOCTOU the original load -> unset -> save
     * implementation was vulnerable to (issue #43 C3).
     *
     * The concurrent consumer is simulated by writing the option row
     * directly, exactly as Code_Store's own compare-and-swap does, and
     * deliberately leaving the object cache holding the pre-write value.
     * That is what a second request actually leaves behind, and it is the
     * harder case: an implementation that read through get_option() would
     * see the code still present and resurrect it. consume() must instead
     * observe the row as it really is and lose the race cleanly (null).
     *
     * (Before issue #182 this test injected via the `option_{name}` filter
     * and a plain update_option(), which refreshed the cache on the way
     * past and so could never have caught the resurrection bug.)
     */
    public function test_consume_detects_and_rejects_a_stale_concurrent_write(): void
    {
        global $wpdb;

        $code = $this->issue();
        $key  = array_key_first(get_option(Code_Store::OPTION));

        // Prime the cache, so a naive read would still show the code.
        get_option(Code_Store::OPTION);

        // A concurrent request redeems the same code, writing past the cache.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                maybe_serialize([]),
                Code_Store::OPTION
            )
        );

        $result = Code_Store::consume($code);

        $this->assertNull($result, 'A code already claimed by a concurrent consumer must not be redeemed again.');

        // Assert against the row, not get_option(): the simulated competitor
        // wrote past the cache (as a separate process would), so this
        // process's cached copy is legitimately stale. What must hold is
        // that the persisted store was not overwritten with the resurrected
        // code.
        $this->assertArrayNotHasKey(
            $key,
            $this->stored_row(),
            'The code must remain consumed, not resurrected by a stale overwrite.'
        );
    }

    /**
     * The compare-and-swap's losing path: the row changes after consume()
     * has read it but before its UPDATE lands, so the UPDATE matches no row.
     * consume() must retry against the fresh row and then lose cleanly
     * rather than overwriting the winner's change.
     *
     * The `query` filter fires inside wpdb::query() just before the SQL is
     * sent, which is precisely the read/write window to interleave in.
     */
    public function test_consume_loses_cleanly_when_the_row_changes_between_read_and_swap(): void
    {
        global $wpdb;

        $code = $this->issue();
        $key  = array_key_first(get_option(Code_Store::OPTION));

        $armed  = false;
        $filter = static function ($query) use (&$armed) {
            global $wpdb;

            if (! $armed && false !== stripos($query, 'UPDATE') && false !== strpos($query, Code_Store::OPTION)) {
                $armed = true;
                // The competing consumer claims the row first.
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                        maybe_serialize([]),
                        Code_Store::OPTION
                    )
                );
            }

            return $query;
        };

        add_filter('query', $filter);
        $result = Code_Store::consume($code);
        remove_filter('query', $filter);

        $this->assertNull($result, 'The losing caller must not claim a code the winner already took.');
        $this->assertArrayNotHasKey($key, $this->stored_row());
    }

    public function test_consume_rejects_an_unknown_code(): void
    {
        $this->assertNull(Code_Store::consume('never-issued'));
    }

    public function test_consume_rejects_an_expired_code(): void
    {
        Code_Store::set_clock_override(fn() => 1000);
        $code = $this->issue();

        // Advance past the TTL.
        Code_Store::set_clock_override(fn() => 1000 + Code_Store::TTL_SECONDS + 1);

        $this->assertNull(Code_Store::consume($code));
    }

    public function test_consume_accepts_a_code_right_before_expiry(): void
    {
        Code_Store::set_clock_override(fn() => 1000);
        $code = $this->issue();

        Code_Store::set_clock_override(fn() => 1000 + Code_Store::TTL_SECONDS);

        $this->assertIsArray(Code_Store::consume($code));
    }

    /**
     * The compare-and-swap in consume() writes past get_option()'s cache.
     * wpmcp_oauth_codes is autoloaded, so get_option() serves it out of the
     * `alloptions` blob, which wp_cache_delete(OPTION, 'options') does not
     * touch. If the swap does not invalidate what get_option() actually
     * reads, the consumed hash survives in cache and the next issue()'s
     * load() -> save() read-modify-write writes the redeemed code straight
     * back into wp_options, making it redeemable a second time inside its
     * 60-second TTL.
     */
    public function test_a_consumed_code_is_not_resurrected_by_a_later_issue(): void
    {
        $code = $this->issue();
        $this->assertNotNull(Code_Store::consume($code), 'first redemption should succeed');

        // Read-modify-write of the same option row by an unrelated issue().
        $this->issue();

        $this->assertNull(
            Code_Store::consume($code),
            'a redeemed authorization code was resurrected and redeemed twice'
        );
    }

    /**
     * The retry loop must observe the row as another process left it. Reading
     * $before through get_option() returns the same in-process cache copy on
     * every attempt, so a caller that loses the race can never see the fresh
     * row: all attempts compare an identical stale $before, every CAS affects
     * zero rows, and a valid unredeemed code is wrongly rejected.
     */
    public function test_consume_sees_a_row_written_behind_the_options_cache(): void
    {
        global $wpdb;

        $code = $this->issue();

        // Simulate a concurrent process: write the row directly, the way
        // Code_Store's own compare-and-swap does, leaving the cache stale.
        $stored = get_option(Code_Store::OPTION);
        $stored['unrelated_hash'] = [
            'client_id' => 'other',
            'user_id'   => 7,
            'issued_at' => time(),
        ];
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                maybe_serialize($stored),
                Code_Store::OPTION
            )
        );

        $this->assertNotNull(
            Code_Store::consume($code),
            'consume() could not redeem a valid code after a concurrent direct write'
        );
    }
}
