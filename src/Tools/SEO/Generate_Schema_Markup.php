<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Proposal (read) tool: generate schema.org JSON-LD for a post without
 * writing anything. The agent gets the structure back and decides where it
 * goes (a block, a theme hook, a later set-schema write tool). Because
 * nothing is mutated, this never touches Safe_Mutation, matching the other
 * read-only SEO tools.
 *
 * Registered PRO via the ability tier (Registrar enforces Pro\Gate
 * centrally), per the tiering in issue #67: generation tools are pro, the
 * existing post-meta surface stays free.
 *
 * TODO(#67): companion generate-meta-tags proposal tool on the same pattern.
 * TODO(#67): set-social-image snapshot-first write via Safe_Mutation.
 */
class Generate_Schema_Markup
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $type = (string) ($args['schema_type'] ?? 'Article');

        $schema = Schema_Generator::generate($post_id, $type);

        return [
            'post_id'         => $post_id,
            'schema_type'     => $type,
            'schema'          => $schema,
            'json_ld'         => wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'supported_types' => Schema_Generator::SUPPORTED_TYPES,
        ];
    }
}
