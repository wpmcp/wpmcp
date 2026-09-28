<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read one custom widget's stored spec by id, plus its compiled state.
 *
 * The compiled block is not decoration: a compiled class takes precedence over
 * the spec when the widget registers, so `stale` (the stored spec no longer
 * compiles to the bytes the manifest vouches for) is the difference between
 * the spec an agent just wrote and the template the site is actually
 * rendering. Read-only.
 */
class Get_Custom_Widget
{
    public function handle(array $args)
    {
        $id   = (int) ($args['widget_id'] ?? 0);
        $spec = Widget_Spec_Store::get($id);
        if (null === $spec) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        return [
            'widget_id' => $id,
            'status'    => get_post_status($id),
            'spec'      => $spec,
            'compiled'  => Compiler\Compiled_Widget_Manifest::status_for($id, $spec),
        ];
    }
}
