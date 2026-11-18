<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Request_Log;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Operation_Context;
use WPMCP\Safety\Snapshot_Store;

/**
 * Proves the request outcome log (issue #134) is wired into real ability
 * execution through Registrar's execute wrapper, not merely unit-tested in
 * isolation: reads, writes, WP_Error returns, thrown exceptions and throttled
 * calls each leave exactly one row on the globally-registered abilities.
 */
class RegistrarRequestLogTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // See WPMCP\Tests\Free\PluginAbilitiesTest: wp_abilities_api_init
        // fires lazily on first registry access, and the real abilities (with
        // their wrapped execute_callback) only exist once it has fired.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        Operation_Context::reset();
        // Own clock bucket, so per-client rate-limit counters from other tests
        // cannot throttle (and thus change the outcome of) these calls.
        Rate_Limiter::set_clock_override(fn() => 1_700_001_000);
    }

    protected function tearDown(): void
    {
        Rate_Limiter::set_clock_override(null);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_rate_limit_window');
        remove_all_filters(Request_Log::CAPTURE_OPTION);
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        Operation_Context::reset();
        parent::tearDown();
    }

    private function ability(string $name): \WP_Ability
    {
        return wp_get_abilities()[ $name ];
    }

    private function admin_user(): int
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        return $admin;
    }

    /** @return array<string, mixed>|null */
    private function row_for(string $tool): ?array
    {
        foreach (Request_Log::list() as $row) {
            if ($tool === $row['tool']) {
                return $row;
            }
        }
        return null;
    }

    public function test_a_successful_read_is_logged_with_client_and_duration(): void
    {
        $admin = $this->admin_user();
        $id    = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        $this->ability('wpmcp/get-page')->execute(['id' => $id]);

        $row = $this->row_for('wpmcp/get-page');

        $this->assertNotNull($row, 'Reads must be logged, not only writes.');
        $this->assertTrue($row['ok']);
        $this->assertSame('', $row['error_code']);
        $this->assertSame('user:' . $admin, $row['client']);
        $this->assertIsInt($row['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $row['duration_ms']);
        $this->assertSame('', $row['operation_id'], 'A read takes no snapshot, so it has no undo point.');
    }

    public function test_a_wp_error_return_is_logged_with_its_error_code(): void
    {
        $this->admin_user();

        $result = $this->ability('wpmcp/get-tool-schema')->execute(['name' => 'wpmcp/nope']);

        $this->assertInstanceOf(\WP_Error::class, $result);

        $row = $this->row_for('wpmcp/get-tool-schema');

        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertSame('wpmcp_get_tool_schema_unknown', $row['error_code']);
    }

    /**
     * A handler exception comes back as the WP_Error WordPress 7.1 core
     * builds for it, on every supported WordPress (issue #612): 6.9's
     * WP_Ability has no catch, so before this the exception left execute()
     * there and the REST run endpoint answered a plain refusal with a 500.
     * The WP_Error keeps the handler's message; the log keeps the real
     * exception class, which the WP_Error does not carry.
     */
    public function test_a_thrown_exception_is_logged_with_its_class_and_returned_as_a_wp_error(): void
    {
        $this->admin_user();

        $result = $this->ability('wpmcp/get-post')->execute(['post_id' => 99999999]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_callback_exception', $result->get_error_code());
        // An expected refusal carries only its own message (issue #631).
        $this->assertSame('Post not found', $result->get_error_message());

        $row = $this->row_for('wpmcp/get-post');

        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertSame('exception:InvalidArgumentException', $row['error_code']);
    }

    public function test_a_throttled_call_is_logged_as_a_rate_limited_error(): void
    {
        $this->admin_user();
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        $this->ability('wpmcp/get-page')->execute(['id' => $id]);
        $throttled = $this->ability('wpmcp/get-page')->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $throttled);

        $rows = array_values(array_filter(
            Request_Log::list(),
            fn(array $row) => 'wpmcp/get-page' === $row['tool']
        ));

        $this->assertCount(2, $rows, 'Both the executed and the throttled call must be recorded.');
        $this->assertSame('wpmcp_rate_limited', $rows[0]['error_code']);
        $this->assertFalse($rows[0]['ok']);
        $this->assertTrue($rows[1]['ok']);
    }

    public function test_a_write_row_carries_the_operation_id_of_its_snapshot(): void
    {
        $this->admin_user();
        $id = self::factory()->post->create(['post_title' => 'Before']);

        $result = $this->ability('wpmcp/update-post')->execute(['post_id' => $id, 'title' => 'After']);

        $row = $this->row_for('wpmcp/update-post');

        $this->assertNotNull($row);
        $this->assertTrue($row['ok']);
        $this->assertNotSame('', $row['operation_id']);
        $this->assertSame($result['operation_id'], $row['operation_id']);
        $this->assertNotNull(
            Snapshot_Store::get_by_operation($row['operation_id']),
            'The logged operation_id must resolve to a real undo point.'
        );
    }

    public function test_a_denied_permission_check_never_reaches_the_outcome_log(): void
    {
        // Denials are already covered by Governance_Audit_Log; this log is
        // about execution outcomes, and a denied call never executes.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $id     = self::factory()->post->create(['post_type' => 'page']);
        $result = $this->ability('wpmcp/get-page')->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
        $this->assertNull($this->row_for('wpmcp/get-page'));
    }

    public function test_arguments_are_omitted_by_default_and_redacted_when_captured(): void
    {
        $this->admin_user();
        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        $this->ability('wpmcp/get-page')->execute(['id' => $id]);

        $this->assertArrayNotHasKey('args', $this->row_for('wpmcp/get-page'));

        Request_Log::clear();
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');

        $this->ability('wpmcp/get-page')->execute(['id' => $id]);

        $row = $this->row_for('wpmcp/get-page');

        $this->assertArrayHasKey('args', $row);
        $this->assertSame($id, $row['args']['id']);
    }
}
