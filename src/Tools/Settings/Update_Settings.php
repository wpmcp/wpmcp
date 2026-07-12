<?php

namespace WPMCP\Tools\Settings;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Batch-update WordPress options from the Settings_Registry allowlist.
 *
 * Every option that is actually changed is routed individually through
 * Safe_Mutation::run() with object_type 'option', so each write is
 * snapshotted before it happens and can be rolled back via
 * rollback-operation. Keys that fail validation (not allowlisted, read-only,
 * invalid enum/permalink value) are skipped and reported, not silently
 * dropped: the valid subset of the request still applies (partial failure
 * does not abort the whole batch).
 */
class Update_Settings
{
    /** Characters allowed in a permalink structure: WP rewrite tags, letters, digits, and /-_. */
    private const PERMALINK_PATTERN = '/^[A-Za-z0-9\/\-_%]*$/';

    public function handle(array $args): array
    {
        $settings = $args['settings'] ?? null;
        if (! is_array($settings) || [] === $settings) {
            throw new \InvalidArgumentException('No settings provided to update.');
        }

        $updated         = [];
        $skipped         = [];
        $rewrite_flushed = false;
        $operation_ids   = [];

        foreach ($settings as $key => $raw_value) {
            $key    = (string) $key;
            $result = $this->apply_one($key, $raw_value);

            if (isset($result['skip'])) {
                $skipped[] = ['key' => $key, 'reason' => $result['skip']];
                continue;
            }

            $updated[ $key ] = $result['value'];
            if (isset($result['operation_id'])) {
                $operation_ids[] = $result['operation_id'];
            }
            if ('permalink_structure' === $key) {
                $rewrite_flushed = true;
            }
        }

        return [
            'updated'         => $updated,
            'skipped'         => $skipped,
            'rewrite_flushed' => $rewrite_flushed,
            'operation_ids'   => $operation_ids,
        ];
    }

    /**
     * Validate/coerce one key => value pair, then (if valid and actually
     * changing anything) write it through Safe_Mutation. Returns either
     * ['skip' => reason] or ['value' => coerced value, 'operation_id' => ...].
     */
    private function apply_one(string $key, $raw_value): array
    {
        if (! Settings_Registry::has($key)) {
            return ['skip' => 'not allowlisted'];
        }

        $meta = Settings_Registry::get($key);
        if (! $meta['writable']) {
            return ['skip' => 'read-only'];
        }

        $coerced = $this->validate($meta, $key, $raw_value);
        if (null !== $coerced && $coerced['ok'] === false) {
            return ['skip' => $coerced['reason']];
        }

        $value = $coerced['value'];

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => $key,
                'session_id'  => 'default',
                'tool_name'   => 'update-settings',
                'args'        => [$key => $raw_value],
            ],
            function () use ($key, $value): void {
                update_option($key, $value);
                if ('permalink_structure' === $key) {
                    flush_rewrite_rules();
                }
            }
        );

        return ['value' => $value, 'operation_id' => $out['operation_id']];
    }

    /**
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private function validate(array $meta, string $key, $raw_value): array
    {
        switch ($meta['type']) {
            case 'enum':
                if (! in_array($raw_value, $meta['options'], true)) {
                    return ['ok' => false, 'reason' => 'invalid value'];
                }
                return ['ok' => true, 'value' => $raw_value];

            case 'int':
                $int = (int) $raw_value;
                $int = max($meta['min'], min($meta['max'], $int));
                return ['ok' => true, 'value' => $int];

            case 'bool':
                return ['ok' => true, 'value' => (bool) $raw_value];

            case 'string':
                if ('permalink_structure' === $key) {
                    return $this->validate_permalink((string) $raw_value);
                }
                return ['ok' => true, 'value' => sanitize_text_field((string) $raw_value)];

            default:
                return ['ok' => true, 'value' => $raw_value];
        }
    }

    private function validate_permalink(string $value): array
    {
        $sanitized = sanitize_text_field($value);
        if ('' !== $sanitized && ! preg_match(self::PERMALINK_PATTERN, $sanitized)) {
            return ['ok' => false, 'reason' => 'unsafe permalink structure'];
        }
        return ['ok' => true, 'value' => $sanitized];
    }
}
