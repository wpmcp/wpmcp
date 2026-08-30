<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;

/**
 * Issue #141 phase 1: the encrypted cloud credential vault.
 *
 * Covers the crypto round-trip, corrupted-ciphertext handling, and the
 * transparent plaintext migration (including its refusal to delete the
 * plaintext copies when the sealed write does not come back). The refresher
 * branches live in TokenRefresherTest and the client auth-resolution order
 * in CloudAuthResolutionTest.
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

    public function test_migration_keeps_the_plaintext_options_when_the_sealed_write_does_not_land(): void
    {
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'legacy-key');

        // Simulate a failing write (full disk, a filtering plugin, an encrypt
        // failure): the vault stays empty, so the only copy of the credentials
        // is still the plaintext pair and deleting it would be unrecoverable.
        $block = static fn () => '';
        add_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10, 1);

        try {
            $this->assertSame('legacy-key', Cloud_Config::api_key());
        } finally {
            remove_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10);
        }

        $this->assertSame('https://cloud.example', get_option('wpmcp_cloud_url'));
        $this->assertSame('legacy-key', get_option('wpmcp_cloud_key'));
    }

    public function test_all_reflects_a_write_made_by_another_request_when_forced(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'k']);
        $this->assertSame('k', Cloud_Credentials::all()['api_key']);

        // Stand in for a concurrent process: write the option behind the
        // per-request caches, the way a second PHP worker would.
        global $wpdb;
        $sealed = get_option(Cloud_Credentials::OPTION);
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'rotated']);
        $rotated = get_option(Cloud_Credentials::OPTION);
        $wpdb->update($wpdb->options, ['option_value' => $sealed], ['option_name' => Cloud_Credentials::OPTION]);
        wp_cache_set(Cloud_Credentials::OPTION, $sealed, 'options');
        $wpdb->update($wpdb->options, ['option_value' => $rotated], ['option_name' => Cloud_Credentials::OPTION]);

        $this->assertSame('rotated', Cloud_Credentials::all(true)['api_key']);
    }
}
