<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Edit a theme-builder site part's title, content, conditions or priority
 * (issue #70). Only the fields passed are changed. Snapshot-first through
 * Safe_Mutation: the snapshot records post_content and every
 * _wpmcp_template_* meta row, so the returned operation_id restores all of
 * them through the standard rollback tools.
 *
 * Everything that can be refused is refused before the snapshot is taken:
 * an unknown id, an empty change set, invalid conditions, and a part_type
 * change. Moving a template to another part type would sidestep the
 * per-part-type cap, so that is delete-and-recreate instead.
 */
class Update_Site_Part
{
    private const FIELDS = ['title', 'content', 'conditions', 'priority'];

    public function handle(array $args)
    {
        $id = (int) ($args['template_id'] ?? 0);
        if (! Template_Store::is_template($id)) {
            return new \WP_Error('wpmcp_template_not_found', "No site-part template found with id {$id}.");
        }

        if (array_key_exists('part_type', $args)) {
            return new \WP_Error(
                'wpmcp_part_type_immutable',
                'A site part keeps its part type; delete it (wpmcp/delete-site-part) and create a new one instead.'
            );
        }

        $changes = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $args) && null !== $args[$field]) {
                $changes[$field] = $args[$field];
            }
        }
        if ([] === $changes) {
            return new \WP_Error(
                'wpmcp_nothing_to_update',
                sprintf('Pass at least one of: %s.', implode(', ', self::FIELDS))
            );
        }

        foreach (['title', 'content'] as $text_field) {
            if (array_key_exists($text_field, $changes) && ! is_string($changes[$text_field])) {
                return new \WP_Error('wpmcp_invalid_argument', sprintf('"%s" must be a string.', $text_field));
            }
        }
        if (array_key_exists('priority', $changes) && ! is_numeric($changes['priority'])) {
            return new \WP_Error('wpmcp_invalid_argument', '"priority" must be an integer.');
        }

        if (array_key_exists('conditions', $changes)) {
            if (! is_array($changes['conditions'])) {
                return new \WP_Error('wpmcp_invalid_conditions', '"conditions" must be an object with an include array.');
            }
            $valid = Condition_Schema::validate($changes['conditions']);
            if (is_wp_error($valid)) {
                return $valid;
            }
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'wpmcp/update-site-part',
                'args'        => $args,
            ],
            static function () use ($id, $changes) {
                return Template_Store::update($id, $changes);
            },
            static function ($result): bool {
                return true === $result;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'template'     => Template_Store::get($id),
        ];
    }
}
