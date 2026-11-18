<?php

/**
 * A stand-in for the one Ninja Tables function the Data Tables ops call
 * (issue #706), with Ninja Tables 5.5's own body (TableCache::
 * clearTableDataCache): it drops the table's cached rows and HTML. Defined
 * only while the real plugin is not loaded.
 */
if (! function_exists('ninjaTablesClearTableDataCache')) {
    function ninjaTablesClearTableDataCache($tableId) // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- the plugin's own name and signature.
    {
        foreach ([ '_ninja_table_cache_object', '_ninja_table_cache_html', '_external_cached_data', '_last_external_cached_time', '__ninja_cached_table_html' ] as $key) {
            update_post_meta($tableId, $key, false); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        }
    }
}
