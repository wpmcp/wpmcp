<?php

namespace WPMCP\Tools\Bridge;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;
use WPMCP\Memory\Memory_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The default-off opt-in gate for the third-party ability bridge
 * (issue #194). Silently exposing every ability another plugin registered
 * would be a surprise, not a feature, so the whole bridge surface is closed
 * until a site explicitly opens it: the same constant-plus-filter pattern
 * as Wp_Cli_Guard::is_enabled(), and listed in Opt_In_Gates for the same
 * reason (the ability grid must never appear to open a gate only code can).
 *
 * Opening the gate never widens access:
 *
 *  - a bridged call still runs the target ability's own permission callback
 *    (there is no bypass path, filter or setting; see Execute_Site_Ability),
 *    plus our own governance, rate limiting and audit logging on the bridge
 *    shell itself;
 *  - only abilities the owning plugin marked show_in_rest are reachable.
 *    Registration is not exposure: core defaults show_in_rest to false and
 *    refuses to list or run such an ability over REST/MCP, so the bridge
 *    honours exactly the same flag (is_bridgeable()). An ability kept
 *    internal by its author is treated as if it did not exist here, in
 *    every tool, so hidden names are not enumerable through the bridge.
 *  - a site may narrow the bridge further to named abilities or whole
 *    namespaces (allowlist()), and every bridged ability is governed
 *    individually like one of ours (governance_denial()). Both only ever
 *    take access away, and neither is consulted instead of the target's
 *    own permission callback: they run before it, never in place of it.
 */
class Bridge_Guard
{
    /** The governance domain every bridged ability belongs to. */
    public const DOMAIN = 'bridge';

    /**
     * Whether the site has opted in to bridging third-party abilities.
     * Default (neither the constant nor the filter set) is OFF.
     */
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ENABLE_ABILITY_BRIDGE') && WPMCP_ENABLE_ABILITY_BRIDGE;

        return (bool) apply_filters('wpmcp_enable_ability_bridge', $default);
    }

    /** The WP_Error every bridge tool returns while the gate is closed. */
    public static function disabled_error(): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_bridge_disabled',
            'The third-party ability bridge is disabled on this site (default). Opt in with define(\'WPMCP_ENABLE_ABILITY_BRIDGE\', true) or the wpmcp_enable_ability_bridge filter.'
        );
    }

    /**
     * Whether $name refers to a FOREIGN ability the bridge may touch.
     * Everything in our own namespace is refused: our abilities already have
     * call-tool, and refusing them here keeps the two surfaces disjoint
     * (and keeps the meta-tools and the bridge tools themselves out).
     */
    public static function is_foreign(string $name): bool
    {
        return '' !== $name && ! str_starts_with($name, 'wpmcp/');
    }

    /**
     * Whether a live ability is one the bridge may expose at all: the owning
     * plugin opted it into external clients with meta.show_in_rest, the flag
     * core's own abilities list and run controllers gate on. A hard,
     * unfilterable precondition applied by every bridge tool (listing,
     * schema read and execution agree), so a plugin's internal-only
     * abilities, the ones most likely to carry a lax permission callback
     * because the author assumed in-process callers, never become reachable
     * over HTTP through wpmcp.
     *
     * @param mixed $ability A WP_Ability (or anything else, which is refused).
     */
    public static function is_bridgeable($ability): bool
    {
        return is_object($ability)
            && method_exists($ability, 'get_meta_item')
            && true === $ability->get_meta_item('show_in_rest');
    }

    /**
     * The WP_Error for a name the bridge cannot see. Deliberately the same
     * code and message whether $name is unregistered or registered but not
     * exposed, so probing the bridge by name reveals nothing about hidden
     * abilities.
     */
    public static function unknown_error(string $name): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_bridge_unknown',
            sprintf('No ability named "%s" is registered on this site. Use wpmcp/list-site-abilities to discover names.', $name)
        );
    }

    /**
     * Resolve a foreign name to the live, exposed WP_Ability the bridge may
     * read or execute, or the WP_Error the caller should return unchanged.
     * Callers have already checked the gate and is_foreign().
     *
     * @return \WP_Ability|\WP_Error
     */
    public static function lookup(string $name)
    {
        if (! function_exists('wp_get_ability') || ! function_exists('wp_has_ability')) {
            return new \WP_Error('wpmcp_bridge_unavailable', 'The Abilities API is not available on this site.');
        }

        $ability = wp_has_ability($name) ? wp_get_ability($name) : null;
        if (null === $ability || ! self::is_bridgeable($ability) || ! self::is_allowlisted($name)) {
            return self::unknown_error($name);
        }

        return $ability;
    }

    /**
     * The plugin-ish owner of a foreign ability, derived from its namespace
     * prefix ("yoast/analyze-page" => "yoast"). Best-effort attribution for
     * listings and the audit log.
     */
    public static function owner_of(string $name): string
    {
        $slash = strpos($name, '/');

        return false === $slash ? $name : substr($name, 0, $slash);
    }

    /**
     * The site's per-ability / per-namespace opt-in, or null when the site
     * has not set one (every exposed foreign ability is bridgeable once the
     * site gate is open).
     *
     * Sources, least to most specific: the WPMCP_ABILITY_BRIDGE_ALLOWLIST
     * constant (an array, or a comma-separated string), then the
     * wpmcp_ability_bridge_allowlist filter. Entries are an exact ability
     * name ("yoast/analyze-page"), a namespace wildcard ("yoast/*") or a
     * bare namespace ("yoast"). Anything that is not a list fails closed to
     * an empty allowlist, which bridges nothing: a broken setting must never
     * read as "no restriction".
     *
     * The allowlist narrows only. It never opens the site gate, never admits
     * a wpmcp ability, and never admits one its owner kept off show_in_rest.
     *
     * @return string[]|null
     */
    public static function allowlist(): ?array
    {
        $default = null;
        if (defined('WPMCP_ABILITY_BRIDGE_ALLOWLIST')) {
            $raw     = constant('WPMCP_ABILITY_BRIDGE_ALLOWLIST');
            $default = is_array($raw) ? $raw : explode(',', (string) $raw);
        }

        $list = apply_filters('wpmcp_ability_bridge_allowlist', $default);
        if (null === $list) {
            return null;
        }
        if (! is_array($list)) {
            return [];
        }

        $entries = [];
        foreach ($list as $entry) {
            if (is_string($entry) && '' !== trim($entry)) {
                $entries[] = strtolower(trim($entry));
            }
        }

        return array_values(array_unique($entries));
    }

    /** Whether the site allowlist (if any) admits $name. */
    public static function is_allowlisted(string $name): bool
    {
        $allowlist = self::allowlist();
        if (null === $allowlist) {
            return true;
        }

        $name  = strtolower($name);
        $owner = self::owner_of($name);
        foreach ($allowlist as $entry) {
            if ($entry === $name) {
                return true;
            }
            // "ns/*" and a bare "ns" both mean the whole namespace, matched
            // on the full namespace so "yoast" never admits "yoast-extra/x".
            $namespace = str_ends_with($entry, '/*') ? substr($entry, 0, -2) : $entry;
            if (! str_contains($namespace, '/') && $namespace === $owner && $owner !== $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The governance subject for a foreign ability: a wpmcp Ability that is
     * never registered anywhere (not with the Registrar, not with the
     * Abilities API) and exists only so the six-layer Governance walk,
     * identity scoping and project-memory block rules can decide about a
     * bridged ability exactly as they decide about ours.
     *
     *  - name: the foreign ability's own name, so an ability toggle, the
     *    wpmcp_ability_enabled filter, an identity's abilities list and a
     *    memory "tool:" target all address it directly;
     *  - domain: "bridge", the domain of the three bridge shells, so one
     *    domain toggle closes every bridged ability;
     *  - operation: from the target's own annotations. readonly => read,
     *    destructive => delete, anything else (including no annotations at
     *    all) => update. Foreign code that does not say it is read-only is
     *    never governed as a read.
     *
     * The handler is inert and the capability is the bridge shells' own; the
     * subject grants nothing and is only ever passed to narrowing checks.
     *
     * @param \WP_Ability $ability A live, bridgeable foreign ability.
     */
    public static function governed($ability): Ability
    {
        $annotations = method_exists($ability, 'get_meta_item') ? $ability->get_meta_item('annotations') : null;
        $annotations = is_array($annotations) ? $annotations : [];

        // Contradictory annotations resolve to the narrower reading: a
        // target that claims to be both read-only and destructive is
        // governed as a delete, never as a read.
        $destructive = true === ($annotations['destructive'] ?? null);
        $readonly    = ! $destructive && true === ($annotations['readonly'] ?? null);
        $operation   = $destructive ? 'delete' : ($readonly ? 'read' : 'update');

        return new Ability(
            (string) $ability->get_name(),
            'free',
            method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
            [],
            static fn () => null,
            'edit_posts',
            self::DOMAIN,
            $operation,
            $readonly,
            ! $readonly && false !== ($annotations['destructive'] ?? null),
            true === ($annotations['idempotent'] ?? null)
        );
    }

    /**
     * Why wpmcp governance refuses this bridged ability for the current
     * request, or null when it does not. Mirrors Registrar::is_permitted()'s
     * narrowing layers for our own abilities: the six-layer Governance walk
     * ("governance:<layer>"), the active identity's scope
     * ("identity_scope") and published project-memory block rules
     * ("memory-block:<id>").
     *
     * A null here is not permission. It only means wpmcp adds no refusal of
     * its own; the target's permission callback still decides, inside
     * WP_Ability's own execution path.
     *
     * @param \WP_Ability          $ability A live, bridgeable foreign ability.
     * @param array<string, mixed> $input   The invocation arguments, if any.
     */
    public static function governance_denial($ability, array $input = []): ?string
    {
        $subject = self::governed($ability);

        $explain = Governance::explain($subject);
        if (! $explain['enabled']) {
            return 'governance:' . $explain['layer'];
        }

        if (! Governance::is_within_identity_scope($subject)) {
            return 'identity_scope';
        }

        $rule = Memory_Guard::blocking_rule($subject, $input);
        if (null !== $rule) {
            return 'memory-block:' . (int) $rule['id'];
        }

        return null;
    }

    /** The WP_Error for a bridged ability wpmcp governance refuses. */
    public static function governance_error(string $name, string $reason): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_bridge_governance_denied',
            sprintf('"%s" is disabled for this request by wpmcp governance (%s).', $name, $reason),
            ['reason' => $reason]
        );
    }
}
