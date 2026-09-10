<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a custom Elementor widget from a spec (title, controls, template).
 * The spec is validated, then stored as a wpmcp_widget post and registered as
 * a real Elementor widget at runtime by the data-driven Dynamic_Widget (no code
 * generation, no eval). Creating destroys nothing; remove with
 * delete-custom-widget.
 *
 * The response carries `template_filtered`: true means the caller lacks
 * `unfiltered_html`, Widget_Spec_Store ran the template through wp_kses_post
 * and the stored template (returned as `template`) differs from the one sent.
 */
class Create_Custom_Widget
{
    public function handle(array $args)
    {
        $spec = is_array($args['spec'] ?? null) ? $args['spec'] : [];

        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $id = Widget_Spec_Store::create($spec);
        if (is_wp_error($id)) {
            return $id;
        }

        return self::response($id, $spec);
    }

    /** Shared with Update_Custom_Widget: the stored identity plus the kses-gate outcome. */
    public static function response(int $id, array $submitted): array
    {
        $stored   = Widget_Spec_Store::get($id) ?? [];
        $filtered = Widget_Spec_Store::template_was_filtered($submitted, $id);

        $out = [
            'widget_id'         => $id,
            'name'              => (string) ($stored['name'] ?? ''),
            'title'             => (string) ($stored['title'] ?? ''),
            'template_filtered' => $filtered,
        ];
        if ($filtered) {
            $out['template'] = (string) ($stored['template'] ?? '');
            $out['notice']   = 'The template was filtered through wp_kses_post because your account lacks unfiltered_html; the stored template is returned as "template".';
        }

        return $out;
    }
}
