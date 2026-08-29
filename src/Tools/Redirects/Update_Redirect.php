<?php

namespace WPMCP\Tools\Redirects;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Edit one managed redirect in place (issue #128).
 *
 * Every field is optional; only the ones present in the call are changed, so
 * an agent can flip `enabled` without having to restate the target. The
 * snapshot is keyed on the redirect's CURRENT source path, which is what
 * makes a source rename undoable: the captured row still carries the row id,
 * so rolling back restores the old path onto the same row instead of
 * leaving a duplicate behind.
 *
 * Retargeting re-runs chain flattening and loop detection, ignoring this row
 * so it is never considered a hop in its own chain.
 */
class Update_Redirect
{
    public function handle(array $args): array
    {
        $id  = (int) ($args['redirect_id'] ?? 0);
        $row = $id > 0 ? Redirect_Store::get($id) : null;
        if (null === $row) {
            throw new \InvalidArgumentException('Redirect ' . (int) $id . ' not found.');
        }

        $fields = [];
        $source = $row['source_path'];

        if (isset($args['source']) && '' !== trim((string) $args['source'])) {
            $new_source = Redirect_Input::source((string) $args['source']);
            if ($new_source !== $source) {
                $clash = Redirect_Store::find_by_source($new_source);
                if (null !== $clash) {
                    throw new \InvalidArgumentException(sprintf(
                        'Source "%s" is already redirected by redirect #%d.',
                        esc_html($new_source),
                        (int) $clash['id']
                    ));
                }
                $fields['source_path'] = $new_source;
            }
        }

        // Loop/flatten checks always run against the source the row will have
        // AFTER this call, not the one it has now.
        $effective_source = $fields['source_path'] ?? $source;

        $flat = ['flattened' => false, 'chain' => [], 'target' => '', 'target_post_id' => 0];
        $retargeting = isset($args['target']) || isset($args['target_post_id']);
        if ($retargeting) {
            [$target_url, $target_post_id] = Redirect_Input::target($args);
            $flat                          = Redirect_Chain::flatten($effective_source, $target_url, $id);
            if ($flat['flattened']) {
                $target_url     = $flat['target'];
                $target_post_id = $flat['target_post_id'];
            }
            $fields['target_url']     = $target_post_id > 0 ? '' : $target_url;
            $fields['target_post_id'] = $target_post_id;
        } elseif (isset($fields['source_path'])) {
            // The source moved but the target did not: re-check that the row
            // is not now pointing at itself or into a cycle.
            Redirect_Chain::flatten($effective_source, Redirect_Store::resolve_target($row) ?: $row['target_url'], $id);
        }

        if (isset($args['status_code'])) {
            $fields['status_code'] = Redirect_Input::status_code($args);
        }
        if (isset($args['enabled'])) {
            $fields['enabled'] = $args['enabled'] ? 1 : 0;
        }
        if (isset($args['notes'])) {
            $fields['notes'] = Redirect_Input::notes($args, $row['notes']);
        }

        if ([] === $fields) {
            throw new \InvalidArgumentException(
                'Nothing to update: pass at least one of source, target, target_post_id, status_code, enabled, notes.'
            );
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'redirect',
                'object_id'   => $source,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-redirect',
                'args'        => $args,
            ],
            static function () use ($id, $fields): bool {
                Redirect_Store::update($id, $fields);
                return true;
            }
        );

        return [
            'operation_id'      => $out['operation_id'],
            'redirect'          => Redirect_Store::get($id),
            'flattened'         => (bool) $flat['flattened'],
            'flattened_through' => $flat['chain'],
        ];
    }
}
