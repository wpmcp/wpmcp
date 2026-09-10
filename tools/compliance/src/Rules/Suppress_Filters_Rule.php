<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * Plugin Check, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters
 * (finding B-26, issue #175).
 *
 * `'suppress_filters' => true` turns off posts_where, posts_results,
 * the_posts and the rest of the query filter chain, which is how multilingual
 * plugins scope a query to the current language and how other plugins expect
 * to be able to correct a result set. Reviewers flag it consistently, and
 * Plugin Check reports it at error level, so a site that ships with it fails
 * the directory scan.
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
 *
 * The annotation has to name the code PHPCS actually emits. The VIP sniff
 * extends WordPressCS's AbstractArrayAssignmentRestrictionsSniff, which
 * builds its message code as "<group>_<key>", so the full code is
 * "...WPQueryParams.SuppressFilters_suppress_filters", and PHPCS matches
 * phpcs:ignore on whole dot-separated segments only. An annotation for
 * "...WPQueryParams.SuppressFilters" therefore suppresses nothing in Plugin
 * Check, and Source_File::has_phpcs_ignore() applies the same segment rule
 * here, so it is not accepted either. The owning sniff
 * "WordPressVIPMinimum.Performance.WPQueryParams" is accepted, as it is by
 * PHPCS.
 */
final class Suppress_Filters_Rule extends Base_Rule
{
    /**
     * The message code PHPCS emits, not the sniff group name.
     */
    private const SNIFF = 'WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters';

    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

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

    /**
     * Plugin Check reports the sniff at error level, so this is a blocker in
     * every profile: the finding fails CI rather than accumulating.
     */
    public function default_severity(): string
    {
        return Severity::BLOCKER;
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($this->sites($file) as $line) {
                if ($file->has_phpcs_ignore($line, self::SNIFF)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $line,
                    'suppress_filters => true disables the query filter chain; drop the argument '
                        . '(get_posts already defaults to true), pass false, or justify it in a '
                        . 'phpcs:ignore for ' . self::SNIFF
                );
            }
        }
        return $findings;
    }

    /**
     * Lines carrying a `'suppress_filters' => true` array element.
     *
     * Token-based, like the other code rules, so a comment or a string that
     * quotes the argument (this file's own docblock, a "was suppress_filters
     * => true" note at a call site) cannot match: the key has to be a string
     * literal token, the next significant token has to be "=>", and the one
     * after that the bare constant true.
     *
     * @return int[]
     */
    private function sites(Source_File $file): array
    {
        $tokens = $file->tokens();
        $count = count($tokens);
        $lines = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }
            if ('suppress_filters' !== trim($token[1], '\'"')) {
                continue;
            }
            $arrow = $this->next_significant($tokens, $index, $count);
            if (null === $arrow || ! is_array($tokens[$arrow]) || T_DOUBLE_ARROW !== $tokens[$arrow][0]) {
                continue;
            }
            $value = $this->next_significant($tokens, $arrow, $count);
            if (null === $value || ! is_array($tokens[$value])) {
                continue;
            }
            if (! in_array($tokens[$value][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if ('true' !== strtolower(ltrim($tokens[$value][1], '\\'))) {
                continue;
            }
            $lines[] = $token[2];
        }
        return array_values(array_unique($lines));
    }

    /**
     * Index of the next token after $index that is not whitespace or a
     * comment, or null at end of file.
     *
     * @param array<int,array|string> $tokens
     */
    private function next_significant(array $tokens, int $index, int $count): ?int
    {
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], self::SKIP, true)) {
                continue;
            }
            return $i;
        }
        return null;
    }
}
