<?php

/**
 * Scenario 3: "Replace the phone number in every Elementor page that
 * contains the old one".
 *
 * Fixtures: 8 Elementor pages. 5 carry the old number, one of them in two
 * widgets; 3 do not. The search index is built, as it is on a site where
 * pages were saved after the plugin was active.
 *
 * find-replace-content refuses _elementor_data (protected meta), so the
 * ideal sequence on full is: search-content for the number (one call), read
 * each page that hit with get-elementor-data (one turn), then one
 * batch-update per page with every element that holds the number (one
 * turn; batch-update needs the expected_hash from the read).
 */

use WPMCP\Bench\Surface;

$wpmcp_bench_old_phone = '(555) 010-4477';
$wpmcp_bench_new_phone = '(555) 010-9900';

/** Every element of a parsed Elementor tree whose string settings hold $needle, with those settings replaced. */
$wpmcp_bench_phone_updates = static function (array $elements, string $needle, string $replace) use (&$wpmcp_bench_phone_updates): array {
    $out = [];
    foreach ($elements as $el) {
        $changed = [];
        foreach ((array) ($el['settings'] ?? []) as $key => $value) {
            if (is_string($value) && str_contains($value, $needle)) {
                $changed[ $key ] = str_replace($needle, $replace, $value);
            }
        }
        if ([] !== $changed) {
            $out[] = ['element_id' => (string) $el['id'], 'settings' => $changed];
        }
        $out = array_merge($out, $wpmcp_bench_phone_updates((array) ($el['elements'] ?? ($el['children'] ?? [])), $needle, $replace));
    }
    return $out;
};

return [
    'id'       => 3,
    'slug'     => 'elementor-phone',
    'title'    => 'Replace the phone number in every Elementor page that contains the old one',
    'requires' => [
        'Elementor' => static fn(): bool => defined('ELEMENTOR_VERSION'),
    ],

    'seed' => static function () use ($wpmcp_bench_old_phone): array {
        $widget = static fn(string $id, string $type, array $settings): array => [
            'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [],
        ];

        $with  = [];
        $other = [];
        for ($i = 1; $i <= 8; $i++) {
            $has      = $i <= 5;
            $children = [
                $widget("h{$i}", 'heading', ['title' => "Bench Location {$i}"]),
                $widget("t{$i}", 'text-editor', ['editor' => $has
                    ? "<p>Call us on {$wpmcp_bench_old_phone} from 9 to 5.</p>"
                    : '<p>Email us any time.</p>']),
            ];
            if (2 === $i) {
                $children[] = $widget("b{$i}", 'button', ['text' => "Call {$wpmcp_bench_old_phone}", 'link' => ['url' => 'tel:5550104477']]);
            }
            $data = [[
                'id'       => "c{$i}",
                'elType'   => 'container',
                'settings' => [],
                'elements' => $children,
            ]];

            $id = (int) wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => "Bench Elementor Location {$i}",
                'post_content' => '',
            ]);
            update_post_meta($id, '_elementor_edit_mode', 'builder');
            update_post_meta($id, '_elementor_template_type', 'wp-page');
            update_post_meta($id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
            update_post_meta($id, '_elementor_data', wp_slash((string) wp_json_encode($data)));

            if ($has) {
                $with[] = $id;
            } else {
                $other[] = $id;
            }
        }

        // The index a live site has: rebuilt here because the fixtures were
        // written straight to postmeta, which the save-time indexer skips.
        \WPMCP\Tools\Search\Search_Index_Store::ensure_installed();
        $reindex = new \WPMCP\Tools\Search\Reindex_Search();
        $offset  = 0;
        do {
            $step   = $reindex->handle(['full' => true, 'offset' => $offset]);
            $offset = (int) ($step['next_offset'] ?? 0);
        } while (empty($step['complete']) && $offset > 0);

        return ['with' => $with, 'other' => $other];
    },

    'surfaces' => static function (array $f) use ($wpmcp_bench_old_phone, $wpmcp_bench_new_phone, $wpmcp_bench_phone_updates): array {
        $full = [
            ['tool' => 'wpmcp/search-content', 'args' => ['query' => $wpmcp_bench_old_phone, 'post_types' => ['page'], 'limit' => 50], 'as' => 'hits'],
            [
                'tool' => 'wpmcp/get-elementor-data',
                'each' => static function (array $r): array {
                    $ids = [];
                    foreach ((array) ($r['hits']['results'] ?? []) as $hit) {
                        $ids[ (int) $hit['post_id'] ] = true;
                    }
                    if ([] === $ids) {
                        throw new \RuntimeException('search-content found no page with the old number');
                    }
                    return array_map(static fn(int $id): array => ['post_id' => $id], array_keys($ids));
                },
                'as'   => 'pages',
            ],
            [
                'tool' => 'wpmcp/batch-update',
                'each' => static function (array $r) use ($wpmcp_bench_old_phone, $wpmcp_bench_new_phone, $wpmcp_bench_phone_updates): array {
                    $calls = [];
                    foreach ((array) $r['pages'] as $page) {
                        $updates = $wpmcp_bench_phone_updates((array) ($page['elements'] ?? []), $wpmcp_bench_old_phone, $wpmcp_bench_new_phone);
                        if ([] !== $updates) {
                            $calls[] = ['post_id' => (int) $page['post_id'], 'expected_hash' => (string) $page['data_hash'], 'updates' => $updates];
                        }
                    }
                    return $calls;
                },
                'as'   => 'updated',
            ],
        ];

        return [
            Surface::FULL    => $full,
            Surface::COMPACT => Surface::compact(['search-content', 'elementor'], $full),
        ];
    },

    'verify' => static function (array $f, array $state) use ($wpmcp_bench_old_phone, $wpmcp_bench_new_phone): ?string {
        foreach ($f['with'] as $id) {
            $data = (string) get_post_meta($id, '_elementor_data', true);
            if (str_contains($data, $wpmcp_bench_old_phone) || ! str_contains($data, $wpmcp_bench_new_phone)) {
                return sprintf('page %d still has the old number', $id);
            }
        }
        foreach ($f['other'] as $id) {
            if (str_contains((string) get_post_meta($id, '_elementor_data', true), $wpmcp_bench_new_phone)) {
                return sprintf('page %d was changed but never had the number', $id);
            }
        }
        return 5 === count((array) $state['updated']) ? null : sprintf('expected 5 page updates, made %d', count((array) $state['updated']));
    },
];
