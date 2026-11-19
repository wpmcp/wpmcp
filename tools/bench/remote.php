<?php

/**
 * The site-side half of the benchmark's HTTP mode (issue #1130), run with
 * WP-CLI on the site under test:
 *
 *   wp --path=SITE eval-file tools/bench/remote.php seed <scenario>
 *   wp --path=SITE eval-file tools/bench/remote.php verify <scenario> <run.json>
 *   wp --path=SITE eval-file tools/bench/remote.php prune
 *
 * seed runs the scenario's seed() inside that WordPress and prints
 * {"fixtures": ...}, or {"skipped": "needs ..."} when a plugin it needs is
 * not active. Everything seed() creates is tagged (_wpmcp_bench post and
 * user meta), so prune can remove it: tagged posts of every type and status,
 * the orders of tagged customers, posts the run created under the bench tag
 * (scenario 5), the bench tag itself, and the fake update offer of scenario
 * 4. verify reads {"fixtures", "state"} from the file and prints
 * {"problem": null|string}.
 *
 * Meant for a disposable local site: it writes fixtures, and the scenarios
 * write to them through the tools (a refund, SEO meta, Elementor data).
 *
 * REPO-ONLY, like the rest of tools/.
 */

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    exit(1);
}

require_once __DIR__ . '/Surface.php';

const WPMCP_BENCH_TAG_META = '_wpmcp_bench';

$wpmcp_bench_args   = isset($args) && is_array($args) ? array_values($args) : [];
$wpmcp_bench_action = (string) ($wpmcp_bench_args[0] ?? '');

/** @return array<string, mixed> */
function wpmcp_bench_remote_scenario(string $id): array
{
    foreach (glob(__DIR__ . '/scenarios/*.php') ?: [] as $file) {
        if ((int) basename($file) === (int) $id) {
            return require $file;
        }
    }
    WP_CLI::error('no scenario ' . $id);
    return [];
}

function wpmcp_bench_remote_print(array $data): void
{
    echo wp_json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
}

function wpmcp_bench_remote_prune(): array
{
    $removed = ['posts' => 0, 'users' => 0, 'orders' => 0, 'terms' => 0];

    $users = get_users(['meta_key' => WPMCP_BENCH_TAG_META, 'fields' => 'ID', 'number' => -1]);
    if (function_exists('wc_get_orders') && [] !== $users) {
        foreach ((array) wc_get_orders(['customer_id' => array_map('intval', $users), 'limit' => -1, 'status' => 'any']) as $order) {
            $order->delete(true);
            $removed['orders']++;
        }
    }

    $posts = get_posts([
        'post_type'   => 'any',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => WPMCP_BENCH_TAG_META,
    ]);
    $tag   = get_term_by('slug', 'bench-batch', 'post_tag');
    if ($tag instanceof WP_Term) {
        $posts = array_merge($posts, get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'tag_id' => $tag->term_id]));
    }
    foreach (array_unique(array_map('intval', $posts)) as $id) {
        if (wp_delete_post($id, true)) {
            $removed['posts']++;
        }
    }
    if ($tag instanceof WP_Term && true === wp_delete_term($tag->term_id, 'post_tag')) {
        $removed['terms']++;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) {
        if (wp_delete_user((int) $id)) {
            $removed['users']++;
        }
    }

    $offer = get_site_transient('update_plugins');
    if (is_object($offer) && is_array($offer->response ?? null)) {
        foreach ($offer->response as $update) {
            if ('99.0.0' === ($update->new_version ?? null)) {
                delete_site_transient('update_plugins');
                break;
            }
        }
    }

    return $removed;
}

switch ($wpmcp_bench_action) {
    case 'seed':
        $scenario = wpmcp_bench_remote_scenario((string) ($wpmcp_bench_args[1] ?? ''));
        $missing  = [];
        foreach ((array) $scenario['requires'] as $name => $check) {
            if (! $check()) {
                $missing[] = $name;
            }
        }
        if ([] !== $missing) {
            wpmcp_bench_remote_print(['skipped' => 'needs ' . implode(', ', $missing)]);
            break;
        }
        add_action('wp_insert_post', static function ($id): void {
            update_post_meta((int) $id, WPMCP_BENCH_TAG_META, 1);
        });
        add_action('user_register', static function ($id): void {
            update_user_meta((int) $id, WPMCP_BENCH_TAG_META, 1);
        });
        wpmcp_bench_remote_print(['fixtures' => ($scenario['seed'])()]);
        break;

    case 'verify':
        $scenario = wpmcp_bench_remote_scenario((string) ($wpmcp_bench_args[1] ?? ''));
        $run      = json_decode((string) file_get_contents((string) ($wpmcp_bench_args[2] ?? '')), true);
        $problem  = isset($scenario['verify']) && is_array($run)
            ? ($scenario['verify'])((array) $run['fixtures'], (array) $run['state'])
            : 'no run to verify';
        wpmcp_bench_remote_print(['problem' => $problem]);
        break;

    case 'prune':
        wpmcp_bench_remote_print(['removed' => wpmcp_bench_remote_prune()]);
        break;

    default:
        WP_CLI::error('usage: wp eval-file tools/bench/remote.php seed <scenario> | verify <scenario> <run.json> | prune');
}
