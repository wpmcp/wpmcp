<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Update_Blocks
{
    public function handle(array $args): array
    {
        $id     = (int) ($args['id'] ?? 0);
        $blocks = (string) ($args['blocks'] ?? '');
        if (! $id || ! get_post($id)) {
            throw new \InvalidArgumentException('Page not found');
        }
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-blocks',
                'args'        => $args,
            ],
            function () use ($id, $blocks) {
                wp_update_post(['ID' => $id, 'post_content' => $blocks]);
                return true;
            },
            function () use ($blocks) {
                $parsed = parse_blocks($blocks);
                foreach ($parsed as $b) {
                    if (null === $b['blockName'] && '' !== trim($b['innerHTML']) && ! str_contains($blocks, '<!-- wp:')) {
                        return false;
                    }
                }
                return true;
            }
        );
        return ['operation_id' => $out['operation_id'], 'id' => $id];
    }
}
