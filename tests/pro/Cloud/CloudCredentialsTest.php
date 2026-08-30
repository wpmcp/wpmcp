<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;

/**
 * Issue #141 phase 1: the encrypted cloud credential vault.
 *
 * Covers the crypto round-trip, corrupted-ciphertext handling, and the
 * transparent plaintext migration. The Token_Refresher branch tests
 * (winner, both race-loser shapes, lock-timeout bail, 5xx untouched
 * bundle, genuine rejection, success merge) and the client auth-resolution
 * order land next in this phase.
 */
class CloudCredentialsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cloud_Credentials::clear();
    }

    protected function tearDown(): void
    {
        Cloud_Credentials::clear();
        parent::tearDown();
    }

    public function test_round_trip_never_stores_plaintext(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-visible-nowhere']);

        $this->assertSame('sk-visible-nowhere', Cloud_Credentials::get('api_key'));

        $stored = (string) get_option(Cloud_Credentials::OPTION);
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('sk-visible-nowhere', $stored);
        $this->assertStringNotContainsString('sk-visible-nowhere', (string) base64_decode($stored, true));
    }

    public function test_corrupted_ciphertext_reads_as_not_connected(): void
    {
        update_option(Cloud_Credentials::OPTION, base64_encode(random_bytes(80)), false);

        $this->assertSame([], Cloud_Credentials::all());
        $this->assertNull(Cloud_Credentials::get('api_key'));
    }

    public function test_plaintext_options_migrate_into_vault_and_are_deleted(): void
    {
        update_option('wpmcp_cloud_url', 'https://cloud.example/');
        update_option('wpmcp_cloud_key', 'legacy-key');

        $this->assertSame('https://cloud.example', Cloud_Config::base_url());
        $this->assertSame('legacy-key', Cloud_Config::api_key());
        $this->assertFalse(get_option('wpmcp_cloud_url'));
        $this->assertFalse(get_option('wpmcp_cloud_key'));
        $this->assertTrue(Cloud_Config::is_configured());
    }

    public function test_merge_preserves_unrelated_fields(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'k']);
        Cloud_Credentials::merge(['access_token' => 't', 'access_expires_at' => time() + 3600]);

        $all = Cloud_Credentials::all();
        $this->assertSame('k', $all['api_key']);
        $this->assertSame('t', $all['access_token']);
    }
}
