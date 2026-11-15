<?php

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Query;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Secret_Options;
use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #865: one registry of secret options, so a generic option read
 * (get-option, update-option's response, the database query tool) masks a
 * credential the way the dedicated settings tools do. Three classes are
 * covered: whole options, named keys inside an option array, and the
 * key-name heuristic for options nobody registered.
 */
class SecretOptionsTest extends \WP_UnitTestCase
{
    private const MASK = '[redacted]';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ([ 'wp_rocket_settings', 'FLYING_PRESS_CONFIG', 'wp_mail_smtp', 'wpmcp_test_unregistered', 'wpmcp_test_custom_secret', 'updraft_s3', 'imagify_settings', 'mc4wp', 'sib_main_option', 'backwpup_jobs', 'weglot-translate-v3', 'wordpress_api_key', 'sib_token_store' ] as $name) {
            delete_option($name);
        }
        remove_all_filters('wpmcp_enable_option_write');
        remove_all_filters('wpmcp_secret_options');
        remove_all_filters('wpmcp_secret_key_allowlist');
        remove_all_filters('wpmcp_option_denylist_patterns');
        parent::tearDown();
    }

    // ---- Class 1: a cache plugin's credentials, through get-option (the issue's cases).

    public function test_get_option_masks_every_wp_rocket_credential(): void
    {
        $stored = [ 'lazyload' => 1, 'minify_css' => 1 ];
        foreach ((new \WPMCP\Integrations\Cache_Settings_WP_Rocket())->secret_keys() as $key) {
            $stored[ $key ] = 'real-' . $key;
        }
        update_option('wp_rocket_settings', $stored);

        $value = (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value'];

        foreach ((new \WPMCP\Integrations\Cache_Settings_WP_Rocket())->secret_keys() as $key) {
            $this->assertSame(self::MASK, $value[ $key ], "wp_rocket_settings.$key must be masked");
        }
        $this->assertSame(1, $value['lazyload'], 'a plain setting stays readable');
        $this->assertStringNotContainsString('real-', wp_json_encode($value));
    }

    public function test_get_option_masks_every_flyingpress_credential(): void
    {
        $stored = [ 'lazy_load' => true ];
        foreach ((new \WPMCP\Integrations\Cache_Settings_FlyingPress())->secret_keys() as $key) {
            $stored[ $key ] = 'real-' . $key;
        }
        update_option('FLYING_PRESS_CONFIG', $stored);

        $value = (new Get_Option())->handle([ 'name' => 'FLYING_PRESS_CONFIG' ])['value'];

        foreach ((new \WPMCP\Integrations\Cache_Settings_FlyingPress())->secret_keys() as $key) {
            $this->assertSame(self::MASK, $value[ $key ], "FLYING_PRESS_CONFIG.$key must be masked");
        }
        $this->assertTrue($value['lazy_load']);
    }

    /** The registry's literal lists must not drift from the settings reads' lists. */
    public function test_the_registry_matches_the_cache_adapters_secret_keys(): void
    {
        $registry = Secret_Options::registry();
        $rocket   = new \WPMCP\Integrations\Cache_Settings_WP_Rocket();
        $fly      = new \WPMCP\Integrations\Cache_Settings_FlyingPress();

        $this->assertEqualsCanonicalizing($rocket->secret_keys(), $registry[ $rocket->option() ]);
        $this->assertEqualsCanonicalizing($fly->secret_keys(), $registry[ $fly->option() ]);
    }

    public function test_an_unset_credential_stays_empty_so_not_set_is_visible(): void
    {
        update_option('wp_rocket_settings', [ 'cloudflare_api_key' => '', 'secret_cache_key' => 'abc' ]);

        $value = (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value'];

        $this->assertSame('', $value['cloudflare_api_key']);
        $this->assertSame(self::MASK, $value['secret_cache_key']);
    }

    // ---- Class 2: registered names, exact and nested.

    public function test_a_registered_nested_key_without_a_secret_looking_name_is_masked(): void
    {
        // "pass" and the nested license "key" do not look like secrets to the heuristic.
        $stored = [
            'mail'    => [ 'from_email' => 'a@example.com', 'mailer' => 'smtp' ],
            'smtp'    => [ 'host' => 'smtp.example.com', 'user' => 'bob', 'pass' => 'hunter2' ],
            'license' => [ 'key' => 'LIC-123' ],
            'mailgun' => [ 'api_key' => 'mg-1', 'domain' => 'mg.example.com' ],
        ];
        update_option('wp_mail_smtp', $stored);

        $value = Secret_Options::mask_option('wp_mail_smtp', get_option('wp_mail_smtp'));

        $this->assertSame(self::MASK, $value['smtp']['pass']);
        $this->assertSame(self::MASK, $value['license']['key']);
        $this->assertSame(self::MASK, $value['mailgun']['api_key']);
        $this->assertSame('smtp.example.com', $value['smtp']['host']);
        $this->assertSame('bob', $value['smtp']['user']);
        $this->assertSame('mg.example.com', $value['mailgun']['domain']);
        $this->assertSame('a@example.com', $value['mail']['from_email']);
    }

    public function test_a_prefix_registered_option_masks_remote_storage_credentials_at_any_depth(): void
    {
        // UpdraftPlus keeps one option per service with instance settings nested inside.
        update_option('updraft_s3', [ 'version' => 1, 'settings' => [ 's3-abc' => [ 'accesskey' => 'AKIAREAL', 'secretkey' => 'realsecret', 'path' => 'backups' ] ] ]);

        $value = Secret_Options::mask_option('updraft_s3', get_option('updraft_s3'));

        $this->assertSame(self::MASK, $value['settings']['s3-abc']['accesskey']);
        $this->assertSame(self::MASK, $value['settings']['s3-abc']['secretkey']);
        $this->assertSame('backups', $value['settings']['s3-abc']['path']);
    }

    /** @dataProvider whole_secret_options */
    public function test_a_whole_secret_option_is_masked_whatever_its_shape(string $name, $stored): void
    {
        update_option($name, $stored);

        $this->assertSame(self::MASK, Secret_Options::mask_option($name, get_option($name)), "$name must be masked whole");
    }

    public function whole_secret_options(): array
    {
        return [
            'ShortPixel API key'    => [ 'wp-short-pixel-apiKey', 'sp-real-key' ],
            'EWWW cloud key'        => [ 'ewww_image_optimizer_cloud_key', 'ewww-real-key' ],
            'Akismet key'           => [ 'wordpress_api_key', 'akismet-real-key' ],
            'Updraft encryption'    => [ 'updraft_encryptionphrase', 'a long phrase' ],
            'Post SMTP OAuth token' => [ 'postman_auth_token', [ 'access_token' => 'ya29.real', 'refresh_token' => 'r-real' ] ],
            'HubSpot access token'  => [ 'leadin_access_token', 'hub-real' ],
            'Brevo token store'     => [ 'sib_token_store', 'brevo-real' ],
            'WP Mail SMTP mail key' => [ 'wp_mail_smtp_mail_key', 'bWFpbGtleQ==' ],
            'BackWPup app secret'   => [ 'backwpup_cfg_dropboxappsecret', 'dbx-real' ],
            'Weglot private key'    => [ 'weglot-translate-api_key_private', 'wg_private_real' ],
        ];
    }

    /** @dataProvider nested_secret_options */
    public function test_each_registered_plugin_option_masks_its_credential_keys(string $name, array $stored, array $secret_paths, array $kept): void
    {
        update_option($name, $stored);

        $masked = Secret_Options::mask_option($name, get_option($name));

        foreach ($secret_paths as $path) {
            $this->assertSame(self::MASK, $this->path_value($masked, $path), "$name.$path must be masked");
        }
        foreach ($kept as $path => $expected) {
            $this->assertSame($expected, $this->path_value($masked, (string) $path), "$name.$path must stay readable");
        }
    }

    public function nested_secret_options(): array
    {
        return [
            'FluentSMTP connection'  => [ 'fluentmail-settings', [ 'connections' => [ 'abc' => [ 'provider_settings' => [ 'provider' => 'sendgrid', 'api_key' => 'SG.real', 'password' => 'pw', 'key_store' => 'db' ] ] ] ], [ 'connections.abc.provider_settings.api_key', 'connections.abc.provider_settings.password' ], [ 'connections.abc.provider_settings.provider' => 'sendgrid', 'connections.abc.provider_settings.key_store' => 'db' ] ],
            'Post SMTP options'      => [ 'postman_options', [ 'transport_type' => 'smtp', 'basic_auth_password' => 'pw', 'sendgrid_api_key' => 'SG.real', 'fallback_smtp_password' => 'fb' ], [ 'basic_auth_password', 'sendgrid_api_key', 'fallback_smtp_password' ], [ 'transport_type' => 'smtp' ] ],
            'BackWPup jobs'          => [ 'backwpup_jobs', [ 1 => [ 'name' => 'Nightly', 'dbpassword' => 'dbpw', 'ftppass' => 'ftpw', 's3secretkey' => 's3real', 'dropboxtoken' => 'dbx' ] ], [ '1.dbpassword', '1.ftppass', '1.s3secretkey', '1.dropboxtoken' ], [ '1.name' => 'Nightly' ] ],
            'Imagify settings'       => [ 'imagify_settings', [ 'api_key' => 'imagify-real', 'optimization_level' => 2 ], [ 'api_key' ], [ 'optimization_level' => 2 ] ],
            'Mailchimp for WP'       => [ 'mc4wp', [ 'api_key' => 'mc-real-us1', 'allow_usage_tracking' => 0 ], [ 'api_key' ], [ 'allow_usage_tracking' => 0 ] ],
            'Brevo main option'      => [ 'sib_main_option', [ 'access_key' => 'xkeysib-real', 'ma_key' => 'ma-real', 'sib_use_apiv2' => 1 ], [ 'access_key', 'ma_key' ], [ 'sib_use_apiv2' => 1 ] ],
            'Weglot settings'        => [ 'weglot-translate-v3', [ 'api_key' => 'wg_real', 'original_language' => 'en' ], [ 'api_key' ], [ 'original_language' => 'en' ] ],
        ];
    }

    public function test_the_registry_can_be_extended_with_a_filter(): void
    {
        update_option('wpmcp_test_custom_secret', [ 'endpoint' => 'https://x.example', 'sig' => 'abc123' ]);
        $this->assertSame('abc123', Secret_Options::mask_option('wpmcp_test_custom_secret', get_option('wpmcp_test_custom_secret'))['sig'], 'unregistered and not secret-looking: untouched');

        add_filter('wpmcp_secret_options', static function (array $registry): array {
            $registry['wpmcp_test_custom_secret'] = [ 'sig' ];
            return $registry;
        });

        $masked = Secret_Options::mask_option('wpmcp_test_custom_secret', get_option('wpmcp_test_custom_secret'));
        $this->assertSame(self::MASK, $masked['sig']);
        $this->assertSame('https://x.example', $masked['endpoint']);
    }

    // ---- Class 3: the heuristic.

    /** @dataProvider heuristic_keys */
    public function test_the_heuristic_masks_secret_looking_keys_in_an_unregistered_option(string $key): void
    {
        update_option('wpmcp_test_unregistered', [ 'label' => 'visible', 'nested' => [ $key => 'live-value' ] ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertSame(self::MASK, $value['nested'][ $key ], "$key must be masked by the heuristic");
        $this->assertSame('visible', $value['label']);
    }

    public function heuristic_keys(): array
    {
        return [
            'api_key'      => [ 'stripe_api_key' ],
            'apikey'       => [ 'apikey' ],
            'secret'       => [ 'client_secret' ],
            'password'     => [ 'smtp_password' ],
            'token'        => [ 'refresh_token' ],
            'license_key'  => [ 'license_key' ],
            'upper case'   => [ 'API_KEY' ],
        ];
    }

    public function test_the_heuristic_leaves_flags_and_empty_values_alone(): void
    {
        update_option('wpmcp_test_unregistered', [ 'require_password' => true, 'token_set' => false, 'api_key' => '', 'secret' => null ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertTrue($value['require_password']);
        $this->assertFalse($value['token_set']);
        $this->assertSame('', $value['api_key']);
        $this->assertNull($value['secret']);
    }

    public function test_the_allowlist_lets_a_key_through_the_heuristic(): void
    {
        update_option('wpmcp_test_unregistered', [ 'token_budget' => '500', 'api_key' => 'live' ]);
        add_filter('wpmcp_secret_key_allowlist', static function (array $keys): array {
            $keys[] = 'token_budget';
            return $keys;
        });

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertSame('500', $value['token_budget']);
        $this->assertSame(self::MASK, $value['api_key']);
    }

    public function test_the_allowlist_does_not_unmask_a_registered_credential(): void
    {
        update_option('wp_rocket_settings', [ 'secret_cache_key' => 'abc' ]);
        add_filter('wpmcp_secret_key_allowlist', static fn (array $keys): array => array_merge($keys, [ 'secret_cache_key' ]));

        $this->assertSame(self::MASK, (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value']['secret_cache_key']);
    }

    public function test_a_plain_option_comes_back_untouched(): void
    {
        update_option('wpmcp_test_unregistered', [ 'a' => 1, 'b' => [ 'c' => 'two' ] ]);
        $this->assertSame([ 'a' => 1, 'b' => [ 'c' => 'two' ] ], (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value']);

        update_option('wpmcp_test_unregistered', 'just text');
        $this->assertSame('just text', (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value']);
    }

    public function test_a_credential_stored_as_json_text_or_a_serialized_string_is_masked_in_the_same_form(): void
    {
        update_option('wp_rocket_settings', wp_json_encode([ 'secret_cache_key' => 'abc', 'lazyload' => 1 ]));
        $masked = (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value'];
        $this->assertSame([ 'secret_cache_key' => self::MASK, 'lazyload' => 1 ], json_decode($masked, true));

        update_option('wp_rocket_settings', serialize([ 'secret_cache_key' => 'abc', 'lazyload' => 1 ])); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- fixture for the stored-shape case.
        $masked = (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value'];
        $this->assertIsString($masked, 'a serialized string stays a serialized string');
        $this->assertSame([ 'secret_cache_key' => self::MASK, 'lazyload' => 1 ], unserialize($masked)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reads back the fixture this test wrote.
    }

    public function test_a_registered_option_with_an_unreadable_shape_is_masked_whole(): void
    {
        update_option('wp_rocket_settings', 'opaque-credential-blob');

        $this->assertSame(self::MASK, (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value']);
    }

    // ---- Writes: round-trip and echo.

    public function test_update_option_keeps_a_masked_credential_as_stored_and_never_echoes_a_real_one(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wp_rocket_settings', [ 'secret_cache_key' => 'stored-secret', 'minify_css_key' => 'stored-css', 'lazyload' => 0 ]);

        $read = (new Get_Option())->handle([ 'name' => 'wp_rocket_settings' ])['value'];
        $read['lazyload'] = 1;
        $out = (new Update_Option())->handle([ 'name' => 'wp_rocket_settings', 'value' => $read ]);

        $stored = get_option('wp_rocket_settings');
        $this->assertSame('stored-secret', $stored['secret_cache_key'], 'the mask must not overwrite the live credential');
        $this->assertSame('stored-css', $stored['minify_css_key']);
        $this->assertSame(1, $stored['lazyload']);
        $this->assertSame(self::MASK, $out['value']['secret_cache_key']);
        $this->assertStringNotContainsString('stored-secret', wp_json_encode($out));
    }

    public function test_update_option_response_masks_a_credential_it_just_wrote(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wp_mail_smtp', [ 'smtp' => [ 'host' => 'h', 'pass' => 'old' ] ]);

        $out = (new Update_Option())->handle([ 'name' => 'wp_mail_smtp', 'value' => [ 'smtp' => [ 'host' => 'h2', 'pass' => 'brand-new-pass' ] ] ]);

        $this->assertSame('brand-new-pass', get_option('wp_mail_smtp')['smtp']['pass'], 'a real new value is written');
        $this->assertSame(self::MASK, $out['value']['smtp']['pass']);
        $this->assertSame('h2', $out['value']['smtp']['host']);
        $this->assertStringNotContainsString('brand-new-pass', wp_json_encode($out));
    }

    public function test_a_masked_whole_option_written_back_keeps_the_stored_value(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('sib_token_store', 'brevo-live');

        (new Update_Option())->handle([ 'name' => 'sib_token_store', 'value' => self::MASK ]);

        $this->assertSame('brevo-live', get_option('sib_token_store'));
    }

    public function test_the_undo_point_still_restores_the_raw_credential(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wp_mail_smtp', [ 'smtp' => [ 'pass' => 'original-pass' ] ]);

        $out = (new Update_Option())->handle([ 'name' => 'wp_mail_smtp', 'value' => [ 'smtp' => [ 'pass' => 'changed-pass' ] ] ]);
        $this->assertSame('changed-pass', get_option('wp_mail_smtp')['smtp']['pass']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertSame('original-pass', get_option('wp_mail_smtp')['smtp']['pass']);
    }

    public function test_a_nested_masked_credential_is_restored_from_the_stored_path(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('updraft_s3', [ 'settings' => [ 'a' => [ 'secretkey' => 'keep-me', 'path' => 'old' ] ] ]);

        (new Update_Option())->handle([ 'name' => 'updraft_s3', 'value' => [ 'settings' => [ 'a' => [ 'secretkey' => self::MASK, 'path' => 'new' ] ] ] ]);

        $this->assertSame([ 'settings' => [ 'a' => [ 'secretkey' => 'keep-me', 'path' => 'new' ] ] ], get_option('updraft_s3'));
    }

    // ---- The database query tool.

    /** The query tool steers a registered option to get-option, even when option_name is selected. */
    public function test_the_query_tool_refuses_to_read_a_registered_option_row(): void
    {
        global $wpdb;
        update_option('wp_rocket_settings', [ 'secret_cache_key' => 'sql-secret', 'lazyload' => 1 ]);

        try {
            (new Query())->handle([ 'sql' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = 'wp_rocket_settings'" ]);
            $this->fail('query returned a registered credential option.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('get-option', $e->getMessage());
            $this->assertStringNotContainsString('sql-secret', $e->getMessage());
        }
    }

    /** Write tools' before-images still mask a row that carries its option name. */
    public function test_a_before_image_row_of_a_registered_option_is_masked(): void
    {
        $rows = \WPMCP\Tools\Database\Database_Guard::mask_gateway_secrets([
            [ 'option_name' => 'wp_rocket_settings', 'option_value' => serialize([ 'secret_cache_key' => 'img-secret', 'lazyload' => 1 ]) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- fixture.
        ]);

        $this->assertStringNotContainsString('img-secret', wp_json_encode($rows));
        $this->assertSame(1, unserialize($rows[0]['option_value'])['lazyload']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reads back the masked fixture.
    }

    public function test_the_query_tool_masks_a_heuristic_key_in_an_unregistered_row(): void
    {
        global $wpdb;
        update_option('wpmcp_test_unregistered', [ 'client_secret' => 'sql-heur', 'label' => 'x' ]);

        $out = (new Query())->handle([ 'sql' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_unregistered'" ]);

        $this->assertStringNotContainsString('sql-heur', wp_json_encode($out));
    }

    /** The registry works even when a site has trimmed the name denylist, so the read reaches it. */
    public function test_masking_does_not_depend_on_the_name_denylist(): void
    {
        add_filter('wpmcp_option_denylist_patterns', '__return_empty_array');
        update_option('wordpress_api_key', 'akismet-live');

        $this->assertSame(self::MASK, (new Get_Option())->handle([ 'name' => 'wordpress_api_key' ])['value']);
    }

    // ---- Review fixes (PR #917).

    /** A value-only select of a registered option must not return the credential. */
    public function test_a_value_only_select_of_a_registered_option_is_refused(): void
    {
        global $wpdb;
        update_option('wp_mail_smtp', [ 'smtp' => [ 'pass' => 'LEAKPASS' ] ]);

        foreach ([
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_mail_smtp'",
            "SELECT option_value AS v FROM {$wpdb->options} WHERE option_name = 'WP_MAIL_SMTP'",
            "SELECT * FROM {$wpdb->options} WHERE option_name = 'wp_mail_smtp'",
            "SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('siteurl', 'wp_mail_smtp')",
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_mail%'",
            "SELECT o.option_value FROM {$wpdb->options} o JOIN {$wpdb->options} p ON p.option_id = o.option_id WHERE o.option_name = 'wp_mail_smtp'",
            "SELECT REVERSE(option_value) FROM {$wpdb->options} WHERE option_name = 'wp_mail_smtp'",
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_mail_smtp' UNION SELECT 1",
            "SELECT option_value FROM wp_999_options WHERE option_name = 'wp_mail_smtp'",
        ] as $sql) {
            try {
                $out = (new Query())->handle([ 'sql' => $sql ]);
                $this->assertStringNotContainsString('LEAKPASS', wp_json_encode($out), $sql);
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString('LEAKPASS', $e->getMessage());
            }
        }
    }

    public function test_a_value_only_select_of_a_whole_secret_option_is_refused(): void
    {
        global $wpdb;
        update_option('sib_token_store', 'LEAKTOKEN');

        $this->expectException(\RuntimeException::class);
        (new Query())->handle([ 'sql' => "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'sib_token_store'" ]);
    }

    public function test_a_value_only_select_masks_a_heuristic_key_and_refuses_a_transform(): void
    {
        global $wpdb;
        update_option('wpmcp_test_unregistered', [ 'client_secret' => 'LEAKHEUR', 'label' => 'x' ]);

        $out = (new Query())->handle([ 'sql' => "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_unregistered'" ]);
        $this->assertStringNotContainsString('LEAKHEUR', wp_json_encode($out));
        $this->assertSame('x', maybe_unserialize($out['rows'][0]['option_value'])['label']);

        $this->expectException(\RuntimeException::class);
        (new Query())->handle([ 'sql' => "SELECT REVERSE(option_value) FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_unregistered'" ]);
    }

    public function test_the_query_tool_cannot_read_a_registered_site_option_from_sitemeta(): void
    {
        global $wpdb;
        $this->expectException(\RuntimeException::class);
        (new Query())->handle([ 'sql' => "SELECT meta_value FROM {$wpdb->base_prefix}sitemeta WHERE meta_key = 'backwpup_jobs'" ]);
    }

    public function test_nested_json_and_serialized_strings_are_masked(): void
    {
        update_option('wpmcp_test_unregistered', [
            'cfg' => '{"api_key":"NESTJSON","label":"keep"}',
            'ser' => serialize([ 'password' => 'NESTSER', 'host' => 'h' ]), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- fixture.
        ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertStringNotContainsString('NESTJSON', wp_json_encode($value));
        $this->assertStringNotContainsString('NESTSER', wp_json_encode($value));
        $this->assertSame('keep', json_decode($value['cfg'], true)['label']);
        $this->assertSame('h', unserialize($value['ser'])['host']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reads back the masked fixture.
    }

    public function test_the_heuristic_leaves_numbers_and_booleans_readable(): void
    {
        update_option('wpmcp_test_unregistered', [ 'max_tokens' => 4096, 'password_min_length' => 8, 'token_ttl' => 3600.5, 'secret_enabled' => true, 'api_key' => 'live' ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertSame(4096, $value['max_tokens']);
        $this->assertSame(8, $value['password_min_length']);
        $this->assertSame(3600.5, $value['token_ttl']);
        $this->assertTrue($value['secret_enabled']);
        $this->assertSame(self::MASK, $value['api_key']);
    }

    public function test_a_masked_value_in_a_reshaped_list_is_never_restored_into_another_entry(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wpmcp_test_unregistered', [ 'accounts' => [ [ 'id' => 'a', 'api_key' => 'KEY-A' ], [ 'id' => 'b', 'api_key' => 'KEY-B' ] ] ]);

        // The caller deletes entry "a" and sends back entry "b" with its mask, now at index 0.
        try {
            (new Update_Option())->handle([ 'name' => 'wpmcp_test_unregistered', 'value' => [ 'accounts' => [ [ 'id' => 'b', 'api_key' => self::MASK ] ] ] ]);
            $this->fail('an ambiguous restore must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('list', $e->getMessage());
        }
        $this->assertSame('KEY-A', get_option('wpmcp_test_unregistered')['accounts'][0]['api_key'], 'nothing was written');
    }

    public function test_a_masked_value_in_an_unchanged_list_is_still_restored(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wpmcp_test_unregistered', [ 'accounts' => [ [ 'id' => 'a', 'api_key' => 'KEY-A' ], [ 'id' => 'b', 'api_key' => 'KEY-B' ] ] ]);

        (new Update_Option())->handle([ 'name' => 'wpmcp_test_unregistered', 'value' => [ 'accounts' => [ [ 'id' => 'a', 'api_key' => self::MASK ], [ 'id' => 'b', 'api_key' => self::MASK ] ] ] ]);

        $this->assertSame([ 'KEY-A', 'KEY-B' ], array_column(get_option('wpmcp_test_unregistered')['accounts'], 'api_key'));
    }

    // ---- Re-review fixes (PR #917).

    public function test_a_transform_over_the_value_column_is_refused_on_another_site_or_on_sitemeta(): void
    {
        global $wpdb;
        $this->expectException(\RuntimeException::class);
        (new Query())->handle([ 'sql' => "SELECT REVERSE(option_value) FROM {$wpdb->base_prefix}7_options WHERE option_name = 'wpmcp_test_unregistered'" ]);
    }

    public function test_a_transform_over_sitemeta_value_is_refused(): void
    {
        global $wpdb;
        $this->expectException(\RuntimeException::class);
        (new Query())->handle([ 'sql' => "SELECT REVERSE(meta_value) FROM {$wpdb->base_prefix}sitemeta WHERE meta_key = 'wpmcp_test_unregistered'" ]);
    }

    public function test_a_transform_checks_the_stored_row_not_the_filtered_option(): void
    {
        global $wpdb;
        update_option('wpmcp_test_unregistered', [ 'client_secret' => 'FILTERED-LEAK' ]);
        add_filter('option_wpmcp_test_unregistered', static fn () => [ 'harmless' => 1 ]);

        try {
            $this->expectException(\RuntimeException::class);
            (new Query())->handle([ 'sql' => "SELECT REVERSE(option_value) FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_unregistered'" ]);
        } finally {
            remove_all_filters('option_wpmcp_test_unregistered');
        }
    }

    public function test_arrays_and_integers_under_a_secret_named_key_are_masked(): void
    {
        update_option('wpmcp_test_unregistered', [
            'api_keys' => [ 'sk-1', 'sk-2' ],
            'secrets'  => [ 'a' => 'v', 'deep' => [ 'b' => 'w' ], 'flag' => true ],
            'password' => 12345678,
        ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertStringNotContainsString('sk-1', wp_json_encode($value));
        $this->assertStringNotContainsString('"v"', wp_json_encode($value));
        $this->assertStringNotContainsString('"w"', wp_json_encode($value));
        $this->assertStringNotContainsString('12345678', wp_json_encode($value));
        $this->assertTrue($value['secrets']['flag'], 'a boolean leaf stays readable');
    }

    public function test_numeric_strings_under_non_secret_numeric_names_stay_readable(): void
    {
        update_option('wpmcp_test_unregistered', [ 'max_tokens' => '4096', 'token_ttl' => '3600', 'password_min_length' => '8', 'token_count' => '12', 'api_key' => '123456' ]);

        $value = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];

        $this->assertSame('4096', $value['max_tokens']);
        $this->assertSame('3600', $value['token_ttl']);
        $this->assertSame('8', $value['password_min_length']);
        $this->assertSame('12', $value['token_count']);
        $this->assertSame(self::MASK, $value['api_key'], 'a numeric credential is still a credential');
    }

    public function test_an_identity_less_list_reordered_at_the_same_length_is_refused(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wpmcp_test_unregistered', [ 'hosts' => [ [ 'api_key' => 'K1', 'url' => 'u1' ], [ 'api_key' => 'K2', 'url' => 'u2' ] ] ]);

        $this->expectException(\RuntimeException::class);
        (new Update_Option())->handle([ 'name' => 'wpmcp_test_unregistered', 'value' => [ 'hosts' => [ [ 'api_key' => self::MASK, 'url' => 'u2' ], [ 'api_key' => self::MASK, 'url' => 'u1' ] ] ] ]);
    }

    public function test_an_identity_less_list_written_back_as_read_is_restored(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wpmcp_test_unregistered', [ 'hosts' => [ [ 'api_key' => 'K1', 'url' => 'u1' ], [ 'api_key' => 'K2', 'url' => 'u2' ] ] ]);

        $read = (new Get_Option())->handle([ 'name' => 'wpmcp_test_unregistered' ])['value'];
        (new Update_Option())->handle([ 'name' => 'wpmcp_test_unregistered', 'value' => $read ]);

        $this->assertSame([ 'K1', 'K2' ], array_column(get_option('wpmcp_test_unregistered')['hosts'], 'api_key'));
    }

    /** Walks "a.b.c" through nested arrays; null when absent. */
    private function path_value(array $data, string $path)
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[ $segment ];
        }
        return $data;
    }
}
