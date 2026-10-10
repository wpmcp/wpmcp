<?php

namespace WPMCP\Tests\Free\Privacy;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Request_Log;
use WPMCP\Memory\Memory_Store;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Search\Search_Index_Store;

/**
 * Issue #478: WP MCP's own stores take part in WordPress's personal data
 * export and erasure. Every test here goes through core's real admin-ajax
 * handlers, one page at a time, with core's and every integration's own
 * exporters and erasers running alongside, as Tools > Export Personal Data
 * and Erase Personal Data do.
 *
 * @group ajax
 */
class PersonalDataRequestTest extends \WP_Ajax_UnitTestCase
{
    use Privacy_Request_Runner;

    private const EMAIL = 'Ada.Lovelace@example.test';
    private const IP    = '203.0.113.77';

    private int $ada;
    private int $admin;
    private int $post;
    private string $comment_operation;
    private string $user_operation;
    private string $user_session = 'ada-profile-session';
    private string $unrelated_operation;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Search_Index_Store::install();
        Memory_Store::ensure_post_type();
        add_filter('pre_http_request', [$this, 'stub_version_check'], 10, 3);
        update_option(Request_Log::CAPTURE_OPTION, true);

        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->ada   = self::factory()->user->create([
            'role'         => 'subscriber',
            'user_email'   => strtolower(self::EMAIL),
            'first_name'   => 'Ada',
            'last_name'    => 'Lovelace',
            'display_name' => 'Ada Lovelace',
        ]);
        $this->post = self::factory()->post->create([ 'post_title' => 'Analytical engines', 'post_content' => 'V0' ]);

        wp_set_current_user($this->admin);

        // A guest comment from Ada, deleted through an undoable tool: the
        // snapshot is now the only copy of her email, name and IP.
        $comment = self::factory()->comment->create([
            'comment_post_ID'      => $this->post,
            'comment_author'       => 'Ada Lovelace',
            'comment_author_email' => self::EMAIL,
            'comment_author_IP'    => self::IP,
            'comment_agent'        => 'Mozilla/5.0 (Ada)',
            'comment_content'      => 'Note G computes Bernoulli numbers.',
        ]);
        $this->comment_operation = Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $comment,
                'session_id'  => 'comment-cleanup',
                'tool_name'   => 'delete-comment',
                'args'        => [ 'id' => $comment ],
            ],
            static fn () => wp_delete_comment($comment, true)
        )['operation_id'];

        // A profile edit of Ada's account: the snapshot holds her profile.
        $ada                  = $this->ada;
        $this->user_operation = Safe_Mutation::run(
            [
                'object_type' => 'user',
                'object_id'   => $ada,
                'session_id'  => $this->user_session,
                'tool_name'   => 'update-user',
                'args'        => [ 'id' => $ada ],
            ],
            static fn () => wp_update_user([ 'ID' => $ada, 'description' => 'Countess of Lovelace' ])
        )['operation_id'];

        // A change that has nothing to do with Ada.
        $post                      = $this->post;
        $this->unrelated_operation = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post,
                'session_id'  => 'unrelated',
                'tool_name'   => 'update-post',
                'args'        => [ 'id' => $post ],
            ],
            static fn () => wp_update_post([ 'ID' => $post, 'post_content' => 'V1' ])
        )['operation_id'];

        // Activity logs: a call Ada made, and an address refusal that names
        // only her IP (an identity, not her account, made that call).
        wp_set_current_user($this->ada);
        Request_Log::record([
            'tool'          => 'update-user',
            'client'        => 'user:' . $this->ada,
            'ok'            => false,
            'error_code'    => 'invalid_email',
            'error_message' => 'The address ' . self::EMAIL . ' is taken.',
            'args'          => [ 'email' => self::EMAIL ],
        ]);
        wp_set_current_user($this->admin);
        Request_Log::record([ 'tool' => 'get-site-context', 'client' => 'claude', 'ok' => true ]);
        Governance_Audit_Log::record('wpmcp/update-user', 'user:' . $this->ada, true, 'client:' . self::IP);
        Governance_Audit_Log::record('wpmcp/ip-allowlist', 'agent-one', false, 'client:' . self::IP);
        Governance_Audit_Log::record('wpmcp/get-site-context', 'none', true);

        // Agent memory that mentions her, and one that does not.
        $this->memory_with_ada = (int) Memory_Store::propose([
            'title' => 'Refund contact',
            'text'  => 'Refunds go to ' . self::EMAIL . ' before the 5th.',
            'kind'  => 'fact',
        ]);
        $this->memory_without_ada = (int) Memory_Store::propose([
            'title' => 'House style',
            'text'  => 'Use sentence case for headings.',
            'kind'  => 'convention',
        ]);

        // The search index copy of a page that prints her address.
        Search_Index_Store::replace_object('post', $this->post, [
            [ 'content' => 'Contact ' . self::EMAIL . ' for the engine plans.', 'source' => 'content' ],
            [ 'content' => 'Analytical engines', 'source' => 'title' ],
        ]);
    }

    private int $memory_with_ada;
    private int $memory_without_ada;

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'stub_version_check'], 10);
        delete_option(Request_Log::CAPTURE_OPTION);
        parent::tearDown();
    }

    /** Same stub as RestoreControllerAjaxTest: the ajax cycle fires core's update checks. */
    public function stub_version_check($preempt, $parsed_args, $url)
    {
        if (false === strpos($url, 'api.wordpress.org') || false === strpos($url, '-check')) {
            return $preempt;
        }
        return [
            'headers'  => [],
            'body'     => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private const OUR_KEYS = [ 'wpmcp-snapshots', 'wpmcp-activity-logs', 'wpmcp-memory', 'wpmcp-search-index' ];

    public function test_wp_mcp_is_listed_with_core_privacy_tools_after_integrations(): void
    {
        $integration = static function (array $list): array {
            $list['an-integration'] = [ 'exporter_friendly_name' => 'Integration', 'eraser_friendly_name' => 'Integration', 'callback' => '__return_empty_array' ];
            return $list;
        };
        add_filter('wp_privacy_personal_data_exporters', $integration, 10);
        add_filter('wp_privacy_personal_data_erasers', $integration, 10);

        foreach ([ 'wp_privacy_personal_data_exporters' => 'exporter_friendly_name', 'wp_privacy_personal_data_erasers' => 'eraser_friendly_name' ] as $filter => $name_field) {
            $list = apply_filters($filter, []);
            $keys = array_keys($list);
            foreach (self::OUR_KEYS as $key) {
                $this->assertArrayHasKey($key, $list, "$filter must register $key");
                $this->assertNotSame('', (string) ($list[ $key ][ $name_field ] ?? ''), "$key needs a friendly name");
                $this->assertIsCallable($list[ $key ]['callback']);
                // Runs after an integration's own eraser, so an integration
                // erasure cannot leave its data behind in WP MCP's copies.
                $this->assertGreaterThan(array_search('an-integration', $keys, true), array_search($key, $keys, true), "$key must run after integrations");
            }
        }

        remove_filter('wp_privacy_personal_data_exporters', $integration, 10);
        remove_filter('wp_privacy_personal_data_erasers', $integration, 10);
    }

    public function test_an_export_lists_the_person_from_every_wp_mcp_store(): void
    {
        $export = $this->run_core_export(self::EMAIL);

        foreach (self::OUR_KEYS as $key) {
            $this->assertArrayHasKey($key, $export);
            $this->assertNotEmpty($export[ $key ], "$key exported nothing for the person");
            $this->assertStringContainsStringIgnoringCase(self::EMAIL, self::export_text($export[ $key ]), "$key export is missing the person's records");
        }

        $snapshots = self::export_text($export['wpmcp-snapshots']);
        $this->assertStringContainsString($this->comment_operation, $snapshots);
        $this->assertStringContainsString($this->user_operation, $snapshots);
        $this->assertStringContainsString(self::IP, $snapshots);
        $this->assertStringContainsString('Note G computes Bernoulli numbers.', $snapshots);
        $this->assertStringNotContainsString($this->unrelated_operation, $snapshots, 'A snapshot with nothing of hers is not exported');

        $logs = self::export_text($export['wpmcp-activity-logs']);
        $this->assertStringContainsString('update-user', $logs);

        $memory = self::export_text($export['wpmcp-memory']);
        $this->assertStringContainsString('Refund contact', $memory);
        $this->assertStringNotContainsString('House style', $memory);
    }

    public function test_after_an_erase_no_wp_mcp_store_holds_her_email_name_or_ip(): void
    {
        $responses = $this->run_core_erasure(self::EMAIL);

        foreach (self::OUR_KEYS as $key) {
            $this->assertArrayHasKey($key, $responses);
            $removed = array_filter($responses[ $key ], static fn (array $page): bool => ! empty($page['items_removed']));
            $this->assertNotEmpty($removed, "$key reported nothing removed");
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT operation_id, before_blob FROM %i', Snapshot_Store::table_name()), ARRAY_A);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $json = (string) wp_json_encode(Snapshot::unserialize($row['before_blob']));
            foreach ([ self::EMAIL, 'Lovelace', self::IP, 'Bernoulli' ] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $json, "snapshot {$row['operation_id']} still holds $needle");
            }
        }

        foreach ([ Request_Log::OPTION, Governance_Audit_Log::OPTION ] as $option) {
            $json = (string) wp_json_encode(get_option($option));
            $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, $json, "$option still holds the email");
            $this->assertStringNotContainsString(self::IP, $json, "$option still holds the IP");
            $this->assertStringNotContainsString('user:' . $this->ada, $json, "$option still names her account");
        }
        foreach ((array) get_option(Request_Log::OPTION) as $row) {
            $this->assertNotSame($this->ada, (int) ($row['user_id'] ?? 0), 'The request log still links a row to her account');
        }
        $this->assertCount(2, array_filter((array) get_option(Request_Log::OPTION)), 'Log rows are anonymized, not dropped');

        $this->assertNull(get_post($this->memory_with_ada), 'The memory entry about her is deleted');
        $this->assertNotNull(get_post($this->memory_without_ada), 'Unrelated memory is kept');

        $matches = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE content LIKE %s', Search_Index_Store::table_name(), '%' . $wpdb->esc_like(self::EMAIL) . '%'));
        $this->assertSame(0, $matches, 'The search index still holds her address');
        $kept = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', Search_Index_Store::table_name()));
        $this->assertSame(1, $kept, 'Index rows that do not hold her data are kept');
    }

    public function test_a_redacted_snapshot_refuses_rollback_and_says_why(): void
    {
        $this->run_core_erasure(self::EMAIL);
        wp_set_current_user($this->admin);

        $this->assertFalse(Rollback_Service::restore_operation($this->comment_operation));
        $warnings = implode(' ', Rollback_Service::take_warnings());
        $this->assertStringContainsString('personal data erasure', $warnings, 'The refusal must name the reason');
        $this->assertSame(0, (int) get_comments([ 'post_id' => $this->post, 'count' => true ]), 'The comment is not brought back');

        try {
            Rollback_Service::restore_session($this->user_session);
            $this->fail('A session holding a redacted snapshot must refuse to roll back.');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('personal data erasure', $e->getMessage());
        }
        $this->assertSame('Countess of Lovelace', get_userdata($this->ada)->description, 'Nothing in the session was restored');

        // A snapshot that held nothing of hers still rolls back.
        $this->assertTrue(Rollback_Service::restore_operation($this->unrelated_operation));
        $this->assertSame('V0', get_post($this->post)->post_content);
    }

    public function test_a_redacted_snapshot_cannot_be_applied_directly_either(): void
    {
        $this->run_core_erasure(self::EMAIL);
        $row = Snapshot_Store::get_by_operation($this->comment_operation);

        $this->expectException(Mutation_Failed::class);
        $this->expectExceptionMessage('personal data erasure');
        Rollback_Service::apply_snapshot($row['snapshot']);
    }

    public function test_a_second_erase_finds_nothing_left(): void
    {
        $this->run_core_erasure(self::EMAIL);
        $again = $this->run_core_erasure(self::EMAIL);

        foreach (self::OUR_KEYS as $key) {
            $this->assertArrayHasKey($key, $again);
            foreach ($again[ $key ] as $page) {
                $this->assertFalse($page['items_removed'], "$key removed something on a second pass");
            }
        }
    }

    public function test_export_and_erase_page_through_a_large_snapshot_table(): void
    {
        $filler = static fn (int $i): array => [
            'object_type' => 'db_rows',
            'object_id'   => 'wp_filler',
            'data'        => [ 'rows' => [ [ 'id' => $i, 'label' => 'row ' . $i ] ] ],
        ];
        $mine   = static fn (int $i): array => [
            'object_type' => 'db_rows',
            'object_id'   => 'wp_signups',
            'data'        => [ 'rows' => [ [ 'id' => $i, 'contact' => self::EMAIL, 'full_name' => 'Ada Lovelace', 'ip' => self::IP ] ] ],
        ];

        $ids = [];
        for ($i = 1; $i <= 260; $i++) {
            $snapshot = in_array($i, [ 1, 131, 260 ], true) ? $mine($i) : $filler($i);
            $op       = wp_generate_uuid4();
            Snapshot_Store::save($op, 'bulk', $snapshot, 'update-rows', str_repeat('0', 64));
            if (in_array($i, [ 1, 131, 260 ], true)) {
                $ids[] = $op;
            }
        }

        $export = $this->run_core_export(self::EMAIL);
        $this->assertGreaterThan(2, $this->pages_requested['wpmcp-snapshots'], 'The snapshot exporter must page, not read the table in one request');
        $text = self::export_text($export['wpmcp-snapshots']);
        foreach ($ids as $op) {
            $this->assertStringContainsString($op, $text, "snapshot $op was skipped by the export");
        }

        $this->run_core_erasure(self::EMAIL);
        $this->assertGreaterThan(2, $this->pages_requested['wpmcp-snapshots'], 'The snapshot eraser must page, not rewrite the table in one request');
        foreach ($ids as $op) {
            $json = (string) wp_json_encode(Snapshot_Store::get_by_operation($op)['snapshot']);
            $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, $json, "snapshot $op was skipped by the erasure");
            $this->assertStringNotContainsString('Lovelace', $json);
            $this->assertStringNotContainsString(self::IP, $json);
        }
        $untouched = Snapshot_Store::get_by_operation($this->unrelated_operation)['snapshot'];
        $this->assertArrayNotHasKey('privacy_erased', $untouched);
    }
}
