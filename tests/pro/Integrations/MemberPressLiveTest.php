<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #668: the MemberPress ops against a real MemberPress.
 *
 * It runs only in the local gate's live MemberPress leg
 * (bin/test-local.sh --live-memberpress), which sets WPMCP_LIVE_MEMBERPRESS=1,
 * copies MemberPress from WPMCP_MEMBERPRESS_SRC onto a separate WordPress
 * install (MemberPress is a commercial plugin, not on wordpress.org), loads
 * it in tests/bootstrap.php and builds its tables through MeprDb::upgrade().
 *
 * What the ops write must be what MemberPress's own models read: a
 * membership created here loads through MeprProduct with the same settings
 * and passes MeprProduct::validate(), a rule loads through MeprRule with its
 * access conditions, and a membership and rule MemberPress stored itself
 * read back through the ops unchanged. Rollback must leave MemberPress
 * reading the old values.
 *
 * Everywhere else MemberPress is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group memberpress-live
 */
class MemberPressLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('MEPR_VERSION') || ! class_exists('MeprProduct') || ! class_exists('MeprRule')) {
            $this->markTestSkipped('Needs the real MemberPress (bin/test-local.sh --live-memberpress, WPMCP_MEMBERPRESS_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        add_filter('wpmcp_enable_memberpress_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_memberpress_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args): array
    {
        $integration = new Plugin_Data_Integration();
        $out         = 'read' === $half
            ? $integration->handle_read([ 'operation' => $op, 'args' => $args ])
            : $integration->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_memberpress_is_detected_and_its_ops_are_available(): void
    {
        $this->assertTrue(\WPMCP\Integrations\MemberPress_Pack::active());
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertTrue($ops['memberpress-create-rule']['dependency_met']);
        $this->assertSame(\MeprUtils::get_mepr_admin_capability(), $ops['memberpress-list-memberships']['capability']);
    }

    public function test_a_membership_created_here_loads_and_validates_through_memberpress(): void
    {
        $out = $this->call('write', 'memberpress-create-membership', [
            'title'            => 'Gold',
            'price'            => 19,
            'period'           => 3,
            'period_type'      => 'months',
            'trial'            => true,
            'trial_days'       => 14,
            'trial_amount'     => 1.5,
            'pricing_benefits' => [ 'Forum', 'Videos' ],
            'expire_type'      => 'delay',
            'expire_after'     => 2,
            'expire_unit'      => 'years',
        ]);
        $id      = $out['result']['membership']['id'];
        $product = new \MeprProduct($id);

        $this->assertSame($id, (int) $product->ID);
        $this->assertSame('19.00', (string) $product->price);
        $this->assertSame(3, (int) $product->period);
        $this->assertSame('months', $product->period_type);
        $this->assertTrue((bool) $product->trial);
        $this->assertSame(14, (int) $product->trial_days);
        $this->assertSame([ 'Forum', 'Videos' ], $product->pricing_benefits);
        $this->assertFalse((bool) $product->limit_cycles);
        $this->assertSame('delay', $product->expire_type);
        $this->assertSame('years', $product->expire_unit);
        $product->validate(); // Throws MeprCreateException on a setting MemberPress would refuse.

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_a_membership_stored_by_memberpress_reads_back_and_an_edit_rolls_back(): void
    {
        $product              = new \MeprProduct();
        $product->post_title  = 'Silver';
        $product->post_status = 'publish';
        $product->price       = 9.99;
        $product->period_type = 'years';
        $product->trial       = false;
        $id                   = (int) $product->store();

        $read = $this->call('read', 'memberpress-get-membership', [ 'id' => $id ])['result']['membership'];
        $this->assertSame('Silver', $read['title']);
        $this->assertSame('9.99', $read['price']);
        $this->assertSame('years', $read['period_type']);
        $this->assertFalse($read['trial']);
        $this->assertSame('Sign Up', $read['signup_button_text']);

        $edit = $this->call('write', 'memberpress-update-membership', [ 'id' => $id, 'price' => 12, 'is_highlighted' => true ]);
        $this->assertSame('12.00', (string) (new \MeprProduct($id))->price);
        $this->assertTrue((bool) (new \MeprProduct($id))->is_highlighted);

        $this->assertTrue(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertSame('9.99', (string) (new \MeprProduct($id))->price);
        $this->assertFalse((bool) (new \MeprProduct($id))->is_highlighted);
    }

    public function test_a_rule_created_here_loads_through_memberpress_with_its_access_and_rolls_back(): void
    {
        $gold = $this->call('write', 'memberpress-create-membership', [ 'title' => 'Gold', 'status' => 'publish' ])['result']['membership']['id'];
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Lounge' ]);

        $this->assertContains('single_page', array_keys(\MeprRule::get_types()));
        $out  = $this->call('write', 'memberpress-create-rule', [
            'type'    => 'single_page',
            'content' => (string) $page,
            'status'  => 'publish',
            'access'  => [ [ 'type' => 'membership', 'condition' => (string) $gold ], [ 'type' => 'role', 'condition' => 'editor' ] ],
        ]);
        $id   = $out['result']['rule']['id'];
        $rule = new \MeprRule($id);

        $this->assertSame('single_page', $rule->mepr_type);
        $this->assertSame((string) $page, (string) $rule->mepr_content);
        $this->assertSame([ 'membership' => [ (string) $gold ], 'role' => [ 'editor' ] ], $rule->mgm_mepr_access('get'));
        $rule->validate();
        $this->assertContains($id, array_map(static fn ($r): int => (int) $r->ID, \MeprRule::get_rules(get_post($page))));

        $listed = $this->call('read', 'memberpress-list-rules', [ 'membership_id' => $gold ])['result']['rules'];
        $this->assertSame([ $id ], array_column($listed, 'id'));

        $update = $this->call('write', 'memberpress-update-rule', [ 'id' => $id, 'access' => [ [ 'type' => 'login_state', 'condition' => 'logged_in' ] ] ]);
        $this->assertSame([ 'login_state' => [ 'logged_in' ] ], (new \MeprRule($id))->mgm_mepr_access('get'));
        Rollback_Service::restore_session($update['session_id']);
        $this->assertSame([ 'membership' => [ (string) $gold ], 'role' => [ 'editor' ] ], (new \MeprRule($id))->mgm_mepr_access('get'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame([], (new \MeprRule($id))->mgm_mepr_access('get'));
    }

    public function test_members_and_transactions_written_by_memberpress_are_listed_without_gateway_data(): void
    {
        $gold = $this->call('write', 'memberpress-create-membership', [ 'title' => 'Gold', 'status' => 'publish', 'price' => 5 ])['result']['membership']['id'];
        $user = self::factory()->user->create([ 'user_login' => 'livemember', 'user_email' => 'live@example.org' ]);

        $txn             = new \MeprTransaction();
        $txn->user_id    = $user;
        $txn->product_id = $gold;
        $txn->amount     = 5.00;
        $txn->total      = 5.00;
        $txn->status     = \MeprTransaction::$complete_str;
        $txn->gateway    = \MeprTransaction::$manual_gateway_str;
        $txn->response   = '{"secret":"gateway payload"}';
        $txn->expires_at = \MeprUtils::db_lifetime();
        $txn_id          = (int) $txn->store();
        ( new \MeprUser($user) )->update_member_data();

        $txns = $this->call('read', 'memberpress-list-transactions', [ 'membership_id' => $gold ])['result'];
        $this->assertSame([ $txn_id ], array_column($txns['transactions'], 'id'));
        $this->assertStringNotContainsString('gateway payload', (string) wp_json_encode($txns));

        $memberships = array_column($this->call('read', 'memberpress-list-memberships', [])['result']['memberships'], null, 'id');
        $this->assertSame(1, $memberships[ $gold ]['active_members']);

        $members = $this->call('read', 'memberpress-list-members', [ 'membership_id' => $gold, 'status' => 'active' ])['result']['members'];
        $this->assertSame([ $user ], array_column($members, 'user_id'));
        $this->assertSame([ $gold ], $members[0]['active_memberships']);
    }
}
