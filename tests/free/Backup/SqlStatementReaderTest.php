<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Sql_Statement_Reader;

/**
 * The bounded, quote-aware splitter under the restore importer (issue #190).
 * Every case is run with a tiny chunk size as well as the default, because
 * the bugs a streaming splitter has live at slice boundaries.
 */
class SqlStatementReaderTest extends \WP_UnitTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function file(string $sql): string
    {
        $path = (string) tempnam(get_temp_dir(), 'wpmcp-reader');
        file_put_contents($path, $sql);
        $this->files[] = $path;
        return $path;
    }

    /** @return array<int, array{sql: string, offset: int, index: int}> */
    private function read(string $sql, int $chunk = Sql_Statement_Reader::CHUNK_BYTES, int $max = Sql_Statement_Reader::MAX_STATEMENT_BYTES): array
    {
        return iterator_to_array((new Sql_Statement_Reader($this->file($sql), $max, $chunk))->statements(), false);
    }

    public static function chunk_sizes(): array
    {
        return [[16], [17], [19], [23], [Sql_Statement_Reader::CHUNK_BYTES]];
    }

    /** @dataProvider chunk_sizes */
    public function test_semicolons_inside_quotes_and_comments_do_not_split(int $chunk): void
    {
        $sql = "-- header ; not a statement\n"
            . "SET NAMES utf8mb4;\n\n"
            . "/* block ; comment */\n"
            . "CREATE TABLE `a;b` (\n `x` text COMMENT 'semi;colon'\n);\n"
            . "INSERT INTO `a;b` (`x`) VALUES\n('it''s; \\' ok'),(\"dq;\\\"\"),(NULL);\n"
            . "# hash comment ;\n"
            . "SELECT 1--1;\n";

        $sqls = array_column($this->read($sql, $chunk), 'sql');

        $this->assertSame([
            'SET NAMES utf8mb4',
            "CREATE TABLE `a;b` (\n `x` text COMMENT 'semi;colon'\n)",
            "INSERT INTO `a;b` (`x`) VALUES\n('it''s; \\' ok'),(\"dq;\\\"\"),(NULL)",
            'SELECT 1--1',
        ], $sqls);
    }

    /** @dataProvider chunk_sizes */
    public function test_offsets_and_ordinals_point_at_each_statement(int $chunk): void
    {
        $sql        = "SET A = 1;\n-- c\nSET B = 2;\n";
        $statements = $this->read($sql, $chunk);

        $this->assertSame([1, 2], array_column($statements, 'index'));
        $this->assertSame(0, $statements[0]['offset']);
        $this->assertSame(strpos($sql, 'SET B'), $statements[1]['offset']);
    }

    public function test_empty_statements_are_skipped(): void
    {
        $this->assertSame(['SET A = 1'], array_column($this->read(";;\nSET A = 1;;\n"), 'sql'));
    }

    /** @dataProvider chunk_sizes */
    public function test_a_dump_ending_inside_a_value_is_truncated(int $chunk): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ends inside a quoted value');
        $this->read("SET A = 1;\nINSERT INTO t VALUES ('abc", $chunk);
    }

    /** @dataProvider chunk_sizes */
    public function test_a_dump_ending_without_a_semicolon_is_truncated(int $chunk): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no terminating semicolon');
        $this->read("SET A = 1;\nINSERT INTO t VALUES ('abc')", $chunk);
    }

    public function test_a_dump_ending_inside_a_block_comment_is_truncated(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unterminated comment');
        $this->read("SET A = 1;\n/* never closed");
    }

    public function test_trailing_whitespace_and_comments_are_fine(): void
    {
        $this->assertCount(1, $this->read("SET A = 1;\n\n-- the end\n   \n"));
    }

    public function test_an_oversized_statement_is_refused_rather_than_buffered(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('larger than the 100 byte limit');
        $this->read("SET A = 1;\nINSERT INTO t VALUES ('" . str_repeat('x', 500) . "');\n", 16, 100);
    }

    public function test_an_unreadable_file_is_an_error(): void
    {
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new Sql_Statement_Reader('/no/such/dump.sql'))->statements());
    }

    public function test_a_large_dump_streams_with_bounded_memory(): void
    {
        $row  = "INSERT INTO `t` (`a`) VALUES ('" . str_repeat('lorem \\\' ipsum ', 200) . "');\n";
        $path = (string) tempnam(get_temp_dir(), 'wpmcp-reader-big');
        $this->files[] = $path;
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($path, str_repeat($row, 1000), FILE_APPEND);
        }
        $size = filesize($path);

        $before = memory_get_usage();
        $peak   = 0;
        $count  = 0;
        foreach ((new Sql_Statement_Reader($path))->statements() as $statement) {
            $count++;
            $peak = max($peak, memory_get_usage() - $before);
        }

        $this->assertSame(10000, $count);
        $this->assertLessThan($size / 3, $peak, 'Memory must track one slice, not the file.');
    }
}
