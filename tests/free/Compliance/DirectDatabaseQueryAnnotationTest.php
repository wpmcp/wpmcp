<?php

namespace WPMCP\Tests\Free\Compliance;

/**
 * Guards the issue #182 work.
 *
 * `composer lint` runs the PSR-12 ruleset, and the WordPress ruleset that
 * actually emits WordPress.DB.DirectDatabaseQuery is not wired into CI on
 * this branch, so nothing here would notice an annotation being dropped or a
 * new unannotated $wpdb call site appearing in a file that has already been
 * cleaned. This test is that missing guard: for every file listed in
 * COVERED_FILES, every $wpdb query call site must carry a
 * WordPress.DB.DirectDatabaseQuery ignore with a justification.
 *
 * COVERED_FILES is deliberately a list rather than "all of src": the sweep is
 * not finished. The list may only ever grow as more files are annotated, and
 * a file that is in it can never silently regress.
 */
class DirectDatabaseQueryAnnotationTest extends \WP_UnitTestCase
{
    /** Files whose $wpdb call sites are fully annotated. Append only. */
    private const COVERED_FILES = [
        'src/Auth/Code_Store.php',
        'src/Tools/Database/Database_Guard.php',
        'src/Tools/Database/Delete_Rows.php',
        'src/Tools/Database/Describe_Table.php',
        'src/Tools/Database/Insert_Row.php',
        'src/Tools/Database/List_Tables.php',
        'src/Tools/Database/Query.php',
        'src/Tools/Database/Update_Rows.php',
        'src/Tools/Diagnostics/List_Transients.php',
    ];

    /** wpdb methods that reach the database. prepare()/esc_like() do not. */
    private const QUERY_METHODS = [
        'query',
        'get_results',
        'get_row',
        'get_col',
        'get_var',
        'insert',
        'replace',
        'update',
        'delete',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<int, array{line: int, code: string}>
     */
    private function unannotated_call_sites(string $relative): array
    {
        $lines   = file($this->root() . '/' . $relative, FILE_IGNORE_NEW_LINES);
        $pattern = '/\$wpdb->(' . implode('|', self::QUERY_METHODS) . ')\s*\(/';
        $missing = [];

        foreach ($lines as $index => $line) {
            if (1 !== preg_match($pattern, $line)) {
                continue;
            }

            // A call site written about in a comment is not a call site.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Walk back over blank lines to the nearest preceding statement
            // or comment, which is where the annotation has to live.
            $cursor = $index - 1;
            while ($cursor >= 0 && '' === trim($lines[ $cursor ])) {
                $cursor--;
            }

            $previous = $cursor >= 0 ? $lines[ $cursor ] : '';

            if (
                false !== strpos($previous, 'phpcs:ignore')
                && false !== strpos($previous, 'WordPress.DB.DirectDatabaseQuery')
            ) {
                continue;
            }

            $missing[] = ['line' => $index + 1, 'code' => trim($line)];
        }

        return $missing;
    }

    public function test_every_covered_file_annotates_all_of_its_wpdb_call_sites(): void
    {
        foreach (self::COVERED_FILES as $relative) {
            $this->assertFileExists($this->root() . '/' . $relative);

            $missing = $this->unannotated_call_sites($relative);

            $this->assertSame(
                [],
                $missing,
                $relative . ' has $wpdb call sites without a WordPress.DB.DirectDatabaseQuery ignore: '
                . wp_json_encode($missing)
            );
        }
    }

    /**
     * An ignore with no justification is exactly what the issue's definition
     * of done rules out, so every annotation must carry a `--` reason.
     */
    public function test_every_direct_database_query_ignore_carries_a_justification(): void
    {
        foreach (self::COVERED_FILES as $relative) {
            $lines = file($this->root() . '/' . $relative, FILE_IGNORE_NEW_LINES);

            foreach ($lines as $index => $line) {
                if (false === strpos($line, 'phpcs:ignore')) {
                    continue;
                }
                if (false === strpos($line, 'WordPress.DB.')) {
                    continue;
                }

                $reason = substr($line, (int) strpos($line, '--') + 2);

                $this->assertStringContainsString(
                    '--',
                    $line,
                    $relative . ':' . ($index + 1) . ' has a phpcs:ignore with no justification.'
                );
                $this->assertGreaterThan(
                    20,
                    strlen(trim($reason)),
                    $relative . ':' . ($index + 1) . ' has a justification too short to explain anything.'
                );
            }
        }
    }

    /**
     * The three row-write tools must invalidate the caches their raw writes
     * make stale. This pins the behavior the annotations now claim, so the
     * justification text and the code cannot drift apart.
     */
    public function test_the_row_write_tools_invalidate_after_writing(): void
    {
        $writers = [
            'src/Tools/Database/Insert_Row.php',
            'src/Tools/Database/Update_Rows.php',
            'src/Tools/Database/Delete_Rows.php',
        ];

        foreach ($writers as $relative) {
            $source = (string) file_get_contents($this->root() . '/' . $relative);

            $this->assertStringContainsString(
                'Database_Guard::invalidate_caches(',
                $source,
                $relative . ' writes rows directly but never invalidates the object cache.'
            );
        }
    }
}
