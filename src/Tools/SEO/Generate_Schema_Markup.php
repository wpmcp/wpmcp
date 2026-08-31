<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Proposal (read) tool: generate schema.org JSON-LD for a post without
 * writing anything. The agent gets the encoded graph back and decides where
 * it goes (a block, a theme hook, a later set-schema write tool). Because
 * nothing is mutated, this never touches Safe_Mutation, matching the other
 * read-only SEO tools.
 *
 * The payload is returned only in its encoded form. It exists to be pasted
 * inside a <script type="application/ld+json"> block, so it is encoded with
 * the HTML-significant characters hexed: an author-controlled post title
 * containing a closing script tag must not be able to end that block.
 *
 * Registration is a paid-tier surface per the tiering in issue #67
 * (generation tools are paid; the existing post-meta surface stays free).
 * The tier is declared on the Ability and enforced centrally in the
 * Registrar, not re-checked here.
 *
 * TODO(#67): companion generate-meta-tags proposal tool on the same pattern.
 * TODO(#67): set-social-image snapshot-first write via Safe_Mutation.
 */
class Generate_Schema_Markup
{
    /**
     * Encoding flags for a payload destined for an inline script block.
     * Slash escaping stays on (default), and the tag/amp/quote/apostrophe
     * hexing means no HTML-significant character survives into the string.
     */
    private const ENCODE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        // edit_posts is a surface-level capability, not a per-post one. A
        // proposal for an unpublished, private or password-protected post
        // returns its title, description, dates and author, so the caller
        // must be able to read that post directly. Post_Access is the one
        // gate the whole SEO group shares.
        Post_Access::assert_readable($post_id);

        $type = (string) ($args['schema_type'] ?? 'Article');

        $json_ld = wp_json_encode(Schema_Generator::generate($post_id, $type), self::ENCODE_FLAGS);
        if (! is_string($json_ld)) {
            throw new \RuntimeException(
                'Could not encode the schema graph for post ' . (int) $post_id . '.'
            );
        }

        return [
            'post_id'     => $post_id,
            'schema_type' => $type,
            'json_ld'     => $json_ld,
        ];
    }
}
