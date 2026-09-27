<?php

namespace WPMCP\Tools\Redirects;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create one managed redirect (issue #128).
 *
 * This is the ONLY way a redirect ever comes into existence. Deleting a post
 * or renaming a published slug produces a SUGGESTION (see
 * Redirect_Suggestions) that an agent or a human must turn into an explicit
 * create-redirect call; nothing in the plugin creates a redirect implicitly,
 * so a site's routing never changes as a side effect of a content edit.
 *
 * The write runs through Safe_Mutation with object_type 'redirect', keyed on
 * the source path, so the creation lands in the ordinary operation history
 * and rollback-operation removes it again like any other write.
 *
 * The stored target is the FLATTENED one: if the requested target is itself
 * redirected, the chain is followed to its end (and refused outright if it
 * loops) so visitors never pay for two hops. The response reports both what
 * was asked for and what was stored.
 */
class Create_Redirect
{
    public function handle(array $args): array
    {
        $source      = Redirect_Input::source((string) ($args['source'] ?? ''));
        $status_code = Redirect_Input::status_code($args);
        $notes       = Redirect_Input::notes($args);
        $enabled     = ! isset($args['enabled']) || (bool) $args['enabled'];

        $existing = Redirect_Store::find_by_source($source);
        if (null !== $existing) {
            throw new \InvalidArgumentException(sprintf(
                'Source "%s" is already redirected by redirect #%d; use update-redirect to change it.',
                esc_html($source),
                (int) $existing['id']
            ));
        }

        [$target_url, $target_post_id] = Redirect_Input::target($args);
        $requested_target              = $target_url;

        $flat = Redirect_Chain::flatten($source, $target_url, 0);
        if ($flat['flattened']) {
            $target_url     = $flat['target'];
            $target_post_id = $flat['target_post_id'];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'redirect',
                'object_id'   => $source,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'create-redirect',
                'args'        => $args,
            ],
            static function () use ($source, $target_url, $target_post_id, $status_code, $enabled, $notes): int {
                return Redirect_Store::insert([
                    'source_path'    => $source,
                    'target_url'     => $target_post_id > 0 ? '' : $target_url,
                    'target_post_id' => $target_post_id,
                    'status_code'    => $status_code,
                    'enabled'        => $enabled ? 1 : 0,
                    'notes'          => $notes,
                ]);
            }
        );

        // A suggestion the caller has now acted on is no longer pending.
        Redirect_Suggestions::remove($source);

        return [
            'operation_id'      => $out['operation_id'],
            'redirect'          => Redirect_Store::get((int) $out['result']),
            'effective_target'  => $target_url,
            'flattened'         => $flat['flattened'],
            'requested_target'  => $requested_target,
            'flattened_through' => $flat['chain'],
        ];
    }
}
