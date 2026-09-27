<?php
/**
 * The directory cut's additions to the shared strip (scripts/flavors/wporg/
 * strip.php and policy.php). Read by strip.php when it is given no manifest,
 * which is how scripts/build-wporg-release.sh and the strip tests run it.
 *
 * Everything here is specific to the one zip that is certain to be served by
 * WordPress.org's language-pack pipeline. Anything that is about the paid
 * tier belongs in the shared policy instead, so every directory submission
 * gets it (issue #257).
 */

declare(strict_types=1);

return [
    'label' => 'wp.org',

    /** Plugin.php method declarations deleted on top of the shared list. */
    'removed_methods' => [
        // Not pro, but not for this build either: the directory delivers
        // language packs just in time, and I18n_Rule flags
        // load_plugin_textdomain() as unnecessary there. The off-directory
        // builds keep it (issue #184), and so does the WooCommerce vertical,
        // which ships its own languages/ for the rewritten text domain.
        'load_textdomain',
    ],

    /** Exact-string edits, [old, new, expected occurrences], per path. */
    'edits' => [
        // The self-hosted translation loader's hook goes with its method.
        'src/Plugin.php' => [
            [
                "            // Self-hosted translations from languages/ (issue #184).\n"
                    . "            add_action('init', [\$this, 'load_textdomain']);\n",
                '',
                1,
            ],
        ],
    ],

    /** Paths removed on top of the shared policy's removed_paths. */
    'removed_paths' => [],
];
