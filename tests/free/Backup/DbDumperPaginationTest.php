<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Site_Archive_Builder;

/**
 * A backup that quietly drops rows is worse than one that fails.
 *
 * The dump paginated with LIMIT/OFFSET and no ORDER BY. MySQL guarantees no
 * row order across separate statements, and each batch here is a separate
 * statement with no enclosing transaction - so on a live site (the only kind
 * anyone backs up) a concurrent INSERT or DELETE shifts the offset window
 * and rows are duplicated or skipped. Nothing reports it: the archive is
 * well-formed, the manifest counts what was read, and the loss only surfaces
 * on restore.
 *
 * The existing DbDumperTest cannot catch it - its tables are far below
 * Db_Dumper::BATCH, so the loop never iterates twice and the ordering
 * question never arises.
 */
class DbDumperPaginationTest extends \WP_UnitTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->table = $wpdb->prefix . 'wpmcp_dump_probe';

        // The suite rewrites CREATE TABLE to CREATE TEMPORARY TABLE, and a
        // temporary table is invisible to SHOW TABLES - which is exactly how
        // Db_Dumper enumerates what to dump, so the probe table would never
        // be seen. Drop the rewrite for these two statements only.
        $this->without_temporary_table_rewrite(function () use ($wpdb) {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
            $wpdb->query(
                "CREATE TABLE {$this->table} (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    label VARCHAR(64) NOT NULL,
                    PRIMARY KEY (id)
                ) {$wpdb->get_charset_collate()}"
            );
        });
    }

    protected function tearDown(): void
    {
        global $wpdb;

        $this->without_temporary_table_rewrite(function () use ($wpdb) {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        });

        parent::tearDown();
    }

    private function without_temporary_table_rewrite(callable $run): void
    {
        $create = [$this, '_create_temporary_tables'];
        $drop   = [$this, '_drop_temporary_tables'];

        remove_filter('query', $create);
        remove_filter('query', $drop);

        try {
            $run();
        } finally {
            add_filter('query', $create);
            add_filter('query', $drop);
        }
    }

    private function seed(int $rows): void
    {
        global $wpdb;

        for ($i = 1; $i <= $rows; $i++) {
            $wpdb->insert($this->table, ['label' => 'row-' . $i]);
        }
    }

    private function dump(): string
    {
        $sql = '';
        (new Db_Dumper())->dump(
            function (string $chunk) use (&$sql): void {
                $sql .= $chunk;
            },
            [$this->table]
        );

        return $sql;
    }

    /**
     * More rows than one batch, so pagination actually runs. Every row must
     * appear exactly once.
     */
    public function test_every_row_is_dumped_exactly_once_across_batches(): void
    {
        $rows = Db_Dumper::BATCH * 2 + 7;
        $this->seed($rows);

        $sql = $this->dump();

        $missing = [];
        for ($i = 1; $i <= $rows; $i++) {
            if (1 !== substr_count($sql, "'row-{$i}'")) {
                $missing[] = $i;
            }
        }

        $this->assertSame(
            [],
            array_slice($missing, 0, 20),
            sprintf('%d of %d rows were dropped or duplicated by the paginated dump.', count($missing), $rows)
        );
    }

    /**
     * The property that actually prevents the loss: the dump must not depend
     * on the server happening to return rows in a stable order. Pinning the
     * generated SQL to an ordered read is what makes the guarantee real
     * rather than incidental.
     */
    public function test_the_paginated_read_is_ordered(): void
    {
        $this->seed(Db_Dumper::BATCH + 1);

        global $wpdb;
        $seen = [];
        $spy  = static function ($query) use (&$seen) {
            if (str_starts_with(strtoupper(ltrim((string) $query)), 'SELECT')) {
                $seen[] = (string) $query;
            }
            return $query;
        };

        add_filter('query', $spy);
        try {
            $this->dump();
        } finally {
            remove_filter('query', $spy);
        }

        $reads = array_values(array_filter(
            $seen,
            fn(string $q): bool => str_contains($q, $this->table) && str_contains(strtoupper($q), 'SELECT *')
        ));

        $this->assertNotEmpty($reads, 'The dumper never read the table.');

        foreach ($reads as $query) {
            $this->assertStringContainsStringIgnoringCase(
                'ORDER BY',
                $query,
                "A paginated read without ORDER BY can return rows in any order: {$query}"
            );
        }
    }

    /**
     * Downloadable products, exported archives and backups a user keeps in
     * the Media Library are real uploads. Excluding them by extension across
     * the whole tree means a WooCommerce store migrates with every
     * downloadable file silently absent while file_count reports success.
     */
    public function test_archive_extensions_are_not_excluded_inside_uploads(): void
    {
        $uploads = wp_upload_dir();
        $target  = trailingslashit($uploads['basedir']) . 'wpmcp-probe-product.zip';

        $this->assertTrue(
            Site_Archive_Builder::should_archive($target),
            'A .zip inside uploads is user content, not a regenerable artefact, and must be backed up.'
        );

        $this->assertFalse(
            Site_Archive_Builder::should_archive(WP_CONTENT_DIR . '/cache/bundle.zip'),
            'Regenerable caches should still be skipped.'
        );

        $this->assertFalse(
            Site_Archive_Builder::should_archive(WP_CONTENT_DIR . '/plugins/acme/dist.zip'),
            'Build output beside a plugin is regenerable and stays excluded.'
        );

        // Logs are the one thing in uploads that is not user content:
        // WooCommerce's wc-logs are regenerable and full of customer detail.
        $this->assertFalse(
            Site_Archive_Builder::should_archive(trailingslashit($uploads['basedir']) . 'wc-logs/orders.log'),
            'Logs inside uploads are regenerable and carry customer data.'
        );
    }
}
