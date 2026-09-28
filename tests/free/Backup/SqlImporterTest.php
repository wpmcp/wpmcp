<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Sql_Import_Policy;
use WPMCP\Tools\Backup\Sql_Importer;

/**
 * The two passes of the restore importer (issue #190): scan() refuses a
 * dump before anything runs; import() stops at the first failure and says
 * where. These cases use SET statements only, so nothing here changes a
 * table.
 */
class SqlImporterTest extends \WP_UnitTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        parent::tearDown();
    }

    private function dump(string $sql): string
    {
        $path = (string) tempnam(get_temp_dir(), 'wpmcp-import');
        file_put_contents($path, $sql);
        $this->files[] = $path;
        return $path;
    }

    private function policy(): Sql_Import_Policy
    {
        global $wpdb;
        return new Sql_Import_Policy($wpdb->prefix);
    }

    public function test_scan_counts_statements_and_tables_without_running_them(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'never_created';
        $sql   = "SET FOREIGN_KEY_CHECKS = 0;\nDROP TABLE IF EXISTS `{$table}`;\nSET FOREIGN_KEY_CHECKS = 1;\n";

        $out = (new Sql_Importer($this->dump($sql), $this->policy()))->scan();

        $this->assertSame(3, $out['statements']);
        $this->assertSame([$table], $out['tables']);
        $this->assertNull($out['placeholder']);
    }

    public function test_scan_learns_a_legacy_placeholder_only_when_no_value_holds_a_literal_percent(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'never_created';
        $token = '{' . str_repeat('0f', 32) . '}';
        $legacy = "INSERT INTO `{$table}` (`v`) VALUES ('100{$token}');\n";
        $modern = $legacy . "INSERT INTO `{$table}` (`v`) VALUES ('50%');\n";

        $this->assertSame($token, (new Sql_Importer($this->dump($legacy), $this->policy()))->scan()['placeholder']);
        $this->assertNull((new Sql_Importer($this->dump($modern), $this->policy()))->scan()['placeholder']);
    }

    public function test_scan_refuses_a_statement_larger_than_max_allowed_packet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_allowed_packet of 10');

        (new Sql_Importer($this->dump("SET FOREIGN_KEY_CHECKS = 0;\n"), $this->policy()))->scan(10);
    }

    public function test_scan_names_the_statement_the_policy_refuses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Statement 2 (byte 28) is not allowed');

        (new Sql_Importer($this->dump("SET FOREIGN_KEY_CHECKS = 0;\nDELETE FROM wp_users;\n"), $this->policy()))->scan();
    }

    public function test_scan_refuses_an_empty_dump(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty or truncated');

        (new Sql_Importer($this->dump("-- nothing here\n"), $this->policy()))->scan();
    }

    public function test_import_runs_statements_in_order_and_reports_each(): void
    {
        global $wpdb;
        $seen = [];

        $out = (new Sql_Importer($this->dump("SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 1;\n"), $this->policy()))
            ->import(null, static function (array $statement, array $class) use (&$seen): void {
                $seen[] = [$statement['index'], $class['kind']];
            });

        $this->assertNull($out['failure']);
        $this->assertSame(2, $out['executed']);
        $this->assertSame([[1, 'set'], [2, 'set']], $seen);
        $this->assertSame('0', (string) $wpdb->get_var('SELECT @@SESSION.foreign_key_checks'));
    }

    public function test_import_stops_at_a_statement_the_policy_refuses_and_reports_it(): void
    {
        $out = (new Sql_Importer($this->dump("SET FOREIGN_KEY_CHECKS = 0;\nSET @x = 1;\nSET UNIQUE_CHECKS = 1;\n"), $this->policy()))->import();

        $this->assertSame(1, $out['executed']);
        $this->assertSame(2, $out['failure']['statement']);
        $this->assertSame(28, $out['failure']['offset']);
        $this->assertNull($out['failure']['kind']);
        $this->assertStringContainsString('Only the SET statements', $out['failure']['error']);
    }

    public function test_import_reports_a_dump_that_turns_out_truncated(): void
    {
        $out = (new Sql_Importer($this->dump("SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 1"), $this->policy()))->import();

        $this->assertSame(1, $out['executed']);
        $this->assertSame(2, $out['failure']['statement']);
        $this->assertStringContainsString('truncated', $out['failure']['error']);
    }
}
