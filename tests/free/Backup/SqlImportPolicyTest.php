<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Sql_Import_Policy;

/**
 * The statement allowlist a restore holds every line of db.sql to before
 * executing any of it (issue #190).
 */
class SqlImportPolicyTest extends \WP_UnitTestCase
{
    private function policy(): Sql_Import_Policy
    {
        return new Sql_Import_Policy('wp_', ['wp_posts', 'wp_options']);
    }

    public static function allowed(): array
    {
        return [
            'sql_mode'        => ["SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'", 'set', null],
            'fk off'          => ['SET FOREIGN_KEY_CHECKS = 0', 'set', null],
            'unique on'       => ['SET UNIQUE_CHECKS = 1', 'set', null],
            'names'           => ['SET NAMES utf8mb4', 'set', null],
            'names collate'   => ['SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci', 'set', null],
            'drop'            => ['DROP TABLE IF EXISTS `wp_posts`', 'drop', 'wp_posts'],
            'create'          => ["CREATE TABLE `wp_posts` (\n `ID` bigint COMMENT 'select me',\n `select` int,\n PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'create', 'wp_posts'],
            'insert literals' => ["INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n('1', 'it''s (a) \\' test; SELECT 1'),\n('2', NULL),(3, -1.5e3)", 'insert', 'wp_posts'],
        ];
    }

    /** @dataProvider allowed */
    public function test_statements_a_dump_writes_are_allowed(string $sql, string $kind, ?string $table): void
    {
        $this->assertSame(['kind' => $kind, 'table' => $table], $this->policy()->classify($sql));
    }

    public static function refused(): array
    {
        return [
            'user variable'     => ['SET @x = 1', 'Only the SET statements'],
            'global variable'   => ['SET GLOBAL general_log = 1', 'Only the SET statements'],
            'other prefix'      => ['DROP TABLE IF EXISTS `other_posts`', 'Table prefix mismatch'],
            'unlisted table'    => ["INSERT INTO `wp_users` (`ID`) VALUES ('1')", 'does not list'],
            'plain drop'        => ['DROP TABLE `wp_posts`', 'Only SET, DROP TABLE IF EXISTS'],
            'drop database'     => ['DROP DATABASE wordpress', 'Only SET, DROP TABLE IF EXISTS'],
            'grant'             => ["GRANT ALL ON *.* TO 'x'@'%'", 'Only SET, DROP TABLE IF EXISTS'],
            'create select'     => ['CREATE TABLE `wp_posts` (a int) SELECT * FROM mysql.user WHERE (1)', 'reaches outside'],
            'data directory'    => ["CREATE TABLE `wp_posts` (a int) DATA DIRECTORY = '/var/www'", 'reaches outside'],
            'federated'         => ["CREATE TABLE `wp_posts` (a int) ENGINE=FEDERATED CONNECTION='mysql://x'", 'reaches outside'],
            'insert select'     => ['INSERT INTO `wp_posts` SELECT * FROM wp_users', 'Only SET, DROP TABLE IF EXISTS'],
            'subquery value'    => ["INSERT INTO `wp_posts` (`ID`) VALUES ((SELECT authentication_string FROM mysql.user LIMIT 1))", 'literal values'],
            'function value'    => ['INSERT INTO `wp_posts` (`ID`) VALUES (user())', 'literal values'],
            'on duplicate'      => ["INSERT INTO `wp_posts` (`ID`) VALUES ('1') ON DUPLICATE KEY UPDATE ID = 2", 'literal values'],
            'bare word'         => ['INSERT INTO `wp_posts` (`ID`) VALUES (NULLX)', 'literal values'],
            'unterminated'      => ["INSERT INTO `wp_posts` (`ID`) VALUES ('1)", 'literal values'],
            'no tuple'          => ['INSERT INTO `wp_posts` (`ID`) VALUES ', 'literal values'],
        ];
    }

    /** @dataProvider refused */
    public function test_anything_else_is_refused_with_a_reason(string $sql, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        $this->policy()->classify($sql);
    }

    public function test_without_a_table_list_any_prefixed_table_is_allowed(): void
    {
        $out = (new Sql_Import_Policy('wp_'))->classify('DROP TABLE IF EXISTS `wp_anything`');
        $this->assertSame('wp_anything', $out['table']);
    }

    public function test_an_empty_prefix_refuses_everything_that_names_a_table(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Sql_Import_Policy(''))->classify('DROP TABLE IF EXISTS `wp_posts`');
    }
}
