<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Source_File;

/**
 * Plugin Check Nonce_Verification_Check plus the reviewer checklist's
 * capability requirement.
 *
 * File-scoped by design: a handler that reads $_POST and never verifies a
 * nonce or a capability anywhere in its file is the pattern reviewers close
 * submissions over.
 */
final class Nonce_Capability_Rule extends Base_Rule
{
    private const NONCE_CHECKS = ['wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer'];
    private const CAPABILITY_CHECKS = ['current_user_can', 'current_user_can_for_blog', 'user_can', 'is_super_admin', 'author_can'];
    private const WRITE_SUPERGLOBALS = ['_POST', '_REQUEST', '_FILES'];

    public function id(): string
    {
        return 'PCP-NONCE-CAP';
    }

    public function guideline(): string
    {
        return 'Plugin Check Nonce_Verification_Check; reviewer checklist, capability checks';
    }

    public function title(): string
    {
        return 'Missing nonce or capability check on a write path';
    }

    public function explanation(): string
    {
        return 'Anything that acts on submitted data needs both halves: a nonce, so the request came '
            . 'from your form, and a capability check, so this user is allowed to do it. Registering '
            . 'the screen at manage_options is not enough on its own when the handler can also be '
            . 'reached directly.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            $writes = $this->write_reads($file);
            if ([] === $writes) {
                continue;
            }
            if ([] === $file->find_calls(self::NONCE_CHECKS)) {
                $findings[] = $this->finding(
                    $file,
                    $writes[0],
                    'reads submitted data but the file never verifies a nonce'
                );
            }
            if ([] === $file->find_calls(self::CAPABILITY_CHECKS)) {
                $findings[] = $this->finding(
                    $file,
                    $writes[0],
                    'reads submitted data but the file never checks a capability'
                );
            }
        }
        return $findings;
    }

    /**
     * @return int[] lines reading a write-carrying superglobal
     */
    private function write_reads(Source_File $file): array
    {
        $pattern = '/\$(' . implode('|', self::WRITE_SUPERGLOBALS) . ')\s*\[/';
        return array_column($file->grep_code($pattern), 'line');
    }
}
