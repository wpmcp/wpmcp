<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core site identity, homepage and permalink settings (issues #713
 * and #717) as free ops on the theme dispatcher pair, so the two features add
 * no top-level tools: the pair is always registered (the active theme is
 * always present), the Customizer owns Site Identity and Homepage Settings,
 * and Redirection already lives here, next to the permalinks whose change
 * it cleans up after.
 *
 * Reads: get-site-identity (name, tagline, logo, icon, homepage settings),
 * get-permalink-structure (structure, bases, presets with an example URL
 * each, the registered structure tags) and test-url (the rewrite rule and
 * query vars a path on this site resolves to, and the post it reaches).
 *
 * Writes, every one snapshotted so rollback-operation puts the setting back
 * exactly: set-site-logo (the custom_logo theme mod, on the theme_mods
 * option like set-mods), set-site-icon (the site_icon option),
 * set-front-page (show_on_front and page_on_front as ONE option_set undo
 * point, so a rollback never leaves "page" pointing at nothing),
 * set-posts-page (page_for_posts) and set-permalink-structure (the
 * structure with both bases as one option_set; destructive, so it needs
 * confirm:true, with a preview naming the before and after example URL).
 * flush-rewrite-rules rebuilds the rules and is honestly reported
 * recoverable:false: it destroys nothing. Name and tagline stay on
 * update-settings (blogname, blogdescription).
 *
 * The rewrite rules are a side effect, not a snapshot: set-permalink-structure
 * flushes them after the write (soft by default; hard:true also rewrites
 * .htaccess or web.config the way the Permalinks screen does, which no
 * snapshot covers). Both write the rules for the bases just stored: the
 * category and post_tag permastructs are re-pointed at them before the
 * flush (issue #785). A rollback restores the options and rebuilds the
 * rules for them the same way (soft) through after_options_restored().
 *
 * Writes are off until the site opts in through the
 * wpmcp_enable_core_site_write filter (or per op through
 * wpmcp_integration_op_enabled); a refused write names the filter and
 * changes nothing. Each op takes what its wp-admin screen takes:
 * edit_theme_options for the logo (the Customizer's own capability),
 * manage_options for the icon, the homepage settings and everything under
 * Settings > Permalinks.
 */
final class Core_Site_Pack
{
    /** The site-wide opt-in for every write op of this pack. */
    public const ENABLE_FILTER = 'wpmcp_enable_core_site_write';

    private const ENABLE_HINT = 'The site opts in with add_filter("wpmcp_enable_core_site_write", "__return_true"), or per operation with the wpmcp_integration_op_enabled filter.';

    /** What a permalink structure may contain: letters, digits, / - _ . and %tag%. */
    private const STRUCTURE_PATTERN = '/^[A-Za-z0-9\/\-_%.]*$/';

    /** What a category or tag base may contain: the same, without tags. */
    private const BASE_PATTERN = '/^[A-Za-z0-9\/\-_.]*$/';

    private const MAX_LENGTH = 200;

    /** The Customizer's own minimum for a site icon, in pixels on each edge. */
    private const ICON_MIN_EDGE = 512;

    /** The common structures the Permalinks screen offers. */
    private const PRESETS = [
        'plain'          => [ 'label' => 'Plain', 'structure' => '' ],
        'day-and-name'   => [ 'label' => 'Day and name', 'structure' => '/%year%/%monthnum%/%day%/%postname%/' ],
        'month-and-name' => [ 'label' => 'Month and name', 'structure' => '/%year%/%monthnum%/%postname%/' ],
        'numeric'        => [ 'label' => 'Numeric', 'structure' => '/archives/%post_id%' ],
        'post-name'      => [ 'label' => 'Post name', 'structure' => '/%postname%/' ],
    ];

    private const PERMALINK_OPTIONS = [ 'permalink_structure', 'category_base', 'tag_base' ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled    = (bool) apply_filters('wpmcp_enable_core_site_write', false);
        $no_args    = [ 'type' => 'object', 'properties' => [] ];
        $attachment = [
            'type'       => 'object',
            'properties' => [ 'attachment_id' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ] ],
            'required'   => [ 'attachment_id' ],
        ];
        $page       = [
            'type'       => 'object',
            'properties' => [ 'page_id' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ] ],
            'required'   => [ 'page_id' ],
        ];
        $hard       = [ 'hard' => [ 'type' => 'boolean' ] ];

        return [
            'get-site-identity'       => [
                'mode'         => 'read',
                'description'  => 'Read the site identity and homepage settings: name, tagline, home and site URL, language, timezone, logo (custom_logo theme mod) and icon (site_icon) with their attachment ids and URLs, show_on_front with the front page and posts page, and the permalink structure',
                'input_schema' => $no_args,
                'handler'      => static fn (): array => self::identity(),
            ],
            'get-permalink-structure' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read the permalink structure, category and tag bases, whether pretty permalinks are on, an example URL, the Permalinks screen presets each with its example URL and which one is current, the registered structure tags and the rewrite rule count',
                'input_schema' => $no_args,
                'handler'      => static fn (): array => self::permalink_structure(),
            ],
            'test-url'                => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Resolve a path or URL on this site the way WordPress routes a request: the rewrite rule it matches, the query it maps to, the resulting query vars, and the post or page it reaches (post_id 0 when none). Reports when no rule matches',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'url' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ] ],
                    'required'   => [ 'url' ],
                ],
                'validate'     => static fn (array $args): ?array => self::url_refusal((string) $args['url']),
                'handler'      => static fn (array $args): array => self::test_url((string) $args['url']),
            ],
            'set-site-logo'           => [
                'mode'               => 'write',
                'description'        => 'Set the site logo (the custom_logo theme mod) to an image attachment, or pass null to remove it. Refuses a missing or non-image attachment and a value already in place. Snapshotted on the theme_mods option; undo: rollback-operation. Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => $attachment,
                'objects'            => [ 'attachment_id' => 'post' ],
                'validate'           => static fn (array $args): ?array => self::logo_refusal(self::id_or_null($args['attachment_id'])),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(self::logo_options()) ],
                'handler'            => static fn (array $args): array => self::set_logo(self::id_or_null($args['attachment_id'])),
            ],
            'set-site-icon'           => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Set the site icon (favicon, the site_icon option) to an image attachment of at least 512 by 512 pixels, or pass null to remove it. Refuses a missing, non-image or too small attachment and a value already in place. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => $attachment,
                'objects'            => [ 'attachment_id' => 'post' ],
                'validate'           => static fn (array $args): ?array => self::icon_refusal(self::id_or_null($args['attachment_id'])),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => 'site_icon' ],
                'handler'            => static fn (array $args): array => self::set_icon(self::id_or_null($args['attachment_id'])),
            ],
            'set-front-page'          => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Show a published page as the static front page (sets show_on_front to page and page_on_front), or pass null to show the latest posts again. Refuses a missing, unpublished or non-page post, the current posts page, and a value already in place. Both options are one undo point; undo: rollback-operation. Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => $page,
                'objects'            => [ 'page_id' => 'post' ],
                'validate'           => static fn (array $args): ?array => self::front_page_refusal(self::id_or_null($args['page_id'])),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id([ 'show_on_front', 'page_on_front' ]) ],
                'handler'            => static fn (array $args): array => self::set_front_page(self::id_or_null($args['page_id'])),
            ],
            'set-posts-page'          => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Make a published page the posts page (page_for_posts), or pass null to clear it. Refuses a missing, unpublished or non-page post, the current front page, and a value already in place; notes when show_on_front still shows posts. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => $page,
                'objects'            => [ 'page_id' => 'post' ],
                'validate'           => static fn (array $args): ?array => self::posts_page_refusal(self::id_or_null($args['page_id'])),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => 'page_for_posts' ],
                'handler'            => static fn (array $args): array => self::set_posts_page(self::id_or_null($args['page_id'])),
            ],
            'set-permalink-structure' => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'Change the permalink structure (empty for plain, otherwise a structure with at least one %tag%, e.g. /%postname%/) and optionally the category_base and tag_base, then flush the rewrite rules (soft; hard:true also rewrites .htaccess or web.config like the Permalinks screen). Existing inbound links break, so it needs confirm:true and the preview shows the before and after example URL. The structure and both bases are one undo point (rollback-operation), which also rebuilds the rules (soft). Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'structure'     => [ 'type' => 'string', 'maxLength' => self::MAX_LENGTH ],
                        'category_base' => [ 'type' => 'string', 'maxLength' => self::MAX_LENGTH ],
                        'tag_base'      => [ 'type' => 'string', 'maxLength' => self::MAX_LENGTH ],
                    ] + $hard,
                    'required'   => [ 'structure' ],
                ],
                'validate'           => static fn (array $args): ?array => self::permalink_refusal($args),
                'confirm_preview'    => static fn (array $args): array => self::permalink_preview($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(self::PERMALINK_OPTIONS) ],
                'handler'            => static fn (array $args): array => self::set_permalink_structure($args),
            ],
            'flush-rewrite-rules'     => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Rebuild the rewrite rules from the current permalink structure and registered post types and taxonomies (soft; hard:true also rewrites .htaccess or web.config). Run it with hard:true after undoing a set-permalink-structure that used hard:true. Not snapshotted: rebuilding the rules destroys nothing. Disabled by default (wpmcp_enable_core_site_write filter)',
                'enabled_by_default' => $enabled,
                'enable_hint'        => self::ENABLE_HINT,
                'input_schema'       => [ 'type' => 'object', 'properties' => $hard ],
                'handler'            => static fn (array $args): array => self::flush(! empty($args['hard'])),
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Site identity
    // ---------------------------------------------------------------

    private static function identity(): array
    {
        $logo = (int) get_theme_mod('custom_logo');
        $icon = (int) get_option('site_icon');

        return [
            'name'                       => (string) get_bloginfo('name'),
            'tagline'                    => (string) get_bloginfo('description'),
            'home_url'                   => home_url('/'),
            'site_url'                   => site_url('/'),
            'language'                   => (string) get_bloginfo('language'),
            'timezone'                   => wp_timezone_string(),
            'logo'                       => $logo > 0 ? self::image_summary($logo) : null,
            'theme_supports_custom_logo' => current_theme_supports('custom-logo'),
            'icon'                       => $icon > 0 ? self::image_summary($icon) : null,
            'homepage'                   => self::homepage(),
            'permalink_structure'        => (string) get_option('permalink_structure'),
            'notes'                      => 'Name and tagline are written with update-settings (blogname, blogdescription); logo, icon and homepage with set-site-logo, set-site-icon, set-front-page and set-posts-page on this pair.',
        ];
    }

    private static function image_summary(int $attachment_id): array
    {
        $url = wp_get_attachment_image_url($attachment_id, 'full');

        return [
            'attachment_id' => $attachment_id,
            'url'           => is_string($url) && '' !== $url ? $url : null,
        ];
    }

    private static function homepage(): array
    {
        $show_on_front = (string) get_option('show_on_front', 'posts');

        return [
            'show_on_front' => $show_on_front,
            'front_page'    => 'page' === $show_on_front ? self::page_summary((int) get_option('page_on_front')) : null,
            'posts_page'    => self::page_summary((int) get_option('page_for_posts')),
        ];
    }

    private static function page_summary(int $page_id): ?array
    {
        $page = $page_id > 0 ? get_post($page_id) : null;
        if (! $page instanceof \WP_Post) {
            return null;
        }

        return [
            'id'     => $page->ID,
            'title'  => get_the_title($page),
            'status' => $page->post_status,
            'url'    => (string) get_permalink($page),
        ];
    }

    private static function id_or_null($value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    /** Refusal for an attachment that is not an existing image, or null. */
    private static function image_refusal(int $attachment_id): ?array
    {
        $post = get_post($attachment_id);
        if (! $post instanceof \WP_Post || 'attachment' !== $post->post_type) {
            return self::refusal('attachment_not_found', sprintf('Attachment %d does not exist.', $attachment_id));
        }
        if (! wp_attachment_is_image($post)) {
            return self::refusal('not_an_image', sprintf('Attachment %d is not an image (%s).', $attachment_id, $post->post_mime_type), [ 'mime_type' => $post->post_mime_type ]);
        }
        return null;
    }

    private static function logo_refusal(?int $attachment_id): ?array
    {
        if (null !== $attachment_id) {
            $refusal = self::image_refusal($attachment_id);
            if (null !== $refusal) {
                return $refusal;
            }
        }
        $current = (int) get_theme_mod('custom_logo');
        if (($attachment_id ?? 0) === $current) {
            return self::no_change(null === $attachment_id ? 'No site logo is set.' : sprintf('Attachment %d is already the site logo.', $attachment_id));
        }
        return null;
    }

    private static function icon_refusal(?int $attachment_id): ?array
    {
        if (null !== $attachment_id) {
            $refusal = self::image_refusal($attachment_id);
            if (null !== $refusal) {
                return $refusal;
            }
            $meta   = (array) wp_get_attachment_metadata($attachment_id);
            $width  = (int) ($meta['width'] ?? 0);
            $height = (int) ($meta['height'] ?? 0);
            if (($width > 0 && $width < self::ICON_MIN_EDGE) || ($height > 0 && $height < self::ICON_MIN_EDGE)) {
                return self::refusal(
                    'icon_too_small',
                    sprintf('A site icon must be at least %1$d by %1$d pixels; attachment %2$d is %3$d by %4$d.', self::ICON_MIN_EDGE, $attachment_id, $width, $height),
                    [ 'width' => $width, 'height' => $height, 'minimum' => self::ICON_MIN_EDGE ]
                );
            }
        }
        $current = (int) get_option('site_icon');
        if (($attachment_id ?? 0) === $current) {
            return self::no_change(null === $attachment_id ? 'No site icon is set.' : sprintf('Attachment %d is already the site icon.', $attachment_id));
        }
        return null;
    }

    /**
     * The two options a logo write touches: the theme_mods option holding
     * custom_logo, and site_logo, which the Site Logo block keeps in step
     * with it and which overrides the theme mod on read
     * (_override_custom_logo_theme_mod). Both are one undo point, so a
     * rollback never leaves the block showing a logo the mod no longer has.
     *
     * @return string[]
     */
    private static function logo_options(): array
    {
        return [ 'theme_mods_' . get_stylesheet(), 'site_logo' ];
    }

    private static function set_logo(?int $attachment_id): array
    {
        // Written explicitly rather than left to core's sync hooks, which
        // are wired on setup_theme and keep site_logo in step only while
        // they ran for this stylesheet.
        if (null === $attachment_id) {
            remove_theme_mod('custom_logo');
            delete_option('site_logo');
        } else {
            set_theme_mod('custom_logo', $attachment_id);
            update_option('site_logo', $attachment_id);
        }
        $supports = current_theme_supports('custom-logo');

        return [
            'custom_logo'                => $attachment_id,
            'url'                        => null === $attachment_id ? null : self::image_summary($attachment_id)['url'],
            'theme_supports_custom_logo' => $supports,
            'note'                       => $supports ? '' : 'The active theme does not declare custom-logo support, so it may not display the logo.',
        ];
    }

    private static function set_icon(?int $attachment_id): array
    {
        update_option('site_icon', $attachment_id ?? 0);
        $url = null === $attachment_id ? '' : get_site_icon_url(self::ICON_MIN_EDGE);

        return [
            'site_icon' => $attachment_id,
            'url'       => '' !== $url ? $url : null,
        ];
    }

    // ---------------------------------------------------------------
    // Homepage settings
    // ---------------------------------------------------------------

    /** Refusal for a post that is not a published page, or null. */
    private static function page_refusal(int $page_id): ?array
    {
        $page = get_post($page_id);
        if (! $page instanceof \WP_Post) {
            return self::refusal('page_not_found', sprintf('Page %d does not exist.', $page_id));
        }
        if ('page' !== $page->post_type) {
            return self::refusal('not_a_page', sprintf('Post %d is a %s, not a page.', $page_id, $page->post_type), [ 'post_type' => $page->post_type ]);
        }
        if ('publish' !== $page->post_status) {
            return self::refusal('page_not_published', sprintf('Page %d is %s; only a published page can be shown here.', $page_id, $page->post_status), [ 'status' => $page->post_status ]);
        }
        return null;
    }

    private static function front_page_refusal(?int $page_id): ?array
    {
        $show_on_front = (string) get_option('show_on_front', 'posts');
        if (null === $page_id) {
            return 'posts' === $show_on_front ? self::no_change('The front page already shows the latest posts.') : null;
        }
        $refusal = self::page_refusal($page_id);
        if (null !== $refusal) {
            return $refusal;
        }
        if ($page_id === (int) get_option('page_for_posts')) {
            return self::refusal('same_as_posts_page', sprintf('Page %d is the posts page; the front page and the posts page must differ.', $page_id));
        }
        if ('page' === $show_on_front && $page_id === (int) get_option('page_on_front')) {
            return self::no_change(sprintf('Page %d is already the front page.', $page_id));
        }
        return null;
    }

    private static function posts_page_refusal(?int $page_id): ?array
    {
        $current = (int) get_option('page_for_posts');
        if (null === $page_id) {
            return 0 === $current ? self::no_change('No posts page is set.') : null;
        }
        $refusal = self::page_refusal($page_id);
        if (null !== $refusal) {
            return $refusal;
        }
        if ('page' === (string) get_option('show_on_front') && $page_id === (int) get_option('page_on_front')) {
            return self::refusal('same_as_front_page', sprintf('Page %d is the front page; the front page and the posts page must differ.', $page_id));
        }
        if ($page_id === $current) {
            return self::no_change(sprintf('Page %d is already the posts page.', $page_id));
        }
        return null;
    }

    private static function set_front_page(?int $page_id): array
    {
        if (null === $page_id) {
            update_option('show_on_front', 'posts');
            update_option('page_on_front', 0);
        } else {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $page_id);
        }

        return self::homepage();
    }

    private static function set_posts_page(?int $page_id): array
    {
        update_option('page_for_posts', $page_id ?? 0);

        $out         = self::homepage();
        $out['note'] = null !== $page_id && 'page' !== $out['show_on_front']
            ? 'show_on_front is "posts", so the posts page only takes effect once set-front-page names a static front page.'
            : '';

        return $out;
    }

    // ---------------------------------------------------------------
    // Permalinks
    // ---------------------------------------------------------------

    private static function permalink_structure(): array
    {
        global $wp_rewrite;

        $structure = (string) get_option('permalink_structure');
        $sample    = self::sample_post();
        $presets   = [];
        foreach (self::PRESETS as $key => $preset) {
            $presets[] = [
                'key'       => $key,
                'label'     => $preset['label'],
                'structure' => $preset['structure'],
                'current'   => $preset['structure'] === $structure,
                'example'   => self::example($preset['structure'], $sample),
            ];
        }

        return [
            'structure'              => $structure,
            'category_base'          => (string) get_option('category_base'),
            'tag_base'               => (string) get_option('tag_base'),
            'using_permalinks'       => (bool) $wp_rewrite->using_permalinks(),
            'using_index_permalinks' => (bool) $wp_rewrite->using_index_permalinks(),
            'is_preset'              => in_array($structure, array_column(self::PRESETS, 'structure'), true),
            'example'                => self::example($structure, $sample),
            'presets'                => $presets,
            'tags'                   => array_values(array_map('strval', (array) $wp_rewrite->rewritecode)),
            'rewrite_rules_count'    => count((array) get_option('rewrite_rules', [])),
        ];
    }

    /** The newest published post, for example URLs; null on an empty site. */
    private static function sample_post(): ?\WP_Post
    {
        $posts = get_posts([ 'numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC' ]);
        return isset($posts[0]) && $posts[0] instanceof \WP_Post ? $posts[0] : null;
    }

    /**
     * The URL a structure gives the sample post (or a stand-in post when the
     * site has none), by substituting its tags; home_url() carries the
     * scheme and host, the structure the path.
     */
    private static function example(string $structure, ?\WP_Post $post): string
    {
        $id = null !== $post ? $post->ID : 123;
        if ('' === $structure) {
            return home_url('/?p=' . $id);
        }

        $time     = null !== $post ? strtotime($post->post_date) : time();
        $author   = null !== $post ? get_the_author_meta('user_nicename', (int) $post->post_author) : 'author';
        $category = 'uncategorized';
        if (null !== $post) {
            $categories = get_the_category($post->ID);
            if (isset($categories[0]) && $categories[0] instanceof \WP_Term) {
                $category = $categories[0]->slug;
            }
        }
        $values = [
            '%year%'     => gmdate('Y', (int) $time),
            '%monthnum%' => gmdate('m', (int) $time),
            '%day%'      => gmdate('d', (int) $time),
            '%hour%'     => gmdate('H', (int) $time),
            '%minute%'   => gmdate('i', (int) $time),
            '%second%'   => gmdate('s', (int) $time),
            '%post_id%'  => (string) $id,
            '%postname%' => null !== $post && '' !== $post->post_name ? $post->post_name : 'sample-post',
            '%category%' => $category,
            '%author%'   => is_string($author) && '' !== $author ? $author : 'author',
        ];

        return home_url(strtr($structure, $values));
    }

    private static function url_refusal(string $url): ?array
    {
        $parts = wp_parse_url(trim($url));
        if (! is_array($parts)) {
            return self::refusal('invalid_url', 'The url could not be parsed.');
        }
        $home_host = strtolower((string) (wp_parse_url(home_url('/'))['host'] ?? ''));
        if (isset($parts['host']) && strtolower((string) $parts['host']) !== $home_host) {
            return self::refusal('url_not_on_site', sprintf('%s is not on this site (%s); only this site\'s URLs can be resolved.', $parts['host'], $home_host), [ 'home_url' => home_url('/') ]);
        }
        return null;
    }

    /**
     * Resolve a path the way WP::parse_request() does: strip the home path
     * and the index.php of index permalinks, try every rewrite rule in
     * order (the verbose page rule check included), map the matches into
     * the query, and name the post url_to_postid() reaches.
     */
    private static function test_url(string $url): array
    {
        global $wp_rewrite;

        $parts     = (array) wp_parse_url(trim($url));
        $home_path = trim((string) (wp_parse_url(home_url('/'))['path'] ?? ''), '/');
        $path      = trim((string) ($parts['path'] ?? ''), '/');
        if ('' !== $home_path && str_starts_with($path . '/', $home_path . '/')) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }
        $index = (string) $wp_rewrite->index;
        if ($path === $index) {
            $path = '';
        } elseif (str_starts_with($path, $index . '/')) {
            $path = substr($path, strlen($index) + 1);
        }

        $query_string = (string) ($parts['query'] ?? '');
        $explicit     = [];
        parse_str($query_string, $explicit);

        $out = [
            'url'              => home_url('/' . $path . ('' === $path ? '' : '/') . ('' !== $query_string ? '?' . $query_string : '')),
            'request'          => $path,
            'using_permalinks' => (bool) $wp_rewrite->using_permalinks(),
            'matched_rule'     => null,
            'matched_query'    => null,
            'query_vars'       => $explicit,
            'post_id'          => 0,
            'post'             => null,
            'resolves_to'      => '',
            'note'             => '',
        ];

        if (! $out['using_permalinks']) {
            $out['note'] = 'Plain permalinks are in use: no rewrite rule applies, the query string alone decides what a request shows.';
        } else {
            $rules   = (array) $wp_rewrite->wp_rewrite_rules();
            $matches = [];
            if ('' === $path) {
                if (isset($rules['$'])) {
                    $out['matched_rule'] = '$';
                    $query               = (string) $rules['$'];
                    $matches             = [ '' ];
                }
            } else {
                foreach ($rules as $match => $query) {
                    $match = (string) $match;
                    if (! preg_match("#^$match#", $path, $matches) && ! preg_match("#^$match#", urldecode($path), $matches)) {
                        continue;
                    }
                    if ($wp_rewrite->use_verbose_page_rules && preg_match('/pagename=\$matches\[([0-9]+)\]/', (string) $query, $var)) {
                        $page = get_page_by_path((string) ($matches[ (int) $var[1] ] ?? ''));
                        if (! $page instanceof \WP_Post) {
                            continue;
                        }
                    }
                    $out['matched_rule'] = $match;
                    break;
                }
            }
            if (null !== $out['matched_rule']) {
                $query                = (string) preg_replace('!^.+\?!', '', (string) $query);
                $query                = \WP_MatchesMapRegex::apply($query, $matches);
                $out['matched_query'] = $query;
                $vars                 = [];
                parse_str($query, $vars);
                $out['query_vars'] = $vars + $explicit;
            }
        }

        $post_id = (int) url_to_postid($out['url']);
        if ($post_id > 0) {
            $post            = get_post($post_id);
            $out['post_id']  = $post_id;
            $out['post']     = $post instanceof \WP_Post ? [ 'id' => $post->ID, 'type' => $post->post_type, 'title' => get_the_title($post), 'status' => $post->post_status ] : null;
        }

        if ('' === $path && [] === $explicit) {
            $out['resolves_to'] = 'front page';
        } elseif ($post_id > 0) {
            $out['resolves_to'] = 'post';
        } elseif (null !== $out['matched_rule'] || [] !== $explicit) {
            $out['resolves_to'] = 'query';
        } else {
            $out['resolves_to'] = 'nothing';
            $out['note']        = trim($out['note'] . ' No rewrite rule matches this path, so it answers 404 unless a file exists there.');
        }

        return $out;
    }

    /**
     * Validate and normalize a set-permalink-structure call. Returns the plan
     * (structure, bases, what they are now) or a refusal.
     *
     * @return array{error?: array, structure?: string, category_base?: ?string, tag_base?: ?string, before?: array<string,string>, after?: array<string,string>}
     */
    private static function permalink_plan(array $args): array
    {
        global $wp_rewrite;

        $before = [];
        foreach (self::PERMALINK_OPTIONS as $option) {
            $before[ $option ] = (string) get_option($option);
        }

        $structure = trim((string) $args['structure']);
        if ('' !== $structure) {
            $structure = (string) preg_replace('#/+#', '/', '/' . str_replace('#', '', $structure));
            if (strlen($structure) > self::MAX_LENGTH || ! preg_match(self::STRUCTURE_PATTERN, $structure) || str_contains($structure, '..')) {
                return [ 'error' => self::refusal('invalid_structure', 'A permalink structure may contain letters, digits, / - _ . and %tag% placeholders only.') ];
            }
            if (! preg_match_all('/%[^\/%]+%/', $structure, $tags)) {
                return [ 'error' => self::refusal('structure_tag_required', 'A structure tag such as %postname% or %post_id% is required when using custom permalinks.', [ 'tags' => array_values((array) $wp_rewrite->rewritecode) ]) ];
            }
            $known   = array_map('strval', (array) $wp_rewrite->rewritecode);
            $unknown = array_values(array_diff(array_unique($tags[0]), $known));
            if ([] !== $unknown) {
                return [ 'error' => self::refusal('unknown_structure_tag', sprintf('Unknown structure tag %s.', implode(', ', $unknown)), [ 'unknown' => $unknown, 'tags' => $known ]) ];
            }
        }

        $bases = [];
        foreach ([ 'category_base', 'tag_base' ] as $option) {
            if (! array_key_exists($option, $args)) {
                $bases[ $option ] = null;
                continue;
            }
            // Stored the way core reads it back (_wp_filter_taxonomy_base
            // strips the surrounding slashes on every read), so the before
            // and after values compare like for like.
            $base = trim((string) preg_replace('#/+#', '/', str_replace('#', '', trim((string) $args[ $option ]))), '/');
            if ('' !== $base && (strlen($base) > self::MAX_LENGTH || ! preg_match(self::BASE_PATTERN, $base) || str_contains($base, '..'))) {
                return [ 'error' => self::refusal('invalid_base', sprintf('%s may contain letters, digits, / - _ and . only.', $option), [ 'option' => $option ]) ];
            }
            $bases[ $option ] = $base;
        }

        $after = [
            'permalink_structure' => $structure,
            'category_base'       => $bases['category_base'] ?? $before['category_base'],
            'tag_base'            => $bases['tag_base'] ?? $before['tag_base'],
        ];
        if ($after === $before) {
            return [ 'error' => self::no_change('The permalink structure and bases are already set this way.') ];
        }

        return [
            'structure'     => $structure,
            'category_base' => $bases['category_base'],
            'tag_base'      => $bases['tag_base'],
            'before'        => $before,
            'after'         => $after,
        ];
    }

    private static function permalink_refusal(array $args): ?array
    {
        return self::permalink_plan($args)['error'] ?? null;
    }

    private static function permalink_preview(array $args): array
    {
        $plan   = self::permalink_plan($args);
        $sample = self::sample_post();
        $data   = [
            'before'         => $plan['before'],
            'after'          => $plan['after'],
            'example_before' => self::example($plan['before']['permalink_structure'], $sample),
            'example_after'  => self::example($plan['after']['permalink_structure'], $sample),
        ];

        return [
            'message' => sprintf(
                'This changes the permalink structure from "%s" to "%s" (for example %s becomes %s). Existing inbound links to the old URLs break unless they are redirected; the change is undoable with rollback-operation.',
                $plan['before']['permalink_structure'],
                $plan['after']['permalink_structure'],
                $data['example_before'],
                $data['example_after']
            ),
            'data'    => $data,
        ];
    }

    private static function set_permalink_structure(array $args): array
    {
        global $wp_rewrite;

        $plan   = self::permalink_plan($args);
        $sample = self::sample_post();

        // Sync the in-memory structure with the options first: the setter
        // compares against it and would skip a write it believes is a no-op.
        $wp_rewrite->init();
        $wp_rewrite->set_permalink_structure($plan['structure']);
        if (null !== $plan['category_base']) {
            $wp_rewrite->set_category_base($plan['category_base']);
        }
        if (null !== $plan['tag_base']) {
            $wp_rewrite->set_tag_base($plan['tag_base']);
        }
        self::sync_taxonomy_permastructs();
        $hard = ! empty($args['hard']);
        flush_rewrite_rules($hard);

        return [
            'before'          => $plan['before'],
            'after'           => $plan['after'],
            'example_before'  => self::example($plan['before']['permalink_structure'], $sample),
            'example_after'   => self::example($plan['after']['permalink_structure'], $sample),
            'rewrite_flushed' => $hard ? 'hard' : 'soft',
            'redirects'       => 'Inbound links to the old URLs now answer 404 unless redirected: add redirects from the old paths to the new ones (the Redirection ops on this pair, or the redirects tools) before announcing the change.',
            'after_rollback'  => 'rollback-operation restores the structure and both bases and rebuilds the rewrite rules for them (soft); run flush-rewrite-rules with hard:true afterwards only if this change was made with hard:true.',
        ];
    }

    /**
     * Point the category and post_tag permastructs at the bases and front
     * stored now. Both were registered at init from the options stored
     * then, and WP_Rewrite::init() does not touch them, so a flush in the
     * same request after a base or front change wrote rules for the old
     * bases until a second flush (issue #785). The slug and with_front are
     * the ones create_initial_taxonomies() derives from the options.
     *
     * WP_Rewrite also merges each permastruct's rules into extra_rules_top
     * whenever it builds the rules and never clears them in that request,
     * so the rules the old permastruct produced are dropped from there too;
     * otherwise a build earlier in the request keeps the old base routing.
     */
    private static function sync_taxonomy_permastructs(): void
    {
        global $wp_rewrite;

        $wp_rewrite->init();
        foreach ([ 'category' => [ 'category_base', 'category' ], 'post_tag' => [ 'tag_base', 'tag' ] ] as $taxonomy => [ $option, $default ]) {
            $object = get_taxonomy($taxonomy);
            if (! $object instanceof \WP_Taxonomy || ! is_array($object->rewrite)) {
                continue;
            }
            $old = $wp_rewrite->extra_permastructs[ $taxonomy ] ?? null;
            if (is_array($old) && isset($old['struct'])) {
                $stale                       = (array) $wp_rewrite->generate_rewrite_rules((string) $old['struct'], (int) ($old['ep_mask'] ?? EP_NONE), (bool) ($old['paged'] ?? true), (bool) ($old['feed'] ?? true), (bool) ($old['forcomments'] ?? false), (bool) ($old['walk_dirs'] ?? true), (bool) ($old['endpoints'] ?? true));
                $wp_rewrite->extra_rules_top = array_diff_key((array) $wp_rewrite->extra_rules_top, $stale);
            }
            $base = (string) get_option($option);
            $object->remove_rewrite_rules();
            $object->rewrite['slug']       = '' !== $base ? $base : $default;
            $object->rewrite['with_front'] = '' === $base || $wp_rewrite->using_index_permalinks();
            $object->add_rewrite_rules();
        }
    }

    /**
     * After a rollback put options back (wpmcp_rollback_options_restored):
     * when the undo touched the permalink structure or a base, rebuild the
     * rewrite rules for the restored values the way the forward write did
     * (soft), so the old URLs route again without a separate flush.
     *
     * @param string[] $names
     */
    public static function after_options_restored(array $names): void
    {
        if ([] === array_intersect(self::PERMALINK_OPTIONS, array_map('strval', $names))) {
            return;
        }
        self::sync_taxonomy_permastructs();
        flush_rewrite_rules(false);
    }

    private static function flush(bool $hard): array
    {
        global $wp_rewrite;

        flush_rewrite_rules($hard);

        return [
            'flushed'          => $hard ? 'hard' : 'soft',
            'using_permalinks' => (bool) $wp_rewrite->using_permalinks(),
            'rules_count'      => count((array) get_option('rewrite_rules', [])),
        ];
    }

    // ---------------------------------------------------------------
    // Refusal shapes
    // ---------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function no_change(string $message): array
    {
        return self::refusal('no_change', $message . ' Nothing was written and no undo point was taken.');
    }
}
