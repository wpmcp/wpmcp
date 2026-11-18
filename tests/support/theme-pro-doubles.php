<?php

/**
 * Stand-ins for the hook catalogs of the three commercial theme add-ons the
 * theme layout ops read (issue #656), recorded from the real plugins:
 * Astra Pro 4.13.12 (Astra_Ext_Advanced_Hooks_Meta::$hooks), Kadence Pro
 * 1.2.5 (Kadence_Pro\Elements_Controller hook options) and GP Premium 2.5.6
 * (GeneratePress_Elements_Helper::get_available_hooks()). Each is a short
 * slice in the plugin's own shape, so the adapters read them exactly as they
 * read the real ones. Loaded only by the stub-backed tests; the live leg
 * loads the real plugins instead.
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Files.SideEffects.FoundWithSymbols

namespace {
    if (! class_exists('Astra_Ext_Advanced_Hooks_Meta')) {
        class Astra_Ext_Advanced_Hooks_Meta
        {
            /** @var array<string,mixed> */
            public static $hooks = [
                'head'   => [
                    'title' => 'Head',
                    'hooks' => [
                        'astra_head_top' => [ 'title' => 'Head Top', 'description' => 'astra_head_top - Placement to add your content or snippet at top of the <head> tag.' ],
                        'wp_head'        => [ 'title' => 'WP Head', 'description' => 'wp_head - Action to add custom style, script and meta at the bottom of <head> tag.' ],
                    ],
                ],
                'header' => [
                    'title' => 'Header',
                    'hooks' => [
                        'astra_body_top'      => [ 'title' => 'Body Top', 'description' => 'astra_body_top - Placement to add your content or snippet at top of the <body> tag.' ],
                        'astra_header_before' => [ 'title' => 'Before Header', 'description' => 'astra_header_before - Placement to add your content or snippet just before the opening <header> tag.' ],
                    ],
                ],
                'custom' => [
                    'title' => 'Custom',
                    'hooks' => [
                        'custom_hook' => [ 'title' => 'Custom Hook', 'description' => 'Trigger your content or snippet on your custom action.' ],
                    ],
                ],
            ];
        }
    }

    if (! class_exists('Astra_Target_Rules_Fields')) {
        /**
         * Astra Pro's display rule lists. Like the real class, the location
         * list runs astra_addon_location_rule_post_types, where Astra Pro's
         * own callback (Astra_Ext_Advanced_Hooks_Meta::location_rule_post_types)
         * calls get_current_screen(), a wp-admin function (issue #894).
         */
        class Astra_Target_Rules_Fields
        {
            /** @return array<string,mixed> */
            public static function get_location_selections(): array
            {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Astra Pro's own filter, as the real class applies it.
                $types = (array) apply_filters('astra_addon_location_rule_post_types', [ 'post' => 'post', 'page' => 'page' ]);
                $screen = get_current_screen();
                unset($screen);
                return [
                    'basic'   => [ 'label' => 'Basic', 'value' => [ 'basic-global' => 'Entire Website', 'basic-singulars' => 'All Singulars', 'basic-archives' => 'All Archives' ] ],
                    'special' => [ 'label' => 'Special Pages', 'value' => [ 'special-404' => '404 Page' ] ],
                    'post'    => [ 'label' => 'Posts', 'value' => array_map(static fn ($t) => $t . '|all', $types) ],
                ];
            }

            /** @return array<string,mixed> */
            public static function get_user_selections(): array
            {
                return [ 'basic' => [ 'label' => 'Basic', 'value' => [ 'all' => 'All', 'logged-in' => 'Logged In', 'logged-out' => 'Logged Out' ] ] ];
            }
        }
    }

    if (! class_exists('GeneratePress_Elements_Helper')) {
        class GeneratePress_Elements_Helper
        {
            /** @return array<string,mixed> */
            public static function get_available_hooks(): array
            {
                return [
                    'scripts' => [ 'group' => 'Scripts/Styles', 'hooks' => [ 'wp_head', 'wp_body_open', 'wp_footer' ] ],
                    'header'  => [ 'group' => 'Header', 'hooks' => [ 'generate_before_header', 'generate_after_header' ] ],
                    'footer'  => [ 'group' => 'Footer', 'hooks' => [ 'generate_before_footer', 'generate_after_footer' ] ],
                ];
            }
        }
    }
}

namespace Kadence_Pro {
    if (! class_exists('Kadence_Pro\Elements_Controller')) {
        class Elements_Controller
        {
            private static ?self $instance = null;

            public static function get_instance(): self
            {
                return self::$instance ??= new self();
            }

            /** @return array<int,array<string,mixed>> */
            public function get_hook_options(): array
            {
                return [
                    [ 'label' => 'Body', 'options' => [
                        [ 'value' => 'kadence_before_wrapper', 'label' => 'Before Site Wrapper' ],
                        [ 'value' => 'kadence_after_wrapper', 'label' => 'After Site Wrapper' ],
                    ] ],
                    [ 'label' => 'Header', 'options' => [
                        [ 'value' => 'kadence_before_header', 'label' => 'Before Header' ],
                        [ 'value' => 'replace_header', 'label' => 'Replace Header' ],
                    ] ],
                    [ 'label' => 'Custom', 'options' => [
                        [ 'value' => 'custom', 'label' => 'Custom Hook' ],
                    ] ],
                ];
            }

            /** @return array<int,array<string,mixed>> */
            public function get_fixed_hook_options(): array
            {
                return [
                    [ 'label' => 'Fixed', 'options' => [
                        [ 'value' => 'fixed_above_header', 'label' => 'Fixed Above Header' ],
                        [ 'value' => 'fixed_below_footer', 'label' => 'Fixed Below Footer' ],
                    ] ],
                ];
            }

            /** @return array<int,array<string,mixed>> */
            public function get_template_hook_options(): array
            {
                return [
                    [ 'label' => 'Templates', 'options' => [
                        [ 'value' => 'replace_single_content', 'label' => 'Replace Single Content' ],
                        [ 'value' => 'replace_404', 'label' => 'Replace 404 Page Content' ],
                    ] ],
                ];
            }
        }
    }
}
