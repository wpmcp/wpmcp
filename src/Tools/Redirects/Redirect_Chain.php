<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Chain flattening and loop detection for a proposed redirect (issue #128).
 *
 * A redirect manager that stores what the caller typed will happily build
 * A -> B -> C, costing every visitor two round trips and leaking link equity,
 * and will just as happily build A -> B -> A, which browsers turn into
 * ERR_TOO_MANY_REDIRECTS. Both are decided here, at write time, rather than
 * papered over at request time:
 *
 *  - FLATTENING: if the proposed target is itself the source of another
 *    enabled redirect, the chain is followed to its end and the FINAL
 *    destination is what gets stored, so every managed redirect is exactly
 *    one hop. When the last hop was a post-id-backed redirect, the flattened
 *    row inherits that target_post_id instead of a frozen permalink, so it
 *    still survives the target's later slug changes.
 *  - LOOPS: revisiting any path already seen on the walk (including the
 *    proposed source itself) is refused outright, with the cycle reported
 *    back to the caller. Refusing at create time is the difference between
 *    an agent getting an actionable error and a site quietly breaking.
 *
 * Disabled rows and rows whose target no longer resolves (a deleted target
 * post) terminate the walk instead of being followed: neither would actually
 * fire for a visitor, so flattening through them would invent a hop the site
 * does not have.
 */
class Redirect_Chain
{
    /**
     * Resolve the effective, one-hop target for a proposed redirect.
     *
     * @param string $source_path The proposed (already normalizable) source.
     * @param string $target      The proposed target URL or path.
     * @param int    $ignore_id   Row id to treat as absent (the row being
     *                            updated), so update-redirect does not
     *                            flatten a redirect through its own old self.
     * @return array{target:string,target_post_id:int,flattened:bool,chain:string[]}
     * @throws \InvalidArgumentException When the proposal would create a loop.
     */
    public static function flatten(string $source_path, string $target, int $ignore_id = 0): array
    {
        $source = Redirect_Store::normalize_path($source_path);
        $result = [
            'target'         => $target,
            'target_post_id' => 0,
            'flattened'      => false,
            'chain'          => [],
        ];

        if (! Redirect_Store::is_internal($target)) {
            return $result;
        }

        $path = Redirect_Store::normalize_path($target);
        if ($path === $source) {
            throw new \InvalidArgumentException(
                'Redirect refused: "' . esc_html($source) . '" would point at itself.'
            );
        }

        $seen = [$source => true, $path => true];

        for ($hop = 0; $hop < Redirect_Store::MAX_CHAIN_DEPTH; $hop++) {
            $row = Redirect_Store::find_by_source($path);
            if (! $row || ! $row['enabled'] || $row['id'] === $ignore_id) {
                return $result;
            }

            $next = Redirect_Store::resolve_target($row);
            if ('' === $next) {
                return $result; // Dead end: this hop would not fire for a visitor either.
            }

            $result['chain'][]        = $path;
            $result['target']         = $next;
            $result['target_post_id'] = (int) $row['target_post_id'];
            $result['flattened']      = true;

            if (! Redirect_Store::is_internal($next)) {
                return $result;
            }

            $path = Redirect_Store::normalize_path($next);
            if (isset($seen[ $path ])) {
                throw new \InvalidArgumentException(sprintf(
                    'Redirect refused: "%s" -> "%s" would create a redirect loop (%s).',
                    esc_html($source),
                    esc_html(Redirect_Store::normalize_path($target)),
                    esc_html(implode(' -> ', array_merge([$source], $result['chain'], [$path])))
                ));
            }
            $seen[ $path ] = true;
        }

        throw new \InvalidArgumentException(sprintf(
            'Redirect refused: "%s" leads through more than %d redirects, which is treated as a loop.',
            esc_html($source),
            (int) Redirect_Store::MAX_CHAIN_DEPTH
        ));
    }
}
