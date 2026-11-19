<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a Brizy page (issue #645). Recorded from Brizy 2.8.26
 * (Brizy_Editor_Post, Brizy_Editor_Storage_Post, Brizy_Editor_Compiler):
 *
 * - `brizy_enabled` postmeta (1) marks a page Brizy renders.
 * - The `brizy` postmeta row is an array whose `brizy-post` entry holds the
 *   editor data, base64 of the page JSON (see Brizy_Tree), beside the
 *   editor, compiler and plugin versions, the compiler kind and the last
 *   editing user.
 * - What the front end shows is compiled HTML in `brizy-compiled-sections`.
 *   Brizy recompiles a page on its next view when its
 *   `brizy-post-compiler-version` row is older than its minimum compiler
 *   version, which is how Brizy_Editor_Compiler::resetCompiledVersion()
 *   asks for it (version '0.0.0').
 * - `brizy_data_version` is the editor's optimistic lock: a save from an
 *   editor tab must carry the stored version plus one.
 *
 * A write stores the new editor data the way Brizy's saveStorage() does,
 * marks the page for recompiling ('0.0.0' and `brizy-need-compile`) and
 * bumps the data version, so an editor tab opened before the write gets
 * Brizy's own conflict instead of overwriting it. Every row is postmeta,
 * which the post snapshot captures and restores (the compiled sections and
 * the compiler version come back with the layout, so a rollback leaves the
 * page as it rendered before).
 */
final class Brizy_Content
{
    public const META_KEY = 'brizy';

    public const POST_KEY = 'brizy-post';

    public const ENABLED_KEY = 'brizy_enabled';

    public const COMPILER_VERSION_KEY = 'brizy-post-compiler-version';

    public const NEEDS_COMPILE_KEY = 'brizy-need-compile';

    public const DATA_VERSION_KEY = 'brizy_data_version';

    public const SAVED_BLOCK_TYPE = 'brizy-saved-block';

    public const LAYOUT_TYPE = 'brizy-layout';

    public const GLOBAL_BLOCK_TYPE = 'brizy-global-block';

    /** The post types a page can add blocks from (element {template}). */
    public const TEMPLATE_TYPES = [self::SAVED_BLOCK_TYPE, self::LAYOUT_TYPE, self::GLOBAL_BLOCK_TYPE];

    /** The compiler version Brizy's own reset writes to force a recompile. */
    public const STALE_COMPILER_VERSION = '0.0.0';

    /** Whether Brizy is loaded (filterable for tests). */
    public static function plugin_active(): bool
    {
        $loaded = defined('BRIZY_VERSION') && class_exists('Brizy_Editor_Post');

        return (bool) apply_filters('wpmcp_brizy_active', $loaded);
    }

    /** The loaded Brizy version, or null. */
    public static function plugin_version(): ?string
    {
        if (! self::plugin_active() || ! defined('BRIZY_VERSION')) {
            return null;
        }
        $version = (string) constant('BRIZY_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs Brizy active. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "Brizy is not active on this site, so Brizy pages cannot be changed. Install and activate Brizy ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Brizy', 'url' => $url]
        );
    }

    /** Whether Brizy renders this page: its own isBrizyEnabled() test. */
    public static function is_brizy_page(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, self::ENABLED_KEY, true);
    }

    /**
     * The `brizy-post` entry of the storage row, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function entry(int $post_id): ?array
    {
        $row = get_post_meta($post_id, self::META_KEY, true);
        $entry = is_array($row) ? ($row[ self::POST_KEY ] ?? null) : null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * The decoded page data, null when the page has none or it cannot be
     * decoded.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(int $post_id): ?array
    {
        $entry = self::entry($post_id);
        $raw   = is_string($entry['editor_data'] ?? null) ? $entry['editor_data'] : '';
        if ('' === $raw) {
            return null;
        }
        $json = base64_decode($raw, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Brizy's own storage format.
        $data = json_decode(false === $json ? $raw : $json, true);

        return is_array($data) ? $data : null;
    }

    /** sha256 of the stored row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $row = get_post_meta($post_id, self::META_KEY, true);

        return hash('sha256', is_array($row) ? serialize($row) : (string) $row); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint of the stored row, never unserialized.
    }

    /** Whether the page holds editor data (a write to it then needs the hash). */
    public static function has_layout(int $post_id): bool
    {
        return null !== self::entry($post_id);
    }

    /** Whether the page waits for Brizy to recompile it. */
    public static function compile_pending(int $post_id): bool
    {
        return self::STALE_COMPILER_VERSION === (string) get_post_meta($post_id, self::COMPILER_VERSION_KEY, true)
            || (bool) get_post_meta($post_id, self::NEEDS_COMPILE_KEY, true);
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(int $post_id)
    {
        if (! current_user_can('read_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this post.', ['status' => 403]);
        }

        $data = self::decode($post_id);
        $out  = [
            'post_id'         => $post_id,
            'builder'         => 'brizy',
            'plugin_active'   => self::plugin_active(),
            'plugin_version'  => self::plugin_version(),
            'enabled'         => (bool) get_post_meta($post_id, self::ENABLED_KEY, true),
            'hash'            => self::hash($post_id),
            'data_version'    => (int) get_post_meta($post_id, self::DATA_VERSION_KEY, true),
            'compile_pending' => self::compile_pending($post_id),
            'tree'            => Brizy_Tree::outline($data ?? []),
            'data'            => $data ?? ['items' => []],
        ];
        if (self::has_layout($post_id) && null === $data) {
            $out['tree_error'] = 'The stored editor data could not be decoded.';
        }

        return $out;
    }

    /**
     * Store page data as Brizy's saveStorage() does and ask Brizy to
     * recompile the page (see the class comment).
     *
     * @param array<string,mixed> $data
     */
    public static function save(int $post_id, array $data): void
    {
        $row   = get_post_meta($post_id, self::META_KEY, true);
        $row   = is_array($row) ? $row : [];
        $entry = is_array($row[ self::POST_KEY ] ?? null) ? $row[ self::POST_KEY ] : [
            'editor_version'     => defined('BRIZY_EDITOR_VERSION') ? (string) constant('BRIZY_EDITOR_VERSION') : null,
            'compiler_version'   => null,
            'plugin_version'     => defined('BRIZY_VERSION') ? (string) constant('BRIZY_VERSION') : null,
            'pro_plugin_version' => defined('BRIZY_PRO_VERSION') ? (string) constant('BRIZY_PRO_VERSION') : null,
            'compiler'           => 'server',
        ];

        $entry['editor_data']      = base64_encode((string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Brizy's own storage format.
        $entry['lastUserEdited']   = get_current_user_id();
        $entry['brizy-use-brizy']  = true;
        $row[ self::POST_KEY ]     = $entry;

        update_post_meta($post_id, self::META_KEY, wp_slash($row));
        update_post_meta($post_id, self::ENABLED_KEY, 1);
        update_post_meta($post_id, self::COMPILER_VERSION_KEY, self::STALE_COMPILER_VERSION);
        update_post_meta($post_id, self::NEEDS_COMPILE_KEY, 1);
        update_post_meta($post_id, self::DATA_VERSION_KEY, (int) get_post_meta($post_id, self::DATA_VERSION_KEY, true) + 1);
    }

    /**
     * The blocks a Brizy template adds to a page: a saved block or a layout
     * copied (new ids are given by Brizy_Tree::insert), or a global block as
     * a reference.
     *
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function template_nodes(string $template): array
    {
        $id   = ctype_digit($template) ? (int) $template : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || ! in_array($post->post_type, self::TEMPLATE_TYPES, true)) {
            throw new \InvalidArgumentException(esc_html("element {template} needs the id of a Brizy saved block, layout or global block (scope templates lists them); got '{$template}'."));
        }
        if (! current_user_can('read_post', $id)) {
            throw new \InvalidArgumentException(esc_html("You are not allowed to read template {$id}."));
        }

        if (self::GLOBAL_BLOCK_TYPE === $post->post_type) {
            $uid = (string) get_post_meta($id, 'brizy_post_uid', true);
            if ('' === $uid) {
                throw new \InvalidArgumentException(esc_html("Global block {$id} has no uid."));
            }
            $data     = self::decode($id);
            $block_id = is_array($data) && is_string($data['blockId'] ?? null) ? $data['blockId'] : Brizy_Tree::BLANK_BLOCK_ID;

            return [['blockId' => $block_id, 'type' => 'GlobalBlock', 'value' => ['_id' => $uid]]];
        }

        $data = self::decode($id);
        if (null === $data) {
            throw new \InvalidArgumentException(esc_html("Template {$id} holds no Brizy data."));
        }
        $nodes = isset($data['type']) ? [$data] : (is_array($data['items'] ?? null) ? $data['items'] : []);
        $nodes = array_values(array_filter($nodes, 'is_array'));
        if ([] === $nodes) {
            throw new \InvalidArgumentException(esc_html("Template {$id} holds no blocks."));
        }

        return $nodes;
    }
}
