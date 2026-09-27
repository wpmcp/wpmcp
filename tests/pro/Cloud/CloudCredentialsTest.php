<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Token_Refresher;

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

    public function test_connecting_over_legacy_plaintext_options_removes_them(): void
    {
        // The reconnect path: a legacy connected site runs cloud-connect, so
        // the vault is WRITTEN before anything ever reads it and the read-path
        // migration never fires. The plaintext key must still not survive.
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'legacy-key');

        Cloud_Config::set('https://cloud.example', 'new-key');

        $this->assertFalse(get_option('wpmcp_cloud_key'), 'the plaintext api key must not survive a connect');
        $this->assertFalse(get_option('wpmcp_cloud_url'));
        $this->assertSame('new-key', Cloud_Config::api_key());
    }

    public function test_write_reports_failure_when_the_sealed_blob_does_not_read_back(): void
    {
        $block = static fn () => '';
        add_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10, 1);

        try {
            $this->assertFalse(Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'k']));
            $this->assertFalse(Cloud_Credentials::merge(['api_key' => 'k']));
        } finally {
            remove_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10);
        }
    }

    public function test_replace_clears_a_stale_refresh_health_marker(): void
    {
        // The phase 2 connect flow writes a token bundle through replace()
        // directly, without going through the Cloud_Config facade. A brand-new
        // bundle must not inherit the previous one's rejection backoff.
        update_option(Token_Refresher::HEALTH_OPTION, ['rejected_at' => time()], false);

        Cloud_Credentials::replace([
            'base_url'      => 'https://cloud.example',
            'refresh_token' => 'rt-new',
            'client_id'     => 'client-1',
        ]);

        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION));
        $this->assertFalse(Token_Refresher::is_unhealthy());
    }

    public function test_forced_read_sees_the_option_after_it_was_cached_as_absent(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'k']);

        // Stand in for a request that read the vault before it existed: the
        // options cache remembers the miss in its notoptions entry, which a
        // plain cache delete does not clear.
        wp_cache_delete(Cloud_Credentials::OPTION, 'options');
        wp_cache_set('notoptions', [Cloud_Credentials::OPTION => true], 'options');

        $this->assertSame('k', Cloud_Credentials::all(true)['api_key'] ?? null);
    }

    // ---- salt rotation --------------------------------------------------------

    public function test_a_rotated_auth_salt_reads_as_unreadable_and_keeps_the_ciphertext(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-sealed']);
        $sealed = (string) get_option(Cloud_Credentials::OPTION);

        $rotated = static fn () => 'a-freshly-generated-auth-salt';
        add_filter('salt', $rotated);

        try {
            // Documented behaviour: the vault cannot be opened under the new
            // key, so the site reads as not connected (never as a plaintext
            // fallback), cloud-status says why, and the sealed blob is left
            // exactly as it was.
            $this->assertSame([], Cloud_Credentials::all(true));
            $this->assertTrue(Cloud_Credentials::is_unreadable());
            $this->assertFalse(Cloud_Config::is_configured());
            $this->assertSame($sealed, get_option(Cloud_Credentials::OPTION), 'an unreadable vault must not be overwritten or deleted by a read');
        } finally {
            remove_filter('salt', $rotated);
        }

        // Restoring the previous salts (a rolled-back wp-config) recovers the
        // credentials, which is why the ciphertext is kept.
        $this->assertSame('sk-sealed', Cloud_Credentials::all(true)['api_key'] ?? null);
        $this->assertFalse(Cloud_Credentials::is_unreadable());
    }

    public function test_reconnecting_after_a_salt_rotation_reseals_under_the_new_key(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-old']);

        $rotated = static fn () => 'a-freshly-generated-auth-salt';
        add_filter('salt', $rotated);

        try {
            $this->assertTrue(Cloud_Config::set('https://cloud.example', 'sk-new'));
            $this->assertSame('sk-new', Cloud_Credentials::all(true)['api_key'] ?? null);
            $this->assertFalse(Cloud_Credentials::is_unreadable());
        } finally {
            remove_filter('salt', $rotated);
        }
    }

    // ---- secret hygiene ---------------------------------------------------------

    public function test_redact_scrubs_every_stored_secret_from_a_message(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'api_key'           => 'sk-live-123456',
            'access_token'      => 'at-abcdef',
            'refresh_token'     => 'rt-ghijkl',
            'access_expires_at' => time() + 3600,
            'client_id'         => 'client-1',
            'client_secret'     => 'cs-mnopqr',
        ]);

        $out = Cloud_Credentials::redact('key sk-live-123456, at-abcdef, rt-ghijkl and cs-mnopqr for client-1');

        foreach (['sk-live-123456', 'at-abcdef', 'rt-ghijkl', 'cs-mnopqr'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertStringContainsString('client-1', $out, 'a client id is an identifier, not a secret');
        $this->assertSame('nothing secret here', Cloud_Credentials::redact('nothing secret here'));
    }

    public function test_fingerprint_is_keyed_rather_than_a_bare_hash_of_the_secret(): void
    {
        $fingerprint = Cloud_Credentials::fingerprint('rt-1');

        $this->assertSame(64, strlen($fingerprint));
        $this->assertNotSame(hash('sha256', 'rt-1'), $fingerprint, 'an unkeyed digest of a token is an offline oracle for it');
        $this->assertSame($fingerprint, Cloud_Credentials::fingerprint('rt-1'));
        $this->assertNotSame($fingerprint, Cloud_Credentials::fingerprint('rt-2'));
    }

    public function test_the_generic_option_tools_refuse_every_cloud_credential_option(): void
    {
        foreach ([Cloud_Credentials::OPTION, 'wpmcp_cloud_key', Token_Refresher::HEALTH_OPTION] as $name) {
            $this->assertTrue(\WPMCP\Tools\Meta\Option_Guard::is_denylisted($name), $name . ' must not be readable or writable through the option tools');
        }
    }

    // ---- eager migration ----------------------------------------------------------

    public function test_boot_migration_imports_autoloaded_plaintext_without_a_cloud_call(): void
    {
        update_option('wpmcp_cloud_url', 'https://cloud.example', true);
        update_option('wpmcp_cloud_key', 'legacy-key', true);

        Cloud_Credentials::maybe_migrate_on_boot();

        $this->assertFalse(get_option('wpmcp_cloud_key'), 'an updated site must not keep the plaintext key until some cloud tool happens to run');
        $this->assertFalse(get_option('wpmcp_cloud_url'));
        $stored = (string) get_option(Cloud_Credentials::OPTION);
        $this->assertStringNotContainsString('legacy-key', $stored);
        $this->assertSame('legacy-key', Cloud_Credentials::all(true)['api_key'] ?? null);
    }

    public function test_boot_migration_writes_nothing_on_a_site_without_legacy_options(): void
    {
        Cloud_Credentials::maybe_migrate_on_boot();

        $this->assertFalse(get_option(Cloud_Credentials::OPTION));
    }
}
