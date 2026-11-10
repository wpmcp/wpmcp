<?php

/**
 * A stand-in for the two FlyingPress classes the cache settings pack calls
 * (issue #692), recorded from FlyingPress 5.6.6 (src/Config.php and
 * src/Preload.php): Config loads FLYING_PRESS_CONFIG into a static at
 * start-up, and update_config() merges new values in, saves the option and
 * fires flying_press_update_config:after with the new and previous config.
 * Preload::preload_cache() queues the site's URLs; here it only counts.
 *
 * Loaded only where the real FlyingPress is not (the live leg loads it).
 */

namespace FlyingPress;

if (! class_exists(Config::class)) {
    class Config
    {
        /** @var array<string, mixed> */
        public static $config = [];

        public static function update_config($new_config = [])
        {
            $prev         = self::$config;
            self::$config = array_merge(self::$config, $new_config);
            update_option('FLYING_PRESS_CONFIG', self::$config);
            do_action('flying_press_update_config:after', self::$config, $prev);
        }
    }
}

if (! class_exists(Preload::class)) {
    class Preload
    {
        public static int $runs = 0;

        public static function preload_cache()
        {
            ++self::$runs;
        }
    }
}
