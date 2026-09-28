<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Portable\Spec_Bundle_Kind;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The custom widget store's side of the portable bundle (issue #297). Pro,
 * like the widget builder itself. Widget_Spec_Store applies its own
 * unfiltered_html gate on create, so an imported template is filtered exactly
 * like a hand-created one, and the response says when it was.
 */
class Widget_Bundle_Kind extends Spec_Bundle_Kind
{
    public function type(): string
    {
        return 'widget';
    }

    public function available(): bool
    {
        return Gate::is_pro();
    }

    protected function rows(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['widget_id'], 'title' => (string) $row['title']],
            Widget_Spec_Store::all()
        );
    }

    protected function get(int $id): ?array
    {
        return Widget_Spec_Store::get($id);
    }

    protected function validate(array $spec)
    {
        return Widget_Spec::validate($spec);
    }

    protected function name_of(array $spec): string
    {
        return (string) Widget_Spec::normalize($spec)['name'];
    }

    protected function find_by_name(string $name): ?int
    {
        return Widget_Spec_Store::find_by_name($name);
    }

    protected function create_inactive(array $spec)
    {
        return Widget_Spec_Store::create($spec, 'draft');
    }

    protected function created(int $id, array $spec): array
    {
        return Widget_Spec_Store::template_was_filtered($spec, $id) ? ['template_filtered' => true] : [];
    }
}
