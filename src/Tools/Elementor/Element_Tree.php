<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared engine for the Elementor structural editing suite (issue #58).
 * Not a tool itself: it reads a page's `_elementor_data` (or
 * `_elementor_page_settings`) under a freshness guard and writes it back
 * snapshot-first through Safe_Mutation.
 *
 * Concurrency contract (mirrors the surgical block tools of issue #56):
 * every mutation requires `expected_hash` — sha256 of the raw
 * `_elementor_data` JSON string (or of the JSON-encoded page settings for
 * update-page-settings) as reported by get-elementor-data / find-element /
 * the previous mutation's response. A stale hash means the page changed
 * between read and write, so the element ids and positions the caller
 * computed can no longer be trusted: the write is refused outright with a
 * structured error and nothing is touched.
 *
 * Write routing: when Elementor is fully bootstrapped (document available
 * AND an active kit exists — container controls dereference the kit during
 * save), the tree is written through Elementor's own Document::save() path,
 * which regenerates data canonically, deletes the page's generated CSS
 * (Post_CSS) and invalidates the document cache exactly as a builder save
 * would. When that path is unavailable the engine falls back to a raw
 * `_elementor_data` meta write and clears Elementor's generated-CSS cache
 * explicitly.
 *
 * Failure handling: the snapshot is captured BEFORE the write; a verify
 * step re-reads the stored tree and requires it to match the intended tree
 * (id/elType/widgetType/settings/children, ignoring Elementor's own
 * additive normalization such as isInner). A verify miss triggers
 * Safe_Mutation's automatic restore; a mid-save throwable triggers an
 * explicit restore of the same snapshot. Either way the caller gets a
 * structured 'mutation_failed' error and the page is byte-identical to its
 * pre-operation state — this is what makes batch-update atomic.
 */
class Element_Tree
{
    /** Raw `_elementor_data` JSON string ('' when absent). */
    public static function raw(int $post_id): string
    {
        $raw = get_post_meta($post_id, '_elementor_data', true);

        return is_string($raw) ? $raw : '';
    }

    public static function data_hash(int $post_id): string
    {
        return hash('sha256', self::raw($post_id));
    }

    /** Current `_elementor_page_settings` ([] when absent). */
    public static function page_settings(int $post_id): array
    {
        $settings = get_post_meta($post_id, '_elementor_page_settings', true);

        return is_array($settings) ? $settings : [];
    }

    public static function settings_hash(int $post_id): string
    {
        return hash('sha256', wp_json_encode(self::page_settings($post_id)));
    }

    /**
     * Load a page's element tree for a mutation, enforcing the existence
     * and expected_hash freshness guards.
     *
     * @return array{0:int,1:array}|\WP_Error [post_id, elements]
     */
    public static function read_for_edit(array $args)
    {
        $guard = self::guard($args, 'data');
        if (is_wp_error($guard)) {
            return $guard;
        }

        return [$guard, Elementor_Page_Data::get($guard)];
    }

    /**
     * Load a page's Elementor page settings for a mutation, enforcing the
     * same guards against the settings meta.
     *
     * @return array{0:int,1:array}|\WP_Error [post_id, settings]
     */
    public static function read_settings_for_edit(array $args)
    {
        $guard = self::guard($args, 'settings');
        if (is_wp_error($guard)) {
            return $guard;
        }

        return [$guard, self::page_settings($guard)];
    }

    /** @return int|\WP_Error the validated post id. */
    private static function guard(array $args, string $surface)
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if (! get_post($post_id)) {
            return new \WP_Error('post_not_found', "No post found with id {$post_id}.");
        }

        $expected = (string) ($args['expected_hash'] ?? '');

        if ('' === $expected) {
            return new \WP_Error(
                'missing_expected_hash',
                '"expected_hash" is required: read the page with get-elementor-data first and pass back its '
                . ('settings' === $surface ? 'settings_hash.' : 'data_hash.')
            );
        }

        $current = 'settings' === $surface ? self::settings_hash($post_id) : self::data_hash($post_id);

        if (! hash_equals($current, $expected)) {
            return new \WP_Error(
                'stale_expected_hash',
                'Stale expected_hash: the page\'s Elementor '
                . ('settings' === $surface ? 'settings' : 'data')
                . ' changed since it was read, so the targets of this edit can no longer be trusted. '
                . 'Nothing was written. Re-read with get-elementor-data and retry.'
            );
        }

        return $post_id;
    }

    /**
     * Write a mutated element tree back, snapshot-first. Returns the
     * operation envelope (operation_id + fresh data_hash for chaining) or
     * a structured 'mutation_failed' error after a full rollback.
     *
     * @return array|\WP_Error
     */
    public static function write(int $post_id, array $elements, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();
        $intended     = self::normalize($elements);

        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => $tool_name,
                    'args'         => $args,
                ],
                function () use ($post_id, $elements) {
                    self::persist($post_id, $elements);
                    return true;
                },
                function () use ($post_id, $intended) {
                    clean_post_cache($post_id);
                    return self::normalize(Elementor_Page_Data::get($post_id)) === $intended;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error(
                'mutation_failed',
                'The write did not store the intended element tree (a save filter altered or dropped elements); '
                . 'the page was rolled back to its pre-operation state.'
            );
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The write failed mid-save and the page was rolled back to its pre-operation state: '
                . $e->getMessage()
            );
        }

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'data_hash'    => self::data_hash($post_id),
        ];
    }

    /**
     * Write merged Elementor page settings back, snapshot-first, with the
     * same failure handling as write().
     *
     * @return array|\WP_Error
     */
    public static function write_settings(int $post_id, array $settings, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();

        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => $tool_name,
                    'args'         => $args,
                ],
                function () use ($post_id, $settings) {
                    self::persist_settings($post_id, $settings);
                    return true;
                },
                function () use ($post_id, $settings) {
                    clean_post_cache($post_id);
                    return self::page_settings($post_id) === $settings;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error(
                'mutation_failed',
                'The write did not store the intended page settings; '
                . 'the page was rolled back to its pre-operation state.'
            );
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The write failed mid-save and the page was rolled back to its pre-operation state: '
                . $e->getMessage()
            );
        }

        return [
            'operation_id'  => $operation_id,
            'post_id'       => $post_id,
            'settings'      => self::page_settings($post_id),
            'settings_hash' => self::settings_hash($post_id),
        ];
    }

    /** Save the tree through Elementor's Document::save() path, or fall back to a raw meta write. */
    private static function persist(int $post_id, array $elements): void
    {
        $document = self::document($post_id);

        if ($document) {
            self::document_save($document, ['elements' => $elements]);
            return;
        }

        // Raw fallback: Elementor_Page_Data::save() writes the meta and
        // clears Elementor's generated-CSS cache explicitly.
        Elementor_Page_Data::save($post_id, $elements);
    }

    private static function persist_settings(int $post_id, array $settings): void
    {
        $document = self::document($post_id);

        if ($document) {
            self::document_save($document, ['settings' => $settings]);
            return;
        }

        update_post_meta($post_id, '_elementor_page_settings', $settings);

        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /**
     * Run Document::save() with output-buffer bookkeeping: saving legacy
     * sections/columns (and any third-party element) can render content
     * into buffers it never closes, which would corrupt the MCP response.
     * Any buffer the save leaks is discarded here.
     *
     * @param object $document
     */
    private static function document_save($document, array $data): void
    {
        $level = ob_get_level();
        try {
            $saved = $document->save($data);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if (false === $saved) {
            throw new \RuntimeException('Elementor refused the document save (current user cannot edit this page).');
        }
    }

    /**
     * The page's Elementor document, only when the full save path can
     * actually run: Elementor loaded, document resolvable, and an active
     * kit present (element controls dereference the kit during save; a
     * missing kit would fatal mid-write).
     *
     * @return object|null
     */
    private static function document(int $post_id)
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return null;
        }

        $kit_id = (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
        if (! $kit_id || ! get_post($kit_id)) {
            return null;
        }

        $document = \Elementor\Plugin::instance()->documents->get($post_id, false);

        return $document ?: null;
    }

    /**
     * Reduce a tree to the shape this plugin is responsible for (id, elType,
     * widgetType, settings, children, and any atomic `styles` blob), ignoring
     * keys Elementor's own save adds or normalizes (isInner, isLocked). Used
     * by the verify step to prove the stored tree is the intended tree.
     */
    public static function normalize(array $elements): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $node = [
                'id'       => (string) ($element['id'] ?? ''),
                'elType'   => (string) ($element['elType'] ?? ''),
                'settings' => is_array($element['settings'] ?? null) ? $element['settings'] : [],
                'elements' => self::normalize(is_array($element['elements'] ?? null) ? $element['elements'] : []),
            ];

            if ('widget' === $node['elType']) {
                $node['widgetType'] = (string) ($element['widgetType'] ?? '');
            }

            // Atomic elements carry their local style classes in `styles`,
            // which is load-bearing data (an element's `classes` ref points
            // into it), so it has to be part of the verified round trip.
            // Only when non-empty, so classic elements, which never have the
            // key, keep their existing normalized shape.
            if (! empty($element['styles']) && is_array($element['styles'])) {
                $node['styles'] = $element['styles'];
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * Insert $element into $elements as a child of $parent_id ('' = top
     * level) at $position (null = append). Assumes the parent was already
     * validated to exist.
     */
    public static function insert_at(array &$elements, string $parent_id, array $element, ?int $position): bool
    {
        if ('' === $parent_id) {
            return self::splice($elements, $element, $position);
        }

        foreach ($elements as &$item) {
            if (($item['id'] ?? null) === $parent_id) {
                if (! isset($item['elements']) || ! is_array($item['elements'])) {
                    $item['elements'] = [];
                }
                return self::splice($item['elements'], $element, $position);
            }

            if (! empty($item['elements']) && is_array($item['elements'])) {
                if (self::insert_at($item['elements'], $parent_id, $element, $position)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function splice(array &$siblings, array $element, ?int $position): bool
    {
        if (null === $position || $position >= count($siblings)) {
            $siblings[] = $element;
            return true;
        }

        array_splice($siblings, max(0, $position), 0, [$element]);
        return true;
    }

    /** Insert $element immediately after the sibling with id $after_id. */
    public static function insert_after(array &$elements, string $after_id, array $element): bool
    {
        foreach ($elements as $index => &$item) {
            if (($item['id'] ?? null) === $after_id) {
                array_splice($elements, $index + 1, 0, [$element]);
                return true;
            }

            if (! empty($item['elements']) && is_array($item['elements'])) {
                if (self::insert_after($item['elements'], $after_id, $element)) {
                    return true;
                }
            }
        }

        return false;
    }
}
