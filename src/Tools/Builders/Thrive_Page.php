<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of Thrive Architect editing (issue #640) that sit beside
 * the element operations of Thrive_Html. Recorded from the Thrive Architect
 * 11.3 source:
 *
 * - Revisions are ordinary WordPress revisions. While Thrive is loaded its
 *   save_post hook (tve_save_post_callback()) copies each layout row of the
 *   page onto the revision as `tve_revision_<key>`, for the keys
 *   tve_get_used_meta_keys() lists; tve_restore_post_to_revision() writes
 *   them back. A revision without `tve_revision_tve_updated_post` holds no
 *   Thrive layout.
 * - User templates ("Save as template" in the editor) are posts of the
 *   `tve_user_template` type with `template_name`, `template_content`,
 *   `template_type`, `template_css` and `template_media_css` (the element
 *   rules, see Thrive_Css) meta and a `tve_user_template_category` term.
 *   Older ones are entries of the `tve_user_templates` option, addressed as
 *   `o_<index>`. The editor loads either through Template::load(), which
 *   strips slashes from the stored strings, and inserts the markup with
 *   every data-css id renewed and the rules added to the page.
 */
final class Thrive_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    public const TEMPLATE_POST_TYPE = 'tve_user_template';

    public const LEGACY_TEMPLATES_OPTION = 'tve_user_templates';

    /** The rows Thrive copies onto a revision and restores from it, less tve_landing_page. */
    public const REVISION_KEYS = [
        'tve_disable_theme_dependency', 'tve_content_before_more', 'tve_content_more_found', 'tve_save_post',
        'tve_custom_css', 'tve_user_custom_css', 'tve_page_events', 'tve_globals', 'tve_global_scripts',
        'thrive_icon_pack', 'thrive_tcb_post_fonts', 'tve_has_masonry', 'tve_has_typefocus', 'tve_updated_post',
        'tve_has_wistia_popover', 'ttb_inherit_typography', 'thrv_lp_template_palettes_v2', 'thrv_lp_template_palettes_config_v2',
    ];

    /** The template_type Thrive records for each tree type it saves. */
    private const TEMPLATE_TYPES = [
        'section' => 'section', 'content-box' => 'contentbox', 'columns' => 'columns', 'text' => 'text',
        'image' => 'image', 'button' => 'button', 'icon' => 'icon', 'divider' => 'divider',
    ];

    /** The loaded Thrive Architect's version, or null when it is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! Thrive_Content::plugin_active() || ! defined('TVE_VERSION')) {
            return null;
        }
        $version = (string) constant('TVE_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs Thrive Architect loaded. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "Thrive Architect is not active on this site, so this cannot run. Install and activate Thrive Architect ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Thrive Architect', 'url' => $url]
        );
    }

    /** sha256 of the stored layout, the hash writes are pinned to. */
    public static function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            $id = (int) $revision->ID;
            if (wp_is_post_autosave($revision) || ! metadata_exists('post', $id, 'tve_revision_tve_updated_post')) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => self::hash((string) get_post_meta($id, 'tve_revision_tve_updated_post', true)),
            ];
        }

        return $out;
    }

    /**
     * The layout rows one of the page's revisions holds, keyed by base key,
     * as tve_restore_post_to_revision() puts them back.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when $revision is not a Thrive revision of $post_id
     */
    public static function revision_rows(int $post_id, string $revision): array
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        if (! metadata_exists('post', $id, 'tve_revision_tve_updated_post')) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no Thrive Architect layout."));
        }
        if ((string) get_post_meta($id, 'tve_revision_tve_landing_page', true) !== Thrive_Content::landing_page($post_id)) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} uses another landing page template than the page does now; restore it in Thrive Architect."));
        }

        $rows = [];
        foreach (self::REVISION_KEYS as $key) {
            if (metadata_exists('post', $id, 'tve_revision_' . $key)) {
                $rows[ $key ] = get_post_meta($id, 'tve_revision_' . $key, true);
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> the templates scope read */
    public static function templates(): array
    {
        $templates = [];
        foreach (Builder_Design_Data::posts([self::TEMPLATE_POST_TYPE]) as $post) {
            $data        = self::post_template((int) $post->ID);
            $templates[] = self::describe((string) $post->ID, $data) + ['status' => $post->post_status];
        }
        $legacy = get_option(self::LEGACY_TEMPLATES_OPTION, []);
        foreach (is_array($legacy) ? $legacy : [] as $index => $entry) {
            if (is_array($entry)) {
                $templates[] = self::describe('o_' . $index, $entry) + ['legacy' => true];
            }
        }

        return [
            'builder'        => 'thrive',
            'scope'          => 'templates',
            'plugin_active'  => Thrive_Content::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'templates'      => $templates,
        ];
    }

    /**
     * A user template as Template::load() hands it to the editor: markup,
     * legacy colour rules and element rules, slashes stripped.
     *
     * @return array{content:string,css:string,media_css:array<int|string,mixed>}|null
     */
    public static function template(string $id): ?array
    {
        if (ctype_digit($id)) {
            if (self::TEMPLATE_POST_TYPE !== get_post_type((int) $id)) {
                return null;
            }
            $data = self::post_template((int) $id);
        } elseif (preg_match('/^o_(\d+)$/', $id, $m)) {
            $legacy = get_option(self::LEGACY_TEMPLATES_OPTION, []);
            $data   = is_array($legacy) && is_array($legacy[ (int) $m[1] ] ?? null) ? $legacy[ (int) $m[1] ] : null;
            if (null === $data) {
                return null;
            }
        } else {
            return null;
        }

        $media = $data['media_css'] ?? [];

        return [
            'content'   => stripslashes(is_string($data['content'] ?? null) ? $data['content'] : ''),
            'css'       => stripslashes(is_string($data['css'] ?? null) ? $data['css'] : ''),
            'media_css' => is_array($media) ? array_map(static fn ($v) => is_string($v) ? stripslashes($v) : $v, $media) : [],
        ];
    }

    /**
     * Insert a user template under a container ('' is the top level) at a
     * child index, the way the editor does it: data-css ids renewed and the
     * template's rules added to the page stylesheet. The markup goes in
     * byte for byte (in place of a placeholder element, so a template
     * whose outer wrapper is not itself an element still fits).
     *
     * @return array{0:string,1:string,2:string} layout, stylesheet, path
     */
    public static function insert_template(string $content, string $css, string $to, ?int $index, string $id): array
    {
        $template = self::template($id);
        if (null === $template || '' === trim($template['content'])) {
            throw new \InvalidArgumentException(esc_html("No Thrive Architect template '{$id}'; read get-builder-content scope templates for the ids."));
        }
        Thrive_Html::assert_balanced($template['content']);

        [$markup, $css] = Thrive_Css::insert($content, $css, trim($template['content']), $template['css'], $template['media_css']);

        do {
            $mark = 'wpmcp-mark-' . bin2hex(random_bytes(6));
        } while (false !== strpos($content . $markup, $mark));
        [$placed, $path] = Thrive_Html::add($content, $to, $index, ['attrs' => ['class' => 'thrv_wrapper ' . $mark], 'text' => '']);

        $placeholder = '<div class="thrv_wrapper ' . $mark . '"></div>';
        $at          = strpos($placed, $placeholder);

        return [substr_replace($placed, $markup, (int) $at, strlen($placeholder)), $css, $path];
    }

    /**
     * Save the element at `path` as a new user template named by element
     * {title}, through Thrive's own Template::insert() (so it needs Thrive
     * loaded), with the page rules that style it, as the editor's "Save as
     * template" does. Writes no page; the new post is recorded for undo.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_template(int $post_id, array $args)
    {
        if (! Thrive_Content::plugin_active() || ! class_exists('TCB\UserTemplates\Template')) {
            return self::unavailable();
        }
        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_thrive_request', 'save_template needs element {title}: the name to save the template under.');
        }
        if (! current_user_can('edit_posts')) {
            return new \WP_Error('forbidden', 'Saving a Thrive Architect template needs the capability to create posts.');
        }

        $content = Thrive_Content::get_content($post_id);
        $path    = (string) ($args['path'] ?? '');
        try {
            if ('' === $path) {
                throw new \InvalidArgumentException(esc_html('save_template needs path: the element to save.'));
            }
            $markup = Thrive_Html::markup($content, $path);
            $node   = self::node(Thrive_Html::tree($content), $path);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_thrive_request', $e->getMessage());
        }

        $id = \TCB\UserTemplates\Template::insert([
            'name'        => sanitize_text_field($title),
            'content'     => $markup,
            'type'        => self::TEMPLATE_TYPES[ $node['type'] ?? '' ] ?? '',
            'id_category' => '',
            'css'         => '',
            'media_css'   => Thrive_Css::rules_for(Thrive_Content::get_css($post_id), Thrive_Css::ids($markup)),
            'thumb'       => '',
        ]);
        if (is_wp_error($id) || ! $id) {
            return is_wp_error($id) ? $id : new \WP_Error('invalid_thrive_request', 'Thrive Architect did not save the template.');
        }
        $id = (int) $id;

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return ['operation_id' => $operation_id, 'post_id' => $post_id, 'builder' => 'thrive', 'template_id' => $id];
    }

    /** @return array<string,mixed> */
    private static function post_template(int $id): array
    {
        $data = [];
        foreach (['name', 'content', 'type', 'css', 'media_css'] as $key) {
            $data[ $key ] = get_post_meta($id, 'template_' . $key, true);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private static function describe(string $id, array $data): array
    {
        $content = stripslashes(is_string($data['content'] ?? null) ? $data['content'] : '');
        $item    = [
            'id'   => $id,
            'name' => rawurldecode(is_string($data['name'] ?? null) ? $data['name'] : ''),
            'type' => is_string($data['type'] ?? null) ? $data['type'] : '',
        ];
        try {
            $item['tree'] = Thrive_Html::tree($content);
        } catch (\InvalidArgumentException $e) {
            $item['tree']       = [];
            $item['tree_error'] = $e->getMessage();
        }

        return $item;
    }

    /** @return array<string,mixed> */
    private static function node(array $tree, string $path): array
    {
        $node = ['children' => $tree];
        foreach (explode('.', $path) as $step) {
            $node = $node['children'][ (int) $step ] ?? [];
        }

        return $node;
    }
}
