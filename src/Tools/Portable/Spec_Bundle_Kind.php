<?php

namespace WPMCP\Tools\Portable;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared export and import for the spec-builder stores (custom blocks and
 * custom widgets), whose items use the cloud asset shape {type, name, title,
 * spec}. The concrete kinds live beside their stores and supply the store
 * primitives; this class names no store.
 *
 * Import validates with the store's own validator, creates the spec as a
 * draft (the store's "inactive"), and writes a 'post_create' creation row
 * marked remove_on_rollback: undoing an import moves the spec to the trash
 * rather than only deactivating it, since the import is what brought it here.
 */
abstract class Spec_Bundle_Kind implements Bundle_Kind
{
    private const MAX_RENAMES = 100;

    /** @return array<int,array{id:int,title:string}> every stored spec. */
    abstract protected function rows(): array;

    abstract protected function get(int $id): ?array;

    /** @return true|\WP_Error */
    abstract protected function validate(array $spec);

    /** The stored (normalized) name $spec would get. */
    abstract protected function name_of(array $spec): string;

    abstract protected function find_by_name(string $name): ?int;

    /** @return int|\WP_Error the new spec's post id, stored as a draft. */
    abstract protected function create_inactive(array $spec);

    /** Extra response fields for a created spec. */
    protected function created(int $id, array $spec): array
    {
        return [];
    }

    public function export(?array $ids): array
    {
        $items = [];
        foreach ($this->rows() as $row) {
            if (null !== $ids && ! in_array((string) $row['id'], $ids, true)) {
                continue;
            }
            $spec = $this->get((int) $row['id']);
            if (! is_array($spec)) {
                continue;
            }
            $items[] = [
                'type'  => $this->type(),
                'name'  => (string) ($spec['name'] ?? ''),
                'title' => (string) $row['title'],
                'spec'  => $spec,
            ];
        }

        return $items;
    }

    public function import(array $item, bool $rename, string $session_id): array
    {
        $spec = $item['spec'] ?? null;
        if (! is_array($spec)) {
            throw new Bundle_Item_Refused(sprintf('A %s item needs its spec as an object.', esc_html($this->type())));
        }
        $valid = $this->validate($spec);
        if (is_wp_error($valid)) {
            throw new Bundle_Item_Refused(esc_html($valid->get_error_message()));
        }

        $original = $this->name_of($spec);
        $spec     = $this->free_name($spec, $original, $rename);
        $final    = $this->name_of($spec);

        $id = $this->create_inactive($spec);
        if (is_wp_error($id)) {
            throw new Bundle_Item_Refused(esc_html($id->get_error_message()));
        }

        try {
            Post_Creation_Snapshot::record('import-bundle', [$id], ['type' => $this->type(), 'name' => $final], $session_id, true);
        } catch (\Throwable $e) {
            // record() has already removed the spec it could not cover.
            throw new Bundle_Item_Refused('The import could not be recorded for rollback, so the spec was not kept: ' . esc_html($e->getMessage()));
        }

        $result = ['id' => $id, 'name' => $final];
        if ($final !== $original) {
            $result['renamed_from'] = $original;
        }

        return $result + $this->created($id, $spec);
    }

    private function free_name(array $spec, string $name, bool $rename): array
    {
        if (null === $this->find_by_name($name)) {
            return $spec;
        }
        if (! $rename) {
            throw new Bundle_Item_Refused(sprintf('A %s named "%s" already exists. Pass on_conflict rename to import it under a new name.', esc_html($this->type()), esc_html($name)));
        }
        $base = substr((string) strrchr('/' . $name, '/'), 1);
        for ($n = 2; $n <= self::MAX_RENAMES; $n++) {
            $spec['name'] = $base . '-' . $n;
            if (null === $this->find_by_name($this->name_of($spec))) {
                return $spec;
            }
        }

        throw new Bundle_Item_Refused(sprintf('No free name found for %s "%s".', esc_html($this->type()), esc_html($name)));
    }
}
