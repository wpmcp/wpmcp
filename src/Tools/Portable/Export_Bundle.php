<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * export-bundle (issue #297): every item, or the selected ones, of each
 * available store as one versioned, checksummed bundle. Read-only.
 *
 * Selection: `types` narrows to some kinds, `ids` to some store ids (block
 * and widget post ids, snippet ids). Both are optional.
 */
class Export_Bundle
{
    /** @var array<string,Bundle_Kind> */
    private array $kinds;

    /** @param Bundle_Kind[]|null $kinds */
    public function __construct(?array $kinds = null)
    {
        $this->kinds = Bundle_Kinds::resolve($kinds);
    }

    public function handle(array $args): array
    {
        $types = is_array($args['types'] ?? null) ? array_map('strval', $args['types']) : null;
        $ids   = is_array($args['ids'] ?? null) ? array_map('strval', array_filter($args['ids'], 'is_scalar')) : null;

        $items  = [];
        $counts = [];
        foreach ($this->kinds as $type => $kind) {
            if ((null !== $types && ! in_array($type, $types, true)) || ! $kind->available()) {
                continue;
            }
            $exported        = $kind->export($ids);
            $counts[ $type ] = count($exported);
            array_push($items, ...$exported);
        }

        if (count($items) > Bundle::MAX_ITEMS) {
            return new \WP_Error('bundle_too_large', sprintf('%d items selected, over the %d item bundle limit. Narrow the export with types or ids.', count($items), Bundle::MAX_ITEMS));
        }

        return ['bundle' => Bundle::build($items), 'counts' => $counts];
    }
}
