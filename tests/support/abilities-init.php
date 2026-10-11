<?php
/**
 * Fires wp_abilities_api_init exactly once per run, before the first test
 * class starts (issue #534).
 *
 * Many test classes guard their registration with
 * `if ( 0 === did_action( 'wp_abilities_api_init' ) ) do_action( ... )`.
 * The WordPress test case snapshots the action counters when the first test
 * sets up and restores them after every test, so a hook first fired from
 * inside a test class counted as never fired for the next class. Every such
 * guard then fired it again and every ability was registered a second time
 * (about 18,000 "already registered" notices per run).
 *
 * The listener builds the abilities registry when the outermost suite starts:
 * after every test file is loaded (so the harness doubles some tests define,
 * such as the JetEngine and Pods stand-ins, are present, as they were when the
 * first class fired the hook) and before any setUpBeforeClass or snapshot.
 * Going through the registry, not a bare do_action(), also avoids the nested
 * fire core's lazy registry construction adds to the first registration.
 */

namespace WPMCP\Tests\Support;

final class Abilities_Init_Listener implements \PHPUnit\Framework\TestListener
{
    use \PHPUnit\Framework\TestListenerDefaultImplementation;

    private static bool $fired = false;

    public function startTestSuite(\PHPUnit\Framework\TestSuite $suite): void
    {
        if (self::$fired || ! function_exists('wp_get_abilities')) {
            return;
        }
        self::$fired = true;
        wp_get_abilities();
    }
}
