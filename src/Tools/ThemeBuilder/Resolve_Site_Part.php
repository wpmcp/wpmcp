<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report which site part wins for a given context (issue #70 acceptance
 * criterion): pass a part type plus a context description (is_front_page,
 * is_404, is_search, is_archive, is_singular, post_type, post_id, term_ids,
 * user_roles) and get the winner plus every considered template with its
 * match, specificity, and priority. Read-only.
 *
 * When post_id is given, post_type and term_ids are derived from that post if
 * the caller left them out, so "which header does post 12 get" needs one
 * argument, the same way the live renderer reads them from the main query.
 * Derivation only reads posts the caller could read anyway.
 */
class Resolve_Site_Part
{
    public function handle(array $args)
    {
        $part_type = Template_Store::validate_part_type(trim((string) ($args['part_type'] ?? '')));
        if (is_wp_error($part_type)) {
            return $part_type;
        }

        $context = is_array($args['context'] ?? null) ? $args['context'] : [];

        return Template_Resolver::resolve($part_type, self::normalize_context($context));
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private static function normalize_context(array $context): array
    {
        $post_id = (int) ($context['post_id'] ?? 0);
        if ($post_id < 1 || ! get_post($post_id) || ! current_user_can('read_post', $post_id)) {
            return $context;
        }
        $post_type = (string) get_post_type($post_id);
        if (! isset($context['post_type']) || '' === (string) $context['post_type']) {
            $context['post_type'] = $post_type;
        }
        if (! isset($context['term_ids'])) {
            $context['term_ids'] = Render\Template_Renderer::post_term_ids($post_id, $post_type);
        }
        return $context;
    }
}
