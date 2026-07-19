<?php

namespace WPMCP\Tools\Compose;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Strict structural validator for the declarative build-page spec (#57).
 *
 * The spec is a single JSON document the agent submits once; everything is
 * checked here, BEFORE any write, and every rejection is addressed by node
 * path (e.g. "spec.content[1].children[0]"). Validation is pure: no DB
 * reads or writes. Referential checks that need live site state (menu
 * exists, attachment exists, pattern registered, Elementor widget known)
 * live in Build_Page::preflight(), which also runs before any write.
 *
 * Top-level shape (both dialects):
 *   title    string, required, non-empty
 *   status   'draft' | 'publish' (default 'draft')
 *   slug     string, optional
 *   dialect  'gutenberg' (default, free) | 'elementor' (PRO)
 *   content  node[], required, non-empty — the sections tree
 *   media    { featured: attachment_id } optional
 *   menu     { menu_id: int required, title?: string, position?: int, parent?: int } optional
 *
 * Every node is { type: string, settings?: object, children?: node[] }.
 *
 * Gutenberg dialect node types:
 *   containers: group, columns (children must be column), column (only
 *               inside columns), buttons (children must be button)
 *   leaves:     heading{text, level 1-6}, paragraph{text}, list{items[],
 *               ordered?}, quote{text, citation?}, image{attachment_id|url,
 *               alt?}, button{text, url?}, separator{}, spacer{height 1-500},
 *               code{text}, html{html}, pattern{slug} (top level only)
 *
 * Elementor dialect node types:
 *   containers: container, section, column (settings passed through to the
 *               element verbatim — Elementor's settings vocabulary is its own)
 *   leaf:       widget{widget: string, widget_settings?: object}
 *
 * Bounds (no unbounded payloads): MAX_BYTES on the JSON-encoded spec,
 * MAX_SECTIONS top-level sections, MAX_NODES total nodes, MAX_DEPTH nesting.
 */
class Page_Spec
{
    public const MAX_BYTES    = 262144; // 256 KiB of JSON-encoded spec.
    public const MAX_SECTIONS = 40;
    public const MAX_NODES    = 400;
    public const MAX_DEPTH    = 8;

    private const STATUSES = ['draft', 'publish'];
    private const DIALECTS = ['gutenberg', 'elementor'];

    private const TOP_LEVEL_KEYS = ['title', 'status', 'slug', 'dialect', 'content', 'media', 'menu'];
    private const NODE_KEYS      = ['type', 'settings', 'children'];

    /**
     * Allowed settings keys per Gutenberg node type; a null entry means the
     * type is a container (children allowed). Containers deliberately accept
     * no settings: layout intent is expressed by the tree itself, keeping the
     * composed markup deterministic.
     */
    private const GUTENBERG_SETTINGS = [
        'group'     => [],
        'columns'   => [],
        'column'    => [],
        'buttons'   => [],
        'heading'   => ['text', 'level'],
        'paragraph' => ['text'],
        'list'      => ['items', 'ordered'],
        'quote'     => ['text', 'citation'],
        'image'     => ['attachment_id', 'url', 'alt'],
        'button'    => ['text', 'url'],
        'separator' => [],
        'spacer'    => ['height'],
        'code'      => ['text'],
        'html'      => ['html'],
        'pattern'   => ['slug'],
    ];

    private const GUTENBERG_CONTAINERS = ['group', 'columns', 'column', 'buttons'];
    private const ELEMENTOR_CONTAINERS = ['container', 'section', 'column'];

    private int $nodes = 0;

    /**
     * Validate and normalize a raw spec. Throws InvalidArgumentException with
     * a node-path-addressed message on the first violation; returns the
     * normalized spec (defaults applied) when the whole document is valid.
     */
    public static function validate($raw): array
    {
        return (new self())->run($raw);
    }

    private function run($raw): array
    {
        if (! is_array($raw) || [] === $raw) {
            $this->reject('spec', 'a non-empty spec object is required');
        }

        $bytes = strlen((string) wp_json_encode($raw));
        if ($bytes > self::MAX_BYTES) {
            $this->reject('spec', sprintf('spec is too large (%d bytes; the limit is %d)', $bytes, self::MAX_BYTES));
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array((string) $key, self::TOP_LEVEL_KEYS, true)) {
                $this->reject('spec', sprintf('unknown key "%s"', $key));
            }
        }

        $title = trim((string) ($raw['title'] ?? ''));
        if ('' === $title) {
            $this->reject('spec.title', 'a non-empty title is required');
        }

        $status = (string) ($raw['status'] ?? 'draft');
        if (! in_array($status, self::STATUSES, true)) {
            $this->reject('spec.status', 'status must be one of: ' . implode(', ', self::STATUSES));
        }

        $dialect = (string) ($raw['dialect'] ?? 'gutenberg');
        if (! in_array($dialect, self::DIALECTS, true)) {
            $this->reject('spec.dialect', 'dialect must be one of: ' . implode(', ', self::DIALECTS));
        }

        if (! isset($raw['content']) || ! is_array($raw['content']) || [] === $raw['content']) {
            $this->reject('spec.content', 'a non-empty array of section nodes is required');
        }
        if (count($raw['content']) > self::MAX_SECTIONS) {
            $this->reject('spec.content', sprintf('too many top-level sections (%d; the limit is %d)', count($raw['content']), self::MAX_SECTIONS));
        }

        $content = [];
        foreach (array_values($raw['content']) as $i => $node) {
            $content[] = $this->node($node, 'content[' . $i . ']', $dialect, 1, null);
        }

        return [
            'title'   => $title,
            'status'  => $status,
            'slug'    => isset($raw['slug']) ? (string) $raw['slug'] : '',
            'dialect' => $dialect,
            'content' => $content,
            'media'   => $this->media($raw['media'] ?? null),
            'menu'    => $this->menu($raw['menu'] ?? null),
        ];
    }

    private function node($node, string $path, string $dialect, int $depth, ?string $parent_type): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->reject($path, sprintf('nesting depth exceeds the limit of %d', self::MAX_DEPTH));
        }
        if (++$this->nodes > self::MAX_NODES) {
            $this->reject($path, sprintf('the spec exceeds the limit of %d total nodes', self::MAX_NODES));
        }

        if (! is_array($node)) {
            $this->reject($path, 'each node must be an object');
        }
        foreach (array_keys($node) as $key) {
            if (! in_array((string) $key, self::NODE_KEYS, true)) {
                $this->reject($path, sprintf('unknown node key "%s"', $key));
            }
        }

        $type = (string) ($node['type'] ?? '');
        $settings = $node['settings'] ?? [];
        if (! is_array($settings)) {
            $this->reject($path, '"settings" must be an object');
        }

        if ('elementor' === $dialect) {
            return $this->elementor_node($node, $type, $settings, $path, $dialect, $depth);
        }

        return $this->gutenberg_node($node, $type, $settings, $path, $dialect, $depth, $parent_type);
    }

    private function gutenberg_node(array $node, string $type, array $settings, string $path, string $dialect, int $depth, ?string $parent_type): array
    {
        if (! array_key_exists($type, self::GUTENBERG_SETTINGS)) {
            $this->reject($path, sprintf('unknown node type "%s"', $type));
        }

        foreach (array_keys($settings) as $key) {
            if (! in_array((string) $key, self::GUTENBERG_SETTINGS[ $type ], true)) {
                $this->reject($path, sprintf('unknown setting "%s" for node type "%s"', $key, $type));
            }
        }

        $is_container = in_array($type, self::GUTENBERG_CONTAINERS, true);
        if (! $is_container && isset($node['children'])) {
            $this->reject($path, sprintf('node type "%s" may not have children', $type));
        }

        if ('column' === $type && 'columns' !== $parent_type) {
            $this->reject($path, 'a "column" node is only valid as a direct child of "columns"');
        }
        if ('pattern' === $type && null !== $parent_type) {
            $this->reject($path, 'a "pattern" node is only valid at the top level of "content"');
        }

        $this->check_required_settings($type, $settings, $path);

        $children = [];
        if ($is_container) {
            foreach (array_values((array) ($node['children'] ?? [])) as $i => $child) {
                $child_path = $path . '.children[' . $i . ']';
                if ('columns' === $type && 'column' !== (string) (is_array($child) ? ($child['type'] ?? '') : '')) {
                    $this->reject($child_path, 'children of "columns" must be "column" nodes');
                }
                if ('buttons' === $type && 'button' !== (string) (is_array($child) ? ($child['type'] ?? '') : '')) {
                    $this->reject($child_path, 'children of "buttons" must be "button" nodes');
                }
                $children[] = $this->node($child, $child_path, $dialect, $depth + 1, $type);
            }
        }

        return ['type' => $type, 'settings' => $settings, 'children' => $children];
    }

    /** Per-type required/bounded Gutenberg settings, path-addressed. */
    private function check_required_settings(string $type, array $settings, string $path): void
    {
        $text_required = ['heading', 'paragraph', 'quote', 'button', 'code'];
        if (in_array($type, $text_required, true) && '' === trim((string) ($settings['text'] ?? ''))) {
            $this->reject($path, sprintf('node type "%s" requires a non-empty "text" setting', $type));
        }

        if ('heading' === $type && isset($settings['level'])) {
            $level = $settings['level'];
            if (! is_int($level) || $level < 1 || $level > 6) {
                $this->reject($path, '"level" must be an integer between 1 and 6');
            }
        }

        if ('list' === $type) {
            $items = $settings['items'] ?? null;
            if (! is_array($items) || [] === $items) {
                $this->reject($path, 'a "list" node requires a non-empty "items" array');
            }
            foreach ($items as $item) {
                if (! is_scalar($item)) {
                    $this->reject($path, '"items" entries must be strings');
                }
            }
        }

        if ('image' === $type) {
            $has_attachment = isset($settings['attachment_id']) && (int) $settings['attachment_id'] > 0;
            $has_url        = '' !== trim((string) ($settings['url'] ?? ''));
            if (! $has_attachment && ! $has_url) {
                $this->reject($path, 'an "image" node requires an "attachment_id" or a "url"');
            }
        }

        if ('spacer' === $type && isset($settings['height'])) {
            $height = $settings['height'];
            if (! is_int($height) || $height < 1 || $height > 500) {
                $this->reject($path, '"height" must be an integer between 1 and 500');
            }
        }

        if ('html' === $type && '' === trim((string) ($settings['html'] ?? ''))) {
            $this->reject($path, 'an "html" node requires a non-empty "html" setting');
        }

        if ('pattern' === $type && '' === trim((string) ($settings['slug'] ?? ''))) {
            $this->reject($path, 'a "pattern" node requires a non-empty "slug" setting');
        }
    }

    private function elementor_node(array $node, string $type, array $settings, string $path, string $dialect, int $depth): array
    {
        $is_container = in_array($type, self::ELEMENTOR_CONTAINERS, true);

        if (! $is_container && 'widget' !== $type) {
            $this->reject($path, sprintf('unknown builder node type "%s" (expected container, section, column, or widget)', $type));
        }

        if ('widget' === $type) {
            if (isset($node['children'])) {
                $this->reject($path, 'a "widget" node may not have children');
            }
            foreach (array_keys($settings) as $key) {
                if (! in_array((string) $key, ['widget', 'widget_settings'], true)) {
                    $this->reject($path, sprintf('unknown setting "%s" for a "widget" node', $key));
                }
            }
            if ('' === trim((string) ($settings['widget'] ?? ''))) {
                $this->reject($path, 'a "widget" node requires a non-empty "widget" setting (the Elementor widget type)');
            }
            if (isset($settings['widget_settings']) && ! is_array($settings['widget_settings'])) {
                $this->reject($path, '"widget_settings" must be an object');
            }
        }

        $children = [];
        foreach (array_values((array) ($node['children'] ?? [])) as $i => $child) {
            $children[] = $this->node($child, $path . '.children[' . $i . ']', $dialect, $depth + 1, $type);
        }

        return ['type' => $type, 'settings' => $settings, 'children' => $children];
    }

    private function media($media): array
    {
        if (null === $media) {
            return [];
        }
        if (! is_array($media)) {
            $this->reject('spec.media', '"media" must be an object');
        }
        foreach (array_keys($media) as $key) {
            if ('featured' !== (string) $key) {
                $this->reject('spec.media', sprintf('unknown key "%s"', $key));
            }
        }
        if (isset($media['featured']) && (! is_int($media['featured']) || $media['featured'] <= 0)) {
            $this->reject('spec.media', '"featured" must be a positive attachment id (integer)');
        }
        return $media;
    }

    private function menu($menu): array
    {
        if (null === $menu) {
            return [];
        }
        if (! is_array($menu)) {
            $this->reject('spec.menu', '"menu" must be an object');
        }
        foreach (array_keys($menu) as $key) {
            if (! in_array((string) $key, ['menu_id', 'title', 'position', 'parent'], true)) {
                $this->reject('spec.menu', sprintf('unknown key "%s"', $key));
            }
        }
        if (! isset($menu['menu_id']) || ! is_int($menu['menu_id']) || $menu['menu_id'] <= 0) {
            $this->reject('spec.menu', 'a positive integer "menu_id" is required for menu placement');
        }
        return $menu;
    }

    /** @return never */
    private function reject(string $path, string $message): void
    {
        throw new \InvalidArgumentException($path . ': ' . $message);
    }
}
