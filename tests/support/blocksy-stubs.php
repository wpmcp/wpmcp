<?php
/**
 * Faithful double of the part of the Blocksy theme a settings write meets
 * (issue #797), verified against Blocksy 2.1.61: blocksy_manager() returns
 * the manager singleton, whose public db property is a Blocksy\Database.
 * That class reads get_theme_mods() once per request outside wp-admin, the
 * Customizer and AJAX, keeps the copy, and drops it only in wipe_cache().
 * So on a REST or MCP request that read a theme mod before the write, the
 * dynamic CSS rebuild in the same request sees the old values unless the
 * copy is wiped first. Real Blocksy always wins: nothing here is defined
 * when the theme is loaded.
 */

namespace Blocksy {
    if (! class_exists(Database::class, false)) {
        class Database
        {
            private $mods = '__EMPTY__';

            public function get_theme_mod($name, $default_value = false)
            {
                if (is_admin() || is_customize_preview() || wp_doing_ajax() || '__EMPTY__' === $this->mods) {
                    $this->mods = get_theme_mods();
                }
                $value = $this->mods[ $name ] ?? $default_value;
                return apply_filters("theme_mod_{$name}", $value);
            }

            public function wipe_cache()
            {
                $this->mods = '__EMPTY__';
            }
        }
    }
}

namespace {
    if (! class_exists('WPMCP_Test_Blocksy_Manager', false)) {
        class WPMCP_Test_Blocksy_Manager
        {
            public $db = null;

            private static ?self $instance = null;

            public static function instance(): self
            {
                if (null === self::$instance) {
                    self::$instance     = new self();
                    self::$instance->db = new \Blocksy\Database();
                }
                return self::$instance;
            }
        }
    }

    if (! function_exists('blocksy_manager')) {
        function blocksy_manager()
        {
            return WPMCP_Test_Blocksy_Manager::instance();
        }
    }
}
