<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write seam for Elementor 4 global variables (the v4 design tokens:
 * colors, fonts and sizes).
 *
 * Elementor keeps every variable in one JSON record on the active kit
 * (`_elementor_global_variables`), and deletes are soft (a `deleted_at` stamp),
 * so the record only ever grows. Reads and writes go through Elementor's own
 * Variables_Service, which owns the id minting, the label rules, the storage
 * format conversion and the watermark bump; this class never writes that
 * record itself except to undo.
 *
 * Every write is snapshot-first. The snapshot is a dedicated
 * 'elementor_global_variables' object type holding the RAW meta string (or
 * the fact that there was none), because the service rewrites the whole record
 * and bumps the watermark on each save: replaying a change through the service
 * could never put the record back exactly, while restoring the captured bytes
 * does.
 */
class Global_Variables_Store
{
    public const SERVICE    = '\\Elementor\\Modules\\Variables\\Services\\Variables_Service';
    public const REPOSITORY = '\\Elementor\\Modules\\Variables\\Storage\\Variables_Repository';
    public const PROCESSOR  = '\\Elementor\\Modules\\Variables\\Services\\Batch_Operations\\Batch_Processor';
    public const META_KEY   = '_elementor_global_variables';

    /** Snapshot object type; Rollback_Service::apply_snapshot() dispatches on it. */
    public const SNAPSHOT_TYPE = 'elementor_global_variables';

    /**
     * Elementor 4 with the variables module classes AND the atomic elements
     * feature active: the variables module only boots (renders the CSS custom
     * properties, exposes the editor picker) when that feature is on, so a
     * token written without it would never reach the page.
     */
    public static function is_supported(): bool
    {
        if (! class_exists(self::SERVICE) || ! class_exists(self::REPOSITORY) || ! class_exists(self::PROCESSOR)) {
            return false;
        }

        $experiments = \Elementor\Plugin::$instance->experiments ?? null;

        return is_object($experiments) && $experiments->is_feature_active('e_atomic_elements');
    }

    /**
     * Size variables are an Elementor Pro feature: without Pro the service
     * filters them out of every read, so the editor never shows them.
     */
    public static function sizes_supported(): bool
    {
        return class_exists('\\Elementor\\Utils') && \Elementor\Utils::has_pro();
    }

    /** Elementor's variables REST API requires manage_options for writes. */
    public static function can_edit(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * The active variables (soft-deleted ones excluded), in Elementor's
     * display order, plus the raw record the optimistic lock hashes.
     *
     * @return array{kit_id:int,variables:array<string,array>,raw:string,exists:bool}|\WP_Error
     */
    public static function read()
    {
        $kit_id = Elementor_Kit_Data::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        try {
            $record = self::service($kit_id)->load();
        } catch (\Throwable $e) {
            return new \WP_Error('read_failed', 'Elementor could not read the global variables: ' . $e->getMessage());
        }

        $variables = [];
        foreach ((array) ($record['data'] ?? []) as $id => $variable) {
            $variable = (array) $variable;
            if (! empty($variable['deleted']) || isset($variable['deleted_at'])) {
                continue;
            }
            $variables[(string) $id] = ['id' => (string) $id] + $variable;
        }

        uasort(
            $variables,
            static fn ($a, $b) => [(int) ($a['order'] ?? PHP_INT_MAX), $a['id']] <=> [(int) ($b['order'] ?? PHP_INT_MAX), $b['id']]
        );

        [$exists, $raw] = self::raw($kit_id);

        return ['kit_id' => $kit_id, 'variables' => $variables, 'raw' => $raw, 'exists' => $exists];
    }

    /**
     * Optimistic-lock fingerprint: the stored record's exact bytes. Elementor
     * bumps a watermark on every save, so any write by anyone changes it.
     */
    public static function state_hash(array $state): string
    {
        return hash('sha256', ($state['exists'] ? '1:' : '0:') . $state['raw']);
    }

    /**
     * Read the variables and enforce the shared write preconditions:
     * availability, capability and a matching expected_hash.
     *
     * @return array|\WP_Error the read() state.
     */
    public static function guard(array $args)
    {
        if (! self::is_supported()) {
            return new \WP_Error(
                'unsupported',
                'Global variables need Elementor 4 with atomic elements active; this site does not expose them.'
            );
        }
        if (! self::can_edit()) {
            return new \WP_Error('forbidden', 'You are not allowed to edit Elementor global variables.');
        }

        $state = self::read();
        if (is_wp_error($state)) {
            return $state;
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error(
                'missing_expected_hash',
                '"expected_hash" is required: read the variables with list-global-variables first and pass back its state_hash.'
            );
        }
        if (! hash_equals(self::state_hash($state), $expected)) {
            return new \WP_Error(
                'stale_expected_hash',
                'Stale expected_hash: the global variables changed since they were read. Nothing was written. '
                . 'Re-read with list-global-variables and retry.'
            );
        }

        return $state;
    }

    /**
     * Snapshot the record, run $mutate against Elementor's service, then clear
     * the generated CSS. Any failure, including $verify rejecting what landed,
     * restores the captured bytes so a half-applied write cannot stick.
     *
     * @param callable $mutate fn(Variables_Service): array  Elementor's result.
     * @param callable $verify fn(array $after_state, array $result): bool
     * @return array|\WP_Error
     */
    public static function write(array $before, callable $mutate, callable $verify, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();
        $kit_id       = (int) $before['kit_id'];

        Snapshot_Store::save(
            $operation_id,
            (string) ($args['session_id'] ?? 'default'),
            [
                'object_type' => self::SNAPSHOT_TYPE,
                'object_id'   => $kit_id,
                'data'        => [
                    'kit_id' => $kit_id,
                    'exists' => (bool) $before['exists'],
                    'raw'    => (string) $before['raw'],
                ],
            ],
            $tool_name,
            hash('sha256', (string) wp_json_encode($args))
        );
        Snapshot_Store::prune();

        try {
            $result = (array) $mutate(self::service($kit_id));
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return self::error_from($e);
        }

        self::clear_cache();

        $after = self::read();
        if (is_wp_error($after) || ! $verify($after, $result)) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The write did not store the intended global variables; the previous variables were restored.'
            );
        }

        return [
            'operation_id' => $operation_id,
            'kit_id'       => $kit_id,
            'state_hash'   => self::state_hash($after),
            'result'       => $result,
        ];
    }

    /** Elementor regenerates the kit CSS (where the custom properties live) on next view. */
    public static function clear_cache(): void
    {
        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /** Elementor's service bound to the given kit. */
    private static function service(int $kit_id)
    {
        $kit = \Elementor\Plugin::$instance->kits_manager->get_kit($kit_id);
        if (! is_object($kit) || ! method_exists($kit, 'get_json_meta') || (int) $kit->get_id() !== $kit_id) {
            throw new \RuntimeException('the active kit document could not be loaded');
        }

        $repository = self::REPOSITORY;
        $processor  = self::PROCESSOR;
        $service    = self::SERVICE;

        return new $service(new $repository($kit), new $processor());
    }

    /** @return array{0:bool,1:string} whether the meta row exists, and its raw value. */
    private static function raw(int $kit_id): array
    {
        if (! metadata_exists('post', $kit_id, self::META_KEY)) {
            return [false, ''];
        }

        $value = get_post_meta($kit_id, self::META_KEY, true);

        return [true, is_string($value) ? $value : (string) wp_json_encode($value)];
    }

    /** Map Elementor's storage exceptions to stable error codes. */
    private static function error_from(\Throwable $e): \WP_Error
    {
        $class = (new \ReflectionClass($e))->getShortName();
        $codes = [
            'DuplicatedLabel'       => 'duplicate_label',
            'RecordNotFound'        => 'variable_not_found',
            'VariablesLimitReached' => 'limit_reached',
            'Type_Mismatch'         => 'type_change_forbidden',
            'InvalidVariable'       => 'invalid_variable',
        ];

        return new \WP_Error(
            $codes[$class] ?? 'mutation_failed',
            'Elementor refused the global variable write; the previous variables were restored: ' . $e->getMessage()
        );
    }
}
