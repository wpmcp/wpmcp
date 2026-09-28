<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Portable\Spec_Bundle_Kind;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The custom block store's side of the portable bundle (issue #297). Pro,
 * like the block builder itself: without the paid tier, block items are
 * neither exported nor imported.
 */
class Block_Bundle_Kind extends Spec_Bundle_Kind
{
    public function type(): string
    {
        return 'block';
    }

    public function available(): bool
    {
        return Gate::is_pro();
    }

    protected function rows(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['block_id'], 'title' => (string) $row['title']],
            Block_Spec_Store::all()
        );
    }

    protected function get(int $id): ?array
    {
        return Block_Spec_Store::get($id);
    }

    protected function validate(array $spec)
    {
        return Block_Spec::validate($spec);
    }

    protected function name_of(array $spec): string
    {
        return (string) Block_Spec::normalize($spec)['name'];
    }

    protected function find_by_name(string $name): ?int
    {
        return Block_Spec_Store::find_by_name($name);
    }

    protected function create_inactive(array $spec)
    {
        return Block_Spec_Store::create($spec, 'draft');
    }
}
