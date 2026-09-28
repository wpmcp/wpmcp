<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Proposal (read) tool: the title, description, canonical, robots,
 * OpenGraph and Twitter card tags a post should carry, derived from the
 * active SEO plugin's stored fields where they are usable and from the
 * post's own record where they are not (issue #67). Writes nothing, so it
 * never touches Safe_Mutation.
 *
 * Every tag says where its value came from (`plugin` for a stored SEO or
 * social field, `post` for a value derived from the post record) so an agent
 * can see which fields are still unset before calling update-seo-meta or
 * set-social-image. Stored values that are still unrendered template strings
 * ('%%title%% %sep% %sitename%') count as unset: the literal template is
 * worse than the fallback.
 *
 * `plugin_emits_tags` is true whenever a supported SEO plugin is active,
 * because every one of them prints these tags itself: pasting `html` into a
 * theme on such a site double-emits them. The right move there is to write
 * the values through the plugin (update-seo-meta, set-social-image), and the
 * proposal is the preview of what that would render.
 *
 * Paid tier per the issue; enforced centrally by the Registrar.
 */
class Generate_Meta_Tags
{
    /** Length a derived description is trimmed to, in characters. */
    private const DESCRIPTION_LENGTH = 160;

    /** Tags whose content is a URL. */
    private const URL_KEYS = ['og:url', 'og:image', 'twitter:image'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        // Same per-post gate as the rest of the group: the proposal returns
        // the post's title, excerpt and content-derived copy.
        $post = Post_Access::assert_readable($post_id);

        $active = SEO_Adapter::active_plugin();
        $seo    = '' !== $active ? SEO_Adapter::get_meta($post_id) : [];
        $social = Social_Meta::get($post_id);
        $social = ! empty($social['supported']) ? (array) $social['fields'] : [];
        foreach (['og_image', 'twitter_image'] as $image_field) {
            if (isset($social[$image_field])) {
                $social[$image_field] = self::http_url((string) $social[$image_field]);
            }
        }

        [$title, $title_src]             = self::pick((string) ($seo['title'] ?? ''), (string) $post->post_title);
        [$description, $description_src] = self::pick((string) ($seo['description'] ?? ''), self::derived_description($post));

        $canonical     = self::http_url((string) ($seo['canonical'] ?? ''));
        $canonical_src = 'plugin';
        if ('' === $canonical) {
            $canonical     = (string) get_permalink($post);
            $canonical_src = 'post';
        }

        [$og_title, $og_title_src]             = self::pick((string) ($social['og_title'] ?? ''), $title, $title_src);
        [$og_description, $og_description_src] = self::pick((string) ($social['og_description'] ?? ''), $description, $description_src);

        $featured                  = get_the_post_thumbnail_url($post, 'full');
        [$og_image, $og_image_src] = self::pick((string) ($social['og_image'] ?? ''), is_string($featured) ? $featured : '');

        // The resolved Twitter fields already carry the plugin's own
        // OG-mirroring rules; where nothing is stored, mirror our OG values.
        [$tw_title, $tw_title_src]             = self::pick((string) ($social['twitter_title'] ?? ''), $og_title, $og_title_src);
        [$tw_description, $tw_description_src] = self::pick((string) ($social['twitter_description'] ?? ''), $og_description, $og_description_src);
        [$tw_image, $tw_image_src]             = self::pick((string) ($social['twitter_image'] ?? ''), $og_image, $og_image_src);

        $tags = [];
        self::add($tags, 'title', '', $title, $title_src);
        self::add($tags, 'name', 'description', $description, $description_src);
        self::add($tags, 'link', 'canonical', $canonical, $canonical_src);

        $robots = [];
        if (! empty($seo['noindex'])) {
            $robots[] = 'noindex';
        }
        if (! empty($seo['nofollow'])) {
            $robots[] = 'nofollow';
        }
        if ([] !== $robots) {
            self::add($tags, 'name', 'robots', implode(', ', $robots), 'plugin');
        }

        self::add($tags, 'property', 'og:type', 'post' === $post->post_type ? 'article' : 'website', 'post');
        self::add($tags, 'property', 'og:title', $og_title, $og_title_src);
        self::add($tags, 'property', 'og:description', $og_description, $og_description_src);
        self::add($tags, 'property', 'og:url', $canonical, $canonical_src);
        self::add($tags, 'property', 'og:site_name', (string) get_bloginfo('name'), 'post');
        self::add($tags, 'property', 'og:image', $og_image, $og_image_src);

        self::add($tags, 'name', 'twitter:card', '' !== $tw_image ? 'summary_large_image' : 'summary', 'post');
        self::add($tags, 'name', 'twitter:title', $tw_title, $tw_title_src);
        self::add($tags, 'name', 'twitter:description', $tw_description, $tw_description_src);
        self::add($tags, 'name', 'twitter:image', $tw_image, $tw_image_src);

        return [
            'post_id'           => $post_id,
            'plugin'            => $active,
            'plugin_emits_tags' => '' !== $active,
            'tags'              => $tags,
            'html'              => implode("\n", array_map([self::class, 'render'], $tags)),
        ];
    }

    /**
     * The stored value when usable, else the fallback, with its source.
     *
     * @return array{0: string, 1: string}
     */
    private static function pick(string $stored, string $fallback, string $fallback_source = 'post'): array
    {
        $stored = trim(wp_strip_all_tags($stored));
        if ('' !== $stored && ! Schema_Generator::has_unrendered_variables($stored)) {
            return [$stored, 'plugin'];
        }

        return [trim(wp_strip_all_tags($fallback)), $fallback_source];
    }

    /**
     * The excerpt, else the start of the content with shortcodes and tags
     * removed, trimmed on a word boundary. Raw fields rather than the
     * display filters, for the reason Schema_Generator gives.
     */
    private static function derived_description(\WP_Post $post): string
    {
        $text = (string) $post->post_excerpt;
        if ('' === trim($text)) {
            $text = strip_shortcodes((string) $post->post_content);
        }

        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        if (mb_strlen($text) <= self::DESCRIPTION_LENGTH) {
            return $text;
        }

        $cut   = mb_substr($text, 0, self::DESCRIPTION_LENGTH);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false !== $space ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '...';
    }

    /**
     * A stored URL only if it is absolute http(s); anything else (a
     * javascript: or data: value, a stray relative path) counts as unset so
     * the post-derived fallback is used instead.
     */
    private static function http_url(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        $clean  = esc_url_raw($value, ['http', 'https']);
        $scheme = wp_parse_url($clean, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $clean : '';
    }

    /** Append one tag, skipping empty values. */
    private static function add(array &$tags, string $kind, string $key, string $value, string $source): void
    {
        if ('' === $value) {
            return;
        }

        $tags[] = [
            'kind'    => $kind,
            'key'     => $key,
            'content' => $value,
            'source'  => $source,
        ];
    }

    /** One tag as escaped HTML. */
    private static function render(array $tag): string
    {
        if ('title' === $tag['kind']) {
            return '<title>' . esc_html($tag['content']) . '</title>';
        }
        if ('link' === $tag['kind']) {
            return '<link rel="' . esc_attr($tag['key']) . '" href="' . esc_url($tag['content']) . '" />';
        }

        // URL-valued tags go through esc_url so a stored javascript: or
        // data: value cannot ride into og:image verbatim.
        $content = in_array($tag['key'], self::URL_KEYS, true)
            ? esc_url($tag['content'])
            : esc_attr($tag['content']);
        $attr    = 'property' === $tag['kind'] ? 'property' : 'name';

        return '<meta ' . $attr . '="' . esc_attr($tag['key']) . '" content="' . $content . '" />';
    }
}
