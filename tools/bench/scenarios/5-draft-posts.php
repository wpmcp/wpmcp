<?php

/**
 * Scenario 5: "Create a draft post per row of a 10-row list and tag them".
 *
 * Fixtures: the 10 rows are in the prompt, not on the site; the seed only
 * returns them. create-post takes terms by name, so the tag needs no call
 * of its own.
 *
 * Ideal sequence on full: one create-post per row, all in one turn.
 */

use WPMCP\Bench\Surface;

return [
    'id'       => 5,
    'slug'     => 'draft-posts',
    'title'    => 'Create a draft post per row of a 10-row list and tag them',
    'requires' => [],

    'seed' => static function (): array {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'title' => sprintf('Bench Draft Idea %02d', $i),
                'body'  => sprintf('<!-- wp:paragraph --><p>Outline for idea %d: a made-up topic.</p><!-- /wp:paragraph -->', $i),
            ];
        }
        return ['rows' => $rows, 'tag' => 'bench-batch'];
    },

    'surfaces' => static function (array $f): array {
        $full = [
            [
                'tool' => 'wpmcp/create-post',
                'each' => static fn(): array => array_map(static fn(array $row): array => [
                    'post_type' => 'post',
                    'status'    => 'draft',
                    'title'     => $row['title'],
                    'content'   => $row['body'],
                    'terms'     => ['post_tag' => [$f['tag']]],
                ], $f['rows']),
                'as'   => 'created',
            ],
        ];

        return [
            Surface::FULL    => $full,
            Surface::COMPACT => Surface::compact(['create-post'], $full),
        ];
    },

    'verify' => static function (array $f, array $state): ?string {
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'tag' => $f['tag'], 'numberposts' => 50]);
        return 10 === count($posts) ? null : sprintf('expected 10 tagged drafts, found %d', count($posts));
    },
];
