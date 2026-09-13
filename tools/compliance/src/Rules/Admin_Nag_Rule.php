<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * Guideline 11: "Upgrade prompts, notices, alerts, and the like must be
 * limited in scope and used sparingly, be that contextually or only on the
 * plugin's setting page. Site wide notices or embedded dashboard widgets must
 * be dismissible or self-dismiss when resolved."
 *
 * Guideline 9 also prohibits "implying users must pay to unlock included
 * features" outright, which makes upsell copy a finding in its own right when
 * the feature is in the zip. That half follows the profile: a profile that
 * permits gating shipped code (the distribution zip) sets the severity of the
 * copy that describes the gate, and the notice-placement half is unaffected.
 */
final class Admin_Nag_Rule extends Base_Rule
{
    private const NOTICE_HOOKS = ['admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices'];

    private const UPSELL_COPY = [
        'upgrade to unlock',
        'unlock this feature',
        'pro feature',
        'premium feature',
        'available in pro',
        'only in pro',
        'upgrade now',
        'go pro',
        'buy now',
        'upgrade to pro',
    ];

    public function id(): string
    {
        return 'WPORG-11-ADMIN-NAG';
    }

    public function guideline(): string
    {
        return 'Guideline 11, admin hijacking; guideline 9, pay-to-unlock copy';
    }

    public function title(): string
    {
        return 'Admin notice and upsell placement';
    }

    public function explanation(): string
    {
        return 'Upselling is permitted from the plugin\'s own settings screen and from a link on the '
            . 'plugins-list row, and nowhere else. Site-wide notices must be dismissible and must '
            . 'self-dismiss once resolved. Copy that tells the user to pay to unlock a feature that is '
            . 'already in the zip is a separate, harder violation: guideline 9 lists "implying users '
            . 'must pay to unlock included features" as prohibited.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        $copy_severity = $context->profile()->pay_to_unlock_copy_severity();
        foreach ($context->php_files() as $file) {
            foreach ($this->hooked_notices($file) as $hook) {
                $findings[] = $this->finding(
                    $file,
                    $hook['line'],
                    sprintf(
                        'hooks %s: a site-wide notice must be dismissible, must self-dismiss when resolved, and must not carry upgrade copy outside the plugin\'s own settings screen',
                        $hook['hook']
                    ),
                    Severity::REVIEWER_DISCRETION
                );
            }
            foreach ($file->find_calls(['wp_add_dashboard_widget']) as $call) {
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    'embedded dashboard widget: must be dismissible and must not be promotional',
                    Severity::REVIEWER_DISCRETION
                );
            }
            foreach ($file->string_literals() as $literal) {
                foreach (self::UPSELL_COPY as $phrase) {
                    if (false === stripos($literal['value'], $phrase)) {
                        continue;
                    }
                    $findings[] = $this->finding(
                        $file,
                        $literal['line'],
                        sprintf('pay-to-unlock copy "%s": prohibited by guideline 9 when the feature ships in the same zip', $phrase),
                        $copy_severity
                    );
                    break;
                }
            }
        }
        return $findings;
    }

    /**
     * @return array<int,array{hook:string,line:int}>
     */
    private function hooked_notices(Source_File $file): array
    {
        $found = [];
        foreach ($file->string_literals() as $literal) {
            if (! in_array(strtolower(trim($literal['value'])), self::NOTICE_HOOKS, true)) {
                continue;
            }
            if (! preg_match('/\b(add_action|do_action|add_filter)\s*\(/', $file->line($literal['line']))) {
                continue;
            }
            $found[] = ['hook' => trim($literal['value']), 'line' => $literal['line']];
        }
        return $found;
    }
}
