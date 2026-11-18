<?php

/**
 * A stand-in for the GTranslate class the translation settings pack reads
 * (issue #690), recorded from GTranslate 5.0.2 (gtranslate.php): the
 * language list it accepts (a subset of GTranslate::$lang_array) and
 * load_defaults(), which fills the keys the plugin reads from its
 * 'GTranslate' option.
 *
 * Loaded only where the real GTranslate is not.
 */

if (! class_exists('GTranslate')) {
    class GTranslate
    {
        /** @var array<string, string> */
        public static $lang_array = [ 'en' => 'English', 'ar' => 'Arabic', 'de' => 'German', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'ru' => 'Russian', 'zh-CN' => 'Chinese (Simplified)' ];

        public static function load_defaults(&$data): void
        {
            if (! is_array($data)) {
                $data = [];
            }
            $data['pro_version']        = $data['pro_version'] ?? '';
            $data['enterprise_version'] = $data['enterprise_version'] ?? '';
            $data['url_translation']    = $data['url_translation'] ?? '';
            $data['default_language']   = $data['default_language'] ?? 'en';
            $data['widget_look']        = $data['widget_look'] ?? 'float';
            $data['incl_langs']         = $data['incl_langs'] ?? [ 'en', 'es', 'it', 'pt', 'de', 'fr', 'ru', 'nl', 'ar', 'zh-CN' ];
            $data['fincl_langs']        = $data['fincl_langs'] ?? [ 'en', 'es', 'it', 'pt', 'de', 'fr', 'ru', 'nl', 'ar', 'zh-CN' ];
        }
    }
}
