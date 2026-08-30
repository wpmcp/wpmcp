<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Auth\PKCE;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Oauth;
use WPMCP\Cloud\Settings_Sync;
use WPMCP\Cloud\Token_Vault;
use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Pro\Gate;
use WPMCP\Skills\Skills_Module;
use WPMCP\Tools\Cloud\Cloud_Sync_Settings;

/**
 * Cloud MVP Phase B (issue #135): the encrypted token vault, the PKCE OAuth
 * connect flow, and settings sync over the governance-option allowlist.
 *
 * HTTP is faked through pre_http_request exactly like CloudSyncTest, so no
 * live network is involved.
 */
class CloudPhaseBTest extends \WP_UnitTestCase
{
    /** @var array<int,array{url:string,method:string,body:mixed,headers:array}> */
    private array $requests = [];

    /** @var callable|null */
    private $responder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'secret-key');
        Token_Vault::clear();
        $this->requests  = [];
        $this->responder = null;
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        Token_Vault::clear();
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        delete_option(Governance::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Skills_Module::OPTION);
        delete_option(Exposure::OPTION);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function fake_http($pre, $args, $url)
    {
        $body = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
        if (null === $body && isset($args['body']) && is_array($args['body'])) {
            $body = $args['body'];
        }
        $this->requests[] = [
            'url'     => $url,
            'method'  => strtoupper((string) ($args['method'] ?? 'GET')),
            'body'    => $body,
            'headers' => $args['headers'] ?? [],
        ];

        if (null !== $this->responder) {
            return ($this->responder)($url, $args, count($this->requests));
        }

        return [
            'headers'  => [],
            'body'     => wp_json_encode([]),
            'response' => ['code' => 200, 'message' => 'OK'],
        ];
    }

    private static function json(array $data, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode($data),
            'response' => ['code' => $code, 'message' => 'OK'],
        ];
    }

    // ---- Token_Vault: sealed storage ---------------------------------------

    public function test_store_and_read_round_trips_the_bundle(): void
    {
        $this->assertTrue(Token_Vault::store('at', 'rt', 1234567890, 'https://cloud.example'));

        $bundle = Token_Vault::read();
        $this->assertIsArray($bundle);
        $this->assertSame('at', $bundle['access_token']);
        $this->assertSame('rt', $bundle['refresh_token']);
        $this->assertSame(1234567890, $bundle['expires_at']);
        $this->assertSame('https://cloud.example', $bundle['issuer']);
    }

    public function test_stored_blob_is_not_plaintext(): void
    {
        Token_Vault::store('super-secret-access', 'rt', time() + 60, 'https://cloud.example');
        $raw = (string) get_option('wpmcp_cloud_token_bundle', '');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('super-secret-access', $raw);
        $this->assertStringStartsWith('wpmcp_v1:', $raw);
    }

    public function test_read_returns_null_on_tampered_blob(): void
    {
        Token_Vault::store('at', 'rt', time() + 60, 'https://cloud.example');
        $raw = (string) get_option('wpmcp_cloud_token_bundle', '');
        // Flip a byte inside the ciphertext segment.
        $parts        = explode(':', $raw);
        $payload      = base64_decode($parts[2], true);
        $payload[30]  = ('A' === $payload[30]) ? 'B' : 'A';
        $parts[2]     = base64_encode($payload);
        update_option('wpmcp_cloud_token_bundle', implode(':', $parts));

        $this->assertNull(Token_Vault::read());
    }

    public function test_read_returns_null_when_the_key_fingerprint_does_not_match(): void
    {
        Token_Vault::store('at', 'rt', time() + 60, 'https://cloud.example');
        $raw      = (string) get_option('wpmcp_cloud_token_bundle', '');
        $parts    = explode(':', $raw);
        $parts[1] = 'deadbeef';
        update_option('wpmcp_cloud_token_bundle', implode(':', $parts));

        $this->assertNull(Token_Vault::read());
    }

    public function test_read_returns_null_on_garbage_and_clear_removes_the_bundle(): void
    {
        update_option('wpmcp_cloud_token_bundle', 'not-a-sealed-blob');
        $this->assertNull(Token_Vault::read());
        $this->assertFalse(Token_Vault::has_bundle());

        Token_Vault::store('at', 'rt', time() + 60, 'https://cloud.example');
        $this->assertTrue(Token_Vault::has_bundle());
        Token_Vault::clear();
        $this->assertNull(Token_Vault::read());
    }

    // ---- Token_Vault: refresh mutex ----------------------------------------

    public function test_with_refresh_lock_rotates_and_stores_the_new_bundle(): void
    {
        Token_Vault::store('old', 'old-refresh', time() - 10, 'https://cloud.example');

        $out = Token_Vault::with_refresh_lock(static function (array $bundle): array {
            return [
                'access_token'  => 'new-' . $bundle['refresh_token'],
                'refresh_token' => 'rotated',
                'expires_at'    => 999,
                'issuer'        => $bundle['issuer'],
            ];
        });

        $this->assertIsArray($out);
        $this->assertSame('new-old-refresh', $out['access_token']);
        $this->assertSame('new-old-refresh', Token_Vault::read()['access_token']);
        // The lock is always released.
        $this->assertFalse(Token_Vault::is_refresh_locked());
    }

    public function test_losing_the_lock_without_a_rotation_returns_a_retryable_error(): void
    {
        Token_Vault::store('old', 'old-refresh', time() - 10, 'https://cloud.example');
        $this->assertTrue(Token_Vault::acquire_refresh_lock_for_tests());

        $out = Token_Vault::with_refresh_lock(
            static function (array $bundle): array {
                throw new \LogicException('rotate must not run while another worker holds the lock');
            },
            'old'
        );

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_refresh_race', $out->get_error_code());
    }

    public function test_losing_the_lock_after_a_rotation_adopts_the_winners_bundle(): void
    {
        Token_Vault::store('old', 'old-refresh', time() - 10, 'https://cloud.example');
        $this->assertTrue(Token_Vault::acquire_refresh_lock_for_tests());
        // The winner finished rotating while we were blocked.
        Token_Vault::store('winner', 'winner-refresh', time() + 600, 'https://cloud.example');

        $out = Token_Vault::with_refresh_lock(
            static function (array $bundle): array {
                throw new \LogicException('rotate must not run for the loser');
            },
            'old' // the access token this worker actually presented and had refused
        );

        $this->assertIsArray($out);
        $this->assertSame('winner', $out['access_token']);
    }

    public function test_a_stale_lock_is_stolen(): void
    {
        global $wpdb;
        Token_Vault::store('old', 'old-refresh', time() - 10, 'https://cloud.example');
        $this->assertTrue(Token_Vault::acquire_refresh_lock_for_tests());
        // Backdate the lock well past its TTL.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                (string) (time() - 3600),
                'wpmcp_cloud_token_refresh_lock'
            )
        );
        wp_cache_flush();

        $out = Token_Vault::with_refresh_lock(static fn (array $b): array => [
            'access_token'  => 'stolen',
            'refresh_token' => 'r',
            'expires_at'    => 1,
            'issuer'        => $b['issuer'],
        ]);

        $this->assertIsArray($out);
        $this->assertSame('stolen', $out['access_token']);
    }

    // ---- Cloud_Config: which credential goes on the wire --------------------

    public function test_bearer_token_prefers_a_live_vault_token(): void
    {
        Token_Vault::store('vault-token', 'r', time() + 600, 'https://cloud.example');
        $this->assertSame('vault-token', Cloud_Config::bearer_token());
    }

    public function test_bearer_token_falls_back_to_the_api_key_when_the_bundle_expired(): void
    {
        Token_Vault::store('vault-token', 'r', time() - 5, 'https://cloud.example');
        $this->assertSame('secret-key', Cloud_Config::bearer_token());
    }

    public function test_bearer_token_ignores_a_bundle_issued_by_a_different_cloud(): void
    {
        Token_Vault::store('other-cloud-token', 'r', time() + 600, 'https://other.example');
        $this->assertSame('secret-key', Cloud_Config::bearer_token());
    }

    public function test_reconnecting_clears_the_vault(): void
    {
        Token_Vault::store('stale', 'r', time() + 600, 'https://cloud.example');
        Cloud_Config::set('https://new-cloud.example', 'new-key');

        $this->assertNull(Token_Vault::read());
        $this->assertSame('new-key', Cloud_Config::bearer_token());
    }

    // ---- Cloud_Oauth --------------------------------------------------------

    public function test_begin_builds_a_valid_s256_authorize_request(): void
    {
        $out = Cloud_Oauth::begin('https://cloud.example/');
        $this->assertIsArray($out);

        $query = [];
        parse_str((string) wp_parse_url($out['url'], PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['client_id']);
        $this->assertNotEmpty($query['scope']);
        $this->assertSame($out['state'], $query['state']);

        $pending = get_option('wpmcp_cloud_oauth_state');
        $this->assertSame(
            PKCE::challenge_from_verifier($pending['verifier']),
            $query['code_challenge']
        );
    }

    public function test_exchange_rejects_a_state_mismatch_and_burns_the_pending_state(): void
    {
        Cloud_Oauth::begin('https://cloud.example');

        $out = Cloud_Oauth::exchange('code-123', 'not-the-state');

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('oauth_state_mismatch', $out->get_error_code());
        $this->assertFalse(get_option('wpmcp_cloud_oauth_state', false));
    }

    public function test_exchange_rejects_an_empty_state_against_a_missing_pending_record(): void
    {
        delete_option('wpmcp_cloud_oauth_state');
        $out = Cloud_Oauth::exchange('code-123', '');
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('oauth_state_mismatch', $out->get_error_code());
    }

    public function test_exchange_rejects_an_expired_pending_state(): void
    {
        $begun   = Cloud_Oauth::begin('https://cloud.example');
        $pending  = get_option('wpmcp_cloud_oauth_state');
        $pending['created'] = time() - 7200;
        update_option('wpmcp_cloud_oauth_state', $pending);

        $out = Cloud_Oauth::exchange('code-123', $begun['state']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('oauth_state_expired', $out->get_error_code());
        $this->assertFalse(get_option('wpmcp_cloud_oauth_state', false));
    }

    public function test_exchange_seals_the_token_bundle_and_clears_the_pending_state(): void
    {
        $this->responder = fn (string $url, array $args) => self::json([
            'access_token'  => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in'    => 3600,
        ]);

        $begun = Cloud_Oauth::begin('https://cloud.example');
        $out   = Cloud_Oauth::exchange('code-123', $begun['state']);

        $this->assertTrue($out);
        $bundle = Token_Vault::read();
        $this->assertSame('fresh-access', $bundle['access_token']);
        $this->assertSame('fresh-refresh', $bundle['refresh_token']);
        $this->assertGreaterThan(time(), $bundle['expires_at']);
        $this->assertSame('https://cloud.example', $bundle['issuer']);
        $this->assertFalse(get_option('wpmcp_cloud_oauth_state', false));

        $token_request = end($this->requests);
        $this->assertStringEndsWith('/wpmcp-cloud/v1/oauth/token', $token_request['url']);
        $this->assertSame('authorization_code', $token_request['body']['grant_type']);
        $this->assertSame('code-123', $token_request['body']['code']);
        $this->assertNotEmpty($token_request['body']['code_verifier']);
        $this->assertNotEmpty($token_request['body']['client_id']);
    }

    public function test_refresh_rotates_the_bundle_through_the_vault(): void
    {
        Token_Vault::store('old-access', 'old-refresh', time() - 5, 'https://cloud.example');
        $this->responder = fn () => self::json([
            'access_token'  => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in'    => 3600,
        ]);

        $out = Cloud_Oauth::refresh();

        $this->assertIsArray($out);
        $this->assertSame('rotated-access', Token_Vault::read()['access_token']);
        $this->assertSame('refresh_token', $this->requests[0]['body']['grant_type']);
        $this->assertSame('old-refresh', $this->requests[0]['body']['refresh_token']);
    }

    // ---- Settings_Sync ------------------------------------------------------

    public function test_allowlist_holds_only_real_option_names(): void
    {
        $expected = [
            Governance::OPTION,
            Tool_Exposure::OPTION,
            Exposure::OPTION,
            Skills_Module::OPTION,
        ];
        sort($expected);
        $actual = Settings_Sync::allowlist();
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_export_omits_unset_options(): void
    {
        delete_option(Governance::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Skills_Module::OPTION);
        delete_option(Exposure::OPTION);

        $this->assertSame([], Settings_Sync::export());
    }

    public function test_export_returns_the_stored_governance_posture(): void
    {
        Governance::set_domain_toggle('database', false);
        update_option(Tool_Exposure::OPTION, 'compact');

        $payload = Settings_Sync::export();

        $this->assertSame('compact', $payload[ Tool_Exposure::OPTION ]);
        $this->assertFalse($payload[ Governance::OPTION ]['domain']['database']);
        $this->assertArrayNotHasKey(Skills_Module::OPTION, $payload);
    }

    public function test_apply_writes_allowlisted_options_and_drops_everything_else(): void
    {
        $out = Settings_Sync::apply([
            Tool_Exposure::OPTION => 'compact',
            'active_plugins'      => ['evil/evil.php'],
            'wpmcp_cloud_key'     => 'stolen',
            'wpmcp_allow_php_exec' => true,
        ]);

        $this->assertSame([Tool_Exposure::OPTION], $out['applied']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));
        $this->assertFalse(get_option('wpmcp_allow_php_exec', false));
        $this->assertNotSame('stolen', get_option('wpmcp_cloud_key'));
        $reasons = array_column($out['skipped'], 'reason', 'key');
        $this->assertSame('not allowlisted', $reasons['active_plugins']);
    }

    public function test_apply_rejects_a_value_of_the_wrong_shape(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([
            Tool_Exposure::OPTION => ['not', 'a', 'mode'],
            Governance::OPTION    => 'not-a-toggle-map',
        ]);

        $this->assertSame([], $out['applied']);
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
        $this->assertCount(2, $out['skipped']);
    }

    public function test_apply_coerces_the_governance_toggle_map(): void
    {
        $out = Settings_Sync::apply([
            Governance::OPTION => [
                'domain'    => ['database' => false, 'media' => 1],
                'operation' => ['delete' => false],
                'junk'      => ['x' => true],
            ],
        ]);

        $this->assertSame([Governance::OPTION], $out['applied']);
        $stored = get_option(Governance::OPTION);
        $this->assertSame(['ability', 'domain', 'operation'], array_keys($stored));
        $this->assertFalse($stored['domain']['database']);
        $this->assertTrue($stored['domain']['media']);
        $this->assertArrayNotHasKey('junk', $stored);
    }

    public function test_apply_is_rollback_able_through_safe_mutation(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));

        $rolled = (new \WPMCP\Tools\Rollback_Operation())->handle([
            'operation_id' => $out['operation_ids'][0],
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $rolled);
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    public function test_apply_requires_the_paid_cloud_entitlement(): void
    {
        Gate::set_pro_for_tests(false);
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_pro_only', $out->get_error_code());
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    public function test_apply_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_forbidden', $out->get_error_code());
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    // ---- cloud-sync-settings tool ------------------------------------------

    public function test_preview_tool_reports_the_real_posture(): void
    {
        update_option(Tool_Exposure::OPTION, 'compact');

        $out = (new Cloud_Sync_Settings())->handle([]);

        $this->assertSame(1, $out['count']);
        $this->assertSame('compact', $out['payload'][ Tool_Exposure::OPTION ]);
        $this->assertContains(Governance::OPTION, $out['allowlist']);
    }
}
