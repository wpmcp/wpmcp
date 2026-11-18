<?php

namespace WPMCP\MCP;

use WPMCP\Governance\Opt_In_Gates;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tool annotations and requirement notes on the wire (issue #630).
 *
 * Every Ability computes readOnlyHint, destructiveHint and idempotentHint,
 * and clients use them to decide which calls can run in parallel and which
 * are safe to retry, but registration never handed them to the adapter.
 * They are added here, at the wire, with Output_Schemas, rather than as
 * ability meta: the adapter copies the label (our description) into
 * annotations.title whenever ability meta carries annotations, which would
 * send every description twice.
 *
 * openWorldHint is not sent: nothing computes it yet, and the spec's
 * default (true) is safer than a guessed false.
 *
 * A tool with a site-level requirement also says so twice:
 *
 *  - _meta["com.wpmcp-pro/requires"], the structured form, for clients.
 *  - A short suffix on the description ("Off until the site enables
 *    wpmcp_enable_db_writes."), for the model, at most SUFFIX_MAX bytes and
 *    only where the description does not already name the requirement.
 *
 * The requirements come from requires(): the default-off opt-in gate
 * (Opt_In_Gates) and, once abilities declare one (issue #627), an
 * Ability::$requires array with the same keys. Per-user requirements
 * (capability, identity scope) are left out on purpose: tools/list must
 * not describe one user's grants to another.
 */
final class Tool_Annotations
{
    public const META_KEY   = 'com.wpmcp-pro/requires';
    public const SUFFIX_MAX = 120;

    /**
     * The MCP annotations for one ability.
     *
     * @return array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool}
     */
    public static function annotations(Ability $ability): array
    {
        return [
            'readOnlyHint'    => $ability->read_only_hint,
            'destructiveHint' => $ability->destructive_hint,
            'idempotentHint'  => $ability->idempotent_hint,
        ];
    }

    /**
     * The site-level requirements of one ability. Keys: opt_in (filter
     * name), constant, multisite, plugin (slug => label). The tier is not
     * one: a Pro tool is only listed where Pro is active, so on the wire
     * it could never be the reason a call fails, and naming it on some
     * 200 tools would cost about 7 KB a listing for nothing.
     *
     * @return array<string,mixed>
     */
    public static function requires(Ability $ability): array
    {
        $requires = [];
        if (property_exists($ability, 'requires') && is_array($ability->requires)) {
            $requires = $ability->requires;
            unset($requires['tier']);
        }
        $filter = Opt_In_Gates::filter_for($ability->name);
        if (null !== $filter && ! isset($requires['opt_in'])) {
            $requires['opt_in'] = $filter;
        }

        return $requires;
    }

    /**
     * The description suffix for a set of requirements, or ''.
     *
     * @param array<string,mixed> $requires
     */
    public static function suffix(array $requires): string
    {
        $parts = [];
        if (! empty($requires['plugin']) && is_array($requires['plugin'])) {
            $parts[] = 'Needs: ' . implode(', ', array_map('strval', $requires['plugin'])) . ' active.';
        }
        if (! empty($requires['multisite'])) {
            $parts[] = 'Needs multisite.';
        }
        if (! empty($requires['opt_in']) && is_string($requires['opt_in'])) {
            $parts[] = 'Off until the site enables ' . $requires['opt_in'] . '.';
        }
        if (! empty($requires['constant']) && is_string($requires['constant'])) {
            $parts[] = 'Off while ' . $requires['constant'] . ' is set.';
        }

        $suffix = implode(' ', $parts);
        if (strlen($suffix) > self::SUFFIX_MAX) {
            $suffix = rtrim(substr($suffix, 0, self::SUFFIX_MAX - 3), " ,.") . '...';
        }

        return $suffix;
    }

    /**
     * $description with the requirement suffix, unless it already names
     * every requirement the suffix would state.
     *
     * @param array<string,mixed> $requires
     */
    public static function described(string $description, array $requires): string
    {
        $missing = [];
        foreach ([ 'plugin', 'multisite', 'opt_in', 'constant' ] as $key) {
            if (empty($requires[ $key ])) {
                continue;
            }
            if ('plugin' === $key) {
                $labels = is_array($requires['plugin']) ? array_map('strval', $requires['plugin']) : [];
                $unnamed = array_filter($labels, static fn (string $label): bool => false === stripos($description, $label));
                if ([] !== $unnamed) {
                    $missing['plugin'] = $unnamed;
                }
                continue;
            }
            $named = 'multisite' === $key ? 'multisite' : (string) $requires[ $key ];
            if (false === stripos($description, $named)) {
                $missing[ $key ] = $requires[ $key ];
            }
        }

        $suffix = self::suffix($missing);
        if ('' === $suffix) {
            return $description;
        }
        $description = rtrim($description);
        if ('' === $description) {
            return $suffix;
        }

        return $description . (str_ends_with($description, '.') ? ' ' : '. ') . $suffix;
    }

    /**
     * One wire tool entry with the annotations, the _meta requirement and
     * the description suffix added. What the entry already carries wins:
     * annotations someone else set and an existing _meta key are kept.
     *
     * @param array<string,mixed> $tool
     * @return array<string,mixed>
     */
    public static function apply(array $tool, Ability $ability): array
    {
        if (! isset($tool['annotations'])) {
            $tool['annotations'] = self::annotations($ability);
        }

        $requires = self::requires($ability);
        if ([] === $requires) {
            return $tool;
        }

        $meta = isset($tool['_meta']) && is_array($tool['_meta']) ? $tool['_meta'] : [];
        if (! isset($meta[ self::META_KEY ])) {
            $meta[ self::META_KEY ] = $requires;
            $tool['_meta']          = $meta;
        }

        if (isset($tool['description']) && is_string($tool['description'])) {
            $tool['description'] = self::described($tool['description'], $requires);
        }

        return $tool;
    }
}
