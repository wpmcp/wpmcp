<?php

/**
 * Stand-ins for the Thrive Automator classes the Thrive Automator pack uses
 * (issue #830), recorded from Thrive Automator 10.6.1.1:
 *  - the Trigger, Action, Filter and App registries (the Automation_Item
 *    trait: a static id => class map read through get());
 *  - Automation::schedule_events() and unschedule_events(), here recording
 *    each call with the post status it saw;
 *  - Hooks::launch_post_status_hooks() and launch_post_updated_hook(),
 *    which fire tap_post_publish and tap_post_updated as the real ones do.
 *
 * Loaded only where the real Thrive Automator is not.
 */

namespace Thrive\Automator\Items;

if (! class_exists('Thrive\Automator\Items\Automation')) {
    abstract class Trigger
    {
        /** @var array<string, string> */
        protected static $registered_items = [];

        public static function get(): array
        {
            return static::$registered_items;
        }

        public static function register(string $class): void
        {
            static::$registered_items[ $class::get_id() ] = $class;
        }

        public static function reset(): void
        {
            static::$registered_items = [];
        }

        public static function has_access()
        {
            return true;
        }
    }

    abstract class Action
    {
        /** @var array<string, string> */
        protected static $registered_items = [];

        public static function get(): array
        {
            return static::$registered_items;
        }

        public static function register(string $class): void
        {
            static::$registered_items[ $class::get_id() ] = $class;
        }

        public static function reset(): void
        {
            static::$registered_items = [];
        }

        public static function has_access()
        {
            return true;
        }
    }

    abstract class Filter
    {
        /** @var array<string, string> */
        protected static $registered_items = [];

        public static function get(): array
        {
            return static::$registered_items;
        }

        public static function register(string $class): void
        {
            static::$registered_items[ $class::get_id() ] = $class;
        }

        public static function reset(): void
        {
            static::$registered_items = [];
        }
    }

    abstract class App
    {
        /** @var array<string, string> */
        protected static $registered_items = [];

        public static function get(): array
        {
            return static::$registered_items;
        }

        public static function register(string $class): void
        {
            static::$registered_items[ $class::get_id() ] = $class;
        }

        public static function reset(): void
        {
            static::$registered_items = [];
        }
    }

    class Automation
    {
        const POST_TYPE = 'tve_automation';

        /** @var list<array{0: string, 1: int, 2: string}> */
        public static $calls = [];

        final public static function unschedule_events(int $automation_id)
        {
            self::$calls[] = [ 'unschedule', $automation_id, (string) get_post_status($automation_id) ];
        }

        final public static function schedule_events(int $automation_id)
        {
            self::$calls[] = [ 'schedule', $automation_id, (string) get_post_status($automation_id) ];
        }
    }

    // Registered items, as Thrive Automator 10.6.1.1 names them.
    class Wordpress_Post_Publish extends Trigger
    {
        public static function get_id()
        {
            return 'wordpress/postpublish';
        }

        public static function get_name()
        {
            return 'Post is published';
        }

        public static function get_description()
        {
            return 'This trigger will be fired whenever a post is published';
        }

        public static function get_app_id()
        {
            return 'wordpress';
        }
    }

    class Wordpress_Webhook_Receive extends Trigger
    {
        public static function get_id()
        {
            return 'wordpress/webhook_receive';
        }

        public static function get_name()
        {
            return 'Incoming webhook';
        }

        public static function get_description()
        {
            return 'Receive data from an external source';
        }

        public static function get_app_id()
        {
            return 'general';
        }
    }

    class Webhook extends Action
    {
        public static function get_id()
        {
            return 'general/webhook';
        }

        public static function get_name()
        {
            return 'Send a webhook';
        }

        public static function get_description()
        {
            return 'Send data to an external URL';
        }

        public static function get_app_id()
        {
            return 'general';
        }
    }

    class Wordpress_Add_User_Role extends Action
    {
        public static function get_id()
        {
            return 'wordpress/add_user_role';
        }

        public static function get_name()
        {
            return 'Add user role';
        }

        public static function get_description()
        {
            return 'Add a role to the user';
        }

        public static function get_app_id()
        {
            return 'wordpress';
        }
    }

    class Text_Filter extends Filter
    {
        public static function get_id()
        {
            return 'text_filter';
        }

        public static function get_name()
        {
            return 'Text';
        }
    }

    class Wordpress_App extends App
    {
        public static function get_id()
        {
            return 'wordpress';
        }

        public static function get_name()
        {
            return 'WordPress';
        }

        public static function get_description()
        {
            return 'WordPress core';
        }
    }
}

namespace Thrive\Automator;

if (! class_exists('Thrive\Automator\Hooks')) {
    class Hooks
    {
        public static function launch_post_status_hooks($new_status, $old_status, $post)
        {
            if ('publish' === $new_status && 'publish' !== $old_status) {
                do_action('tap_post_publish', $post);
            }
        }

        public static function launch_post_updated_hook($post_id)
        {
            do_action('tap_post_updated', $post_id);
        }
    }
}
