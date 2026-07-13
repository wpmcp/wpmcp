<?php

namespace WPMCP\Tests\Free\Code;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Run_Php_Snippet;

/**
 * Run_Php_Snippet composes every Php_Snippet_Guard check plus an injectable
 * evaluator callable, so these tests assert every guard outcome WITHOUT
 * ever eval()ing anything: the evaluator passed in here is a plain closure
 * that records what it was called with and returns a canned result. This
 * mirrors tests/free/Cli/RunWpCliTest.php's structure for Run_Wp_Cli.
 *
 * issue #45: this tool is the ONE explicit escape hatch outside the
 * snapshot/rollback safety model. Its effects are not captured and not
 * undoable, and enabling it grants RCE to anyone who can call it with
 * manage_options.
 */
class RunPhpSnippetTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Governance_Audit_Log::OPTION);
        Php_Snippet_Guard::set_environment_override('local');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_allow_php_exec');
        remove_all_filters('wpmcp_allow_php_exec_on_production');
        Php_Snippet_Guard::set_environment_override(null);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    private function recording_evaluator(array &$calls): callable
    {
        return function (string $code, int $timeout) use (&$calls): array {
            $calls[] = ['code' => $code, 'timeout' => $timeout];
            return [
                'return_value' => 4,
                'output'       => '',
                'error'        => null,
            ];
        };
    }

    public function test_default_off_blocks_execution_without_calling_evaluator(): void
    {
        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP execution is disabled');

        try {
            $tool->handle(['code' => 'return 2 + 2;']);
        } finally {
            $this->assertCount(0, $calls, 'The evaluator must never be invoked when disabled.');
        }
    }

    public function test_default_off_records_a_denied_audit_entry(): void
    {
        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        try {
            $tool->handle(['code' => 'return 2 + 2;']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/run-php-snippet', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_refuses_on_production_even_when_enabled(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('production');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['code' => 'return 2 + 2;']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function test_refuses_on_unknown_environment_even_when_enabled(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('some-unrecognized-value');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['code' => 'return 2 + 2;']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function test_validator_flagged_snippet_is_rejected_before_evaluation(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('flagged this snippet');
        try {
            // eval() is a DANGEROUS_FUNCTIONS entry in Php_Snippet_Validator:
            // a critical finding, so 'safe' is false and this must be
            // rejected before ever reaching the evaluator.
            $tool->handle(['code' => 'eval($_GET["x"]);']);
        } finally {
            $this->assertCount(0, $calls, 'The evaluator must never be invoked for a validator-flagged snippet.');
        }
    }

    public function test_validator_flagged_snippet_records_a_denied_audit_entry(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));
        try {
            $tool->handle(['code' => 'eval($_GET["x"]);']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_allowed_snippet_runs_through_the_evaluator(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));

        $result = $tool->handle(['code' => 'return 2 + 2;']);

        $this->assertCount(1, $calls);
        $this->assertSame('return 2 + 2;', $calls[0]['code']);
        $this->assertSame(4, $result['return_value']);
        $this->assertSame('', $result['output']);
        $this->assertNull($result['error']);
    }

    public function test_timeout_is_passed_through_to_the_evaluator(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));
        $tool->handle(['code' => 'return 2 + 2;']);

        $this->assertSame(30, $calls[0]['timeout']);
    }

    public function test_evaluator_reported_error_is_surfaced_in_the_result(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $tool = new Run_Php_Snippet(function (string $code, int $timeout): array {
            return ['return_value' => null, 'output' => '', 'error' => 'RuntimeException: boom'];
        });

        $result = $tool->handle(['code' => 'throw new \RuntimeException("boom");']);
        $this->assertNull($result['return_value']);
        $this->assertSame('RuntimeException: boom', $result['error']);
    }

    public function test_allowed_execution_records_an_allowed_audit_entry(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));
        $tool->handle(['code' => 'return 2 + 2;']);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/run-php-snippet', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
    }

    public function test_audit_entries_never_contain_the_snippet_source(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));
        $tool->handle(['code' => 'return "some_super_secret_marker_string";']);

        $entries    = Governance_Audit_Log::list();
        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString('some_super_secret_marker_string', $serialized);
    }

    public function test_audit_entries_never_contain_the_snippet_output(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');

        $tool = new Run_Php_Snippet(function (string $code, int $timeout): array {
            return ['return_value' => null, 'output' => 'another_secret_marker_output', 'error' => null];
        });
        $tool->handle(['code' => 'echo "another_secret_marker_output";']);

        $entries    = Governance_Audit_Log::list();
        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString('another_secret_marker_output', $serialized);
    }

    public function test_requires_code(): void
    {
        $calls = [];
        $tool  = new Run_Php_Snippet($this->recording_evaluator($calls));
        $this->expectException(\InvalidArgumentException::class);
        $tool->handle([]);
    }

    public function test_default_evaluator_is_the_real_php_snippet_runner_class(): void
    {
        // Verifies the injection seam's default without invoking it: the
        // no-arg constructor must wire up Php_Snippet_Runner::run as the
        // default callable, while every behavioral test above supplies a
        // fake.
        $tool       = new Run_Php_Snippet();
        $reflection = new \ReflectionProperty(Run_Php_Snippet::class, 'evaluator');
        $evaluator  = $reflection->getValue($tool);

        $this->assertSame([\WPMCP\Tools\Code\Php_Snippet_Runner::class, 'run'], $evaluator);
    }
}
