<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Confirmation_Required;
use WPMCP\MCP\Elicitation;

/**
 * The confirm gates carry one recognizable signal (issue #387), so the
 * transports can tell "this call needs the user's confirmation" apart from
 * every other refusal and ask instead of failing.
 */
class ConfirmationGateTest extends \WP_UnitTestCase
{
    /**
     * Existing callers catch InvalidArgumentException from a confirm gate;
     * the typed refusal must stay one so nothing that caught it changes.
     */
    public function test_the_refusal_is_still_an_invalid_argument(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, new Confirmation_Required('x'));
    }

    /**
     * Every thrown confirm refusal in the tools uses the typed exception. A
     * new gate written the old way would silently never be elicited, so
     * this scans the source rather than trusting a list.
     */
    public function test_no_confirm_gate_throws_a_plain_invalid_argument(): void
    {
        $root     = dirname(__DIR__, 3) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/new\s+\\\\InvalidArgumentException\s*\(\s*(?:sprintf\s*\(\s*)?(["\'])(.*?)(?<!\\\\)\1/s', $source, $matches)) {
                foreach ($matches[2] as $message) {
                    if (str_contains($message, 'confirm:true')) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $message;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_every_refusal_shape_is_recognized(): void
    {
        $this->assertTrue(Elicitation::is_confirmation_refusal(new Confirmation_Required('Deleting is permanent. Pass confirm:true to proceed.')));
        $this->assertTrue(Elicitation::is_confirmation_refusal(new \WP_Error('confirm_required', 'Pass confirm:true.')));
        $this->assertTrue(Elicitation::is_confirmation_refusal(new \WP_Error('confirmation_required', 'Needs confirm:true.')));
        $this->assertTrue(Elicitation::is_confirmation_refusal([ 'integration' => 'acf', 'error' => [ 'code' => 'confirmation_required', 'message' => 'Operation "x" is destructive and requires confirm:true.' ] ]));

        $this->assertFalse(Elicitation::is_confirmation_refusal(new \InvalidArgumentException('Comment not found.')));
        $this->assertFalse(Elicitation::is_confirmation_refusal(new \RuntimeException('Pass confirm:true to proceed.')));
        $this->assertFalse(Elicitation::is_confirmation_refusal(new \WP_Error('forbidden', 'No.')));
        $this->assertFalse(Elicitation::is_confirmation_refusal([ 'error' => [ 'code' => 'operation_denied' ] ]));
        $this->assertFalse(Elicitation::is_confirmation_refusal([ 'confirm_required' => true, 'dry_run' => true ]), 'A preview is an answer, not a refusal.');
        $this->assertFalse(Elicitation::is_confirmation_refusal([ 'ok' => true ]));
    }

    /** The human sees why, not an instruction meant for the agent. */
    public function test_the_question_is_worded_for_the_person(): void
    {
        foreach ([
            'Deleting a plugin is permanent. Pass confirm:true to proceed.',
            'Deleting rows requires confirm:true.',
            'Operation "delete" is destructive and requires confirm:true.',
            'Pass post_id for one page, or confirm:true to purge generated CSS and render caches site-wide.',
        ] as $reason) {
            $message = Elicitation::question('wpmcp-delete-plugin', [ 'plugin' => 'hello.php' ], $reason);

            $this->assertStringNotContainsString('Pass confirm:true to proceed', $message);
            $this->assertStringNotContainsString('requires confirm:true', $message);
            $this->assertStringContainsString('wpmcp-delete-plugin', $message);
            $this->assertStringContainsString('hello.php', $message, 'The person sees what the call targets.');
        }
    }
}
