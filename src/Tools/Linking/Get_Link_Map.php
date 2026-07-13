<?php

namespace WPMCP\Tools\Linking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: summarize the internal-link graph.
 *
 * Reports, per scanned post, its outgoing and incoming internal-link counts,
 * the list of orphans (zero incoming), and the most-linked posts (highest
 * incoming). The per-post list is capped so the summary stays bounded on a
 * large site. Reads have nothing to roll back, so this never touches
 * Safe_Mutation.
 */
class Get_Link_Map
{
    private const DEFAULT_SCAN = 200;
    private const DEFAULT_CAP  = 100;
    private const TOP_LINKED    = 10;

    public function handle(array $args): array
    {
        $post_types = Args::post_types($args['post_type'] ?? 'post');
        $scan       = Args::scan_limit($args['limit'] ?? self::DEFAULT_SCAN, self::DEFAULT_SCAN);
        $cap        = Args::cap($args['cap'] ?? self::DEFAULT_CAP, self::DEFAULT_CAP);

        $graph = Link_Graph::build($post_types, $scan);

        $posts       = [];
        $orphans     = [];
        $most_linked = [];
        foreach ($graph as $id => $node) {
            $row = [
                'id'        => $id,
                'title'     => $node['title'],
                'post_type' => $node['post_type'],
                'outgoing'  => count($node['outgoing']),
                'incoming'  => $node['incoming'],
            ];
            $posts[] = $row;

            if (0 === $node['incoming']) {
                $orphans[] = ['id' => $id, 'title' => $node['title'], 'post_type' => $node['post_type']];
            }
            if ($node['incoming'] > 0) {
                $most_linked[] = ['id' => $id, 'title' => $node['title'], 'incoming' => $node['incoming']];
            }
        }

        usort($most_linked, static fn ($a, $b) => $b['incoming'] <=> $a['incoming']);

        return [
            'total'        => count($posts),
            'orphan_total' => count($orphans),
            'posts'        => array_slice($posts, 0, $cap),
            'orphans'      => array_slice($orphans, 0, $cap),
            'most_linked'  => array_slice($most_linked, 0, self::TOP_LINKED),
        ];
    }
}
