<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Element operations over a BeBuilder layout (issue #643), addressed by the
 * `uid` every section, wrap and item carries. Pure array work: nothing here
 * reads or writes the database.
 *
 * The shape is the one BeTheme 29.0.5 stores (see BeTheme_Cache): sections
 * hold `wraps`, a wrap holds `items`, and an item with `item_is_wrap` is a
 * nested wrap holding `items` (one level deep, as the theme's own uid reset
 * walks it). What a container takes follows from that: the top level ('')
 * takes sections, a section takes wraps, a wrap takes items and nested
 * wraps, a nested wrap takes items.
 *
 * Two kinds of element are rendered from somewhere else, so their contents
 * are refused here: a section with `mfn_global_section_id` and a wrap whose
 * `global_wraps_select` names a template take their children from that
 * template on render. A section in the pre-wraps format (`items` directly
 * on the section) is refused too: BeBuilder converts it when the page is
 * next saved there.
 *
 * Fields of an element's `attr` named css_* are style fields: the theme
 * stores them as {selector, style, val} and builds the page CSS from that.
 * An update that passes a plain value for one keeps its selector and style
 * and sets val; a new one takes its selector and style from $css_def (the
 * theme's field definitions), or must be passed whole.
 */
final class BeTheme_Tree
{
    private const SIZE_FIELDS = ['size', 'laptop_size', 'tablet_size', 'mobile_size'];

    /**
     * The outline a read returns: uid, kind, type and children per element.
     *
     * @param array<int|string,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    public static function outline(array $sections): array
    {
        $out = [];
        foreach (array_values($sections) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $node = ['uid' => (string) ($section['uid'] ?? ''), 'kind' => 'section'];
            if (! empty($section['mfn_global_section_id'])) {
                $node['global'] = (int) $section['mfn_global_section_id'];
            }
            if (! isset($section['wraps']) && isset($section['items'])) {
                $node['legacy'] = true;
            }
            $node['children'] = [];
            foreach (array_values((array) ($section['wraps'] ?? [])) as $wrap) {
                if (is_array($wrap)) {
                    $node['children'][] = self::outline_wrap($wrap, 'wrap');
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function outline_wrap(array $wrap, string $kind): array
    {
        $node = ['uid' => (string) ($wrap['uid'] ?? ''), 'kind' => $kind, 'size' => (string) ($wrap['size'] ?? '')];
        $global = (int) ($wrap['attr']['global_wraps_select'] ?? 0);
        if ($global > 0) {
            $node['global'] = $global;
        }
        $node['children'] = [];
        foreach (array_values((array) ($wrap['items'] ?? [])) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['item_is_wrap'])) {
                $node['children'][] = self::outline_wrap($item, 'nested_wrap');
                continue;
            }
            $leaf  = ['uid' => (string) ($item['uid'] ?? ''), 'kind' => 'item', 'type' => (string) ($item['type'] ?? ''), 'size' => (string) ($item['size'] ?? '')];
            $label = self::label($item);
            if ('' !== $label) {
                $leaf['label'] = $label;
            }
            $node['children'][] = $leaf;
        }

        return $node;
    }

    /** A short text an item shows, to tell items apart in the outline. */
    private static function label(array $item): string
    {
        foreach (['title', 'content', 'text', 'label'] as $field) {
            $value = $item['attr'][ $field ] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                $text = trim(wp_strip_all_tags($value));
                return function_exists('mb_substr') ? mb_substr($text, 0, 60) : substr($text, 0, 60);
            }
        }

        return '';
    }

    /**
     * Check a whole layout before it is written: a list of sections, wraps
     * and items in the shape above, every uid unique. Missing uids are
     * given new ones.
     *
     * @param mixed $sections
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function validate($sections): array
    {
        if (! is_array($sections) || ! array_is_list($sections)) {
            throw new \InvalidArgumentException(esc_html('BeBuilder content must be a JSON array of sections.'));
        }
        $taken = [];
        foreach ($sections as $i => $section) {
            $sections[ $i ] = self::check($section, 'section', $taken, "sections[{$i}]");
        }

        return $sections;
    }

    /**
     * @param mixed    $node
     * @param string[] $taken
     * @return array<string,mixed>
     */
    private static function check($node, string $kind, array &$taken, string $where): array
    {
        if (! is_array($node)) {
            throw new \InvalidArgumentException(esc_html("{$where} must be an object."));
        }
        if ('section' === $kind && ! isset($node['wraps']) && isset($node['items'])) {
            throw new \InvalidArgumentException(esc_html("{$where} is in BeBuilder's pre-wraps format; open and save the page in BeBuilder first."));
        }
        $uid = (string) ($node['uid'] ?? '');
        if ('' === $uid) {
            $uid = self::new_uid($taken);
        } elseif (in_array($uid, $taken, true)) {
            throw new \InvalidArgumentException(esc_html("{$where} repeats uid '{$uid}'; every uid must be unique."));
        }
        $taken[]     = $uid;
        $node['uid'] = $uid;
        if ('item' === $kind && (! isset($node['type']) || ! is_string($node['type']) || '' === $node['type'])) {
            throw new \InvalidArgumentException(esc_html("{$where} is an item and needs a type."));
        }
        if (isset($node['attr']) && ! is_array($node['attr'])) {
            throw new \InvalidArgumentException(esc_html("{$where}.attr must be an object."));
        }

        $key = 'section' === $kind ? 'wraps' : ('item' === $kind ? null : 'items');
        if (null === $key) {
            return $node;
        }
        $children = $node[ $key ] ?? [];
        if (! is_array($children)) {
            throw new \InvalidArgumentException(esc_html("{$where}.{$key} must be a list."));
        }
        $node[ $key ] = [];
        foreach (array_values($children) as $c => $child) {
            $child_kind = 'section' === $kind ? 'wrap' : (is_array($child) && ! empty($child['item_is_wrap']) ? 'nested_wrap' : 'item');
            if ('nested_wrap' === $child_kind && 'nested_wrap' === $kind) {
                throw new \InvalidArgumentException(esc_html("{$where}.{$key}[{$c}]: a nested wrap cannot hold another wrap."));
            }
            $node[ $key ][] = self::check($child, $child_kind, $taken, "{$where}.{$key}[{$c}]");
        }

        return $node;
    }

    /**
     * Every uid in the layout.
     *
     * @param array<int|string,mixed> $sections
     * @return string[]
     */
    public static function uids(array $sections): array
    {
        $uids = [];
        array_walk_recursive($sections, static function ($value, $key) use (&$uids): void {
            if ('uid' === $key && is_scalar($value)) {
                $uids[] = (string) $value;
            }
        });

        return $uids;
    }

    /**
     * Where an element sits: its key path from the top, its kind and the
     * uids of the global elements above it.
     *
     * @param array<int|string,mixed> $sections
     * @return array{path:array<int,int|string>,kind:string,global:bool}
     * @throws \InvalidArgumentException when no element has that uid
     */
    public static function locate(array $sections, string $uid): array
    {
        foreach ($sections as $s => $section) {
            if (! is_array($section)) {
                continue;
            }
            $section_global = ! empty($section['mfn_global_section_id']);
            if ((string) ($section['uid'] ?? '') === $uid) {
                return ['path' => [$s], 'kind' => 'section', 'global' => false];
            }
            foreach ((array) ($section['wraps'] ?? []) as $w => $wrap) {
                if (! is_array($wrap)) {
                    continue;
                }
                if ((string) ($wrap['uid'] ?? '') === $uid) {
                    return ['path' => [$s, 'wraps', $w], 'kind' => 'wrap', 'global' => $section_global];
                }
                $wrap_global = $section_global || (int) ($wrap['attr']['global_wraps_select'] ?? 0) > 0;
                foreach ((array) ($wrap['items'] ?? []) as $i => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $nested = ! empty($item['item_is_wrap']);
                    if ((string) ($item['uid'] ?? '') === $uid) {
                        return ['path' => [$s, 'wraps', $w, 'items', $i], 'kind' => $nested ? 'nested_wrap' : 'item', 'global' => $wrap_global];
                    }
                    if (! $nested) {
                        continue;
                    }
                    $nested_global = $wrap_global || (int) ($item['attr']['global_wraps_select'] ?? 0) > 0;
                    foreach ((array) ($item['items'] ?? []) as $n => $child) {
                        if (is_array($child) && (string) ($child['uid'] ?? '') === $uid) {
                            return ['path' => [$s, 'wraps', $w, 'items', $i, 'items', $n], 'kind' => 'item', 'global' => $nested_global];
                        }
                    }
                }
            }
        }

        throw new \InvalidArgumentException(esc_html("No element with uid '{$uid}' on this page; read get-builder-content for the uids."));
    }

    /**
     * Merge attrs into an element's attr (null removes a key) and set its
     * top-level size fields from $fields.
     *
     * @param array<int|string,mixed>   $sections
     * @param array<string,mixed>       $attrs
     * @param array<string,mixed>       $fields
     * @param callable|null             $css_def fn(string kind, string type, string key): ?array{selector,style}
     * @return array<int|string,mixed>
     */
    public static function update(array $sections, string $uid, array $attrs, array $fields = [], ?callable $css_def = null): array
    {
        $at = self::locate($sections, $uid);
        self::refuse_global($at, $uid);
        $node = self::get($sections, $at['path']);

        foreach ($fields as $field => $value) {
            if (! in_array($field, self::SIZE_FIELDS, true) || 'section' === $at['kind']) {
                throw new \InvalidArgumentException(esc_html("element may only set size, laptop_size, tablet_size and mobile_size, on a wrap or item; got '{$field}'."));
            }
            $node[ $field ] = self::size($value);
        }

        $node['attr'] = self::merge_attrs((array) ($node['attr'] ?? []), $attrs, $at['kind'], (string) ($node['type'] ?? ''), $css_def);

        return self::set($sections, $at['path'], $node);
    }

    /**
     * Add a new element to a container: '' for a section, a section's uid
     * for a wrap, a wrap's uid for an item (or a nested wrap, with
     * item_is_wrap). Every uid in it is new.
     *
     * @param array<int|string,mixed> $sections
     * @param array<string,mixed>     $element
     * @param callable|null           $defaults fn(string type): ?array{size,tablet_size,mobile_size} for an item
     * @return array{0:array<int|string,mixed>,1:string} the layout and the new element's uid
     */
    public static function add(array $sections, string $to, ?int $index, array $element, ?callable $defaults = null, ?callable $css_def = null): array
    {
        [$path, $kind] = self::container($sections, $to, ! empty($element['item_is_wrap']));
        $taken         = self::uids($sections);
        $kind          = 'item' === $kind && ! empty($element['item_is_wrap']) ? 'nested_wrap' : $kind;
        $node          = self::build($element, $kind, $taken, $defaults, $css_def);

        return [self::insert($sections, $path, $index, $node), (string) $node['uid']];
    }

    /**
     * Insert already built elements (a template's, with new uids) into a
     * container, in order from $index.
     *
     * @param array<int|string,mixed>        $sections
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int|string,mixed>
     */
    public static function insert_many(array $sections, string $to, ?int $index, array $nodes): array
    {
        foreach (array_values($nodes) as $offset => $node) {
            [$path] = self::container($sections, $to, ! empty($node['item_is_wrap']));
            $sections = self::insert($sections, $path, null === $index ? null : $index + $offset, $node);
        }

        return $sections;
    }

    /**
     * What a container holds: 'section', 'wrap' or 'item'.
     *
     * @param array<int|string,mixed> $sections
     */
    public static function child_kind(array $sections, string $to): string
    {
        return self::container($sections, $to, false)[1];
    }

    /**
     * @param array<int|string,mixed> $sections
     * @return array<int|string,mixed>
     */
    public static function remove(array $sections, string $uid): array
    {
        $at = self::locate($sections, $uid);
        self::refuse_global($at, $uid);

        return self::unset_at($sections, $at['path']);
    }

    /**
     * @param array<int|string,mixed> $sections
     * @return array<int|string,mixed>
     */
    public static function move(array $sections, string $uid, string $to, ?int $index): array
    {
        $at = self::locate($sections, $uid);
        self::refuse_global($at, $uid);
        $node = self::get($sections, $at['path']);

        $rest          = self::unset_at($sections, $at['path']);
        [$path, $kind] = self::container($rest, $to, 'nested_wrap' === $at['kind']);
        $wants         = 'nested_wrap' === $at['kind'] ? 'item' : $at['kind'];
        if ($kind !== $wants) {
            $where = '' === $to ? 'the top level' : "'{$to}'";
            throw new \InvalidArgumentException(esc_html("A {$at['kind']} cannot be moved into {$where}, which holds {$kind}s."));
        }

        return self::insert($rest, $path, $index, $node);
    }

    /**
     * Copies of the elements of one kind found in a template's layout, each
     * with new uids: its sections, every wrap of them, or every item of
     * those (top-level items and nested wraps).
     *
     * @param array<int|string,mixed> $template
     * @param string[]                $taken
     * @return array<int,array<string,mixed>>
     */
    public static function template_nodes(array $template, string $kind, array $taken): array
    {
        $nodes = [];
        foreach ($template as $section) {
            if (! is_array($section)) {
                continue;
            }
            if ('section' === $kind) {
                $nodes[] = $section;
                continue;
            }
            foreach ((array) ($section['wraps'] ?? []) as $wrap) {
                if (! is_array($wrap)) {
                    continue;
                }
                if ('wrap' === $kind) {
                    $nodes[] = $wrap;
                    continue;
                }
                foreach ((array) ($wrap['items'] ?? []) as $item) {
                    if (is_array($item)) {
                        $nodes[] = $item;
                    }
                }
            }
        }

        foreach ($nodes as $i => $node) {
            unset($node['mfn_global_section_id']);
            $nodes[ $i ] = self::renew_uids($node, $taken);
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $node
     * @param string[]            $taken
     * @return array<string,mixed>
     */
    private static function renew_uids(array $node, array &$taken): array
    {
        $node['uid'] = self::new_uid($taken);
        $taken[]     = $node['uid'];
        foreach (['wraps', 'items'] as $key) {
            if (isset($node[ $key ]) && is_array($node[ $key ])) {
                foreach ($node[ $key ] as $c => $child) {
                    if (is_array($child)) {
                        $node[ $key ][ $c ] = self::renew_uids($child, $taken);
                    }
                }
            }
        }

        return $node;
    }

    /**
     * A new element of $kind from a caller's element object.
     *
     * @param array<string,mixed> $element
     * @param string[]            $taken
     * @return array<string,mixed>
     */
    private static function build(array $element, string $kind, array &$taken, ?callable $defaults, ?callable $css_def): array
    {
        $allowed = [
            'section'     => ['attr', 'wraps'],
            'wrap'        => array_merge(['attr', 'items'], self::SIZE_FIELDS),
            'nested_wrap' => array_merge(['attr', 'items', 'item_is_wrap'], self::SIZE_FIELDS),
            'item'        => array_merge(['attr', 'type'], self::SIZE_FIELDS),
        ][ $kind ];
        $extra = array_diff(array_keys($element), $allowed);
        if ([] !== $extra) {
            throw new \InvalidArgumentException(esc_html(sprintf('A new %s takes %s; got %s.', $kind, implode(', ', $allowed), implode(', ', $extra))));
        }

        $uid     = self::new_uid($taken);
        $taken[] = $uid;
        $type    = '';
        if ('item' === $kind) {
            $type = (string) ($element['type'] ?? '');
            if (1 !== preg_match('/^[a-z0-9_]+$/', $type)) {
                throw new \InvalidArgumentException(esc_html('A new item needs a type, such as column, heading or image (scope catalog lists them).'));
            }
        }

        $node = 'item' === $kind ? ['type' => $type, 'uid' => $uid] : ['uid' => $uid];
        if ('nested_wrap' === $kind) {
            $node['item_is_wrap'] = 1;
        }
        if ('section' !== $kind) {
            $sizes = 'item' === $kind && null !== $defaults ? (array) $defaults($type) : [];
            foreach (['size', 'tablet_size', 'mobile_size'] as $field) {
                $node[ $field ] = self::size($element[ $field ] ?? ($sizes[ $field ] ?? '1/1'));
            }
            if (isset($element['laptop_size'])) {
                $node['laptop_size'] = self::size($element['laptop_size']);
            }
        }
        if ('item' === $kind) {
            $node['used_fonts'] = '';
        }

        $attrs = $element['attr'] ?? [];
        if (! is_array($attrs)) {
            throw new \InvalidArgumentException(esc_html('attr must be an object.'));
        }
        $node['attr'] = self::merge_attrs([], $attrs, $kind, $type, $css_def);

        $key = ['section' => 'wraps', 'wrap' => 'items', 'nested_wrap' => 'items', 'item' => null][ $kind ];
        if (null !== $key) {
            $children = $element[ $key ] ?? [];
            if (! is_array($children)) {
                throw new \InvalidArgumentException(esc_html("{$key} must be a list."));
            }
            $node[ $key ] = [];
            foreach (array_values($children) as $child) {
                if (! is_array($child)) {
                    throw new \InvalidArgumentException(esc_html("Each of {$key} must be an object."));
                }
                $child_kind = 'section' === $kind ? 'wrap' : (! empty($child['item_is_wrap']) ? 'nested_wrap' : 'item');
                if ('nested_wrap' === $child_kind && 'nested_wrap' === $kind) {
                    throw new \InvalidArgumentException(esc_html('A nested wrap cannot hold another wrap.'));
                }
                $node[ $key ][] = self::build($child, $child_kind, $taken, $defaults, $css_def);
            }
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private static function merge_attrs(array $current, array $attrs, string $kind, string $type, ?callable $css_def): array
    {
        foreach ($attrs as $key => $value) {
            $key = (string) $key;
            if (null === $value) {
                unset($current[ $key ]);
                continue;
            }
            if (0 === strpos($key, 'css_') && ! self::is_style($value)) {
                if (isset($current[ $key ]) && self::is_style($current[ $key ])) {
                    $current[ $key ]['val'] = $value;
                    continue;
                }
                $def = null === $css_def ? null : $css_def('nested_wrap' === $kind ? 'wrap' : $kind, $type, $key);
                if (! is_array($def) || ! isset($def['selector'], $def['style'])) {
                    throw new \InvalidArgumentException(esc_html("Style field '{$key}' is not one BeTheme defines here; pass it as {selector, style, val}, or read scope catalog for the fields."));
                }
                $current[ $key ] = ['selector' => $def['selector'], 'style' => $def['style'], 'val' => $value];
                continue;
            }
            $current[ $key ] = $value;
        }

        return $current;
    }

    /** @param mixed $value */
    private static function is_style($value): bool
    {
        return is_array($value) && array_key_exists('selector', $value) && array_key_exists('style', $value);
    }

    /** @param mixed $value */
    private static function size($value): string
    {
        if (! is_scalar($value) || 1 !== preg_match('/^(\d{1,2}\/\d{1,2}|[0-9.]{1,6}%?|auto|custom)$/', (string) $value)) {
            throw new \InvalidArgumentException(esc_html('A size is a fraction such as 1/1, 1/2 or 1/3.'));
        }

        return (string) $value;
    }

    /**
     * @param array{path:array<int,int|string>,kind:string,global:bool} $at
     */
    private static function refuse_global(array $at, string $uid): void
    {
        if ($at['global']) {
            throw new \InvalidArgumentException(esc_html("Element '{$uid}' is inside a global section or wrap, which renders from its template; edit that template instead."));
        }
    }

    /**
     * The key path of a container's child list and the kind it holds.
     *
     * @param array<int|string,mixed> $sections
     * @return array{0:array<int,int|string>,1:string}
     */
    private static function container(array $sections, string $to, bool $nested): array
    {
        if ('' === $to) {
            if ($nested) {
                throw new \InvalidArgumentException(esc_html('A nested wrap goes into a wrap; pass to: a wrap uid.'));
            }
            return [[], 'section'];
        }
        $at = self::locate($sections, $to);
        if ($at['global']) {
            self::refuse_global($at, $to);
        }
        $node = self::get($sections, $at['path']);
        if ('section' === $at['kind']) {
            if (! empty($node['mfn_global_section_id'])) {
                self::refuse_global(['global' => true] + $at, $to);
            }
            if ($nested) {
                throw new \InvalidArgumentException(esc_html('A nested wrap goes into a wrap, not a section.'));
            }
            return [array_merge($at['path'], ['wraps']), 'wrap'];
        }
        if ('item' === $at['kind']) {
            throw new \InvalidArgumentException(esc_html("'{$to}' is an item; items hold no elements."));
        }
        if ((int) ($node['attr']['global_wraps_select'] ?? 0) > 0) {
            self::refuse_global(['global' => true] + $at, $to);
        }
        if ($nested && 'nested_wrap' === $at['kind']) {
            throw new \InvalidArgumentException(esc_html('A nested wrap cannot hold another wrap.'));
        }

        return [array_merge($at['path'], ['items']), 'item'];
    }

    /**
     * @param array<int|string,mixed> $sections
     * @param array<int,int|string>   $path
     * @param array<string,mixed>     $node
     * @return array<int|string,mixed>
     */
    private static function insert(array $sections, array $path, ?int $index, array $node): array
    {
        $list  = [] === $path ? $sections : (array) (self::get($sections, $path) ?? []);
        $list  = array_values($list);
        $index = null === $index ? count($list) : max(0, min($index, count($list)));
        array_splice($list, $index, 0, [$node]);

        return [] === $path ? $list : self::set($sections, $path, $list);
    }

    /**
     * @param array<int|string,mixed> $data
     * @param array<int,int|string>   $path
     * @return mixed
     */
    private static function get(array $data, array $path)
    {
        foreach ($path as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[ $key ];
        }

        return $data;
    }

    /**
     * @param array<int|string,mixed> $data
     * @param array<int,int|string>   $path
     * @param mixed                   $value
     * @return array<int|string,mixed>
     */
    private static function set(array $data, array $path, $value): array
    {
        $key = array_shift($path);
        if ([] === $path) {
            $data[ $key ] = $value;
            return $data;
        }
        $data[ $key ] = self::set(is_array($data[ $key ] ?? null) ? $data[ $key ] : [], $path, $value);

        return $data;
    }

    /**
     * Remove the element at $path, keeping its list a list.
     *
     * @param array<int|string,mixed> $data
     * @param array<int,int|string>   $path
     * @return array<int|string,mixed>
     */
    private static function unset_at(array $data, array $path): array
    {
        $last   = array_pop($path);
        $parent = [] === $path ? $data : (array) self::get($data, $path);
        unset($parent[ $last ]);
        $parent = array_values($parent);

        return [] === $path ? $parent : self::set($data, $path, $parent);
    }

    /** @param string[] $taken */
    private static function new_uid(array $taken): string
    {
        do {
            $uid = substr(bin2hex(random_bytes(5)), 0, 9);
        } while (in_array($uid, $taken, true));

        return $uid;
    }
}
