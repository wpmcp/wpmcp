<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handler for compile-custom-widget (issue #72): compile a stored widget
 * spec into a real PHP widget class in the manifest-loaded sandbox. WIP:
 * validates and gate-checks today, and reports the compiler's current state
 * honestly; nothing is written to disk until Widget_Compiler emits source
 * that passes Generated_Code_Lint.
 */
class Compile_Custom_Widget
{
    public function handle(array $args): array
    {
        if (! Gate::can_use('compile-custom-widget')) {
            throw new \RuntimeException('Spec-compiled widgets are a PRO feature.');
        }

        $widget_id = absint($args['widget_id'] ?? 0);
        $spec      = Widget_Spec_Store::get($widget_id);
        if (null === $spec) {
            return ['compiled' => false, 'error' => 'No custom widget with that id.', 'code' => 'wpmcp_widget_not_found'];
        }

        $source = Widget_Compiler::compile($spec);
        if (is_wp_error($source)) {
            return ['compiled' => false, 'error' => $source->get_error_message(), 'code' => $source->get_error_code()];
        }

        $lint = Generated_Code_Lint::check($source);
        if (is_wp_error($lint)) {
            // Lint failure means a generator bug: abort, write nothing.
            return ['compiled' => false, 'error' => $lint->get_error_message(), 'code' => $lint->get_error_code()];
        }

        // TODO(#72): write the linted source into the sandbox, record it in
        // Compiled_Widget_Manifest (hash + spec_id + enabled), and record the
        // file change as an operation in history so delete is restorable from
        // the spec.
        return ['compiled' => false, 'error' => 'Sandbox write path not implemented yet.', 'code' => 'wpmcp_compiler_incomplete'];
    }
}
