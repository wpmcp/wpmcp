<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parser and editor for a Divi 5 layout (issue #636). Divi 5 stores a page
 * as block markup in post_content, the same comment grammar the block
 * editor uses, every block in the `divi` namespace and the whole layout
 * wrapped in one `divi/placeholder` block:
 *
 *   <!-- wp:divi/placeholder -->
 *   <!-- wp:divi/section {"builderVersion":"5.18.0"} -->
 *   <!-- wp:divi/row {...} --><!-- wp:divi/column {...} -->
 *   <!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"..."}}}} /-->
 *   ...
 *   <!-- /wp:divi/placeholder -->
 *
 * A module's settings are its JSON attributes, keyed by element and then by
 * breakpoint (`desktop`, `tablet`, `phone` ...); there is no per-block id in
 * the markup, so blocks are addressed by dotted paths of child indexes
 * counted inside the placeholder ("0.1.0" is the first child of the second
 * child of the first section). Pure string work: Divi never has to be loaded.
 *
 * Edits splice the original string by offsets, so every byte outside the
 * touched block is kept exactly. Attributes are decoded to objects, so an
 * empty object stays `{}` when its block is written back, and are written
 * with core's serialize_block_attributes(), the encoding the block
 * serializer Divi's builder uses applies (`<`, `>`, `&`, `"` and `--`
 * escaped as \u sequences, so a value cannot end the comment).
 */
class Divi5_Blocks
{
    public const PLACEHOLDER = 'divi/placeholder';

    /**
     * The main text field of the modules whose text `text` can set, as an
     * attribute path under `<field>.innerContent.desktop.value`, recorded
     * from Divi 5.18.0's conversion outline: every module whose Divi 4
     * `content` became a `content.innerContent` string, the heading's title
     * and the button's text.
     */
    private const TEXT_FIELDS = [
        'divi/accordion-item'  => ['content'],
        'divi/blurb'           => ['content'],
        'divi/button'          => ['button', 'text'],
        'divi/code'            => ['content'],
        'divi/cta'             => ['content'],
        'divi/fullwidth-code'  => ['content'],
        'divi/fullwidth-header' => ['content'],
        'divi/heading'         => ['title'],
        'divi/login'           => ['content'],
        'divi/map-pin'         => ['content'],
        'divi/pricing-table'   => ['content'],
        'divi/slide'           => ['content'],
        'divi/tab'             => ['content'],
        'divi/team-member'     => ['content'],
        'divi/testimonial'     => ['content'],
        'divi/text'            => ['content'],
        'divi/toggle'          => ['content'],
    ];

    /** The block comment delimiter, as core's block parser matches it. */
    private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    private const NAME = '/^[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?$/';

    /** Whether a string holds Divi 5 layout markup. */
    public static function is_divi5(string $content): bool
    {
        return 1 === preg_match('/<!--\s+wp:divi\//', $content);
    }

    /**
     * Public tree: blocks with path, name, attrs (objects) and children,
     * counted inside the placeholder.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function tree(string $content): array
    {
        return self::export(self::root($content)['children'], '');
    }

    /**
     * Merge `$attrs` into a block's attributes (null removes a key, an
     * object merges, anything else replaces) and set `$text` on its main
     * text field. `$map` is applied to every string the call writes.
     */
    public static function update(string $content, string $path, ?array $attrs, ?string $text, ?callable $map = null): string
    {
        $node = self::resolve($content, $path, false);
        if (null === $attrs && null === $text) {
            throw new \InvalidArgumentException(esc_html('update needs attrs (the attributes to merge; null removes a key) or text.'));
        }
        $value = self::decode_attrs($node);
        if (null !== $attrs) {
            $value = self::merge($value, $attrs, $map);
        }
        if (null !== $text) {
            $value = self::set_text($node['name'], $value, $map ? $map($text) : $text);
        }

        return substr($content, 0, $node['start'])
            . self::opener($node['name'], $value, $node['void'])
            . substr($content, $node['open_end']);
    }

    /**
     * Insert a new block, {name, attrs?, text?, children?}, under the block
     * at `$parent_path` ('' is the top level). Blocks the call creates get
     * `builderVersion` = `$version` unless they carry one.
     *
     * @return array{0:string,1:string} new content and the new block's path
     */
    public static function add(string $content, string $parent_path, ?int $index, array $element, ?string $version, ?callable $map = null): array
    {
        $markup = self::serialize($element, $version, $map);

        if ('' === $parent_path && '' === trim($content)) {
            return [self::wrap($markup), '0'];
        }

        $parent   = self::resolve($content, $parent_path, true);
        $children = $parent['children'];
        $position = self::position($index, count($children));
        $path     = ('' === $parent_path ? '' : $parent_path . '.') . $position;

        if ($parent['void']) {
            // A self-closed block (`<!-- wp:divi/placeholder /-->`) is opened.
            $open = self::opener($parent['name'], self::decode_attrs($parent), false);
            return [substr($content, 0, $parent['start']) . $open . $markup . self::closer($parent['name']) . substr($content, $parent['end']), $path];
        }

        $offset = $position < count($children) ? $children[ $position ]['start'] : $parent['close_start'];

        return [substr($content, 0, $offset) . $markup . substr($content, $offset), $path];
    }

    public static function remove(string $content, string $path): string
    {
        $node = self::resolve($content, $path, false);

        return substr($content, 0, $node['start']) . substr($content, $node['end']);
    }

    public static function move(string $content, string $path, string $to, ?int $index): string
    {
        $node = self::resolve($content, $path, false);
        if ($to === $path || 0 === strpos($to . '.', $path . '.')) {
            throw new \InvalidArgumentException(esc_html('A block cannot be moved into itself.'));
        }
        $slice   = substr($content, $node['start'], $node['end'] - $node['start']);
        $removed = substr($content, 0, $node['start']) . substr($content, $node['end']);

        // The target's path as it reads once the block is gone: a later
        // sibling of the moved block (or of one of its ancestors) shifts up.
        $from = explode('.', $path);
        $dest = '' === $to ? [] : explode('.', $to);
        $last = count($from) - 1;
        if (count($dest) > $last && array_slice($dest, 0, $last) === array_slice($from, 0, $last) && (int) $dest[ $last ] > (int) $from[ $last ]) {
            $dest[ $last ] = (string) ((int) $dest[ $last ] - 1);
        }

        $parent   = self::resolve($removed, implode('.', $dest), true);
        $children = $parent['children'];
        $position = self::position($index, count($children));

        if ($parent['void']) {
            $open = self::opener($parent['name'], self::decode_attrs($parent), false);
            return substr($removed, 0, $parent['start']) . $open . $slice . self::closer($parent['name']) . substr($removed, $parent['end']);
        }

        $offset = $position < count($children) ? $children[ $position ]['start'] : $parent['close_start'];

        return substr($removed, 0, $offset) . $slice . substr($removed, $offset);
    }

    /** Markup for a new block and its children. */
    public static function serialize(array $element, ?string $version, ?callable $map = null): string
    {
        $name = $element['name'] ?? null;
        if (! is_string($name) || '' === $name) {
            throw new \InvalidArgumentException(esc_html('An element needs a name, such as divi/text (the divi/ prefix may be left out).'));
        }
        $name = self::normalise_name($name);

        $attrs = $element['attrs'] ?? [];
        if (! is_array($attrs)) {
            throw new \InvalidArgumentException(esc_html("attrs of {$name} must be an object."));
        }
        $value = self::merge(new \stdClass(), $attrs, $map);
        if (isset($element['text'])) {
            if (! is_string($element['text'])) {
                throw new \InvalidArgumentException(esc_html("text of {$name} must be a string."));
            }
            $value = self::set_text($name, $value, $map ? $map($element['text']) : $element['text']);
        }
        if (null !== $version && '' !== $version && ! property_exists($value, 'builderVersion') && self::PLACEHOLDER !== $name) {
            $value->builderVersion = $version;
        }

        $children = $element['children'] ?? [];
        if (! is_array($children) || ($children !== [] && ! array_is_list($children))) {
            throw new \InvalidArgumentException(esc_html("children of {$name} must be a list of elements."));
        }

        $inner = '';
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new \InvalidArgumentException(esc_html("children of {$name} must be a list of elements."));
            }
            $inner .= self::serialize($child, $version, $map);
        }

        return self::opener($name, $value, false) . $inner . self::closer($name);
    }

    /** A layout of top-level block markup, wrapped the way Divi 5 stores it. */
    public static function wrap(string $markup): string
    {
        return self::opener(self::PLACEHOLDER, new \stdClass(), false) . $markup . self::closer(self::PLACEHOLDER);
    }

    /** Assert a whole layout parses (balanced delimiters, valid attributes). */
    public static function validate(string $content): void
    {
        $check = static function (array $nodes) use (&$check): void {
            foreach ($nodes as $node) {
                self::decode_attrs($node);
                $check($node['children']);
            }
        };
        $check(self::parse($content));
    }

    /**
     * Apply `$map` to every string attribute value in the layout, rewriting
     * only the openers whose attributes it changes.
     */
    public static function map_strings(string $content, callable $map): string
    {
        $nodes = [];
        $flat  = static function (array $list) use (&$flat, &$nodes): void {
            foreach ($list as $node) {
                $nodes[] = $node;
                $flat($node['children']);
            }
        };
        $flat(self::parse($content));
        usort($nodes, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($nodes as $node) {
            $before = self::decode_attrs($node);
            $after  = self::map_value($before, $map);
            if (wp_json_encode($after) === wp_json_encode($before)) {
                continue;
            }
            $content = substr($content, 0, $node['start']) . self::opener($node['name'], $after, $node['void']) . substr($content, $node['open_end']);
        }

        return $content;
    }

    // ------------------------------------------------------------- internals

    private static function normalise_name(string $name): string
    {
        $name = false === strpos($name, '/') ? 'divi/' . $name : $name;
        if (! preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException(esc_html("Invalid block name {$name}; use lowercase names such as divi/text."));
        }

        return $name;
    }

    private static function opener(string $name, object $attrs, bool $void): string
    {
        $json = [] === get_object_vars($attrs) ? '' : serialize_block_attributes($attrs) . ' ';

        return '<!-- wp:' . $name . ' ' . $json . ($void ? '/-->' : '-->');
    }

    private static function closer(string $name): string
    {
        return '<!-- /wp:' . $name . ' -->';
    }

    /**
     * The block nodes, nested: name, raw attrs, offsets (start, open_end,
     * close_start, end), void flag and children.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parse(string $content): array
    {
        preg_match_all(self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $top   = [];
        $stack = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            $end   = $start + strlen($m[0][0]);
            $name  = (isset($m['namespace']) && '' !== $m['namespace'][0] ? $m['namespace'][0] : 'core/') . $m['name'][0];
            $raw   = isset($m['attrs']) && -1 !== $m['attrs'][1] ? $m['attrs'][0] : '';

            if ('/' === ($m['closer'][0] ?? '')) {
                $open = array_pop($stack);
                if (null === $open || $open['name'] !== $name) {
                    throw new \InvalidArgumentException(esc_html("The layout markup is not balanced: <!-- /wp:{$name} --> at offset {$start} closes nothing open."));
                }
                $open['close_start'] = $start;
                $open['end']         = $end;
                self::attach($top, $stack, $open);
                continue;
            }

            $node = [
                'name'        => $name,
                'raw'         => $raw,
                'start'       => $start,
                'open_end'    => $end,
                'close_start' => $end,
                'end'         => $end,
                'void'        => '/' === ($m['void'][0] ?? ''),
                'children'    => [],
            ];

            if ($node['void']) {
                self::attach($top, $stack, $node);
            } else {
                $stack[] = $node;
            }
        }

        if ([] !== $stack) {
            $open = end($stack);
            throw new \InvalidArgumentException(esc_html("The layout markup is not balanced: <!-- wp:{$open['name']} --> is never closed."));
        }

        return $top;
    }

    private static function attach(array &$top, array &$stack, array $node): void
    {
        if ([] === $stack) {
            $top[] = $node;
            return;
        }
        $stack[ count($stack) - 1 ]['children'][] = $node;
    }

    /** The node paths count from: the placeholder, else the whole document. */
    private static function root(string $content): array
    {
        $top = self::parse($content);
        foreach ($top as $node) {
            if (self::PLACEHOLDER === $node['name']) {
                return $node;
            }
        }

        return [
            'name'        => '',
            'raw'         => '',
            'start'       => 0,
            'open_end'    => 0,
            'close_start' => strlen($content),
            'end'         => strlen($content),
            'void'        => false,
            'children'    => $top,
        ];
    }

    private static function resolve(string $content, string $path, bool $want_container): array
    {
        $node = self::root($content);

        if ('' === $path) {
            if (! $want_container) {
                throw new \InvalidArgumentException(esc_html('A path to a block is required.'));
            }
            return $node;
        }

        if (! preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            throw new \InvalidArgumentException(esc_html("Invalid path {$path}; use dotted child indexes such as 0.1.0."));
        }
        foreach (explode('.', $path) as $step) {
            if (! isset($node['children'][ (int) $step ])) {
                throw new \InvalidArgumentException(esc_html("No block at path {$path}."));
            }
            $node = $node['children'][ (int) $step ];
        }

        return $node;
    }

    private static function position(?int $index, int $count): int
    {
        if (null === $index || $index > $count) {
            return $count;
        }
        if ($index < 0) {
            throw new \InvalidArgumentException(esc_html('index must be 0 or greater.'));
        }

        return $index;
    }

    private static function decode_attrs(array $node): object
    {
        if ('' === $node['raw']) {
            return new \stdClass();
        }
        $value = json_decode($node['raw']);
        if (! is_object($value)) {
            throw new \InvalidArgumentException(esc_html("The attributes of the {$node['name']} block at offset {$node['start']} are not valid JSON."));
        }

        return $value;
    }

    /**
     * Merge a decoded-JSON patch into an object: null removes a key, an
     * associative array merges into the object there, anything else
     * replaces it.
     */
    private static function merge(object $target, array $patch, ?callable $map): object
    {
        foreach ($patch as $key => $value) {
            $key = (string) $key;
            if (null === $value) {
                unset($target->{$key});
                continue;
            }
            if (is_array($value) && ($value === [] || ! array_is_list($value))) {
                $current       = isset($target->{$key}) && is_object($target->{$key}) ? $target->{$key} : new \stdClass();
                $target->{$key} = self::merge($current, $value, $map);
                continue;
            }
            $target->{$key} = self::value($value, $map);
        }

        return $target;
    }

    /** @param mixed $value */
    private static function value($value, ?callable $map)
    {
        if (is_string($value)) {
            return $map ? $map($value) : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn ($item) => self::value($item, $map), $value);
        }

        return self::merge(new \stdClass(), $value, $map);
    }

    /**
     * @param mixed $value a decoded attribute value
     * @return mixed the same shape with every string mapped
     */
    private static function map_value($value, callable $map)
    {
        if (is_string($value)) {
            return $map($value);
        }
        if (is_array($value)) {
            return array_map(static fn ($item) => self::map_value($item, $map), $value);
        }
        if (is_object($value)) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::map_value($item, $map);
            }
            return $out;
        }

        return $value;
    }

    private static function set_text(string $name, object $attrs, string $text): object
    {
        $field = self::TEXT_FIELDS[ $name ] ?? null;
        if (null === $field) {
            throw new \InvalidArgumentException(esc_html("text has no field to set on {$name}; set it through attrs instead, e.g. {\"title\":{\"innerContent\":{\"desktop\":{\"value\":\"...\"}}}}."));
        }

        $patch = isset($field[1])
            ? [$field[0] => ['innerContent' => ['desktop' => ['value' => [$field[1] => $text]]]]]
            : [$field[0] => ['innerContent' => ['desktop' => ['value' => $text]]]];

        return self::merge($attrs, $patch, null);
    }

    private static function export(array $nodes, string $prefix): array
    {
        $out = [];
        foreach ($nodes as $i => $node) {
            $path  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
            $out[] = [
                'path'     => $path,
                'name'     => $node['name'],
                'attrs'    => self::decode_attrs($node),
                'children' => self::export($node['children'], $path),
            ];
        }

        return $out;
    }
}
