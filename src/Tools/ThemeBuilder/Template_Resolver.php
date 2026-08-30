<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic winner resolution for theme-builder templates (issue #70):
 * among the active templates of a part type whose conditions match the
 * context, the winner is picked by specificity desc, then priority desc,
 * then lowest id. The full considered list is returned so the resolve tool
 * can report WHY a template won.
 */
class Template_Resolver
{
    /**
     * @return array{winner: ?array, considered: array<int,array>}
     */
    public static function resolve(string $part_type, array $context): array
    {
        $considered = [];
        foreach (Template_Store::all(true, $part_type) as $template) {
            $matches      = Condition_Schema::matches($template['conditions'], $context);
            $considered[] = [
                'template_id' => $template['template_id'],
                'title'       => $template['title'],
                'matches'     => $matches,
                'specificity' => $matches ? Condition_Schema::specificity($template['conditions'], $context) : null,
                'priority'    => $template['priority'],
            ];
        }

        $candidates = array_values(array_filter($considered, fn (array $c): bool => $c['matches']));
        usort($candidates, function (array $a, array $b): int {
            return [$b['specificity'], $b['priority'], $a['template_id']]
                <=> [$a['specificity'], $a['priority'], $b['template_id']];
        });

        return [
            'winner'     => $candidates[0] ?? null,
            'considered' => $considered,
        ];
    }
}
