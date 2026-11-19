<?php
/**
 * Faithful double of Blocksy Companion's extension manager (issue #650),
 * verified against Blocksy Companion 2.1.62 (wordpress.org):
 * Blocksy\ExtensionsManager keeps the active extension ids in the
 * blocksy_active_extensions option. activate_extension() appends the id,
 * deactivate_extension() removes it, and both fire
 * blocksy:dynamic-css:refresh-caches; either returns early for an id it has
 * no path for (a Pro extension listed only from the preliminary config).
 * get_extensions(['require_config' => true]) returns id => [path, config],
 * config holding name, description, pro and hidden.
 *
 * It is handed to the pack through wpmcp_blocksy_extensions_manager, so no
 * class in the Blocksy namespace is defined and real Blocksy always wins.
 */

if (! class_exists('WPMCP_Test_Blocksy_Extensions_Manager', false)) {
    class WPMCP_Test_Blocksy_Extensions_Manager
    {
        /** @var array<string,array<string,mixed>> */
        public $extensions = [];

        /** @var string[] ids whose activation or deactivation ran, in order */
        public $calls = [];

        public function __construct()
        {
            $free = [
                'cookies-consent'      => 'Cookies Consent',
                'newsletter-subscribe' => 'Newsletter Subscribe',
                'product-reviews'      => 'Product Reviews',
                'trending'             => 'Trending Posts',
            ];
            foreach ($free as $id => $name) {
                $this->extensions[ $id ] = [
                    'path'     => '/blocksy-companion/framework/extensions/' . $id,
                    '__object' => null,
                    'config'   => [ 'name' => $name, 'description' => $name . ' extension.', 'pro' => false, 'hidden' => false, 'icon' => '<svg></svg>' ],
                    'data'     => null,
                ];
            }
            // Listed from the preliminary config on the free plan, no path.
            $this->extensions['mega-menu'] = [
                'path'     => null,
                '__object' => null,
                'config'   => [ 'name' => 'Advanced Menu', 'description' => 'Mega menus.', 'pro' => true, 'hidden' => false ],
                'readme'   => '',
                'data'     => null,
            ];
        }

        public function get_extensions($args = [])
        {
            return $this->extensions;
        }

        private function get_activated_extensions()
        {
            $opt = get_option('blocksy_active_extensions', []);
            return is_array($opt) ? $opt : [];
        }

        public function activate_extension($id)
        {
            if (! isset($this->extensions[ $id ]) || ! $this->extensions[ $id ]['path']) {
                return;
            }
            $this->calls[] = 'activate:' . $id;
            $activated     = $this->get_activated_extensions();
            $activated[]   = strtolower($id);
            update_option('blocksy_active_extensions', array_unique($activated));
            do_action('blocksy:dynamic-css:refresh-caches');
        }

        public function deactivate_extension($id)
        {
            if (! isset($this->extensions[ $id ]) || ! $this->extensions[ $id ]['path']) {
                return;
            }
            $this->calls[] = 'deactivate:' . $id;
            update_option('blocksy_active_extensions', array_diff($this->get_activated_extensions(), [ $id ]));
            do_action('blocksy:dynamic-css:refresh-caches');
        }
    }
}
