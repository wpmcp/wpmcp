<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/memberpress-stubs.php';

/**
 * MemberPress (issue #668): paid-tier ops on the plugin-data dispatcher pair,
 * so they add no top-level tools.
 *
 * MemberPress is not loaded in the shared test core. Presence is driven
 * through wpmcp_memberpress_active, and the storage is seeded the way
 * MemberPress 1.14.3 keeps it (tests/support/memberpress-stubs.php): a
 * membership is a memberpressproduct post with _mepr_* meta, a rule is a
 * memberpressrule post with _mepr_rules_* meta and its access conditions
 * are rows of {prefix}mepr_rule_access_conditions, and members and
 * transactions are rows of {prefix}mepr_members and
 * {prefix}mepr_transactions.
 *
 * Every write is off until the site opts in (wpmcp_enable_memberpress_write)
 * and is snapshotted: a created membership or rule goes to the trash on
 * rollback, an edit is a post snapshot (meta included), and a rule's access
 * conditions are a plugin_table_rows image keyed by the rule, so one
 * rollback-session restores them exactly. Nothing here moves money: there
 * is no refund, charge or transaction write.
 */
class MemberPressPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'memberpress-list-memberships'  => 'read',
        'memberpress-get-membership'    => 'read',
        'memberpress-list-members'      => 'read',
        'memberpress-list-transactions' => 'read',
        'memberpress-list-rules'        => 'read',
        'memberpress-create-membership' => 'write',
        'memberpress-update-membership' => 'write',
        'memberpress-create-rule'       => 'write',
        'memberpress-update-rule'       => 'write',
    ];

    /** The other plugin-data plugins, off so only MemberPress decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_memberpress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_memberpress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_memberpress_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_memberpress_active', '__return_true');
        add_filter('wpmcp_enable_memberpress_write', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_memberpress_active');
        remove_all_filters('wpmcp_enable_memberpress_write');
        remove_all_filters('mepr_admin_capability');
        wpmcp_test_unregister_memberpress_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A membership stored the way MeprProduct::store_meta() writes one. */
    private function membership(string $title, array $meta = [], string $status = 'publish'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'memberpressproduct', 'post_title' => $title, 'post_status' => $status ]);
        foreach ($meta + [ '_mepr_product_price' => '0.00', '_mepr_product_period' => 1, '_mepr_product_period_type' => 'lifetime', '_mepr_product_trial' => false ] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    /** A rule stored the way MeprRule::store_meta() and MeprRuleAccessCondition::store() write one. */
    private function rule(string $type, string $content, array $access, string $title = 'Rule'): int
    {
        global $wpdb;
        $id = self::factory()->post->create([ 'post_type' => 'memberpressrule', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, '_mepr_rules_type', $type);
        update_post_meta($id, '_mepr_rules_content', $content);
        update_post_meta($id, '_mepr_rules_drip_enabled', false);
        update_post_meta($id, '_mepr_rules_unauth_message_type', 'default');
        foreach ($access as [$access_type, $condition]) {
            $wpdb->insert($wpdb->prefix . 'mepr_rule_access_conditions', [ 'rule_id' => $id, 'access_type' => $access_type, 'access_operator' => 'is', 'access_condition' => (string) $condition ]);
        }
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_memberpress_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('remove_users', $ops[ $name ]['capability'], $name);
        }
        $names = array_keys($ops);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/^memberpress-.*(refund|charge|transaction-(create|update)|cancel)/', $name, 'no money-moving op');
        }
    }

    public function test_without_pro_the_memberpress_ops_are_not_in_the_catalog_and_the_pair_is_not_available(): void
    {
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $ops         = array_column($integration->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse($integration->is_available());
    }

    public function test_while_memberpress_is_inactive_its_ops_answer_memberpress_inactive(): void
    {
        remove_all_filters('wpmcp_memberpress_active');
        add_filter('wpmcp_memberpress_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('memberpress-list-memberships');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertSame('memberpress_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('MemberPress', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_mepr_version_constant(): void
    {
        remove_all_filters('wpmcp_memberpress_active');
        $this->assertSame(defined('MEPR_VERSION'), \WPMCP\Integrations\MemberPress_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_memberpress_write');
        $out = $this->write('memberpress-create-membership', [ 'title' => 'Gold' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_memberpress_write', $out['error']['message']);
        $this->assertSame([], get_posts([ 'post_type' => 'memberpressproduct', 'post_status' => 'any', 'title' => 'Gold' ]));
    }

    public function test_every_op_takes_memberpress_admin_capability(): void
    {
        $this->membership('Gold');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'memberpress-list-memberships', 'memberpress-list-members', 'memberpress-list-transactions', 'memberpress-list-rules' ] as $op) {
            $out = $this->read($op);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, $op);
        }
        $out = $this->write('memberpress-create-membership', [ 'title' => 'Silver' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
    }

    public function test_the_admin_capability_follows_the_mepr_admin_capability_filter(): void
    {
        add_filter('mepr_admin_capability', static fn (): string => 'manage_options');
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertSame('manage_options', $ops['memberpress-list-memberships']['capability']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_memberships_returns_pricing_and_active_member_counts(): void
    {
        $gold   = $this->membership('Gold', [ '_mepr_product_price' => '19.00', '_mepr_product_period' => 1, '_mepr_product_period_type' => 'months', '_mepr_product_trial' => true, '_mepr_product_trial_days' => 7 ]);
        $silver = $this->membership('Silver', [], 'draft');
        $u1     = self::factory()->user->create();
        $u2     = self::factory()->user->create();
        $u3     = self::factory()->user->create();
        wpmcp_test_memberpress_transaction($u1, $gold);
        wpmcp_test_memberpress_transaction($u1, $gold, [ 'created_at' => gmdate('Y-m-d H:i:s') ]);
        wpmcp_test_memberpress_transaction($u2, $gold, [ 'status' => 'confirmed', 'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS) ]);
        wpmcp_test_memberpress_transaction($u3, $gold, [ 'expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS) ]);
        wpmcp_test_memberpress_transaction($u3, $silver, [ 'status' => 'refunded' ]);

        $out  = $this->ok($this->read('memberpress-list-memberships'))['result'];
        $rows = array_column($out['memberships'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('Gold', $rows[ $gold ]['title']);
        $this->assertSame('publish', $rows[ $gold ]['status']);
        $this->assertSame('19.00', $rows[ $gold ]['price']);
        $this->assertSame(1, $rows[ $gold ]['period']);
        $this->assertSame('months', $rows[ $gold ]['period_type']);
        $this->assertTrue($rows[ $gold ]['trial']);
        $this->assertSame(7, $rows[ $gold ]['trial_days']);
        $this->assertSame(2, $rows[ $gold ]['active_members'], 'an expired and a duplicate transaction do not count');
        $this->assertSame(0, $rows[ $silver ]['active_members'], 'a refund does not count');

        $drafts = $this->ok($this->read('memberpress-list-memberships', [ 'status' => 'draft' ]))['result'];
        $this->assertSame([ $silver ], array_column($drafts['memberships'], 'id'));
        $found = $this->ok($this->read('memberpress-list-memberships', [ 'search' => 'Gol' ]))['result'];
        $this->assertSame([ $gold ], array_column($found['memberships'], 'id'));
    }

    public function test_get_membership_reads_every_setting_with_memberpress_defaults_for_missing_meta(): void
    {
        $gold = $this->membership('Gold', [ '_mepr_product_price' => '49.50', '_mepr_product_period_type' => 'years', '_mepr_product_pricing_benefits' => [ 'Forum', 'Videos' ], '_mepr_product_is_highlighted' => true, '_mepr_access_url' => 'https://example.org/welcome' ]);
        $out  = $this->ok($this->read('memberpress-get-membership', [ 'id' => $gold ]))['result']['membership'];

        $this->assertSame($gold, $out['id']);
        $this->assertSame('Gold', $out['title']);
        $this->assertSame('49.50', $out['price']);
        $this->assertSame('years', $out['period_type']);
        $this->assertSame([ 'Forum', 'Videos' ], $out['pricing_benefits']);
        $this->assertTrue($out['is_highlighted']);
        $this->assertFalse($out['trial'], 'a stored false (empty string) reads as false');
        $this->assertSame('https://example.org/welcome', $out['access_url']);
        $this->assertSame('none', $out['expire_type'], 'missing meta reads as MemberPress\'s own default');
        $this->assertSame('Sign Up', $out['signup_button_text']);
        $this->assertSame(0, $out['active_members']);
    }

    public function test_get_membership_refuses_a_post_that_is_not_a_membership(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->read('memberpress-get-membership', [ 'id' => $page ]);
        $this->assertSame('membership_not_found', $out['error']['code'] ?? null);
    }

    public function test_list_members_filters_by_membership_and_status_and_returns_only_listed_fields(): void
    {
        $gold   = $this->membership('Gold');
        $silver = $this->membership('Silver');
        $ada    = self::factory()->user->create([ 'display_name' => 'Ada', 'user_email' => 'ada@example.org', 'user_login' => 'ada' ]);
        $bob    = self::factory()->user->create([ 'display_name' => 'Bob', 'user_email' => 'bob@example.org', 'user_login' => 'bob' ]);
        $cy     = self::factory()->user->create([ 'display_name' => 'Cy', 'user_email' => 'cy@example.org', 'user_login' => 'cy' ]);
        wpmcp_test_memberpress_member($ada, "{$gold},{$silver}");
        wpmcp_test_memberpress_member($bob, '', (string) $gold);
        wpmcp_test_memberpress_member($cy, (string) $gold . "1");

        $all = $this->ok($this->read('memberpress-list-members'))['result'];
        $this->assertSame(3, $all['total']);
        $row = array_column($all['members'], null, 'user_id')[ $ada ];
        $this->assertSame([ 'user_id', 'user_login', 'display_name', 'email', 'active_memberships', 'inactive_memberships', 'transactions', 'active_subscriptions', 'total_spent', 'member_since' ], array_keys($row));
        $this->assertSame([ $gold, $silver ], $row['active_memberships']);
        $this->assertSame('ada@example.org', $row['email']);

        $active = $this->ok($this->read('memberpress-list-members', [ 'membership_id' => $gold, 'status' => 'active' ]))['result'];
        $this->assertSame([ $ada ], array_column($active['members'], 'user_id'));
        $any = $this->ok($this->read('memberpress-list-members', [ 'membership_id' => $gold ]))['result'];
        $this->assertEqualsCanonicalizing([ $ada, $bob ], array_column($any['members'], 'user_id'), 'an id that only prefixes another does not match');
        $inactive = $this->ok($this->read('memberpress-list-members', [ 'status' => 'inactive' ]))['result'];
        $this->assertSame([ $bob ], array_column($inactive['members'], 'user_id'));
        $search = $this->ok($this->read('memberpress-list-members', [ 'search' => 'bob@' ]))['result'];
        $this->assertSame([ $bob ], array_column($search['members'], 'user_id'));
    }

    public function test_list_transactions_filters_and_never_returns_the_gateway_response(): void
    {
        $gold = $this->membership('Gold');
        $ada  = self::factory()->user->create();
        $bob  = self::factory()->user->create();
        $t1   = wpmcp_test_memberpress_transaction($ada, $gold, [ 'created_at' => '2026-01-01 10:00:00' ]);
        $t2   = wpmcp_test_memberpress_transaction($bob, $gold, [ 'status' => 'refunded', 'created_at' => '2026-02-01 10:00:00', 'refunded_at' => '2026-02-02 10:00:00' ]);

        $out = $this->ok($this->read('memberpress-list-transactions'))['result'];
        $this->assertSame(2, $out['total']);
        $this->assertSame([ $t2, $t1 ], array_column($out['transactions'], 'id'), 'newest first');
        $this->assertArrayNotHasKey('response', $out['transactions'][0]);
        $this->assertStringNotContainsString('gateway payload', (string) wp_json_encode($out));
        $this->assertSame($gold, $out['transactions'][0]['membership_id']);
        $this->assertSame('refunded', $out['transactions'][0]['status']);
        $this->assertSame('10.00', $out['transactions'][0]['total']);

        $refunds = $this->ok($this->read('memberpress-list-transactions', [ 'status' => 'refunded' ]))['result'];
        $this->assertSame([ $t2 ], array_column($refunds['transactions'], 'id'));
        $by_user = $this->ok($this->read('memberpress-list-transactions', [ 'user_id' => $ada ]))['result'];
        $this->assertSame([ $t1 ], array_column($by_user['transactions'], 'id'));
        $page = $this->ok($this->read('memberpress-list-transactions', [ 'per_page' => 1, 'page' => 2 ]))['result'];
        $this->assertSame([ $t1 ], array_column($page['transactions'], 'id'));
    }

    public function test_list_rules_returns_type_content_and_access_conditions(): void
    {
        $gold  = $this->membership('Gold');
        $page  = self::factory()->post->create([ 'post_type' => 'page' ]);
        $rule1 = $this->rule('single_page', (string) $page, [ [ 'membership', $gold ] ], 'Members page');
        $rule2 = $this->rule('all', '', [ [ 'login_state', 'logged_in' ], [ 'role', 'editor' ] ], 'Everything');

        $out   = $this->ok($this->read('memberpress-list-rules'))['result'];
        $rules = array_column($out['rules'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('single_page', $rules[ $rule1 ]['type']);
        $this->assertSame((string) $page, $rules[ $rule1 ]['content']);
        $this->assertSame([ [ 'type' => 'membership', 'operator' => 'is', 'condition' => (string) $gold ] ], $rules[ $rule1 ]['access']);
        $this->assertCount(2, $rules[ $rule2 ]['access']);

        $for_gold = $this->ok($this->read('memberpress-list-rules', [ 'membership_id' => $gold ]))['result'];
        $this->assertSame([ $rule1 ], array_column($for_gold['rules'], 'id'));
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_membership_stores_it_the_way_memberpress_does_and_rollback_session_trashes_it(): void
    {
        $out = $this->ok($this->write('memberpress-create-membership', [
            'title'       => 'Gold',
            'content'     => 'All the things',
            'price'       => 19,
            'period'      => 1,
            'period_type' => 'months',
            'trial'       => true,
            'trial_days'  => 14,
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_ids']);
        $id = $out['result']['membership']['id'];

        $this->assertSame('memberpressproduct', get_post_type($id));
        $this->assertSame('draft', get_post_status($id), 'a new membership starts as a draft');
        $this->assertSame('19.00', get_post_meta($id, '_mepr_product_price', true), 'price as MeprUtils::format_float() stores it');
        $this->assertSame('months', get_post_meta($id, '_mepr_product_period_type', true));
        $this->assertSame('1', get_post_meta($id, '_mepr_product_trial', true));
        $this->assertSame('14', get_post_meta($id, '_mepr_product_trial_days', true));
        $this->assertSame('', get_post_meta($id, '_mepr_product_limit_cycles', true), 'unset booleans stored as MemberPress stores false');
        $this->assertSame('Sign Up', get_post_meta($id, '_mepr_product_signup_button_text', true));
        $this->assertSame('none', get_post_meta($id, '_mepr_expire_type', true));
        $this->assertTrue(metadata_exists('post', $id, '_mepr_product_pricing_benefits'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_update_membership_is_one_post_snapshot_and_rollback_operation_restores_meta(): void
    {
        $gold = $this->membership('Gold', [ '_mepr_product_price' => '19.00', '_mepr_product_period_type' => 'months' ]);
        $out  = $this->ok($this->write('memberpress-update-membership', [ 'id' => $gold, 'title' => 'Gold Plus', 'price' => 29.5, 'period_type' => 'years', 'pricing_benefits' => [ 'Forum' ], 'status' => 'publish' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Gold Plus', get_the_title($gold));
        $this->assertSame('29.50', get_post_meta($gold, '_mepr_product_price', true));
        $this->assertSame('years', get_post_meta($gold, '_mepr_product_period_type', true));
        $this->assertSame([ 'Forum' ], get_post_meta($gold, '_mepr_product_pricing_benefits', true));
        $this->assertSame('29.50', $out['result']['membership']['price']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Gold', get_the_title($gold));
        $this->assertSame('19.00', get_post_meta($gold, '_mepr_product_price', true));
        $this->assertSame('months', get_post_meta($gold, '_mepr_product_period_type', true));
        $this->assertFalse(metadata_exists('post', $gold, '_mepr_product_pricing_benefits'), 'a key the write added is removed again');
    }

    public function test_update_membership_refuses_bad_input_before_any_snapshot(): void
    {
        $gold   = $this->membership('Gold');
        $before = Snapshot_Store::row_count();

        $this->assertSame('membership_not_found', $this->write('memberpress-update-membership', [ 'id' => self::factory()->post->create(), 'price' => 1 ])['error']['code'] ?? null);
        $this->assertSame('nothing_to_update', $this->write('memberpress-update-membership', [ 'id' => $gold ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('memberpress-update-membership', [ 'id' => $gold, 'period' => 13 ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('memberpress-update-membership', [ 'id' => $gold, 'period_type' => 'fortnights' ])['error']['code'] ?? null);
        $this->assertSame('invalid_setting', $this->write('memberpress-update-membership', [ 'id' => $gold, 'expire_type' => 'fixed' ])['error']['code'] ?? null, 'a fixed expiry needs expire_fixed');
        $this->assertSame($before, Snapshot_Store::row_count());
    }

    public function test_create_rule_stores_post_meta_and_access_rows_and_rollback_session_removes_both(): void
    {
        $gold = $this->membership('Gold');
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Lounge' ]);

        $out = $this->ok($this->write('memberpress-create-rule', [
            'type'    => 'single_page',
            'content' => (string) $page,
            'access'  => [ [ 'type' => 'membership', 'condition' => (string) $gold ], [ 'type' => 'login_state', 'condition' => 'logged_in' ] ],
            'status'  => 'publish',
            'drip_enabled' => true,
            'drip_amount'  => 3,
            'drip_unit'    => 'days',
        ]));
        $this->assertTrue($out['recoverable']);
        $id = $out['result']['rule']['id'];

        $this->assertSame('memberpressrule', get_post_type($id));
        $this->assertSame('publish', get_post_status($id));
        $this->assertNotSame('', get_the_title($id), 'a rule without a title gets one');
        $this->assertSame('single_page', get_post_meta($id, '_mepr_rules_type', true));
        $this->assertSame((string) $page, get_post_meta($id, '_mepr_rules_content', true));
        $this->assertSame('1', get_post_meta($id, '_mepr_rules_drip_enabled', true));
        $this->assertSame('3', get_post_meta($id, '_mepr_rules_drip_amount', true));
        $this->assertSame('registers', get_post_meta($id, '_mepr_rules_drip_after', true));
        $this->assertSame([
            [ 'access_type' => 'membership', 'access_operator' => 'is', 'access_condition' => (string) $gold ],
            [ 'access_type' => 'login_state', 'access_operator' => 'is', 'access_condition' => 'logged_in' ],
        ], wpmcp_test_memberpress_conditions($id));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame([], wpmcp_test_memberpress_conditions($id), 'the access rows the create wrote are removed');
    }

    public function test_update_rule_replaces_access_and_rollback_session_puts_the_old_rows_and_meta_back(): void
    {
        $gold   = $this->membership('Gold');
        $silver = $this->membership('Silver');
        $rule   = $this->rule('all_posts', '', [ [ 'membership', $gold ] ], 'Posts');
        $other  = $this->rule('all_pages', '', [ [ 'membership', $silver ] ], 'Pages');
        $before = wpmcp_test_memberpress_conditions($rule);

        $out = $this->ok($this->write('memberpress-update-rule', [ 'id' => $rule, 'type' => 'category', 'content' => '7', 'access' => [ [ 'type' => 'membership', 'condition' => (string) $silver ] ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('category', get_post_meta($rule, '_mepr_rules_type', true));
        $this->assertSame([ [ 'access_type' => 'membership', 'access_operator' => 'is', 'access_condition' => (string) $silver ] ], wpmcp_test_memberpress_conditions($rule));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([], Rollback_Service::take_warnings());
        $this->assertSame('all_posts', get_post_meta($rule, '_mepr_rules_type', true));
        $this->assertSame($before, wpmcp_test_memberpress_conditions($rule));
        $this->assertSame([ [ 'access_type' => 'membership', 'access_operator' => 'is', 'access_condition' => (string) $silver ] ], wpmcp_test_memberpress_conditions($other), 'another rule\'s rows are untouched');
    }

    public function test_update_rule_without_access_leaves_the_access_rows_alone(): void
    {
        $gold = $this->membership('Gold');
        $rule = $this->rule('all', '', [ [ 'membership', $gold ] ]);
        $out  = $this->ok($this->write('memberpress-update-rule', [ 'id' => $rule, 'unauth_message_type' => 'custom', 'unauth_message' => 'Join to read' ]));
        $this->assertSame('custom', get_post_meta($rule, '_mepr_rules_unauth_message_type', true));
        $this->assertSame('Join to read', get_post_meta($rule, '_mepr_rules_unath_message', true), 'MemberPress\'s own spelling of the key');
        $this->assertCount(1, wpmcp_test_memberpress_conditions($rule));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('default', get_post_meta($rule, '_mepr_rules_unauth_message_type', true));
    }

    public function test_rule_writes_refuse_bad_input_before_any_snapshot(): void
    {
        $gold   = $this->membership('Gold');
        $rule   = $this->rule('all', '', [ [ 'membership', $gold ] ]);
        $before = Snapshot_Store::row_count();

        $cases = [
            'content_required'   => [ 'memberpress-create-rule', [ 'type' => 'single_page', 'access' => [ [ 'type' => 'membership', 'condition' => (string) $gold ] ] ] ],
            'invalid_rule_type'  => [ 'memberpress-create-rule', [ 'type' => 'no such type', 'access' => [ [ 'type' => 'membership', 'condition' => (string) $gold ] ] ] ],
            'invalid_condition'  => [ 'memberpress-create-rule', [ 'type' => 'all', 'access' => [ [ 'type' => 'membership', 'condition' => '999999' ] ] ] ],
            'invalid_args'       => [ 'memberpress-create-rule', [ 'type' => 'all', 'access' => [ [ 'type' => 'shoe_size', 'condition' => '9' ] ] ] ],
            'rule_not_found'     => [ 'memberpress-update-rule', [ 'id' => $gold, 'type' => 'all' ] ],
            'nothing_to_update'  => [ 'memberpress-update-rule', [ 'id' => $rule ] ],
        ];
        foreach ($cases as $code => [$op, $args]) {
            $out = $this->write($op, $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame('invalid_condition', $this->write('memberpress-create-rule', [ 'type' => 'all', 'access' => [ [ 'type' => 'login_state', 'condition' => 'sometimes' ] ] ])['error']['code'] ?? null);
        $this->assertSame('invalid_condition', $this->write('memberpress-create-rule', [ 'type' => 'all', 'access' => [ [ 'type' => 'role', 'condition' => 'no_such_role' ] ] ])['error']['code'] ?? null);
        $this->assertSame('invalid_condition', $this->write('memberpress-create-rule', [ 'type' => 'all', 'access' => [ [ 'type' => 'member', 'condition' => 'nobody-here' ] ] ])['error']['code'] ?? null);
        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame(0, (int) wp_count_posts('memberpressrule')->draft);
    }

    public function test_a_member_condition_names_a_user_login(): void
    {
        $ada = self::factory()->user->create([ 'user_login' => 'ada' ]);
        $out = $this->ok($this->write('memberpress-create-rule', [ 'type' => 'all', 'title' => 'Ada only', 'access' => [ [ 'type' => 'member', 'condition' => 'ada' ] ] ]));
        $this->assertSame([ [ 'access_type' => 'member', 'access_operator' => 'is', 'access_condition' => 'ada' ] ], wpmcp_test_memberpress_conditions($out['result']['rule']['id']));
        $this->assertSame('Ada only', get_the_title($out['result']['rule']['id']));
        $this->assertGreaterThan(0, $ada);
    }
}
