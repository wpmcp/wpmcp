<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\WC_Memberships_Integration;
use WPMCP\Safety\Snapshot_Store;

/**
 * WooCommerce Memberships dispatcher pair, exercised against an injected fake
 * membership API (the host plugin is not loaded in the test process). Plans
 * and memberships are plain objects exposing the same method names the real
 * plan / user-membership classes do; the write ops mutate them in place, and
 * the snapshotted ones target the membership post created in the test DB.
 */
class WcMembershipsIntegrationTest extends \WP_UnitTestCase
{
    private WC_Memberships_Integration $integration;
    private Fake_Wc_Memberships_Api $api;
    private int $membership_post;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->membership_post = self::factory()->post->create([ 'post_type' => 'wc_user_membership', 'post_status' => 'wcm-active' ]);
        $this->api = new Fake_Wc_Memberships_Api($this->membership_post);
        WC_Memberships_Integration::use_api($this->api);
        $this->integration = new WC_Memberships_Integration();
    }

    protected function tearDown(): void
    {
        WC_Memberships_Integration::use_api(null);
        parent::tearDown();
    }

    public function test_unavailable_without_plugin_or_fake(): void
    {
        $this->assertTrue($this->integration->is_available());
        WC_Memberships_Integration::use_api(null);
        $this->assertFalse((new WC_Memberships_Integration())->is_available());
    }

    public function test_list_plans_with_rules_and_member_counts(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-plans' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total']);
        $plan = $out['result']['plans'][0];
        $this->assertSame('Gold', $plan['name']);
        $this->assertSame(2, $plan['active_members']);
        $this->assertSame(3, $plan['members']);
        $this->assertSame('post', $plan['content_restriction_rules'][0]['content_type']);
    }

    public function test_list_members_filters_by_status(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-members', 'args' => [ 'plan_id' => 7, 'status' => 'paused' ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame('paused', $out['result']['members'][0]['status']);
    }

    public function test_get_user_memberships(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-user-memberships', 'args' => [ 'user_id' => 1 ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame(7, $out['result']['memberships'][0]['plan_id']);
    }

    public function test_grant_membership_creates_one(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'grant-membership', 'args' => [ 'user_id' => 9, 'plan_id' => 7 ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(9, $out['result']['membership']['user_id']);
        $this->assertFalse($out['recoverable']);
        $this->assertSame(9, $this->api->created[0]['user_id']);
    }

    public function test_pause_is_snapshotted_and_recoverable(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'pause-membership', 'args' => [ 'membership_id' => $this->membership_post ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('paused', $out['result']['membership']['status']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_resume_and_extend(): void
    {
        $this->api->membership($this->membership_post)->pause();
        $out = $this->integration->handle_write([ 'operation' => 'resume-membership', 'args' => [ 'membership_id' => $this->membership_post ] ]);
        $this->assertSame('active', $out['result']['membership']['status']);

        $out = $this->integration->handle_write([ 'operation' => 'extend-membership', 'args' => [ 'membership_id' => $this->membership_post, 'end_date' => '2030-01-01 00:00:00' ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('2030-01-01 00:00:00', $out['result']['membership']['end_date']);
    }

    public function test_extend_rejects_malformed_date(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'extend-membership', 'args' => [ 'membership_id' => $this->membership_post, 'end_date' => 'soon' ] ]);
        $this->assertSame('invalid_args', $out['error']['code']);
    }

    public function test_cancel_requires_confirmation(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'cancel-membership', 'args' => [ 'membership_id' => $this->membership_post ] ]);
        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame('active', $this->api->membership($this->membership_post)->get_status());

        $out = $this->integration->handle_write([ 'operation' => 'cancel-membership', 'confirm' => true, 'args' => [ 'membership_id' => $this->membership_post ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('cancelled', $out['result']['membership']['status']);
    }

    public function test_unknown_membership_refused_without_side_effects(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'pause-membership', 'args' => [ 'membership_id' => 999999 ] ]);
        $this->assertSame('not_found', $out['error']['code']);
    }
}

class Fake_Wc_Membership
{
    public string $status;
    public string $end_date = '2027-01-01 00:00:00';

    public function __construct(private int $id, private int $user_id, private int $plan_id, string $status = 'active')
    {
        $this->status = $status;
    }

    public function get_id(): int { return $this->id; }
    public function get_user_id(): int { return $this->user_id; }
    public function get_plan_id(): int { return $this->plan_id; }
    public function get_status(): string { return $this->status; }
    public function get_start_date(): string { return '2026-01-01 00:00:00'; }
    public function get_end_date(): string { return $this->end_date; }
    public function pause(): void { $this->status = 'paused'; }
    public function activate(): void { $this->status = 'active'; }
    public function cancel(): void { $this->status = 'cancelled'; }
    public function set_end_date(string $date): void { $this->end_date = $date; }
}

class Fake_Wc_Membership_Rule
{
    public function get_content_type(): string { return 'post'; }
    public function get_content_type_name(): string { return 'post'; }
    public function get_object_ids(): array { return [ 12, 13 ]; }
}

class Fake_Wc_Membership_Plan
{
    public function get_id(): int { return 7; }
    public function get_name(): string { return 'Gold'; }
    public function get_slug(): string { return 'gold'; }
    public function get_access_method(): string { return 'purchase'; }
    public function get_access_length(): string { return '1 year'; }
    public function get_content_restriction_rules(): array { return [ new Fake_Wc_Membership_Rule() ]; }
    public function get_product_restriction_rules(): array { return []; }
    public function get_purchasing_discount_rules(): array { return []; }
}

class Fake_Wc_Memberships_Api
{
    /** @var Fake_Wc_Membership[] */
    private array $memberships;
    public array $created = [];

    public function __construct(int $membership_post)
    {
        $this->memberships = [
            new Fake_Wc_Membership($membership_post, 1, 7, 'active'),
            new Fake_Wc_Membership($membership_post + 1000, 2, 7, 'active'),
            new Fake_Wc_Membership($membership_post + 1001, 3, 7, 'paused'),
        ];
    }

    public function plans(): array { return [ new Fake_Wc_Membership_Plan() ]; }

    public function plan_members(int $plan_id, ?string $status): array
    {
        return array_values(array_filter($this->memberships, fn ($m) => $m->get_plan_id() === $plan_id && (null === $status || $m->get_status() === $status)));
    }

    public function user_memberships(int $user_id): array
    {
        return array_values(array_filter($this->memberships, fn ($m) => $m->get_user_id() === $user_id));
    }

    public function membership(int $id): ?object
    {
        foreach ($this->memberships as $m) {
            if ($m->get_id() === $id) {
                return $m;
            }
        }
        return null;
    }

    public function create(int $user_id, int $plan_id)
    {
        $this->created[] = [ 'user_id' => $user_id, 'plan_id' => $plan_id ];
        $m = new Fake_Wc_Membership(555, $user_id, $plan_id);
        $this->memberships[] = $m;
        return $m;
    }
}
