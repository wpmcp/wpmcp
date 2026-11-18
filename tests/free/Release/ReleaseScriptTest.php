<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * bin/release.sh refused every release while main carried known failing CI
 * jobs (the first real run, 1.0.1): preflight wanted every check green and
 * the pr phase merged only after `gh pr checks --fail-fast` passed. A red job
 * matching --allow-red=<regex> is reported and accepted; any other red job
 * still stops the release.
 */
class ReleaseScriptTest extends TestCase
{
    public function test_only_red_checks_outside_the_allow_pattern_stop_a_release(): void
    {
        $runs = "completed success static\n"
            . "completed failure Live legs (group 2 of 3)\n"
            . "completed failure Unit WP 7.1 multisite (1/3)\n"
            . "completed skipped docs\n";

        $this->assertSame(
            [ 'completed failure Live legs (group 2 of 3)', 'completed failure Unit WP 7.1 multisite (1/3)' ],
            $this->unexpected_red($runs, '')
        );
        $this->assertSame(
            [ 'completed failure Unit WP 7.1 multisite (1/3)' ],
            $this->unexpected_red($runs, '^Live legs')
        );
        $this->assertSame([], $this->unexpected_red($runs, '^Live legs|multisite'));
    }

    /** @return string[] */
    private function unexpected_red(string $runs, string $allow): array
    {
        $root = dirname(__DIR__, 3);
        $cmd  = 'bash ' . escapeshellarg($root . '/bin/release.sh') . ' red-filter'
            . ('' === $allow ? '' : ' --allow-red=' . escapeshellarg($allow));
        $proc = proc_open($cmd, [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $runs);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'red-filter exits 0');

        return array_values(array_filter(explode("\n", $out)));
    }
}
