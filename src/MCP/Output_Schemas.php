<?php

namespace WPMCP\MCP;

use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tool output schemas (issue #387).
 *
 * Every wpmcp tool answers with structuredContent, so every wpmcp tool
 * declares an outputSchema, and the spec makes that a promise: a server
 * MUST answer with structured results that conform to it. Two kinds:
 *
 *  - The object contract, {"type":"object"}, for the long tail. It is the
 *    one shape every result is guaranteed to have on the wire, because
 *    Structured_Result wraps lists and scalars there. It is cheap (about 32
 *    bytes a tool) and never wrong.
 *  - A detailed contract for the tools whose results are fixed records a
 *    client can act on without parsing text: the backup and CLI job tools,
 *    which are also the tools that run as Tasks.
 *
 * The schemas are applied at the wire, on the tools/list the client sees,
 * and deliberately NOT handed to wp_register_ability() as output_schema:
 * core validates an ability's return value against that schema, and the
 * object contract describes the wire shape, not the value a list-returning
 * ability hands back before Structured_Result wraps it. Keeping them here
 * leaves every ability contract, and every internal caller, unchanged.
 *
 * outputSchema is for the client to validate results with; it is not part
 * of what a model reads to choose a tool, which is what the tools/list
 * byte budget guards. It is capped by its own budget in
 * OutputSchemaConformanceTest instead.
 */
final class Output_Schemas
{
    /** The object contract every wire result satisfies. */
    public const OBJECT = [ 'type' => 'object' ];

    private const JOB_STATUS = [
        'type' => 'string',
        'enum' => [ 'queued', 'running', 'completed', 'failed', 'canceled' ],
    ];

    public function register(): void
    {
        add_filter('mcp_adapter_tools_list', [ $this, 'filter_tools_list' ], 20, 2);
    }

    /**
     * The outputSchema for one ability.
     *
     * @return array<string,mixed>
     */
    public static function for_ability(string $ability_name): array
    {
        return self::detailed()[ $ability_name ] ?? self::OBJECT;
    }

    /**
     * Callback for the adapter's mcp_adapter_tools_list filter: adds the
     * outputSchema to every wpmcp tool that has none. Other servers' tools
     * and entries that cannot be read pass through untouched, as in
     * Tool_Exposure, and a DTO that cannot be rebuilt is kept as it was.
     *
     * @param mixed $tools  Tool DTOs or arrays.
     * @param mixed $server The adapter server (unused).
     * @return mixed
     */
    public function filter_tools_list($tools, $server = null)
    {
        if (! is_array($tools)) {
            return $tools;
        }

        $ours = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $ours[ Tool_Exposure::tool_name($ability->name) ] = $ability->name;
        }

        foreach ($tools as $i => $tool) {
            $tools[ $i ] = self::with_schema($tool, $ours);
        }

        return $tools;
    }

    /**
     * @param mixed                $tool
     * @param array<string,string> $ours Tool name => ability name.
     * @return mixed
     */
    private static function with_schema($tool, array $ours)
    {
        if (is_array($tool)) {
            $name = isset($tool['name']) && is_string($tool['name']) ? $tool['name'] : null;
            if (null !== $name && isset($ours[ $name ]) && ! isset($tool['outputSchema'])) {
                $tool['outputSchema'] = self::for_ability($ours[ $name ]);
            }
            return $tool;
        }

        if (! is_object($tool) || ! method_exists($tool, 'getName') || ! method_exists($tool, 'toArray') || ! method_exists($tool, 'fromArray')) {
            return $tool;
        }

        try {
            $name = $tool->getName();
            if (! is_string($name) || ! isset($ours[ $name ])) {
                return $tool;
            }
            if (method_exists($tool, 'getOutputSchema') && null !== $tool->getOutputSchema()) {
                return $tool;
            }

            // Round-tripped through JSON: toArray() serializes an empty
            // properties map as stdClass, which fromArray() does not take
            // back, while the array form it was built from is accepted.
            $data = json_decode((string) wp_json_encode($tool->toArray()), true);
            if (! is_array($data)) {
                return $tool;
            }
            $data['outputSchema'] = self::for_ability($ours[ $name ]);

            return $tool::fromArray($data);
        } catch (\Throwable $e) {
            // A foreign or changed DTO must never be able to break tools/list.
            return $tool;
        }
    }

    /** @return array<string, array<string,mixed>> ability name => detailed schema. */
    private static function detailed(): array
    {
        $handle = [
            'type'       => 'object',
            'properties' => [
                'job_id' => [ 'type' => 'integer' ],
                'status' => self::JOB_STATUS,
            ],
            'required'   => [ 'job_id', 'status' ],
        ];

        $backup_job = [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer' ],
                'type'       => [ 'type' => 'string' ],
                'scope'      => [ 'type' => 'string' ],
                'status'     => self::JOB_STATUS,
                'created_at' => [ 'type' => 'integer' ],
                'updated_at' => [ 'type' => 'integer' ],
            ],
            'required'   => [ 'id', 'status', 'created_at', 'updated_at' ],
        ];

        $cli_job = [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer' ],
                'command'    => [ 'type' => 'string' ],
                'timeout'    => [ 'type' => 'integer' ],
                'status'     => self::JOB_STATUS,
                'created_at' => [ 'type' => 'integer' ],
                'updated_at' => [ 'type' => 'integer' ],
            ],
            'required'   => [ 'id', 'status', 'created_at', 'updated_at' ],
        ];

        // trigger-backup with every answers with the schedule instead of a
        // job handle (issue #455), so neither half is required there.
        $triggered                          = $handle;
        $triggered['properties']['schedule'] = [ 'type' => 'object' ];
        unset($triggered['required']);

        $backup_jobs                            = self::listing($backup_job);
        $backup_jobs['properties']['schedules'] = [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ];

        $dispatched                          = $handle;
        $dispatched['properties']['command'] = [ 'type' => 'string' ];
        $dispatched['properties']['timeout'] = [ 'type' => 'integer' ];

        return [
            'wpmcp/trigger-backup'    => $triggered,
            'wpmcp/cancel-backup-job' => $handle,
            'wpmcp/get-backup-status' => $backup_job,
            'wpmcp/list-backup-jobs'  => $backup_jobs,
            'wpmcp/dispatch-cli-job'  => $dispatched,
            'wpmcp/cancel-cli-job'    => $handle,
            'wpmcp/get-cli-job'       => $cli_job,
            'wpmcp/list-cli-jobs'     => self::listing($cli_job),
        ];
    }

    /**
     * A list tool's shape: {jobs: [record, ...]}, newest first.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function listing(array $item): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'jobs' => [ 'type' => 'array', 'items' => $item ],
            ],
            'required'   => [ 'jobs' ],
        ];
    }
}
