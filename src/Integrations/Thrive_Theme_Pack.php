<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Builders\Thrive_Content;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Theme Builder templates, sections and theme settings (issue #824)
 * as ops on the theme pair, read and written the way Thrive Theme Builder
 * 10.8 stores them:
 *  - templates are thrive_template posts in the active skin (the
 *    thrive_skin_tax term whose is_active meta is 1). Their meta names what
 *    they render (primary_template, secondary_template, variable_template,
 *    format, and default for the type's default template), their layout
 *    (layout, a thrive_layout id) and their sections (sections, keyed
 *    header, footer, top, content, sidebar and bottom, each an array with
 *    'id' and 'content': a non-zero id links a shared section, otherwise
 *    the markup is the template's own);
 *  - shared sections are thrive_section posts in the skin (type and content
 *    meta), and headers and footers are Thrive Architect symbols (tcb_symbol
 *    posts in the headers or footers term of tcb_symbols_tax) whose markup
 *    is in tve_updated_post, written through the Thrive Architect adapter
 *    (Thrive_Content, issue #640);
 *  - a post's own template is its thrive_post_template meta, offered by
 *    Thrive's picker only from the skin's singular (or, for the front page,
 *    home) templates of that post type;
 *  - the theme settings written here are the logo link, the favicon and
 *    the default featured image options of the theme dashboard.
 *
 * Contract: tier pro, capability edit_theme_options, a 'requires' check
 * that answers plugin_missing or plugin_inactive naming the theme and the
 * Themes screen, writes off until the site opts in with
 * wpmcp_enable_theme_write (the theme pair's switch), every refusal decided
 * in 'validate' before any snapshot, and every write snapshotted (the
 * template, section or post with all its meta, or the settings options as
 * one set), so rollback-operation restores it exactly. Markup writes are
 * pinned to the sha256 of the stored markup (expected_hash), as the page
 * builder writes are, and need unfiltered_html, since Thrive markup is not
 * kses-safe.
 *
 * Out of this slice: switching a type's default template (two templates
 * change together), creating templates or sections, and the skin palette
 * and typography.
 */
final class Thrive_Theme_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    private const PRODUCT = 'Thrive Theme Builder';

    private const THEME = 'thrive-theme';

    private const TEMPLATE = 'thrive_template';

    private const SECTION = 'thrive_section';

    private const LAYOUT = 'thrive_layout';

    private const SYMBOL = 'tcb_symbol';

    private const SKIN_TAX = 'thrive_skin_tax';

    private const SYMBOL_TAX = 'tcb_symbols_tax';

    private const POST_TEMPLATE_META = 'thrive_post_template';

    /** Header and footer symbols live in these terms of tcb_symbols_tax. */
    private const HF_TERMS = [ 'header' => 'headers', 'footer' => 'footers' ];

    /** Template section slots, as the layout's [thrive_template_section] shortcodes name them. */
    private const SLOTS = [ 'header', 'footer', 'top', 'content', 'sidebar', 'bottom' ];

    private const PRIMARY = [ 'singular', 'archive', 'home', 'search', 'error404' ];

    /** Writable settings => the option behind each. */
    private const SETTINGS = [
        'logo_url'               => 'thrive-theme-logo-url',
        'favicon'                => 'thrive-theme-favicon',
        'default_featured_image' => 'thrive-theme-default-featured-image',
    ];

    /** Posts listed per template as using it. */
    private const MAX_ASSIGNED = 100;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $base  = [
            'tier'       => 'pro',
            'capability' => 'edit_theme_options',
            'requires'   => static fn () => self::requirement(),
        ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
            'opt_in_filter'      => 'wpmcp_enable_theme_write',
        ];
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $own   = static fn (string $access): array => [ 'type' => 'post', 'own_type' => true, 'access' => $access ];
        $hash  = [ 'type' => 'string', 'pattern' => '^[0-9a-f]{64}$' ];
        $post  = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['template_id'] ];

        return [
            'list-thrive-templates'           => $base + [
                'mode'         => 'read',
                'description'  => 'List the Thrive Theme Builder templates of the active skin (id, title, what they render, whether default for that type, layout, header and footer), filtered by primary type; paged',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'primary'  => [ 'type' => 'string', 'enum' => self::PRIMARY ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_templates($args),
            ],
            'get-thrive-template'             => $base + [
                'mode'         => 'read',
                'objects'      => [ 'template_id' => $own('read') ],
                'description'  => 'Read one Thrive Theme Builder template: its sections (shared ones by id, its own with markup and hash), layout, header, footer and the posts assigned to it',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => $id ], 'required' => [ 'template_id' ] ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::must_template((int) $args['template_id'])),
                'handler'      => static fn (array $args): array => [ 'template' => self::template_view(self::must_template((int) $args['template_id']), true) ],
            ],
            'list-thrive-sections'            => $base + [
                'mode'         => 'read',
                'description'  => 'List the Thrive Theme Builder headers and footers and the shared sections of the active skin (id, title, type, markup hash, the templates using each); with_content adds the markup',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'         => [ 'type' => 'string', 'enum' => self::SLOTS ],
                        'with_content' => [ 'type' => 'boolean' ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_sections($args),
            ],
            'get-thrive-theme-settings'       => $base + [
                'mode'         => 'read',
                'description'  => 'Read the active Thrive Theme Builder skin and the theme settings these ops write: logo link, favicon and default featured image',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::settings_view(),
            ],
            'update-thrive-template-settings' => $write + [
                'objects'      => [ 'template_id' => $own('write'), 'layout_id' => $own('read'), 'header_id' => $own('read'), 'footer_id' => $own('read') ],
                'description'  => 'Change one Thrive Theme Builder template\'s title, layout (a layout of the skin), header or footer (a header or footer from list-thrive-sections). Omitted fields are kept. Snapshotted and reversible',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'template_id' => $id,
                        'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'layout_id'   => $id,
                        'header_id'   => $id,
                        'footer_id'   => $id,
                    ],
                    'required'      => [ 'template_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_template_settings($args)),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::update_template_settings($args),
            ],
            'assign-thrive-template'          => $write + [
                'objects'      => [ 'post_id' => 'post', 'template_id' => $own('read') ],
                'description'  => 'Show one post or page with a Thrive Theme Builder template of its own (one Thrive offers for it: a singular template of its post type, or home for the front page), or pass template_id 0 to go back to the default. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'post_id' => $id, 'template_id' => [ 'type' => 'integer', 'minimum' => 0 ] ],
                    'required'   => [ 'post_id', 'template_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_assign($args)),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['post_id'] ],
                'handler'      => static fn (array $args): array => self::assign($args),
            ],
            'update-thrive-section'           => $write + [
                'objects'      => [ 'section_id' => $own('write'), 'template_id' => $own('write') ],
                'description'  => 'Change a Thrive Theme Builder section: a header, footer or shared section by section_id (title and markup), or a template\'s own section by template_id and type (markup). Markup needs expected_hash, the hash a read returned. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'section_id'    => $id,
                        'template_id'   => $id,
                        'type'          => [ 'type' => 'string', 'enum' => self::SLOTS ],
                        'title'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'content'       => [ 'type' => 'string' ],
                        'expected_hash' => $hash,
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_section($args)),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) ($args['section_id'] ?? $args['template_id'] ?? 0) ],
                'handler'      => static fn (array $args): array => self::update_section($args),
            ],
            'update-thrive-theme-settings'    => $write + [
                'description'  => 'Set Thrive Theme Builder theme settings: logo_url (where the logo links, empty for the home page), favicon and default_featured_image (image attachment ids, 0 for Thrive\'s placeholder). The options are one undo point; snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'settings' => [
                            'type'          => 'object',
                            'minProperties' => 1,
                            'properties'    => [
                                'logo_url'               => [ 'type' => 'string', 'maxLength' => 2000 ],
                                'favicon'                => [ 'type' => 'integer', 'minimum' => 0 ],
                                'default_featured_image' => [ 'type' => 'integer', 'minimum' => 0 ],
                            ],
                        ],
                    ],
                    'required'   => [ 'settings' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_settings((array) $args['settings'])),
                'snapshot'     => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(array_values(self::SETTINGS)) ],
                'handler'      => static fn (array $args): array => self::update_settings((array) $args['settings']),
            ],
        ];
    }

    /** Thrive Theme Builder is the active theme and loaded, filterable through wpmcp_thrive_theme_active. */
    public static function active(): bool
    {
        $loaded = self::THEME === get_template() && defined('THEME_VERSION') && class_exists('\Thrive_Template', false);
        return (bool) apply_filters('wpmcp_thrive_theme_active', $loaded);
    }

    /** @return true|array{code:string,message:string,data:array<string,mixed>} */
    public static function requirement()
    {
        if (self::active()) {
            return true;
        }
        $installed = wp_get_theme(self::THEME)->exists();
        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                '%1$s is %2$s on this site. Install and activate %1$s: %3$s',
                self::PRODUCT,
                $installed ? 'installed but not the active theme' : 'not installed',
                admin_url('themes.php')
            ),
            'data'    => [ 'plugin' => self::THEME, 'plugin_name' => self::PRODUCT, 'state' => $installed ? 'inactive' : 'missing', 'plugins_url' => admin_url('themes.php') ],
        ];
    }

    // Reads.

    private static function skin(): int
    {
        $terms = get_terms([
            'taxonomy'   => self::SKIN_TAX,
            'hide_empty' => false,
            'number'     => 1,
            'fields'     => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Thrive marks its active skin this way and reads it the same way.
            'meta_query' => [ [ 'key' => 'is_active', 'value' => 1, 'compare' => '=' ] ],
        ]);
        return is_array($terms) && [] !== $terms ? (int) $terms[0] : 0;
    }

    private static function in_skin(int $id): bool
    {
        $skin = self::skin();
        return 0 !== $skin && has_term($skin, self::SKIN_TAX, $id);
    }

    /** @return int[] */
    private static function skin_posts(string $type, array $meta_query = []): array
    {
        $skin = self::skin();
        if (0 === $skin) {
            return [];
        }
        $args = [
            'post_type'      => $type,
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => 500,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the skin is a term, as Thrive queries it.
            'tax_query'      => [ [ 'taxonomy' => self::SKIN_TAX, 'field' => 'term_id', 'terms' => $skin ] ],
        ];
        if ([] !== $meta_query) {
            $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Thrive's own template lookup.
        }
        return array_map('intval', Readable_Posts::get_posts($args));
    }

    /** @return array<string,mixed> */
    private static function list_templates(array $args): array
    {
        $meta = isset($args['primary']) ? [ [ 'key' => 'primary_template', 'value' => (string) $args['primary'] ] ] : [];
        $ids  = self::skin_posts(self::TEMPLATE, $meta);
        $per  = (int) ($args['per_page'] ?? 50);
        $page = (int) ($args['page'] ?? 1);
        $out  = [];
        foreach (array_slice($ids, ($page - 1) * $per, $per) as $id) {
            $post = get_post($id);
            if ($post instanceof \WP_Post) {
                $out[] = self::template_view($post, false);
            }
        }
        return [ 'skin' => self::skin(), 'total' => count($ids), 'page' => $page, 'per_page' => $per, 'templates' => $out ];
    }

    /** @return array<string,mixed> */
    private static function sections_of(int $id): array
    {
        $sections = get_post_meta($id, 'sections', true);
        return is_array($sections) ? $sections : [];
    }

    /** @return array<string,mixed> */
    private static function template_view(\WP_Post $post, bool $full): array
    {
        $id       = (int) $post->ID;
        $sections = self::sections_of($id);
        $hf       = static fn (string $slot): array => [
            'id'   => (int) ($sections[ $slot ]['id'] ?? 0),
            'hide' => ! empty($sections[ $slot ]['hide']),
        ];
        $out      = [
            'id'        => $id,
            'title'     => $post->post_title,
            'status'    => $post->post_status,
            'primary'   => (string) get_post_meta($id, 'primary_template', true),
            'secondary' => (string) get_post_meta($id, 'secondary_template', true),
            'variable'  => (string) get_post_meta($id, 'variable_template', true),
            'format'    => (string) get_post_meta($id, 'format', true),
            'default'   => 1 === (int) get_post_meta($id, 'default', true),
            'layout_id' => (int) get_post_meta($id, 'layout', true),
            'header'    => $hf('header'),
            'footer'    => $hf('footer'),
            'modified'  => (string) $post->post_modified_gmt,
        ];
        if (! $full) {
            return $out;
        }
        $out['sections'] = [];
        foreach (self::SLOTS as $slot) {
            if (! isset($sections[ $slot ]) || ! is_array($sections[ $slot ])) {
                continue;
            }
            $shared = (int) ($sections[ $slot ]['id'] ?? 0);
            $row    = [ 'id' => $shared, 'shared' => 0 !== $shared, 'hide' => ! empty($sections[ $slot ]['hide']) ];
            if (0 === $shared) {
                $html            = (string) ($sections[ $slot ]['content'] ?? '');
                $row['content']  = $html;
                $row['hash']     = hash('sha256', $html);
            }
            $out['sections'][ $slot ] = $row;
        }
        $out['assigned_posts'] = array_map('intval', Readable_Posts::get_posts([
            'post_type'      => 'any',
            'posts_per_page' => self::MAX_ASSIGNED,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Thrive's own per-post template meta.
            'meta_key'       => self::POST_TEMPLATE_META,
            'meta_value'     => (string) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]));
        return $out;
    }

    /** @return array<string,mixed> */
    private static function list_sections(array $args): array
    {
        $only  = isset($args['type']) ? (string) $args['type'] : null;
        $rows  = [];
        $users = self::section_users();
        foreach (self::HF_TERMS as $slot => $term) {
            if (null !== $only && $only !== $slot) {
                continue;
            }
            $ids = Readable_Posts::get_posts([
                'post_type'      => self::SYMBOL,
                'post_status'    => 'publish',
                'posts_per_page' => 500,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Thrive's own header and footer query.
                'tax_query'      => [ [ 'taxonomy' => self::SYMBOL_TAX, 'field' => 'slug', 'terms' => $term ] ],
            ]);
            foreach ($ids as $id) {
                $rows[] = self::section_view((int) $id, $slot, $users, ! empty($args['with_content']));
            }
        }
        foreach (self::skin_posts(self::SECTION) as $id) {
            $type = (string) get_post_meta($id, 'type', true);
            if (null === $only || $only === $type) {
                $rows[] = self::section_view($id, $type, $users, ! empty($args['with_content']));
            }
        }
        return [ 'skin' => self::skin(), 'sections' => $rows ];
    }

    /**
     * Section id => the skin's templates linking it.
     *
     * @return array<int,int[]>
     */
    private static function section_users(): array
    {
        $users = [];
        foreach (self::skin_posts(self::TEMPLATE) as $tpl) {
            foreach (self::sections_of($tpl) as $section) {
                $id = is_array($section) ? (int) ($section['id'] ?? 0) : 0;
                if (0 !== $id) {
                    $users[ $id ][] = $tpl;
                }
            }
        }
        return $users;
    }

    /** @param array<int,int[]> $users */
    private static function section_view(int $id, string $type, array $users, bool $content): array
    {
        $post = get_post($id);
        $html = self::section_markup($id);
        $row  = [
            'id'      => $id,
            'title'   => $post instanceof \WP_Post ? $post->post_title : '',
            'type'    => $type,
            'hash'    => hash('sha256', $html),
            'used_by' => array_values(array_unique($users[ $id ] ?? [])),
        ];
        if ($content) {
            $row['content'] = $html;
        }
        return $row;
    }

    private static function section_markup(int $id): string
    {
        $key   = self::SYMBOL === get_post_type($id) ? Thrive_Content::LAYOUT_META_KEY : 'content';
        $value = get_post_meta($id, $key, true);
        return is_string($value) ? $value : '';
    }

    /** @return array<string,mixed> */
    private static function settings_view(): array
    {
        $skin = self::skin();
        $term = 0 === $skin ? null : get_term($skin, self::SKIN_TAX);
        $url  = get_option(self::SETTINGS['logo_url'], '');
        return [
            'skin'     => $term instanceof \WP_Term ? [ 'id' => (int) $term->term_id, 'name' => $term->name ] : null,
            'settings' => [
                'logo_url'               => is_string($url) ? $url : '',
                'favicon'                => (int) get_option(self::SETTINGS['favicon'], 0),
                'default_featured_image' => (int) get_option(self::SETTINGS['default_featured_image'], 0),
            ],
            'writable' => array_keys(self::SETTINGS),
        ];
    }

    // Plans: every refusal, before any snapshot.

    /** @throws Operation_Refused */
    private static function must_template(int $id): \WP_Post
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::TEMPLATE !== $post->post_type || 'trash' === $post->post_status || ! self::in_skin($id)) {
            throw new Operation_Refused('template_not_found', sprintf('No %s template with id %d in the active skin.', self::PRODUCT, $id), [ 'template_id' => $id ]);
        }
        return $post;
    }

    /** @throws Operation_Refused */
    private static function must_hf(int $id, string $slot): void
    {
        $post = get_post($id);
        if (
            ! $post instanceof \WP_Post || self::SYMBOL !== $post->post_type || 'publish' !== $post->post_status
            || ! has_term(self::HF_TERMS[ $slot ], self::SYMBOL_TAX, $id)
        ) {
            throw new Operation_Refused('invalid_' . $slot, sprintf('Post %1$d is not a published %2$s %3$s; pick one from list-thrive-sections.', $id, self::PRODUCT, $slot), [ $slot . '_id' => $id ]);
        }
    }

    /**
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    private static function plan_template_settings(array $args): array
    {
        $post = self::must_template((int) $args['template_id']);
        $plan = [ 'id' => (int) $post->ID, 'post' => [], 'layout' => null, 'sections' => [] ];
        if (isset($args['title'])) {
            $plan['post']['post_title'] = sanitize_text_field((string) $args['title']);
        }
        if (isset($args['layout_id'])) {
            $layout = (int) $args['layout_id'];
            if (self::LAYOUT !== get_post_type($layout) || ! self::in_skin($layout)) {
                throw new Operation_Refused('invalid_layout', sprintf('Post %d is not a layout of the active %s skin.', $layout, self::PRODUCT), [ 'layout_id' => $layout ]);
            }
            $plan['layout'] = $layout;
        }
        foreach ([ 'header', 'footer' ] as $slot) {
            if (isset($args[ $slot . '_id' ])) {
                self::must_hf((int) $args[ $slot . '_id' ], $slot);
                $plan['sections'][ $slot ] = (int) $args[ $slot . '_id' ];
            }
        }
        return $plan;
    }

    /**
     * @return array{post_id:int,template_id:int}
     * @throws Operation_Refused
     */
    private static function plan_assign(array $args): array
    {
        $post_id = (int) $args['post_id'];
        $target  = get_post($post_id);
        if (! $target instanceof \WP_Post || in_array($target->post_type, [ self::TEMPLATE, self::SECTION, self::LAYOUT, self::SYMBOL ], true)) {
            throw new Operation_Refused('post_not_found', sprintf('No post with id %d to give a template.', $post_id), [ 'post_id' => $post_id ]);
        }
        $tpl_id = (int) $args['template_id'];
        if (0 === $tpl_id) {
            return [ 'post_id' => $post_id, 'template_id' => 0 ];
        }
        self::must_template($tpl_id);
        $offered = self::offered_templates($target);
        if (! in_array($tpl_id, $offered, true)) {
            throw new Operation_Refused('template_not_assignable', sprintf('%s does not offer template %d for this %s.', self::PRODUCT, $tpl_id, $target->post_type), [ 'template_id' => $tpl_id, 'offered' => $offered ]);
        }
        return [ 'post_id' => $post_id, 'template_id' => $tpl_id ];
    }

    /**
     * The templates Thrive's own picker offers a post (Thrive_Post::get_all_templates()):
     * the skin's singular templates of its post type (home ones for the
     * front page), or every singular one when the type has none.
     *
     * @return int[]
     */
    private static function offered_templates(\WP_Post $post): array
    {
        $front   = 'page' === get_option('show_on_front') && (int) get_option('page_on_front') === (int) $post->ID;
        $primary = [ [ 'key' => 'primary_template', 'value' => $front ? 'home' : 'singular' ] ];
        $typed   = self::skin_posts(self::TEMPLATE, array_merge($primary, [ [ 'key' => 'secondary_template', 'value' => $post->post_type ] ]));
        return [] !== $typed ? $typed : self::skin_posts(self::TEMPLATE, $primary);
    }

    /**
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    private static function plan_section(array $args): array
    {
        $by_id  = isset($args['section_id']);
        $by_tpl = isset($args['template_id']) || isset($args['type']);
        if ($by_id === $by_tpl || ($by_tpl && ! (isset($args['template_id']) && isset($args['type'])))) {
            throw new Operation_Refused('invalid_args', 'Pass section_id for a header, footer or shared section, or template_id with type for a template\'s own section.');
        }
        if (! array_key_exists('content', $args) && ! isset($args['title'])) {
            throw new Operation_Refused('invalid_args', 'Pass content (with expected_hash) or, for a section_id, title.');
        }

        if ($by_id) {
            $id   = (int) $args['section_id'];
            $type = get_post_type($id);
            $ok   = (self::SECTION === $type && self::in_skin($id)) || (self::SYMBOL === $type && (has_term('headers', self::SYMBOL_TAX, $id) || has_term('footers', self::SYMBOL_TAX, $id)));
            if (! $ok || 'trash' === get_post_status($id)) {
                throw new Operation_Refused('section_not_found', sprintf('No %s header, footer or shared section with id %d; see list-thrive-sections.', self::PRODUCT, $id), [ 'section_id' => $id ]);
            }
            $current = self::section_markup($id);
            $plan    = [ 'section_id' => $id, 'template_id' => 0, 'slot' => '', 'post' => [] ];
            if (isset($args['title'])) {
                $plan['post']['post_title'] = sanitize_text_field((string) $args['title']);
            }
        } else {
            if (isset($args['title'])) {
                throw new Operation_Refused('invalid_args', 'A template\'s own section has no title; rename the template with update-thrive-template-settings.');
            }
            $tpl      = self::must_template((int) $args['template_id']);
            $slot     = (string) $args['type'];
            $sections = self::sections_of((int) $tpl->ID);
            $shared   = (int) ($sections[ $slot ]['id'] ?? 0);
            if (0 !== $shared) {
                throw new Operation_Refused('section_is_shared', sprintf('The %1$s of this template is shared section %2$d; change it by section_id, or pick another with update-thrive-template-settings.', $slot, $shared), [ 'section_id' => $shared ]);
            }
            $current = (string) ($sections[ $slot ]['content'] ?? '');
            $plan    = [ 'section_id' => 0, 'template_id' => (int) $tpl->ID, 'slot' => $slot, 'post' => [] ];
        }

        if (array_key_exists('content', $args)) {
            if (! isset($args['expected_hash'])) {
                throw new Operation_Refused('missing_expected_hash', 'A markup write needs expected_hash, the hash get-thrive-template or list-thrive-sections returned for it.');
            }
            if (! hash_equals(hash('sha256', $current), (string) $args['expected_hash'])) {
                throw new Operation_Refused('content_changed', 'The section changed since it was read; read it again and retry.', [ 'hash' => hash('sha256', $current) ]);
            }
            if (! current_user_can('unfiltered_html')) {
                throw new Operation_Refused('no_unfiltered_html', sprintf('%s section markup is saved as is, which needs the unfiltered_html capability.', self::PRODUCT));
            }
            $plan['content'] = (string) $args['content'];
        }
        return $plan;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed> setting => clean value
     * @throws Operation_Refused
     */
    private static function plan_settings(array $settings): array
    {
        $clean = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (! isset(self::SETTINGS[ $key ])) {
                throw new Operation_Refused('setting_not_writable', sprintf('%1$s setting "%2$s" is not writable here. It takes %3$s.', self::PRODUCT, $key, implode(', ', array_keys(self::SETTINGS))), [ 'key' => $key, 'writable' => array_keys(self::SETTINGS) ]);
            }
            if ('logo_url' === $key) {
                $url = trim((string) $value);
                if ('' !== $url && (esc_url_raw($url, [ 'http', 'https' ]) !== $url || ! wp_http_validate_url($url))) {
                    throw new Operation_Refused('invalid_setting_value', 'logo_url must be an http or https URL, or empty for the home page.', [ 'key' => $key ]);
                }
                $clean[ $key ] = $url;
                continue;
            }
            $id = (int) $value;
            if (0 !== $id && ! wp_attachment_is_image($id)) {
                throw new Operation_Refused('invalid_setting_value', sprintf('%1$s must be an image attachment id, or 0 for %2$s\'s placeholder.', $key, self::PRODUCT), [ 'key' => $key ]);
            }
            $clean[ $key ] = $id;
        }
        return $clean;
    }

    // Writes.

    /** @return array<string,mixed> */
    private static function update_template_settings(array $args): array
    {
        $plan = self::plan_template_settings($args);
        $id   = $plan['id'];
        if ([] !== $plan['post']) {
            $done = Save_Filters::update_post(wp_slash([ 'ID' => $id ] + $plan['post']), true);
            if (is_wp_error($done)) {
                throw new Operation_Refused('template_not_updated', $done->get_error_message());
            }
        }
        if (null !== $plan['layout']) {
            update_post_meta($id, 'layout', $plan['layout']);
        }
        if ([] !== $plan['sections']) {
            $sections = self::sections_of($id);
            foreach ($plan['sections'] as $slot => $section_id) {
                $entry               = is_array($sections[ $slot ] ?? null) ? $sections[ $slot ] : [ 'content' => '' ];
                $entry['id']         = $section_id;
                $sections[ $slot ]   = $entry;
            }
            update_post_meta($id, 'sections', wp_slash($sections));
        }
        return [ 'template' => self::template_view(get_post($id), false) ];
    }

    /** @return array<string,mixed> */
    private static function assign(array $args): array
    {
        $plan = self::plan_assign($args);
        if (0 === $plan['template_id']) {
            delete_post_meta($plan['post_id'], self::POST_TEMPLATE_META);
        } else {
            update_post_meta($plan['post_id'], self::POST_TEMPLATE_META, $plan['template_id']);
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private static function update_section(array $args): array
    {
        $plan = self::plan_section($args);
        if (0 !== $plan['section_id']) {
            $id = $plan['section_id'];
            if ([] !== $plan['post']) {
                $done = Save_Filters::update_post(wp_slash([ 'ID' => $id ] + $plan['post']), true);
                if (is_wp_error($done)) {
                    throw new Operation_Refused('section_not_updated', $done->get_error_message());
                }
            }
            if (isset($plan['content'])) {
                if (self::SYMBOL === get_post_type($id)) {
                    Thrive_Content::save($id, $plan['content'], null);
                } else {
                    update_post_meta($id, 'content', wp_slash($plan['content']));
                }
            }
            $type = self::SYMBOL === get_post_type($id) ? (has_term('headers', self::SYMBOL_TAX, $id) ? 'header' : 'footer') : (string) get_post_meta($id, 'type', true);
            return [ 'section' => self::section_view($id, $type, self::section_users(), false) ];
        }

        $id       = $plan['template_id'];
        $sections = self::sections_of($id);
        $entry    = is_array($sections[ $plan['slot'] ] ?? null) ? $sections[ $plan['slot'] ] : [ 'id' => 0 ];
        $entry['content']          = $plan['content'];
        $sections[ $plan['slot'] ] = $entry;
        update_post_meta($id, 'sections', wp_slash($sections));
        return [ 'template_id' => $id, 'type' => $plan['slot'], 'hash' => hash('sha256', $plan['content']) ];
    }

    /** @param array<string,mixed> $settings */
    private static function update_settings(array $settings): array
    {
        $clean = self::plan_settings($settings);
        if (isset($clean['logo_url'])) {
            if ('' === $clean['logo_url']) {
                delete_option('thrive-theme-logo-url');
            } else {
                update_option('thrive-theme-logo-url', $clean['logo_url']);
            }
        }
        if (isset($clean['favicon'])) {
            update_option('thrive-theme-favicon', $clean['favicon']);
        }
        if (isset($clean['default_featured_image'])) {
            update_option('thrive-theme-default-featured-image', $clean['default_featured_image']);
        }
        return self::settings_view();
    }

    private static function refusal(callable $plan): ?array
    {
        try {
            $plan();
        } catch (Operation_Refused $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
        return null;
    }
}
