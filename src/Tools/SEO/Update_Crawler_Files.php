<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Change the site-level crawler files (issue #384): the extra robots.txt
 * rules, the generated llms.txt, and the core sitemap's exclusion lists.
 *
 * Only the sections passed change, and within llms and sitemap only the
 * keys passed. The exclusion lists are full replacements, so including a
 * post type again means passing the list without it.
 *
 * Conflicts refuse the whole call before anything is written: a physical
 * robots.txt or llms.txt the web server serves first, an SEO plugin that
 * serves its own llms.txt, or one that has replaced the core sitemap. The
 * write itself is one Safe_Mutation on the settings option, restorable only
 * by a manage_options caller, the capability the write takes.
 */
class Update_Crawler_Files
{
    public function handle(array $args): array
    {
        $has_robots  = array_key_exists('robots_rules', $args) && null !== $args['robots_rules'];
        $has_llms    = isset($args['llms']) && is_array($args['llms']);
        $has_sitemap = isset($args['sitemap']) && is_array($args['sitemap']);
        if (! $has_robots && ! $has_llms && ! $has_sitemap) {
            throw new \InvalidArgumentException('Nothing to update: pass robots_rules, llms or sitemap.');
        }

        $current = Crawler_Files::settings();
        $next    = $current;
        $refused = [];
        $notes   = [];

        if ($has_robots) {
            $next['robots_rules'] = Crawler_Files::sanitize_robots_rules((string) $args['robots_rules']);
            if ('' !== $next['robots_rules'] && Crawler_Files::has_physical_file('robots.txt')) {
                $refused[] = 'A physical robots.txt at the site root is served instead of the virtual one; edit or remove that file.';
            }
            if ('' !== $next['robots_rules'] && '' !== SEO_Adapter::active_plugin()) {
                $notes[] = sprintf('The rules are appended after the robots.txt output of %s.', Crawler_Files::plugin_name(SEO_Adapter::active_plugin()));
            }
        }

        if ($has_llms) {
            $next['llms'] = $this->merge_llms($current['llms'], $args['llms']);
            if ($next['llms']['enabled']) {
                if (Crawler_Files::has_physical_file('llms.txt')) {
                    $refused[] = 'A physical llms.txt at the site root is served by the web server; edit or remove that file.';
                } elseif ('' !== Crawler_Files::llms_owner()) {
                    $refused[] = sprintf('%s serves llms.txt on this site; manage it there.', Crawler_Files::plugin_name(Crawler_Files::llms_owner()));
                } elseif ([] === $next['llms']['pages']) {
                    $notes[] = 'llms.txt is enabled but lists no pages, so nothing is served yet.';
                }
            }
        }

        if ($has_sitemap) {
            $next['sitemap'] = $this->merge_sitemap($current['sitemap'], $args['sitemap']);
            $owner           = Crawler_Files::sitemap_owner();
            if ('core' !== $owner && 'disabled' !== $owner) {
                $refused[] = sprintf('%s replaces the core sitemap; change sitemap inclusion in its settings.', Crawler_Files::plugin_name($owner));
            } elseif ('disabled' === $owner) {
                $notes[] = 'Core sitemaps are off on this site (not public, or switched off), so the exclusions apply once they are on.';
            }
        }

        if ([] !== $refused) {
            throw new \InvalidArgumentException(esc_html('Nothing was changed. ' . implode(' ', $refused)));
        }

        if ($next === $current) {
            return [ 'operation_id' => null, 'changed' => false, 'settings' => $current, 'notes' => $notes ];
        }

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'option',
                'object_id'           => Crawler_Files::OPTION,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'update-crawler-files',
                'args'                => $args,
                'extra_snapshot_data' => [ 'restore_capability' => 'manage_options' ],
            ],
            static function () use ($next): bool {
                return update_option(Crawler_Files::OPTION, $next, false);
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'changed'      => true,
            'settings'     => Crawler_Files::settings(),
            'notes'        => $notes,
        ];
    }

    private function merge_llms(array $current, array $input): array
    {
        $llms = $current;
        if (array_key_exists('enabled', $input)) {
            $llms['enabled'] = (bool) $input['enabled'];
        }
        if (array_key_exists('title', $input)) {
            $llms['title'] = sanitize_text_field((string) $input['title']);
        }
        if (array_key_exists('summary', $input)) {
            $llms['summary'] = sanitize_text_field((string) $input['summary']);
        }
        if (array_key_exists('pages', $input)) {
            $llms['pages'] = Crawler_Files::sanitize_llms_pages($input['pages']);
        }
        return $llms;
    }

    private function merge_sitemap(array $current, array $input): array
    {
        $sitemap = $current;
        if (array_key_exists('exclude_post_types', $input)) {
            $known = array_values(array_diff(get_post_types([ 'public' => true ]), [ 'attachment' ]));
            $sitemap['exclude_post_types'] = Crawler_Files::sanitize_names($input['exclude_post_types'], $known, 'exclude_post_types');
        }
        if (array_key_exists('exclude_taxonomies', $input)) {
            $known = array_values(get_taxonomies([ 'public' => true ]));
            $sitemap['exclude_taxonomies'] = Crawler_Files::sanitize_names($input['exclude_taxonomies'], $known, 'exclude_taxonomies');
        }
        if (array_key_exists('exclude_post_ids', $input)) {
            $sitemap['exclude_post_ids'] = Crawler_Files::sanitize_post_ids($input['exclude_post_ids']);
        }
        return $sitemap;
    }
}
