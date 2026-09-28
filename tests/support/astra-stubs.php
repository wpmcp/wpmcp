<?php
/**
 * Faithful global test double for Astra's asset cache class. The call shape is
 * taken from astra_clear_all_assets_cache() in Astra 4.x
 * (inc/theme-update/astra-update-functions.php), which does
 * `new Astra_Cache_Base('astra')` and then calls refresh_assets('astra') on the
 * INSTANCE. Loaded only by the test that needs it; a real class always wins.
 */

if (! class_exists('Astra_Cache_Base', false)) {
    class Astra_Cache_Base
    {
        /** @var array<int,string> cache directories refreshed, in call order */
        public static array $calls = [];

        private string $dir;

        public function __construct(string $dir)
        {
            $this->dir = $dir;
        }

        public function refresh_assets(string $dir): void
        {
            self::$calls[] = $dir;
        }
    }
}
