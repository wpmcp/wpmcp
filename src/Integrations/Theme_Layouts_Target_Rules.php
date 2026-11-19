<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The display rules of the target rules library that Astra Pro and
 * Ultimate Addons for Elementor (header-footer-elementor) both ship as
 * Astra_Target_Rules_Fields (issues #656, #651): a location and an exclusion,
 * each {rule: [...], specific: [...]} in get_format_rule_value()'s stored
 * shape, plus a list of user rules. Only the meta keys differ per add-on.
 * Used by Theme_Layouts_Adapter subclasses.
 */
trait Theme_Layouts_Target_Rules
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /**
     * @param mixed $stored
     * @return array{rule:array<int,string>,specific:array<int,string>}
     */
    private static function stored_target($stored): array
    {
        return [
            'rule'     => is_array($stored) && is_array($stored['rule'] ?? null) ? array_values($stored['rule']) : [],
            'specific' => is_array($stored) && is_array($stored['specific'] ?? null) ? array_values($stored['specific']) : [],
        ];
    }

    /**
     * One location or exclusion, in get_format_rule_value()'s stored shape.
     *
     * @param mixed $value
     * @return array{rule:array<int,string>,specific:array<int,string>}
     */
    private static function target($value, string $key, string $vocabulary_op): array
    {
        if (! is_array($value)) {
            throw self::bad_rules(sprintf('"%s" is {rule: [...], specific: [...]}.', $key));
        }
        $rule     = array_values(array_unique(array_map('strval', self::list_of($value['rule'] ?? [], $key . '.rule'))));
        $specific = array_values(array_unique(array_map('strval', self::list_of($value['specific'] ?? [], $key . '.specific'))));
        foreach ($rule as $r) {
            self::check_target_rule($r, $vocabulary_op);
        }
        foreach ($specific as $s) {
            if (1 !== preg_match('/^(post-\d+|tax-\d+(-single-[a-z0-9_-]+)?)$/', $s)) {
                throw self::bad_rules(sprintf('Specific target "%s" is not post-<id>, tax-<term_id> or tax-<term_id>-single-<taxonomy>.', $s));
            }
        }
        if ([] !== $specific && ! in_array('specifics', $rule, true)) {
            $rule[] = 'specifics';
        }
        if (in_array('specifics', $rule, true) && [] === $specific) {
            throw self::bad_rules(sprintf('"%s" names the specifics rule but no specific targets.', $key));
        }
        return [ 'rule' => $rule, 'specific' => $specific ];
    }

    /**
     * User rules: all, logged-in, logged-out or a role on this site.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private static function target_users($value): array
    {
        $users = [];
        foreach (self::list_of($value, 'users') as $user) {
            $user = (string) $user;
            if (! in_array($user, [ 'all', 'logged-in', 'logged-out' ], true) && ! self::role_exists($user)) {
                throw self::bad_rules(sprintf('User rule "%s" is not all, logged-in, logged-out or a role on this site.', $user));
            }
            $users[] = $user;
        }
        return array_values(array_unique($users));
    }

    private static function check_target_rule(string $rule, string $vocabulary_op): void
    {
        $special = [ 'special-404', 'special-search', 'special-blog', 'special-front', 'special-date', 'special-author', 'special-woo-shop' ];
        if (in_array($rule, [ 'basic-global', 'basic-singulars', 'basic-archives', 'specifics' ], true) || in_array($rule, $special, true)) {
            return;
        }
        if (1 !== preg_match('/^([a-z0-9_-]+)\|all(\|archive|\|taxarchive\|([a-z0-9_-]+))?$/', $rule, $m)) {
            throw self::bad_rules(sprintf('"%s" is not a display rule (see %s).', $rule, $vocabulary_op));
        }
        self::check_post_type($m[1], $rule);
        if (isset($m[3])) {
            self::check_taxonomy($m[3], $rule);
        }
    }

    /** The rule format the library takes, for rule_vocabulary(). */
    private static function target_format(): string
    {
        return 'Rules: basic-global, basic-singulars, basic-archives, special-404|search|blog|front|date|author|woo-shop, <post_type>|all, <post_type>|all|archive, <post_type>|all|taxarchive|<taxonomy>, and specifics with specific entries post-<id>, tax-<term_id> or tax-<term_id>-single-<taxonomy>. Users: all, logged-in, logged-out or a role.';
    }
}
