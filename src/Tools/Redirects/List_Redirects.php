<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only listing of the managed redirects (issue #128), newest first,
 * with optional enabled/search filters and paging.
 *
 * Each row is returned with its effective_target resolved the same way the
 * front-end handler resolves it, so an agent sees the URL visitors would
 * actually be sent to (a post-id-backed row shows the post's current
 * permalink) rather than having to re-derive it. An empty effective_target
 * means the row's target post is gone and the redirect is inert, which is
 * surfaced as `active` so it does not have to be inferred.
 *
 * The pending suggestion queue is included too, so the same call that shows
 * what IS redirected also shows what the plugin thinks SHOULD be, without a
 * second tool on the tools/list surface.
 */
class List_Redirects
{
    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function handle(array $args)
    {
        // Issue #930: the active redirect plugin's own redirects, when one is.
        $routed = Redirect_Router::route('list-redirects', $args);
        if (null !== $routed) {
            if (is_array($routed)) {
                $routed['pending_suggestions'] = Redirect_Suggestions::all();
                $routed['managed_total']       = Redirect_Store::count([]);
            }
            return $routed;
        }

        $filters = [
            'limit'  => (int) ($args['limit'] ?? 100),
            'offset' => (int) ($args['offset'] ?? 0),
        ];
        if (isset($args['enabled'])) {
            $filters['enabled'] = (bool) $args['enabled'];
        }
        if (isset($args['search'])) {
            $filters['search'] = (string) $args['search'];
        }

        $redirects = [];
        foreach (Redirect_Store::all($filters) as $row) {
            $target             = Redirect_Store::resolve_target($row);
            $row['effective_target'] = $target;
            $row['active']           = $row['enabled'] && '' !== $target;
            $redirects[]             = $row;
        }

        return [
            'provider'            => Redirect_Router::BUILTIN,
            'redirects'           => $redirects,
            'total'               => Redirect_Store::count($filters),
            'pending_suggestions' => Redirect_Suggestions::all(),
        ];
    }
}
