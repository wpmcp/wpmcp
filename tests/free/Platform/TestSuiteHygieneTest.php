<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * The suite's own code stays quiet on current PHP and registers each ability
 * once (issue #534), so a real notice in gate output is not buried under
 * thousands of known ones.
 */
class TestSuiteHygieneTest extends \WP_UnitTestCase
{
    /**
     * ReflectionMethod/ReflectionProperty::setAccessible() has had no effect
     * since PHP 8.1 (the plugin's floor) and is deprecated in PHP 8.5.
     */
    public function test_no_test_file_calls_set_accessible(): void
    {
        $root  = dirname(__DIR__, 2);
        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE) as $token) {
                if (is_array($token) && T_STRING === $token[0] && 'setAccessible' === $token[1]) {
                    $calls[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $token[2];
                }
            }
        }

        $this->assertSame([], $calls, 'setAccessible() is a no-op since PHP 8.1 and deprecated in PHP 8.5; drop the call.');
    }

    /**
     * The tests guard their own registration with
     * `0 === did_action('wp_abilities_api_init')`. The WordPress test case
     * restores the action counters to a snapshot taken before the first
     * test, so the hook has to have fired by then, or every such guard fires
     * it again and every ability is registered a second time.
     */
    public function test_the_abilities_api_init_has_fired_before_any_test_runs(): void
    {
        $this->assertGreaterThanOrEqual(1, did_action('wp_abilities_api_init'));
        $this->assertTrue(wp_has_ability('wpmcp/get-page'));
    }

    public function test_the_guarded_registration_in_tests_registers_nothing_twice(): void
    {
        $duplicates = [];
        $record     = static function ($function_name, $message) use (&$duplicates): void {
            if (false !== strpos((string) $message, 'already registered')) {
                $duplicates[] = wp_strip_all_tags((string) $message);
            }
        };
        add_action('doing_it_wrong_run', $record, 1, 2);
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
        remove_action('doing_it_wrong_run', $record, 1);

        // Expected so a failure reports the list below, not the test case's
        // generic unexpected-incorrect-usage message.
        if ([] !== $duplicates) {
            $this->setExpectedIncorrectUsage('WP_Abilities_Registry::register');
        }
        $this->assertSame([], array_slice($duplicates, 0, 3), sprintf('%d abilities were registered twice.', count($duplicates)));
    }
}
