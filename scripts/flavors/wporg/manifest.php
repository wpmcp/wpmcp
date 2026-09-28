<?php
/**
 * The directory cut's additions to the shared strip (scripts/flavors/wporg/
 * strip.php and policy.php). Read by strip.php when it is given no manifest,
 * which is how scripts/build-wporg-release.sh and the strip tests run it.
 *
 * Empty on purpose: everything the directory cut removes today, including
 * the self-hosted translation loader, applies to every WordPress.org
 * submission and so lives in the shared policy (issue #257). Put an entry
 * here only for something that must leave this zip and no other.
 */

declare(strict_types=1);

return [
    'label' => 'wp.org',

    /** Plugin.php method declarations deleted on top of the shared list. */
    'removed_methods' => [],

    /** Exact-string edits, [old, new, expected occurrences], per path. */
    'edits' => [],

    /** Paths removed on top of the shared policy's removed_paths. */
    'removed_paths' => [],
];
