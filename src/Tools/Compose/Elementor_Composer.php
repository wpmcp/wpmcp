<?php

namespace WPMCP\Tools\Compose;

use WPMCP\Tools\Elementor\Atomic_Prop_Schema;
use WPMCP\Tools\Elementor\Atomic_Props;
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
     * Widget nodes naming an Elementor 4.x atomic type (e-heading, e-button,
     * ...) run their widget_settings through the SAME Atomic_Props mapper the
     * add-atomic-* tools use, so a page composed in one call and a page built
     * widget-by-widget end up with byte-identical typed props (issue #137).
     * Every rename, rewrap and refusal is reported back to Build_Page, which
     * surfaces it in the dry-run report and in the build result.
     *
     * @param array[] $sections Normalized top-level nodes from Page_Spec.
     * @return array{elements: array, count: int, coerced: array<int,string>, warnings: array<int,string>}
     */
    public static function compose(array $sections): array
    {
        $count    = 0;
        $coerced  = [];
        $warnings = [];
        $elements = [];

        foreach (array_values($sections) as $i => $node) {
            $elements[] = self::element($node, 'content[' . $i . ']', $count, $coerced, $warnings);
        }

        return ['elements' => $elements, 'count' => $count, 'coerced' => $coerced, 'warnings' => $warnings];
    }

    private static function element(array $node, string $path, int &$count, array &$coerced, array &$warnings): array
    {
        $count++;

        if ('widget' === $node['type']) {
            $widget_type = (string) $node['settings']['widget'];
            $settings    = (array) ($node['settings']['widget_settings'] ?? []);

            if (Atomic_Prop_Schema::known($widget_type)) {
                // build-page composes, it does not gate: an atomic node on a
                // builder that cannot render atomic elements is still written,
                // but the caller is told rather than left to discover a blank
                // section. The atomic write tools refuse outright instead
                // (Atomic_Element::require_supported); build-page has classic
                // siblings in the same tree that must still land.
                if (! \WPMCP\Tools\Elementor\Atomic_Element::is_supported()) {
                    $warnings[] = $path . ': "' . $widget_type . '" is an Elementor 4.0+ atomic widget, and this builder cannot render atomic elements. It was written as composed, but will not display until Elementor 4 is active.';
                }

                $mapped   = Atomic_Props::map($widget_type, $settings);
                $settings = $mapped['settings'];

                foreach ($mapped['coerced'] as $note) {
                    $coerced[] = $path . ': ' . $note;
                }
                foreach ($mapped['warnings'] as $warning) {
                    $warnings[] = $path . ': ' . $warning;
                }
            }

            return [
                'id'         => Element_Id::generate(),
                'elType'     => 'widget',
                'widgetType' => $widget_type,
                'settings'   => $settings,
                'elements'   => [],
            ];
        }

        $children = [];
        foreach (array_values($node['children']) as $i => $child) {
            $children[] = self::element($child, $path . '.children[' . $i . ']', $count, $coerced, $warnings);
        }

        return [
            'id'       => Element_Id::generate(),
            'elType'   => $node['type'],
            'settings' => $node['settings'],
            'elements' => $children,
        ];
    }
}
