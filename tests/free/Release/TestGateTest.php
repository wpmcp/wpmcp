<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * Issue #488: the readme says the suite runs on the newest WordPress and on
 * the `Requires at least` floor on every change, but the pre-push hook ran
 * bin/test-local.sh without --all, so the floor leg never ran before a push
 * and went red unnoticed. The gate's plain run (what the hook and the merge
 * queue run) must include the floor leg, and a failing leg must not quietly
 * skip the legs after it.
 *
 * `bin/test-local.sh --plan` prints the legs a run would execute, one per
 * line, without starting a database or installing WordPress.
 */
class TestGateTest extends TestCase
{
    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] the leg lines of `bin/test-local.sh --plan <args>`. */
    private function plan(string ...$args): array
    {
        $command = 'bash ' . escapeshellarg($this->repository() . '/bin/test-local.sh') . ' --plan';
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, "--plan failed:\n" . implode("\n", $output));

        return array_values(array_filter($output, static fn (string $line): bool => str_starts_with($line, 'leg: ')));
    }

    private function requires_at_least(): string
    {
        $readme = (string) file_get_contents($this->repository() . '/readme.txt');
        $this->assertSame(1, preg_match('/^Requires at least:\s*(\S+)/m', $readme, $m));

        return $m[1];
    }

    private function default_wordpress(): string
    {
        $script = (string) file_get_contents($this->repository() . '/bin/test-local.sh');
        $this->assertSame(1, preg_match('/^WP_VERSIONS="(\S+)/m', $script, $m));

        return $m[1];
    }

    public function test_a_plain_run_covers_the_default_leg_with_coverage_and_the_requires_floor(): void
    {
        $plan  = $this->plan();
        $floor = $this->requires_at_least();

        $this->assertContains('leg: lint', $plan);
        $this->assertContains('leg: drift', $plan);
        $this->assertContains('leg: phpunit wp=' . $this->default_wordpress() . ' coverage=floor', $plan);
        $this->assertContains('leg: phpunit wp=' . $floor . ' coverage=none', $plan, 'the plain run (pre-push hook, merge queue gate) must run the Requires at least floor');
        $this->assertContains('leg: live-forms wp=' . $this->default_wordpress(), $plan);
    }

    public function test_the_floor_leg_runs_before_the_live_groups_and_both_run_on_failure(): void
    {
        $plan  = $this->plan();
        $floor = array_search('leg: phpunit wp=' . $this->requires_at_least() . ' coverage=none', $plan, true);
        $live  = array_search('leg: live-forms wp=' . $this->default_wordpress(), $plan, true);

        $this->assertIsInt($floor);
        $this->assertIsInt($live);
        $this->assertLessThan($live, $floor);
        exec('bash ' . escapeshellarg($this->repository() . '/bin/test-local.sh') . ' --plan 2>&1', $output);
        $this->assertContains('on-failure: continue', $output, 'a failing leg must not stop the legs after it');
    }

    /** A targeted run (the merge queue's fast re-check) stays on the single-site default leg. */
    public function test_a_targeted_run_stays_on_the_default_leg(): void
    {
        $this->assertSame(
            ['leg: phpunit wp=' . $this->default_wordpress() . ' coverage=none'],
            $this->plan('--', '--filter', 'SomeTest')
        );
    }

    /**
     * Issue #501: the leg that runs the suite on the released MCP Adapter
     * plugin. It runs alone under --live-adapter and as part of --all, not in
     * the plain gate: it repeats the whole suite, which would add minutes to
     * every push for a dependency that changes only when it is released.
     */
    public function test_the_adapter_leg_runs_alone_and_under_all_but_not_in_the_plain_gate(): void
    {
        $leg = 'leg: live-adapter wp=' . $this->default_wordpress();

        $this->assertSame([$leg], $this->plan('--live-adapter'));
        $this->assertContains($leg, $this->plan('--all'));
        $this->assertNotContains($leg, $this->plan(), 'the plain gate leaves the adapter leg to --all');
    }

    public function test_the_adapter_leg_runs_the_released_plugin_and_its_coexistence_checks(): void
    {
        $script = (string) file_get_contents($this->repository() . '/bin/test-local.sh');

        $this->assertTrue(str_contains($script, 'https://downloads.wordpress.org/plugin/mcp-adapter.'), 'the leg downloads the released plugin from WordPress.org');
        $this->assertTrue(str_contains($script, 'WPMCP_TEST_ADAPTER_PLUGIN='), 'the leg points the bootstrap at the downloaded plugin');
        $this->assertTrue(str_contains($script, '--group adapter-live --fail-on-skipped'), 'the coexistence checks must not pass by skipping');
    }

    /**
     * Issue #530: no leg ran on multisite, so network-only bugs (a sub-site
     * admin reaching network tables, sub-sites missing plugin tables) went
     * unnoticed. The multisite leg runs the suite with WP_MULTISITE=1 on the
     * default WordPress, without coverage, in the plain gate, and alone under
     * --multisite.
     */
    public function test_the_multisite_leg_runs_in_the_plain_gate_and_alone(): void
    {
        $leg = 'leg: multisite wp=' . $this->default_wordpress() . ' coverage=none';

        $this->assertContains($leg, $this->plan(), 'the plain gate (pre-push hook, merge queue) must run the suite on multisite');
        $this->assertContains($leg, $this->plan('--all'));
        $this->assertSame([$leg], $this->plan('--multisite'));
        $this->assertSame([$leg], $this->plan('--multisite', '--', '--filter', 'SomeTest'), 'a targeted multisite run runs only that leg');
    }

    public function test_the_multisite_leg_runs_before_the_live_groups(): void
    {
        $plan      = $this->plan();
        $multisite = array_search('leg: multisite wp=' . $this->default_wordpress() . ' coverage=none', $plan, true);
        $live      = array_search('leg: live-forms wp=' . $this->default_wordpress(), $plan, true);

        $this->assertIsInt($multisite);
        $this->assertIsInt($live);
        $this->assertLessThan($live, $multisite);
    }

    public function test_the_multisite_leg_tells_the_test_library_to_install_a_network(): void
    {
        $script = (string) file_get_contents($this->repository() . '/bin/test-local.sh');

        $this->assertTrue(str_contains($script, 'WP_MULTISITE=1'), 'the WordPress test library installs multisite only when WP_MULTISITE=1 is exported');
    }

    public function test_the_pre_push_hook_runs_the_plain_gate(): void
    {
        $hook = (string) file_get_contents($this->repository() . '/.githooks/pre-push');

        $this->assertMatchesRegularExpression('#^exec bin/test-local\.sh </dev/null$#m', $hook);
    }
}
