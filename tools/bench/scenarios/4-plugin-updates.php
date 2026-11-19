<?php

/**
 * Scenario 4: "List plugins with updates available and the pages that use
 * each one's blocks or widgets".
 *
 * Fixtures: an update offer (the update_plugins transient, no network) for
 * WooCommerce and Elementor; 12 pages: 3 with WooCommerce blocks, 4 built
 * with Elementor, 5 plain.
 *
 * Ideal sequence on full: list-plugins (it carries update_available), then
 * in one turn a list-posts search for each block namespace of an updated
 * plugin (wp:woocommerce/) and one page listing for the widget plugin
 * (list-posts reports is_elementor per post). Read-only.
 */

use WPMCP\Bench\Surface;

return [
    'id'       => 4,
    'slug'     => 'plugin-updates',
    'title'    => 'List plugins with updates available and the pages that use their blocks or widgets',
    'requires' => [
        'WooCommerce' => static fn(): bool => class_exists('WooCommerce'),
        'Elementor'   => static fn(): bool => defined('ELEMENTOR_VERSION'),
    ],

    'seed' => static function (): array {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $offer = new \stdClass();
        $offer->last_checked = time();
        $offer->checked      = [];
        $offer->response     = [];
        foreach (['woocommerce/woocommerce.php' => 'woocommerce', 'elementor/elementor.php' => 'elementor'] as $file => $slug) {
            $offer->response[ $file ] = (object) [
                'slug'        => $slug,
                'plugin'      => $file,
                'new_version' => '99.0.0',
                'package'     => '',
                'url'         => 'https://example.test/' . $slug,
            ];
        }
        set_site_transient('update_plugins', $offer);

        $make = static function (string $title, string $content): int {
            return (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
        };

        $woo = [];
        foreach (['Shop', 'Featured', 'Cart Help'] as $i => $name) {
            $woo[] = $make("Bench {$name}", '<!-- wp:woocommerce/product-collection {"queryId":' . ($i + 1) . '} /--><!-- wp:paragraph --><p>Bench.</p><!-- /wp:paragraph -->');
        }
        $elementor = [];
        for ($i = 1; $i <= 4; $i++) {
            $id = $make("Bench Landing {$i}", '');
            update_post_meta($id, '_elementor_edit_mode', 'builder');
            update_post_meta($id, '_elementor_data', wp_slash((string) wp_json_encode([[
                'id' => "w{$i}", 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => "Landing {$i}"], 'elements' => [],
            ]])));
            $elementor[] = $id;
        }
        for ($i = 1; $i <= 5; $i++) {
            $make("Bench Plain {$i}", '<!-- wp:paragraph --><p>Plain page.</p><!-- /wp:paragraph -->');
        }

        return ['woo' => $woo, 'elementor' => $elementor];
    },

    'surfaces' => static function (array $f): array {
        // What the agent knows about each plugin: block namespaces it
        // searches post content for, or that it is a page builder.
        $blocks   = ['woocommerce/woocommerce.php' => 'wp:woocommerce/'];
        $builders = ['elementor/elementor.php' => 'is_elementor'];

        $full = [
            ['tool' => 'wpmcp/list-plugins', 'args' => [], 'as' => 'plugins'],
            [
                'tool' => 'wpmcp/list-posts',
                'each' => static function (array $r) use ($blocks, $builders): array {
                    // Block searches first, then one page listing for the
                    // builders: verify reads the results in that order.
                    $calls   = [];
                    $listing = false;
                    foreach ((array) ($r['plugins']['plugins'] ?? $r['plugins']) as $plugin) {
                        if (empty($plugin['update_available'])) {
                            continue;
                        }
                        $file = (string) ($plugin['file'] ?? $plugin['plugin'] ?? '');
                        if (isset($blocks[ $file ])) {
                            $calls[] = ['post_type' => 'page', 'search' => $blocks[ $file ], 'per_page' => 100];
                        }
                        $listing = $listing || isset($builders[ $file ]);
                    }
                    if ($listing) {
                        $calls[] = ['post_type' => 'page', 'per_page' => 100];
                    }
                    if ([] === $calls) {
                        throw new \RuntimeException('list-plugins showed no plugin with an update');
                    }
                    return $calls;
                },
                'as'   => 'pages',
            ],
        ];

        return [
            Surface::FULL    => $full,
            Surface::COMPACT => Surface::compact(['plugins', 'list-posts'], $full),
        ];
    },

    'verify' => static function (array $f, array $state): ?string {
        [$woo, $all] = array_pad((array) $state['pages'], 2, null);
        $woo_ids     = array_map(static fn($p) => (int) $p['post_id'], (array) ($woo['posts'] ?? []));
        sort($woo_ids);
        $want = $f['woo'];
        sort($want);
        if ($woo_ids !== $want) {
            return sprintf('the block search returned [%s], the WooCommerce pages are [%s]', implode(',', $woo_ids), implode(',', $want));
        }
        $el = [];
        foreach ((array) ($all['posts'] ?? []) as $p) {
            if (! empty($p['is_elementor'])) {
                $el[] = (int) $p['post_id'];
            }
        }
        sort($el);
        $want = $f['elementor'];
        sort($want);
        return $el === $want ? null : sprintf('the listing flagged [%s] as Elementor, the Elementor pages are [%s]', implode(',', $el), implode(',', $want));
    },
];
