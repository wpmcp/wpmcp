<?php

namespace WPMCP\Tools\Filesystem;

use WPMCP\Safety\File_Backup;
use WPMCP\Safety\File_Write_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs one write-file, edit-file or delete-file change as an undoable
 * operation (issue #602): the file is backed up into the operation's backup
 * directory, the undo point is saved under the caller's session_id through
 * Safe_Mutation, and only then is the file changed. The operation is listed
 * by list-operations and on the Mutations tab, and rollback-operation and
 * rollback-session restore it (File_Write_Snapshot).
 *
 * A change refused before anything was written (a PHP syntax error, a site
 * check that cannot run, a write the site check reverted) leaves the file as
 * it was, so its undo point and backup are removed again: an operation must
 * never describe a change that did not happen.
 */
class File_Write_Operation
{
    /**
     * @param string   $tool_name The tool, without the wpmcp/ prefix.
     * @param string   $abs       The resolved target path.
     * @param array    $args      The tool call's arguments (session_id, unchecked).
     * @param callable $change    Receives the backup path ('' when the file did
     *                            not exist) and makes the change; its return
     *                            value is handed back as 'result'.
     * @return array{operation_id: string, backup: string, result: mixed}
     */
    public static function run(string $tool_name, string $abs, array $args, callable $change): array
    {
        $operation_id = wp_generate_uuid4();
        $existed      = is_file($abs);
        $before       = $existed ? (string) hash_file('sha256', $abs) : null;

        $backup = File_Write_Snapshot::backup($operation_id, $abs);
        if (null === $backup) {
            File_Backup::delete_backup_dir($operation_id);
            throw new \RuntimeException('Could not back up the file before writing, so nothing was changed.');
        }

        $started = false;
        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => File_Write_Snapshot::TYPE,
                    'object_id'           => $operation_id,
                    'session_id'          => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'           => $tool_name,
                    'args'                => $args,
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => [
                        'path'      => Filesystem_Guard::to_relative($abs),
                        'existed'   => $existed,
                        'unchecked' => ! empty($args['unchecked']),
                    ],
                ],
                static function () use ($change, $backup, &$started) {
                    $started = true;
                    return $change($backup);
                }
            );
        } catch (\Throwable $e) {
            $now       = is_file($abs) ? (string) hash_file('sha256', $abs) : null;
            $unchanged = $now === $before;
            if ($unchanged) {
                if ($started) {
                    Snapshot_Store::delete_operation($operation_id);
                }
                File_Backup::delete_backup_dir($operation_id);
            }
            if (! $started) {
                throw new \RuntimeException(esc_html('The undo point for this file write could not be saved, so nothing was changed: ' . $e->getMessage()));
            }
            if (! $unchanged) {
                // The file did change (a revert that failed): keep the undo
                // point and say how to use it.
                throw new \RuntimeException(esc_html($e->getMessage() . ' Undo point: rollback-operation with operation_id ' . $operation_id . '.'));
            }
            throw $e;
        }

        return ['operation_id' => $out['operation_id'], 'backup' => $backup, 'result' => $out['result']];
    }
}
