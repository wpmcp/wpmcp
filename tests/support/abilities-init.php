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
 *
 * That build runs outside PHPUnit's per-test coverage window, so the listener
 * measures it itself (issue #564) and hands the data to the run's coverage
 * report, under the id "abilities-init", when the first test starts.
 * Otherwise the registration code, and the file header of every tool class it
 * is the first to load, would be reported as never executed.
 */

namespace WPMCP\Tests\Support;

final class Abilities_Init_Listener implements \PHPUnit\Framework\TestListener
{
    use \PHPUnit\Framework\TestListenerDefaultImplementation;

    /** The coverage id the registration is reported under. */
    public const COVERAGE_ID = 'abilities-init';

    private static bool $fired = false;

    /** Line coverage of the registration, until the first test hands it on. */
    private static ?\SebastianBergmann\CodeCoverage\RawCodeCoverageData $measured = null;

    public function startTestSuite(\PHPUnit\Framework\TestSuite $suite): void
    {
        if (self::$fired || ! function_exists('wp_get_abilities')) {
            return;
        }
        self::$fired = true;

        $driver = self::coverage_driver();
        if (null !== $driver) {
            $driver->start();
        }
        try {
            wp_get_abilities();
        } finally {
            if (null !== $driver) {
                self::$measured = $driver->stop();
            }
        }
    }

    public function startTest(\PHPUnit\Framework\Test $test): void
    {
        if (null === self::$measured || ! $test instanceof \PHPUnit\Framework\TestCase) {
            return;
        }
        $result   = $test->getTestResultObject();
        $coverage = null === $result ? null : $result->getCodeCoverage();
        if (null !== $coverage) {
            $coverage->append(self::$measured, self::COVERAGE_ID);
        }
        // A run without coverage drops the measurement on its first test.
        self::$measured = null;
    }

    /** A line coverage driver over src/, or null when PHP has none (pcov, Xdebug). */
    private static function coverage_driver(): ?\SebastianBergmann\CodeCoverage\Driver\Driver
    {
        if (! class_exists(\SebastianBergmann\CodeCoverage\Driver\Selector::class)) {
            return null;
        }
        try {
            $filter = new \SebastianBergmann\CodeCoverage\Filter();
            $filter->includeDirectory(dirname(__DIR__, 2) . '/src');
            return (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
