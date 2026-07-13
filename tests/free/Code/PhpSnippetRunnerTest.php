<?php

namespace WPMCP\Tests\Free\Code;

use WPMCP\Tools\Code\Php_Snippet_Runner;

/**
 * Php_Snippet_Runner is the ONLY class that actually evals a PHP snippet
 * (mirroring Wp_Cli_Executor being the only class that spawns a wp-cli
 * process). These tests exercise the REAL evaluator end to end: a trivial,
 * unambiguously safe snippet proving the happy path works, plus the bounded-
 * execution guarantees (echoed output captured, thrown Throwable caught and
 * returned as a structured error instead of an unhandled fatal).
 */
class PhpSnippetRunnerTest extends \WP_UnitTestCase
{
    public function test_trivial_snippet_returns_its_return_value_via_the_real_evaluator(): void
    {
        $result = Php_Snippet_Runner::run('return 2 + 2;');

        $this->assertSame(4, $result['return_value']);
        $this->assertSame('', $result['output']);
        $this->assertNull($result['error']);
    }

    public function test_echoed_output_is_captured(): void
    {
        $result = Php_Snippet_Runner::run('echo "hello world";');

        $this->assertSame('hello world', $result['output']);
        $this->assertNull($result['error']);
    }

    public function test_thrown_exception_is_caught_and_returned_as_structured_error(): void
    {
        $result = Php_Snippet_Runner::run('throw new \RuntimeException("boom");');

        $this->assertNull($result['return_value']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('boom', $result['error']);
    }

    public function test_thrown_error_is_caught_and_returned_as_structured_error(): void
    {
        // \Error (e.g. TypeError, DivisionByZeroError) is a Throwable but NOT
        // an Exception; this must be caught too or a fatal escapes as an
        // unhandled 500.
        $result = Php_Snippet_Runner::run('intdiv(1, 0);');

        $this->assertNull($result['return_value']);
        $this->assertNotNull($result['error']);
    }

    public function test_output_before_a_thrown_exception_is_still_captured(): void
    {
        $result = Php_Snippet_Runner::run('echo "before"; throw new \Exception("after");');

        $this->assertSame('before', $result['output']);
        $this->assertNotNull($result['error']);
    }
}
