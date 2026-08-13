<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Db_Dumper;

/**
 * Db_Dumper produces the SQL half of a site archive. These tests run against
 * the real test database rather than a mocked $wpdb: the whole value of this
 * class is that its output is valid, importable SQL for actual MySQL types
 * and actual WordPress data, which a mock cannot demonstrate.
 *
 * The dump is verified by importing it back into a scratch table and
 * comparing rows, which is the only assertion that proves the escaping
 * round-trips. Asserting on the generated SQL text alone would pass for a
 * dump that no server can import.
 */
class DbDumperTest extends \WP_UnitTestCase
{
    private Db_Dumper $dumper;
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->dumper  = new Db_Dumper();
        $this->scratch = $wpdb->prefix . 'wpmcp_dump_fixture';

        // WP_UnitTestCase rewrites CREATE TABLE into CREATE TEMPORARY TABLE
        // through a 'query' filter, and temporary tables are invisible to
        // SHOW TABLES, which is exactly the statement this class enumerates
        // with. Left in place, every fixture table here would be silently
        // skipped by the dumper and the suite would pass while proving
        // nothing. The filters are removed so the fixtures are real tables;
        // tearDown drops them explicitly, since they now outlive the
        // harness's per-test transaction rollback.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->scratch . '`');
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->scratch . '_restored`');
        parent::tearDown();
    }

    /** Collect a dump into a string. Test fixtures are small by construction. */
    private function dump_to_string(?array $tables = null): array
    {
        $sql = '';
        $result = $this->dumper->dump(static function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        }, $tables);

        return [$sql, $result];
    }

    private function create_fixture_table(): void
    {
        global $wpdb;
        $wpdb->query(
            'CREATE TABLE `' . $this->scratch . '` ('
            . 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'label VARCHAR(255) NOT NULL,'
            . 'body LONGTEXT NULL,'
            . 'score INT NULL,'
            . 'PRIMARY KEY (id))'
        );
    }

    public function test_tables_are_scoped_to_this_installs_prefix(): void
    {
        global $wpdb;
        $this->create_fixture_table();

        $tables = $this->dumper->tables();

        $this->assertContains($this->scratch, $tables);
        $this->assertContains($wpdb->prefix . 'posts', $tables);
        foreach ($tables as $table) {
            $this->assertStringStartsWith(
                is_multisite() ? $wpdb->base_prefix : $wpdb->prefix,
                $table,
                'A dump must never reach into another install sharing the database.'
            );
        }
    }

    public function test_dump_emits_structure_and_rows_for_the_requested_table(): void
    {
        global $wpdb;
        $this->create_fixture_table();
        $wpdb->query(
            'INSERT INTO `' . $this->scratch . '` (label, body, score) VALUES '
            . "('first', 'hello', 1), ('second', NULL, NULL)"
        );

        [$sql, $result] = $this->dump_to_string([$this->scratch]);

        $this->assertStringContainsString('DROP TABLE IF EXISTS `' . $this->scratch . '`;', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `' . $this->scratch . '`', $sql);
        $this->assertSame(2, $result['tables'][ $this->scratch ]);
        $this->assertGreaterThan(0, $result['bytes']);
    }

    public function test_a_null_column_round_trips_as_sql_null_not_the_string_null(): void
    {
        global $wpdb;
        $this->create_fixture_table();
        $wpdb->query('INSERT INTO `' . $this->scratch . "` (label, body, score) VALUES ('n', NULL, NULL)");

        [$sql] = $this->dump_to_string([$this->scratch]);

        // "NULL" unquoted, not "'NULL'": the quoted form silently turns every
        // nullable column into the four-character string on restore.
        $this->assertMatchesRegularExpression('/VALUES\s*\n?\([^)]*,\s*NULL,\s*NULL\)/', $sql);
        $this->assertStringNotContainsString("'NULL'", $sql);
    }

    public function test_dump_reimports_cleanly_and_preserves_hostile_values(): void
    {
        global $wpdb;
        $this->create_fixture_table();

        // The values that break naive dumpers: quotes, backslashes, newlines,
        // NUL bytes, a lone percent sign (which a careless prepare() would
        // eat as a placeholder), emoji, and serialized data.
        $hostile = [
            "quote ' and \" double",
            'back\\slash and \\\' escaped quote',
            "line\nbreak\ttab",
            'percent 100% and %s and %d',
            'emoji 🚀 unicode ünïcödé',
            serialize(['url' => 'https://example.test', 'n' => 5]),
            "nul" . chr(0) . "byte",
        ];

        foreach ($hostile as $i => $value) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO `' . $this->scratch . '` (label, body, score) VALUES (%s, %s, %d)',
                    'row' . $i,
                    $value,
                    $i
                )
            );
        }

        [$sql] = $this->dump_to_string([$this->scratch]);

        // Re-import the dump under a different table name and compare.
        $restored = $this->scratch . '_restored';
        $import   = str_replace('`' . $this->scratch . '`', '`' . $restored . '`', $sql);

        foreach ($this->statements($import) as $statement) {
            $wpdb->query($statement);
            $this->assertSame('', (string) $wpdb->last_error, 'Every statement in the dump must import cleanly: ' . substr($statement, 0, 120));
        }

        $original = $wpdb->get_results('SELECT label, body, score FROM `' . $this->scratch . '` ORDER BY id', ARRAY_A);
        $copy     = $wpdb->get_results('SELECT label, body, score FROM `' . $restored . '` ORDER BY id', ARRAY_A);

        $this->assertSame($original, $copy, 'A dump that does not round-trip its own data is not a backup.');
    }

    public function test_multi_row_inserts_are_split_once_a_statement_grows_too_large(): void
    {
        global $wpdb;
        $this->create_fixture_table();

        // Each row is ~64KB, so a handful must produce more than one INSERT
        // rather than a single statement past max_allowed_packet.
        $chunk = str_repeat('x', 64000);
        for ($i = 0; $i < 12; $i++) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO `' . $this->scratch . '` (label, body, score) VALUES (%s, %s, %d)',
                    'big' . $i,
                    $chunk,
                    $i
                )
            );
        }

        [$sql, $result] = $this->dump_to_string([$this->scratch]);

        $this->assertSame(12, $result['tables'][ $this->scratch ]);
        $this->assertGreaterThan(
            1,
            substr_count($sql, 'INSERT INTO `' . $this->scratch . '`'),
            'Rows past the statement byte cap must start a new INSERT.'
        );
    }

    public function test_batching_reads_every_row_past_a_single_batch(): void
    {
        global $wpdb;
        $this->create_fixture_table();

        $rows = Db_Dumper::BATCH + 25;
        $values = [];
        for ($i = 0; $i < $rows; $i++) {
            $values[] = $wpdb->prepare('(%s, %s, %d)', 'r' . $i, 'body', $i);
        }
        // Insert in chunks to stay inside max_allowed_packet.
        foreach (array_chunk($values, 200) as $chunk) {
            $wpdb->query('INSERT INTO `' . $this->scratch . '` (label, body, score) VALUES ' . implode(',', $chunk));
        }

        [, $result] = $this->dump_to_string([$this->scratch]);

        $this->assertSame($rows, $result['tables'][ $this->scratch ], 'The batch loop must not stop at the first batch boundary.');
    }

    public function test_unknown_table_names_are_ignored_rather_than_interpolated(): void
    {
        // The caller-supplied list is intersected with the real table list, so
        // an injected identifier can never reach a query.
        [$sql, $result] = $this->dump_to_string(['wp_posts; DROP TABLE wp_users; --']);

        $this->assertSame([], $result['tables']);
        $this->assertStringNotContainsString('DROP TABLE wp_users', $sql);
    }

    public function test_header_disables_constraint_checks_and_the_footer_restores_them(): void
    {
        [$sql] = $this->dump_to_string([]);

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        $this->assertStringContainsString('SET UNIQUE_CHECKS = 0;', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1;', $sql);
        $this->assertStringContainsString('SET UNIQUE_CHECKS = 1;', $sql);
    }

    public function test_blob_columns_are_reported_for_restore_time_warnings(): void
    {
        global $wpdb;
        $wpdb->query(
            'CREATE TABLE `' . $this->scratch . '` (id INT NOT NULL AUTO_INCREMENT, payload BLOB, PRIMARY KEY (id))'
        );

        [, $result] = $this->dump_to_string([$this->scratch]);

        $this->assertContains($this->scratch, $result['blob_tables']);
    }

    /**
     * Split a dump into executable statements. Deliberately simple: it splits
     * on ";\n" only, which is exactly how this dumper terminates statements,
     * and is not a general SQL parser.
     *
     * @return string[]
     */
    private function statements(string $sql): array
    {
        $out = [];
        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);
            if ('' === $statement || str_starts_with($statement, '--')) {
                continue;
            }
            // Strip leading comment lines that precede a real statement.
            $lines = array_filter(
                explode("\n", $statement),
                static fn(string $line): bool => '' !== trim($line) && ! str_starts_with(trim($line), '--')
            );
            $cleaned = trim(implode("\n", $lines));
            if ('' !== $cleaned) {
                $out[] = $cleaned;
            }
        }

        return $out;
    }
}
