<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Database_Guard;

class DatabaseGuardTest extends \WP_UnitTestCase
{
    private function ok(string $sql): bool
    {
        return true === Database_Guard::is_read_only_sql($sql);
    }

    private function code(string $sql): string
    {
        $result = Database_Guard::is_read_only_sql($sql);
        return ($result instanceof \WP_Error) ? $result->get_error_code() : 'OK';
    }

    public function test_allows_read_statements(): void
    {
        $this->assertTrue($this->ok('SELECT * FROM wp_options'));
        $this->assertTrue($this->ok('  select 1'));
        $this->assertTrue($this->ok('SeLeCt 1'));
        $this->assertTrue($this->ok('SHOW TABLES'));
        $this->assertTrue($this->ok('DESCRIBE wp_posts'));
        $this->assertTrue($this->ok('EXPLAIN SELECT 1'));
        $this->assertTrue($this->ok('WITH t AS (SELECT 1 AS n) SELECT n FROM t'));
        $this->assertTrue($this->ok('SELECT 1;'));
    }

    public function test_rejects_write_and_ddl_statements(): void
    {
        foreach ([
            'INSERT INTO x VALUES (1)',
            'UPDATE x SET a=1',
            'DELETE FROM x',
            'DROP TABLE x',
            'TRUNCATE x',
            'ALTER TABLE x ADD c INT',
            'CREATE TABLE x (a int)',
            'GRANT ALL ON *.* TO a',
            'REPLACE INTO x VALUES (1)',
        ] as $sql) {
            $this->assertSame('not_read_only', $this->code($sql), $sql);
        }
    }

    public function test_rejects_stacked_statements(): void
    {
        $this->assertSame('multi_statement', $this->code('SELECT 1; DROP TABLE x'));
        $this->assertSame('multi_statement', $this->code('SELECT 1; SELECT 2'));
    }

    public function test_rejects_file_access_selects(): void
    {
        $this->assertSame('file_access_blocked', $this->code("SELECT * FROM x INTO OUTFILE '/tmp/x'"));
        $this->assertSame('file_access_blocked', $this->code("SELECT * INTO DUMPFILE '/tmp/x' FROM x"));
        $this->assertSame('file_access_blocked', $this->code("SELECT LOAD_FILE('/etc/passwd')"));
        $this->assertSame('file_access_blocked', $this->code("select load_file ('/etc/passwd')"));
    }

    public function test_rejects_writes_smuggled_behind_comments(): void
    {
        $this->assertSame('not_read_only', $this->code('/* hi */ DELETE FROM x'));
        $this->assertSame('not_read_only', $this->code("-- comment\nDROP TABLE x"));
        $this->assertSame('not_read_only', $this->code("# c\nUPDATE x SET a=1"));
    }

    public function test_rejects_empty_sql(): void
    {
        $this->assertSame('empty_sql', $this->code('   '));
        $this->assertSame('empty_sql', $this->code('/* only a comment */'));
    }

    public function test_normalize_sql_strips_comments_and_literals(): void
    {
        $norm = Database_Guard::normalize_sql("/* a */ SELECT 'x;y' FROM `t`");
        $this->assertStringContainsString('SELECT', $norm);
        $this->assertStringNotContainsString('x;y', $norm);
        $this->assertStringNotContainsString('/*', $norm);
    }

    public function test_rejects_executable_comment_bypasses(): void
    {
        foreach ([
            "/*! INSERT INTO wp_options(option_name,option_value) */ SELECT 'inj','1'",
            "/*!50000 INSERT INTO wp_options(option_name,option_value) */ SELECT 'inj','1'",
            "/*! UPDATE wp_options SET option_value=(*/ SELECT 1 /*!) WHERE option_id=1 */",
            "/*! CREATE TABLE wp_evil */ SELECT 1 AS a",
        ] as $sql) {
            $this->assertSame('executable_comment', $this->code($sql), $sql);
        }
    }

    public function test_rejects_comment_separated_file_access(): void
    {
        $this->assertSame('file_access_blocked', $this->code('SELECT LOAD_FILE/**/(\'/etc/passwd\')'));
        $this->assertSame('file_access_blocked', $this->code("SELECT * FROM x INTO/**/OUTFILE '/tmp/x'"));
        $this->assertSame('file_access_blocked', $this->code("SELECT * INTO/**/DUMPFILE '/tmp/x' FROM x"));
    }

    public function test_rejects_with_dml(): void
    {
        $this->assertSame('not_read_only', $this->code('WITH x AS (SELECT 1) DELETE FROM y'));
        $this->assertSame('not_read_only', $this->code('WITH x AS (SELECT 1) UPDATE y SET a=1'));
    }

    public function test_allows_keywords_inside_string_literals(): void
    {
        $this->assertTrue($this->ok("SELECT 'please delete this' AS note"));
        $this->assertTrue($this->ok("SELECT 'a;b' AS x"));
        $this->assertTrue($this->ok('SELECT delete_count FROM wp_x'));
    }

    public function test_table_is_protected_matches_case_insensitively(): void
    {
        $protected = ['wp_users', 'wp_usermeta'];
        $this->assertTrue(Database_Guard::table_is_protected('wp_users', $protected));
        $this->assertTrue(Database_Guard::table_is_protected('WP_UserMeta', $protected));
        $this->assertFalse(Database_Guard::table_is_protected('wp_posts', $protected));
    }

    public function test_is_protected_covers_users_and_usermeta_by_default(): void
    {
        global $wpdb;
        $this->assertTrue(Database_Guard::is_protected($wpdb->users));
        $this->assertTrue(Database_Guard::is_protected($wpdb->usermeta));
        $this->assertFalse(Database_Guard::is_protected($wpdb->options));
    }

    public function test_is_protected_honors_filter(): void
    {
        add_filter('wpmcp_db_protected_tables', function (array $tables) {
            $tables[] = 'wp_custom_secret';
            return $tables;
        });

        $this->assertTrue(Database_Guard::is_protected('wp_custom_secret'));

        remove_all_filters('wpmcp_db_protected_tables');
    }
}
