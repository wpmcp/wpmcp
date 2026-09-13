<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 8: "Serving updates or otherwise installing plugins, themes, or
 * add-ons from servers other than WordPress.org's."
 *
 * Mirrors Plugin_Updater_Check: the Update URI header rule, the error-level
 * content regexes (which have no Freemius exclusion, unlike the PHPCS
 * ruleset), and the warning-level transient filters.
 */
final class Updater_Rule extends Base_Rule
{
    /**
     * Plugin_Updater_Check.php:189-197, error level.
     *
     * Public because scripts/lib/updater-gate.sh reads this list rather than
     * carrying a second copy of it: the build-time grep and this rule have to
     * be the same policy, or the gate quietly becomes the weaker of the two.
     */
    public const UPDATER_PATTERNS = [
        'plugin-update-checker',
        'WP_GitHub_Updater',
        'WPGitHubUpdater',
        'class [A-Z_]+_Plugin_Updater',
        'updater\.\w+\.\w{2,5}',
        'site_transient_update_plugins',
        'YahnisElsts\\\\PluginUpdateChecker',
        'PucFactory::buildUpdateChecker',
    ];

    /** Plugin_Updater_Check.php:232-236, warning level. */
    private const SOFT_PATTERNS = [
        'auto_update_plugin',
        'pre_set_site_transient_update_\w+',
        '_site_transient_update_\w+',
    ];

    public function id(): string
    {
        return 'WPORG-08-UPDATER';
    }

    public function guideline(): string
    {
        return 'Guideline 8; Plugin Check Plugin_Updater_Check';
    }

    public function title(): string
    {
        return 'Self-hosted update mechanism';
    }

    public function explanation(): string
    {
        return 'A directory-hosted plugin is updated by WordPress.org and nothing else. The Update URI '
            . 'header must be absent or point at wordpress.org/plugins/<slug>, and no updater code may '
            . 'be present. Note that Plugin_Updater_Check is a file-content check with no Freemius '
            . 'carve-out: the bundled SDK\'s site_transient_update_plugins usage will be reported, so '
            . 'the directory build has to strip the SDK updater or explain it to the reviewer.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];

        $header = $context->header();
        $update_uri = $header->get('update uri');
        if (null !== $update_uri && '' !== $update_uri) {
            $slug = preg_quote($context->slug(), '#');
            $allowed = '#^(https?://)?(wordpress\.org|w\.org)/plugins?/' . $slug . '/?$#i';
            if (! preg_match($allowed, $update_uri)) {
                $findings[] = $this->file_finding(
                    $header->relative_path(),
                    sprintf('Update URI "%s" is not allowed for a WordPress.org hosted plugin', $update_uri),
                    null,
                    $header->line_of('Update URI'),
                    $update_uri
                );
            }
        }

        $hard = '/(' . implode('|', self::UPDATER_PATTERNS) . ')/i';
        $soft = '/(' . implode('|', self::SOFT_PATTERNS) . ')/i';
        foreach ($context->php_files() as $file) {
            if ('plugin-update-checker.php' === basename($file->relative_path())) {
                $findings[] = $this->finding($file, 1, 'plugin-update-checker.php must not ship in a directory plugin');
            }
            foreach ($file->grep($hard) as $hit) {
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    sprintf('updater marker "%s" is an error in Plugin_Updater_Check', $hit['match'])
                );
            }
            foreach ($file->grep($soft) as $hit) {
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    sprintf('update-transient filter "%s" is reported as a warning by Plugin_Updater_Check', $hit['match']),
                    Severity::BEST_PRACTICE
                );
            }
        }

        foreach ($this->vendored_sdk_hits($context) as $hit) {
            $findings[] = $hit;
        }

        foreach ($this->vendor_updater_hits($context) as $hit) {
            $findings[] = $hit;
        }

        return $findings;
    }

    /**
     * Plugin_Updater_Check has no vendor carve-out and the wordpress.org run
     * does not exclude vendor/, but Plugin_Source keeps vendor out of
     * source_files() so that no code rule analyses third-party dependencies.
     * For an artifact scan that exclusion is wrong: the extracted zip is
     * exactly what the directory receives, vendor included, so the
     * error-level patterns are applied to every vendored PHP file too. A
     * development checkout still skips this: its vendor tree carries dev
     * dependencies that never ship, and reporting those would be noise.
     *
     * @return \WPMCP\Compliance\Finding[]
     */
    private function vendor_updater_hits(Rule_Context $context): array
    {
        if (! $context->profile()->is_artifact_scan()) {
            return [];
        }

        $findings = [];
        $hard = '/(' . implode('|', self::UPDATER_PATTERNS) . ')/i';
        foreach ($context->source()->files_under('vendor') as $file) {
            if ('plugin-update-checker.php' === basename($file->relative_path())) {
                $findings[] = $this->finding($file, 1, 'plugin-update-checker.php must not ship in a directory plugin');
            }
            foreach ($file->grep($hard) as $hit) {
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    sprintf(
                        'updater marker "%s" is an error in Plugin_Updater_Check, which has no vendor carve-out',
                        $hit['match']
                    )
                );
            }
        }
        return $findings;
    }

    /**
     * Plugin_Updater_Check is a file-content check with no vendor exclusion,
     * so a bundled licensing SDK's updater is reported even though PHPCS
     * skips the same path. Scan the known SDK directories only: everything
     * else in vendor is out of scope for this engine.
     *
     * @return \WPMCP\Compliance\Finding[]
     */
    private function vendored_sdk_hits(Rule_Context $context): array
    {
        $findings = [];
        foreach (['vendor/freemius'] as $directory) {
            foreach ($context->source()->files_under($directory) as $file) {
                if (! $file->contains('site_transient_update_plugins', false)) {
                    continue;
                }
                $findings[] = $this->file_finding(
                    $directory,
                    sprintf(
                        'the bundled licensing SDK contains an updater (%s references site_transient_update_plugins); Plugin_Updater_Check has no vendor carve-out, so strip the SDK updater from the directory build or be ready to explain it',
                        $file->relative_path()
                    ),
                    Severity::REVIEWER_DISCRETION
                );
                break;
            }
        }
        return $findings;
    }
}
