<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The block suites the block-suites dispatcher pair knows (issue #287), and
 * the per-suite facts it needs: block namespace, the attribute that holds
 * each block's unique id and how the suite's editor generates it, whether
 * the suite is loaded, and how it serves the CSS it derives from block
 * attributes.
 *
 * Kadence Blocks builds each block's CSS from its attributes while the
 * block renders (Kadence_Blocks_Abstract_Block::render_css() and the wp_head
 * pass over the post's blocks), keyed by uniqueID, and caches nothing per
 * post, so there is nothing to rebuild after a write.
 *
 * GenerateBlocks writes a per-post stylesheet to
 * uploads/generateblocks/style-{post_id}.css and records the posts whose
 * file is current in the generateblocks_dynamic_css_posts option; its own
 * save_post handler drops the post from that list so the file is rebuilt on
 * the next view. A snapshot restore bypasses that handler for the columns
 * and meta it writes back, so the same entry is dropped here after writes
 * and rollbacks.
 *
 * Spectra builds each post's CSS and JS from block attributes once and
 * keeps it in the _uag_page_assets, _uag_css_file_name and _uag_js_file_name
 * post meta (its UAGB_Helper::delete_page_assets() drops them on save_post).
 * Most Spectra blocks are registered only in the editor, so their schema
 * comes from the attribute defaults in each block's attributes.php
 * (default_attributes()), typed from the default values, and attributes the
 * server does not know are allowed.
 *
 * Otter Blocks writes uploads/themeisle-gutenberg/{name}.css only when its
 * editor calls its REST route after a save, and serves it from the
 * _themeisle_gutenberg_block_stylesheet and _themeisle_gutenberg_block_styles
 * meta. With both gone it rebuilds them on the next view, so writes and
 * rollbacks drop them and the stale file.
 *
 * The Blocksy companion blocks build their CSS while they render, keyed by
 * uniqueId, like Kadence.
 */
final class Block_Suite
{
    public const KADENCE        = 'kadence-blocks';
    public const GENERATEBLOCKS = 'generateblocks';
    public const SPECTRA        = 'spectra';
    public const OTTER          = 'otter-blocks';
    public const BLOCKSY        = 'blocksy';

    /** Stands in for the unique id inside markup an agent writes before the id exists. */
    public const PLACEHOLDER = '__UNIQUE_ID__';

    /** GenerateBlocks' list of posts whose generated stylesheet is current. */
    private const GB_CSS_POSTS = 'generateblocks_dynamic_css_posts';

    /** The post meta Spectra and Otter derive from a post's blocks. */
    private const SPECTRA_META = [ '_uag_page_assets', '_uag_css_file_name', '_uag_js_file_name' ];
    private const OTTER_FILE   = '_themeisle_gutenberg_block_stylesheet';
    private const OTTER_STYLES = '_themeisle_gutenberg_block_styles';

    /** @var array<string,array<string,mixed>> Spectra attributes.php path => defaults, read once per request. */
    private static array $spectra_files = [];

    /**
     * Per suite: id_style is how the editor shapes a new id (see
     * generate_id()), schema is where attribute definitions come from
     * (registry: block.json, strict; defaults: attributes.php, lenient), and
     * version is the constant holding the version, when there is one.
     *
     * @return array<string,array<string,string>> suite slug => spec
     */
    public static function specs(): array
    {
        return [
            self::KADENCE        => [
                'label'     => 'Kadence Blocks',
                'namespace' => 'kadence/',
                'id_attr'   => 'uniqueID',
                'id_style'  => 'post_scoped',
                'css_model' => 'render_time',
                'constant'  => 'KADENCE_BLOCKS_VERSION',
                'version'   => 'KADENCE_BLOCKS_VERSION',
                'schema'    => 'registry',
            ],
            self::GENERATEBLOCKS => [
                'label'     => 'GenerateBlocks',
                'namespace' => 'generateblocks/',
                'id_attr'   => 'uniqueId',
                'id_style'  => 'hex',
                'css_model' => 'per_post_cache',
                'constant'  => 'GENERATEBLOCKS_VERSION',
                'version'   => 'GENERATEBLOCKS_VERSION',
                'schema'    => 'registry',
            ],
            self::SPECTRA        => [
                'label'     => 'Spectra',
                'namespace' => 'uagb/',
                'id_attr'   => 'block_id',
                'id_style'  => 'hex',
                'css_model' => 'per_post_cache',
                'constant'  => 'UAGB_VER',
                'version'   => 'UAGB_VER',
                'schema'    => 'defaults',
            ],
            self::OTTER          => [
                'label'     => 'Otter Blocks',
                'namespace' => 'themeisle-blocks/',
                'id_attr'   => 'id',
                'id_style'  => 'block_prefixed',
                'css_model' => 'per_post_cache',
                'constant'  => 'OTTER_BLOCKS_VERSION',
                'version'   => 'OTTER_BLOCKS_VERSION',
                'schema'    => 'registry',
            ],
            self::BLOCKSY        => [
                'label'     => 'Blocksy Companion',
                'namespace' => 'blocksy/',
                'id_attr'   => 'uniqueId',
                'id_style'  => 'hex',
                'css_model' => 'render_time',
                'constant'  => 'BLOCKSY__FILE__',
                'version'   => '',
                'schema'    => 'registry',
            ],
        ];
    }

    /** @return array<string,string>|null */
    public static function spec(string $suite): ?array
    {
        return self::specs()[ $suite ] ?? null;
    }

    /**
     * Whether the suite's plugin is loaded. Filterable per suite through
     * wpmcp_block_suite_active (bool, suite slug).
     */
    public static function is_active(string $suite): bool
    {
        $spec = self::spec($suite);
        if (null === $spec) {
            return false;
        }
        return (bool) apply_filters('wpmcp_block_suite_active', defined($spec['constant']), $suite);
    }

    public static function version(string $suite): ?string
    {
        $spec = self::spec($suite);
        return (null !== $spec && '' !== $spec['version'] && defined($spec['version'])) ? (string) constant($spec['version']) : null;
    }

    /** The suite a block name belongs to, or null. */
    public static function suite_of(string $block_name): ?string
    {
        foreach (self::specs() as $suite => $spec) {
            if (0 === strpos($block_name, $spec['namespace'])) {
                return $suite;
            }
        }
        return null;
    }

    /**
     * A fresh id in the shape the suite's editor generates, not in $taken.
     * Every editor derives it from the block's client id (a UUID): Kadence
     * takes characters 2 to 10 and prefixes the post id, GenerateBlocks takes
     * the same characters without the hyphen, Spectra and Blocksy take the
     * first eight, and Otter prefixes those with wp-block-{namespace}-{name}-.
     *
     * @param array<string,bool> $taken
     */
    public static function generate_id(string $suite, int $post_id, array $taken, string $block_name = ''): string
    {
        $style = (string) (self::spec($suite)['id_style'] ?? 'hex');
        do {
            $uuid = wp_generate_uuid4();
            switch ($style) {
                case 'post_scoped':
                    $id = $post_id . '_' . substr($uuid, 2, 9);
                    break;
                case 'block_prefixed':
                    $id = 'wp-block-' . str_replace('/', '-', $block_name) . '-' . substr($uuid, 0, 8);
                    break;
                default:
                    $id = self::GENERATEBLOCKS === $suite ? str_replace('-', '', substr($uuid, 2, 9)) : substr($uuid, 0, 8);
            }
        } while (isset($taken[ $id ]));
        return $id;
    }

    /**
     * Whether an existing id can stay: well formed, not already used in the
     * post, and for Kadence scoped to this post (an id copied from another
     * post keeps that post's prefix, which Kadence's editor re-scopes).
     *
     * @param mixed              $id
     * @param array<string,bool> $taken
     */
    public static function keeps_id(string $suite, $id, int $post_id, array $taken): bool
    {
        if (! is_string($id) || '' === $id || self::PLACEHOLDER === $id || isset($taken[ $id ])) {
            return false;
        }
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
            return false;
        }
        if (self::KADENCE === $suite) {
            $parts = explode('_', $id);
            if (2 === count($parts) && ctype_digit($parts[0]) && (int) $parts[0] !== $post_id) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rebuild the suite's cached CSS for a post after a write or restore.
     *
     * @return array{model:string,refreshed:bool}
     */
    public static function refresh_css(string $suite, int $post_id): array
    {
        $spec = self::spec($suite);
        if (null === $spec) {
            return [ 'model' => 'unknown', 'refreshed' => false ];
        }
        $refreshed = false;
        if ('per_post_cache' === $spec['css_model'] && self::is_active($suite)) {
            if (self::GENERATEBLOCKS === $suite) {
                $posts = get_option(self::GB_CSS_POSTS, []);
                if (is_array($posts) && isset($posts[ $post_id ])) {
                    unset($posts[ $post_id ]);
                    update_option(self::GB_CSS_POSTS, $posts);
                }
            } elseif (self::SPECTRA === $suite) {
                foreach (self::SPECTRA_META as $key) {
                    delete_post_meta($post_id, $key);
                }
                // Spectra's own routine also bumps its asset version and purges host caches.
                if (is_callable([ '\UAGB_Helper', 'delete_page_assets' ])) {
                    \UAGB_Helper::delete_page_assets($post_id);
                }
            } elseif (self::OTTER === $suite) {
                self::drop_otter_stylesheet($post_id);
            }
            $refreshed = true;
        }
        /** Action: a site refreshes its own caches after a block suite write or restore. */
        do_action('wpmcp_block_suite_css_refresh', $suite, $post_id);
        return [ 'model' => $spec['css_model'], 'refreshed' => $refreshed ];
    }

    /**
     * Remove Otter's per-post stylesheet and the meta that serves it. Besides
     * the file the meta names, every post-v2-{id}-{time}.css of the post goes:
     * a restore puts back the meta of the snapshot, which names an older file
     * than the one Otter wrote since.
     */
    private static function drop_otter_stylesheet(int $post_id): void
    {
        $dir   = wp_upload_dir(null, false)['basedir'] . '/themeisle-gutenberg/';
        $files = glob($dir . 'post-v2-' . $post_id . '-*.css');
        $files = is_array($files) ? $files : [];
        $name  = get_post_meta($post_id, self::OTTER_FILE, true);
        if (is_string($name) && 1 === preg_match('/^[A-Za-z0-9_-]{1,100}$/', $name)) {
            $files[] = $dir . $name . '.css';
        }
        foreach (array_unique($files) as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        delete_post_meta($post_id, self::OTTER_FILE);
        delete_post_meta($post_id, self::OTTER_STYLES);
    }

    /**
     * Attribute defaults for a suite whose blocks the server does not
     * register (schema "defaults"): block name => title and attribute
     * defaults. For Spectra they come from each block's attributes.php.
     * Filterable through wpmcp_block_suite_default_attributes (array,
     * suite slug).
     *
     * @return array<string,array{title:string,attributes:array<string,mixed>}>
     */
    public static function default_attributes(string $suite): array
    {
        $defaults = [];
        if (self::SPECTRA === $suite && self::is_active($suite) && class_exists('\UAGB_Block') && defined('UAGB_DIR')) {
            foreach ((array) \UAGB_Block::get_instance()->get_blocks() as $name => $data) {
                if (! is_array($data) || ! empty($data['extension']) || 0 !== strpos((string) $name, 'uagb/')) {
                    continue;
                }
                $dir  = basename((string) ($data['dynamic_assets']['dir'] ?? substr((string) $name, 5)));
                $base = isset($data['plugin-dir']) ? (string) $data['plugin-dir'] : (string) constant('UAGB_DIR');
                $file = $base . 'includes/blocks/' . $dir . '/attributes.php';
                if (! isset(self::$spectra_files[ $file ])) {
                    try {
                        $attrs = is_readable($file) ? include $file : [];
                    } catch (\Throwable $e) {
                        $attrs = [];
                    }
                    self::$spectra_files[ $file ] = is_array($attrs) ? $attrs : [];
                }
                $defaults[ (string) $name ] = [ 'title' => (string) ($data['title'] ?? ''), 'attributes' => self::$spectra_files[ $file ] ];
            }
        }
        $defaults = apply_filters('wpmcp_block_suite_default_attributes', $defaults, $suite);
        return is_array($defaults) ? $defaults : [];
    }

    /**
     * The suite's block types: the registered ones in its namespace, and for
     * a defaults-schema suite, the blocks from default_attributes() typed
     * from their default values (a registered definition wins) plus the
     * unique id attribute.
     *
     * @return array<string,\WP_Block_Type> sorted by name
     */
    public static function block_types(string $suite): array
    {
        $spec = self::spec($suite);
        if (null === $spec) {
            return [];
        }
        $types = [];
        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
            if (0 === strpos((string) $name, $spec['namespace'])) {
                $types[ (string) $name ] = $type;
            }
        }
        if ('defaults' === $spec['schema']) {
            foreach (self::default_attributes($suite) as $name => $data) {
                $name = (string) $name;
                if (0 !== strpos($name, $spec['namespace'])) {
                    continue;
                }
                $attributes = [];
                foreach ((array) ($data['attributes'] ?? []) as $key => $default) {
                    $type                         = self::infer_type($default);
                    $attributes[ (string) $key ] = null === $type ? [ 'default' => $default ] : [ 'type' => $type, 'default' => $default ];
                }
                $attributes[ $spec['id_attr'] ] = [ 'type' => 'string' ];
                $registered                     = $types[ $name ] ?? null;
                $types[ $name ]                 = new \WP_Block_Type($name, [
                    'title'           => '' !== (string) ($registered->title ?? '') ? (string) $registered->title : (string) ($data['title'] ?? ''),
                    'attributes'      => array_merge($attributes, is_array($registered->attributes ?? null) ? $registered->attributes : []),
                    'render_callback' => $registered->render_callback ?? null,
                    'supports'        => is_array($registered->supports ?? null) ? $registered->supports : [],
                ]);
            }
            foreach ($types as $name => $type) {
                if (! isset($type->attributes[ $spec['id_attr'] ])) {
                    $type                                 = clone $type; // Never widen the registered type itself.
                    $type->attributes                     = (is_array($type->attributes) ? $type->attributes : []) + [ $spec['id_attr'] => [ 'type' => 'string' ] ];
                    $types[ $name ]                       = $type;
                }
            }
        }
        ksort($types, SORT_STRING);
        return $types;
    }

    /** Whether attributes a suite's schema does not list are refused. */
    public static function strict_attributes(string $suite): bool
    {
        return 'registry' === (self::spec($suite)['schema'] ?? 'registry');
    }

    /**
     * The JSON type a default value implies, or null when it says nothing
     * (an empty string, null or an empty array: Spectra uses those as
     * "unset" for numbers and objects alike).
     *
     * @param mixed $value
     */
    private static function infer_type($value): ?string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return '' === $value ? null : 'string';
        }
        if (is_array($value) && [] !== $value) {
            return array_is_list($value) ? 'array' : 'object';
        }
        return null;
    }

    /**
     * After a rollback restored a post (wpmcp_rollback_post_restored), let
     * every ACTIVE suite rebuild what it cached for the content that was
     * undone. An inactive suite is left alone.
     */
    public static function refresh_after_restore(int $post_id): void
    {
        foreach (array_keys(self::specs()) as $suite) {
            if (self::is_active($suite)) {
                self::refresh_css($suite, $post_id);
            }
        }
    }
}
