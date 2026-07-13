<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: for a single registered block name, return its full attributes
 * schema, declared supports, and block-context wiring (uses_context and
 * provides_context) from WP_Block_Type_Registry.
 */
class Get_Block_Type
{
    public function handle(array $args): array
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';

        $registry   = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered($name);

        if (! $block_type) {
            throw new \InvalidArgumentException("Block type \"{$name}\" is not registered.");
        }

        return [
            'name'             => $name,
            'title'            => (string) ($block_type->title ?? ''),
            'category'         => (string) ($block_type->category ?? ''),
            'is_dynamic'       => is_callable($block_type->render_callback ?? null),
            'attributes'       => is_array($block_type->attributes ?? null) ? $block_type->attributes : [],
            'supports'         => is_array($block_type->supports ?? null) ? $block_type->supports : [],
            'uses_context'     => is_array($block_type->uses_context ?? null) ? $block_type->uses_context : [],
            'provides_context' => is_array($block_type->provides_context ?? null) ? $block_type->provides_context : [],
        ];
    }
}
