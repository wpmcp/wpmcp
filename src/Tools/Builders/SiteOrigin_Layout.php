<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Element operations over a SiteOrigin Page Builder layout (issue #644).
 * Pure array work: nothing here reads or writes the database.
 *
 * The stored shape is the one SiteOrigin Page Builder 2.38.0's builder
 * serializes (getPanelsData()) and its save validates
 * (SiteOrigin_Panels_Admin::validate_layout_structure()): three flat lists,
 * `grids` (rows: cells, style, label), `grid_cells` (grid, index, weight,
 * style) and `widgets` (the widget instance plus `panels_info`: class,
 * grid, cell, id, widget_id, style), widgets in row, cell, position order.
 * Rows, cells and widgets are addressed by position, so this class turns
 * the lists into a tree, edits that, and writes the lists back with every
 * grid, cell, index and id renumbered, as the builder itself does.
 *
 * Paths are dotted positions: 'r' a row, 'r.c' a cell, 'r.c.w' a widget,
 * all 0-based; '' is the top level, which takes rows. A row takes cells, a
 * cell takes widgets.
 */
final class SiteOrigin_Layout
{
    /**
     * The layout as a tree, or an exception when its references do not
     * resolve (a layout the builder could not load).
     *
     * @param array<string,mixed> $data
     * @return array<int,array{row:array<string,mixed>,cells:array<int,array{cell:array<string,mixed>,widgets:array<int,array<string,mixed>>}>}>
     * @throws \InvalidArgumentException
     */
    public static function tree(array $data): array
    {
        foreach (['grids', 'grid_cells'] as $key) {
            if (! isset($data[ $key ]) || ! is_array($data[ $key ]) || ! array_is_list($data[ $key ])) {
                throw new \InvalidArgumentException(esc_html("A SiteOrigin layout needs a '{$key}' list."));
            }
        }
        $widgets = $data['widgets'] ?? [];
        if (! is_array($widgets) || ! array_is_list($widgets)) {
            throw new \InvalidArgumentException(esc_html("A SiteOrigin layout's 'widgets' must be a list."));
        }

        $rows = [];
        foreach ($data['grids'] as $grid) {
            if (! is_array($grid)) {
                throw new \InvalidArgumentException(esc_html('Every row in grids must be an object.'));
            }
            unset($grid['cells']);
            $rows[] = ['row' => $grid, 'cells' => []];
        }
        foreach ($data['grid_cells'] as $cell) {
            if (! is_array($cell) || ! isset($cell['grid']) || ! is_numeric($cell['grid']) || ! isset($rows[ (int) $cell['grid'] ])) {
                throw new \InvalidArgumentException(esc_html('Every grid_cells entry needs a numeric grid that points at an existing row.'));
            }
            $row = (int) $cell['grid'];
            unset($cell['grid'], $cell['index']);
            $rows[ $row ]['cells'][] = ['cell' => $cell, 'widgets' => []];
        }
        foreach ($widgets as $widget) {
            $info = is_array($widget) ? ($widget['panels_info'] ?? null) : null;
            if (! is_array($info) || ! isset($info['grid'], $info['cell']) || ! is_numeric($info['grid']) || ! is_numeric($info['cell'])) {
                throw new \InvalidArgumentException(esc_html('Every widget needs a panels_info with a numeric grid and cell.'));
            }
            $row  = (int) $info['grid'];
            $cell = (int) $info['cell'];
            if (! isset($rows[ $row ]['cells'][ $cell ])) {
                throw new \InvalidArgumentException(esc_html("A widget points at row {$row}, cell {$cell}, which the layout does not have."));
            }
            $rows[ $row ]['cells'][ $cell ]['widgets'][] = $widget;
        }

        return $rows;
    }

    /**
     * The three stored lists for a tree, renumbered, with any other
     * top-level keys of $base kept.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $base
     * @return array<string,mixed>
     */
    public static function flatten(array $rows, array $base = []): array
    {
        $widgets = [];
        $grids   = [];
        $cells   = [];
        $id      = 0;
        foreach (array_values($rows) as $r => $row) {
            foreach (array_values($row['cells']) as $c => $cell) {
                foreach (array_values($cell['widgets']) as $widget) {
                    $info                  = (array) $widget['panels_info'];
                    $info['grid']          = $r;
                    $info['cell']          = $c;
                    $info['id']            = $id++;
                    $widget['panels_info'] = $info;
                    $widgets[]             = $widget;
                }
                $cells[] = ['grid' => $r, 'index' => $c] + $cell['cell'];
            }
            $grids[] = ['cells' => count($row['cells'])] + $row['row'];
        }

        return ['widgets' => $widgets, 'grids' => $grids, 'grid_cells' => $cells] + $base;
    }

    /**
     * The outline a read returns: path, kind and children per element.
     *
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    public static function outline(array $data): array
    {
        $out = [];
        foreach (self::tree($data) as $r => $row) {
            $node = ['path' => (string) $r, 'kind' => 'row', 'cells' => count($row['cells'])];
            if (isset($row['row']['label']) && '' !== (string) $row['row']['label']) {
                $node['label'] = (string) $row['row']['label'];
            }
            $node['children'] = [];
            foreach ($row['cells'] as $c => $cell) {
                $cnode = ['path' => "{$r}.{$c}", 'kind' => 'cell', 'weight' => $cell['cell']['weight'] ?? null, 'children' => []];
                foreach ($cell['widgets'] as $w => $widget) {
                    $info              = (array) $widget['panels_info'];
                    $cnode['children'][] = [
                        'path'      => "{$r}.{$c}.{$w}",
                        'kind'      => 'widget',
                        'class'     => (string) ($info['class'] ?? ''),
                        'widget_id' => (string) ($info['widget_id'] ?? ''),
                        'label'     => self::label($widget),
                    ];
                }
                $node['children'][] = $cnode;
            }
            $out[] = $node;
        }

        return $out;
    }

    /** A short human label for a widget: its builder label, title, or the start of its text. */
    private static function label(array $widget): string
    {
        $info = (array) $widget['panels_info'];
        foreach ([$info['label'] ?? null, $widget['title'] ?? null, $widget['text'] ?? null, $widget['content'] ?? null] as $candidate) {
            if (is_string($candidate)) {
                $text = trim(wp_strip_all_tags($candidate));
                if ('' !== $text) {
                    return function_exists('mb_substr') ? mb_substr($text, 0, 60) : substr($text, 0, 60);
                }
            }
        }

        return '';
    }

    /**
     * Parse a path into its positions, checking each one exists.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return int[]
     * @throws \InvalidArgumentException
     */
    public static function locate(array $rows, string $path): array
    {
        if (1 !== preg_match('/^\d+(\.\d+){0,2}$/', $path)) {
            throw new \InvalidArgumentException(esc_html("Path '{$path}' is not a row (r), cell (r.c) or widget (r.c.w) path."));
        }
        $parts = array_map('intval', explode('.', $path));
        $ok    = isset($rows[ $parts[0] ])
            && (! isset($parts[1]) || isset($rows[ $parts[0] ]['cells'][ $parts[1] ]))
            && (! isset($parts[2]) || isset($rows[ $parts[0] ]['cells'][ $parts[1] ]['widgets'][ $parts[2] ]));
        if (! $ok) {
            throw new \InvalidArgumentException(esc_html("The layout has no element at path '{$path}'; read the page again for its paths."));
        }

        return $parts;
    }

    /**
     * Merge $changes into $target: a null value removes that key.
     *
     * @param array<string,mixed> $target
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private static function merge(array $target, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (null === $value) {
                unset($target[ $key ]);
            } else {
                $target[ $key ] = $value;
            }
        }

        return $target;
    }

    /**
     * Update one element. A widget: attrs are its instance fields, element
     * takes style (merged into panels_info.style) and label. A row: attrs
     * are its style, element takes label. A cell: attrs are its style,
     * element takes weight (its siblings rescaled to keep the row at 1).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $attrs
     * @param array<string,mixed>            $element
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function update(array $rows, string $path, array $attrs, array $element): array
    {
        $at = self::locate($rows, $path);

        if (3 === count($at)) {
            if (array_key_exists('panels_info', $attrs)) {
                throw new \InvalidArgumentException(esc_html('panels_info is the builder\'s own placement; pass style or label in element, and move the widget to place it.'));
            }
            self::only($element, ['style', 'label']);
            $widget = self::merge($rows[ $at[0] ]['cells'][ $at[1] ]['widgets'][ $at[2] ], $attrs);
            $info   = (array) $widget['panels_info'];
            if (isset($element['style'])) {
                $info['style'] = self::merge((array) ($info['style'] ?? []), self::assoc($element['style'], 'style'));
            }
            if (array_key_exists('label', $element)) {
                $info = self::merge($info, ['label' => null === $element['label'] ? null : (string) $element['label']]);
            }
            $widget['panels_info'] = $info;
            $rows[ $at[0] ]['cells'][ $at[1] ]['widgets'][ $at[2] ] = $widget;

            return $rows;
        }

        if (2 === count($at)) {
            self::only($element, ['weight']);
            $cell          = $rows[ $at[0] ]['cells'][ $at[1] ]['cell'];
            $cell['style'] = self::merge((array) ($cell['style'] ?? []), $attrs);
            $rows[ $at[0] ]['cells'][ $at[1] ]['cell'] = $cell;
            if (isset($element['weight'])) {
                $rows[ $at[0] ]['cells'] = self::reweigh($rows[ $at[0] ]['cells'], $at[1], $element['weight']);
            }

            return $rows;
        }

        self::only($element, ['label']);
        $row          = $rows[ $at[0] ]['row'];
        $row['style'] = self::merge((array) ($row['style'] ?? []), $attrs);
        if (array_key_exists('label', $element)) {
            $row = self::merge($row, ['label' => null === $element['label'] ? null : (string) $element['label']]);
        }
        $rows[ $at[0] ]['row'] = $row;

        return $rows;
    }

    /**
     * Add an element. To '' a row: element {cells: count or list of
     * weights, attrs: style, label}. To 'r' a cell: element {weight, attrs:
     * style}. To 'r.c' a widget: element {class, attrs: instance fields,
     * style, label}, with $widget_known deciding whether the class is one
     * the site has.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $element
     * @return array{0:array<int,array<string,mixed>>,1:string}
     * @throws \InvalidArgumentException
     */
    public static function add(array $rows, string $to, ?int $index, array $element, callable $widget_known): array
    {
        if ('' === $to) {
            self::only($element, ['cells', 'attrs', 'label']);
            $weights = self::weights($element['cells'] ?? 1);
            $row     = ['style' => self::assoc($element['attrs'] ?? [], 'attrs')];
            if (isset($element['label'])) {
                $row['label'] = (string) $element['label'];
            }
            $cells = [];
            foreach ($weights as $weight) {
                $cells[] = ['cell' => ['weight' => $weight, 'style' => []], 'widgets' => []];
            }
            $at = self::position(count($rows), $index);
            array_splice($rows, $at, 0, [['row' => $row, 'cells' => $cells]]);

            return [$rows, (string) $at];
        }

        $parts = self::locate($rows, $to);
        if (1 === count($parts)) {
            self::only($element, ['weight', 'attrs']);
            $cells = $rows[ $parts[0] ]['cells'];
            $at    = self::position(count($cells), $index);
            array_splice($cells, $at, 0, [['cell' => ['weight' => 0, 'style' => self::assoc($element['attrs'] ?? [], 'attrs')], 'widgets' => []]]);
            $weight = $element['weight'] ?? 1 / count($cells);
            $rows[ $parts[0] ]['cells'] = self::reweigh($cells, $at, $weight);

            return [$rows, "{$parts[0]}.{$at}"];
        }

        if (2 !== count($parts)) {
            throw new \InvalidArgumentException(esc_html("A widget takes no children; add to its cell ('{$parts[0]}.{$parts[1]}') instead."));
        }
        self::only($element, ['class', 'attrs', 'style', 'label']);
        $class = is_string($element['class'] ?? null) ? $element['class'] : '';
        if ('' === $class) {
            throw new \InvalidArgumentException(esc_html('A widget needs element.class, a widget class from scope catalog.'));
        }
        if (! $widget_known($class)) {
            throw new \InvalidArgumentException(esc_html("This site has no widget class '{$class}'; read scope catalog for the list."));
        }
        $attrs = self::assoc($element['attrs'] ?? [], 'attrs');
        unset($attrs['panels_info']);
        $info = ['class' => $class, 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => wp_generate_uuid4(), 'style' => self::assoc($element['style'] ?? [], 'style')];
        if (isset($element['label'])) {
            $info['label'] = (string) $element['label'];
        }
        $widgets = $rows[ $parts[0] ]['cells'][ $parts[1] ]['widgets'];
        $at      = self::position(count($widgets), $index);
        array_splice($widgets, $at, 0, [$attrs + ['panels_info' => $info]]);
        $rows[ $parts[0] ]['cells'][ $parts[1] ]['widgets'] = $widgets;

        return [$rows, "{$parts[0]}.{$parts[1]}.{$at}"];
    }

    /**
     * Remove a row, a cell (its siblings take its width) or a widget. A
     * row's last cell is refused: remove the row.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function remove(array $rows, string $path): array
    {
        $at = self::locate($rows, $path);
        if (1 === count($at)) {
            array_splice($rows, $at[0], 1);
            return $rows;
        }
        if (2 === count($at)) {
            $cells = $rows[ $at[0] ]['cells'];
            if (1 === count($cells)) {
                throw new \InvalidArgumentException(esc_html("Cell '{$path}' is its row's only cell; remove row '{$at[0]}' instead."));
            }
            array_splice($cells, $at[1], 1);
            $rows[ $at[0] ]['cells'] = self::normalize($cells);
            return $rows;
        }
        array_splice($rows[ $at[0] ]['cells'][ $at[1] ]['widgets'], $at[2], 1);

        return $rows;
    }

    /**
     * Move a widget to a cell, or a row to another position at the top
     * level. The index counts positions after the element is taken out.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:array<int,array<string,mixed>>,1:string}
     * @throws \InvalidArgumentException
     */
    public static function move(array $rows, string $path, string $to, ?int $index): array
    {
        $at = self::locate($rows, $path);
        if (1 === count($at)) {
            if ('' !== $to) {
                throw new \InvalidArgumentException(esc_html("A row moves at the top level: pass to ''."));
            }
            $row = array_splice($rows, $at[0], 1)[0];
            $new = self::position(count($rows), $index);
            array_splice($rows, $new, 0, [$row]);
            return [$rows, (string) $new];
        }
        if (2 === count($at)) {
            throw new \InvalidArgumentException(esc_html('Cells move with their row; move the row, or move the widgets into another cell.'));
        }

        $target = self::locate($rows, $to);
        if (2 !== count($target)) {
            throw new \InvalidArgumentException(esc_html("A widget moves into a cell: pass to as 'r.c'."));
        }
        $widget = array_splice($rows[ $at[0] ]['cells'][ $at[1] ]['widgets'], $at[2], 1)[0];
        $list   = $rows[ $target[0] ]['cells'][ $target[1] ]['widgets'];
        $new    = self::position(count($list), $index);
        array_splice($list, $new, 0, [$widget]);
        $rows[ $target[0] ]['cells'][ $target[1] ]['widgets'] = $list;

        return [$rows, "{$target[0]}.{$target[1]}.{$new}"];
    }

    /**
     * Insert the rows of another layout (a prebuilt layout or another
     * page's) at the top level, every widget given a new widget_id.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $source
     * @return array{0:array<int,array<string,mixed>>,1:string}
     * @throws \InvalidArgumentException
     */
    public static function insert_layout(array $rows, ?int $index, array $source): array
    {
        $copied = self::tree($source);
        if ([] === $copied) {
            throw new \InvalidArgumentException(esc_html('That template holds no rows.'));
        }
        foreach ($copied as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                foreach ($cell['widgets'] as $w => $widget) {
                    $widget['panels_info']['widget_id'] = wp_generate_uuid4();
                    $copied[ $r ]['cells'][ $c ]['widgets'][ $w ] = $widget;
                }
            }
        }
        $at = self::position(count($rows), $index);
        array_splice($rows, $at, 0, $copied);

        return [$rows, (string) $at];
    }

    private static function position(int $count, ?int $index): int
    {
        if (null === $index) {
            return $count;
        }
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException(esc_html("index {$index} is out of range 0 to {$count}."));
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $element
     * @param string[]            $allowed
     * @throws \InvalidArgumentException
     */
    private static function only(array $element, array $allowed): void
    {
        $extra = array_diff(array_keys($element), $allowed);
        if ([] !== $extra) {
            throw new \InvalidArgumentException(esc_html('element takes ' . implode(', ', $allowed) . ' here; got ' . implode(', ', $extra) . '.'));
        }
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private static function assoc($value, string $name): array
    {
        if (! is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new \InvalidArgumentException(esc_html("{$name} must be an object of fields."));
        }

        return $value;
    }

    /**
     * Cell weights for a new row: a count (equal widths) or the weights.
     *
     * @param mixed $cells
     * @return float[]|int[]
     * @throws \InvalidArgumentException
     */
    private static function weights($cells): array
    {
        if (is_int($cells) || (is_string($cells) && ctype_digit($cells))) {
            $n = (int) $cells;
            if ($n < 1 || $n > 12) {
                throw new \InvalidArgumentException(esc_html('A row takes 1 to 12 cells.'));
            }
            return 1 === $n ? [1] : array_fill(0, $n, round(1 / $n, 4));
        }
        if (! is_array($cells) || [] === $cells || count($cells) > 12) {
            throw new \InvalidArgumentException(esc_html('cells is a count (1 to 12) or a list of weights.'));
        }
        $out = [];
        foreach (array_values($cells) as $weight) {
            if (! is_numeric($weight) || $weight <= 0) {
                throw new \InvalidArgumentException(esc_html('Every cell weight must be a number above 0.'));
            }
            $out[] = $weight + 0;
        }
        $sum = array_sum($out);

        return abs($sum - 1) < 0.001 ? $out : array_map(static fn ($w) => round($w / $sum, 4), $out);
    }

    /**
     * Set one cell's weight and rescale the others so the row sums to 1.
     *
     * @param array<int,array<string,mixed>> $cells
     * @param mixed                          $weight
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    private static function reweigh(array $cells, int $at, $weight): array
    {
        if (! is_numeric($weight) || $weight <= 0 || $weight > 1) {
            throw new \InvalidArgumentException(esc_html('A cell weight is a number above 0 and at most 1 (its share of the row).'));
        }
        $weight = $weight + 0;
        if (1 === count($cells)) {
            $cells[0]['cell']['weight'] = 1;
            return $cells;
        }
        $rest = 0.0;
        foreach ($cells as $i => $cell) {
            if ($i !== $at) {
                $rest += (float) ($cell['cell']['weight'] ?? 0);
            }
        }
        $others = count($cells) - 1;
        foreach ($cells as $i => $cell) {
            if ($i === $at) {
                $cells[ $i ]['cell']['weight'] = $weight;
                continue;
            }
            $share = $rest > 0 ? (float) ($cell['cell']['weight'] ?? 0) / $rest : 1 / $others;
            $cells[ $i ]['cell']['weight'] = round((1 - $weight) * $share, 4);
        }

        return $cells;
    }

    /**
     * @param array<int,array<string,mixed>> $cells
     * @return array<int,array<string,mixed>>
     */
    private static function normalize(array $cells): array
    {
        $cells = array_values($cells);
        $sum   = 0.0;
        foreach ($cells as $cell) {
            $sum += (float) ($cell['cell']['weight'] ?? 0);
        }
        foreach ($cells as $i => $cell) {
            $cells[ $i ]['cell']['weight'] = 1 === count($cells) ? 1 : ($sum > 0 ? round((float) ($cell['cell']['weight'] ?? 0) / $sum, 4) : round(1 / count($cells), 4));
        }

        return $cells;
    }
}
