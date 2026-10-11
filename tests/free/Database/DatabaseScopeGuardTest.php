<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Database_Guard;

/**
 * Issue #526: the pure pieces of the multisite table-scope guard, which run
 * on the single-site harness too. sql_identifiers() is what the `query`
 * scope check scans, so it must surface every name the server could read
 * as a table, whatever the quoting, comment or escape rules in play.
 * table_in_site_scope() decides which tables belong to the current site.
 */
class DatabaseScopeGuardTest extends \WP_UnitTestCase
{
    private const GLOBALS = ['wp_users', 'wp_usermeta', 'wp_blogs', 'wp_blogmeta', 'wp_signups', 'wp_site', 'wp_sitemeta', 'wp_registration_log'];

    private function ids(string $sql): array
    {
        $ids = Database_Guard::sql_identifiers($sql);
        $this->assertIsArray($ids, $sql);
        return $ids;
    }

    public function test_identifiers_cover_joins_subqueries_and_quoting_forms(): void
    {
        $ids = $this->ids('SELECT a.x FROM wp_2_posts a JOIN `wp_SiteMeta` m ON 1 LEFT JOIN (SELECT * FROM db.`wp_3_options`) t ON 1, wp_blogs WHERE a.y IN (SELECT z FROM wp_site)');
        foreach (['wp_2_posts', 'wp_sitemeta', 'db', 'wp_3_options', 'wp_blogs', 'wp_site'] as $name) {
            $this->assertContains($name, $ids);
        }
    }

    public function test_identifiers_include_double_quoted_names_and_escaped_backticks(): void
    {
        $this->assertContains('wp_sitemeta', $this->ids('SELECT * FROM "wp_sitemeta"'));
        $this->assertContains('a`b', $this->ids('SELECT * FROM `a``b`'));
    }

    public function test_single_quoted_literals_are_not_identifiers(): void
    {
        $this->assertNotContains('wp_sitemeta', $this->ids("SELECT * FROM wp_2_posts WHERE post_content = 'wp_sitemeta'"));
    }

    public function test_identifiers_survive_either_backslash_escape_mode(): void
    {
        $this->assertContains('wp_sitemeta', $this->ids("SELECT 'a\\' , meta_value FROM wp_sitemeta -- '"));
        $this->assertContains('wp_sitemeta', $this->ids("SELECT 'it\\'s' FROM wp_sitemeta"));
    }

    public function test_double_dash_without_a_space_is_not_a_comment(): void
    {
        $this->assertContains('wp_sitemeta', $this->ids('SELECT 1--1, meta_value FROM wp_sitemeta'));
    }

    public function test_names_inside_comments_count_conservatively(): void
    {
        $this->assertContains('wp_sitemeta', $this->ids('SELECT 1 /*M! , (SELECT meta_value FROM wp_sitemeta) */'));
        $this->assertContains('wp_sitemeta', $this->ids("SELECT 1 # FROM wp_sitemeta\n"));
    }

    public function test_unterminated_sql_is_unparseable(): void
    {
        $this->assertNull(Database_Guard::sql_identifiers('SELECT * FROM `wp_sitemeta'));
        $this->assertNull(Database_Guard::sql_identifiers('SELECT * FROM "wp_sitemeta'));
        $this->assertNull(Database_Guard::sql_identifiers('SELECT 1 /* FROM wp_sitemeta'));
    }

    public function test_mariadb_executable_comments_are_refused(): void
    {
        $result = Database_Guard::is_read_only_sql('SELECT 1 /*M!, (SELECT 2) */');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('executable_comment', $result->get_error_code());

        $result = Database_Guard::is_read_only_sql('SELECT 1 /*m!100000 , 2 */');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_sub_site_scope_is_its_own_prefix_only(): void
    {
        $in = static fn (string $t): bool => Database_Guard::table_in_site_scope($t, 'wp_2_', 'wp_', self::GLOBALS);

        $this->assertTrue($in('wp_2_options'));
        $this->assertTrue($in('WP_2_POSTS'));
        $this->assertTrue($in('wp_2_wpmcp_snapshots'));
        $this->assertFalse($in('wp_options'));
        $this->assertFalse($in('wp_sitemeta'));
        $this->assertFalse($in('wp_users'));
        $this->assertFalse($in('wp_22_options'));
        $this->assertFalse($in('wp_3_options'));
        $this->assertFalse($in('other_table'));
    }

    public function test_main_site_scope_excludes_global_and_numbered_site_tables(): void
    {
        $in = static fn (string $t): bool => Database_Guard::table_in_site_scope($t, 'wp_', 'wp_', self::GLOBALS);

        $this->assertTrue($in('wp_options'));
        $this->assertTrue($in('wp_wpmcp_snapshots'));
        $this->assertTrue($in('wp_2fa_settings'));
        $this->assertFalse($in('wp_sitemeta'));
        $this->assertFalse($in('WP_USERS'));
        $this->assertFalse($in('wp_blogs'));
        $this->assertFalse($in('wp_2_options'));
        $this->assertFalse($in('wp_15_posts'));
        $this->assertFalse($in('custom_table'));
    }

    public function test_single_site_is_unrestricted(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site behavior.');
        }
        global $wpdb;

        $this->assertFalse(Database_Guard::scope_restricted());
        $this->assertTrue(Database_Guard::guard_query_scope('SHOW TABLES'));
        $this->assertTrue(Database_Guard::guard_query_scope('SELECT table_name FROM information_schema.TABLES'));
        $this->assertSame($wpdb->options, Database_Guard::scoped_table($wpdb->options));
        $this->assertTrue(Database_Guard::table_in_scope('any_table_at_all'));
        $this->assertFalse(Database_Guard::is_protected($wpdb->posts));
    }
}
