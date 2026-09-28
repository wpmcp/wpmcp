<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * Builds small, real site-backup archives for restore tests (issue #190).
 *
 * A restore test cannot use a full-site archive: importing every table of
 * the shared test database would replace it for every later test. These
 * archives carry a genuine Db_Dumper dump of only the tables a test names,
 * plus a manifest in exactly the shape Site_Archive_Builder writes.
 *
 * Restoring such an archive under WP_UnitTestCase is safe because of the
 * harness's own query filter: it rewrites CREATE TABLE into CREATE
 * TEMPORARY TABLE and DROP TABLE into DROP TEMPORARY TABLE. The dump's
 * DROP TABLE IF EXISTS therefore drops nothing real, its CREATE TABLE
 * builds a temporary table that shadows the real one for this connection,
 * and the INSERTs fill the shadow. WordPress then reads the restored rows,
 * nothing is committed, and drop_restored_tables() removes the shadows.
 */
trait RestoreArchiveFixtures
{
    /** @var string[] Files to delete in tearDown. */
    private array $fixture_files = [];

    /** @var string[] Tables a restore may have shadowed with a temporary copy. */
    private array $restored_tables = [];

    /**
     * A real dump of $tables, as SQL text.
     *
     * @param string[] $tables
     * @return array{0: string, 1: array}
     */
    private function dump_tables(array $tables): array
    {
        $sql    = '';
        $result = (new Db_Dumper())->dump(static function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        }, $tables);

        $this->restored_tables = array_values(array_unique(array_merge($this->restored_tables, $tables)));

        return [$sql, $result];
    }

    /**
     * Write an archive holding $sql as db.sql and a manifest for this site.
     *
     * @param array<string, mixed>  $overrides Dotted manifest keys; null removes the key.
     * @param array<string, string> $files     Extra zip entries (name => contents).
     */
    private function archive_from_sql(string $sql, array $tables, array $overrides = [], array $files = [], ?string $dir = null): string
    {
        global $wpdb, $wp_version;

        $manifest = [
            'format'         => 'wpmcp-site-backup',
            'format_version' => 1,
            'created_at'     => gmdate('c'),
            'scope'          => 'database',
            'site'           => [
                'site_url'     => get_site_url(),
                'home_url'     => get_home_url(),
                'table_prefix' => $wpdb->prefix,
                'base_prefix'  => $wpdb->base_prefix,
                'multisite'    => is_multisite(),
                'charset'      => $wpdb->charset,
                'locale'       => get_locale(),
            ],
            'versions'       => ['wordpress' => $wp_version, 'php' => PHP_VERSION, 'plugin' => WPMCP_VERSION],
            'database'       => [
                'tables'      => $tables,
                'row_count'   => array_sum($tables),
                'blob_tables' => [],
                'bytes'       => strlen($sql),
            ],
            'files'          => ['count' => count($files)],
        ];

        foreach ($overrides as $dotted => $value) {
            $keys = explode('.', $dotted);
            $ref  = &$manifest;
            while (count($keys) > 1) {
                $key = array_shift($keys);
                if (! isset($ref[ $key ]) || ! is_array($ref[ $key ])) {
                    $ref[ $key ] = [];
                }
                $ref = &$ref[ $key ];
            }
            if (null === $value) {
                unset($ref[ $keys[0] ]);
            } else {
                $ref[ $keys[0] ] = $value;
            }
            unset($ref);
        }

        $dir ??= Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);
        $path = $dir . '/restore-fixture-' . wp_generate_password(10, false) . '.zip';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', (string) wp_json_encode($manifest));
        $zip->addFromString('db.sql', $sql);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $this->fixture_files[] = $path;

        return $path;
    }

    /**
     * A real archive of $tables as they are right now.
     *
     * @param string[] $tables
     * @param array<string, mixed> $overrides
     */
    private function archive_of(array $tables, array $overrides = []): string
    {
        [$sql, $result] = $this->dump_tables($tables);

        return $this->archive_from_sql($sql, $result['tables'], $overrides);
    }

    /**
     * A safety-archive producer for Restore_Site_Backup that archives only
     * $tables, so a rollback in a test restores those and nothing else.
     *
     * @param string[] $tables
     */
    private function safety_producer(array $tables): callable
    {
        return function (array $job) use ($tables): array {
            $file = $this->archive_of($tables);
            return ['file' => $file, 'size' => (int) filesize($file), 'scope' => 'database'];
        };
    }

    /** Remove temporary shadows and fixture files. Call from tearDown(). */
    private function clean_restore_fixtures(): void
    {
        global $wpdb;

        foreach ($this->restored_tables as $table) {
            $wpdb->query('DROP TEMPORARY TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
        $this->restored_tables = [];

        foreach ($this->fixture_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->fixture_files = [];

        wp_cache_flush();
    }
}
