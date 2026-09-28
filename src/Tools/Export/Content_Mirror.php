<?php

namespace WPMCP\Tools\Export;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Builders\{Avada_Content, Beaver_Builder_Content, Beaver_Builder_Nodes, Breakdance_Content, Breakdance_Tree, Bricks_Content, Builder_Detector, Divi_Content, Oxygen_Classic_Content, Oxygen_Classic_Json, Thrive_Content, Thrive_Html, WPBakery_Content};
use WPMCP\Tools\Content\Content_Guard;
use WPMCP\Tools\Elementor\Elementor_Page_Data;
use WPMCP\Tools\Filesystem\Filesystem_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A git-friendly file mirror of builder page data (issue #298).
 *
 * export() writes one pretty-printed JSON file per page to
 * uploads/wpmcp-mirror/post-<id>.json: a small header (format, post id, post
 * type, builder, modified) and the page's builder data in the shape that
 * builder's own write path takes. Output is stable: the same stored content
 * always gives byte-identical files, and a file is only rewritten when its
 * bytes change, so a checkout of the directory diffs cleanly. Content held in
 * post_content (Gutenberg blocks, Divi, WPBakery and Avada shortcodes) is
 * written as an array of lines, as is a Thrive Architect layout (HTML in
 * postmeta), so a one-line edit is a one-line diff. A classic Oxygen page is
 * written as its JSON tree (or, for a page still in the pre-4.0 format, its
 * shortcodes as lines, which a restore refuses: only Oxygen can sign them).
 *
 * restore() reads a page's file back and writes it through that builder's
 * storage adapter inside Safe_Mutation::run(), so the restore is snapshotted
 * like any other write and rollback-operation undoes it exactly.
 *
 * The caller only ever names a post id. File names are derived from it here,
 * every path is confined by Filesystem_Guard::resolve_path() (the directory
 * to uploads, each file to the directory, which also refuses a symlink that
 * leaves it), and all file I/O goes through WP_Filesystem. The directory is
 * blocked from direct web access the way File_Backup blocks its own, and only
 * ever holds .json files besides its guard files.
 *
 * An adapter whose class a build does not ship is reported as unavailable
 * rather than called, so a trimmed build skips those pages instead of
 * failing on them.
 */
class Content_Mirror
{
    public const DIR_NAME = 'wpmcp-mirror';
    public const FORMAT   = 1;
    public const PER_PAGE = 50;

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Builders whose data is post_content, mirrored as an array of lines. */
    private const LINE_BUILDERS = ['gutenberg', 'divi', 'wpbakery', 'avada'];

    private const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    public static function dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . self::DIR_NAME;
    }

    public static function file_for(int $post_id): string
    {
        return self::dir() . '/post-' . $post_id . '.json';
    }

    /**
     * Mirror one page (post_id) or one page of every builder page (page,
     * PER_PAGE posts at a time in id order).
     */
    public function export(array $args): array
    {
        self::require_admin();
        $dir = self::prepare_dir();

        if (array_key_exists('post_id', $args)) {
            $post_id = self::post_id($args['post_id']);
            $post    = self::post($post_id);
            $builder = Builder_Detector::detect($post_id);
            if (! self::available($builder)) {
                throw new \InvalidArgumentException(esc_html("Post {$post_id} is detected as '{$builder}', which has no builder data this site can mirror."));
            }

            return [
                'dir'   => self::dir(),
                'files' => [self::write($dir, $post, $builder)],
                'count' => 1,
            ];
        }

        $page  = max(1, (int) ($args['page'] ?? 1));
        $query = new \WP_Query([
            'post_type'           => self::post_types(),
            'post_status'         => self::STATUSES,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'posts_per_page'      => self::PER_PAGE,
            'paged'               => $page,
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
        ]);

        $files   = [];
        $skipped = 0;
        foreach ($query->posts as $post_id) {
            $post    = get_post((int) $post_id);
            $builder = Builder_Detector::detect((int) $post_id);
            if (! $post || ! self::available($builder)) {
                $skipped++;
                continue;
            }
            $files[] = self::write($dir, $post, $builder);
        }

        return [
            'dir'     => self::dir(),
            'files'   => $files,
            'count'   => count($files),
            'skipped' => $skipped,
            'page'    => $page,
            'pages'   => (int) $query->max_num_pages,
        ];
    }

    /**
     * Restore one page from its mirror file as a snapshotted write.
     */
    public function restore(array $args): array
    {
        self::require_admin();
        $post_id = self::post_id($args['post_id'] ?? null);
        self::post($post_id);

        $dir  = self::prepare_dir();
        $path = self::confine($dir, $post_id);
        $fs   = self::filesystem();
        if (! $fs->exists($path)) {
            throw new \RuntimeException(esc_html("Post {$post_id} has no mirror file; export it with export-content mirror:true first."));
        }
        $raw = (string) $fs->get_contents($path);

        $doc   = json_decode($raw);
        $arr   = json_decode($raw, true);
        $valid = is_object($doc) && self::FORMAT === ($doc->mirror_format ?? null) && $post_id === ($doc->post_id ?? null)
            && is_string($doc->builder ?? null) && property_exists($doc, 'data');
        if (! $valid) {
            throw new \RuntimeException(esc_html("The mirror file for post {$post_id} is not a mirror of that post."));
        }

        $builder = $doc->builder;
        if (! self::available($builder)) {
            throw new \RuntimeException(esc_html("The mirror file holds '{$builder}' data, which this site cannot write."));
        }
        $current = Builder_Detector::detect($post_id);
        if ($current !== $builder && 'classic' !== $current) {
            throw new \RuntimeException(esc_html("Post {$post_id} is now a '{$current}' page and its mirror file holds '{$builder}' data; refusing to switch builders."));
        }

        $write = self::writer($post_id, $builder, $doc->data, $arr['data']);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'import-content',
                'args'        => ['mirror' => true, 'post_id' => $post_id],
            ],
            $write
        );

        return [
            'operation_id' => $out['operation_id'],
            'post_id'      => $post_id,
            'builder'      => $builder,
            'file'         => self::file_for($post_id),
        ];
    }

    /**
     * Validate a file's data for its builder and return the write that stores
     * it. Validation happens here, before the snapshot, so a malformed file
     * never leaves an operation behind.
     *
     * @param mixed $data   the data decoded with objects kept as objects
     * @param mixed $assoc  the same data decoded to arrays
     */
    private static function writer(int $post_id, string $builder, $data, $assoc): callable
    {
        if (in_array($builder, self::LINE_BUILDERS, true)) {
            if (! is_array($assoc) || ! array_is_list($assoc) || [] !== array_filter($assoc, static fn ($line) => ! is_string($line))) {
                throw new \RuntimeException(esc_html("The mirror file for post {$post_id} must hold its content as an array of lines."));
            }
            $content = implode("\n", $assoc);

            if ('divi' === $builder) {
                return static function () use ($post_id, $content) {
                    Divi_Content::save($post_id, $content);
                    return true;
                };
            }
            if ('wpbakery' === $builder) {
                return static function () use ($post_id, $content) {
                    WPBakery_Content::save($post_id, $content);
                    return true;
                };
            }
            if ('avada' === $builder) {
                return static function () use ($post_id, $content) {
                    Avada_Content::save($post_id, $content);
                    return true;
                };
            }

            return static function () use ($post_id, $content) {
                wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($content)]);
                return true;
            };
        }

        if ('elementor' === $builder || 'bricks' === $builder) {
            if (! is_array($data) || ! is_array($assoc)) {
                throw new \RuntimeException(esc_html("The {$builder} data in the mirror file for post {$post_id} must be an array."));
            }
            if ('bricks' === $builder) {
                return static function () use ($post_id, $assoc) {
                    Bricks_Content::save($post_id, $assoc);
                    return true;
                };
            }

            return static function () use ($post_id, $data) {
                Elementor_Page_Data::save($post_id, $data);
                return true;
            };
        }

        if ('thrive' === $builder) {
            if (! is_array($assoc) || ! array_is_list($assoc) || [] !== array_filter($assoc, static fn ($line) => ! is_string($line))) {
                throw new \RuntimeException(esc_html("The mirror file for post {$post_id} must hold its layout as an array of lines."));
            }
            $content = implode("\n", $assoc);
            try {
                Thrive_Html::assert_balanced($content);
                Thrive_Html::tree($content);
                $before_more = Thrive_Content::before_more($post_id, Thrive_Content::get_content($post_id), $content);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException(esc_html($e->getMessage()));
            }

            return static function () use ($post_id, $content, $before_more) {
                Thrive_Content::save($post_id, $content, $before_more);
                return true;
            };
        }

        if ('oxygen-classic' === $builder) {
            if (! is_object($data)) {
                throw new \RuntimeException(esc_html("The mirror file for post {$post_id} holds classic Oxygen shortcodes from before Oxygen 4, which only Oxygen can sign; it cannot be restored here."));
            }
            $json = (string) wp_json_encode($data, JSON_UNESCAPED_UNICODE);
            try {
                Oxygen_Classic_Json::validate($json);
                $json = (Oxygen_Classic_Content::signer())($json);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException(esc_html($e->getMessage()));
            }
            if ('' !== trim(Oxygen_Classic_Content::get_shortcodes($post_id)) && ! Oxygen_Classic_Content::can_regenerate()) {
                throw new \RuntimeException('Oxygen keeps a signed shortcode copy of this page beside its JSON, and only Oxygen can sign it; activate Oxygen to restore this page.');
            }
            $shortcodes = Oxygen_Classic_Content::shortcodes_for($json);

            return static function () use ($post_id, $json, $shortcodes) {
                Oxygen_Classic_Content::save($post_id, $json, $shortcodes);
                return true;
            };
        }

        if ('beaver-builder' === $builder) {
            if (Beaver_Builder_Content::draft_pending($post_id)) {
                throw new \RuntimeException('This page has unpublished Beaver Builder edits; publish or discard them in the editor first.');
            }
            try {
                $nodes = Beaver_Builder_Nodes::from_tree($data);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException(esc_html($e->getMessage()));
            }

            return static function () use ($post_id, $nodes) {
                Beaver_Builder_Content::save($post_id, $nodes);
                return true;
            };
        }

        // Breakdance or Oxygen 6 (the same engine), the last builders
        // available() admits.
        $tree = is_object($data) ? Breakdance_Tree::decode((string) wp_json_encode($data)) : null;
        if (null === $tree) {
            throw new \RuntimeException(esc_html("The {$builder} data in the mirror file for post {$post_id} is not a document with a root node."));
        }

        return static function () use ($post_id, $tree, $builder) {
            Breakdance_Content::save($post_id, $tree, $builder);
            return true;
        };
    }

    /**
     * The page's builder data in the shape its write path takes.
     *
     * @return mixed
     */
    private static function data(\WP_Post $post, string $builder)
    {
        $post_id = (int) $post->ID;

        if (in_array($builder, self::LINE_BUILDERS, true)) {
            return explode("\n", (string) $post->post_content);
        }

        switch ($builder) {
            case 'elementor':
                // Decoded with objects kept, so an empty settings object stays
                // {} in the file and through the restore.
                $raw     = get_post_meta($post_id, '_elementor_data', true);
                $decoded = is_string($raw) ? json_decode($raw) : null;
                return is_array($decoded) ? $decoded : [];

            case 'bricks':
                return Bricks_Content::get($post_id) ?? [];

            case 'beaver-builder':
                return Beaver_Builder_Nodes::tree(Beaver_Builder_Content::get_nodes($post_id));

            case 'thrive':
                return explode("\n", Thrive_Content::get_content($post_id));

            case 'oxygen-classic':
                if ('json' === Oxygen_Classic_Content::format($post_id)) {
                    return json_decode(Oxygen_Classic_Content::get_json($post_id));
                }
                return explode("\n", Oxygen_Classic_Content::get_shortcodes($post_id));
        }

        $raw   = get_post_meta($post_id, Breakdance_Content::data_key($builder), true);
        $outer = is_string($raw) ? json_decode($raw, true) : null;
        $tree  = is_array($outer) && is_string($outer['tree_json_string'] ?? null) ? json_decode($outer['tree_json_string']) : null;

        return is_object($tree) ? $tree : Breakdance_Tree::blank();
    }

    /** Write one page's file when its bytes changed. */
    private static function write(string $dir, \WP_Post $post, string $builder): array
    {
        $post_id = (int) $post->ID;
        $bytes   = wp_json_encode(
            [
                'mirror_format' => self::FORMAT,
                'post_id'       => $post_id,
                'post_type'     => (string) $post->post_type,
                'builder'       => $builder,
                'modified'      => (string) $post->post_modified_gmt,
                'data'          => self::data($post, $builder),
            ],
            self::JSON_FLAGS
        );
        if (false === $bytes) {
            throw new \RuntimeException(esc_html("Post {$post_id}'s builder data could not be encoded as JSON."));
        }
        $bytes .= "\n";

        $path    = self::confine($dir, $post_id);
        $fs      = self::filesystem();
        $changed = ! $fs->exists($path) || $fs->get_contents($path) !== $bytes;
        if ($changed && ! $fs->put_contents($path, $bytes, FS_CHMOD_FILE)) {
            throw new \RuntimeException(esc_html("Could not write the mirror file for post {$post_id}."));
        }

        return [
            'post_id' => $post_id,
            'builder' => $builder,
            'file'    => self::file_for($post_id),
            'changed' => $changed,
        ];
    }

    /** Whether this build can read and write a builder's data. */
    private static function available(string $builder): bool
    {
        $adapters = [
            'gutenberg'      => null,
            'elementor'      => Elementor_Page_Data::class,
            'bricks'         => Bricks_Content::class,
            'divi'           => Divi_Content::class,
            'wpbakery'       => WPBakery_Content::class,
            'avada'          => Avada_Content::class,
            'beaver-builder' => Beaver_Builder_Content::class,
            'breakdance'     => Breakdance_Content::class,
            'oxygen'         => Breakdance_Content::class,
            'thrive'         => Thrive_Content::class,
            'oxygen-classic' => Oxygen_Classic_Content::class,
        ];
        if (! array_key_exists($builder, $adapters)) {
            return false;
        }

        return null === $adapters[ $builder ] || class_exists($adapters[ $builder ]);
    }

    /**
     * A post id as the caller sent it: a positive integer, or a string of
     * digits. Anything else, including path-like strings that an int cast
     * would quietly turn into an id, is refused.
     *
     * @param mixed $value
     */
    private static function post_id($value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^[1-9][0-9]{0,18}$/', $value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('post_id must be a positive integer.');
    }

    private static function post(int $post_id): \WP_Post
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(esc_html("No post found with id {$post_id}."));
        }
        if (! Content_Guard::is_writable_post_type($post->post_type) || ! Content_Guard::is_agent_readable_post_type($post->post_type)) {
            throw new \InvalidArgumentException(esc_html("Posts of type '{$post->post_type}' are not mirrored."));
        }

        return $post;
    }

    /** @return string[] */
    private static function post_types(): array
    {
        return array_values(array_filter(
            get_post_types(),
            static fn ($type) => Content_Guard::is_writable_post_type($type) && Content_Guard::is_agent_readable_post_type($type)
        ));
    }

    private static function require_admin(): void
    {
        if (! current_user_can('manage_options')) {
            throw new \RuntimeException('The content mirror requires the manage_options capability.');
        }
    }

    /**
     * Create the mirror directory under uploads, confirm through the guard
     * that it resolves inside uploads, and block direct web access to it.
     * Returns the resolved directory.
     */
    private static function prepare_dir(): string
    {
        $uploads = wp_upload_dir();
        if (! empty($uploads['error'])) {
            throw new \RuntimeException('The uploads directory is unavailable.');
        }
        $dir = self::dir();
        if (! wp_mkdir_p($dir)) {
            throw new \RuntimeException('Could not create the mirror directory.');
        }
        $real = Filesystem_Guard::resolve_path($dir, $uploads['basedir']);
        if (is_wp_error($real)) {
            throw new \RuntimeException('The mirror directory resolves outside the uploads directory.');
        }

        $fs = self::filesystem();
        if (! $fs->exists($real . '/.htaccess')) {
            $fs->put_contents($real . '/.htaccess', "Require all denied\n", FS_CHMOD_FILE);
        }
        if (! $fs->exists($real . '/index.php')) {
            $fs->put_contents($real . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE);
        }

        return $real;
    }

    /** The resolved path of a page's file, which must stay inside $dir. */
    private static function confine(string $dir, int $post_id): string
    {
        $path = Filesystem_Guard::resolve_path($dir . '/post-' . $post_id . '.json', $dir);
        if (is_wp_error($path)) {
            throw new \RuntimeException(esc_html("The mirror file for post {$post_id} resolves outside the mirror directory."));
        }

        return $path;
    }

    /**
     * The WP_Filesystem instance, initialised on first use. Relaxed ownership
     * lets the direct method serve a directory PHP can write even when its
     * owner differs, since prompting for FTP credentials from a tool call is
     * not an option.
     */
    private static function filesystem(): \WP_Filesystem_Base
    {
        global $wp_filesystem;
        if (! $wp_filesystem instanceof \WP_Filesystem_Base) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem(false, false, true);
        }
        if (! $wp_filesystem instanceof \WP_Filesystem_Base) {
            throw new \RuntimeException('The WordPress filesystem is unavailable.');
        }

        return $wp_filesystem;
    }
}
