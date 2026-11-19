<?php

/**
 * Scenario 2: "Set the meta description on every published page that has
 * none" (SEO, 40 pages).
 *
 * Fixtures: 40 published pages, 15 of them (every other one up to page 30) with a description already, and
 * 5 drafts without one (which must be left alone). Needs an SEO plugin the
 * SEO tools write through (the test install has Yoast).
 *
 * Ideal sequence on full: list the published pages (one call, per_page
 * 100), read each page's SEO meta (no listing carries it, so one call per
 * page, all in one turn), then set the description on each page that has
 * none (one turn).
 */

use WPMCP\Bench\Surface;

return [
    'id'       => 2,
    'slug'     => 'seo-meta',
    'title'    => 'Set the meta description on every published page that has none (40 pages)',
    'requires' => [
        'an SEO plugin (Yoast SEO, Rank Math or similar)' => static fn(): bool => function_exists('wp_has_ability') && wp_has_ability('wpmcp/update-seo-meta'),
    ],

    'seed' => static function (): array {
        $with    = [];
        $without = [];
        for ($i = 1; $i <= 40; $i++) {
            $id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => sprintf('Bench Service Page %02d', $i),
                'post_content' => sprintf('<!-- wp:paragraph --><p>Bench page %d about a made-up service.</p><!-- /wp:paragraph -->', $i),
            ]);
            if (0 === $i % 2 && count($with) < 15) {
                \WPMCP\Tools\SEO\SEO_Adapter::update_meta((int) $id, ['description' => sprintf('Existing description %d.', $i)]);
                $with[] = (int) $id;
            } else {
                $without[] = (int) $id;
            }
        }
        $drafts = [];
        for ($i = 1; $i <= 5; $i++) {
            $drafts[] = (int) wp_insert_post([
                'post_type'   => 'page',
                'post_status' => 'draft',
                'post_title'  => sprintf('Bench Draft %d', $i),
            ]);
        }

        return ['with' => $with, 'without' => $without, 'drafts' => $drafts];
    },

    'surfaces' => static function (array $f): array {
        $full = [
            ['tool' => 'wpmcp/list-posts', 'args' => ['post_type' => 'page', 'status' => 'publish', 'per_page' => 100], 'as' => 'pages'],
            [
                'tool' => 'wpmcp/get-seo-meta',
                'each' => static fn(array $r): array => array_map(
                    static fn(array $p): array => ['post_id' => (int) $p['post_id']],
                    (array) ($r['pages']['posts'] ?? [])
                ),
                'as'   => 'meta',
            ],
            [
                'tool' => 'wpmcp/update-seo-meta',
                'each' => static function (array $r): array {
                    $calls = [];
                    foreach ((array) $r['meta'] as $meta) {
                        if ('' === trim((string) ($meta['description'] ?? ''))) {
                            $calls[] = ['post_id' => (int) $meta['post_id'], 'description' => sprintf('Bench description for page %d.', (int) $meta['post_id'])];
                        }
                    }
                    return $calls;
                },
                'as'   => 'updated',
            ],
        ];

        return [
            Surface::FULL    => $full,
            Surface::COMPACT => Surface::compact(['seo', 'list-posts'], $full),
        ];
    },

    'verify' => static function (array $f, array $state): ?string {
        if (40 !== count((array) ($state['pages']['posts'] ?? []))) {
            return 'list-posts did not return the 40 published pages';
        }
        foreach ($f['without'] as $id) {
            if (! str_starts_with((string) (\WPMCP\Tools\SEO\SEO_Adapter::get_meta($id)['description'] ?? ''), 'Bench description')) {
                return sprintf('page %d still has no description', $id);
            }
        }
        foreach ($f['with'] as $id) {
            if (! str_starts_with((string) (\WPMCP\Tools\SEO\SEO_Adapter::get_meta($id)['description'] ?? ''), 'Existing description')) {
                return sprintf('page %d lost its existing description', $id);
            }
        }
        foreach ($f['drafts'] as $id) {
            if ('' !== (string) (\WPMCP\Tools\SEO\SEO_Adapter::get_meta($id)['description'] ?? '')) {
                return sprintf('draft %d was changed', $id);
            }
        }
        return 25 === count((array) $state['updated']) ? null : sprintf('expected 25 updates, made %d', count((array) $state['updated']));
    },
];
