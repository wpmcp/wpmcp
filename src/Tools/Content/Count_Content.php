<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Counts across the site: posts by type and status, media by MIME group,
 * comments by status, terms by taxonomy, and users by role.
 *
 * This exists so an agent can size a job before starting it. "Rewrite every
 * product description" is a different plan at 12 products than at 12,000,
 * and without a count the only way to find out is to page through
 * list-posts, which costs far more context than the answer is worth.
 *
 * Every count comes from a core counting API (wp_count_posts,
 * wp_count_comments, wp_count_terms, count_users) rather than from a
 * COUNT(*) of our own, so the numbers agree exactly with what wp-admin
 * shows and stay correct on installs that filter what a role may see.
 */
class Count_Content
{
    public function handle(array $args): array
    {
        $sections = (array) ($args['include'] ?? ['posts', 'media', 'comments', 'terms', 'users']);
        $out      = [];

        if (in_array('posts', $sections, true)) {
            $out['posts'] = $this->posts($args);
        }
        if (in_array('media', $sections, true)) {
            $out['media'] = $this->media();
        }
        if (in_array('comments', $sections, true)) {
            $out['comments'] = $this->comments();
        }
        if (in_array('terms', $sections, true)) {
            $out['terms'] = $this->terms();
        }
        if (in_array('users', $sections, true)) {
            $out['users'] = $this->users();
        }

        return $out;
    }

    /** Post counts per public post type, keyed by status. */
    private function posts(array $args): array
    {
        $requested = (string) ($args['post_type'] ?? '');
        $types     = '' !== $requested
            ? [sanitize_key($requested)]
            : array_values(get_post_types(['public' => true], 'names'));

        $out = [];
        foreach ($types as $type) {
            if (! post_type_exists($type) || 'attachment' === $type) {
                continue;
            }
            $counts = (array) wp_count_posts($type);
            $byStatus = [];
            $total    = 0;
            foreach ($counts as $status => $n) {
                $n = (int) $n;
                if (0 === $n) {
                    continue;
                }
                $byStatus[ $status ] = $n;
                // auto-draft rows are editor scratch space, never content a
                // user would recognise, so they are excluded from the total
                // while still being visible in the breakdown.
                if ('auto-draft' !== $status) {
                    $total += $n;
                }
            }
            $out[ $type ] = ['total' => $total, 'by_status' => $byStatus];
        }

        return $out;
    }

    /** Attachment counts grouped by MIME family. */
    private function media(): array
    {
        $counts = (array) wp_count_attachments();
        $groups = ['image' => 0, 'video' => 0, 'audio' => 0, 'application' => 0, 'text' => 0, 'other' => 0];
        $total  = 0;

        foreach ($counts as $mime => $n) {
            if ('trash' === $mime) {
                continue;
            }
            $n      = (int) $n;
            $total += $n;
            $family = strtok((string) $mime, '/');
            if (isset($groups[ $family ])) {
                $groups[ $family ] += $n;
            } else {
                $groups['other'] += $n;
            }
        }

        return ['total' => $total, 'by_type' => $groups];
    }

    private function comments(): array
    {
        $counts = wp_count_comments();

        return [
            'total'     => (int) ($counts->total_comments ?? 0),
            'approved'  => (int) ($counts->approved ?? 0),
            'pending'   => (int) ($counts->moderated ?? 0),
            'spam'      => (int) ($counts->spam ?? 0),
            'trash'     => (int) ($counts->trash ?? 0),
        ];
    }

    private function terms(): array
    {
        $out = [];
        foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
            $n = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            $out[ $taxonomy ] = is_wp_error($n) ? 0 : (int) $n;
        }

        return $out;
    }

    private function users(): array
    {
        $counts = count_users();

        return [
            'total'   => (int) ($counts['total_users'] ?? 0),
            'by_role' => array_map('intval', (array) ($counts['avail_roles'] ?? [])),
        ];
    }
}
