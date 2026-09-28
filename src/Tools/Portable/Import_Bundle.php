<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * import-bundle (issue #297). The bundle is verified whole first (format,
 * version, checksum); a bundle that fails is refused and nothing is written.
 * Each item is then handed to its store's kind, which re-validates it with
 * the store's own validator and creates it INACTIVE. An item that is
 * malformed, of a kind this site lacks, invalid, or colliding by name (with
 * on_conflict "refuse", the default) is skipped and reported; it never stops
 * the rest. Nothing here activates or executes anything.
 *
 * Every creation is recorded under ONE session: the caller's session_id, or
 * a fresh one returned in the response, so rollback-session removes exactly
 * what this import created.
 */
class Import_Bundle
{
    public const ON_CONFLICT = ['refuse', 'rename'];

    /** The ledger's session_id column width. */
    private const MAX_SESSION_ID = 36;

    /** @var array<string,Bundle_Kind> */
    private array $kinds;

    /** @param Bundle_Kind[]|null $kinds */
    public function __construct(?array $kinds = null)
    {
        $this->kinds = Bundle_Kinds::resolve($kinds);
    }

    public function handle(array $args)
    {
        $on_conflict = (string) ($args['on_conflict'] ?? 'refuse');
        if (! in_array($on_conflict, self::ON_CONFLICT, true)) {
            return new \WP_Error('invalid_on_conflict', 'on_conflict must be refuse or rename.');
        }

        $bundle = Bundle::verify($args['bundle'] ?? null);
        if (is_wp_error($bundle)) {
            return $bundle;
        }

        $session_id = trim((string) ($args['session_id'] ?? ''));
        if ('' === $session_id) {
            $session_id = wp_generate_uuid4();
        } elseif (strlen($session_id) > self::MAX_SESSION_ID) {
            return new \WP_Error('invalid_session_id', sprintf('session_id must be at most %d characters.', self::MAX_SESSION_ID));
        }

        $imported = [];
        $skipped  = [];
        foreach ($bundle['items'] as $index => $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : '';
            $name = is_array($item) && is_scalar($item['name'] ?? null) ? (string) $item['name'] : '';
            $kind = $this->kinds[ $type ] ?? null;

            if (null === $kind || ! $kind->available()) {
                $skipped[] = ['index' => $index, 'type' => $type, 'name' => $name, 'reason' => sprintf('Item type "%s" is not available on this site.', $type)];
                continue;
            }

            try {
                $imported[] = ['index' => $index, 'type' => $type] + $kind->import($item, 'rename' === $on_conflict, $session_id);
            } catch (Bundle_Item_Refused $e) {
                $skipped[] = ['index' => $index, 'type' => $type, 'name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return [
            'session_id'  => $session_id,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'recoverable' => true,
        ];
    }
}
