<?php

namespace WPMCP\Tools\Settings;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

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
            // A UTC offset given as timezone_string also sets gmt_offset.
            $updated = array_merge($updated, $result['also'] ?? []);
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

        $value  = $coerced['value'];
        $writes = $coerced['writes'] ?? [ $key => $value ];
        $also   = array_diff_key($writes, [ $key => true ]);

        // Skip the write (and its snapshot) when the coerced value is
        // identical to what's already stored: nothing would actually
        // change, so routing it through Safe_Mutation::run() would only
        // waste a snapshot row (and a free-tier retention slot) undoing a
        // no-op. The key is still reported back as applied/unchanged.
        if ($this->unchanged($writes)) {
            return ['value' => $value, 'also' => $also];
        }

        // A locale that is not installed yet gets its language pack first;
        // if that cannot happen, nothing is written (issue #472).
        if ('WPLANG' === $key) {
            $refusal = Site_Locale::ensure_installed((string) $value);
            if (null !== $refusal) {
                return ['skip' => $refusal];
            }
        }

        // A timezone change spans timezone_string and gmt_offset, so both are
        // snapshotted together and a rollback restores the pair.
        $timezone = in_array($key, Site_Locale::TIMEZONE_OPTIONS, true);

        $out = Safe_Mutation::run(
            [
                'object_type' => $timezone ? 'option_set' : 'option',
                'object_id'   => $timezone ? Snapshot::option_set_id(Site_Locale::TIMEZONE_OPTIONS) : $key,
                'session_id'  => 'default',
                'tool_name'   => 'update-settings',
                'args'        => [$key => $raw_value],
            ],
            function () use ($key, $writes): void {
                foreach ($writes as $name => $new_value) {
                    update_option($name, $new_value);
                }
                if ('permalink_structure' === $key) {
                    flush_rewrite_rules();
                }
            }
        );

        return ['value' => $value, 'also' => $also, 'operation_id' => $out['operation_id']];
    }

    /** True when every option in $writes already holds its new value. */
    private function unchanged(array $writes): bool
    {
        foreach ($writes as $name => $new_value) {
            $current = get_option($name);
            if ('gmt_offset' === $name ? ! is_numeric($current) || (float) $current !== $new_value : $current !== $new_value) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private function validate(array $meta, string $key, $raw_value): array
    {
        if ('WPLANG' === $key) {
            return Site_Locale::validate_locale($raw_value);
        }
        if (in_array($key, Site_Locale::TIMEZONE_OPTIONS, true)) {
            return Site_Locale::validate_timezone($key, $raw_value);
        }
        if ('date_format' === $key || 'time_format' === $key) {
            return Site_Locale::validate_format($raw_value);
        }

        switch ($meta['type']) {
            case 'enum':
                if (! in_array($raw_value, $meta['options'], true)) {
                    return ['ok' => false, 'reason' => 'invalid value'];
                }
                return ['ok' => true, 'value' => $raw_value];

            case 'int':
                if (! empty($meta['strict'])) {
                    return $this->validate_strict_int($meta, $raw_value);
                }
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

    /** A whole number inside the entry's min/max, refused (not clamped) otherwise. */
    private function validate_strict_int(array $meta, $raw_value): array
    {
        $reason = sprintf('must be a whole number from %d to %d', $meta['min'], $meta['max']);
        if (is_bool($raw_value) || ! is_numeric($raw_value) || (float) $raw_value !== floor((float) $raw_value)) {
            return ['ok' => false, 'reason' => $reason];
        }
        $int = (int) $raw_value;
        if ($int < $meta['min'] || $int > $meta['max']) {
            return ['ok' => false, 'reason' => $reason];
        }
        return ['ok' => true, 'value' => $int];
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
