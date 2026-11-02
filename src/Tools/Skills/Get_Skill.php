<?php

namespace WPMCP\Tools\Skills;

use WPMCP\Skills\Skill_Library;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: load one skill's full instructions by slug (issue #74).
 *
 * Returns the SKILL.md body exactly as it is on disk, byte for byte below the
 * frontmatter block (only the blank lines surrounding it are trimmed).
 * Nothing is truncated, reflowed, or summarized: oversized documents are
 * refused at discovery time
 * (Skill_Library::MAX_FILE_BYTES) precisely so that everything the catalog
 * lists can be served verbatim, and an agent that caches a body keyed by
 * `version` knows it holds the real thing.
 *
 * Every failure is a structured WP_Error carrying the data an agent needs to
 * recover on its own:
 *  - wpmcp_skill_slug_required: no slug given.
 *  - wpmcp_skill_not_found: unknown slug, with the known slugs attached so
 *    the agent can retry without a second list-skills round trip.
 *  - wpmcp_skill_locked: the skill declares `tier: pro` and this site is not
 *    licensed. The catalog entry stays visible, only the body is withheld.
 *
 * The slug is a lookup key into the scanned catalog, never a path fragment:
 * see Skill_Library::get().
 */
class Get_Skill
{
    /** Cap on the slug list attached to a not-found error, to bound the payload. */
    public const MAX_SUGGESTIONS = 50;

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function handle(array $args)
    {
        $slug = isset($args['slug']) && is_string($args['slug']) ? trim($args['slug']) : '';
        if ('' === $slug) {
            return new \WP_Error(
                'wpmcp_skill_slug_required',
                'A skill slug is required, e.g. {"slug":"wpmcp-safe-writes"}. Use wpmcp/list-skills to discover slugs.'
            );
        }

        $skill = Skill_Library::get($slug);
        if (null === $skill) {
            return new \WP_Error(
                'wpmcp_skill_not_found',
                sprintf('No skill named "%s" is installed on this site.', $slug),
                [
                    'slug'            => $slug,
                    'available_slugs' => array_slice(
                        array_keys(Skill_Library::index()),
                        0,
                        self::MAX_SUGGESTIONS
                    ),
                ]
            );
        }

        if (true === ($skill['locked'] ?? false)) {
            return new \WP_Error(
                'wpmcp_skill_locked',
                \WPMCP\MCP\Pro_Refusal::message(sprintf('The skill "%s"', $slug)),
                [
                    'slug' => $slug,
                    'tier' => 'pro',
                ]
            );
        }

        unset($skill['locked']);

        return $skill;
    }
}
