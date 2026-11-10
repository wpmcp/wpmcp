<?php
/**
 * A faithful test double for the JetElements settings API the addon pack
 * (issue #653) reads its module catalog from, shaped like JetElements 2.9
 * (includes/class-jet-elements-settings.php):
 *
 *  - Jet_Elements_Settings::get_instance() returns the settings singleton.
 *  - $avaliable_widgets maps each widget file slug under includes/addons
 *    (for example jet-elements-banner) to its Name header; init() fills it.
 *  - $default_avaliable_extensions is [ 'section_parallax' => 'true' ].
 *  - $settings caches the jet-elements-settings option for the request;
 *    null means it is read again on the next get().
 *
 * Presence (the jet_elements() function) is not faked here: the tests turn
 * the pack on through the wpmcp_jet_elements_active filter. Loaded only by
 * the tests that need it; the real class always wins.
 */

if (! class_exists('Jet_Elements_Settings', false)) {
    class Jet_Elements_Settings
    {
        /** @var self|null */
        private static $instance = null;

        /** @var string */
        public $key = 'jet-elements-settings';

        /** @var array<string,string> */
        public $avaliable_widgets = [];

        /** @var array<string,string> */
        public $default_avaliable_extensions = [ 'section_parallax' => 'true' ];

        /** @var array<string,mixed>|null */
        public $settings = null;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function get($setting, $default = false)
        {
            if (null === $this->settings) {
                $this->settings = get_option($this->key, []);
            }
            return isset($this->settings[ $setting ]) ? $this->settings[ $setting ] : $default;
        }
    }
}
