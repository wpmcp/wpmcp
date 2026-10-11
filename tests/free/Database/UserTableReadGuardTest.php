<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Database\Query;

/**
 * Read-path guard for the users/usermeta tables (issue #129): the flexible
 * SQL tool must refuse to read credential/session data (user_pass,
 * user_activation_key, usermeta session_tokens) by default, an explicit
 * filter opts in, and opted-in reads still get secret values masked unless
 * masking is explicitly disabled too.
 */
class UserTableReadGuardTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The users tables are network-wide on multisite, so only a network
        // operator may read them through query at all (issue #526).
        if (is_multisite()) {
            $super = (int) self::factory()->user->create();
            grant_super_admin($super);
            wp_set_current_user($super);
        }
    }

    protected function tearDown(): void
    {
        Database_Guard::set_no_backslash_escapes_override(null);
        remove_all_filters('wpmcp_db_allow_user_table_reads');
        remove_all_filters('wpmcp_db_mask_user_secrets');
        remove_all_filters('wpmcp_db_masked_meta_keys');
        parent::tearDown();
    }

    private function guard_code(string $sql): string
    {
        $result = Database_Guard::guard_user_table_read($sql);
        return ($result instanceof \WP_Error) ? $result->get_error_code() : 'OK';
    }

    private function allow_user_table_reads(): void
    {
        add_filter('wpmcp_db_allow_user_table_reads', '__return_true');
    }

    public function test_blocks_users_table_read_by_default(): void
    {
        global $wpdb;
        $this->assertSame(
            'users_table_read_blocked',
            $this->guard_code("SELECT user_pass FROM {$wpdb->users}")
        );
    }

    public function test_blocks_usermeta_table_read_by_default(): void
    {
        global $wpdb;
        $this->assertSame(
            'users_table_read_blocked',
            $this->guard_code("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'x'")
        );
    }

    public function test_query_tool_refuses_users_table_read_by_default(): void
    {
        global $wpdb;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/users\/usermeta tables are blocked/');
        (new Query())->handle(['sql' => "SELECT user_pass FROM {$wpdb->users}"]);
    }

    public function test_blocks_backtick_quoted_user_table(): void
    {
        global $wpdb;
        $this->assertSame(
            'users_table_read_blocked',
            $this->guard_code("SELECT user_pass FROM `{$wpdb->users}`")
        );
    }

    public function test_blocks_joined_and_aliased_references(): void
    {
        global $wpdb;
        $this->assertSame(
            'users_table_read_blocked',
            $this->guard_code(
                "SELECT u.user_login FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID = p.post_author"
            )
        );
    }

    public function test_blocks_under_both_no_backslash_escapes_modes(): void
    {
        global $wpdb;
        foreach ([true, false] as $mode) {
            Database_Guard::set_no_backslash_escapes_override($mode);
            $this->assertSame(
                'users_table_read_blocked',
                $this->guard_code("SELECT * FROM {$wpdb->users}"),
                'mode: ' . var_export($mode, true)
            );
        }
        Database_Guard::set_no_backslash_escapes_override(null);
    }

    public function test_double_dash_arithmetic_cannot_hide_a_user_table_read(): void
    {
        global $wpdb;
        // "1--1" is 1 minus -1 to MySQL and MariaDB: "--" only starts a
        // comment when whitespace follows, so the rest of the line is live.
        $sql = "SELECT 1--1, user_pass FROM {$wpdb->users} LIMIT 1";

        $this->assertTrue(Database_Guard::sql_mentions_user_tables($sql));
        $this->assertSame('users_table_read_blocked', $this->guard_code($sql));
    }

    public function test_lookalike_tables_do_not_match(): void
    {
        global $wpdb;
        $this->assertFalse(Database_Guard::sql_mentions_user_tables("SELECT * FROM {$wpdb->users}_backup"));
        $this->assertFalse(Database_Guard::sql_mentions_user_tables("SELECT * FROM backup_{$wpdb->users}"));
        $this->assertFalse(Database_Guard::sql_mentions_user_tables("SELECT * FROM {$wpdb->usermeta}2"));
    }

    public function test_literal_mentions_are_not_false_positives(): void
    {
        global $wpdb;
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%{$wpdb->users}%'";

        foreach ([true, false] as $mode) {
            Database_Guard::set_no_backslash_escapes_override($mode);
            $this->assertFalse(
                Database_Guard::sql_mentions_user_tables($sql),
                'mode: ' . var_export($mode, true)
            );
            $this->assertSame('OK', $this->guard_code($sql), 'mode: ' . var_export($mode, true));
        }
        Database_Guard::set_no_backslash_escapes_override(null);

        // The full tool path runs the query for real: no exception, no rows.
        $result = (new Query())->handle(['sql' => $sql]);
        $this->assertSame(0, $result['row_count']);
    }

    public function test_guard_returns_false_for_non_user_table_reads(): void
    {
        global $wpdb;
        $this->assertFalse(Database_Guard::guard_user_table_read("SELECT * FROM {$wpdb->options}"));
    }

    public function test_opt_in_allows_read_and_masks_user_secrets(): void
    {
        global $wpdb;
        $this->allow_user_table_reads();

        $user_id = self::factory()->user->create(['user_login' => 'wpmcp_read_guard_user']);

        $result = (new Query())->handle([
            'sql' => $wpdb->prepare(
                "SELECT ID, user_login, user_pass, user_activation_key FROM {$wpdb->users} WHERE ID = %d",
                $user_id
            ),
        ]);

        $this->assertSame(1, $result['row_count']);
        $row = $result['rows'][0];
        $this->assertSame('wpmcp_read_guard_user', $row['user_login']);
        $this->assertSame(Database_Guard::SECRET_MASK, $row['user_pass']);
        $this->assertSame(Database_Guard::SECRET_MASK, $row['user_activation_key']);
    }

    public function test_opt_in_masks_session_tokens_meta_but_not_other_meta(): void
    {
        global $wpdb;
        $this->allow_user_table_reads();

        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'session_tokens', ['abc123' => ['expiration' => time() + 100]]);
        update_user_meta($user_id, 'first_name', 'Plain');

        $result = (new Query())->handle([
            'sql' => $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta}"
                . " WHERE user_id = %d AND meta_key IN ('session_tokens', 'first_name')",
                $user_id
            ),
        ]);

        $by_key = array_column($result['rows'], 'meta_value', 'meta_key');
        $this->assertSame(Database_Guard::SECRET_MASK, $by_key['session_tokens']);
        $this->assertSame('Plain', $by_key['first_name']);
    }

    public function test_masking_can_be_disabled_explicitly(): void
    {
        global $wpdb;
        $this->allow_user_table_reads();
        add_filter('wpmcp_db_mask_user_secrets', '__return_false');

        $user_id = self::factory()->user->create();

        $result = (new Query())->handle([
            'sql' => $wpdb->prepare(
                "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d",
                $user_id
            ),
        ]);

        $this->assertNotSame(Database_Guard::SECRET_MASK, $result['rows'][0]['user_pass']);
        $this->assertNotSame('', $result['rows'][0]['user_pass']);
    }

    public function test_mask_user_secrets_targets_only_secret_keys(): void
    {
        $rows = [
            ['ID' => '1', 'user_login' => 'admin', 'user_pass' => '$hash$', 'user_activation_key' => 'key'],
            ['meta_key' => 'session_tokens', 'meta_value' => 'serialized-tokens'],
            ['meta_key' => 'SESSION_TOKENS', 'meta_value' => 'case-insensitive'],
            ['meta_key' => 'first_name', 'meta_value' => 'Plain'],
            ['option_name' => 'siteurl', 'option_value' => 'https://example.test'],
        ];

        $masked = Database_Guard::mask_user_secrets($rows);

        $this->assertSame('admin', $masked[0]['user_login']);
        $this->assertSame(Database_Guard::SECRET_MASK, $masked[0]['user_pass']);
        $this->assertSame(Database_Guard::SECRET_MASK, $masked[0]['user_activation_key']);
        $this->assertSame(Database_Guard::SECRET_MASK, $masked[1]['meta_value']);
        $this->assertSame(Database_Guard::SECRET_MASK, $masked[2]['meta_value']);
        $this->assertSame('Plain', $masked[3]['meta_value']);
        $this->assertSame('https://example.test', $masked[4]['option_value']);
    }

    public function test_masked_meta_keys_filter_extends_the_secret_list(): void
    {
        add_filter('wpmcp_db_masked_meta_keys', function (array $keys) {
            $keys[] = 'private_api_key';
            return $keys;
        });

        $masked = Database_Guard::mask_user_secrets([
            ['meta_key' => 'private_api_key', 'meta_value' => 'sk-live-123'],
        ]);

        $this->assertSame(Database_Guard::SECRET_MASK, $masked[0]['meta_value']);
    }

    public function test_normalize_sql_preserves_backtick_identifier_contents_when_asked(): void
    {
        $preserved = Database_Guard::normalize_sql('SELECT * FROM `wp_users`', false, true);
        $this->assertStringContainsString('wp_users', $preserved);

        // Default keyword-scanning behavior is unchanged: identifiers blank.
        $blanked = Database_Guard::normalize_sql('SELECT * FROM `wp_users`', false);
        $this->assertStringNotContainsString('wp_users', $blanked);
    }
}
