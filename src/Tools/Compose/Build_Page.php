<?php

namespace WPMCP\Tools\Compose;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Atomic_Prop_Schema;
use WPMCP\Tools\Elementor\Elementor_Page_Data;
use WPMCP\Tools\Elementor\Widget_Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-call declarative page composition (issue #57).
 *
 * The agent submits a single spec (title, sections/blocks tree, media
 * references, menu placement — shape documented on Page_Spec) and gets back
 * a complete page in ONE operation. The pipeline is ordered so nothing is
 * half-created:
 *
 *  1. Page_Spec::validate() — strict structural validation, node-path
 *     addressed, bounded payload. Pure; a malformed spec has NO side effects.
 *  2. preflight() — referential validation against live state (patterns
 *     registered, attachments exist, menu exists, Elementor widgets known),
 *     still before any write.
 *  3. compose — deterministic markup / element tree from the spec (pure;
 *     nothing from the spec is evaluated or executed).
 *  4. The write phase, wrapped as one atomic unit: create the page, attach
 *     media, place the menu item. ANY failure triggers compensation — every
 *     object created so far is deleted — and the error is rethrown, so a
 *     mid-build failure leaves no orphan page/media/menu rows and no
 *     history entry.
 *  5. On success, exactly ONE 'page_build' snapshot row is written under a
 *     single operation_id. Rollback_Service::apply_page_build_snapshot()
 *     reverses the entire composition: the page and its menu placement
 *     vanish with one rollback-operation call.
 *
 * Creation normally has no prior state to snapshot (see Create_Post); the
 * 'page_build' snapshot instead records WHAT WAS CREATED, so the undo is a
 * deletion rather than a restore. The snapshot is written AFTER the
 * mutation (unlike Safe_Mutation's capture-first order) because the created
 * ids cannot exist before the mutation runs; atomicity is provided by the
 * compensation path above, which guarantees the snapshot only ever
 * describes a fully-built page.
 *
 * The Gutenberg dialect is free; the builder (Elementor) dialect is gated
 * PRO via Pro\Gate before any write.
 */
class Build_Page
{
    /** Element count above which a one-shot page build is worth a warning (issue #137). */
    public const LARGE_PAGE_ELEMENTS = 150;

    public function handle(array $args): array
    {
        $spec = Page_Spec::validate($args['spec'] ?? null);

        if ('elementor' === $spec['dialect']) {
            if (! Gate::can_use('build-page-builder')) {
                throw new \RuntimeException('The builder (Elementor) dialect is not enabled for this install; the Gutenberg dialect is always available.');
            }
            if (! class_exists('\\Elementor\\Plugin')) {
                throw new \RuntimeException('The builder dialect requires Elementor to be active on this site.');
            }
        }

        if (! empty($args['dry_run'])) {
            return $this->dry_run($spec);
        }

        $this->preflight($spec);

        if ('elementor' === $spec['dialect']) {
            $composed = Elementor_Composer::compose($spec['content']);
            $content  = '';
        } else {
            $composed = Block_Composer::compose($spec['content']);
            $content  = $composed['markup'];
        }

        $operation_id  = wp_generate_uuid4();
        $post_id       = 0;
        $menu_item_ids = [];

        try {
            $postarr = [
                'post_type'    => 'page',
                'post_status'  => $spec['status'],
                'post_title'   => sanitize_text_field($spec['title']),
                'post_content' => $content,
            ];
            if ('' !== $spec['slug']) {
                $postarr['post_name'] = sanitize_title($spec['slug']);
            }

            $result = wp_insert_post($postarr, true);
            if (is_wp_error($result)) {
                throw new \RuntimeException('Could not create the page: ' . $result->get_error_message());
            }
            $post_id = (int) $result;

            if ('elementor' === $spec['dialect']) {
                Elementor_Page_Data::save($post_id, $composed['elements']);
                update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            }

            if (! empty($spec['media']['featured'])) {
                set_post_thumbnail($post_id, (int) $spec['media']['featured']);
            }

            if ([] !== $spec['menu']) {
                $menu_item_ids[] = $this->place_in_menu($spec['menu'], $post_id, $spec['title']);
            }

            $this->record_operation($operation_id, (string) ($args['session_id'] ?? 'default'), $post_id, $menu_item_ids, $args);
        } catch (\Throwable $e) {
            $this->compensate($post_id, $menu_item_ids);
            throw $e;
        }

        $out = [
            'post_id'          => $post_id,
            'status'           => $spec['status'],
            'edit_url'         => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'preview_url'      => 'publish' === $spec['status']
                ? (string) get_permalink($post_id)
                : (string) get_preview_post_link($post_id),
            'created_elements' => $composed['count'],
            'menu_item_id'     => $menu_item_ids[0] ?? null,
            'operation_id'     => $operation_id,
            'recoverable'      => true,
        ];

        // Prop repairs the builder dialect had to make are reported on the
        // real build too, not just in dry_run, so a caller that skipped the
        // dry run still learns what was changed on the way in.
        if ([] !== ($composed['coerced'] ?? [])) {
            $out['coerced'] = array_values($composed['coerced']);
        }
        if ([] !== ($composed['warnings'] ?? [])) {
            $out['warnings'] = array_values($composed['warnings']);
        }

        return $out;
    }

    /**
     * Referential validation against live site state — everything the spec
     * points at must exist BEFORE any write happens, path-addressed like the
     * structural checks. The first problem aborts the build; dry_run uses the
     * same inspection but reports the whole list instead of throwing.
     */
    private function preflight(array $spec): void
    {
        $problems = $this->inspect($spec);

        if ([] !== $problems) {
            throw new \InvalidArgumentException(esc_html($problems[0]));
        }
    }

    /**
     * Every referential problem in the spec, in document order, addressed by
     * node path. Pure: reads live state, writes nothing.
     *
     * @return array<int,string>
     */
    private function inspect(array $spec): array
    {
        $problems = [];

        if (! empty($spec['media']['featured'])) {
            $missing = $this->missing_attachment((int) $spec['media']['featured'], 'spec.media.featured');
            if (null !== $missing) {
                $problems[] = $missing;
            }
        }

        if ([] !== $spec['menu'] && ! wp_get_nav_menu_object((int) $spec['menu']['menu_id'])) {
            $problems[] = sprintf('spec.menu: menu %d was not found', (int) $spec['menu']['menu_id']);
        }

        $this->walk($spec['content'], 'content', function (array $node, string $path) use ($spec, &$problems): void {
            if ('pattern' === $node['type']) {
                $slug = (string) $node['settings']['slug'];
                if (! \WP_Block_Patterns_Registry::get_instance()->is_registered($slug)) {
                    $problems[] = sprintf('%s: block pattern "%s" is not registered', $path, $slug);
                }
            }
            if ('image' === $node['type'] && ! empty($node['settings']['attachment_id'])) {
                $missing = $this->missing_attachment((int) $node['settings']['attachment_id'], $path);
                if (null !== $missing) {
                    $problems[] = $missing;
                }
            }
            if ('widget' === $node['type'] && 'elementor' === $spec['dialect']) {
                $problem = $this->widget_problem((string) $node['settings']['widget']);
                if (null !== $problem) {
                    $problems[] = $path . ': ' . $problem;
                }
            }
        });

        return $problems;
    }

    /**
     * Why a widget type cannot be built here, or null when it can.
     *
     * Registration on THIS site stays the authority: a spec must never
     * compose an element the site cannot render. The curated Widget_Catalog
     * is consulted only to explain the failure: a widget we know but Elementor
     * has not registered means its plugin is missing or inactive, which is a
     * very different fix from a typo, and worth saying out loud.
     */
    private function widget_problem(string $widget): ?string
    {
        if (Atomic_Prop_Schema::known($widget)) {
            return null;
        }

        $registered = class_exists('\\Elementor\\Plugin')
            && null !== \Elementor\Plugin::instance()->widgets_manager->get_widget_types($widget);

        if ($registered) {
            return null;
        }

        $entry = Widget_Catalog::get($widget);
        if (null !== $entry) {
            return sprintf(
                'Elementor widget type "%s" is in the wpmcp catalog but is not registered on this site; it needs %s to be active',
                $widget,
                (string) $entry['requires']
            );
        }

        return sprintf('unknown Elementor widget type "%s"', $widget);
    }

    /** Depth-first walk over normalized nodes with spec paths. */
    private function walk(array $nodes, string $prefix, callable $visit): void
    {
        foreach (array_values($nodes) as $i => $node) {
            $path = $prefix . '[' . $i . ']';
            $visit($node, $path);
            if ([] !== $node['children']) {
                $this->walk($node['children'], $path . '.children', $visit);
            }
        }
    }

    private function missing_attachment(int $attachment_id, string $path): ?string
    {
        $post = get_post($attachment_id);
        if (! $post || 'attachment' !== $post->post_type) {
            return sprintf('%s: attachment %d was not found', $path, $attachment_id);
        }

        return null;
    }

    /**
     * dry_run: validate, inspect, and compose, then write nothing.
     *
     * The spec has already been through Page_Spec (structure) by the time we
     * get here; this adds the two things an agent cannot check for itself
     * before committing: what the composition would actually produce (element
     * counts per node type, nesting depth, markup size) and everything the
     * live site would object to (missing attachments, unregistered patterns
     * and widget types, atomic props that had to be renamed, rewrapped, or
     * refused). Composition is a pure transform, so running it here has no
     * side effects at all.
     */
    private function dry_run(array $spec): array
    {
        $problems = $this->inspect($spec);

        if ('elementor' === $spec['dialect']) {
            $composed = Elementor_Composer::compose($spec['content']);
            $markup   = 0;
        } else {
            $composed = Block_Composer::compose($spec['content']);
            $markup   = strlen($composed['markup']);
        }

        $counts  = [];
        $depth   = 0;
        $unknown = [];

        $this->walk($spec['content'], 'content', function (array $node, string $path) use (&$counts, &$depth, &$unknown, $spec): void {
            $type = 'widget' === $node['type'] && 'elementor' === $spec['dialect']
                ? 'widget:' . (string) $node['settings']['widget']
                : $node['type'];

            $counts[ $type ] = ($counts[ $type ] ?? 0) + 1;
            $depth           = max($depth, substr_count($path, '.children[') + 1);

            if ('widget' === $node['type'] && 'elementor' === $spec['dialect']) {
                if (null !== $this->widget_problem((string) $node['settings']['widget'])) {
                    $unknown[] = (string) $node['settings']['widget'];
                }
            }
        });

        ksort($counts);

        $warnings = array_merge($problems, $composed['warnings'] ?? []);
        if ($composed['count'] > self::LARGE_PAGE_ELEMENTS) {
            $warnings[] = sprintf(
                'This spec composes %d elements. Pages over %d elements are slow to edit and easy to get wrong in one shot; '
                . 'consider building the page in sections and appending them.',
                $composed['count'],
                self::LARGE_PAGE_ELEMENTS
            );
        }

        return [
            'dry_run'          => true,
            'valid'            => [] === $problems,
            'title'            => $spec['title'],
            'status'           => $spec['status'],
            'dialect'          => $spec['dialect'],
            'created_elements' => $composed['count'],
            'element_counts'   => $counts,
            'max_depth'        => $depth,
            'markup_bytes'     => $markup,
            'unknown_widgets'  => array_values(array_unique($unknown)),
            'coerced'          => array_values($composed['coerced'] ?? []),
            'warnings'         => array_values($warnings),
            'would_create'     => [
                'page'      => 1,
                'menu_item' => [] !== $spec['menu'] ? 1 : 0,
                'featured'  => ! empty($spec['media']['featured']) ? (int) $spec['media']['featured'] : 0,
            ],
        ];
    }

    /** Add the created page to the requested menu; WP_Error becomes a build failure (compensated by the caller). */
    private function place_in_menu(array $menu, int $post_id, string $page_title): int
    {
        $item_id = wp_update_nav_menu_item((int) $menu['menu_id'], 0, [
            'menu-item-title'     => '' !== trim((string) ($menu['title'] ?? '')) ? (string) $menu['title'] : $page_title,
            'menu-item-type'      => 'post_type',
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $post_id,
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => (int) ($menu['parent'] ?? 0),
            'menu-item-position'  => (int) ($menu['position'] ?? 0),
        ]);

        if (is_wp_error($item_id)) {
            throw new \RuntimeException('The menu placement step failed: ' . esc_html($item_id->get_error_message()));
        }

        return (int) $item_id;
    }

    /**
     * Persist the single 'page_build' history row for the whole composition.
     * post_date_gmt is captured for the same identity check the post-restore
     * path uses: rollback must never delete a different post that has since
     * reclaimed the id.
     */
    private function record_operation(string $operation_id, string $session_id, int $post_id, array $menu_item_ids, array $args): void
    {
        $post = get_post($post_id);

        Snapshot_Store::save(
            $operation_id,
            $session_id,
            [
                'object_type' => 'page_build',
                'object_id'   => $post_id,
                'data'        => [
                    'post_id'       => $post_id,
                    'post_date_gmt' => $post ? $post->post_date_gmt : null,
                    'menu_item_ids' => array_map('intval', $menu_item_ids),
                ],
            ],
            'build-page',
            hash('sha256', (string) wp_json_encode($args))
        );
        Snapshot_Store::prune(Gate::history_limit());
    }

    /** Undo a partial build: delete everything created so far, newest first. */
    private function compensate(int $post_id, array $menu_item_ids): void
    {
        foreach ($menu_item_ids as $item_id) {
            wp_delete_post((int) $item_id, true);
        }
        if ($post_id > 0) {
            wp_delete_post($post_id, true);
        }
    }
}
