<?php

namespace WPMCP\Tools\Meta;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a single wp_options value by name. Refuses the same Option_Guard
 * denylist as Get_Option (sensitive/core option names), and is disabled by
 * default: unlike the allowlisted Update_Settings tool, this writes to any
 * option name, so a site must explicitly opt in with
 * add_filter('wpmcp_enable_option_write', '__return_true') before any write
 * is allowed at all, matching the disabled-by-default pattern used for
 * other broad/destructive tools (delete-post force-delete, raw DB writes).
 *
 * The write routes through Safe_Mutation with object_type 'option', reusing
 * the existing option snapshot/rollback path (Update_Settings already
 * exercises this same object_type), so it is undoable via
 * rollback-operation regardless of whether the option previously existed.
 */
class Update_Option
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_option_write', false);
    }

    public function handle(array $args): array
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('An option name is required.');
        }

        if (Option_Guard::is_denylisted($name)) {
            throw new \RuntimeException("Refusing to write sensitive option \"{$name}\".");
        }

        if (! self::is_enabled()) {
            throw new \RuntimeException('Option writes are disabled. Enable them with the wpmcp_enable_option_write filter.');
        }

        $value = $args['value'] ?? '';

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => $name,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-option',
                'args'        => $args,
            ],
            function () use ($name, $value): void {
                update_option($name, $value);
            }
        );

        return [
            'name'         => $name,
            'value'        => get_option($name),
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
