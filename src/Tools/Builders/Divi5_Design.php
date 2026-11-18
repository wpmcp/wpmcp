<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only view of a Divi 5 site's templates and module catalog (issue
 * #636), for get-builder-content's scope reads.
 *
 * - Templates are the Divi Library (`et_pb_layout` posts, their kind in the
 *   `layout_type` taxonomy), the Theme Builder's header, body and footer
 *   layouts (`et_header_layout`, `et_body_layout`, `et_footer_layout`) and
 *   its templates (`et_template`), whose conditions sit in the repeated
 *   `_et_use_on` / `_et_exclude_from` meta and whose areas point at layouts
 *   through `_et_<area>_layout_id` and `_et_<area>_layout_enabled`. Every
 *   layout is a post, so the update-builder-content operations edit one by
 *   its id the way they edit a page.
 * - The catalog comes from the loaded Divi 5: the module metadata its
 *   ModuleRegistration keeps, summarised to each module's attributes and
 *   the setting groups under them, with the module's default attributes.
 *   Divi lists its WooCommerce, Gravity Forms, Contact Form 7 and Imagely
 *   modules whether or not those plugins run, and they render nothing
 *   without them, so such entries name the plugin (`requires`) and say
 *   whether it is loaded (`available`), checked the way Divi checks it
 *   (issue #836).
 * - A layout with no content reads as `format: empty` rather than Divi 4:
 *   Divi creates empty layouts for new Theme Builder templates (#836).
 */
class Divi5_Design
{
    public const LAYOUT_TYPES = ['et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout'];

    private const REGISTRATION = 'ET\\Builder\\Packages\\ModuleLibrary\\ModuleRegistration';

    /** Plugin slug => the class Divi 5.18's module checks before rendering. */
    private const PLUGIN_CLASSES = [
        'woocommerce'     => 'WooCommerce',
        'gravityforms'    => 'GFForms',
        'contact-form-7'  => 'WPCF7_ContactForm',
        'nextgen-gallery' => 'C_NextGEN_Bootstrap',
    ];

    /** Module name => required plugin slug, besides every divi/woocommerce-* module. */
    private const MODULE_PLUGINS = [
        'divi/shop'            => 'woocommerce',
        'divi/gravity-forms'   => 'gravityforms',
        'divi/contact-form-7'  => 'contact-form-7',
        'divi/imagely-gallery' => 'nextgen-gallery',
    ];

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $layouts   = [];
        $templates = [];
        foreach (Builder_Design_Data::posts(array_merge(self::LAYOUT_TYPES, ['et_template'])) as $post) {
            if ('et_template' === $post->post_type) {
                $templates[] = self::template($post);
                continue;
            }

            $content = (string) $post->post_content;
            $layout  = [
                'id'     => (int) $post->ID,
                'title'  => $post->post_title,
                'status' => $post->post_status,
                'type'   => 'et_pb_layout' === $post->post_type ? self::library_kind((int) $post->ID) : substr($post->post_type, 3, -7),
                'format' => self::layout_format($content),
            ];
            if ('divi5' === $layout['format']) {
                try {
                    $layout['tree'] = Divi5_Blocks::tree($content);
                } catch (\InvalidArgumentException $e) {
                    $layout['tree_error'] = $e->getMessage();
                }
            }
            $layouts[] = $layout;
        }

        return [
            'builder'        => 'divi',
            'scope'          => 'templates',
            'plugin_active'  => Divi_Content::divi5_active(),
            'plugin_version' => Divi_Content::plugin_version(),
            'layouts'        => $layouts,
            'templates'      => $templates,
        ];
    }

    /**
     * The module list, or one module's attributes when $element names it
     * (`divi/text` or `text`).
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function catalog(string $element = '')
    {
        $metadata = self::metadata();
        if (null === $metadata) {
            return Builder_Design_Data::not_loaded('divi');
        }

        $modules = [];
        foreach ($metadata as $module) {
            if (is_array($module) && is_string($module['name'] ?? null)) {
                $modules[ $module['name'] ] = $module;
            }
        }
        ksort($modules);

        if ('' !== $element) {
            $name   = false === strpos($element, '/') ? 'divi/' . $element : $element;
            $module = $modules[ $name ] ?? null;
            if (null === $module) {
                return Builder_Design_Data::element_not_found('divi', $element);
            }

            return [
                'builder' => 'divi',
                'scope'   => 'catalog',
                'element' => self::summary($module) + [
                    'description' => (string) ($module['description'] ?? ''),
                    'attrs_shape' => '{<attribute>:{innerContent|decoration.<group>|advanced.<group>|meta.<group>:{desktop|tablet|phone:{value:...}}}}',
                    'attributes'  => self::attributes($module),
                    'defaults'    => self::defaults($name),
                ],
            ];
        }

        return [
            'builder'        => 'divi',
            'scope'          => 'catalog',
            'plugin_version' => Divi_Content::plugin_version(),
            'elements'       => array_values(array_map([self::class, 'summary'], $modules)),
        ];
    }

    /** @return array<int|string,mixed>|null the loaded Divi 5's module metadata */
    private static function metadata(): ?array
    {
        $metadata = apply_filters('wpmcp_divi_module_metadata', null);
        if (is_array($metadata)) {
            return $metadata;
        }
        if (! Divi_Content::divi5_active() || ! method_exists(self::REGISTRATION, 'get_all_core_modules_metadata')) {
            return null;
        }

        try {
            $metadata = call_user_func([self::REGISTRATION, 'get_all_core_modules_metadata']);
        } catch (\Throwable $e) {
            unset($e);
            return null;
        }

        return is_array($metadata) ? $metadata : null;
    }

    private static function layout_format(string $content): string
    {
        if ('' === trim($content)) {
            return 'empty';
        }

        return Divi5_Blocks::is_divi5($content) ? 'divi5' : 'divi4';
    }

    /** @return array<string,mixed> */
    private static function summary(array $module): array
    {
        $name    = (string) $module['name'];
        $summary = [
            'name'     => $name,
            'title'    => (string) ($module['title'] ?? ''),
            'category' => (string) ($module['category'] ?? ''),
            'children' => array_values(array_filter((array) ($module['childrenName'] ?? []), 'is_string')),
        ];

        $plugin = self::MODULE_PLUGINS[ $name ] ?? (0 === strpos($name, 'divi/woocommerce-') ? 'woocommerce' : null);
        if (null !== $plugin) {
            $summary['requires']  = $plugin;
            $summary['available'] = self::plugin_active($plugin);
        }

        return $summary;
    }

    private static function plugin_active(string $plugin): bool
    {
        return (bool) apply_filters('wpmcp_divi_module_plugin_active', class_exists(self::PLUGIN_CLASSES[ $plugin ]), $plugin);
    }

    /**
     * Each attribute with the setting groups under it: innerContent's label,
     * and the group names under decoration, advanced and meta.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function attributes(array $module): array
    {
        $out = [];
        foreach ((array) ($module['attributes'] ?? []) as $attribute => $definition) {
            $settings = is_array($definition) ? (array) ($definition['settings'] ?? []) : [];
            $entry    = [];
            foreach ($settings as $key => $setting) {
                if ('innerContent' === $key) {
                    $label = $setting['item']['label'] ?? null;
                    $entry['innerContent'] = is_string($label) ? $label : true;
                    continue;
                }
                $entry[ (string) $key ] = is_array($setting) ? array_map('strval', array_keys($setting)) : [];
            }
            $out[ (string) $attribute ] = $entry;
        }

        return $out;
    }

    /** @return array<mixed> the module's default attributes, [] when unknown */
    private static function defaults(string $name): array
    {
        if (! method_exists(self::REGISTRATION, 'get_default_attrs')) {
            return [];
        }

        try {
            $defaults = call_user_func([self::REGISTRATION, 'get_default_attrs'], $name);
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }

        return is_array($defaults) ? $defaults : [];
    }

    private static function library_kind(int $post_id): string
    {
        $terms = get_the_terms($post_id, 'layout_type');

        return is_array($terms) && isset($terms[0]->slug) ? (string) $terms[0]->slug : 'layout';
    }

    /** @return array<string,mixed> */
    private static function template(\WP_Post $post): array
    {
        $id    = (int) $post->ID;
        $areas = [];
        foreach (['header', 'body', 'footer'] as $area) {
            $areas[ $area ] = [
                'layout_id' => (int) get_post_meta($id, "_et_{$area}_layout_id", true),
                'enabled'   => '1' === (string) get_post_meta($id, "_et_{$area}_layout_enabled", true),
            ];
        }

        return [
            'id'           => $id,
            'title'        => $post->post_title,
            'status'       => $post->post_status,
            'default'      => '1' === (string) get_post_meta($id, '_et_default', true),
            'enabled'      => '1' === (string) get_post_meta($id, '_et_enabled', true),
            'use_on'       => array_values(array_map('strval', (array) get_post_meta($id, '_et_use_on', false))),
            'exclude_from' => array_values(array_map('strval', (array) get_post_meta($id, '_et_exclude_from', false))),
            'layouts'      => $areas,
        ];
    }
}
