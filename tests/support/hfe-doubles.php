<?php

/**
 * Stand-in for the display rule lists of Ultimate Addons for Elementor
 * (header-footer-elementor 2.9.8, issue #651): HFE\Lib\Astra_Target_Rules_Fields,
 * the plugin's own copy of the target rules library. A short slice in the
 * plugin's own shape, recorded from the real class, so the adapter reads it
 * as it reads the real one. Loaded only by the stub-backed tests.
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Files.SideEffects.FoundWithSymbols

namespace HFE\Lib {
    if (! class_exists(__NAMESPACE__ . '\\Astra_Target_Rules_Fields', false)) {
        class Astra_Target_Rules_Fields
        {
            /** @return array<string,mixed> */
            public static function get_location_selections(): array
            {
                return [
                    'basic'           => [ 'label' => 'Basic', 'value' => [ 'basic-global' => 'Entire Website', 'basic-singulars' => 'All Singulars', 'basic-archives' => 'All Archives' ] ],
                    'special-pages'   => [ 'label' => 'Special Pages', 'value' => [ 'special-404' => '404 Page', 'special-search' => 'Search Page', 'special-front' => 'Front Page' ] ],
                    'post'            => [ 'label' => 'Posts', 'value' => [ 'post|all' => 'All Posts', 'post|all|archive' => 'All Posts Archive' ] ],
                    'specific-target' => [ 'label' => 'Specific Target', 'value' => [ 'specifics' => 'Specific Pages / Posts / Taxonomies, etc.' ] ],
                ];
            }

            /** @return array<string,mixed> */
            public static function get_user_selections(): array
            {
                $roles = [];
                foreach (get_editable_roles() as $slug => $data) {
                    $roles[ $slug ] = $data['name'];
                }
                return [
                    'basic'    => [ 'label' => 'Basic', 'value' => [ 'all' => 'All', 'logged-in' => 'Logged In', 'logged-out' => 'Logged Out' ] ],
                    'advanced' => [ 'label' => 'Advanced', 'value' => $roles ],
                ];
            }
        }
    }
}
