<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Give_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * GiveWP donations, donors, campaigns and form writes on the give pair
 * (issue #680), run without GiveWP loaded.
 *
 * The fixtures follow GiveWP 4.19 (wordpress.org): a donation is a
 * give_payment post whose details are _give_payment_* and _give_donor_billing_*
 * meta (GiveWP routes that meta to its own give_donationmeta table through the
 * post meta filters, so the meta API reads it either way); donors are rows of
 * {prefix}give_donors with {prefix}give_donormeta beside them; campaigns are
 * rows of {prefix}give_campaigns. The DDL below is GiveWP's own
 * (includes/database/class-give-db-donors.php, class-give-db-donor-meta.php,
 * src/Campaigns/Migrations/Tables/CreateCampaignsTable.php and
 * CreateCampaignFormsTable.php).
 */
class GiveDonationsTest extends \WP_UnitTestCase
{
    private Give_Integration $integration;

    private const STATUSES = [ 'refunded', 'failed', 'cancelled', 'abandoned', 'processing', 'revoked', 'preapproval' ];

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::drop_tables();
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}give_donors (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            email varchar(255) NOT NULL,
            name mediumtext NOT NULL,
            purchase_value mediumtext NOT NULL,
            purchase_count bigint(20) NOT NULL,
            payment_ids longtext NOT NULL,
            date_created datetime NOT NULL,
            token VARCHAR(255) NOT NULL,
            verify_key VARCHAR(255) NOT NULL,
            verify_throttle DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        )");
        $wpdb->query("CREATE TABLE {$p}give_donormeta (
            meta_id bigint(20) NOT NULL AUTO_INCREMENT,
            donor_id bigint(20) NOT NULL,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext,
            PRIMARY KEY  (meta_id)
        )");
        $wpdb->query("CREATE TABLE {$p}give_campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_page_id INT UNSIGNED NULL,
            form_id INT NOT NULL,
            campaign_type VARCHAR(12) NOT NULL DEFAULT '',
            campaign_title TEXT NOT NULL,
            campaign_url TEXT NOT NULL,
            short_desc TEXT NOT NULL,
            long_desc TEXT NOT NULL,
            campaign_logo TEXT NOT NULL,
            campaign_image TEXT NOT NULL,
            primary_color VARCHAR(7) NOT NULL,
            secondary_color VARCHAR(7) NOT NULL,
            campaign_goal INT UNSIGNED NOT NULL,
            goal_type VARCHAR(24) NOT NULL DEFAULT 'amount',
            status VARCHAR(12) NOT NULL,
            start_date DATETIME NULL,
            end_date DATETIME NULL,
            date_created DATETIME NOT NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE {$p}give_campaign_forms (
            campaign_id INT UNSIGNED NOT NULL,
            form_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (campaign_id, form_id)
        )");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop_tables();
    }

    private static function drop_tables(): void
    {
        global $wpdb;
        foreach ([ 'give_donors', 'give_donormeta', 'give_campaigns', 'give_campaign_forms' ] as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture teardown.
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('give_forms', [ 'public' => true, 'label' => 'Donation Forms' ]);
        register_post_type('give_payment', [ 'public' => false, 'label' => 'Donations' ]);
        foreach (self::STATUSES as $status) {
            register_post_status($status, [ 'public' => false, 'exclude_from_search' => false ]);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->integration = new Give_Integration();
    }

    protected function tearDown(): void
    {
        global $wpdb, $wp_post_statuses;
        remove_all_filters('wpmcp_enable_give_write');
        foreach (self::STATUSES as $status) {
            unset($wp_post_statuses[ $status ]);
        }
        unregister_post_type('give_payment');
        unregister_post_type('give_forms');
        foreach ([ 'give_donors', 'give_donormeta', 'give_campaigns', 'give_campaign_forms' ] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture reset.
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function form(string $title = 'Annual Fund', array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'give_forms', 'post_title' => $title, 'post_status' => 'publish' ]);
        $meta += [
            '_give_price_option' => 'set',
            '_give_set_price'    => '50.000000',
            '_give_goal_option'  => 'disabled',
            '_give_set_goal'     => '1000.000000',
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function donor(string $name = 'Jane Donor', string $email = 'jane@example.org'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'give_donors', [
            'user_id'         => 0,
            'email'           => $email,
            'name'            => $name,
            'purchase_value'  => '75.000000',
            'purchase_count'  => 2,
            'payment_ids'     => '',
            'date_created'    => '2026-01-02 10:00:00',
            'token'           => 'secret-token',
            'verify_key'      => 'secret-key',
            'verify_throttle' => '2026-01-02 10:00:00',
        ]);
        $id = (int) $wpdb->insert_id;
        [$first, $last] = explode(' ', $name . ' ');
        $wpdb->insert($wpdb->prefix . 'give_donormeta', [ 'donor_id' => $id, 'meta_key' => '_give_donor_first_name', 'meta_value' => $first ]);
        $wpdb->insert($wpdb->prefix . 'give_donormeta', [ 'donor_id' => $id, 'meta_key' => '_give_donor_last_name', 'meta_value' => trim($last) ]);
        $wpdb->insert($wpdb->prefix . 'give_donormeta', [ 'donor_id' => $id, 'meta_key' => 'additional_email', 'meta_value' => 'jane.alt@example.org' ]);
        return $id;
    }

    private function donation(int $form_id, int $donor_id, string $amount, string $status = 'publish', array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'give_payment', 'post_status' => $status, 'post_date' => '2026-03-04 12:00:00' ]);
        $meta += [
            '_give_payment_total'            => $amount,
            '_give_payment_currency'         => 'USD',
            '_give_payment_form_id'          => (string) $form_id,
            '_give_payment_form_title'       => get_the_title($form_id),
            '_give_payment_donor_id'         => (string) $donor_id,
            '_give_payment_donor_email'      => 'jane@example.org',
            '_give_donor_billing_first_name' => 'Jane',
            '_give_donor_billing_last_name'  => 'Donor',
            '_give_donor_billing_city'       => 'Lahore',
            '_give_payment_gateway'          => 'manual',
            '_give_payment_mode'             => 'live',
            '_give_payment_purchase_key'     => 'purchase-secret',
            '_give_payment_donor_ip'         => '203.0.113.9',
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function campaign(string $title, int $form_id, int $goal = 5000): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'give_campaigns', [
            'form_id'         => $form_id,
            'campaign_type'   => 'core',
            'campaign_title'  => $title,
            'campaign_url'    => '',
            'short_desc'      => 'Short',
            'long_desc'       => '',
            'campaign_logo'   => '',
            'campaign_image'  => '',
            'primary_color'   => '#0b72d9',
            'secondary_color' => '#27ae60',
            'campaign_goal'   => $goal,
            'goal_type'       => 'amount',
            'status'          => 'active',
            'start_date'      => '2026-01-01 00:00:00',
            'date_created'    => '2026-01-01 00:00:00',
        ]);
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'give_campaign_forms', [ 'campaign_id' => $id, 'form_id' => $form_id ]);
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        return $this->integration->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = [], bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return $this->integration->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out['error'] ?? null));
        return $out;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function test_catalog_lists_the_new_operations(): void
    {
        $names = array_column($this->read('list-operations')['result']['operations'], 'name');
        foreach ([ 'list-donations', 'get-donation', 'list-donors', 'get-donor', 'list-campaigns', 'donation-summary', 'update-form', 'update-donation-status' ] as $op) {
            $this->assertContains($op, $names);
        }
    }

    public function test_list_donations_masks_the_donor_and_filters(): void
    {
        $form  = $this->form();
        $other = $this->form('Other');
        $donor = $this->donor();
        $a     = $this->donation($form, $donor, '25.000000');
        $this->donation($form, $donor, '10.000000', 'refunded');
        $this->donation($other, $donor, '5.000000');

        $out = $this->ok($this->read('list-donations', [ 'form_id' => $form ]))['result'];
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['masked']);

        $out = $this->ok($this->read('list-donations', [ 'form_id' => $form, 'status' => 'complete' ]))['result'];
        $this->assertSame(1, $out['total']);
        $row = $out['donations'][0];
        $this->assertSame($a, $row['id']);
        $this->assertSame('complete', $row['status']);
        $this->assertSame('25.000000', $row['amount']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame($form, $row['form_id']);
        $this->assertSame($donor, $row['donor_id']);
        $this->assertSame('j***@example.org', $row['donor_email']);
        $this->assertSame('J***', $row['donor_name']);
        $this->assertArrayNotHasKey('purchase_key', $row);
    }

    public function test_get_donation_returns_the_full_record_without_secrets(): void
    {
        $form  = $this->form();
        $donor = $this->donor();
        $id    = $this->donation($form, $donor, '25.000000');

        $d = $this->ok($this->read('get-donation', [ 'donation_id' => $id ]))['result']['donation'];
        $this->assertSame('jane@example.org', $d['donor_email']);
        $this->assertSame('Jane', $d['first_name']);
        $this->assertSame('Lahore', $d['billing']['city']);
        $this->assertSame('manual', $d['gateway']);
        $this->assertSame('live', $d['mode']);
        $encoded = (string) wp_json_encode($d);
        $this->assertStringNotContainsString('purchase-secret', $encoded);
        $this->assertStringNotContainsString('203.0.113.9', $encoded);
    }

    public function test_get_donation_refuses_a_post_that_is_not_a_donation(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->read('get-donation', [ 'donation_id' => $page ]);
        $this->assertSame('donation_not_found', $out['error']['code'] ?? null);
    }

    public function test_donation_reads_need_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'list-donations', 'list-donors', 'list-campaigns', 'donation-summary' ] as $op) {
            $this->assertArrayHasKey('error', $this->read($op), $op);
        }
    }

    public function test_list_donors_is_masked_and_get_donor_is_full(): void
    {
        $form  = $this->form();
        $donor = $this->donor();
        $this->donor('Ali Khan', 'ali@example.com');
        $d1 = $this->donation($form, $donor, '25.000000');

        $list = $this->ok($this->read('list-donors'))['result'];
        $this->assertSame(2, $list['total']);
        $this->assertTrue($list['masked']);
        $emails = array_column($list['donors'], 'email');
        $this->assertContains('j***@example.org', $emails);
        $this->assertStringNotContainsString('secret-token', (string) wp_json_encode($list));

        $search = $this->ok($this->read('list-donors', [ 'search' => 'ali@' ]))['result'];
        $this->assertSame(1, $search['total']);

        $one = $this->ok($this->read('get-donor', [ 'donor_id' => $donor ]))['result']['donor'];
        $this->assertSame('jane@example.org', $one['email']);
        $this->assertSame('Jane', $one['first_name']);
        $this->assertSame([ 'jane.alt@example.org' ], $one['additional_emails']);
        $this->assertSame($d1, $one['recent_donations'][0]['id']);
        $this->assertStringNotContainsString('secret', (string) wp_json_encode($one));

        $this->assertSame('donor_not_found', $this->read('get-donor', [ 'donor_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_list_campaigns_with_raised_totals(): void
    {
        $form  = $this->form();
        $donor = $this->donor();
        $c     = $this->campaign('Winter Appeal', $form, 5000);
        $this->donation($form, $donor, '25.000000', 'publish', [ '_give_campaign_id' => (string) $c ]);
        $this->donation($form, $donor, '30.000000', 'publish', [ '_give_campaign_id' => (string) $c ]);
        $this->donation($form, $donor, '99.000000', 'refunded', [ '_give_campaign_id' => (string) $c ]);

        $out = $this->ok($this->read('list-campaigns'))['result'];
        $this->assertSame(1, $out['total']);
        $row = $out['campaigns'][0];
        $this->assertSame($c, $row['id']);
        $this->assertSame('Winter Appeal', $row['title']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(5000, $row['goal']);
        $this->assertSame([ $form ], $row['form_ids']);
        $this->assertEqualsWithDelta(55.0, $row['raised'], 0.001);
        $this->assertSame(2, $row['donations']);
    }

    public function test_donation_summary_groups_by_status_and_currency(): void
    {
        $form  = $this->form();
        $donor = $this->donor();
        $this->donation($form, $donor, '25.000000');
        $this->donation($form, $donor, '30.500000');
        $this->donation($form, $donor, '10.000000', 'refunded');
        $this->donation($form, $donor, '7.000000', 'publish', [ '_give_payment_currency' => 'EUR' ]);
        $this->donation($form, $donor, '1.000000', 'publish', [ '_give_payment_mode' => 'test' ]);

        $out = $this->ok($this->read('donation-summary', [ 'mode' => 'live' ]))['result'];
        $this->assertSame(4, $out['count']);
        $this->assertSame(2, $out['by_status']['complete']['USD']['count']);
        $this->assertEqualsWithDelta(55.5, $out['by_status']['complete']['USD']['amount'], 0.001);
        $this->assertSame(1, $out['by_status']['refunded']['USD']['count']);
        $this->assertEqualsWithDelta(7.0, $out['by_status']['complete']['EUR']['amount'], 0.001);

        $none = $this->ok($this->read('donation-summary', [ 'from' => '2030-01-01' ]))['result'];
        $this->assertSame(0, $none['count']);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $form = $this->form();
        $out  = $this->write('update-form', [ 'form_id' => $form, 'title' => 'New' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_give_write', (string) wp_json_encode($out));
    }

    public function test_update_form_changes_title_amount_and_goal_and_the_undo_puts_them_back(): void
    {
        add_filter('wpmcp_enable_give_write', '__return_true');
        $form = $this->form();

        $out = $this->ok($this->write('update-form', [ 'form_id' => $form, 'title' => 'Spring Fund', 'status' => 'draft', 'price' => 75, 'goal_enabled' => true, 'goal' => 2500 ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Spring Fund', get_the_title($form));
        $this->assertSame('draft', get_post_status($form));
        $this->assertSame('75.000000', get_post_meta($form, '_give_set_price', true));
        $this->assertSame('enabled', get_post_meta($form, '_give_goal_option', true));
        $this->assertSame('2500.000000', get_post_meta($form, '_give_set_goal', true));
        $this->assertSame('Spring Fund', $out['result']['form']['title']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_post_cache($form);
        $this->assertSame('Annual Fund', get_the_title($form));
        $this->assertSame('publish', get_post_status($form));
        $this->assertSame('50.000000', get_post_meta($form, '_give_set_price', true));
        $this->assertSame('disabled', get_post_meta($form, '_give_goal_option', true));
    }

    public function test_update_form_refuses_amounts_on_a_multi_level_or_visual_builder_form(): void
    {
        add_filter('wpmcp_enable_give_write', '__return_true');
        $multi  = $this->form('Multi', [ '_give_price_option' => 'multi' ]);
        $visual = $this->form('Visual', [ 'formBuilderSettings' => '{"formTitle":"Visual"}' ]);

        $this->assertSame('multi_level_form', $this->write('update-form', [ 'form_id' => $multi, 'price' => 10 ])['error']['code'] ?? null);
        $this->assertSame('visual_builder_form', $this->write('update-form', [ 'form_id' => $visual, 'goal' => 10 ])['error']['code'] ?? null);
        $this->assertSame('50.000000', get_post_meta($multi, '_give_set_price', true));

        $this->assertSame('visual_builder_form', $this->write('update-form', [ 'form_id' => $visual, 'title' => 'Visual 2' ])['error']['code'] ?? null);
        $ok = $this->ok($this->write('update-form', [ 'form_id' => $visual, 'status' => 'draft' ]));
        $this->assertSame('draft', $ok['result']['form']['status']);
    }

    public function test_update_form_refuses_a_post_that_is_not_a_form(): void
    {
        add_filter('wpmcp_enable_give_write', '__return_true');
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->assertSame('form_not_found', $this->write('update-form', [ 'form_id' => $page, 'title' => 'x' ])['error']['code'] ?? null);
    }

    public function test_update_donation_status_needs_confirm_and_reports_it_cannot_be_fully_undone(): void
    {
        add_filter('wpmcp_enable_give_write', '__return_true');
        $form  = $this->form();
        $donor = $this->donor();
        $id    = $this->donation($form, $donor, '25.000000', 'pending');

        $out = $this->write('update-donation-status', [ 'donation_id' => $id, 'status' => 'complete' ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertSame('pending', get_post_status($id));

        $out = $this->ok($this->write('update-donation-status', [ 'donation_id' => $id, 'status' => 'complete' ], true));
        $this->assertFalse($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('pending', $out['result']['previous_status']);
        $this->assertSame('complete', $out['result']['status']);

        // The record is still snapshotted: the status can be put back.
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_post_cache($id);
        $this->assertSame('pending', get_post_status($id));
    }

    public function test_update_donation_status_refuses_unknown_status_same_status_and_non_donations(): void
    {
        add_filter('wpmcp_enable_give_write', '__return_true');
        $form  = $this->form();
        $donor = $this->donor();
        $id    = $this->donation($form, $donor, '25.000000');

        $this->assertSame('invalid_args', $this->write('update-donation-status', [ 'donation_id' => $id, 'status' => 'trash' ], true)['error']['code'] ?? null);
        $this->assertSame('status_unchanged', $this->write('update-donation-status', [ 'donation_id' => $id, 'status' => 'complete' ], true)['error']['code'] ?? null);
        $this->assertSame('donation_not_found', $this->write('update-donation-status', [ 'donation_id' => $form, 'status' => 'refunded' ], true)['error']['code'] ?? null);
        $this->assertSame('publish', get_post_status($id));
    }
}
