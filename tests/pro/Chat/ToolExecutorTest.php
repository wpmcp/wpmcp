<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Request_Log;
use WPMCP\Plugin;
use WPMCP\Pro\Chat\Approval_Gate;
use WPMCP\Pro\Chat\Chat_Identity;
use WPMCP\Pro\Chat\Tool_Executor;
use WPMCP\Pro\Chat\Tool_Inventory;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Operation_Context;
use WPMCP\Safety\Snapshot_Store;

/**
 * Acceptance criteria 1 and 2 of issue #73: a chat tool call runs through the
 * identical permission / governance / rate-limit / snapshot path as an MCP
 * call, under a scoped identity, and every call that changes the site needs
 * an explicit, server-verified, single-use approval.
 *
 * These drive the real registered abilities, not stubs, so a regression in
 * the governed path itself (not only in the chat) trips them.
 */
class ToolExecutorTest extends \WP_UnitTestCase
{
    private const SALT = 'tool_executor_test_salt';

    private Tool_Executor $executor;
    private Approval_Gate $gate;
    private int $admin_id;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Request_Log::OPTION);
        Identity_Store::delete(Chat_Identity::NAME);
        Operation_Context::reset();
        Rate_Limiter::set_clock_override(fn () => 1_700_073_000);

        $this->gate     = new Approval_Gate(self::SALT);
        $this->executor = new Tool_Executor(new Tool_Inventory(Plugin::instance()->registrar()), $this->gate);
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
    }

    protected function tearDown(): void
    {
        Rate_Limiter::set_clock_override(null);
        remove_all_filters('wpmcp_rate_limit');
        Governance::reset_for_tests();
        Identity_Store::delete(Chat_Identity::NAME);
        Gate::set_pro_for_tests(null);
        Operation_Context::reset();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_a_read_runs_without_approval_through_the_registered_ability(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Readable']);

        $outcome = $this->executor->execute($this->admin_id, 'wpmcp/get-post', ['post_id' => $post_id]);

        $this->assertSame(Tool_Executor::OK, $outcome['status'], print_r($outcome, true));
        $this->assertStringContainsString('Readable', wp_json_encode($outcome['result']));

        // The Registrar's execute wrapper logged it: this was the governed
        // path, not a direct handler call.
        $rows = array_values(array_filter(Request_Log::list(), fn ($r) => 'wpmcp/get-post' === $r['tool']));
        $this->assertCount(1, $rows);
    }

    public function test_a_mutating_call_without_a_token_is_parked_and_changes_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Before']);

        $outcome = $this->executor->execute($this->admin_id, 'wpmcp/update-post', ['post_id' => $post_id, 'title' => 'After']);

        $this->assertSame(Tool_Executor::APPROVAL_REQUIRED, $outcome['status']);
        $this->assertSame('Before', get_post($post_id)->post_title);
        $this->assertSame([], array_filter(Request_Log::list(), fn ($r) => 'wpmcp/update-post' === $r['tool']));
    }

    public function test_an_approved_mutation_runs_once_with_a_snapshot_and_audit_attribution(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Before']);
        $args    = ['post_id' => $post_id, 'title' => 'After'];
        $token   = $this->gate->issue_token($this->admin_id, 'wpmcp/update-post', $args);

        $outcome = $this->executor->execute($this->admin_id, 'wpmcp/update-post', $args, $token);

        $this->assertSame(Tool_Executor::OK, $outcome['status'], print_r($outcome, true));
        $this->assertSame('After', get_post($post_id)->post_title);

        // Snapshot-before-write: the same undo point an MCP client gets.
        $row = null;
        foreach (Request_Log::list() as $r) {
            if ('wpmcp/update-post' === $r['tool']) {
                $row = $r;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertNotSame('', $row['operation_id']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($row['operation_id']));

        // Governance audit attributes the decision to the chat identity.
        $audit = array_values(array_filter(
            Governance_Audit_Log::list(),
            fn ($e) => 'wpmcp/update-post' === $e['ability']
        ));
        $this->assertNotEmpty($audit);
        $this->assertSame(Chat_Identity::NAME, $audit[0]['identity']);
        $this->assertTrue($audit[0]['allowed']);

        // Replay: the same token cannot run the call a second time.
        $replay = $this->executor->execute($this->admin_id, 'wpmcp/update-post', $args, $token);
        $this->assertSame(Tool_Executor::ERROR, $replay['status']);
        $this->assertSame('invalid_approval', $replay['code']);
    }

    public function test_a_token_does_not_authorize_different_arguments_or_a_different_ability(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Before']);
        $token   = $this->gate->issue_token($this->admin_id, 'wpmcp/update-post', ['post_id' => $post_id, 'title' => 'Approved']);

        $mutated = $this->executor->execute($this->admin_id, 'wpmcp/update-post', ['post_id' => $post_id, 'title' => 'Injected'], $token);
        $this->assertSame('invalid_approval', $mutated['code']);

        $other_token = $this->gate->issue_token($this->admin_id, 'wpmcp/update-post', ['post_id' => $post_id]);
        $cross       = $this->executor->execute($this->admin_id, 'wpmcp/delete-post', ['post_id' => $post_id], $other_token);
        $this->assertSame('invalid_approval', $cross['code']);

        $this->assertSame('Before', get_post($post_id)->post_title);
        $this->assertSame('publish', get_post_status($post_id));
    }

    public function test_a_token_minted_for_another_user_is_refused(): void
    {
        $post_id  = self::factory()->post->create(['post_title' => 'Before']);
        $other    = self::factory()->user->create(['role' => 'administrator']);
        $args     = ['post_id' => $post_id, 'title' => 'After'];
        $token    = $this->gate->issue_token($other, 'wpmcp/update-post', $args);

        $outcome = $this->executor->execute($this->admin_id, 'wpmcp/update-post', $args, $token);

        $this->assertSame('invalid_approval', $outcome['code']);
        $this->assertSame('Before', get_post($post_id)->post_title);
    }

    public function test_the_executor_refuses_to_act_for_a_user_other_than_the_current_one(): void
    {
        $other   = self::factory()->user->create(['role' => 'administrator']);
        $outcome = $this->executor->execute($other, 'wpmcp/get-post', ['post_id' => 1]);

        $this->assertSame('user_mismatch', $outcome['code']);
    }

    public function test_a_governance_disabled_ability_is_refused_even_with_a_valid_token(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Before']);
        $args    = ['post_id' => $post_id, 'title' => 'After'];
        $token   = $this->gate->issue_token($this->admin_id, 'wpmcp/update-post', $args);

        Governance::set_ability_toggle('wpmcp/update-post', false);
        $outcome = $this->executor->execute($this->admin_id, 'wpmcp/update-post', $args, $token);

        $this->assertSame('tool_not_available', $outcome['code']);
        $this->assertSame('Before', get_post($post_id)->post_title);
    }

    public function test_the_chat_identity_scope_narrows_what_the_chat_can_run(): void
    {
        Identity_Store::create(Chat_Identity::NAME, ['operations' => ['read']]);
        $post_id = self::factory()->post->create(['post_title' => 'Before']);
        $args    = ['post_id' => $post_id, 'title' => 'After'];
        $token   = $this->gate->issue_token($this->admin_id, 'wpmcp/update-post', $args);

        $write = $this->executor->execute($this->admin_id, 'wpmcp/update-post', $args, $token);
        $read  = $this->executor->execute($this->admin_id, 'wpmcp/get-post', ['post_id' => $post_id]);

        $this->assertSame('tool_not_available', $write['code']);
        $this->assertSame(Tool_Executor::OK, $read['status']);
        $this->assertSame('Before', get_post($post_id)->post_title);
    }

    public function test_the_identity_does_not_leak_past_the_call(): void
    {
        $post_id = self::factory()->post->create();
        $this->executor->execute($this->admin_id, 'wpmcp/get-post', ['post_id' => $post_id]);

        $this->assertNull(\WPMCP\Identity\Identity_Context::current());
    }

    public function test_a_user_without_the_capability_cannot_run_the_tool(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $post_id = self::factory()->post->create();

        $outcome = $this->executor->execute($subscriber, 'wpmcp/get-post', ['post_id' => $post_id]);

        $this->assertSame('tool_not_available', $outcome['code']);
    }

    public function test_chat_calls_share_the_plugin_rate_limiter(): void
    {
        add_filter('wpmcp_rate_limit', fn () => 1);
        $post_id = self::factory()->post->create();

        $first  = $this->executor->execute($this->admin_id, 'wpmcp/get-post', ['post_id' => $post_id]);
        $second = $this->executor->execute($this->admin_id, 'wpmcp/get-post', ['post_id' => $post_id]);

        $this->assertSame(Tool_Executor::OK, $first['status']);
        $this->assertSame(Tool_Executor::ERROR, $second['status']);
        $this->assertSame('wpmcp_rate_limited', $second['code']);
    }

    public function test_approval_is_derived_from_the_ability_type_not_a_list(): void
    {
        $registrar = Plugin::instance()->registrar();
        $gated     = [];
        $open      = [];
        foreach ($registrar->all() as $ability) {
            if (Tool_Executor::requires_approval($ability)) {
                $gated[] = $ability->name;
            } else {
                $open[] = $ability->name;
            }
            if ('read' !== $ability->operation || $ability->destructive_hint) {
                $this->assertTrue(Tool_Executor::requires_approval($ability), $ability->name . ' changes the site but is not gated');
            }
        }
        $this->assertContains('wpmcp/delete-post', $gated);
        $this->assertContains('wpmcp/update-post', $gated);
        $this->assertContains('wpmcp/get-post', $open);
    }
}
