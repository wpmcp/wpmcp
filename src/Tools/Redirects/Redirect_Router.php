<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Routes the generic redirect tools to the active redirect plugin (issue
 * #930).
 *
 * Redirect plugins register themselves on the wpmcp_redirect_providers
 * filter as id => [ 'label' => string, 'active' => callable(): bool,
 * 'route' => callable(string $tool, array $args): array|\WP_Error ], so this
 * class names no integration and the builds that prune the integrations
 * simply see no providers. A provider's route() runs the call through that
 * plugin's own pack, which keeps the pack's capability, opt-in, validation
 * and snapshot rules.
 *
 * The choice, made before anything is read or written:
 *  - provider:'wpmcp' always uses WP MCP's own managed table;
 *  - a named provider must be registered and active, or the call is refused;
 *  - with no provider named, the one active redirect plugin is used, WP MCP's
 *    own table when none is active, and the call is refused when two or more
 *    are active, so a redirect never lands in a plugin the caller did not
 *    mean.
 */
final class Redirect_Router
{
    public const BUILTIN = 'wpmcp';

    /** Short schema text for the provider argument the four tools share. */
    public const PROVIDER_HINT = 'wpmcp, redirection or rankmath; default: the one active redirect plugin, else wpmcp';

    /**
     * The routed answer, or null when WP MCP's own table should handle the
     * call.
     *
     * @return array<string,mixed>|\WP_Error|null
     */
    public static function route(string $tool, array $args)
    {
        $chosen = self::choose($args);
        if (null === $chosen || $chosen instanceof \WP_Error) {
            return $chosen;
        }

        [$id, $provider] = $chosen;
        $out = ($provider['route'])($tool, $args);
        if ($out instanceof \WP_Error) {
            $data = $out->get_error_data();
            $out->add_data(( is_array($data) ? $data : [] ) + [ 'provider' => $id, 'builtin_option' => 'Pass provider:"wpmcp" to use WP MCP\'s own redirect table instead.' ]);
            return $out;
        }

        return [ 'provider' => $id ] + (array) $out;
    }

    /**
     * The provider this call goes to: [id, definition], null for WP MCP's own
     * table, or the refusal.
     *
     * @return array{0:string,1:array<string,mixed>}|\WP_Error|null
     */
    private static function choose(array $args)
    {
        $named = isset($args['provider']) ? strtolower(trim((string) $args['provider'])) : '';
        if (self::BUILTIN === $named) {
            return null;
        }

        $providers = self::providers();
        if ('' !== $named) {
            if (! isset($providers[ $named ])) {
                return new \WP_Error('redirect_provider_unknown', sprintf(
                    'Unknown redirect provider "%s". Use one of: %s.',
                    esc_html($named),
                    implode(', ', array_merge([ self::BUILTIN ], array_keys($providers)))
                ), [ 'providers' => array_merge([ self::BUILTIN ], array_keys($providers)) ]);
            }
            if (! self::is_active($providers[ $named ])) {
                return new \WP_Error('redirect_provider_inactive', sprintf(
                    'The %s redirect provider is not active on this site (plugin or its redirect module off). Use one of: %s.',
                    esc_html((string) ($providers[ $named ]['label'] ?? $named)),
                    implode(', ', array_merge([ self::BUILTIN ], self::active_ids($providers)))
                ), [ 'active_providers' => self::active_ids($providers) ]);
            }
            return [ $named, $providers[ $named ] ];
        }

        $active = self::active_ids($providers);
        if ([] === $active) {
            return null;
        }
        if (count($active) > 1) {
            return new \WP_Error('redirect_provider_ambiguous', sprintf(
                'More than one redirect plugin is active (%s), so this call does not pick one. Pass provider: %s.',
                implode(', ', $active),
                implode(', ', array_merge($active, [ self::BUILTIN ]))
            ), [ 'active_providers' => $active ]);
        }

        return [ $active[0], $providers[ $active[0] ] ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function providers(): array
    {
        $out = [];
        foreach ((array) apply_filters('wpmcp_redirect_providers', []) as $id => $def) {
            $id = strtolower((string) $id);
            if (self::BUILTIN !== $id && is_array($def) && is_callable($def['active'] ?? null) && is_callable($def['route'] ?? null)) {
                $out[ $id ] = $def;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $def */
    private static function is_active(array $def): bool
    {
        return (bool) ($def['active'])();
    }

    /**
     * @param array<string,array<string,mixed>> $providers
     * @return string[]
     */
    private static function active_ids(array $providers): array
    {
        return array_values(array_filter(array_keys($providers), static fn (string $id): bool => self::is_active($providers[ $id ])));
    }
}
