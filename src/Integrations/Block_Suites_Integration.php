<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Blocks\Block_Tree;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Block suite packs (issue #287) as one pro dispatcher pair,
 * wpmcp/block-suites-read and wpmcp/block-suites-write, registered only
 * while at least one supported suite is loaded. This first slice covers
 * Kadence Blocks and GenerateBlocks (see Block_Suite); pattern libraries
 * are not part of it.
 *
 * Every write goes through the surgical block engine (Block_Tree): the
 * expected_hash freshness proof, the round-trip guard, and one post
 * snapshot per call, so rollback-operation restores the exact bytes. On
 * top of that engine a suite write:
 *  - checks every block of an active suite against its registered
 *    attribute schema (the suite's block.json), refusing unknown
 *    attributes, wrong types and enum misses before any snapshot;
 *  - gives each suite block a unique id in the shape the suite's editor
 *    generates, replacing a missing, placeholder, duplicate or foreign id
 *    and rewriting the old id (or the __UNIQUE_ID__ placeholder) inside
 *    that block's own markup, so its CSS selectors match;
 *  - compiles a GenerateBlocks 2 block's css attribute from its styles
 *    (Block_Suite_Styles), which the front end prints verbatim;
 *  - then drops the suite's cached per-post CSS, as a rollback does too
 *    (Block_Suite::refresh_after_restore on wpmcp_rollback_post_restored).
 */
final class Block_Suites_Integration extends Integration_Dispatcher
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Attributes WordPress itself adds to every block type. */
    private const CORE_ATTRIBUTES = [ 'lock', 'metadata', 'className', 'anchor', 'style' ];

    public function integration(): string
    {
        return 'block-suites';
    }

    public function is_available(): bool
    {
        foreach (array_keys(Block_Suite::specs()) as $suite) {
            if (Block_Suite::is_active($suite)) {
                return true;
            }
        }
        return false;
    }

    public function registers_only_when_available(): bool
    {
        return true;
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function domain(): string
    {
        return 'blocks';
    }

    protected function summary(): string
    {
        return 'block suites (Kadence Blocks, GenerateBlocks): their block schemas, and inserting or updating their blocks with unique ids and generated CSS';
    }

    protected function operations(): array
    {
        $suite_prop = [ 'type' => 'string', 'enum' => array_keys(Block_Suite::specs()) ];
        $edit_props = [
            'suite'         => $suite_prop,
            'id'            => [ 'type' => 'integer', 'minimum' => 1 ],
            'expected_hash' => [ 'type' => 'string' ],
            'path'          => [ 'type' => 'array', 'minItems' => 1, 'items' => [ 'type' => 'integer', 'minimum' => 0 ] ],
        ];
        $suite_ready = [ self::class, 'refuse_inactive_suite' ];

        return [
            'list-suites'       => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => 'The supported block suites: whether each is active, its version and block namespace, the attribute holding each block\'s unique id, and its CSS model (render_time: built from attributes on render; per_post_cache: a per-post stylesheet rebuilt after writes)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => [ self::class, 'list_suites' ],
            ],
            'get-block-schemas' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => 'The active suite\'s registered blocks (from its block.json files). Without name: every block with title, parent/ancestor and attribute names. With name: that block with its full attribute definitions (type, default, enum)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'suite' => $suite_prop, 'name' => [ 'type' => 'string' ] ],
                    'required'   => [ 'suite' ],
                ],
                'validate'     => $suite_ready,
                'handler'      => [ self::class, 'block_schemas' ],
            ],
            'insert-block'      => [
                'mode'              => 'write',
                'tier'              => 'pro',
                'self_snapshotting' => true,
                'description'       => 'Insert one suite block, given as block markup (it may contain inner blocks), AT path in post id; expected_hash is the content_hash from parse-blocks. Attributes are checked against the block schema. A missing, duplicate or foreign unique id is generated the way the suite\'s editor does and replaces the old id or the __UNIQUE_ID__ placeholder in that block\'s markup; GenerateBlocks css is compiled from styles. Snapshotted: rollback-operation restores the post exactly and the suite CSS is rebuilt',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $edit_props + [ 'markup' => [ 'type' => 'string', 'minLength' => 1 ] ],
                    'required'   => [ 'suite', 'id', 'expected_hash', 'path', 'markup' ],
                ],
                'validate'          => $suite_ready,
                'handler'           => [ self::class, 'insert_block' ],
            ],
            'update-block'      => [
                'mode'              => 'write',
                'tier'              => 'pro',
                'self_snapshotting' => true,
                'description'       => 'Update the suite block at path: attrs are MERGED into its attributes (null removes one) and checked against the schema; the unique id cannot change. inner_html replaces a leaf block\'s markup. GenerateBlocks css is recompiled when styles change. Snapshotted like insert-block',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $edit_props + [
                        'attrs'      => [ 'type' => 'object' ],
                        'inner_html' => [ 'type' => 'string' ],
                    ],
                    'required'   => [ 'suite', 'id', 'expected_hash', 'path' ],
                ],
                'validate'          => $suite_ready,
                'handler'           => [ self::class, 'update_block' ],
            ],
        ];
    }

    /** Refuse, before any handler or snapshot, an op on a suite that is not loaded. */
    public static function refuse_inactive_suite(array $args): ?array
    {
        $suite = (string) ($args['suite'] ?? '');
        if (Block_Suite::is_active($suite)) {
            return null;
        }
        $spec = Block_Suite::spec($suite);
        return [
            'code'    => 'suite_unavailable',
            'message' => sprintf('%s is not active on this site.', null === $spec ? $suite : $spec['label']),
            'data'    => [ 'suite' => $suite ],
        ];
    }

    public static function list_suites(): array
    {
        $suites = [];
        foreach (Block_Suite::specs() as $suite => $spec) {
            $suites[] = [
                'suite'               => $suite,
                'label'               => $spec['label'],
                'active'              => Block_Suite::is_active($suite),
                'version'             => Block_Suite::version($suite),
                'namespace'           => $spec['namespace'],
                'unique_id_attribute' => $spec['id_attr'],
                'css_model'           => $spec['css_model'],
            ];
        }
        return [ 'suites' => $suites, 'placeholder' => Block_Suite::PLACEHOLDER ];
    }

    public static function block_schemas(array $args): array
    {
        $suite = (string) $args['suite'];
        $spec  = (array) Block_Suite::spec($suite);
        $name  = isset($args['name']) ? (string) $args['name'] : '';
        $types = [];

        if ('' !== $name) {
            $type = self::suite_block_type($suite, $name);
            $types = [ $name => $type ];
        } else {
            foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $block_name => $type) {
                if (0 === strpos((string) $block_name, $spec['namespace'])) {
                    $types[ (string) $block_name ] = $type;
                }
            }
            ksort($types, SORT_STRING);
        }

        $blocks = [];
        foreach ($types as $block_name => $type) {
            $attributes = is_array($type->attributes ?? null) ? $type->attributes : [];
            $entry      = [
                'name'       => $block_name,
                'title'      => (string) ($type->title ?? ''),
                'parent'     => $type->parent ?? null,
                'ancestor'   => $type->ancestor ?? null,
                'is_dynamic' => is_callable($type->render_callback ?? null),
            ];
            $entry['attributes'] = '' === $name ? array_keys($attributes) : $attributes;
            if ('' !== $name) {
                $entry['supports'] = is_array($type->supports ?? null) ? $type->supports : [];
            }
            $blocks[] = $entry;
        }

        return [
            'suite'               => $suite,
            'unique_id_attribute' => $spec['id_attr'],
            'blocks'              => $blocks,
        ];
    }

    /** @param array{session_id:string} $context */
    public static function insert_block(array $args, array $context): array
    {
        $suite = (string) $args['suite'];
        [ $post_id, $blocks ] = self::load($args);
        $node = self::single_block((string) $args['markup']);
        if (Block_Suite::suite_of((string) $node['blockName']) !== $suite) {
            throw new Operation_Error('unknown_suite_block', sprintf('The inserted block "%s" is not a %s block.', (string) $node['blockName'], Block_Suite::spec($suite)['label']));
        }

        $path  = self::path($args);
        $taken = self::collect_ids($blocks);
        $ids   = [];
        $node  = self::normalize($node, $post_id, $taken, $ids, $path);

        try {
            $blocks = Block_Tree::insert($blocks, $path, $node);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_edit', $e->getMessage());
        }

        return self::commit($suite, $post_id, $blocks, $path, $ids, 'insert-block', $args, $context);
    }

    /** @param array{session_id:string} $context */
    public static function update_block(array $args, array $context): array
    {
        $suite = (string) $args['suite'];
        [ $post_id, $blocks ] = self::load($args);
        $path = self::path($args);
        try {
            $node = Block_Tree::get($blocks, $path);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_edit', $e->getMessage());
        }
        $name = (string) ($node['blockName'] ?? '');
        if (Block_Suite::suite_of($name) !== $suite) {
            throw new Operation_Error('unknown_suite_block', sprintf('The block at that path is "%s", not a %s block.', $name, Block_Suite::spec($suite)['label']));
        }
        $type    = self::suite_block_type($suite, $name);
        $id_attr = Block_Suite::spec($suite)['id_attr'];
        $current = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $patch   = isset($args['attrs']) ? (array) $args['attrs'] : [];

        if (! isset($args['attrs']) && ! isset($args['inner_html'])) {
            throw new Operation_Error('invalid_block_edit', 'At least one of "attrs" or "inner_html" is required.');
        }
        if (array_key_exists($id_attr, $patch) && ($patch[ $id_attr ] ?? '') !== ($current[ $id_attr ] ?? '') && '' !== (string) ($current[ $id_attr ] ?? '')) {
            throw new Operation_Error('unique_id_immutable', sprintf('The block\'s %s cannot be changed: its CSS and markup are keyed on it.', $id_attr));
        }

        $attrs = $current;
        foreach ($patch as $key => $value) {
            if (null === $value) {
                unset($attrs[ $key ]);
            } else {
                $attrs[ (string) $key ] = $value;
            }
        }
        $problem = self::attribute_problem($type, $attrs);
        if (null !== $problem) {
            throw new Operation_Error('invalid_block_attributes', $problem, [ 'block' => $name ]);
        }
        $node['attrs'] = $attrs;

        if (isset($args['inner_html'])) {
            if (! empty($node['innerBlocks'])) {
                throw new Operation_Error('invalid_block_edit', 'Refusing "inner_html" on a block that has inner blocks: target the inner block by its own path instead.');
            }
            $node['innerHTML']    = (string) $args['inner_html'];
            $node['innerContent'] = [ (string) $args['inner_html'] ];
        }

        // Keep the existing id (a block that never had one gets one), and
        // recompile GenerateBlocks css when its styles were part of the patch.
        $taken = self::collect_ids($blocks, $path);
        $ids   = [];
        $node  = self::assign_id($node, $suite, $post_id, $taken, $ids, $path);
        if (Block_Suite::GENERATEBLOCKS === $suite && array_key_exists('styles', $patch) && ! array_key_exists('css', $patch)) {
            $node = self::compile_css($node);
        }

        try {
            $blocks = Block_Tree::replace($blocks, $path, $node);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_edit', $e->getMessage());
        }

        return self::commit($suite, $post_id, $blocks, $path, $ids, 'update-block', $args, $context);
    }

    /** @return array{0:int,1:array} */
    private static function load(array $args): array
    {
        $post_id = (int) $args['id'];
        if (get_post($post_id) && ! current_user_can('edit_post', $post_id)) {
            throw new Operation_Error('operation_denied', 'You cannot edit this post.', [ 'reason' => 'capability' ]);
        }
        try {
            [ $post_id, , $blocks ] = Block_Tree::read_for_edit([ 'id' => $post_id, 'expected_hash' => (string) $args['expected_hash'] ]);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_edit', $e->getMessage());
        }
        return [ $post_id, $blocks ];
    }

    private static function path(array $args): array
    {
        try {
            return Block_Tree::normalize_path($args['path'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_edit', $e->getMessage());
        }
    }

    /** Write through the block engine (one post snapshot), then refresh the suite's CSS. */
    private static function commit(string $suite, int $post_id, array $blocks, array $path, array $ids, string $op, array $args, array $context): array
    {
        $out = Block_Tree::write($post_id, $blocks, 'block-suites-write', [
            'session_id' => (string) $context['session_id'],
            'operation'  => $op,
            'args'       => $args,
        ]);

        return [
            'result'        => [
                'id'           => $post_id,
                'path'         => $path,
                'content_hash' => $out['content_hash'],
                'unique_ids'   => $ids,
                'css'          => Block_Suite::refresh_css($suite, $post_id),
            ],
            'operation_ids' => [ $out['operation_id'] ],
        ];
    }

    /** Parse markup that must hold exactly one top-level block. */
    private static function single_block(string $markup): array
    {
        $parsed = array_values(array_filter(
            parse_blocks($markup),
            static fn (array $b) => null !== $b['blockName'] || '' !== trim((string) ($b['innerHTML'] ?? ''))
        ));
        if (1 !== count($parsed) || null === $parsed[0]['blockName']) {
            throw new Operation_Error('invalid_block_edit', '"markup" must contain exactly one top-level block (a single "<!-- wp:... -->" delimited block, which may nest inner blocks).');
        }
        return $parsed[0];
    }

    /**
     * Validate and id every block of an active suite in an inserted subtree,
     * depth first. Blocks of other namespaces pass through as written.
     *
     * @param array<string,array<string,bool>> $taken suite => ids in use
     * @param array<int,array<string,mixed>>   $ids   out: the ids assigned
     */
    private static function normalize(array $node, int $post_id, array &$taken, array &$ids, array $rel_path): array
    {
        $name  = (string) ($node['blockName'] ?? '');
        $suite = '' === $name ? null : Block_Suite::suite_of($name);
        if (null !== $suite && Block_Suite::is_active($suite)) {
            $type    = self::suite_block_type($suite, $name);
            $attrs   = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $problem = self::attribute_problem($type, $attrs);
            if (null !== $problem) {
                throw new Operation_Error('invalid_block_attributes', $problem, [ 'block' => $name ]);
            }
            $node = self::assign_id($node, $suite, $post_id, $taken, $ids, $rel_path);
            if (Block_Suite::GENERATEBLOCKS === $suite && ! empty($node['attrs']['styles'])) {
                $node = self::compile_css($node);
            }
        }

        foreach ((array) ($node['innerBlocks'] ?? []) as $i => $child) {
            $node['innerBlocks'][ $i ] = self::normalize($child, $post_id, $taken, $ids, array_merge($rel_path, [ (int) $i ]));
        }
        return $node;
    }

    /**
     * Keep a valid id or generate one, rewriting the old id or the
     * placeholder inside this block's own markup fragments.
     *
     * @param array<string,array<string,bool>> $taken
     * @param array<int,array<string,mixed>>   $ids
     */
    private static function assign_id(array $node, string $suite, int $post_id, array &$taken, array &$ids, array $path): array
    {
        $id_attr = Block_Suite::spec($suite)['id_attr'];
        $type    = self::suite_block_type($suite, (string) $node['blockName']);
        if (! isset($type->attributes[ $id_attr ])) {
            return $node; // A block of the suite without an id attribute.
        }
        $taken[ $suite ] = $taken[ $suite ] ?? [];
        $old             = $node['attrs'][ $id_attr ] ?? '';
        $keep            = Block_Suite::keeps_id($suite, $old, $post_id, $taken[ $suite ]);
        $new             = $keep ? (string) $old : Block_Suite::generate_id($suite, $post_id, $taken[ $suite ]);

        $node['attrs'][ $id_attr ] = $new;
        $taken[ $suite ][ $new ]    = true;

        $search = [ Block_Suite::PLACEHOLDER ];
        if (! $keep && is_string($old) && strlen($old) >= 4 && Block_Suite::PLACEHOLDER !== $old) {
            $search[] = $old;
        }
        $swap                 = static fn ($fragment) => is_string($fragment) ? str_replace($search, $new, $fragment) : $fragment;
        $node['innerHTML']    = $swap((string) ($node['innerHTML'] ?? ''));
        $node['innerContent'] = array_map($swap, (array) ($node['innerContent'] ?? []));
        if (isset($node['attrs']['css']) && is_string($node['attrs']['css'])) {
            $node['attrs']['css'] = str_replace($search, $new, $node['attrs']['css']);
        }

        $ids[] = [ 'path' => $path, 'block' => (string) $node['blockName'], 'unique_id' => $new, 'generated' => ! $keep ];
        return $node;
    }

    /** Set a GenerateBlocks 2 block's css attribute from its styles. */
    private static function compile_css(array $node): array
    {
        $selector = Block_Suite_Styles::selector((string) $node['blockName'], (string) ($node['attrs']['uniqueId'] ?? ''));
        if (null === $selector || '' === (string) ($node['attrs']['uniqueId'] ?? '')) {
            return $node;
        }
        $styles = $node['attrs']['styles'] ?? [];
        try {
            $node['attrs']['css'] = Block_Suite_Styles::compile($selector, is_array($styles) ? $styles : []);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('invalid_block_attributes', $e->getMessage(), [ 'block' => (string) $node['blockName'] ]);
        }
        return $node;
    }

    /**
     * The ids of every suite block already in the post, keyed by suite,
     * skipping the node at $skip (the block an update rewrites).
     *
     * @return array<string,array<string,bool>>
     */
    private static function collect_ids(array $blocks, ?array $skip = null, array $prefix = []): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            $path  = array_merge($prefix, [ (int) $i ]);
            $suite = Block_Suite::suite_of((string) ($block['blockName'] ?? ''));
            if (null !== $suite && $path !== $skip) {
                $id = $block['attrs'][ Block_Suite::spec($suite)['id_attr'] ] ?? '';
                if (is_string($id) && '' !== $id) {
                    $out[ $suite ][ $id ] = true;
                }
            }
            foreach (self::collect_ids((array) ($block['innerBlocks'] ?? []), $skip, $path) as $s => $ids) {
                $out[ $s ] = ($out[ $s ] ?? []) + $ids;
            }
        }
        return $out;
    }

    /** The registered block type of a suite block, or an unknown_suite_block refusal. */
    private static function suite_block_type(string $suite, string $name): \WP_Block_Type
    {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);
        if (! $type || Block_Suite::suite_of($name) !== $suite) {
            throw new Operation_Error('unknown_suite_block', sprintf('"%s" is not a registered %s block.', $name, (string) (Block_Suite::spec($suite)['label'] ?? $suite)), [ 'block' => $name ]);
        }
        return $type;
    }

    /** Why a set of attributes does not fit the block's registered schema, or null. */
    private static function attribute_problem(\WP_Block_Type $type, array $attrs): ?string
    {
        $schema = is_array($type->attributes ?? null) ? $type->attributes : [];
        foreach ($attrs as $key => $value) {
            $key = (string) $key;
            if (! isset($schema[ $key ])) {
                if (in_array($key, self::CORE_ATTRIBUTES, true)) {
                    continue;
                }
                return sprintf('"%s" is not an attribute of %s. Read its schema with get-block-schemas.', $key, $type->name);
            }
            $def = (array) $schema[ $key ];
            if (isset($def['type']) && ! self::type_matches((array) $def['type'], $value)) {
                return sprintf('Attribute "%s" of %s must be of type %s.', $key, $type->name, implode('|', (array) $def['type']));
            }
            if (isset($def['enum']) && is_array($def['enum']) && ! in_array($value, $def['enum'], true)) {
                return sprintf('Attribute "%s" of %s must be one of: %s.', $key, $type->name, implode(', ', array_map('wp_json_encode', $def['enum'])));
            }
            if ('styles' === $key && 0 === strpos($type->name, 'generateblocks/')) {
                $problem = Block_Suite_Styles::problem($value);
                if (null !== $problem) {
                    return $problem;
                }
            }
            if ('css' === $key && is_string($value) && false !== strpos($value, '<')) {
                return 'Attribute "css" must not contain "<".';
            }
        }
        return null;
    }

    /**
     * @param string[] $types
     * @param mixed    $value
     */
    private static function type_matches(array $types, $value): bool
    {
        foreach ($types as $type) {
            switch ($type) {
                case 'string':
                case 'rich-text':
                    $ok = is_string($value);
                    break;
                case 'number':
                    $ok = is_int($value) || is_float($value);
                    break;
                case 'integer':
                    $ok = is_int($value);
                    break;
                case 'boolean':
                    $ok = is_bool($value);
                    break;
                case 'array':
                    $ok = is_array($value) && ([] === $value || array_is_list($value));
                    break;
                case 'object':
                    $ok = is_array($value) && ([] === $value || ! array_is_list($value));
                    break;
                case 'null':
                    $ok = null === $value;
                    break;
                default:
                    $ok = true; // A type this check does not model is left to the suite.
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
