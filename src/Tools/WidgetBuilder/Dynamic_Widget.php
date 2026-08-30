<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The single, data-driven Elementor widget behind every custom widget.
 *
 * One class serves all custom widgets: its identity and controls come from a
 * spec (set at registration, or resolved by name when Elementor rebuilds a
 * saved widget), and render() interpolates control values into the spec's
 * template via Widget_Renderer. No generated PHP, no eval. The class is only
 * defined when Elementor's Widget_Base is available, so it is inert on sites
 * without Elementor.
 */
if (class_exists('\\Elementor\\Widget_Base')) {
    class Dynamic_Widget extends \Elementor\Widget_Base
    {
        /** @var array<string,mixed> */
        private array $spec = [];

        public function set_spec(array $spec): void
        {
            $this->spec = $spec;
        }

        public function get_name()
        {
            $from_data = (string) $this->get_data('widgetType');
            if ('' !== $from_data) {
                return $from_data;
            }
            return (string) ($this->spec['name'] ?? 'wpmcp-widget');
        }

        public function get_title()
        {
            $spec = $this->resolve_spec();
            return (string) ($spec['title'] ?? 'Custom Widget');
        }

        public function get_icon()
        {
            $spec = $this->resolve_spec();
            return (string) ($spec['icon'] ?? 'eicon-code');
        }

        public function get_categories()
        {
            return ['general'];
        }

        public function get_keywords()
        {
            $spec = $this->resolve_spec();
            return is_array($spec['keywords'] ?? null) ? array_map('strval', $spec['keywords']) : [];
        }

        protected function register_controls(): void
        {
            $spec = $this->resolve_spec();

            $this->start_controls_section('wpmcp_content', [
                'label' => (string) ($spec['title'] ?? 'Content'),
            ]);

            foreach ((array) ($spec['controls'] ?? []) as $control) {
                if (! is_array($control)) {
                    continue;
                }
                $name = sanitize_key((string) ($control['name'] ?? ''));
                $type = (string) ($control['type'] ?? 'text');
                if ('' === $name || ! isset(Widget_Spec::CONTROL_TYPES[$type])) {
                    continue;
                }
                // Elementor's control-type constants ARE these lowercase strings
                // (Controls_Manager::TEXT === 'text', WYSIWYG === 'wysiwyg', ...).
                $this->add_control($name, [
                    'label'   => (string) ($control['label'] ?? $name),
                    'type'    => Widget_Spec::CONTROL_TYPES[$type]['elementor'],
                    'default' => $control['default'] ?? '',
                ]);
            }

            $this->end_controls_section();
        }

        protected function render(): void
        {
            $spec = $this->resolve_spec();
            if ([] === $spec) {
                return;
            }
            // Every {{name}} placeholder is escaped by control type inside the renderer
            // (text/textarea -> esc_html, wysiwyg -> wp_kses_post, url/image -> esc_url,
            // anything else -> esc_html). What surrounds them is the spec's own template:
            // author-supplied HTML written through the capability-gated
            // create-custom-widget / update-custom-widget abilities, and wp_kses_post'd on
            // write for any author without `unfiltered_html` (Widget_Spec_Store). Escaping
            // here would double-encode the values and destroy the template.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget_Renderer::render() (src/Tools/WidgetBuilder/Widget_Renderer.php:42) escapes every interpolated value by control type; the surrounding template is capability-gated author markup. Pro-only file, stripped from the directory build by scripts/flavors/wporg/strip.php.
            echo Widget_Renderer::render($spec, (array) $this->get_settings_for_display());
        }

        /** The spec set at registration, or resolved by widget name when Elementor rebuilds this widget. */
        private function resolve_spec(): array
        {
            if ([] !== $this->spec) {
                return $this->spec;
            }
            $resolved = Widget_Registry::spec_for($this->get_name());
            if (is_array($resolved)) {
                $this->spec = $resolved;
            }
            return $this->spec;
        }
    }
}
