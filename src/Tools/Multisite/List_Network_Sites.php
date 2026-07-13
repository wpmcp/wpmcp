<?php

namespace WPMCP\Tools\Multisite;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: paginated list of sites on the network (blog_id, url, name,
 * last_updated), via Multisite_Adapter::list_sites(). Only registered when
 * is_multisite() is true (see Plugin.php).
 *
 * limit/offset are optional and clamped by Multisite_Adapter (default
 * DEFAULT_LIMIT, hard-capped at MAX_LIMIT; offset floored at 0) so a
 * malformed or hostile argument never fatals and a network with many sites
 * cannot be used to dump an unbounded result set in one call, mirroring
 * List_Transients's limit clamping.
 */
class List_Network_Sites
{
    public function handle(array $args)
    {
        $limit  = Multisite_Adapter::clamp_limit($this->to_int_or_null($args['limit'] ?? null));
        $offset = Multisite_Adapter::clamp_offset($this->to_int_or_null($args['offset'] ?? null));

        $result = Multisite_Adapter::list_sites($limit, $offset);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        return ['sites' => $result];
    }

    /**
     * Coerce a caller-supplied limit/offset value to an int, treating
     * non-numeric input the same as "not given" rather than throwing, so a
     * malformed argument degrades to the adapter's default/clamped value
     * instead of a fatal.
     */
    private function to_int_or_null($value): ?int
    {
        if (null === $value || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
