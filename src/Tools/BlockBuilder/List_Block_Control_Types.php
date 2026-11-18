<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the attribute types a custom block spec may use, with the block.json
 * type each maps to and a short description, plus the block supports, the
 * reserved attribute names and the template syntax. Read-only.
 */
class List_Block_Control_Types
{
    public function handle(array $args): array
    {
        $types = [];
        foreach (Block_Spec::ATTRIBUTE_TYPES as $type => $meta) {
            $types[] = [
                'type'        => $type,
                'json'        => $meta['json'],
                'description' => $meta['desc'],
            ];
        }
        return [
            'attribute_types' => $types,
            'supports'        => array_keys(Block_Spec::SUPPORTS),
            'reserved_names'  => Block_Spec::RESERVED_NAMES,
            'template_syntax' => [
                '{{name}}'               => 'the attribute, escaped by its type',
                '{{name.key}}'           => 'one key of a media (url, alt, id) or link (url, text, target) value',
                '{{#name}}...{{/name}}'  => 'once per repeater item (its sub-fields in scope), or once when the attribute is truthy',
            ],
            'spec_extras'     => [
                'attribute.help'  => 'help text under the control',
                'attribute.panel' => 'sidebar panel title the control goes in',
                'panels'          => '[{title, help?, opened?}] order and describe the sidebar panels',
                'supports'        => '{color|spacing|typography|border: true or {feature: true}, shadow: true, align: true or [left, center, right, wide, full]}',
            ],
        ];
    }
}
