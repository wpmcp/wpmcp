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

    public function test_a_targeted_run_stays_on_the_default_leg(): void
    {
        $this->assertSame(
            ['leg: phpunit wp=' . $this->default_wordpress() . ' coverage=none'],
            $this->plan('--', '--filter', 'SomeTest')
        );
    }

    public function test_the_pre_push_hook_runs_the_plain_gate(): void
    {
        $hook = (string) file_get_contents($this->repository() . '/.githooks/pre-push');

        $this->assertMatchesRegularExpression('#^exec bin/test-local\.sh </dev/null$#m', $hook);
    }
}
