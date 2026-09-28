<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only view of the site-level crawler files (issue #384): what
 * /robots.txt actually returns and whether a physical file shadows the
 * virtual one, the managed llms.txt and who serves it, and which sitemap
 * the site exposes (core or the active SEO plugin's) with the post types
 * and taxonomies core includes after the managed exclusions.
 */
class Get_Crawler_Files
{
    public function handle(array $args): array
    {
        $settings = Crawler_Files::settings();
        $notes    = [];

        $robots_physical = Crawler_Files::has_physical_file('robots.txt');
        if ($robots_physical && '' !== $settings['robots_rules']) {
            $notes[] = 'A physical robots.txt is served instead of the virtual one, so the managed robots rules are not visible.';
        }

        $llms_physical = Crawler_Files::has_physical_file('llms.txt');
        $llms_owner    = Crawler_Files::llms_owner();
        if ($llms_physical) {
            $notes[] = 'A physical llms.txt is served by the web server; the managed llms.txt is not.';
        } elseif ('' !== $llms_owner) {
            $notes[] = sprintf('%s serves llms.txt; the managed llms.txt is not served.', Crawler_Files::plugin_name($llms_owner));
        }

        $owner = Crawler_Files::sitemap_owner();
        if ('core' !== $owner && 'disabled' !== $owner) {
            $notes[] = sprintf('%s replaces the core sitemap; manage sitemap inclusion in its settings.', Crawler_Files::plugin_name($owner));
        }

        $llms_body = $settings['llms']['enabled'] ? Crawler_Files::render_llms($settings['llms']) : null;

        return [
            'seo_plugin' => SEO_Adapter::active_plugin(),
            'robots'     => [
                'url'           => home_url('/robots.txt'),
                'physical_file' => $robots_physical,
                'public'        => (bool) get_option('blog_public'),
                'managed_rules' => $settings['robots_rules'],
                'effective'     => Crawler_Files::effective_robots(),
            ],
            'llms'       => [
                'url'           => home_url('/llms.txt'),
                'physical_file' => $llms_physical,
                'owner'         => $llms_physical ? 'file' : ('' !== $llms_owner ? $llms_owner : ($settings['llms']['enabled'] ? 'wpmcp' : '')),
                'enabled'       => (bool) $settings['llms']['enabled'],
                'title'         => (string) $settings['llms']['title'],
                'summary'       => (string) $settings['llms']['summary'],
                'pages'         => array_values((array) $settings['llms']['pages']),
                'content'       => $llms_physical ? Crawler_Files::read_physical_file('llms.txt') : (string) $llms_body,
            ],
            'sitemap'    => [
                'owner'              => $owner,
                'url'                => Crawler_Files::sitemap_url($owner),
                'post_types'         => array_keys((new \WP_Sitemaps_Posts())->get_object_subtypes()),
                'taxonomies'         => array_keys((new \WP_Sitemaps_Taxonomies())->get_object_subtypes()),
                'exclude_post_types' => $settings['sitemap']['exclude_post_types'],
                'exclude_taxonomies' => $settings['sitemap']['exclude_taxonomies'],
                'exclude_post_ids'   => $settings['sitemap']['exclude_post_ids'],
            ],
            'notes'      => $notes,
        ];
    }
}
