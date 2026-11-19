<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Cookie consent banners on the theme pair (issue #686): Complianz,
 * CookieYes and Cookie Notice. None of the three is loaded in the shared
 * test core: presence is each plugin's wpmcp_<slug>_active filter, and the
 * data is seeded as the plugins store it (Complianz 7.5 and CookieYes 3.5
 * tables with their own column layout, the Cookie Notice 3.1 option).
 */
class ConsentPackTest extends \WP_UnitTestCase
{
    private const SLUGS = [ 'complianz', 'cookieyes', 'cookie_notice' ];

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::real_ddl();
        self::drop_tables();
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL from literals.
        // The Complianz 7.5 columns these ops touch, plus a few neighbours.
        $wpdb->query("CREATE TABLE {$p}cmplz_cookiebanners (ID int(11) NOT NULL AUTO_INCREMENT, title text NOT NULL, banner_version int(11) NOT NULL, `default` int(11) NOT NULL, position text NOT NULL, `revoke` text NOT NULL, header text NOT NULL, dismiss text NOT NULL, save_preferences text NOT NULL, view_preferences text NOT NULL, accept text NOT NULL, message_optin text NOT NULL, message_optout text NOT NULL, disable_cookiebanner int(11) NOT NULL, PRIMARY KEY (ID)) {$c}");
        $wpdb->query("CREATE TABLE {$p}cmplz_cookies (ID int(11) NOT NULL AUTO_INCREMENT, name varchar(250) NOT NULL, slug varchar(250) NOT NULL, sync int(11) NOT NULL, ignored int(11) NOT NULL, retention text NOT NULL, type text NOT NULL, serviceID int(11) NOT NULL, cookieFunction text NOT NULL, collectedPersonalData text NOT NULL, purpose text NOT NULL, language varchar(6) NOT NULL, isTranslationFrom int(11) NOT NULL, isOwnDomainCookie int(11) NOT NULL, domain text NOT NULL, deleted int(11) NOT NULL, isMembersOnly int(11) NOT NULL, showOnPolicy int(11) NOT NULL, lastUpdatedDate int(11) NOT NULL, lastAddDate int(11) NOT NULL, firstAddDate int(11) NOT NULL, PRIMARY KEY (ID)) {$c}");
        $wpdb->query("CREATE TABLE {$p}cky_banners (banner_id bigint(20) NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL DEFAULT '', slug varchar(190) NOT NULL DEFAULT '', status int(11) NOT NULL DEFAULT 0, settings longtext NOT NULL, banner_default int(11) NOT NULL DEFAULT 0, contents longtext NOT NULL, date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00', date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (banner_id)) {$c}");
        $wpdb->query("CREATE TABLE {$p}cky_cookies (cookie_id bigint(20) NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL DEFAULT '', slug varchar(190) NOT NULL DEFAULT '', description longtext NOT NULL, duration text NOT NULL, domain varchar(190) NOT NULL DEFAULT '', category bigint(20) NOT NULL, type text NOT NULL, discovered int(11) NOT NULL default 0, url_pattern varchar(190) NULL default '', meta longtext, date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00', date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (cookie_id)) {$c}");
        $wpdb->query("CREATE TABLE {$p}cky_cookie_categories (category_id bigint(20) NOT NULL AUTO_INCREMENT, name text NOT NULL, slug varchar(190) NOT NULL DEFAULT '', description longtext NOT NULL, prior_consent int(11) NOT NULL default 0, visibility int(11) NOT NULL default 1, priority int(11) NOT NULL default 0, sell_personal_data int(11) NOT NULL default 0, meta longtext NULL, date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00', date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (category_id)) {$c}");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::real_ddl();
        self::drop_tables();
    }

    /**
     * The plugin tables must be real ones: Database_Guard finds tables with
     * SHOW TABLES, which does not list temporary tables. Drop any test
     * case's temporary table rewrite still on the query filter (an earlier
     * class in the same shard can leave one behind); setUp adds its own.
     */
    private static function real_ddl(): void
    {
        global $wp_filter;
        if (! isset($wp_filter['query'])) {
            return;
        }
        foreach ($wp_filter['query']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_array($fn) && in_array($fn[1] ?? '', [ '_create_temporary_tables', '_drop_temporary_tables' ], true)) {
                    remove_filter('query', $fn, $priority);
                }
            }
        }
    }

    private static function drop_tables(): void
    {
        global $wpdb;
        foreach ([ 'cmplz_cookiebanners', 'cmplz_cookies', 'cky_banners', 'cky_cookies', 'cky_cookie_categories' ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_consent_write', '__return_true');
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach (array_merge([ 'wpmcp_enable_consent_write', 'cmplz_scan_reset', 'cky_after_update_banner' ], array_map(static fn (string $s): string => "wpmcp_{$s}_active", self::SLUGS)) as $hook) {
            remove_all_filters($hook);
        }
        parent::tearDown();
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert("{$p}cmplz_cookiebanners", [
            'title'                => 'Banner A',
            'banner_version'       => 4,
            'default'              => 1,
            'position'             => 'bottom-right',
            'revoke'               => 'Manage consent',
            'header'               => serialize([ 'text' => 'Manage Cookie Consent', 'show' => 1 ]),
            'dismiss'              => serialize([ 'text' => 'Deny', 'show' => 0 ]),
            'save_preferences'     => 'Save preferences',
            'view_preferences'     => 'View preferences',
            'accept'               => 'Accept',
            'message_optin'        => 'To provide the best experiences, we use technologies like cookies.',
            'message_optout'       => 'We use cookies.',
            'disable_cookiebanner' => 0,
        ]);
        $wpdb->insert("{$p}cmplz_cookies", [ 'name' => '_ga', 'slug' => 'ga', 'sync' => 1, 'ignored' => 0, 'retention' => '2 years', 'type' => 'HTTP', 'serviceID' => 3, 'cookieFunction' => 'store and count pageviews', 'collectedPersonalData' => '', 'purpose' => 'Statistics', 'language' => 'en', 'isTranslationFrom' => 0, 'isOwnDomainCookie' => 1, 'domain' => '', 'deleted' => 0, 'isMembersOnly' => 0, 'showOnPolicy' => 1, 'lastUpdatedDate' => 0, 'lastAddDate' => 1763000000, 'firstAddDate' => 1762000000 ]);
        $wpdb->insert("{$p}cmplz_cookies", [ 'name' => 'old_cookie', 'slug' => 'old', 'sync' => 1, 'ignored' => 0, 'retention' => '', 'type' => 'HTTP', 'serviceID' => 0, 'cookieFunction' => '', 'collectedPersonalData' => '', 'purpose' => '', 'language' => 'en', 'isTranslationFrom' => 0, 'isOwnDomainCookie' => 1, 'domain' => '', 'deleted' => 1, 'isMembersOnly' => 0, 'showOnPolicy' => 0, 'lastUpdatedDate' => 0, 'lastAddDate' => 0, 'firstAddDate' => 0 ]);
        update_option('cmplz_options', [ 'regions' => [ 'eu' => 1 ], 'enable_cookie_banner' => 'yes', 'organisation_name' => 'Acme Ltd', 'email_company' => 'legal@example.org' ]);

        $contents = [ 'en' => [
            'notice'           => [ 'elements' => [ 'title' => 'We value your privacy', 'description' => '<p>We use cookies.</p>', 'buttons' => [ 'elements' => [ 'accept' => 'Accept All', 'reject' => 'Reject All', 'settings' => 'Customise', 'readMore' => 'Cookie Policy' ] ] ] ],
            'preferenceCenter' => [ 'elements' => [ 'title' => 'Customise Consent Preferences', 'description' => '<p>Choose.</p>' ] ],
        ] ];
        $wpdb->insert("{$p}cky_banners", [ 'name' => 'GDPR', 'slug' => 'gdpr', 'status' => 1, 'settings' => (string) wp_json_encode([ 'settings' => [ 'type' => 'box', 'applicableLaw' => 'gdpr' ] ]), 'banner_default' => 1, 'contents' => (string) wp_json_encode($contents) ]);
        $wpdb->insert("{$p}cky_cookie_categories", [ 'name' => '{"en":"Analytics"}', 'slug' => 'analytics', 'description' => '{}' ]);
        $wpdb->insert("{$p}cky_cookies", [ 'name' => '_gid', 'slug' => 'gid', 'description' => '{"en":"Google Analytics session"}', 'duration' => '{"en":"1 day"}', 'domain' => 'example.org', 'category' => (int) $wpdb->insert_id, 'type' => '0', 'discovered' => 1 ]);
        update_option('cky_settings', [ 'api' => [ 'token' => 'tok-secret-123456' ], 'account' => [ 'email' => 'owner@example.org', 'connected' => false, 'plan' => 'free', 'website_key' => '' ], 'consent_logs' => [ 'status' => true ], 'languages' => [ 'selected' => [ 'en' ], 'default' => 'en' ] ]);

        update_option('cookie_notice_options', [ 'position' => 'bottom', 'message_text' => 'We use cookies to ensure that we give you the best experience.', 'accept_text' => 'Ok', 'refuse_text' => 'No', 'revoke_text' => 'Update consent', 'revoke_message_text' => '', 'see_more_opt' => [ 'text' => 'Privacy policy', 'link_type' => 'page', 'id' => 0, 'link' => '' ], 'app_id' => 'app-1234567', 'app_key' => 'key-abcdef12' ]);
        update_option('cookie_notice_status', [ 'status' => '' ]);
    }

    private function only(string $slug): void
    {
        foreach (self::SLUGS as $s) {
            remove_all_filters("wpmcp_{$s}_active");
            add_filter("wpmcp_{$s}_active", $s === $slug ? '__return_true' : '__return_false');
        }
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && is_array($out['result']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args = [], bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function cmplz_row(): array
    {
        global $wpdb;
        return (array) $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cmplz_cookiebanners ORDER BY ID LIMIT 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
    }

    private function cky_row(): array
    {
        global $wpdb;
        return (array) $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cky_banners ORDER BY banner_id LIMIT 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
    }

    public function test_without_a_consent_plugin_the_ops_answer_plugin_missing(): void
    {
        $this->only('none');
        $out = $this->read('get-consent-banner-status');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Complianz, CookieYes or Cookie Notice', $out['error']['message']);
        $this->assertStringContainsString('plugins.php', $out['error']['message']);
    }

    public function test_status_reports_each_plugin_and_its_banners(): void
    {
        $this->only('complianz');
        add_filter('wpmcp_cookieyes_active', '__return_true', 20);
        $out = $this->ok($this->read('get-consent-banner-status'));
        $this->assertTrue($out['plugins']['complianz']['active']);
        $this->assertTrue($out['plugins']['complianz']['banner_enabled']);
        $this->assertSame(1, $out['plugins']['complianz']['cookie_count'], 'deleted cookies are not counted');
        $this->assertSame('Banner A', $out['plugins']['complianz']['banners'][0]['title']);
        $this->assertSame('plugin', $out['plugins']['cookieyes']['managed_by']);
        $this->assertSame('gdpr', $out['plugins']['cookieyes']['banners'][0]['law']);
        $this->assertFalse($out['plugins']['cookie-notice']['active']);
        $this->assertTrue($out['writes_enabled']);
    }

    public function test_settings_mask_credentials_and_keep_company_details_out(): void
    {
        $this->only('cookieyes');
        $out  = $this->ok($this->read('get-consent-settings'));
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('tok-secret-123456', $json);
        $this->assertStringNotContainsString('owner@example.org', $json);
        $this->assertSame('o***@example.org', $out['settings']['account']['email']);
        $this->assertSame('Accept All', $out['texts']['accept']);
        $this->assertContains('description', $out['writable_texts']);

        $this->only('complianz');
        $out  = $this->ok($this->read('get-consent-settings'));
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('Acme Ltd', $json);
        $this->assertSame('Manage Cookie Consent', $out['texts']['header']);

        $this->only('cookie_notice');
        $out  = $this->ok($this->read('get-consent-settings'));
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('app-1234567', $json);
        $this->assertStringNotContainsString('key-abcdef12', $json);
        $this->assertSame('Privacy policy', $out['texts']['privacy_link_text']);
    }

    public function test_several_active_plugins_need_a_plugin_argument(): void
    {
        $this->only('complianz');
        add_filter('wpmcp_cookie_notice_active', '__return_true', 20);
        $this->assertSame('invalid_args', $this->read('get-consent-settings')['error']['code'] ?? null);
        $this->ok($this->read('get-consent-settings', [ 'plugin' => 'cookie-notice' ]));
        $this->assertSame('plugin_missing', $this->read('get-consent-settings', [ 'plugin' => 'cookieyes' ])['error']['code'] ?? null);
    }

    public function test_list_cookies_reads_complianz_and_cookieyes_and_refuses_cookie_notice(): void
    {
        $this->only('complianz');
        $out = $this->ok($this->read('list-consent-cookies'));
        $this->assertSame(1, $out['total']);
        $this->assertSame('_ga', $out['cookies'][0]['name']);
        $this->assertSame('Statistics', $out['cookies'][0]['purpose']);

        $this->only('cookieyes');
        $out = $this->ok($this->read('list-consent-cookies'));
        $this->assertSame('_gid', $out['cookies'][0]['name']);
        $this->assertSame('analytics', $out['cookies'][0]['category']);
        $this->assertSame('1 day', $out['cookies'][0]['duration']);

        $this->only('cookie_notice');
        $this->assertSame('not_supported', $this->read('list-consent-cookies')['error']['code'] ?? null);
    }

    public function test_texts_write_is_off_until_the_site_opts_in(): void
    {
        $this->only('cookie_notice');
        remove_all_filters('wpmcp_enable_consent_write');
        $out = $this->write('update-consent-banner-texts', [ 'texts' => [ 'accept_text' => 'Accept' ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame('Ok', get_option('cookie_notice_options')['accept_text']);
    }

    public function test_cookie_notice_texts_write_shows_before_and_after_and_rolls_back(): void
    {
        $this->only('cookie_notice');
        $before = get_option('cookie_notice_options');
        $out    = $this->ok($this->write('update-consent-banner-texts', [ 'texts' => [ 'accept_text' => 'Accept all', 'privacy_link_text' => 'Read our policy', 'message_text' => 'We use <strong>cookies</strong><script>x</script>' ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'before' => 'Ok', 'after' => 'Accept all' ], $out['result']['changed']['accept_text']);
        $this->assertSame('Read our policy', get_option('cookie_notice_options')['see_more_opt']['text']);
        $this->assertStringNotContainsString('<script>', get_option('cookie_notice_options')['message_text']);
        $this->assertSame('key-abcdef12', get_option('cookie_notice_options')['app_key'], 'other settings untouched');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('cookie_notice_options'));
    }

    public function test_complianz_texts_write_keeps_show_flags_and_rolls_back_the_row(): void
    {
        $this->only('complianz');
        $before = $this->cmplz_row();
        $out    = $this->ok($this->write('update-consent-banner-texts', [ 'texts' => [ 'accept' => 'Allow all', 'dismiss' => 'Refuse' ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'before' => 'Deny', 'after' => 'Refuse' ], $out['result']['changed']['dismiss']);
        $row = $this->cmplz_row();
        $this->assertSame('Allow all', $row['accept']);
        $this->assertSame([ 'text' => 'Refuse', 'show' => 0 ], unserialize($row['dismiss']));
        $this->assertSame('5', (string) $row['banner_version']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->cmplz_row());
    }

    public function test_cookieyes_texts_write_changes_one_language_and_rolls_back(): void
    {
        $this->only('cookieyes');
        $fired = 0;
        add_action('cky_after_update_banner', static function () use (&$fired): void {
            ++$fired;
        });
        $before = $this->cky_row();
        $out    = $this->ok($this->write('update-consent-banner-texts', [ 'texts' => [ 'title' => 'Your privacy', 'reject' => 'Decline' ] ]));
        $this->assertSame([ 'before' => 'Reject All', 'after' => 'Decline' ], $out['result']['changed']['reject']);
        $contents = json_decode($this->cky_row()['contents'], true);
        $this->assertSame('Your privacy', $contents['en']['notice']['elements']['title']);
        $this->assertSame('Accept All', $contents['en']['notice']['elements']['buttons']['elements']['accept']);
        $this->assertSame(1, $fired, 'CookieYes drops its caches after the write');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->cky_row());
        $this->assertSame(2, $fired, 'and again after the undo');
    }

    public function test_texts_write_refusals(): void
    {
        $this->only('cookieyes');
        $this->assertSame('invalid_args', $this->write('update-consent-banner-texts', [ 'texts' => [ 'accept_text' => 'x' ] ])['error']['code'] ?? null, 'a Cookie Notice key on CookieYes');
        $this->assertSame('no_change', $this->write('update-consent-banner-texts', [ 'texts' => [ 'accept' => 'Accept All' ] ])['error']['code'] ?? null);
        $this->assertSame('not_found', $this->write('update-consent-banner-texts', [ 'banner_id' => 999, 'texts' => [ 'accept' => 'Yes' ] ])['error']['code'] ?? null);
        $this->assertSame('not_stored', $this->write('update-consent-banner-texts', [ 'language' => 'de', 'texts' => [ 'accept' => 'Ja' ] ])['error']['code'] ?? null);

        $settings                         = get_option('cky_settings');
        $settings['account']['connected'] = true;
        update_option('cky_settings', $settings);
        $this->assertSame('managed_in_cloud', $this->write('update-consent-banner-texts', [ 'texts' => [ 'accept' => 'Yes' ] ])['error']['code'] ?? null);
    }

    public function test_scan_restarts_complianz_with_confirm_and_refuses_elsewhere(): void
    {
        $this->only('complianz');
        set_transient('cmplz_pages_list', [ 1, 2 ]);
        set_transient('cmplz_processed_pages_list', [ 1 ]);
        $post = self::factory()->post->create();
        update_post_meta($post, '_cmplz_scanned_post', true);
        $fired = 0;
        add_action('cmplz_scan_reset', static function () use (&$fired): void {
            ++$fired;
        });

        $out = $this->write('start-consent-cookie-scan');
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(0, $fired);

        $out = $this->ok($this->write('start-consent-cookie-scan', [], true));
        $this->assertFalse($out['recoverable']);
        $this->assertTrue($out['result']['restarted']);
        $this->assertSame(1, $fired);
        $this->assertFalse(get_transient('cmplz_processed_pages_list'));
        $this->assertSame('', get_post_meta($post, '_cmplz_scanned_post', true));

        $this->only('cookieyes');
        $this->assertSame('not_supported', $this->write('start-consent-cookie-scan', [], true)['error']['code'] ?? null);
    }
}
