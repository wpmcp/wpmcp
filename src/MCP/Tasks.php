<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Backups and background WP-CLI jobs as MCP Tasks (issue #387), through the
 * Tasks extension (io.modelcontextprotocol/tasks) of MCP 2026-07-28.
 *
 * Both tools were already asynchronous: trigger-backup and dispatch-cli-job
 * queue a WP-Cron job, return its id at once, and a status tool polls it.
 * The extension standardizes exactly that shape, so a task here IS a job:
 *
 *  - tools/call on a client that declares the extension returns the job
 *    as a task handle (resultType "task") instead of the synchronous
 *    {job_id, status} result; every other client is unchanged;
 *  - tasks/get reads the job through its status tool and maps the job's
 *    status onto the task lifecycle, carrying the final tool result when
 *    the job completed and the job's error when it failed;
 *  - tasks/cancel withdraws a job that is still queued through its cancel
 *    tool and acknowledges anything else (cancellation is cooperative:
 *    a running job belongs to another request that cannot be signaled);
 *  - tasks/update is acknowledged; these tasks never wait for input.
 *
 * No task state is kept beside the job store, so a task survives the
 * stateless HTTP route, a reconnect and a restart, which is what the
 * extension asks of a durable handle.
 *
 * Every read and cancel goes through the job's own registered ability, so
 * a task is exactly as visible as the job: the same capability, Governance,
 * identity scope and rate limit apply, a caller who cannot read the job
 * directly cannot poll it as a task, and an unknown and a hidden task are
 * the same error. Nothing here names a job store class either, so a build
 * without the CLI job tools simply has no CLI tasks.
 */
final class Tasks
{
    public const EXTENSION = 'io.modelcontextprotocol/tasks';

    /** Suggested polling interval: the jobs run on WP-Cron, not in milliseconds. */
    public const POLL_INTERVAL_MS = 2000;

    /**
     * Seconds a finished CLI job record is kept, when the
     * wpmcp_cli_job_retention_seconds filter does not say otherwise (the
     * CLI job store's default; its purge is what bounds a CLI task's life).
     */
    private const CLI_RETENTION_SECONDS = 86400;

    /** Task kinds: the tool that starts the job and the tools that read and cancel it. */
    private const KINDS = [
        'backup' => [
            'start'  => 'wpmcp/trigger-backup',
            'get'    => 'wpmcp/get-backup-status',
            'cancel' => 'wpmcp/cancel-backup-job',
            'label'  => 'Backup job',
        ],
        'cli'    => [
            'start'  => 'wpmcp/dispatch-cli-job',
            'get'    => 'wpmcp/get-cli-job',
            'cancel' => 'wpmcp/cancel-cli-job',
            'label'  => 'CLI job',
        ],
    ];

    private const TASK_ID_PREFIX = 'wpmcp-';

    /** @param mixed $capabilities Client capabilities from the request _meta. */
    public static function supported($capabilities): bool
    {
        if (is_object($capabilities)) {
            $capabilities = json_decode((string) wp_json_encode($capabilities), true);
        }
        if (! is_array($capabilities) || ! isset($capabilities['extensions'])) {
            return false;
        }

        $extensions = $capabilities['extensions'];

        return is_array($extensions) && array_key_exists(self::EXTENSION, $extensions);
    }

    /** The task kind an ability starts, or null when it starts none. */
    public static function kind_for(string $ability_name): ?string
    {
        foreach (self::KINDS as $kind => $def) {
            if ($def['start'] === $ability_name) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The CreateTaskResult for a job a tool call just queued, or null when
     * the job cannot be read back (the caller then gets the synchronous
     * result instead of a handle it could not poll).
     *
     * @return array<string,mixed>|null
     */
    public static function create_result(string $kind, int $job_id): ?array
    {
        $job = self::read($kind, $job_id);
        if (null === $job) {
            return null;
        }

        return [ 'resultType' => 'task' ] + self::task($kind, $job_id, $job);
    }

    /**
     * Serves tasks/get, tasks/update and tasks/cancel.
     *
     * @param array<string,mixed> $params Request params.
     * @return array{result?:array<string,mixed>,error?:array{code:int,message:string}}
     */
    public static function handle(string $method, array $params): array
    {
        $task_id = isset($params['taskId']) && is_string($params['taskId']) ? $params['taskId'] : '';
        $parsed  = self::parse($task_id);
        $job     = null === $parsed ? null : self::read($parsed[0], $parsed[1]);

        if (null === $parsed || null === $job) {
            return [ 'error' => [ 'code' => Protocol_Revision::INVALID_PARAMS, 'message' => sprintf('Unknown task: %s', $task_id) ] ];
        }

        [ $kind, $job_id ] = $parsed;

        switch ($method) {
            case 'tasks/get':
                return [ 'result' => self::detailed($kind, $job_id, $job) ];
            case 'tasks/cancel':
                if ('queued' === ($job['status'] ?? null)) {
                    // A refusal here means the job moved on meanwhile; the
                    // acknowledgement stands either way.
                    self::run(self::KINDS[ $kind ]['cancel'], [ 'job_id' => $job_id ]);
                }
                return [ 'result' => [] ];
            case 'tasks/update':
                return [ 'result' => [] ];
            default:
                return [ 'error' => [ 'code' => Protocol_Revision::METHOD_NOT_FOUND, 'message' => sprintf('Method not found: %s', $method) ] ];
        }
    }

    /** @return array{0:string,1:int}|null */
    private static function parse(string $task_id): ?array
    {
        if (1 !== preg_match('/^' . preg_quote(self::TASK_ID_PREFIX, '/') . '([a-z]+)-([1-9][0-9]*)$/', $task_id, $m)) {
            return null;
        }
        if (! isset(self::KINDS[ $m[1] ])) {
            return null;
        }

        return [ $m[1], (int) $m[2] ];
    }

    /**
     * The job record through its status tool, or null when it is unknown or
     * the caller may not read it.
     *
     * @return array<string,mixed>|null
     */
    private static function read(string $kind, int $job_id): ?array
    {
        $job = self::run(self::KINDS[ $kind ]['get'], [ 'job_id' => $job_id ]);

        return is_array($job) && isset($job['status']) ? $job : null;
    }

    /**
     * Executes a registered ability with its full permission chain, or
     * returns null when it is not registered on this build.
     *
     * @param array<string,mixed> $input
     * @return mixed
     */
    private static function run(string $ability_name, array $input)
    {
        if (! function_exists('wp_has_ability') || ! wp_has_ability($ability_name)) {
            return null;
        }

        try {
            return wp_get_ability($ability_name)->execute($input);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The Task fields shared by the creation result and tasks/get.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function task(string $kind, int $job_id, array $job): array
    {
        $def     = self::KINDS[ $kind ];
        $status  = (string) $job['status'];
        $created = (int) ($job['created_at'] ?? 0);
        $updated = (int) ($job['updated_at'] ?? $created);

        // Backup job records are never purged. CLI job records are, a
        // retention period after their last update, so that is how long a
        // CLI task stays readable.
        $ttl = null;
        if ('cli' === $kind) {
            $retention = max(0, (int) apply_filters('wpmcp_cli_job_retention_seconds', self::CLI_RETENTION_SECONDS));
            $ttl       = ($retention + max(0, $updated - $created)) * 1000;
        }

        return [
            'taskId'         => self::TASK_ID_PREFIX . $kind . '-' . $job_id,
            'status'         => self::status($status),
            'statusMessage'  => sprintf('%s %d is %s.', $def['label'], $job_id, $status),
            'createdAt'      => gmdate('Y-m-d\TH:i:s\Z', $created),
            'lastUpdatedAt'  => gmdate('Y-m-d\TH:i:s\Z', $updated),
            'ttlMs'          => $ttl,
            'pollIntervalMs' => self::POLL_INTERVAL_MS,
        ];
    }

    /**
     * tasks/get: the task plus, once terminal, what the original tools/call
     * would have returned (completed) or the error that stopped the job
     * (failed).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function detailed(string $kind, int $job_id, array $job): array
    {
        $task = self::task($kind, $job_id, $job);

        if ('completed' === $task['status']) {
            // The start tool's result shape ({job_id, status}) extended with
            // the finished job record, so it conforms to that tool's
            // outputSchema as the extension requires.
            $structured     = Structured_Result::normalize([ 'job_id' => $job_id ] + $job);
            $text           = wp_json_encode($structured);
            $task['result'] = [
                'content'           => [ [ 'type' => 'text', 'text' => false === $text ? '{}' : $text ] ],
                'structuredContent' => $structured,
                'isError'           => false,
            ];
        } elseif ('failed' === $task['status']) {
            $error = $job['error'] ?? '';
            if (! is_string($error)) {
                $encoded = wp_json_encode($error);
                $error   = false === $encoded ? '' : $encoded;
            }
            $message = sprintf('%s %d failed%s', self::KINDS[ $kind ]['label'], $job_id, '' === $error ? '.' : ': ' . $error);

            $task['statusMessage'] = $message;
            $task['error']         = [ 'code' => -32603, 'message' => $message ];
        }

        return $task;
    }

    /** Job status to task status. */
    private static function status(string $job_status): string
    {
        switch ($job_status) {
            case 'completed':
                return 'completed';
            case 'failed':
                return 'failed';
            case 'canceled':
                return 'cancelled';
            default:
                return 'working';
        }
    }
}
