<?php

/**
 * Stand-in for Beaver Builder's model class for the suite without the
 * plugin: it records the asset cache calls wpmcp makes and holds the module
 * registry ($modules, slug => module object) the catalog read lists. Only
 * reached when the wpmcp_beaver_builder_active filter says the plugin is
 * loaded.
 */

if (! class_exists('FLBuilderModel', false)) {
    class FLBuilderModel // phpcs:ignore
    {
        /** @var array<int,array{0:string,1:int}> */
        public static array $calls = [];

        /** @var array<string,object> */
        public static $modules = [];

        public static function delete_all_asset_cache($post_id = false): void
        {
            self::$calls[] = ['delete_all_asset_cache', (int) $post_id];
        }

        public static function delete_node_template_asset_cache($post_id = false): void
        {
            self::$calls[] = ['delete_node_template_asset_cache', (int) $post_id];
        }

        /**
         * The builder UI is never open in a test. Other plugins probe
         * this whenever the class exists (Spectra does on every page view
         * in the live blocks leg), so the stand-in answers like the real one.
         */
        public static function is_builder_active(): bool
        {
            return false;
        }
    }
}
