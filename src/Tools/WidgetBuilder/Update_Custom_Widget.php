<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace a custom widget's spec by id. The new spec is validated before it is
 * stored on the wpmcp_widget post.
 *
 * If the widget has a compiled class, that class is DISABLED here rather than
 * left in place. A compiled class wins over the spec at registration time, so
 * leaving it enabled would mean an accepted update silently changed nothing
 * on the front end: the site would keep rendering the previous template with
 * no error anywhere. Disabling it makes the spec store the source of truth it
 * is documented as; the response says so, and recompiling re-enables it.
 */
class Update_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        $spec  = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        Widget_Spec_Store::update($id, $spec);
        $stored = Widget_Spec_Store::get($id);

        $out = [
            'widget_id' => $id,
            'name'      => (string) ($stored['name'] ?? ''),
            'title'     => (string) ($stored['title'] ?? ''),
        ];

        if (null !== Compiler\Compiled_Widget_Manifest::get($id)) {
            Compiler\Compiled_Widget_Manifest::set_enabled($id, false);
            $out['compiled_disabled'] = true;
            $out['note']              = 'This widget had a compiled class built from the previous spec. It has been disabled so the updated spec is what renders; run compile-custom-widget to compile the new spec.';
        }

        return $out;
    }
}
