<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Token_Refresher;

/**
 * Issue #141 phase 1: the rotation-safe refresh engine.
 *
 * One test per documented branch of Token_Refresher: the lock winner, both
 * race-loser shapes, the lock-timeout bail, the unusable-lock fallback, the
 * transient failures that must leave the bundle untouched (network error,
 * 5xx, rate limit, malformed 2xx body), the single genuine rejection that
 * marks the connection unhealthy, and the backoff that stops the plugin
 * re-presenting a refresh token the cloud has already revoked.
 *
 * The lock and transport seams are injected, so no MySQL advisory lock and
 * no HTTP call is involved; the wire-format branches of the real transport
 * are exercised separately through the pre_http_request filter.
 */
class TokenRefresherTest extends \WP_UnitTestCase
{
    /** @var array<int,array{base_url:string,body:array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cloud_Credentials::clear();
        delete_option(Token_Refresher::HEALTH_OPTION);
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        Cloud_Credentials::clear();
        delete_option(Token_Refresher::HEALTH_OPTION);
        parent::tearDown();
    }

    /** A connected bundle whose access token expired an hour ago. */
    private function seed_stale(array $extra = []): void
    {
        Cloud_Credentials::replace(array_merge([
            'base_url'          => 'https://cloud.example',
            'api_key'           => 'sk-fallback',
            'access_token'      => 'old-access',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() - 3600,
            'client_id'         => 'client-1',
        ], $extra));
    }

    /** Refresher whose lock always succeeds and whose transport is canned. */
    private function refresher($response, ?callable $lock = null): Token_Refresher
    {
        $transport = function (string $base_url, array $body) use ($response) {
            $this->sent[] = ['base_url' => $base_url, 'body' => $body];
            return is_callable($response) ? $response($base_url, $body) : $response;
        };
        return new Token_Refresher(
            $lock ?? static fn (string $n, int $t) => true,
            static function (string $n): void {
            },
            $transport
        );
    }

    // ---- lock winner --------------------------------------------------------

    public function test_winner_refreshes_and_merges_onto_the_stored_bundle(): void
    {
        $this->seed_stale();

        $token = $this->refresher([
            'access_token'  => 'new-access',
            'refresh_token' => 'rt-2',
            'expires_in'    => 3600,
        ])->ensure_fresh_access_token();

        $this->assertSame('new-access', $token);

        $all = Cloud_Credentials::all(true);
        $this->assertSame('new-access', $all['access_token']);
        $this->assertSame('rt-2', $all['refresh_token']);
        $this->assertGreaterThan(time() + 3000, (int) $all['access_expires_at']);
        // Fields the response never mentions survive the merge.
        $this->assertSame('sk-fallback', $all['api_key']);
        $this->assertSame('client-1', $all['client_id']);
        $this->assertSame('rt-1', $this->sent[0]['body']['refresh_token']);
    }

    public function test_fresh_token_short_circuits_without_a_transport_call(): void
    {
        $this->seed_stale(['access_expires_at' => time() + 3600]);

        $token = $this->refresher(new \WP_Error('boom', 'never called'))->ensure_fresh_access_token();

        $this->assertSame('old-access', $token);
        $this->assertSame([], $this->sent);
    }

    public function test_no_refresh_token_returns_null_for_api_key_fallback(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-fallback']);

        $this->assertNull($this->refresher(new \WP_Error('boom', 'never called'))->ensure_fresh_access_token());
        $this->assertSame([], $this->sent);
    }

    // ---- race losers --------------------------------------------------------

    public function test_race_loser_via_rotated_stored_token_is_not_marked_unhealthy(): void
    {
        $this->seed_stale();

        // The winner rotated the bundle while our request was in flight.
        $response = function () {
            Cloud_Credentials::merge(['refresh_token' => 'rt-2', 'access_token' => '', 'access_expires_at' => 0]);
            return ['auth_rejected' => true];
        };

        $this->assertNull($this->refresher($response)->ensure_fresh_access_token());
        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION));
    }

    public function test_race_loser_via_fresh_access_token_returns_the_winners_token(): void
    {
        $this->seed_stale();

        $response = function () {
            Cloud_Credentials::merge([
                'access_token'      => 'winner-access',
                'refresh_token'     => 'rt-2',
                'access_expires_at' => time() + 3600,
            ]);
            return ['auth_rejected' => true];
        };

        $this->assertSame('winner-access', $this->refresher($response)->ensure_fresh_access_token());
        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION));
    }

    // ---- lock behaviour -----------------------------------------------------

    public function test_lock_timeout_never_presents_the_refresh_token(): void
    {
        $this->seed_stale();

        $refresher = $this->refresher(new \WP_Error('boom', 'never called'), static fn () => false);

        // Stale stored token: return null so the caller falls back to the API
        // key rather than sending a token we know is expired.
        $this->assertNull($refresher->ensure_fresh_access_token());
        $this->assertSame([], $this->sent);
    }

    public function test_lock_timeout_returns_a_token_the_winner_already_refreshed(): void
    {
        $this->seed_stale();

        $lock = static function () {
            Cloud_Credentials::merge(['access_token' => 'winner-access', 'access_expires_at' => time() + 3600]);
            return false;
        };

        $this->assertSame('winner-access', $this->refresher(new \WP_Error('boom', 'never called'), $lock)->ensure_fresh_access_token());
        $this->assertSame([], $this->sent);
    }

    public function test_unusable_lock_still_refreshes(): void
    {
        $this->seed_stale();

        // GET_LOCK returning NULL (no privilege, non-MySQL drop-in) must not
        // wedge the connection into never refreshing.
        $refresher = $this->refresher(
            ['access_token' => 'new-access', 'refresh_token' => 'rt-2', 'expires_in' => 3600],
            static fn () => null
        );

        $this->assertSame('new-access', $refresher->ensure_fresh_access_token());
    }

    // ---- transient failures leave the bundle untouched ----------------------

    /** @return array<string,array{0:mixed}> */
    public function transient_responses(): array
    {
        return [
            'network error / 5xx' => [new \WP_Error('cloud_unavailable', 'HTTP 503')],
            'malformed 2xx body'  => [['token_type' => 'Bearer']],
            'zero expiry'         => [['access_token' => 'x', 'expires_in' => 0]],
        ];
    }

    /**
     * @dataProvider transient_responses
     * @param mixed $response
     */
    public function test_transient_failure_leaves_the_bundle_untouched($response): void
    {
        $this->seed_stale();
        $before = Cloud_Credentials::all(true);

        $this->assertNull($this->refresher($response)->ensure_fresh_access_token());
        $this->assertSame($before, Cloud_Credentials::all(true));
        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION));
    }

    // ---- genuine rejection --------------------------------------------------

    public function test_un_raced_rejection_marks_the_connection_unhealthy(): void
    {
        $this->seed_stale();

        $this->assertNull($this->refresher(['auth_rejected' => true])->ensure_fresh_access_token());

        $marker = get_option(Token_Refresher::HEALTH_OPTION);
        $this->assertIsArray($marker);
        $this->assertArrayHasKey('rejected_at', $marker);
        // The refresh token survives so a reconnect can be diagnosed.
        $this->assertSame('rt-1', Cloud_Credentials::all(true)['refresh_token']);
    }

    public function test_unhealthy_connection_backs_off_instead_of_re_presenting_the_token(): void
    {
        $this->seed_stale();
        update_option(Token_Refresher::HEALTH_OPTION, ['rejected_at' => time()], false);

        $this->assertNull($this->refresher(['auth_rejected' => true])->ensure_fresh_access_token());
        $this->assertSame([], $this->sent);
    }

    public function test_backoff_expires_and_the_refresh_is_retried(): void
    {
        $this->seed_stale();
        update_option(
            Token_Refresher::HEALTH_OPTION,
            ['rejected_at' => time() - Token_Refresher::UNHEALTHY_BACKOFF - 1],
            false
        );

        $token = $this->refresher([
            'access_token'  => 'new-access',
            'refresh_token' => 'rt-2',
            'expires_in'    => 3600,
        ])->ensure_fresh_access_token();

        $this->assertSame('new-access', $token);
        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION));
    }

    // ---- the real HTTP transport -------------------------------------------

    /** @return array<string,array{0:int,1:array,2:string}> */
    public function transport_statuses(): array
    {
        return [
            'rate limited'           => [429, [], 'transient'],
            'not found'              => [404, [], 'transient'],
            'forbidden'              => [403, [], 'transient'],
            'server error'           => [503, [], 'transient'],
            'invalid_client is not a revocation' => [400, ['error' => 'invalid_client'], 'transient'],
            'unsupported_grant_type' => [400, ['error' => 'unsupported_grant_type'], 'transient'],
            'invalid_grant'          => [400, ['error' => 'invalid_grant'], 'rejected'],
            'bare 401'               => [401, [], 'rejected'],
        ];
    }

    /**
     * @dataProvider transport_statuses
     * @param array<string,mixed> $body
     */
    public function test_transport_classifies_each_status(int $status, array $body, string $expected): void
    {
        $this->seed_stale();
        $filter = static fn () => [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $status, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
        add_filter('pre_http_request', $filter, 10, 3);

        try {
            $refresher = new Token_Refresher(static fn () => true, static function (): void {
            });
            $this->assertNull($refresher->ensure_fresh_access_token());
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        if ('rejected' === $expected) {
            $this->assertIsArray(get_option(Token_Refresher::HEALTH_OPTION));
        } else {
            $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION), 'a transient failure must not mark the connection unhealthy');
        }
    }

    public function test_transport_posts_a_form_encoded_grant_to_the_token_endpoint(): void
    {
        $this->seed_stale();
        $seen = [];
        $filter = static function ($pre, $args, $url) use (&$seen) {
            $seen = ['url' => $url, 'body' => $args['body'], 'headers' => $args['headers']];
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['access_token' => 'new-access', 'refresh_token' => 'rt-2', 'expires_in' => 3600]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $filter, 10, 3);

        try {
            $refresher = new Token_Refresher(static fn () => true, static function (): void {
            });
            $this->assertSame('new-access', $refresher->ensure_fresh_access_token());
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSame('https://cloud.example' . \WPMCP\Cloud\Cloud_Client::TOKEN_PATH, $seen['url']);
        $this->assertIsArray($seen['body'], 'the grant must be form-encoded, not a JSON envelope');
        $this->assertSame('refresh_token', $seen['body']['grant_type']);
        $this->assertSame('rt-1', $seen['body']['refresh_token']);
        $this->assertSame('application/x-www-form-urlencoded', $seen['headers']['Content-Type']);
    }
}
