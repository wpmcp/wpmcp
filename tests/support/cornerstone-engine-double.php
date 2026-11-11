<?php

/**
 * A stand-in for the Cornerstone (Themeco) calls Cornerstone_Engine makes
 * (issue #646), for the suite without the commercial plugin. The
 * definitions are a few of Cornerstone 7.8.12's, recorded from the real
 * plugin in the live Cornerstone leg (title, group, valid_children,
 * valid_parent). save() stores what Content::save() was seen to store
 * there: the element list as JSON with unescaped slashes and no element
 * ids, `_cornerstone_override` and `_cs_generated_tss` dropped, and the
 * page rendered into post_content between cs-content comments (here a
 * stand-in rendering of each element's type and text).
 */

namespace WPMCP\Tests\Support;

final class Cornerstone_Engine_Double
{
    public const DEFINITIONS = [
        'region'        => ['title' => '', 'group' => null, 'valid_children' => ['*' => '*', 'content' => '*', 'right' => 'bar', 'bottom' => 'bar', 'left' => 'bar'], 'valid_parent' => null, 'in_library' => false],
        'section'       => ['title' => 'Section', 'group' => 'layout', 'valid_children' => '*', 'valid_parent' => 'region', 'in_library' => true],
        'layout-row'    => ['title' => 'Row', 'group' => 'layout', 'valid_children' => 'layout-column', 'valid_parent' => null, 'in_library' => true],
        'layout-column' => ['title' => 'Column', 'group' => 'layout', 'valid_children' => '*', 'valid_parent' => 'layout-row', 'in_library' => true],
        'layout-div'    => ['title' => 'Div', 'group' => 'layout', 'valid_children' => '*', 'valid_parent' => null, 'in_library' => true],
        'headline'      => ['title' => 'Headline', 'group' => 'content', 'valid_children' => null, 'valid_parent' => null, 'in_library' => true],
        'text'          => ['title' => 'Text', 'group' => 'content', 'valid_children' => null, 'valid_parent' => null, 'in_library' => true],
        'button'        => ['title' => 'Button', 'group' => 'interactive', 'valid_children' => null, 'valid_parent' => null, 'in_library' => true],
    ];

    /** Whether Cornerstone's permissions let the user edit the page. */
    public bool $allow = true;

    /** @var array<int,array> every list saved, in order */
    public array $saved = [];

    public function definition(string $type): ?array
    {
        return isset(self::DEFINITIONS[ $type ]) ? ['type' => $type] + self::DEFINITIONS[ $type ] : null;
    }

    public function catalog(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $type => $definition) {
            if ($definition['in_library']) {
                $out[] = ['type' => $type, 'title' => $definition['title'], 'group' => $definition['group']];
            }
        }

        return $out;
    }

    public function schema(string $type): ?array
    {
        $base = $this->definition($type);
        if (null === $base) {
            return null;
        }

        return $base + [
            'inline'       => 'headline' === $type ? ['text_content', 'text_subheadline_content'] : [],
            'defaults'     => 'headline' === $type ? ['text_content' => 'Short and Sweet Headlines are Best!', 'text_tag' => 'h1'] : [],
            'designations' => 'headline' === $type ? ['text_content' => 'markup:seo', 'text_tag' => 'markup'] : [],
        ];
    }

    public function allowed(int $post_id): bool
    {
        return $this->allow;
    }

    public function save(int $post_id, array $elements): void
    {
        $elements      = self::strip_ids($elements);
        $this->saved[] = $elements;
        update_post_meta($post_id, '_cornerstone_data', wp_slash((string) wp_json_encode($elements, JSON_UNESCAPED_SLASHES)));
        delete_post_meta($post_id, '_cornerstone_override');
        delete_post_meta($post_id, '_cs_generated_tss');
        wp_update_post(['ID' => $post_id, 'post_content' => wp_slash('<!-- cs-content -->' . self::render($elements) . '<!-- cs-content-end -->')]);
    }

    private static function strip_ids(array $elements): array
    {
        foreach ($elements as $i => $element) {
            unset($element['_id'], $element['_region']);
            if (isset($element['_modules']) && is_array($element['_modules'])) {
                $element['_modules'] = self::strip_ids($element['_modules']);
            }
            $elements[ $i ] = $element;
        }

        return $elements;
    }

    private static function render(array $elements): string
    {
        $out = '';
        foreach ($elements as $element) {
            $out .= '<div class="x-' . $element['_type'] . '">' . ($element['text_content'] ?? '') . self::render($element['_modules'] ?? []) . "</div>\n";
        }

        return $out;
    }
}
