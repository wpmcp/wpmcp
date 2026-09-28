<?php

namespace WPMCP\Tools\SiteEditor;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Site_Template_Snapshot;
use WPMCP\Tools\Blocks\Block_Tree;
use WPMCP\Tools\Blocks\Serialize_Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * site-templates-write (issue #378): change a block theme template, template
 * part or wp_navigation menu.
 *
 * Actions:
 *  - save: replace the whole content, from "content" (block markup) or
 *    "blocks" (a parsed tree as site-templates-read returns it). For a
 *    template that only lives in a theme file this creates the user
 *    customization core renders instead; the theme file is never written.
 *  - add_block, update_block, remove_block: one block by "path", with the
 *    same targeting and expected_hash freshness rule as the surgical block
 *    tools, through the same Block_Tree helpers.
 *  - revert (templates and parts only): delete the customization, so the
 *    template renders from its theme file again.
 *
 * Every write is one snapshot. Templates and parts are snapshotted by key
 * (Site_Template_Snapshot), so rolling back a first save deletes only the
 * customization it created, and rolling back a revert brings the
 * customization back at its original ID. A navigation menu is an ordinary
 * post and is snapshotted as one.
 *
 * Content that does not round-trip through parse/serialize is refused for a
 * path edit of stored content, as the block tools refuse it. A theme file
 * is the exception: a path edit there creates a new customization from the
 * serialized tree, so there are no stored bytes of untouched blocks to keep.
 */
class Site_Templates_Write
{
    private const TOOL = 'site-templates-write';

    private const PATH_ACTIONS = ['add_block', 'update_block', 'remove_block'];

    public function handle(array $args): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $action = (string) ($args['action'] ?? 'save');
        $id     = $args['id'] ?? null;
        if (null === $id || '' === $id) {
            throw new \InvalidArgumentException('"id" is required.');
        }
        if ('save' !== $action && 'revert' !== $action && ! in_array($action, self::PATH_ACTIONS, true)) {
            throw new \InvalidArgumentException('"action" must be one of: save, add_block, update_block, remove_block, revert.');
        }

        if ('navigation' === $entity) {
            return $this->write_navigation($id, $action, $args);
        }

        $resolved = Site_Templates::resolve($entity, (string) $id);
        if ('revert' === $action) {
            return $this->revert($resolved, $args);
        }
        return $this->save_template($resolved, $action, $args);
    }

    private function write_navigation($id, string $action, array $args): array
    {
        $post = Site_Templates::navigation($id);
        if ('revert' === $action) {
            throw new \InvalidArgumentException('A navigation menu has no theme file to revert to; use save or a path action.');
        }

        $current = (string) $post->post_content;
        $blocks  = $this->new_blocks($current, $action, $args, true);

        return ['entity' => 'navigation'] + Block_Tree::write($post->ID, $blocks, self::TOOL, $args);
    }

    private function save_template(array $resolved, string $action, array $args): array
    {
        $template = $resolved['template'];
        if (null === $template && 'save' !== $action) {
            throw new \InvalidArgumentException(sprintf('No template "%s" in the active theme to edit by path.', esc_html($resolved['slug'])));
        }

        $stored  = null !== $template && (int) $template->wp_id > 0;
        $current = null === $template ? '' : Site_Templates::content($template);
        $blocks  = $this->new_blocks($current, $action, $args, $stored);
        $new     = 'save' === $action ? $this->save_markup($args) : serialize_blocks($blocks);

        $wp_id = $stored ? (int) $template->wp_id : 0;
        $post  = $this->insert_fields($resolved, $template, $args);
        $area  = $post['area'] ?? null;
        unset($post['area']);

        $out = $this->mutate($resolved, $args, function () use (&$wp_id, $new, $post, $area, $resolved) {
            if ($wp_id > 0) {
                wp_update_post(wp_slash(['ID' => $wp_id, 'post_content' => $new]));
                return true;
            }
            $inserted = wp_insert_post(wp_slash($post + ['post_content' => $new]), true);
            if (is_wp_error($inserted)) {
                throw new \RuntimeException(esc_html($inserted->get_error_message()));
            }
            $wp_id = (int) $inserted;
            wp_set_post_terms($wp_id, [$resolved['theme']], 'wp_theme');
            if (null !== $area) {
                wp_set_post_terms($wp_id, [$area], 'wp_template_part_area');
            }
            return true;
        }, function () use (&$wp_id, $new) {
            clean_post_cache($wp_id);
            $saved = $wp_id > 0 ? get_post($wp_id) : null;
            return $saved && (string) $saved->post_content === $new;
        });

        return [
            'operation_id' => $out['operation_id'],
            'id'           => $resolved['theme'] . '//' . $resolved['slug'],
            'wp_id'        => $wp_id,
            'source'       => 'custom',
            'content_hash' => hash('sha256', $new),
        ];
    }

    private function revert(array $resolved, array $args): array
    {
        $template = $resolved['template'];
        if (null === $template || (int) $template->wp_id <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Template "%s" has no customization to revert; it already renders from the theme file.',
                esc_html($resolved['slug'])
            ));
        }
        $key = [$resolved['post_type'], $resolved['theme'], $resolved['slug']];

        $out = $this->mutate($resolved, $args, function () use ($key) {
            foreach (Site_Template_Snapshot::customization_ids(...$key) as $post_id) {
                wp_delete_post($post_id, true);
            }
            return true;
        }, static fn (): bool => [] === Site_Template_Snapshot::customization_ids(...$key));

        $fallback = get_block_template($resolved['theme'] . '//' . $resolved['slug'], $resolved['post_type']);

        return [
            'operation_id' => $out['operation_id'],
            'id'           => $resolved['theme'] . '//' . $resolved['slug'],
            'source'       => $fallback instanceof \WP_Block_Template ? $fallback->source : 'none',
            'notes'        => $fallback instanceof \WP_Block_Template ? '' : 'This template had no theme file, so reverting deleted it.',
        ];
    }

    private function mutate(array $resolved, array $args, callable $mutation, callable $verify): array
    {
        return Safe_Mutation::run(
            [
                'object_type' => Site_Template_Snapshot::TYPE,
                'object_id'   => Site_Template_Snapshot::key($resolved['post_type'], $resolved['theme'], $resolved['slug']),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => self::TOOL,
                'args'        => $args,
            ],
            $mutation,
            $verify
        );
    }

    /** The post a first customization is inserted as, the way the site editor creates one. */
    private function insert_fields(array $resolved, ?\WP_Block_Template $template, array $args): array
    {
        $fields = [
            'post_type'    => $resolved['post_type'],
            'post_status'  => 'publish',
            'post_name'    => $resolved['slug'],
            'post_title'   => (string) ($args['title'] ?? ($template->title ?? '')),
            'post_excerpt' => (string) ($template->description ?? ''),
        ];
        if ('' === $fields['post_title']) {
            $fields['post_title'] = $resolved['slug'];
        }
        if ('wp_template_part' === $resolved['post_type']) {
            $area = (string) ($args['area'] ?? ($template->area ?? WP_TEMPLATE_PART_AREA_UNCATEGORIZED));
            $allowed = wp_list_pluck(get_allowed_block_template_part_areas(), 'area');
            if (! in_array($area, $allowed, true)) {
                throw new \InvalidArgumentException('"area" must be one of: ' . esc_html(implode(', ', $allowed)) . '.');
            }
            $fields['area'] = $area;
        }
        return $fields;
    }

    /** The full-replacement markup for a save, from "content" or "blocks". */
    private function save_markup(array $args): string
    {
        if (isset($args['content'])) {
            return (string) $args['content'];
        }
        if (isset($args['blocks']) && is_array($args['blocks'])) {
            return (string) (new Serialize_Blocks())->handle(['blocks' => $args['blocks']])['markup'];
        }
        throw new \InvalidArgumentException('save needs "content" (block markup) or "blocks" (a parsed block tree).');
    }

    /**
     * The block tree after this action. For save it is the new content
     * parsed; for a path action it is the current tree with one block
     * changed, after the freshness and round-trip guards.
     */
    private function new_blocks(string $current, string $action, array $args, bool $stored): array
    {
        $expected = (string) ($args['expected_hash'] ?? '');
        if ('save' === $action) {
            if ('' !== $expected) {
                $this->check_hash($current, $expected);
            }
            return parse_blocks($this->save_markup($args));
        }

        if ('' === $expected) {
            throw new \InvalidArgumentException(
                '"expected_hash" is required for a path action: read with site-templates-read first and pass back its content_hash.'
            );
        }
        $this->check_hash($current, $expected);
        $blocks = parse_blocks($current);
        if ($stored && serialize_blocks($blocks) !== $current) {
            throw new \InvalidArgumentException(
                'Content does not round-trip cleanly through parse/serialize, so a path edit would rewrite the bytes of '
                . 'untouched blocks. Refusing; use action save to replace the full content instead.'
            );
        }

        $path = Block_Tree::normalize_path($args['path'] ?? null);
        if ('remove_block' === $action) {
            return Block_Tree::remove($blocks, $path);
        }
        if ('add_block' === $action) {
            return Block_Tree::insert($blocks, $path, $this->single_block((string) ($args['markup'] ?? '')));
        }
        return Block_Tree::replace($blocks, $path, $this->updated_node(Block_Tree::get($blocks, $path), $args));
    }

    private function check_hash(string $current, string $expected): void
    {
        if (! hash_equals(hash('sha256', $current), $expected)) {
            throw new \InvalidArgumentException(
                'Stale expected_hash: the content changed since it was read, so block paths can no longer be trusted. '
                . 'Re-read with site-templates-read and retry.'
            );
        }
    }

    /** Parse markup that must hold exactly one real block. */
    private function single_block(string $markup): array
    {
        $parsed = array_values(array_filter(
            parse_blocks($markup),
            static fn (array $b) => null !== $b['blockName'] || '' !== trim((string) ($b['innerHTML'] ?? ''))
        ));
        if (1 !== count($parsed) || null === $parsed[0]['blockName']) {
            throw new \InvalidArgumentException('"markup" must contain exactly one block (a single "<!-- wp:... -->" delimited block).');
        }
        return $parsed[0];
    }

    /** Apply "attrs" (full replacement) and/or "inner_html" (leaf blocks only) to one block. */
    private function updated_node(array $node, array $args): array
    {
        $attrs      = $args['attrs'] ?? null;
        $inner_html = $args['inner_html'] ?? null;
        if (null === $attrs && null === $inner_html) {
            throw new \InvalidArgumentException('update_block needs "attrs" and/or "inner_html".');
        }
        if (null === $node['blockName']) {
            throw new \InvalidArgumentException('The block at that path is freeform content with no block name; it cannot be updated by path.');
        }
        if (null !== $attrs) {
            if (! is_array($attrs)) {
                throw new \InvalidArgumentException('"attrs" must be an object of block attributes.');
            }
            $node['attrs'] = $attrs;
        }
        if (null !== $inner_html) {
            if (! empty($node['innerBlocks'])) {
                throw new \InvalidArgumentException(
                    'Refusing "inner_html" on a block that has innerBlocks: target the inner block by its own path instead.'
                );
            }
            $node['innerHTML']    = (string) $inner_html;
            $node['innerContent'] = [(string) $inner_html];
        }
        return $node;
    }
}
