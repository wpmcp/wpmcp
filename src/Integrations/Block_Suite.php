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
 */
final class Block_Suite
{
    public const KADENCE        = 'kadence-blocks';
    public const GENERATEBLOCKS = 'generateblocks';

    /** Stands in for the unique id inside markup an agent writes before the id exists. */
    public const PLACEHOLDER = '__UNIQUE_ID__';

    /** GenerateBlocks' list of posts whose generated stylesheet is current. */
    private const GB_CSS_POSTS = 'generateblocks_dynamic_css_posts';

    /** @return array<string,array<string,string>> suite slug => spec */
    public static function specs(): array
    {
        return [
            self::KADENCE        => [
                'label'     => 'Kadence Blocks',
                'namespace' => 'kadence/',
                'id_attr'   => 'uniqueID',
                'css_model' => 'render_time',
                'constant'  => 'KADENCE_BLOCKS_VERSION',
            ],
            self::GENERATEBLOCKS => [
                'label'     => 'GenerateBlocks',
                'namespace' => 'generateblocks/',
                'id_attr'   => 'uniqueId',
                'css_model' => 'per_post_cache',
                'constant'  => 'GENERATEBLOCKS_VERSION',
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
        return (null !== $spec && defined($spec['constant'])) ? (string) constant($spec['constant']) : null;
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
     * Both editors take characters 2 to 10 of the block's client id (a UUID):
     * GenerateBlocks drops the hyphen, Kadence prefixes the post id.
     *
     * @param array<string,bool> $taken
     */
    public static function generate_id(string $suite, int $post_id, array $taken): string
    {
        do {
            $chunk = substr(wp_generate_uuid4(), 2, 9);
            $id    = self::KADENCE === $suite
                ? $post_id . '_' . $chunk
                : str_replace('-', '', $chunk);
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
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
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
            $posts = get_option(self::GB_CSS_POSTS, []);
            if (is_array($posts) && isset($posts[ $post_id ])) {
                unset($posts[ $post_id ]);
                update_option(self::GB_CSS_POSTS, $posts);
            }
            $refreshed = true;
        }
        /** Action: a site refreshes its own caches after a block suite write or restore. */
        do_action('wpmcp_block_suite_css_refresh', $suite, $post_id);
        return [ 'model' => $spec['css_model'], 'refreshed' => $refreshed ];
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
