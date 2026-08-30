<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Plugin Check, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters
 * (finding B-26, issue #175).
 *
 * `'suppress_filters' => true` turns off posts_where, posts_results,
 * the_posts and the rest of the query filter chain, which is how multilingual
 * plugins scope a query to the current language and how other plugins expect
 * to be able to correct a result set. Reviewers flag it consistently.
 *
 * The rule exists because nothing else here can see the finding: the VIP
 * standard is not in our phpcs ruleset and automattic/vipwpcs is not a
 * dependency, so before this rule a reintroduced argument would have been
 * caught by wp.org and not by CI.
 *
 * Only `=> true` is reported. `=> false` opts the filters back in and is the
 * remedy, not the problem. A site that genuinely must not be filterable keeps
 * the argument and records why in a justified phpcs:ignore, the same
 * "annotation carries a reason or it suppresses nothing" contract the
 * forbidden-functions rule uses; `Memory_Store::block_rules()` is the one
 * such site in this plugin, because the write guard it feeds fails open.
 */
final class Suppress_Filters_Rule extends Base_Rule
{
    private const SNIFF = 'WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters';

    public function id(): string
    {
        return 'PCP-SUPPRESS-FILTERS';
    }

    public function guideline(): string
    {
        return 'Plugin Check, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters';
    }

    public function title(): string
    {
        return 'Query forces suppress_filters';
    }

    public function explanation(): string
    {
        return 'suppress_filters => true disables posts_where, posts_results and the_posts, which '
            . 'breaks multilingual setups and any plugin that expects to be able to correct a result '
            . 'set. get_posts() already defaults the flag to true, so the argument is usually just '
            . 'noise: delete it, or pass false to opt the filters back in. Where suppression is '
            . 'deliberate, keep the argument and state why in a phpcs:ignore for '
            . self::SNIFF . ' with a justification after "--".';
    }

    public function default_severity(): string
    {
        return Severity::BEST_PRACTICE;
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->grep('/[\'"]suppress_filters[\'"]\s*=>\s*true\b/i') as $hit) {
                if ($file->has_phpcs_ignore($hit['line'], self::SNIFF)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    'suppress_filters => true disables the query filter chain; drop the argument '
                        . '(get_posts already defaults to true), pass false, or justify it in a '
                        . 'phpcs:ignore for ' . self::SNIFF
                );
            }
        }
        return $findings;
    }
}
