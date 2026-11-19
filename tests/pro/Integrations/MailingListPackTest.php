<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Crm_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Newsletter, Groundhogg, MC4WP and Brevo on the plugin-data pair (issue
 * #683), paid-tier, so they add no top-level tools.
 *
 * None of the four plugins is loaded in the shared test core. Their tables
 * are created here from the plugins' own definitions (Newsletter 9.5.7
 * includes/upgrade.php, Groundhogg 4.11 db/*.php, Brevo 3.3.6
 * model/model-forms.php), their settings are the options and transients the
 * plugins read, and presence is driven through each plugin's
 * wpmcp_<plugin>_active filter.
 */
class MailingListPackTest extends \WP_UnitTestCase
{
    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p    = $wpdb->prefix;
        $cols = '';
        for ($i = 1; $i <= 40; $i++) {
            $cols .= "list_{$i} tinyint(4) NOT NULL DEFAULT '0',\n";
        }
        for ($i = 1; $i <= 20; $i++) {
            $cols .= "profile_{$i} varchar(255) NOT NULL DEFAULT '',\n";
        }
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}newsletter (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(100) NOT NULL DEFAULT '',
            name varchar(100) NOT NULL DEFAULT '',
            token varchar(50) NOT NULL DEFAULT '',
            language varchar(10) NOT NULL DEFAULT '',
            status varchar(1) NOT NULL DEFAULT 'S',
            created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated int(11) NOT NULL DEFAULT '0',
            last_activity int(11) NOT NULL DEFAULT '0',
            surname varchar(100) NOT NULL DEFAULT '',
            sex char(1) NOT NULL DEFAULT 'n',
            ip varchar(50) NOT NULL DEFAULT '',
            wp_user_id int(11) NOT NULL DEFAULT '0',
            source varchar(50) NOT NULL DEFAULT '',
            unsub_time int(11) NOT NULL DEFAULT '0',
            {$cols}
            test tinyint(4) NOT NULL DEFAULT '0',
            PRIMARY KEY (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}newsletter_customforms (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            type tinyint(1) NOT NULL DEFAULT 0,
            lists varchar(255) NOT NULL DEFAULT '',
            content longtext,
            token varchar(50) NOT NULL DEFAULT '',
            optin varchar(50) NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}gh_contacts (
            ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(50) NOT NULL,
            first_name mediumtext NOT NULL,
            last_name mediumtext NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            optin_status int unsigned NOT NULL,
            is_free tinyint(1) unsigned NOT NULL,
            date_created datetime DEFAULT '2000-01-01 00:00:00' NOT NULL,
            date_optin_status_changed datetime DEFAULT '2000-01-01 00:00:00' NOT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY email (email)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}gh_tags (
            tag_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tag_slug varchar(190) NOT NULL,
            tag_name mediumtext NOT NULL,
            tag_description text NOT NULL,
            show_as_preference tinyint unsigned NOT NULL,
            PRIMARY KEY (tag_id),
            UNIQUE KEY tag_slug (tag_slug)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}gh_tag_relationships (
            tag_id bigint(20) unsigned NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            date_created datetime NOT NULL,
            PRIMARY KEY (tag_id,contact_id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}gh_funnels (
            ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title text NOT NULL,
            status varchar(20) NOT NULL,
            author bigint(20) unsigned NOT NULL,
            last_updated datetime DEFAULT '2000-01-01 00:00:00' NOT NULL,
            date_created datetime DEFAULT '2000-01-01 00:00:00' NOT NULL,
            PRIMARY KEY (ID)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}gh_steps (
            ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            funnel_id bigint(20) unsigned NOT NULL,
            step_title mediumtext NOT NULL,
            step_type varchar(50) NOT NULL,
            step_group varchar(20) NOT NULL,
            step_status varchar(20) NOT NULL,
            step_order int unsigned NOT NULL,
            PRIMARY KEY (ID)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}sib_model_forms (
            id int(20) NOT NULL AUTO_INCREMENT,
            title varchar(120),
            html longtext,
            css longtext,
            listID longtext,
            templateID int(20) NOT NULL DEFAULT -1,
            confirmID int(20) NOT NULL DEFAULT -1,
            isDopt int(1) NOT NULL DEFAULT 0,
            isOpt int(1) NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            gCaptcha int(1) NOT NULL DEFAULT 0,
            gCaptcha_secret varchar(255),
            gCaptcha_site varchar(255),
            termAccept int(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'newsletter', 'newsletter_customforms', 'gh_contacts', 'gh_tags', 'gh_tag_relationships', 'gh_funnels', 'gh_steps', 'sib_model_forms' ] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'admin@example.com' ]);
        wp_set_current_user($this->admin);
        foreach ([ 'newsletter', 'groundhogg', 'mc4wp', 'brevo' ] as $slug) {
            add_filter("wpmcp_{$slug}_active", '__return_true');
        }
        add_filter('wpmcp_enable_newsletter_write', '__return_true');
        update_option('newsletter_lists', [
            'list_1'        => 'Weekly',
            'list_1_status' => 1,
            'list_2'        => 'Customers',
            'list_2_status' => 0,
        ]);
        update_option('newsletter_subscription', [ 'noconfirmation' => 0 ]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([ 'newsletter', 'newsletter_customforms', 'gh_contacts', 'gh_tags', 'gh_tag_relationships', 'gh_funnels', 'gh_steps', 'sib_model_forms' ] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table);
        }
        foreach ([ 'newsletter', 'groundhogg', 'mc4wp', 'brevo' ] as $slug) {
            remove_all_filters("wpmcp_{$slug}_active");
        }
        remove_all_filters('wpmcp_enable_newsletter_write');
        remove_all_filters('wpmcp_crm_direct_subscribe');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function code(array $out): string
    {
        $this->assertArrayHasKey('error', $out, (string) wp_json_encode($out));
        return (string) $out['error']['code'];
    }

    private function subscriber(string $email, string $status = 'C', array $lists = []): int
    {
        global $wpdb;
        $row = [ 'email' => $email, 'name' => 'Ada', 'surname' => 'Lovelace', 'status' => $status, 'token' => 't' . wp_rand() ];
        foreach ($lists as $list) {
            $row[ 'list_' . $list ] = 1;
        }
        $wpdb->insert($wpdb->prefix . 'newsletter', $row);
        return (int) $wpdb->insert_id;
    }

    private function newsletter_row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'newsletter', $id), ARRAY_A);
    }

    // ------------------------------------------------------------- Newsletter

    public function test_newsletter_summary_counts_contacts_lists_and_forms(): void
    {
        global $wpdb;
        $this->subscriber('a@example.com', 'C', [ 1 ]);
        $this->subscriber('b@example.com', 'S');
        $this->subscriber('c@example.com', 'U');
        $wpdb->insert($wpdb->prefix . 'newsletter_customforms', [ 'name' => 'Footer', 'lists' => '1', 'optin' => 'double' ]);

        $out = $this->ok($this->read('newsletter-summary'));
        $this->assertSame(3, $out['contacts']['total']);
        $this->assertSame(1, $out['contacts']['by_status']['subscribed']);
        $this->assertSame(1, $out['contacts']['by_status']['pending']);
        $this->assertSame(1, $out['contacts']['by_status']['unsubscribed']);
        $this->assertSame(2, $out['lists']);
        $this->assertSame(1, $out['forms']);
        $this->assertSame('double', $out['optin']);
    }

    public function test_newsletter_lists_carry_their_subscribed_counts(): void
    {
        $this->subscriber('a@example.com', 'C', [ 1, 2 ]);
        $this->subscriber('b@example.com', 'C', [ 1 ]);
        $this->subscriber('c@example.com', 'U', [ 1 ]);

        $lists = $this->ok($this->read('newsletter-list-lists'))['lists'];
        $this->assertSame([ 1, 2 ], array_column($lists, 'id'));
        $this->assertSame('Weekly', $lists[0]['title']);
        $this->assertTrue($lists[0]['public']);
        $this->assertSame(3, $lists[0]['contacts']);
        $this->assertSame(2, $lists[0]['subscribed']);
        $this->assertFalse($lists[1]['public']);
    }

    public function test_newsletter_contacts_are_listed_masked_and_filtered(): void
    {
        $this->subscriber('ada@example.com', 'C', [ 1 ]);
        $this->subscriber('bob@example.org', 'S', [ 2 ]);

        $out = $this->ok($this->read('newsletter-list-contacts'));
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['masked']);
        $this->assertSame('b***@example.org', $out['contacts'][0]['email']);
        $this->assertSame('A***', $out['contacts'][0]['first_name']);

        $one = $this->ok($this->read('newsletter-list-contacts', [ 'list_id' => 1 ]));
        $this->assertSame(1, $one['total']);
        $this->assertSame([ 1 ], $one['contacts'][0]['lists']);

        $pending = $this->ok($this->read('newsletter-list-contacts', [ 'status' => 'pending' ]));
        $this->assertSame(1, $pending['total']);

        $search = $this->ok($this->read('newsletter-list-contacts', [ 'search' => 'ada@' ]));
        $this->assertSame(1, $search['total']);

        $this->assertSame('list_not_found', $this->code($this->read('newsletter-list-contacts', [ 'list_id' => 9 ])));
    }

    public function test_newsletter_get_contact_returns_one_in_full(): void
    {
        $id  = $this->subscriber('ada@example.com', 'C', [ 2 ]);
        $out = $this->ok($this->read('newsletter-get-contact', [ 'email' => 'ada@example.com' ]));
        $this->assertSame($id, $out['contact']['id']);
        $this->assertSame('ada@example.com', $out['contact']['email']);
        $this->assertSame('subscribed', $out['contact']['status']);
        $this->assertSame([ 2 ], $out['contact']['lists']);
        $this->assertArrayNotHasKey('token', $out['contact'], 'the unsubscribe token is never returned');
        $this->assertSame('contact_not_found', $this->code($this->read('newsletter-get-contact', [ 'id' => 999 ])));
    }

    public function test_newsletter_forms_list_custom_and_legacy_forms(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'newsletter_customforms', [ 'name' => 'Footer', 'lists' => '1,2', 'optin' => 'single', 'token' => 'secret' ]);
        update_option('newsletter_htmlforms', [ 'form_1' => '<form></form>', 'form_2' => '' ]);

        $forms = $this->ok($this->read('newsletter-list-forms'))['forms'];
        $this->assertCount(2, $forms);
        $this->assertSame('Footer', $forms[0]['title']);
        $this->assertSame([ 1, 2 ], $forms[0]['lists']);
        $this->assertSame('single', $forms[0]['optin']);
        $this->assertArrayNotHasKey('token', $forms[0]);
        $this->assertSame('[newsletter_form form="1"]', $forms[1]['shortcode']);
        delete_option('newsletter_htmlforms');
    }

    public function test_newsletter_create_contact_is_pending_and_undone_by_rollback(): void
    {
        $out     = $this->write('newsletter-create-contact', [ 'email' => 'new@example.com', 'first_name' => 'New', 'lists' => [ 1 ] ]);
        $contact = $this->ok($out)['contact'];
        $this->assertSame('pending', $contact['status'], 'a new contact waits for its own confirmation');
        $this->assertSame([ 1 ], $contact['lists']);
        $this->assertNotSame('', (string) $this->newsletter_row($contact['id'])['token'], 'a new contact gets a token for its unsubscribe link');

        $stored = Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot'];
        $this->assertSame(Crm_Rows_Snapshot::key('newsletter_subscriber', $contact['id']), $stored['object_id']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->newsletter_row($contact['id']), 'undo removes the contact it created');
    }

    public function test_newsletter_create_contact_refusals(): void
    {
        $this->subscriber('ada@example.com');
        $this->assertSame('contact_exists', $this->code($this->write('newsletter-create-contact', [ 'email' => 'ada@example.com' ])));
        $this->assertSame('list_not_found', $this->code($this->write('newsletter-create-contact', [ 'email' => 'x@example.com', 'lists' => [ 7 ] ])));
        $this->assertSame('double_optin_required', $this->code($this->write('newsletter-create-contact', [ 'email' => 'x@example.com', 'status' => 'subscribed' ])));

        add_filter('wpmcp_crm_direct_subscribe', '__return_true');
        $contact = $this->ok($this->write('newsletter-create-contact', [ 'email' => 'x@example.com', 'status' => 'subscribed' ]))['contact'];
        $this->assertSame('subscribed', $contact['status']);
    }

    public function test_newsletter_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_newsletter_write');
        $out = $this->write('newsletter-create-contact', [ 'email' => 'x@example.com' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString('wpmcp_enable_newsletter_write', $out['error']['message']);
    }

    public function test_newsletter_update_contact_is_undone_by_rollback(): void
    {
        $id  = $this->subscriber('ada@example.com');
        $out = $this->write('newsletter-update-contact', [ 'id' => $id, 'first_name' => 'Augusta', 'last_name' => 'King' ]);
        $this->assertSame('Augusta', $this->ok($out)['contact']['first_name']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->newsletter_row($id);
        $this->assertSame('Ada', $row['name']);
        $this->assertSame('Lovelace', $row['surname']);
        $this->assertSame('nothing_to_update', $this->code($this->write('newsletter-update-contact', [ 'id' => $id ])));
    }

    public function test_newsletter_unsubscribe_is_never_undone(): void
    {
        $id  = $this->subscriber('ada@example.com');
        $out = $this->write('newsletter-update-contact', [ 'id' => $id, 'status' => 'unsubscribed', 'first_name' => 'Augusta' ]);
        $this->assertSame('unsubscribed', $this->ok($out)['contact']['status']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->newsletter_row($id);
        $this->assertSame('U', $row['status'], 'an unsubscribed contact is never set back to subscribed');
        $this->assertSame('Ada', $row['name'], 'the rest of the write is undone');
        $this->assertStringContainsString('status kept as U', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_newsletter_list_membership_add_remove_and_undo(): void
    {
        $id  = $this->subscriber('ada@example.com', 'C', [ 2 ]);
        $out = $this->write('newsletter-update-contact-lists', [ 'id' => $id, 'add' => [ 1 ], 'remove' => [ 2 ] ]);
        $this->assertSame([ 1 ], $this->ok($out)['contact']['lists']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->newsletter_row($id);
        $this->assertSame('0', (string) $row['list_1']);
        $this->assertSame('1', (string) $row['list_2']);
    }

    public function test_newsletter_suppressed_contact_is_never_added_to_a_list(): void
    {
        $id = $this->subscriber('ada@example.com', 'U');
        $this->assertSame('contact_suppressed', $this->code($this->write('newsletter-update-contact-lists', [ 'id' => $id, 'add' => [ 1 ] ])));
        $this->ok($this->write('newsletter-update-contact-lists', [ 'id' => $id, 'remove' => [ 1 ] ]));
        $this->assertSame('invalid_args', $this->code($this->write('newsletter-update-contact-lists', [ 'id' => $id, 'add' => [ 1 ], 'remove' => [ 1 ] ])));
        $this->assertSame('list_not_found', $this->code($this->write('newsletter-update-contact-lists', [ 'id' => $id, 'remove' => [ 33 ] ])));
    }

    public function test_newsletter_ops_need_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('newsletter-list-contacts'));
    }

    // ------------------------------------------------------------- Groundhogg

    private function gh_contact(string $email, int $status, array $tags = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'gh_contacts', [ 'email' => $email, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'user_id' => 0, 'owner_id' => 0, 'optin_status' => $status, 'is_free' => 0, 'date_created' => '2026-01-02 03:04:05' ]);
        $id = (int) $wpdb->insert_id;
        foreach ($tags as $tag) {
            $wpdb->insert($wpdb->prefix . 'gh_tag_relationships', [ 'tag_id' => $tag, 'contact_id' => $id, 'date_created' => '2026-01-02 03:04:05' ]);
        }
        return $id;
    }

    private function gh_tag(string $name): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'gh_tags', [ 'tag_slug' => sanitize_title($name), 'tag_name' => $name, 'tag_description' => '', 'show_as_preference' => 0 ]);
        return (int) $wpdb->insert_id;
    }

    public function test_groundhogg_reads_summary_tags_contacts_and_forms(): void
    {
        global $wpdb;
        $vip = $this->gh_tag('VIP');
        $this->gh_tag('Lead');
        $this->gh_contact('grace@example.com', 2, [ $vip ]);
        $this->gh_contact('alan@example.com', 3);
        $wpdb->insert($wpdb->prefix . 'gh_funnels', [ 'title' => 'Welcome', 'status' => 'active', 'author' => 1 ]);
        $funnel = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'gh_steps', [ 'funnel_id' => $funnel, 'step_title' => 'Signup form', 'step_type' => 'form_fill', 'step_group' => 'benchmark', 'step_status' => 'ready', 'step_order' => 1 ]);
        $wpdb->insert($wpdb->prefix . 'gh_steps', [ 'funnel_id' => $funnel, 'step_title' => 'Send email', 'step_type' => 'send_email', 'step_group' => 'action', 'step_status' => 'ready', 'step_order' => 2 ]);

        $summary = $this->ok($this->read('groundhogg-summary'));
        $this->assertSame(2, $summary['contacts']['total']);
        $this->assertSame(1, $summary['contacts']['by_status']['confirmed']);
        $this->assertSame(1, $summary['contacts']['by_status']['unsubscribed']);
        $this->assertSame(2, $summary['tags']);
        $this->assertSame(1, $summary['forms']);

        $lists = $this->ok($this->read('groundhogg-list-lists'))['lists'];
        $this->assertSame('VIP', $lists[0]['title']);
        $this->assertSame(1, $lists[0]['contacts']);

        $contacts = $this->ok($this->read('groundhogg-list-contacts', [ 'tag_id' => $vip ]));
        $this->assertSame(1, $contacts['total']);
        $this->assertSame('g***@example.com', $contacts['contacts'][0]['email']);
        $this->assertSame('confirmed', $contacts['contacts'][0]['status']);
        $this->assertSame([ $vip ], $contacts['contacts'][0]['tags']);
        $this->assertSame(1, $this->ok($this->read('groundhogg-list-contacts', [ 'status' => 'unsubscribed' ]))['total']);

        $forms = $this->ok($this->read('groundhogg-list-forms'))['forms'];
        $this->assertCount(1, $forms);
        $this->assertSame('Signup form', $forms[0]['title']);
        $this->assertSame('Welcome', $forms[0]['funnel']);
        $this->assertSame('[gh_form id="' . $forms[0]['id'] . '"]', $forms[0]['shortcode']);
    }

    // ------------------------------------------------------------- MC4WP

    public function test_mc4wp_reads_cached_lists_and_forms_never_the_api_key(): void
    {
        update_option('mc4wp', [ 'api_key' => 'abc123-us1' ]);
        set_transient('mc4wp_mailchimp_lists', [
            'a1' => (object) [ 'id' => 'a1', 'name' => 'Main', 'web_id' => 5, 'stats' => (object) [ 'member_count' => 42 ] ],
        ]);
        $form = self::factory()->post->create([ 'post_type' => 'mc4wp-form', 'post_title' => 'Sidebar', 'post_status' => 'publish' ]);
        update_post_meta($form, '_mc4wp_settings', [ 'lists' => [ 'a1' ], 'double_optin' => '1', 'update_existing' => '0' ]);

        $summary = $this->ok($this->read('mc4wp-summary'));
        $this->assertTrue($summary['api_key_set']);
        $this->assertSame(1, $summary['lists']);
        $this->assertSame(1, $summary['forms']);
        $this->assertStringNotContainsString('abc123', (string) wp_json_encode($summary));

        $lists = $this->ok($this->read('mc4wp-list-lists'));
        $this->assertTrue($lists['cached']);
        $this->assertSame([ [ 'id' => 'a1', 'title' => 'Main', 'web_id' => 5, 'contacts' => 42 ] ], $lists['lists']);

        $forms = $this->ok($this->read('mc4wp-list-forms'))['forms'];
        $this->assertSame($form, $forms[0]['id']);
        $this->assertSame([ 'a1' ], $forms[0]['lists']);
        $this->assertTrue($forms[0]['double_optin']);
        $this->assertSame('[mc4wp_form id="' . $form . '"]', $forms[0]['shortcode']);

        delete_transient('mc4wp_mailchimp_lists');
        $this->assertFalse($this->ok($this->read('mc4wp-list-lists'))['cached']);
        delete_option('mc4wp');
    }

    // ------------------------------------------------------------- Brevo

    public function test_brevo_reads_cached_lists_and_forms_never_secrets(): void
    {
        global $wpdb;
        update_option('sib_main_option', [ 'access_key' => 'xkeysib-secret' ]);
        update_option('sib_api_key_v3', 'xkeysib-secret');
        set_transient('sib_list_' . md5('xkeysib-secret'), [ [ 'id' => 3, 'name' => 'Newsletter' ] ]);
        $wpdb->insert($wpdb->prefix . 'sib_model_forms', [ 'title' => 'Default', 'html' => '<p>x</p>', 'listID' => serialize([ '3' ]), 'isDopt' => 1, 'date' => '2026-01-01', 'gCaptcha' => 1, 'gCaptcha_secret' => 'captcha-secret' ]);

        $summary = $this->ok($this->read('brevo-summary'));
        $this->assertTrue($summary['api_key_set']);
        $this->assertSame(1, $summary['forms']);
        $this->assertStringNotContainsString('xkeysib', (string) wp_json_encode($summary));

        $lists = $this->ok($this->read('brevo-list-lists'));
        $this->assertTrue($lists['cached']);
        $this->assertSame([ [ 'id' => 3, 'title' => 'Newsletter' ] ], $lists['lists']);

        $forms = $this->ok($this->read('brevo-list-forms'));
        $this->assertSame([ 3 ], $forms['forms'][0]['lists']);
        $this->assertTrue($forms['forms'][0]['double_optin']);
        $this->assertTrue($forms['forms'][0]['captcha']);
        $this->assertStringNotContainsString('captcha-secret', (string) wp_json_encode($forms));
        $this->assertStringContainsString('[sibwp_form id=', $forms['forms'][0]['shortcode']);

        delete_option('sib_main_option');
        delete_option('sib_api_key_v3');
    }

    // ------------------------------------------------------------- Presence

    public function test_an_inactive_plugin_is_named_in_the_refusal(): void
    {
        remove_all_filters('wpmcp_groundhogg_active');
        add_filter('wpmcp_groundhogg_active', '__return_false');
        $out = $this->read('groundhogg-summary');
        $this->assertContains($this->code($out), [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('Groundhogg', $out['error']['message']);
    }

    public function test_ops_are_paid_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $this->assertArrayHasKey('error', $this->read('newsletter-summary'));
    }
}
