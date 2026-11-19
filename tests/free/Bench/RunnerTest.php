<?php

namespace WPMCP\Tests\Free\Bench;

use PHPUnit\Framework\TestCase;
use WPMCP\Bench\Runner;
use WPMCP\Bench\Surface;

require_once dirname(__DIR__, 3) . '/tools/bench/Runner.php';
require_once dirname(__DIR__, 3) . '/tools/bench/Surface.php';

/**
 * The code mode benchmark's counting and sequencing (issue #1130): what the
 * runner reports is what every later code mode PR is measured against, so
 * the counting is pinned here against a fake transport, no WordPress
 * involved.
 */
class RunnerTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $sent = [];

    /**
     * A transport that answers from a table keyed on the tool name and
     * records what it was sent.
     *
     * @param array<string, callable(array): mixed> $answers
     */
    private function transport(array $answers): callable
    {
        return function (string $tool, array $args) use ($answers): array {
            $this->sent[] = [$tool, $args];
            $value = isset($answers[ $tool ]) ? ($answers[ $tool ])($args) : ['ok' => true];
            $json  = (string) json_encode($value);
            return ['value' => $value, 'bytes' => strlen($json), 'error' => null];
        };
    }

    public function test_counts_calls_turns_and_result_bytes(): void
    {
        $runner = new Runner($this->transport([
            'a/one' => fn() => ['id' => 7],
            'a/two' => fn() => ['x' => 'yy'],
        ]));

        $out = $runner->run([
            ['tool' => 'a/one', 'args' => []],
            ['tool' => 'a/two', 'args' => ['q' => 1]],
        ]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(2, $out['calls']);
        $this->assertSame(2, $out['turns']);
        $this->assertSame(strlen('{"id":7}') + strlen('{"x":"yy"}'), $out['result_bytes']);
        $this->assertCount(2, $out['steps']);
        $this->assertSame('a/one', $out['steps'][0]['tool']);
        $this->assertSame(1, $out['steps'][0]['calls']);
        $this->assertGreaterThanOrEqual(0, $out['ms']);
    }

    public function test_a_later_step_reads_an_earlier_result_through_its_closure(): void
    {
        $runner = new Runner($this->transport([
            'a/find' => fn() => ['items' => [['id' => 3], ['id' => 9]]],
            'a/act'  => fn(array $args) => ['did' => $args['id']],
        ]));

        $out = $runner->run([
            ['tool' => 'a/find', 'args' => [], 'as' => 'found'],
            ['tool' => 'a/act', 'args' => fn(array $r) => ['id' => $r['found']['items'][1]['id']], 'as' => 'acted'],
        ]);

        $this->assertSame(['a/act', ['id' => 9]], $this->sent[1]);
        $this->assertSame(['did' => 9], $out['state']['acted']);
    }

    public function test_a_fan_out_step_is_one_turn_of_many_calls_and_keeps_every_result(): void
    {
        $runner = new Runner($this->transport([
            'a/list' => fn() => [1, 2, 3],
            'a/each' => fn(array $args) => ['n' => $args['n'] * 10],
        ]));

        $out = $runner->run([
            ['tool' => 'a/list', 'args' => [], 'as' => 'ids'],
            ['tool' => 'a/each', 'each' => fn(array $r) => array_map(fn($n) => ['n' => $n], $r['ids']), 'as' => 'done'],
        ]);

        $this->assertSame(4, $out['calls']);
        $this->assertSame(2, $out['turns']);
        $this->assertSame([['n' => 10], ['n' => 20], ['n' => 30]], $out['state']['done']);
        $this->assertSame(3, $out['steps'][1]['calls']);
    }

    public function test_an_empty_fan_out_costs_no_call_and_no_turn(): void
    {
        $runner = new Runner($this->transport([]));

        $out = $runner->run([
            ['tool' => 'a/one', 'args' => []],
            ['tool' => 'a/each', 'each' => fn() => [], 'as' => 'none'],
        ]);

        $this->assertSame(1, $out['calls']);
        $this->assertSame(1, $out['turns']);
        $this->assertSame([], $out['state']['none']);
    }

    public function test_an_error_result_stops_the_run_and_is_reported_not_counted_as_success(): void
    {
        $runner = new Runner(function (string $tool, array $args): array {
            $this->sent[] = [$tool, $args];
            return 'a/bad' === $tool
                ? ['value' => null, 'bytes' => 40, 'error' => 'refused: no']
                : ['value' => ['ok' => 1], 'bytes' => 8, 'error' => null];
        });

        $out = $runner->run([
            ['tool' => 'a/one', 'args' => []],
            ['tool' => 'a/bad', 'args' => []],
            ['tool' => 'a/never', 'args' => []],
        ]);

        $this->assertSame('failed', $out['status']);
        $this->assertStringContainsString('a/bad', $out['reason']);
        $this->assertStringContainsString('refused: no', $out['reason']);
        $this->assertSame(2, $out['calls']);
        $this->assertSame(48, $out['result_bytes']);
        $this->assertCount(2, $this->sent);
    }

    public function test_a_closure_that_throws_fails_the_run_with_its_message(): void
    {
        $runner = new Runner($this->transport([]));

        $out = $runner->run([
            ['tool' => 'a/one', 'args' => fn() => throw new \RuntimeException('no newest order')],
        ]);

        $this->assertSame('failed', $out['status']);
        $this->assertStringContainsString('no newest order', $out['reason']);
        $this->assertSame(0, $out['calls']);
    }

    public function test_a_step_without_a_tool_or_with_both_args_and_each_is_refused(): void
    {
        $runner = new Runner($this->transport([]));

        $this->assertSame('failed', $runner->run([['args' => []]])['status']);
        $this->assertSame('failed', $runner->run([['tool' => 'a/x', 'args' => [], 'each' => fn() => []]])['status']);
    }

    // ------------------------------------------------------------ compact

    /** list-tools answers with the names whose name contains the search term. */
    private function catalog_transport(array $names): callable
    {
        return $this->transport([
            'wpmcp/list-tools'      => fn(array $args) => [
                'tools' => array_values(array_map(
                    fn($n) => ['name' => $n],
                    array_filter($names, fn($n) => str_contains($n, (string) $args['search']))
                )),
            ],
            'wpmcp/get-tool-schema' => fn(array $args) => ['name' => $args['name'], 'input_schema' => []],
            'wpmcp/call-tool'       => fn(array $args) => ['called' => $args['name'], 'with' => $args['arguments']],
        ]);
    }

    public function test_compact_adds_one_search_turn_and_one_schema_turn_then_wraps_every_call(): void
    {
        $steps = Surface::compact(['find', 'act'], [
            ['tool' => 'wpmcp/find-things', 'args' => ['q' => 1], 'as' => 'found'],
            ['tool' => 'wpmcp/act-on', 'each' => fn(array $r) => [['id' => 1], ['id' => 2]], 'as' => 'acted'],
            ['tool' => 'wpmcp/find-things', 'args' => fn(array $r) => ['q' => count($r['acted'])]],
        ]);

        $out = (new Runner($this->catalog_transport(['wpmcp/find-things', 'wpmcp/act-on', 'wpmcp/other'])))->run($steps);

        $this->assertSame('ok', $out['status'], (string) ($out['reason'] ?? ''));
        // 2 searches + 2 schemas (distinct tools, in order of first use) + 4 wrapped calls.
        $this->assertSame(8, $out['calls']);
        $this->assertSame(5, $out['turns']);

        $this->assertSame(['wpmcp/list-tools', ['search' => 'find']], $this->sent[0]);
        $this->assertSame(['wpmcp/list-tools', ['search' => 'act']], $this->sent[1]);
        $this->assertSame(['wpmcp/get-tool-schema', ['name' => 'wpmcp/find-things']], $this->sent[2]);
        $this->assertSame(['wpmcp/get-tool-schema', ['name' => 'wpmcp/act-on']], $this->sent[3]);
        $this->assertSame(['wpmcp/call-tool', ['name' => 'wpmcp/find-things', 'arguments' => ['q' => 1]]], $this->sent[4]);
        $this->assertSame(['wpmcp/call-tool', ['name' => 'wpmcp/act-on', 'arguments' => ['id' => 2]]], $this->sent[6]);
        // The closure still sees the unwrapped results, the way the agent reads them.
        $this->assertSame(['wpmcp/call-tool', ['name' => 'wpmcp/find-things', 'arguments' => ['q' => 2]]], $this->sent[7]);
        $this->assertSame('wpmcp/act-on', $out['steps'][3]['tool']);
        $this->assertSame('wpmcp/call-tool', $out['steps'][3]['via']);
    }

    public function test_compact_fails_when_no_search_would_have_shown_the_agent_a_tool_it_calls(): void
    {
        $steps = Surface::compact(['find'], [
            ['tool' => 'wpmcp/find-things', 'args' => []],
            ['tool' => 'wpmcp/act-on', 'args' => []],
        ]);

        $out = (new Runner($this->catalog_transport(['wpmcp/find-things', 'wpmcp/act-on'])))->run($steps);

        $this->assertSame('failed', $out['status']);
        $this->assertStringContainsString('wpmcp/act-on', $out['reason']);
    }

    public function test_compact_calls_a_core_tool_directly_without_discovery(): void
    {
        $steps = Surface::compact([], [
            ['tool' => 'wpmcp/get-site-context', 'args' => []],
        ]);

        $out = (new Runner($this->catalog_transport([])))->run($steps);

        $this->assertSame('ok', $out['status'], (string) ($out['reason'] ?? ''));
        $this->assertSame(1, $out['calls']);
        $this->assertSame([['wpmcp/get-site-context', []]], $this->sent);
    }
}
