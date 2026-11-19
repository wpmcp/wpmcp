<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Blocksy Companion extensions (issue #650) as two ops on the theme pair,
 * offered while Blocksy is the active theme family:
 *
 *  - list-blocksy-extensions (theme-read): every extension the companion
 *    knows, with its name, description, whether it is a Pro one, whether
 *    this site can switch it on, and whether it is on.
 *  - set-blocksy-extensions (theme-write): switch extensions on (true) or
 *    off (false). Each change goes through the companion's own
 *    Blocksy\ExtensionsManager (activate_extension / deactivate_extension),
 *    exactly as its dashboard does, so the extension boots and Blocksy's
 *    dynamic CSS is rebuilt. An unknown id, or one the site has no code or
 *    plan for, refuses the whole batch before any write. Only ids whose
 *    state really changes are written, and the companion's option
 *    (blocksy_active_extensions) is snapshotted first, so rollback-operation
 *    puts the set back exactly; Theme_Framework_Pack::refresh_after_restore
 *    then rebuilds the dynamic CSS as the forward write did.
 *
 * Verified against Blocksy Companion 2.1.62. The Pro content blocks
 * (ct_content_block) are not here: they ship only in the paid companion.
 */
final class Theme_Blocksy_Extensions
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** The companion's own option holding the active extension ids. */
    public const OPTION = 'blocksy_active_extensions';

    private const PLUGIN_FILE = 'blocksy-companion/blocksy-companion.php';

    /** Upper bound on one toggle batch (the companion ships about twenty). */
    private const MAX_BATCH = 40;

    /** @return array<string,array<string,mixed>> */
    public static function operations(?string $family): array
    {
        if ('blocksy' !== $family) {
            return [];
        }
        $base = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::requirement(),
        ];

        return [
            'list-blocksy-extensions' => $base + [
                'mode'         => 'read',
                'description'  => 'List the Blocksy Companion extensions (id, name, description, pro, available, active); available false means the site lacks the code or plan to switch it on',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::read(),
            ],
            'set-blocksy-extensions'  => $base + [
                'mode'               => 'write',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
                'opt_in_filter'      => 'wpmcp_enable_theme_write',
                'description'        => 'Switch Blocksy Companion extensions on (true) or off (false) by id, through the companion\'s own extension manager. An unknown or unavailable id refuses the batch before any write. Snapshotted for rollback-operation',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'extensions' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'maxProperties'        => self::MAX_BATCH,
                            'additionalProperties' => [ 'type' => 'boolean' ],
                        ],
                    ],
                    'required'   => [ 'extensions' ],
                ],
                'validate'           => static fn (array $args): ?array => self::refusal(static fn () => self::plan((array) ($args['extensions'] ?? []))),
                'snapshot'           => static fn (array $args): ?array => [] === self::plan((array) ($args['extensions'] ?? []))
                    ? null
                    : [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'            => static fn (array $args): array => self::write((array) $args['extensions']),
            ],
        ];
    }

    /** Rebuild Blocksy's dynamic CSS, as the companion does after a toggle. */
    public static function refresh(): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking Blocksy's own dynamic CSS refresh action, not defining a hook.
        do_action('blocksy:dynamic-css:refresh-caches');
    }

    /**
     * The companion's extension manager, or null when it is not loaded.
     * Filterable (wpmcp_blocksy_extensions_manager) for sites and tests.
     */
    private static function manager(): ?object
    {
        $manager = null;
        if (class_exists('\\Blocksy\\Plugin') && method_exists('\\Blocksy\\Plugin', 'instance')) {
            $plugin  = \Blocksy\Plugin::instance();
            $manager = is_object($plugin) && isset($plugin->extensions) && is_object($plugin->extensions) ? $plugin->extensions : null;
        }
        $manager = apply_filters('wpmcp_blocksy_extensions_manager', $manager);
        foreach ([ 'get_extensions', 'activate_extension', 'deactivate_extension' ] as $method) {
            if (! is_object($manager) || ! method_exists($manager, $method)) {
                return null;
            }
        }
        return $manager;
    }

    /** @return true|array{code:string,message:string,data:array<string,string>} */
    private static function requirement()
    {
        return null === self::manager() ? Plugin_Refusal::make('blocksy_companion_inactive', 'Blocksy Companion', self::PLUGIN_FILE) : true;
    }

    /** @return string[] the ids the companion has on, as stored. */
    private static function active_ids(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_values(array_unique(array_map('strval', $stored))) : [];
    }

    /**
     * Every extension the companion knows, by id, keyed rows without the
     * icon markup.
     *
     * @return array<string,array{id:string,name:string,description:string,pro:bool,available:bool,active:bool}>
     */
    private static function extensions(): array
    {
        $manager = self::manager();
        if (null === $manager) {
            throw new Operation_Refused('blocksy_companion_inactive', 'Blocksy Companion is not active on this site.');
        }
        $plan   = function_exists('blocksy_companion_get_capabilities') && is_object(blocksy_companion_get_capabilities())
            ? (string) blocksy_companion_get_capabilities()->get_plan()
            : 'free';
        $active = self::active_ids();
        $rows   = [];
        foreach ((array) $manager->get_extensions([ 'require_config' => true ]) as $id => $ext) {
            $ext    = (array) $ext;
            $config = (array) ($ext['config'] ?? []);
            if (! empty($config['hidden'])) {
                continue;
            }
            $id         = (string) $id;
            $pro        = ! empty($config['pro']);
            $rows[ $id ] = [
                'id'          => $id,
                'name'        => wp_strip_all_tags((string) ($config['name'] ?? $id)),
                'description' => wp_strip_all_tags((string) ($config['description'] ?? '')),
                'pro'         => $pro,
                // The companion boots a Pro extension only off the free plan,
                // and lists one it has no code for with no path.
                'available'   => ! empty($ext['path']) && ! ($pro && 'free' === $plan),
                'active'      => in_array(strtolower($id), $active, true),
            ];
        }
        ksort($rows);
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function read(): array
    {
        $rows = self::extensions();
        return [
            'extensions' => array_values($rows),
            'active'     => array_values(array_keys(array_filter($rows, static fn (array $row): bool => $row['active']))),
        ];
    }

    /**
     * The changes a batch would make, id => on, checked whole first.
     *
     * @param array<string,mixed> $wanted
     * @return array<string,bool>
     * @throws Operation_Refused
     */
    private static function plan(array $wanted): array
    {
        $rows    = self::extensions();
        $unknown = array_values(array_diff(array_map('strval', array_keys($wanted)), array_keys($rows)));
        if ([] !== $unknown) {
            throw new Operation_Refused('unknown_extension', sprintf('Blocksy Companion has no extension "%s". list-blocksy-extensions gives the ids.', implode('", "', $unknown)), [ 'unknown' => $unknown, 'extensions' => array_keys($rows) ]);
        }
        $changes = [];
        foreach ($wanted as $id => $on) {
            $id = (string) $id;
            if (! is_bool($on)) {
                throw new Operation_Refused('invalid_args', sprintf('extensions.%s is true (on) or false (off).', $id), [ 'extension' => $id ]);
            }
            if ($on === $rows[ $id ]['active']) {
                continue;
            }
            if ($on && ! $rows[ $id ]['available']) {
                throw new Operation_Refused('extension_unavailable', sprintf('Blocksy Companion extension "%s" is a Pro extension this site has no code or plan for, so it cannot be switched on.', $id), [ 'extension' => $id ]);
            }
            $changes[ $id ] = $on;
        }
        return $changes;
    }

    /** @return array<string,mixed> */
    private static function write(array $wanted): array
    {
        $changes = self::plan($wanted);
        $manager = self::manager();
        foreach ($changes as $id => $on) {
            if ($on) {
                $manager->activate_extension($id);
            } else {
                $manager->deactivate_extension($id);
            }
        }
        return [ 'changed' => $changes, 'active' => self::read()['active'] ];
    }

    private static function refusal(callable $plan): ?array
    {
        try {
            $plan();
        } catch (Operation_Refused $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
        return null;
    }
}
