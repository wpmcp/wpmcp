<?php

namespace WPMCP\Tools\Compose;

use WPMCP\Tools\Elementor\Element_Id;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builder-dialect composition (PRO): turn a validated build-page node tree
 * (see Page_Spec) into an `_elementor_data` element tree. A pure transform —
 * ids come from Element_Id (Elementor's own 7-char hex format), container
 * settings and widget_settings are carried over verbatim as data (Elementor
 * interprets them when rendering; nothing is evaluated here), and the
 * result is written by Build_Page through the same single-operation safety
 * path as the Gutenberg dialect.
 */
class Elementor_Composer
{
    /**
     * @param array[] $sections Normalized top-level nodes from Page_Spec.
     * @return array{elements: array, count: int}
     */
    public static function compose(array $sections): array
    {
        $count    = 0;
        $elements = [];
        foreach ($sections as $node) {
            $elements[] = self::element($node, $count);
        }

        return ['elements' => $elements, 'count' => $count];
    }

    private static function element(array $node, int &$count): array
    {
        $count++;

        if ('widget' === $node['type']) {
            return [
                'id'         => Element_Id::generate(),
                'elType'     => 'widget',
                'widgetType' => (string) $node['settings']['widget'],
                'settings'   => (array) ($node['settings']['widget_settings'] ?? []),
                'elements'   => [],
            ];
        }

        $children = [];
        foreach ($node['children'] as $child) {
            $children[] = self::element($child, $count);
        }

        return [
            'id'       => Element_Id::generate(),
            'elType'   => $node['type'],
            'settings' => $node['settings'],
            'elements' => $children,
        ];
    }
}
