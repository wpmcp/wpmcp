<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves a caller-supplied archive reference (a job id, or a path) to an
 * absolute path that is provably inside the site-backup directory.
 *
 * This is the security boundary for every tool that reads or deletes an
 * archive. Both of those tools take a path from an MCP client, and an MCP
 * client is driven by a language model acting on text it read somewhere on
 * the site. Without this check, "delete the backup at
 * ../../../wp-config.php" is a working request. Containment is enforced on
 * the realpath (symlinks resolved) against the realpath of the backup
 * directory, because a symlink inside the backup directory would otherwise
 * satisfy a naive string-prefix test while pointing anywhere on disk.
 */
class Archive_Locator
{
    /**
     * @throws \RuntimeException When the reference resolves outside the
     *                           backup directory, or does not exist.
     */
    public static function resolve(array $args): string
    {
        $path = isset($args['path']) ? (string) $args['path'] : '';

        if ('' === $path && isset($args['job_id'])) {
            $job = Backup_Job_Store::get((int) $args['job_id']);
            if (null === $job) {
                throw new \RuntimeException('Unknown backup job.');
            }
            if ('completed' !== $job['status']) {
                throw new \RuntimeException(
                    sprintf('Backup job %d is %s, so it has no archive yet.', (int) $args['job_id'], esc_html((string) $job['status']))
                );
            }
            $path = (string) ($job['result']['file'] ?? '');
            if ('' === $path) {
                throw new \RuntimeException('That backup job recorded no archive file.');
            }
        }

        if ('' === $path) {
            throw new \RuntimeException('Provide either job_id or path.');
        }

        $real = realpath($path);

        if (false === $real || ! is_file($real)) {
            throw new \RuntimeException('No such backup archive.');
        }

        // The current folder, and the uploads one while archives made
        // before WPMCP_BACKUP_DIR was set wait to be moved (issue #846).
        foreach (Site_Backup_Dir::roots() as $dir) {
            $root = realpath($dir);
            if (false !== $root && str_starts_with($real, trailingslashit($root))) {
                return $real;
            }
        }

        throw new \RuntimeException('That path is not inside the site-backup directory.');
    }

    /**
     * Read and decode manifest.json out of an archive without extracting it.
     *
     * @throws \RuntimeException
     */
    public static function read_manifest(string $archive): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to read a backup archive.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('That archive could not be opened; it may be truncated.');
        }

        $raw = $zip->getFromName('manifest.json');
        $zip->close();

        if (false === $raw) {
            throw new \RuntimeException('That archive has no manifest, so it was not produced by this plugin.');
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            throw new \RuntimeException('That archive has an unreadable manifest.');
        }

        return $manifest;
    }
}
