<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Email_Marketing_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Meta\Secret_Options;

/**
 * Issue #900: MailerLite, Klaviyo, Kit, Omnisend and ActiveCampaign settings
 * as paid-tier ops on the plugin-data pair.
 *
 * None of the plugins is loaded in the shared test core. Presence is driven
 * through the wpmcp_<plugin>_active filters and the data is seeded in the
 * shapes the wordpress.org releases store: MailerLite 1.7.28 (its options and
 * the network-wide mailerlite_forms table), Klaviyo 3.9.0 ('klaviyo_settings'),
 * Kit 3.5.2 ('_wp_convertkit_settings', the convertkit_* resource caches and
 * the _wp_convertkit_post_meta post meta), Omnisend for WooCommerce 1.22.10
 * (its omnisend_* options and omnisend_logs table) and ActiveCampaign 8.1.21
 * ('settings_activecampaign').
 */
class EmailMarketingPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'mailerlite-get-settings'     => 'read',
        'klaviyo-get-settings'        => 'read',
        'klaviyo-set-checkout-signup' => 'write',
        'kit-get-settings'            => 'read',
        'kit-set-default-forms'       => 'write',
        'omnisend-get-settings'       => 'read',
        'activecampaign-get-settings' => 'read',
    ];

    private const ACTIVE = [ 'wpmcp_mailerlite_active', 'wpmcp_klaviyo_active', 'wpmcp_kit_active', 'wpmcp_omnisend_active', 'wpmcp_activecampaign_active' ];

    private const ML_KEY      = 'mlkey_0123456789abcdefghij_WXYZ';
    private const KIT_TOKEN   = 'kit_access_token_0123456789abcdef';
    private const KIT_SECRET  = 'kit_api_secret_fedcba9876543210';
    private const OMNI_KEY    = '64a1b2c3d4e5f6-omnisend-key-QRST';
    private const AC_KEY      = 'ac0123456789abcdef0123456789abcdef0123456789abcdef0123456789LMNO';
    private const KLAVIYO_KEY = 'AbC123';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::ACTIVE as $filter) {
            add_filter($filter, '__return_true');
        }
        add_filter(Email_Marketing_Pack::ENABLE_FILTER, '__return_true');

        global $wpdb;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}mailerlite_forms (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '2000-01-01 00:00:00' NOT NULL,
            name tinytext NOT NULL,
            type tinyint(1) default '1' NOT NULL,
            data text NOT NULL,
            PRIMARY KEY (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}omnisend_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date datetime,
            type varchar(10),
            endpoint varchar(15),
            url varchar(100),
            message longtext,
            PRIMARY KEY (id)
        )");

        // MailerLite.
        update_option('mailerlite_api_key', self::ML_KEY);
        update_option('mailerlite_platform', 2);
        update_option('account_id', '123456');
        update_option('account_subdomain', 'acme');
        update_option('mailerlite_double_optin_disabled', false);
        update_option('mailerlite_popups_disabled', true);
        $wpdb->insert($wpdb->base_prefix . 'mailerlite_forms', [
            'name' => 'Footer signup',
            'time' => '2026-01-02 03:04:05',
            'type' => 1,
            'data' => serialize([
                'title'           => 'Newsletter signup',
                'lists'           => [ '98765', '43210' ],
                'selected_groups' => [ (object) [ 'id' => '98765', 'name' => 'Customers' ], (object) [ 'id' => '43210', 'name' => 'Leads' ] ],
                'fields'          => [ 'email' => [ 'title' => 'Email', 'type' => 'email' ] ],
            ]),
        ]);
        $wpdb->insert($wpdb->base_prefix . 'mailerlite_forms', [
            'name' => 'Embedded popup',
            'time' => '2026-01-03 03:04:05',
            'type' => 2,
            'data' => serialize([ 'id' => 555, 'code' => 'abc123' ]),
        ]);

        // Klaviyo.
        update_option('klaviyo_settings', [
            'installed'                           => 'true',
            'klaviyo_public_api_key'              => self::KLAVIYO_KEY,
            'klaviyo_subscribe_checkbox'          => 'true',
            'klaviyo_newsletter_list_id'          => 'LiSt01',
            'klaviyo_newsletter_text'             => 'Sign me up',
            'klaviyo_sms_subscribe_checkbox'      => '',
            'klaviyo_sms_list_id'                 => '',
            'klaviyo_sms_consent_text'            => '',
            'klaviyo_sms_consent_disclosure_text' => 'By checking this box...',
        ]);

        // Kit.
        update_option('_wp_convertkit_settings', [
            'access_token'    => self::KIT_TOKEN,
            'refresh_token'   => 'kit_refresh_0123456789',
            'api_key'         => '',
            'api_secret'      => self::KIT_SECRET,
            'non_inline_form' => [ '3003' ],
            'post_form'       => 1001,
            'page_form'       => 0,
            'debug'           => 'on',
        ]);
        update_option('convertkit_account', [
            'user'    => [ 'email' => 'owner@example.com' ],
            'account' => [ 'id' => 42, 'name' => 'Acme Newsletter', 'plan_type' => 'creator', 'primary_email_address' => 'owner@example.com' ],
        ]);
        update_option('convertkit_forms', [
            1001 => [ 'id' => 1001, 'name' => 'Inline form', 'format' => 'inline', 'uid' => 'aa11', 'embed_js' => 'https://acme.kit.com/aa11/index.js' ],
            2002 => [ 'id' => 2002, 'name' => 'Sidebar form', 'format' => 'inline', 'uid' => 'bb22', 'embed_js' => 'https://acme.kit.com/bb22/index.js' ],
            3003 => [ 'id' => 3003, 'name' => 'Exit modal', 'format' => 'modal', 'uid' => 'cc33', 'embed_js' => 'https://acme.kit.com/cc33/index.js' ],
        ]);
        update_option('convertkit_tags', [ 7 => [ 'id' => 7, 'name' => 'Buyers' ], 8 => [ 'id' => 8, 'name' => 'Leads' ] ]);

        // Omnisend.
        update_option('omnisend_account_id', '5f0a1b2c3d4e5f6a7b8c9d0e');
        update_option('omnisend_api_key', self::OMNI_KEY);
        update_option('omnisend_connected_domain', 'shop.example.com');
        update_option('omnisend_plugin_version', '1.22.10');
        $wpdb->insert($wpdb->prefix . 'omnisend_logs', [ 'date' => '2026-11-01 10:00:00', 'type' => 'info', 'message' => 'Connected' ]);
        $wpdb->insert($wpdb->prefix . 'omnisend_logs', [ 'date' => '2026-11-02 11:00:00', 'type' => 'error', 'message' => 'Contact jane.doe@example.com rejected: 401 Unauthorized' ]);
        $wpdb->insert($wpdb->prefix . 'omnisend_logs', [ 'date' => '2026-11-03 12:00:00', 'type' => 'warn', 'message' => 'Slow response' ]);

        // ActiveCampaign.
        update_option('settings_activecampaign', [
            'api_url'       => 'https://acme.api-us1.com',
            'api_key'       => self::AC_KEY,
            'account'       => 'acme.activehosted.com',
            'form_id'       => [ 5 ],
            'forms'         => [ 5 => [ 'id' => 5, 'name' => 'Main form' ], 6 => [ 'id' => 6, 'name' => 'Unused form' ] ],
            'site_tracking' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach (array_merge(self::ACTIVE, [ Email_Marketing_Pack::ENABLE_FILTER, 'update_option_klaviyo_settings' ]) as $hook) {
            remove_all_filters($hook);
        }
        foreach ([ 'mailerlite_api_key', 'mailerlite_platform', 'account_id', 'account_subdomain', 'mailerlite_double_optin_disabled', 'mailerlite_popups_disabled', 'klaviyo_settings', '_wp_convertkit_settings', 'convertkit_account', 'convertkit_forms', 'convertkit_tags', 'omnisend_account_id', 'omnisend_api_key', 'omnisend_connected_domain', 'omnisend_plugin_version', 'settings_activecampaign' ] as $option) {
            delete_option($option);
        }
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}mailerlite_forms");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}omnisend_logs");
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        $cases = [
            'wpmcp_mailerlite_active'     => [ 'mailerlite-get-settings', 'MailerLite' ],
            'wpmcp_klaviyo_active'        => [ 'klaviyo-get-settings', 'Klaviyo' ],
            'wpmcp_kit_active'            => [ 'kit-get-settings', 'Kit' ],
            'wpmcp_omnisend_active'       => [ 'omnisend-get-settings', 'Omnisend' ],
            'wpmcp_activecampaign_active' => [ 'activecampaign-get-settings', 'ActiveCampaign' ],
        ];
        foreach ($cases as $filter => [ $op, $plugin ]) {
            remove_all_filters($filter);
            add_filter($filter, '__return_false');
            $out = $this->read($op);
            $this->assertSame('plugin_missing', $out['error']['code'] ?? null, $op . ': ' . wp_json_encode($out));
            $this->assertStringContainsString('Install and activate ' . $plugin, $out['error']['message']);
        }

        $out = $this->write('klaviyo-set-checkout-signup', [ 'newsletter_list_id' => 'NeW001' ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertSame('LiSt01', get_option('klaviyo_settings')['klaviyo_newsletter_list_id']);
    }

    public function test_mailerlite_read_reports_connection_forms_groups_and_placements_and_never_the_key(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => 'Hi [mailerlite_form form_id=1] there' ]);
        $post = self::factory()->post->create([ 'post_status' => 'publish', 'post_title' => 'Blog', 'post_content' => '<!-- wp:mailerlite/form-block {"form_id":2,"editLink":"x"} /-->' ]);
        self::factory()->post->create([ 'post_status' => 'trash', 'post_content' => '[mailerlite_form form_id=1]' ]);

        $out = $this->ok($this->read('mailerlite-get-settings'))['result'];

        $this->assertTrue($out['connected']);
        $this->assertSame('****WXYZ', $out['api_key']);
        $this->assertSame('MailerLite', $out['platform']);
        $this->assertSame('123456', $out['account']['id']);
        $this->assertSame('acme', $out['account']['subdomain']);
        $this->assertTrue($out['double_opt_in']['enabled']);
        $this->assertFalse($out['popups_enabled']);
        $this->assertCount(2, $out['forms']);

        $forms = array_column($out['forms'], null, 'id');
        $this->assertSame('Footer signup', $forms[1]['name']);
        $this->assertSame('custom', $forms[1]['type']);
        $this->assertSame([ [ 'id' => '98765', 'name' => 'Customers' ], [ 'id' => '43210', 'name' => 'Leads' ] ], $forms[1]['groups']);
        $this->assertSame('[mailerlite_form form_id=1]', $forms[1]['shortcode']);
        $this->assertSame([ $page ], array_column($forms[1]['placements'], 'post_id'));
        $this->assertSame('embedded', $forms[2]['type']);
        $this->assertSame('555', $forms[2]['webform_id']);
        $this->assertSame([ $post ], array_column($forms[2]['placements'], 'post_id'));
        $this->assertSame('block', $forms[2]['placements'][0]['via']);

        $this->assertStringNotContainsString(self::ML_KEY, (string) wp_json_encode($out));
    }

    public function test_mailerlite_read_without_the_forms_table_still_answers(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}mailerlite_forms");
        delete_option('mailerlite_api_key');
        $out = $this->ok($this->read('mailerlite-get-settings'))['result'];
        $this->assertFalse($out['connected']);
        $this->assertNull($out['api_key']);
        $this->assertSame([], $out['forms']);
    }

    public function test_klaviyo_read_reports_lists_and_checkout_signup_with_the_key_masked(): void
    {
        $out = $this->ok($this->read('klaviyo-get-settings'))['result'];
        $this->assertTrue($out['connected']);
        $this->assertSame('****C123', $out['public_api_key']);
        $this->assertSame([ 'enabled' => true, 'list_id' => 'LiSt01', 'text' => 'Sign me up' ], $out['email_signup']);
        $this->assertFalse($out['sms_signup']['enabled']);
        $this->assertNull($out['sms_signup']['list_id']);
        $this->assertTrue($out['writes_enabled']);
        $this->assertArrayHasKey('woocommerce', $out);
        $this->assertStringContainsString('Klaviyo', $out['double_opt_in']['note']);
        $this->assertStringNotContainsString('"' . self::KLAVIYO_KEY . '"', (string) wp_json_encode($out));
    }

    public function test_klaviyo_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Email_Marketing_Pack::ENABLE_FILTER);
        $out = $this->write('klaviyo-set-checkout-signup', [ 'newsletter_list_id' => 'NeW001' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Email_Marketing_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('LiSt01', get_option('klaviyo_settings')['klaviyo_newsletter_list_id']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_klaviyo_set_checkout_signup_writes_the_option_and_rolls_back_exactly(): void
    {
        $before = get_option('klaviyo_settings');
        $out    = $this->ok($this->write('klaviyo-set-checkout-signup', [
            'newsletter_list_id' => 'NeW001',
            'sms_enabled'        => true,
            'sms_list_id'        => 'SmS001',
            'newsletter_text'    => 'Send me deals',
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('NeW001', $out['result']['email_signup']['list_id']);
        $this->assertSame('Send me deals', $out['result']['email_signup']['text']);
        $this->assertTrue($out['result']['sms_signup']['enabled']);

        $stored = get_option('klaviyo_settings');
        $this->assertSame('NeW001', $stored['klaviyo_newsletter_list_id']);
        $this->assertSame('true', $stored['klaviyo_sms_subscribe_checkbox']);
        $this->assertSame('SmS001', $stored['klaviyo_sms_list_id']);
        $this->assertSame(self::KLAVIYO_KEY, $stored['klaviyo_public_api_key'], 'the key is untouched');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('klaviyo_settings'));
    }

    public function test_klaviyo_set_checkout_signup_refuses_bad_input(): void
    {
        $cases = [
            'invalid_args'     => [],
            'invalid_list_id'  => [ 'newsletter_list_id' => 'not a list!' ],
            'list_id_required' => [ 'sms_enabled' => true ],
            'same_list_ids'    => [ 'sms_list_id' => 'LiSt01' ],
            'no_change'        => [ 'newsletter_list_id' => 'LiSt01' ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('klaviyo-set-checkout-signup', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame('LiSt01', get_option('klaviyo_settings')['klaviyo_newsletter_list_id']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_kit_read_reports_account_forms_defaults_and_placements_without_tokens(): void
    {
        $post = self::factory()->post->create([ 'post_status' => 'publish', 'post_title' => 'Override' ]);
        update_post_meta($post, '_wp_convertkit_post_meta', [ 'form' => '2002', 'landing_page' => '', 'tag' => '7' ]);
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[convertkit_form form="1001"]' ]);
        $draft = self::factory()->post->create([ 'post_status' => 'draft', 'post_author' => self::factory()->user->create([ 'role' => 'author' ]), 'post_content' => '[convertkit form=1001]' ]);

        $out = $this->ok($this->read('kit-get-settings'))['result'];

        $this->assertTrue($out['connected']);
        $this->assertSame('oauth', $out['auth']);
        $this->assertSame('Acme Newsletter', $out['account']['name']);
        $this->assertSame('creator', $out['account']['plan']);
        $forms = array_column($out['forms'], null, 'id');
        $this->assertSame([ 1001, 2002, 3003 ], array_keys($forms));
        $this->assertSame('modal', $forms[3003]['format']);
        $this->assertArrayNotHasKey('embed_js', $forms[1001]);
        $this->assertSame(2, $out['tag_count']);
        $this->assertSame(1001, $out['default_forms']['post']);
        $this->assertSame(0, $out['default_forms']['page']);
        $this->assertSame([ 3003 ], $out['site_wide_forms']);

        $by_post = [];
        foreach ($out['placements'] as $row) {
            $by_post[ $row['post_id'] ][] = $row;
        }
        $this->assertSame(2002, $by_post[ $post ][0]['form_id']);
        $this->assertSame('post_setting', $by_post[ $post ][0]['via']);
        $this->assertSame(7, $by_post[ $post ][0]['tag_id']);
        $this->assertSame(1001, $by_post[ $page ][0]['form_id']);
        $this->assertSame('shortcode', $by_post[ $page ][0]['via']);
        $this->assertArrayHasKey($draft, $by_post, 'an administrator may read the draft');

        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(self::KIT_TOKEN, $json);
        $this->assertStringNotContainsString(self::KIT_SECRET, $json);
        $this->assertStringNotContainsString('kit_refresh_', $json);
    }

    public function test_kit_set_default_forms_writes_the_option_and_rolls_back_exactly(): void
    {
        $before = get_option('_wp_convertkit_settings');
        $out    = $this->ok($this->write('kit-set-default-forms', [ 'default_forms' => [ 'page' => 2002, 'post' => 0 ], 'site_wide_forms' => [] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame(2002, $out['result']['default_forms']['page']);
        $this->assertSame(0, $out['result']['default_forms']['post']);
        $this->assertSame([], $out['result']['site_wide_forms']);

        $stored = get_option('_wp_convertkit_settings');
        $this->assertSame(2002, $stored['page_form']);
        $this->assertSame(0, $stored['post_form']);
        $this->assertSame([], $stored['non_inline_form']);
        $this->assertSame(self::KIT_TOKEN, $stored['access_token'], 'credentials are untouched');
        $this->assertSame('on', $stored['debug']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('_wp_convertkit_settings'));
    }

    public function test_kit_set_default_forms_refuses_bad_input(): void
    {
        $cases = [
            'invalid_args'          => [],
            'unknown_post_type'     => [ 'default_forms' => [ 'nope_type' => 1001 ] ],
            'unknown_form'          => [ 'default_forms' => [ 'page' => 9999 ] ],
            'not_a_site_wide_form'  => [ 'site_wide_forms' => [ 1001 ] ],
            'no_change'             => [ 'default_forms' => [ 'post' => 1001 ] ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('kit-set-default-forms', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame(1001, get_option('_wp_convertkit_settings')['post_form']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_omnisend_read_reports_connection_and_the_last_error_masked(): void
    {
        $out = $this->ok($this->read('omnisend-get-settings'))['result'];
        $this->assertTrue($out['connected']);
        $this->assertSame('****QRST', $out['api_key']);
        $this->assertSame('5f0a1b2c3d4e5f6a7b8c9d0e', $out['account_id']);
        $this->assertSame('shop.example.com', $out['connected_domain']);
        $this->assertSame('2026-11-02 11:00:00', $out['last_error']['date']);
        $this->assertStringContainsString('j***@example.com', $out['last_error']['message']);
        $this->assertStringNotContainsString('jane.doe', (string) wp_json_encode($out));
        $this->assertStringNotContainsString(self::OMNI_KEY, (string) wp_json_encode($out));
        $this->assertStringContainsString('Omnisend', $out['lists_note']);
    }

    public function test_activecampaign_read_reports_account_and_forms_without_the_key(): void
    {
        $out = $this->ok($this->read('activecampaign-get-settings'))['result'];
        $this->assertTrue($out['connected']);
        $this->assertSame('****LMNO', $out['api_key']);
        $this->assertSame('acme.activehosted.com', $out['account']);
        $this->assertSame([ [ 'id' => 5, 'name' => 'Main form', 'enabled' => true, 'shortcode' => '[activecampaign form=5]' ], [ 'id' => 6, 'name' => 'Unused form', 'enabled' => false, 'shortcode' => '[activecampaign form=6]' ] ], $out['forms']);
        $this->assertTrue($out['site_tracking']);
        $this->assertStringNotContainsString(self::AC_KEY, (string) wp_json_encode($out));
    }

    public function test_the_credential_options_are_registered_as_secrets(): void
    {
        $this->assertSame(Secret_Options::MASK, Secret_Options::mask_option('mailerlite_api_key', self::ML_KEY));
        $this->assertSame(Secret_Options::MASK, Secret_Options::mask_option('omnisend_api_key', self::OMNI_KEY));
        $kit = Secret_Options::mask_option('_wp_convertkit_settings', get_option('_wp_convertkit_settings'));
        $this->assertSame(Secret_Options::MASK, $kit['access_token']);
        $this->assertSame(Secret_Options::MASK, $kit['api_secret']);
        $ac = Secret_Options::mask_option('settings_activecampaign', get_option('settings_activecampaign'));
        $this->assertSame(Secret_Options::MASK, $ac['api_key']);
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('kit-get-settings'));
        $this->assertArrayHasKey('error', $this->write('kit-set-default-forms', [ 'default_forms' => [ 'page' => 2002 ] ]));
        $this->assertSame(0, get_option('_wp_convertkit_settings')['page_form']);
    }
}
