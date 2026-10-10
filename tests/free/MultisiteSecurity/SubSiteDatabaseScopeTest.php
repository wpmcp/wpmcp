<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Describe_Table;
use WPMCP\Tools\Database\Insert_Row;
use WPMCP\Tools\Database\List_Tables;
use WPMCP\Tools\Database\Query;
use WPMCP\Tools\Database\Update_Rows;

/**
 * Issue #526: on multisite the database tools stay inside the current site
 * for a caller without manage_network_options. A sub-site administrator
 * (manage_options on its own blog, not a super admin) must not read or
 * write network tables (sitemeta, site, blogs, users...), the main site's
 * tables or another sub-site's tables, through a `table` argument or any
 * table reference inside `query` SQL. Super admins keep full reach.
 *
 * Runs under WP_MULTISITE=1 and skips on a single-site harness, where the
 * DatabaseScopeGuardTest pure tests and the existing Database suite cover
 * the unchanged single-site behavior.
 */
class SubSiteDatabaseScopeTest extends \WP_UnitTestCase
{
    private int $blog  = 0;
    private int $other = 0;
    private int $sub_admin = 0;
    private int $super = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        $this->blog      = (int) self::factory()->blog->create();
        $this->other     = (int) self::factory()->blog->create();
        $this->sub_admin = (int) self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');
        $this->super = (int) self::factory()->user->create(['role' => 'subscriber']);
        grant_super_admin($this->super);

        switch_to_blog($this->blog);
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('manage_options'));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_db_writes');
        if ($this->super > 0) {
            revoke_super_admin($this->super);
        }
        if (is_multisite()) {
            restore_current_blog();
        }
        parent::tearDown();
    }

    /**
     * Foreign tables with a key column and a value column that really exist
     * on each, so a refusal can only come from the scope guard, never from
     * a SQL error on a wrong column.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> label => [table, key column, value column]
     */
    private function foreign_tables(): array
    {
        global $wpdb;
        return [
            'sitemeta'           => [$wpdb->sitemeta, 'meta_key', 'meta_value'],
            'main site options'  => [$wpdb->base_prefix . 'options', 'option_name', 'option_value'],
            'other site options' => [$wpdb->get_blog_prefix($this->other) . 'options', 'option_name', 'option_value'],
            'blogs'              => [$wpdb->blogs, 'domain', 'path'],
        ];
    }

    /**
     * Run $call and return the refusal message, or null when it was allowed.
     * A refusal that is only a SQL error does not count: the scope guard's
     * messages all name "this site" (an executable comment is refused
     * earlier by the read-only guard).
     */
    private function refusal(callable $call): ?string
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function refused(callable $call): bool
    {
        $message = $this->refusal($call);
        return null !== $message
            && (false !== stripos($message, 'this site') || false !== stripos($message, 'executable comments'));
    }

    public function test_query_refuses_every_foreign_table_reference_form(): void
    {
        global $wpdb;
        $own   = $wpdb->options;
        $meta  = $wpdb->sitemeta;
        $main  = $wpdb->base_prefix . 'options';
        $other = $wpdb->get_blog_prefix($this->other) . 'options';

        $leaks = [];
        $queries = [
            "SELECT meta_value FROM {$meta} WHERE meta_key = 'site_admins'",
            "SELECT option_value FROM {$main} WHERE option_name = 'admin_email'",
            "SELECT option_value FROM {$other} WHERE option_name = 'admin_email'",
            "SELECT o.option_value FROM {$own} o JOIN {$meta} m ON 1=1 WHERE m.meta_key = 'site_admins'",
            "SELECT o.option_value FROM {$own} o LEFT OUTER JOIN `{$main}` m ON 1=1",
            "SELECT o.option_name FROM {$own} o, {$main} m WHERE o.option_name = m.option_name",
            "SELECT option_name FROM {$own} WHERE option_value IN (SELECT meta_value FROM {$meta})",
            "SELECT * FROM (SELECT meta_value FROM `{$meta}`) AS t",
            "SELECT * FROM `" . DB_NAME . "`.`{$meta}`",
            "SELECT meta_value FROM " . DB_NAME . ".{$meta}",
            "WITH t AS (SELECT meta_value FROM {$meta}) SELECT * FROM t",
            "SELECT option_name FROM {$own} UNION SELECT meta_value FROM {$meta}",
            "SELECT 1--1, meta_value FROM {$meta}",
            "SELECT 1 /*M!, (SELECT meta_value FROM {$meta} LIMIT 1) */",
            'SELECT * FROM "' . $meta . '"',
            "SELECT table_name FROM information_schema.TABLES",
            "SELECT column_name FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = 'x'",
            "DESCRIBE {$meta}",
            "SHOW COLUMNS FROM {$meta}",
            "SHOW INDEX FROM `{$main}`",
            "SHOW FULL COLUMNS FROM {$own} FROM `information_schema`",
            'SHOW TABLES',
            'SHOW TABLE STATUS',
            'SHOW DATABASES',
            'SHOW PROCESSLIST',
        ];
        foreach ($queries as $sql) {
            if (! $this->refused(static fn () => (new Query())->handle(['sql' => $sql]))) {
                $leaks[] = $sql;
            }
        }

        $this->assertSame([], $leaks, 'A sub-site admin query reached tables outside its own site');
    }

    public function test_query_on_the_callers_own_tables_still_works(): void
    {
        global $wpdb;

        $out = (new Query())->handle(['sql' => "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'"]);
        $this->assertSame(1, $out['row_count']);

        $out = (new Query())->handle([
            'sql' => "SELECT p.ID FROM `{$wpdb->posts}` p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID "
                . "WHERE p.ID IN (SELECT object_id FROM {$wpdb->term_relationships}) LIMIT 1",
        ]);
        $this->assertArrayHasKey('rows', $out);

        $out = (new Query())->handle(['sql' => "SHOW COLUMNS FROM {$wpdb->options}"]);
        $this->assertGreaterThan(0, $out['row_count']);

        $out = (new Query())->handle(['sql' => 'SELECT 1 AS n']);
        $this->assertSame(1, $out['row_count']);
    }

    public function test_row_writes_refuse_foreign_tables_even_with_writes_enabled(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');
        \WPMCP\Safety\Snapshot_Store::install();
        $login = get_userdata($this->sub_admin)->user_login;

        $allowed = [];
        foreach ($this->foreign_tables() as $label => [$table, $key, $value]) {
            $calls = [
                'update-rows' => static fn () => (new Update_Rows())->handle([
                    'table'   => $table,
                    'data'    => [$value => serialize(['admin', $login])],
                    'where'   => [$key => 'sitemeta' === $label ? 'site_admins' : 'wpmcp_probe_absent'],
                    'confirm' => true,
                ]),
                'insert-row'  => static fn () => (new Insert_Row())->handle([
                    'table' => $table,
                    'data'  => [$key => 'wpmcp_probe', $value => '1'],
                ]),
                'delete-rows' => static fn () => (new Delete_Rows())->handle([
                    'table'   => $table,
                    'where'   => [$key => 'wpmcp_probe_absent'],
                    'confirm' => true,
                ]),
            ];
            foreach ($calls as $tool => $call) {
                if (! $this->refused($call)) {
                    $allowed[] = "{$tool} on {$label}";
                }
            }
        }

        wp_cache_flush();
        $this->assertSame([], $allowed, 'A sub-site admin wrote outside its own site');
        $this->assertFalse(is_super_admin($this->sub_admin), 'A sub-site admin made itself a super admin');
    }

    public function test_describe_table_refuses_foreign_tables(): void
    {
        $allowed = [];
        foreach ($this->foreign_tables() as $label => [$table]) {
            if (! $this->refused(static fn () => (new Describe_Table())->handle(['table' => $table]))) {
                $allowed[] = $label;
            }
        }
        $this->assertSame([], $allowed);
    }

    public function test_list_tables_shows_only_the_callers_own_site_tables(): void
    {
        global $wpdb;
        // The test harness creates sub-site tables as TEMPORARY tables, which
        // information_schema does not list, so the positive side (own tables
        // stay listed) is asserted by the main-site test below.
        $names = array_column((new List_Tables())->handle([])['tables'], 'table');

        foreach ($names as $name) {
            $this->assertStringStartsWith($wpdb->prefix, $name, "list-tables showed {$name} to a sub-site admin");
        }
        foreach ($this->foreign_tables() as [$table]) {
            $this->assertNotContains($table, $names);
        }
        $this->assertNotContains($wpdb->base_prefix . 'posts', $names);
    }

    public function test_a_main_site_admin_without_network_rights_cannot_reach_sub_site_tables(): void
    {
        global $wpdb;
        restore_current_blog();
        $main_admin = (int) self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($main_admin);
        $this->assertFalse(is_super_admin($main_admin));

        $other = $wpdb->get_blog_prefix($this->other) . 'options';
        $this->assertTrue($this->refused(static fn () => (new Query())->handle(['sql' => "SELECT * FROM {$other}"])));
        $this->assertTrue($this->refused(static fn () => (new Query())->handle(['sql' => "SELECT * FROM {$wpdb->sitemeta}"])));
        $this->assertTrue($this->refused(static fn () => (new Describe_Table())->handle(['table' => $other])));
        $this->assertTrue($this->refused(static fn () => (new Describe_Table())->handle(['table' => $wpdb->sitemeta])));
        $this->assertSame($wpdb->options, (new Describe_Table())->handle(['table' => $wpdb->options])['table']);

        $out = (new Query())->handle(['sql' => "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'"]);
        $this->assertSame(1, $out['row_count']);

        $names = array_column((new List_Tables())->handle([])['tables'], 'table');
        $this->assertContains($wpdb->options, $names);
        $this->assertContains($wpdb->posts, $names);
        $this->assertNotContains($other, $names);
        $this->assertNotContains($wpdb->users, $names);
        $this->assertNotContains($wpdb->sitemeta, $names);

        switch_to_blog($this->blog);
    }

    public function test_a_super_admin_keeps_network_wide_reach(): void
    {
        global $wpdb;
        wp_set_current_user($this->super);

        $out = (new Query())->handle(['sql' => "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'site_admins'"]);
        $this->assertSame(1, $out['row_count']);

        $main = $wpdb->base_prefix . 'options';
        $out  = (new Query())->handle(['sql' => "SELECT option_value FROM {$main} WHERE option_name = 'blogname'"]);
        $this->assertSame(1, $out['row_count']);

        $names = array_column((new List_Tables())->handle([])['tables'], 'table');
        $this->assertContains($wpdb->sitemeta, $names);
        $this->assertContains($main, $names);

        $this->assertSame($wpdb->sitemeta, (new Describe_Table())->handle(['table' => $wpdb->sitemeta])['table']);
    }

    public function test_sitemeta_is_write_protected_by_default_on_multisite(): void
    {
        global $wpdb;
        $this->assertTrue(Database_Guard::is_protected($wpdb->sitemeta));

        wp_set_current_user($this->super);
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $message = $this->refusal(static fn () => (new Update_Rows())->handle([
            'table'   => $wpdb->sitemeta,
            'data'    => ['meta_value' => 'x'],
            'where'   => ['meta_key' => 'wpmcp_probe_absent'],
            'confirm' => true,
        ]));
        $this->assertNotNull($message);
        $this->assertStringContainsString('protected table', $message);
    }
}
