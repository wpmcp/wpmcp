<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

if (! defined('ABSPATH')) {
    exit;
}

class Delete_Post
{
    /**
     * The permanent force-delete path is destructive and disabled by default,
     * matching every newer delete tool (Delete_Media, Delete_Comment, etc.):
     * sites must opt in with add_filter('wpmcp_enable_delete_post',
     * '__return_true') before a force-delete will run at all, in addition to
     * the caller passing confirm:true. The default trash path is left ungated:
     * WordPress's own trash already makes it reversible.
     */
    public static function is_force_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_post', false);
    }

    /**
     * Trash-delete (the default) is NOT routed through Safe_Mutation: WordPress's
     * own trash already makes it reversible, so a redundant snapshot buys us
     * nothing. Force-delete permanently destroys the post, so that branch IS
     * safe-wrapped for a rollback-able snapshot.
     */
    /**
     * The URL this post lives at right now, but only when losing it would
     * actually break a link: an unpublished post has no public URL, so there
     * is nothing to suggest a redirect for.
     */
    private function public_url(\WP_Post $post): string
    {
        if ('publish' !== $post->post_status) {
            return '';
        }
        $permalink = get_permalink($post->ID);
        return is_string($permalink) ? $permalink : '';
    }

    /**
     * Queue (and return) a suggestion that the deleted post's URL be
     * redirected somewhere. SUGGESTION ONLY: nothing here creates a redirect.
     * The caller decides where the URL should now point and makes an explicit
     * create-redirect call, or a human approves it on the Redirects screen.
     * Silently produces nothing when the URL is already redirected.
     *
     * @return array<string,mixed>|null
     */
    private function suggest_redirect(string $url, \WP_Post $post): ?array
    {
        if ('' === $url) {
            return null;
        }

        return Redirect_Suggestions::propose(
            $url,
            Redirect_Suggestions::REASON_POST_DELETED,
            0,
            sprintf('"%s" was deleted; choose where its URL should go.', $post->post_title)
        );
    }

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found');
        }

        $force    = ! empty($args['force']);
        $lost_url = $this->public_url($post);

        if (! $force) {
            Save_Filters::trash_post($post_id);
            $result = ['post_id' => $post_id, 'deleted' => 'trashed'];
            $suggestion = $this->suggest_redirect($lost_url, $post);
            if (null !== $suggestion) {
                $result['suggested_redirect'] = $suggestion;
            }
            return $result;
        }

        if (! self::is_force_enabled()) {
            throw new \RuntimeException('Permanent (force) delete-post is disabled. Enable it with the wpmcp_enable_delete_post filter.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Permanently deleting a post is not reversible for the physical record. Pass confirm:true to proceed.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-post',
                'args'        => $args,
            ],
            function () use ($post_id) {
                wp_delete_post($post_id, true);
                return true;
            }
        );

        $result     = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'deleted' => 'deleted'];
        $suggestion = $this->suggest_redirect($lost_url, $post);
        if (null !== $suggestion) {
            $result['suggested_redirect'] = $suggestion;
        }

        return $result;
    }
}
