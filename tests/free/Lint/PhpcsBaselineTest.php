<?php

namespace WPMCP\Tests\Free\Lint;

use PHPUnit\Framework\TestCase;
use WPMCP\Lint\Phpcs_Baseline;

/**
 * The WordPressCS ratchet (issue #185). Two aggregate integers cannot express
 * "new violations fail the build": a PR that fixes one escaping error and
 * introduces one unprepared query nets zero, and deleting a file with ten
 * findings buys ten units of headroom for brand new ones. So the baseline is
 * keyed per sniff code and every code is compared on its own.
 */
class PhpcsBaselineTest extends TestCase
{
    /**
     * Shape of a phpcs --report=json payload, trimmed to the fields the
     * ratchet reads.
     *
     * @param array<string, array<int, string>> $files map of path to sniff sources
     * @return array<string, mixed>
     */
    private function report(array $files): array
    {
        $out = ['totals' => ['errors' => 0, 'warnings' => 0, 'fixable' => 0], 'files' => []];

        foreach ($files as $path => $sources) {
            $messages = [];
            foreach ($sources as $source) {
                $type = str_contains($source, 'Warning') ? 'WARNING' : 'ERROR';
                $messages[] = ['message' => 'x', 'source' => $source, 'severity' => 5, 'type' => $type, 'line' => 1];
                $out['totals'][$type === 'ERROR' ? 'errors' : 'warnings']++;
            }
            $out['files'][$path] = ['errors' => 0, 'warnings' => 0, 'messages' => $messages];
        }

        return $out;
    }

    public function test_counts_are_keyed_by_sniff_code_not_totalled(): void
    {
        $counts = Phpcs_Baseline::counts_from_report($this->report([
            'src/A.php' => ['WordPress.Security.EscapeOutput.OutputNotEscaped', 'WordPress.DB.PreparedSQL.NotPrepared'],
            'src/B.php' => ['WordPress.Security.EscapeOutput.OutputNotEscaped'],
        ]));

        $this->assertSame(
            [
                'WordPress.DB.PreparedSQL.NotPrepared' => 1,
                'WordPress.Security.EscapeOutput.OutputNotEscaped' => 2,
            ],
            $counts
        );
    }

    public function test_counts_are_sorted_so_the_committed_file_has_a_stable_diff(): void
    {
        $counts = Phpcs_Baseline::counts_from_report($this->report([
            'src/A.php' => ['Zeta.Sniff.Code', 'Alpha.Sniff.Code'],
        ]));

        $this->assertSame(['Alpha.Sniff.Code', 'Zeta.Sniff.Code'], array_keys($counts));
    }

    public function test_an_unchanged_tree_passes(): void
    {
        $baseline = ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 2];
        $result = Phpcs_Baseline::compare($baseline, $baseline);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['regressions']);
    }

    public function test_a_swap_that_nets_zero_still_fails(): void
    {
        $result = Phpcs_Baseline::compare(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 2, 'WordPress.DB.PreparedSQL.NotPrepared' => 1],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 1, 'WordPress.DB.PreparedSQL.NotPrepared' => 2]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            ['WordPress.DB.PreparedSQL.NotPrepared' => ['baseline' => 1, 'current' => 2]],
            $result['regressions']
        );
    }

    public function test_a_brand_new_sniff_code_fails_even_when_the_total_drops(): void
    {
        $result = Phpcs_Baseline::compare(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 10],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 1, 'WordPress.DB.PreparedSQL.NotPrepared' => 1]
        );

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('WordPress.DB.PreparedSQL.NotPrepared', $result['regressions']);
        $this->assertSame(0, $result['regressions']['WordPress.DB.PreparedSQL.NotPrepared']['baseline']);
    }

    public function test_deleting_a_file_does_not_buy_headroom_for_a_different_sniff(): void
    {
        // Ten escaping errors disappear with the file; one new unprepared query
        // arrives. The old total-based gate saw -9 and passed.
        $result = Phpcs_Baseline::compare(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 10],
            ['WordPress.DB.PreparedSQL.NotPrepared' => 1]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(['WordPress.DB.PreparedSQL.NotPrepared'], array_keys($result['regressions']));
    }

    public function test_improvements_are_reported_so_the_baseline_can_be_ratcheted_down(): void
    {
        $result = Phpcs_Baseline::compare(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 10, 'Gone.Sniff.Code' => 3],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 4]
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(
            [
                'Gone.Sniff.Code' => ['baseline' => 3, 'current' => 0],
                'WordPress.Security.EscapeOutput.OutputNotEscaped' => ['baseline' => 10, 'current' => 4],
            ],
            $result['improvements']
        );
    }

    public function test_update_refuses_to_raise_a_count_without_force(): void
    {
        $verdict = Phpcs_Baseline::guard_update(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 1],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 2],
            false
        );

        $this->assertFalse($verdict['allowed']);
        $this->assertArrayHasKey('WordPress.Security.EscapeOutput.OutputNotEscaped', $verdict['regressions']);
    }

    public function test_update_allows_a_raise_only_with_an_explicit_force(): void
    {
        $verdict = Phpcs_Baseline::guard_update(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 1],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 2],
            true
        );

        $this->assertTrue($verdict['allowed']);
    }

    public function test_update_always_allows_a_ratchet_down(): void
    {
        $verdict = Phpcs_Baseline::guard_update(
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 9],
            ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 4],
            false
        );

        $this->assertTrue($verdict['allowed']);
    }

    public function test_update_allows_the_very_first_baseline(): void
    {
        $verdict = Phpcs_Baseline::guard_update(null, ['Some.Sniff.Code' => 7], false);

        $this->assertTrue($verdict['allowed']);
    }

    public function test_a_legacy_totals_only_baseline_is_rejected_rather_than_silently_passing(): void
    {
        // The first cut of this file committed {"errors":246,"warnings":0}.
        // Reading that as a sniff map would yield the codes "errors" and
        // "warnings", so every real sniff would look brand new; better to say so.
        $this->expectException(\RuntimeException::class);
        Phpcs_Baseline::counts_from_baseline(['errors' => 246, 'warnings' => 0]);
    }

    public function test_a_sniff_keyed_baseline_round_trips(): void
    {
        $counts = ['WordPress.Security.EscapeOutput.OutputNotEscaped' => 2];
        $encoded = Phpcs_Baseline::encode($counts);

        $this->assertSame($counts, Phpcs_Baseline::counts_from_baseline(json_decode($encoded, true)));
        $this->assertStringEndsWith("\n", $encoded);
    }

    public function test_the_committed_baseline_is_in_the_sniff_keyed_format(): void
    {
        $path = dirname(__DIR__, 3) . '/.phpcs-baseline.json';
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $counts = Phpcs_Baseline::counts_from_baseline($decoded);

        $this->assertNotSame([], $counts);
        foreach ($counts as $code => $count) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)+$/', $code);
            $this->assertIsInt($count);
        }
    }
}
