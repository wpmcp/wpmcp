<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Display-condition schema and matcher for theme-builder templates
 * (issue #70). A condition set is {include: rule[], exclude: rule[]} where a
 * rule is {type, value?}. The location rule types ship with the engine; the
 * granular ones (term, user_role) are the licensed part of the tier split and
 * are checked in granular_rules_allowed(), the one place that reads the
 * licence.
 *
 * validate() is strict on purpose. A rule that is accepted but can never
 * match produces a template that is silently invisible forever, which is a
 * worse failure than a rejected create call: the agent gets no signal and the
 * site owner sees nothing rendered.
 */
class Condition_Schema
{
    /**
     * Rule type => specificity weight (higher wins; entire_site is the floor).
     * user_role narrows the audience rather than the location, so it only
     * outranks entire_site: a role-targeted site-wide header beats the
     * generic one, and loses to any location rule. term sits between a post
     * type and a single post, because a term selects a subset of posts.
     */
    public const RULE_TYPES = [
        'entire_site' => 0,
        'user_role'   => 5,
        'archive'     => 10,
        'search'      => 10,
        'error_404'   => 10,
        'front_page'  => 20,
        'post_type'   => 20,
        'term'        => 25,
        'singular'    => 30,
    ];

    /** The granular rule types (issue #70 tier split). */
    public const GRANULAR_RULE_TYPES = ['term', 'user_role'];

    /** Rule types that carry no value at all. A value on one of these is a typo. */
    public const VALUELESS_TYPES = ['entire_site', 'archive', 'search', 'error_404', 'front_page'];

    /** @return true|\WP_Error */
    public static function validate(array $conditions)
    {
        foreach (['include', 'exclude'] as $key) {
            $rules = $conditions[$key] ?? [];
            if (! is_array($rules)) {
                return new \WP_Error('wpmcp_invalid_conditions', sprintf('"%s" must be an array of rules.', $key));
            }
            foreach ($rules as $rule) {
                $checked = self::validate_rule($rule);
                if (is_wp_error($checked)) {
                    return $checked;
                }
            }
        }
        if (empty($conditions['include'])) {
            return new \WP_Error('wpmcp_invalid_conditions', 'A condition set needs at least one include rule.');
        }
        return true;
    }

    /**
     * @param mixed $rule
     *
     * @return true|\WP_Error
     */
    private static function validate_rule($rule)
    {
        if (! is_array($rule) || ! isset($rule['type']) || ! is_string($rule['type']) || ! array_key_exists($rule['type'], self::RULE_TYPES)) {
            return new \WP_Error(
                'wpmcp_invalid_conditions',
                sprintf('Each rule needs a "type" from: %s.', implode(', ', array_keys(self::RULE_TYPES)))
            );
        }

        $unknown = array_diff(array_keys($rule), ['type', 'value']);
        if ([] !== $unknown) {
            return new \WP_Error(
                'wpmcp_invalid_conditions',
                sprintf('Unknown rule key(s): %s. A rule is {type, value?}.', implode(', ', array_map('strval', $unknown)))
            );
        }

        $type      = $rule['type'];
        $has_value = array_key_exists('value', $rule) && null !== $rule['value'];

        if (in_array($type, self::VALUELESS_TYPES, true) && $has_value) {
            return new \WP_Error(
                'wpmcp_invalid_conditions',
                sprintf('The "%s" rule takes no "value".', $type)
            );
        }

        if ('post_type' === $type) {
            if (! $has_value || ! is_string($rule['value']) || '' === trim($rule['value'])) {
                return new \WP_Error(
                    'wpmcp_invalid_conditions',
                    'The "post_type" rule needs a non-empty string "value" (the post type slug).'
                );
            }
        }

        if ('term' === $type) {
            $value = $rule['value'] ?? null;
            if (! (is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) {
                return new \WP_Error(
                    'wpmcp_invalid_conditions',
                    'The "term" rule needs a positive integer term id as "value".'
                );
            }
        }

        if ('user_role' === $type) {
            if (! $has_value || ! is_string($rule['value']) || '' === trim($rule['value'])) {
                return new \WP_Error(
                    'wpmcp_invalid_conditions',
                    'The "user_role" rule needs a non-empty string "value" (the role slug).'
                );
            }
        }

        if (in_array($type, self::GRANULAR_RULE_TYPES, true) && ! self::granular_rules_allowed()) {
            return new \WP_Error(
                'wpmcp_granular_condition',
                sprintf('The "%s" rule needs a licensed install; the location rule types are always available.', $type)
            );
        }

        if ('singular' === $type && $has_value) {
            $value = $rule['value'];
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return new \WP_Error(
                    'wpmcp_invalid_conditions',
                    'The "singular" rule takes an integer post id as "value", or no value to match any singular view.'
                );
            }
            if ((int) $value < 1) {
                return new \WP_Error('wpmcp_invalid_conditions', 'The "singular" rule\'s post id must be positive.');
            }
        }

        return true;
    }

    /**
     * Whether this install may store the granular rule types. Checked on
     * write only: a template already stored keeps rendering if the licence
     * later lapses, the same way the per-part-type cap never unpublishes a
     * template that was created under it.
     */
    public static function granular_rules_allowed(): bool
    {
        return Gate::can_use('site-part-granular-conditions');
    }

    /**
     * Specificity of a whole condition set: the weight of its most specific
     * matching include rule. First leg of the deterministic winner order
     * (specificity > priority > id).
     */
    public static function specificity(array $conditions, array $context): int
    {
        $best = -1;
        foreach (self::rules($conditions, 'include') as $rule) {
            if (self::rule_matches($rule, $context)) {
                $best = max($best, self::RULE_TYPES[(string) $rule['type']]);
            }
        }
        return $best;
    }

    /** True when at least one include rule matches and no exclude rule does. */
    public static function matches(array $conditions, array $context): bool
    {
        foreach (self::rules($conditions, 'exclude') as $rule) {
            if (self::rule_matches($rule, $context)) {
                return false;
            }
        }
        return self::specificity($conditions, $context) >= 0;
    }

    /**
     * The well-formed rules under one key. Condition sets are read back out
     * of postmeta, which a direct meta write (or a future update tool) can
     * put anything into, so a malformed entry is skipped here rather than
     * reaching rule_matches(array $rule) and raising a TypeError on the
     * front end.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rules(array $conditions, string $key): array
    {
        $rules = $conditions[$key] ?? [];
        if (! is_array($rules)) {
            return [];
        }
        return array_values(array_filter(
            $rules,
            static fn ($rule): bool => is_array($rule) && isset($rule['type']) && array_key_exists((string) $rule['type'], self::RULE_TYPES)
        ));
    }

    /**
     * $context is a normalized request description: {is_front_page, is_404,
     * is_search, is_archive, is_singular, post_type, post_id, term_ids,
     * user_roles}. Resolve_Site_Part
     * builds it from tool args; Render\Template_Renderer builds it from the
     * live main query.
     */
    private static function rule_matches(array $rule, array $context): bool
    {
        $type = (string) ($rule['type'] ?? '');
        switch ($type) {
            case 'entire_site':
                return true;
            case 'front_page':
                return ! empty($context['is_front_page']);
            case 'error_404':
                return ! empty($context['is_404']);
            case 'search':
                return ! empty($context['is_search']);
            case 'archive':
                return ! empty($context['is_archive']);
            case 'post_type':
                $wanted = (string) ($rule['value'] ?? '');
                return '' !== $wanted && isset($context['post_type']) && $wanted === (string) $context['post_type'];
            case 'term':
                $term_ids = is_array($context['term_ids'] ?? null) ? array_map('intval', $context['term_ids']) : [];
                return in_array((int) ($rule['value'] ?? 0), $term_ids, true);
            case 'user_role':
                $roles = is_array($context['user_roles'] ?? null) ? array_map('strval', $context['user_roles']) : [];
                return in_array((string) ($rule['value'] ?? ''), $roles, true);
            case 'singular':
                if (empty($context['is_singular'])) {
                    return false;
                }
                $value = $rule['value'] ?? null;
                return null === $value || (int) $value === (int) ($context['post_id'] ?? 0);
            default:
                return false;
        }
    }
}
