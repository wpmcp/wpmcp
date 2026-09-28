<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Request_Log;

/**
 * Issue #303: the request log filters by date, user, tool and outcome, and
 * exports CSV with tokens and secret arguments redacted.
 */
class RequestLogFilterExportTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
    }

    protected function tearDown(): void
    {
        Request_Log::set_clock_for_tests(null);
        remove_all_filters(Request_Log::CAPTURE_OPTION);
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function seed(): void
    {
        $alice = self::factory()->user->create();
        $bob   = self::factory()->user->create();
        wp_set_current_user($alice);
        Request_Log::set_clock_for_tests(strtotime('2026-01-10 12:00:00 UTC'));
        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'client' => 'user:' . $alice, 'ok' => true ]);
        wp_set_current_user($bob);
        Request_Log::set_clock_for_tests(strtotime('2026-01-11 08:00:00 UTC'));
        Request_Log::record([ 'tool' => 'wpmcp/update-post', 'client' => 'user:' . $bob, 'ok' => false, 'error_code' => 'nope' ]);
        wp_set_current_user(0);
        Request_Log::set_clock_for_tests(strtotime('2026-01-12 23:59:00 UTC'));
        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'client' => 'ip:10.0.0.1', 'ok' => true ]);
        Request_Log::set_clock_for_tests(null);
    }

    private function tools(array $rows): array
    {
        return array_map(static fn ($r) => $r['tool'] . '@' . gmdate('m-d', $r['timestamp']), $rows);
    }

    public function test_record_stores_the_current_user(): void
    {
        $id = self::factory()->user->create();
        wp_set_current_user($id);
        Request_Log::record([ 'tool' => 't', 'ok' => true ]);

        $this->assertSame($id, Request_Log::list()[0]['user_id']);
    }

    public function test_query_without_filters_is_newest_first(): void
    {
        $this->seed();

        $this->assertSame([ 'wpmcp/get-page@01-12', 'wpmcp/update-post@01-11', 'wpmcp/get-page@01-10' ], $this->tools(Request_Log::query([])));
    }

    public function test_query_filters_by_tool_outcome_and_date(): void
    {
        $this->seed();

        $this->assertSame([ 'wpmcp/update-post@01-11' ], $this->tools(Request_Log::query([ 'tool' => 'UPDATE' ])));
        $this->assertSame([ 'wpmcp/update-post@01-11' ], $this->tools(Request_Log::query([ 'outcome' => 'error' ])));
        $this->assertCount(2, Request_Log::query([ 'outcome' => 'ok' ]));
        $this->assertSame([ 'wpmcp/get-page@01-12', 'wpmcp/update-post@01-11' ], $this->tools(Request_Log::query([ 'date_from' => '2026-01-11' ])));
        $this->assertSame([ 'wpmcp/update-post@01-11', 'wpmcp/get-page@01-10' ], $this->tools(Request_Log::query([ 'date_to' => '2026-01-11' ])));
        $this->assertSame([ 'wpmcp/get-page@01-12' ], $this->tools(Request_Log::query([ 'date_from' => '2026-01-12', 'date_to' => '2026-01-12' ])), 'date_to includes its whole day');
        $this->assertCount(1, Request_Log::query([ 'tool' => 'get-page', 'outcome' => 'ok', 'date_to' => '2026-01-10' ]));
        $this->assertCount(1, Request_Log::query([], 1));
    }

    public function test_query_filters_by_user_including_rows_recorded_before_user_ids(): void
    {
        $this->seed();
        $rows = Request_Log::list();
        $bob  = (int) $rows[1]['user_id'];
        // A row written before user_id was recorded still matches by client.
        update_option(Request_Log::OPTION, [ [ 'timestamp' => 1, 'tool' => 'legacy', 'client' => 'user:' . $bob, 'ok' => true ] ], false);
        $legacy = Request_Log::query([ 'user_id' => $bob ]);
        $this->assertSame('legacy', $legacy[0]['tool']);

        delete_option(Request_Log::OPTION);
        $this->seed();
        $alice = (int) Request_Log::list()[2]['user_id'];
        $this->assertSame([ 'wpmcp/get-page@01-10' ], $this->tools(Request_Log::query([ 'user_id' => $alice ])));
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $this->seed();

        $this->assertCount(3, Request_Log::query([ 'date_from' => 'yesterday', 'outcome' => 'maybe', 'user_id' => 'x' ]));
    }

    public function test_schema_marked_secret_arguments_are_redacted_when_captured(): void
    {
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');
        $schema = [ 'type' => 'object', 'properties' => [
            'license'  => [ 'type' => 'string', 'writeOnly' => true ],
            'answer'   => [ 'type' => 'string', 'format' => 'password' ],
            'title'    => [ 'type' => 'string' ],
        ] ];
        $this->assertSame([ 'license', 'answer' ], Request_Log::secret_fields($schema));
        $this->assertSame([], Request_Log::secret_fields([ 'type' => 'object' ]));

        Request_Log::record([ 'tool' => 't', 'ok' => true, 'args' => [ 'license' => 'L-1', 'answer' => '42', 'title' => 'Hi' ], 'secret_args' => [ 'license', 'answer' ] ]);

        $this->assertSame([ 'license' => Request_Log::REDACTED, 'answer' => Request_Log::REDACTED, 'title' => 'Hi' ], Request_Log::list()[0]['args']);
    }

    public function test_csv_has_a_header_and_one_line_per_row(): void
    {
        $this->seed();
        $csv   = Request_Log::to_csv(Request_Log::query([]));
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertSame('time_utc,tool,user_id,client,outcome,error_code,error_message,duration_ms,operation_id,args', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertStringStartsWith('2026-01-11 08:00:00,wpmcp/update-post,', $lines[2]);
        $this->assertStringContainsString(',error,nope,', $lines[2]);
        $this->assertStringContainsString(',ok,', $lines[1]);
    }

    public function test_csv_redacts_secret_keys_and_token_values(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJlLXZhbHVl';
        update_option(Request_Log::OPTION, [ [
            'timestamp'     => 1700000000,
            'tool'          => 'wpmcp/x',
            'client'        => 'user:1',
            'user_id'       => 1,
            'ok'            => false,
            'error_code'    => 'bad',
            'error_message' => 'Auth failed for Bearer abc.DEF-123_xyz and ' . $jwt,
            'duration_ms'   => 5,
            'operation_id'  => '',
            'args'          => [
                'api_key' => 'raw-key-stored-by-an-older-version',
                'nested'  => [ 'header' => 'Bearer s3cr3tvalue', 'app' => 'abcd EFGH ijkl MNOP qrst UVWX' ],
                'hash'    => 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0',
                'id'      => '123e4567-e89b-12d3-a456-426614174000',
                'title'   => 'Hello world',
            ],
        ] ], false);

        $csv = Request_Log::to_csv(Request_Log::query([]));

        foreach ([ 'raw-key-stored', 's3cr3tvalue', 'abc.DEF-123_xyz', $jwt, 'abcd EFGH', 'a1b2c3d4e5f6a7b8c9d0' ] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
        $this->assertStringContainsString('Hello world', $csv);
        $this->assertStringContainsString('123e4567-e89b-12d3-a456-426614174000', $csv, 'A UUID is an identifier, not a token');
        $this->assertStringContainsString('Bearer [redacted]', $csv);
    }

    public function test_csv_neutralizes_spreadsheet_formulas_and_quotes_fields(): void
    {
        update_option(Request_Log::OPTION, [ [ 'timestamp' => 1, 'tool' => '=HYPERLINK("x")', 'client' => '@evil', 'ok' => true ] ], false);

        $csv = Request_Log::to_csv(Request_Log::query([]));

        $this->assertStringContainsString('"\'=HYPERLINK(""x"")"', $csv);
        $this->assertStringContainsString("'@evil", $csv);
    }
}
