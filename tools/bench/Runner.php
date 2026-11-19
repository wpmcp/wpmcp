<?php

namespace WPMCP\Bench;

/**
 * Replays one scripted call sequence and counts what it cost (issue #1130).
 *
 * A sequence is a list of steps. Each step is one model turn:
 *
 *   ['tool' => 'wpmcp/list-posts', 'args' => [...] | fn(array $state): array, 'as' => 'pages']
 *   ['tool' => 'wpmcp/update-seo-meta', 'each' => fn(array $state): array[], 'as' => 'updated']
 *
 * 'args' is one call. 'each' is a fan-out: the closure returns one argument
 * list per call, all sent in the same turn (calls an agent could make in
 * parallel). A closure reads earlier results by their 'as' key, the way an
 * agent reads what came back before it decides the next call; a fan-out
 * stores the list of its results. A step whose fan-out is empty costs no
 * call and no turn.
 *
 * The transport does the call and measures it. It returns
 * ['value' => mixed, 'bytes' => int, 'error' => ?string]: the result as the
 * agent sees it, the bytes that went back to the model, and an error when
 * the call was refused or failed. The first error, or the first closure
 * that throws (a step that cannot find what it needs in earlier results),
 * stops the run as failed: a failed scenario is reported as failed, never
 * as cheap.
 *
 * REPO-ONLY, like everything under tools/: the release zips ship src/ only.
 */
final class Runner
{
    /** @var callable(string, array): array{value: mixed, bytes: int, error: ?string} */
    private $transport;

    public function __construct(callable $transport)
    {
        $this->transport = $transport;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array{status: string, reason: ?string, calls: int, turns: int, result_bytes: int, ms: float, steps: array<int, array<string, mixed>>, state: array<string, mixed>}
     */
    public function run(array $steps): array
    {
        $out = [
            'status'       => 'ok',
            'reason'       => null,
            'calls'        => 0,
            'turns'        => 0,
            'result_bytes' => 0,
            'ms'           => 0.0,
            'steps'        => [],
            'state'        => [],
        ];
        $start = microtime(true);

        try {
            foreach ($steps as $i => $step) {
                $this->run_step($i, $step, $out);
                if ('ok' !== $out['status']) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $out['status'] = 'failed';
            $out['reason'] = sprintf('step %d: %s', $i ?? 0, $e->getMessage());
        }

        $out['ms'] = round((microtime(true) - $start) * 1000, 1);
        return $out;
    }

    /** @param array<string, mixed> $step */
    private function run_step(int $i, array $step, array &$out): void
    {
        $tool = isset($step['tool']) && is_string($step['tool']) ? $step['tool'] : '';
        if ('' === $tool) {
            throw new \InvalidArgumentException('a step needs a tool name');
        }
        if (array_key_exists('args', $step) === array_key_exists('each', $step)) {
            throw new \InvalidArgumentException($tool . ': a step has exactly one of args or each');
        }

        $fan_out = array_key_exists('each', $step);
        $list    = $fan_out
            ? $this->resolve($step['each'], $out['state'])
            : [$this->resolve($step['args'], $out['state'])];
        if (! is_array($list) || ($fan_out && ! array_is_list($list))) {
            throw new \UnexpectedValueException($tool . ': each must return a list of argument arrays');
        }

        $record = [
            'tool'  => (string) ($step['label'] ?? $tool),
            'via'   => isset($step['label']) ? $tool : null,
            'calls' => 0,
            'bytes' => 0,
            'ms'    => 0.0,
        ];
        $values = [];
        $began  = microtime(true);

        foreach ($list as $args) {
            if (! is_array($args)) {
                throw new \UnexpectedValueException($tool . ': arguments must be an array');
            }
            $result = ($this->transport)($tool, $args);
            $bytes  = (int) ($result['bytes'] ?? 0);

            $record['calls']++;
            $record['bytes']     += $bytes;
            $out['calls']++;
            $out['result_bytes'] += $bytes;

            if (null !== ($result['error'] ?? null)) {
                $out['status'] = 'failed';
                $out['reason'] = sprintf('step %d (%s): %s', $i, $record['tool'], (string) $result['error']);
                break;
            }
            $values[] = $result['value'] ?? null;
        }

        $record['ms'] = round((microtime(true) - $began) * 1000, 1);
        if ($record['calls'] > 0) {
            $out['turns']++;
            $out['steps'][] = $record;
        }

        if (isset($step['as']) && 'ok' === $out['status']) {
            $out['state'][ (string) $step['as'] ] = $fan_out ? $values : ($values[0] ?? null);
        }
    }

    /** @param mixed $spec An array, or a closure of the state returning one. */
    private function resolve($spec, array $state)
    {
        return $spec instanceof \Closure ? $spec($state) : $spec;
    }
}
