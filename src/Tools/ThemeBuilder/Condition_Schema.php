<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Display-condition schema and matcher for theme-builder templates
 * (issue #70). A condition set is {include: rule[], exclude: rule[]} where a
 * rule is {type, value?}. v1 ships the coarse free-tier rule types; granular
 * conditions (per-taxonomy-term, per-post, user role) are PRO and land behind
 * Pro\Gate in a later slice.
 */
class Condition_Schema
{
    /** Rule type => specificity weight (higher wins; entire_site is the floor). */
    public const RULE_TYPES = [
        'entire_site' => 0,
        'archive'     => 10,
        'search'      => 10,
        'error_404'   => 10,
        'front_page'  => 20,
        'post_type'   => 20,
        'singular'    => 30,
    ];

    /** @return true|\WP_Error */
    public static function validate(array $conditions)
    {
        foreach (['include', 'exclude'] as $key) {
            $rules = $conditions[$key] ?? [];
            if (! is_array($rules)) {
                return new \WP_Error('wpmcp_invalid_conditions', sprintf('"%s" must be an array of rules.', $key));
            }
            foreach ($rules as $rule) {
                if (! is_array($rule) || ! isset($rule['type']) || ! array_key_exists((string) $rule['type'], self::RULE_TYPES)) {
                    return new \WP_Error(
                        'wpmcp_invalid_conditions',
                        sprintf('Each rule needs a "type" from: %s.', implode(', ', array_keys(self::RULE_TYPES)))
                    );
                }
            }
        }
        if (empty($conditions['include'])) {
            return new \WP_Error('wpmcp_invalid_conditions', 'A condition set needs at least one include rule.');
        }
        return true;
    }

    /**
     * Specificity of a whole condition set: the weight of its most specific
     * matching include rule. First leg of the deterministic winner order
     * (specificity > priority > id).
     */
    public static function specificity(array $conditions, array $context): int
    {
        $best = -1;
        foreach ((array) ($conditions['include'] ?? []) as $rule) {
            if (self::rule_matches($rule, $context)) {
                $best = max($best, self::RULE_TYPES[(string) $rule['type']]);
            }
        }
        return $best;
    }

    /** True when at least one include rule matches and no exclude rule does. */
    public static function matches(array $conditions, array $context): bool
    {
        foreach ((array) ($conditions['exclude'] ?? []) as $rule) {
            if (self::rule_matches($rule, $context)) {
                return false;
            }
        }
        return self::specificity($conditions, $context) >= 0;
    }

    /**
     * $context is a normalized request description: {is_front_page, is_404,
     * is_search, is_archive, is_singular, post_type, post_id}. Resolve_Template
     * builds it from tool args; the render adapters (TODO) will build it from
     * the live main query.
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
                return isset($context['post_type']) && (string) ($rule['value'] ?? '') === (string) $context['post_type'];
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
