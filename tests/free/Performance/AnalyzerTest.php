<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Analyzer;
use WPMCP\Tools\Performance\Finding;
use WPMCP\Tools\Performance\Page_Audit;
use WPMCP\Tools\Performance\Server_Audit;

class AnalyzerTest extends \WP_UnitTestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new Analyzer();
    }

    private function finding(string $status, string $recommendation = ''): array
    {
        return Finding::make('x', 'server', 'X', $status, 1, 'm', $recommendation);
    }

    public function test_summarize_counts_and_scores(): void
    {
        $findings = [
            $this->finding('critical', 'fix me'),
            $this->finding('warning', 'maybe'),
            $this->finding('warning', 'maybe2'),
            $this->finding('pass'),
            $this->finding('info'),
        ];

        $summary = $this->analyzer->summarize($findings);

        $this->assertSame(1, $summary['counts']['critical']);
        $this->assertSame(2, $summary['counts']['warning']);
        $this->assertSame(1, $summary['counts']['pass']);
        $this->assertSame(1, $summary['counts']['info']);
        // 100 - (1 * 15) - (2 * 4) = 77, grade C.
        $this->assertSame(77, $summary['score']);
        $this->assertSame('C', $summary['grade']);
    }

    public function test_score_clamps_at_zero(): void
    {
        $findings = array_fill(0, 10, $this->finding('critical', 'x'));

        $summary = $this->analyzer->summarize($findings);

        $this->assertSame(0, $summary['score']);
        $this->assertSame('F', $summary['grade']);
    }

    public function test_perfect_score_is_grade_a(): void
    {
        $summary = $this->analyzer->summarize([$this->finding('pass'), $this->finding('info')]);

        $this->assertSame(100, $summary['score']);
        $this->assertSame('A', $summary['grade']);
    }

    public function test_grade_bands_at_exact_boundaries(): void
    {
        // 100 - (2 warnings * 4) = 92 -> A (>= 90).
        $summary = $this->analyzer->summarize(array_fill(0, 2, $this->finding('warning')));
        $this->assertSame(92, $summary['score']);
        $this->assertSame('A', $summary['grade']);

        // 100 - (5 warnings * 4) = 80 -> B (>= 80).
        $summary = $this->analyzer->summarize(array_fill(0, 5, $this->finding('warning')));
        $this->assertSame(80, $summary['score']);
        $this->assertSame('B', $summary['grade']);

        // 100 - (2 critical * 15) = 70 -> C (>= 70), exact lower boundary.
        $summary = $this->analyzer->summarize(array_fill(0, 2, $this->finding('critical')));
        $this->assertSame(70, $summary['score']);
        $this->assertSame('C', $summary['grade']);

        // 100 - (10 warnings * 4) = 60 -> D (>= 60), exact lower boundary.
        $summary = $this->analyzer->summarize(array_fill(0, 10, $this->finding('warning')));
        $this->assertSame(60, $summary['score']);
        $this->assertSame('D', $summary['grade']);

        // 100 - (1 critical * 15) - (12 warnings * 4) = 100 - 15 - 48 = 37 -> F.
        // Confirms crossing just below the D floor grades F.
        $findings = array_merge(
            array_fill(0, 1, $this->finding('critical')),
            array_fill(0, 12, $this->finding('warning'))
        );
        $summary = $this->analyzer->summarize($findings);
        $this->assertSame(37, $summary['score']);
        $this->assertSame('F', $summary['grade']);
    }

    public function test_top_recommendations_rank_critical_before_warning(): void
    {
        $findings = [
            $this->finding('warning', 'warn rec'),
            $this->finding('critical', 'crit rec'),
            $this->finding('pass'),
        ];

        $summary = $this->analyzer->summarize($findings);

        $this->assertNotEmpty($summary['top_recommendations']);
        $this->assertStringContainsString('crit rec', $summary['top_recommendations'][0]);
        $this->assertStringContainsString('warn rec', $summary['top_recommendations'][1]);
    }

    public function test_top_recommendations_are_formatted_with_label_and_capped_at_eight(): void
    {
        $findings = [];
        for ($i = 0; $i < 10; $i++) {
            $findings[] = Finding::make('id' . $i, 'server', 'Label ' . $i, 'critical', 1, 'm', 'rec ' . $i);
        }

        $summary = $this->analyzer->summarize($findings);

        $this->assertCount(8, $summary['top_recommendations']);
        $this->assertSame('[Label 0] rec 0', $summary['top_recommendations'][0]);
    }

    public function test_top_recommendations_skip_findings_without_a_recommendation(): void
    {
        $findings = [$this->finding('warning', ''), $this->finding('pass')];

        $summary = $this->analyzer->summarize($findings);

        $this->assertSame([], $summary['top_recommendations']);
    }

    public function test_validate_same_host_accepts_matching_host_and_rejects_others(): void
    {
        $this->assertTrue($this->analyzer->validate_same_host('https://example.com/page', 'example.com'));
        $this->assertFalse($this->analyzer->validate_same_host('https://evil.test/page', 'example.com'));
    }

    public function test_analyze_defaults_to_the_frontpage_and_merges_server_and_page_findings(): void
    {
        $server = $this->createMock(Server_Audit::class);
        $server->method('run')->willReturn([$this->finding('pass')]);

        $page = $this->createMock(Page_Audit::class);
        $page->method('fetch')->willReturn(['ok' => true, 'status_code' => 200, 'response_ms' => 10, 'total_bytes' => 5, 'headers' => [], 'body' => '', 'error' => null, 'host' => 'example.org']);
        $page->method('analyze')->willReturn([
            'findings'   => [Finding::make('http_status', 'page', 'HTTP status', 'pass', 200, 'ok')],
            'page_fetch' => ['ok' => true, 'status_code' => 200, 'response_ms' => 10, 'total_bytes' => 5, 'error' => null],
        ]);

        $analyzer = new Analyzer($server, $page);
        $result   = $analyzer->analyze([]);

        $this->assertTrue($result['target']['is_front_page']);
        $this->assertNull($result['target']['post_id']);
        $this->assertSame(100, $result['summary']['score']);
        $this->assertSame('A', $result['summary']['grade']);
        $this->assertCount(1, $result['sections']['server']);
        $this->assertCount(1, $result['sections']['page']);
        $this->assertTrue($result['page_fetch']['ok']);
        $this->assertSame([], $result['top_recommendations']);
    }

    public function test_analyze_rejects_an_off_host_url(): void
    {
        $analyzer = new Analyzer(new Server_Audit(), new Page_Audit());

        $result = $analyzer->analyze(['url' => 'https://evil.test/page']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_target', $result->get_error_code());
    }

    public function test_analyze_skips_the_page_fetch_when_include_page_fetch_is_false(): void
    {
        $server = $this->createMock(Server_Audit::class);
        $server->method('run')->willReturn([$this->finding('pass')]);

        $page = $this->createMock(Page_Audit::class);
        $page->expects($this->never())->method('fetch');
        $page->expects($this->never())->method('analyze');

        $analyzer = new Analyzer($server, $page);
        $result   = $analyzer->analyze(['include_page_fetch' => false]);

        $this->assertSame('not_requested', $result['page_fetch']['error']);
        $this->assertSame([], $result['sections']['page']);
    }

    public function test_analyze_resolves_a_post_id_target(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);

        $server = $this->createMock(Server_Audit::class);
        $server->method('run')->willReturn([$this->finding('pass')]);

        $page = $this->createMock(Page_Audit::class);
        $page->method('fetch')->willReturn(['ok' => false, 'status_code' => 0, 'response_ms' => 0, 'total_bytes' => 0, 'headers' => [], 'body' => '', 'error' => 'not_requested', 'host' => '']);
        $page->method('analyze')->willReturn([
            'findings'   => [],
            'page_fetch' => ['ok' => false, 'status_code' => 0, 'response_ms' => 0, 'total_bytes' => 0, 'error' => 'not_requested'],
        ]);

        $analyzer = new Analyzer($server, $page);
        $result   = $analyzer->analyze(['post_id' => $post_id]);

        $this->assertSame($post_id, $result['target']['post_id']);
        $this->assertFalse($result['target']['is_front_page']);
    }
}
