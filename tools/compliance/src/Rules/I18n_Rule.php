<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * Plugin Check I18n_Usage_Check: the text domain on every translation call
 * must be a literal equal to the plugin slug.
 *
 * This is the rule that catches a flavor build whose text-domain rewrite
 * missed a call: a domain that no longer matches the slug produces strings
 * that translate.wordpress.org will never serve.
 *
 * It is the text-domain half of WordPress.WP.I18n only. The rest of that
 * sniff (MissingTranslatorsComment, NonSingularStringLiteralText, the
 * placeholder checks) runs as the real WordPressCS sniff through
 * phpcs-wporg.xml.dist and `composer lint`, where .phpcs-baseline.json
 * records no I18n codes, so the next occurrence fails the build (issue
 * #176). The engine does not carry a second copy of those checks.
 */
final class I18n_Rule extends Base_Rule
{
    /** function => zero-based index of the text domain argument */
    private const GETTEXT_FUNCTIONS = [
        '__' => 1,
        '_e' => 1,
        'esc_html__' => 1,
        'esc_html_e' => 1,
        'esc_attr__' => 1,
        'esc_attr_e' => 1,
        'translate' => 1,
        '_x' => 2,
        '_ex' => 2,
        'esc_html_x' => 2,
        'esc_attr_x' => 2,
        '_n' => 3,
        '_n_noop' => 2,
        '_nx' => 4,
        '_nx_noop' => 3,
    ];

    private const MENU_FUNCTIONS = ['add_menu_page', 'add_submenu_page', 'add_options_page', 'add_management_page'];

    public function id(): string
    {
        return 'PCP-I18N';
    }

    public function guideline(): string
    {
        return 'Plugin Check I18n_Usage_Check; WordPress.WP.I18n';
    }

    public function title(): string
    {
        return 'Text domain consistency';
    }

    public function explanation(): string
    {
        return 'Every translation call must pass the plugin slug as a literal text domain. A variable '
            . 'or concatenated domain cannot be extracted, a mismatched domain is never loaded, and a '
            . 'missing domain silently falls back to core. load_plugin_textdomain has been unnecessary '
            . 'for directory plugins since WordPress 4.6 and warns since 6.7.';
    }

    public function check(Rule_Context $context): array
    {
        $expected = $context->text_domain();
        $findings = [];

        foreach ($context->php_files() as $file) {
            foreach ($this->gettext_calls($file) as $call) {
                if (null === $call['domain']) {
                    $findings[] = $this->finding(
                        $file,
                        $call['line'],
                        sprintf('%s() has no text domain argument, so the string falls back to the core domain', $call['name'])
                    );
                    continue;
                }
                if (false === $call['domain']) {
                    $findings[] = $this->finding(
                        $file,
                        $call['line'],
                        sprintf('%s() uses a non-literal text domain; the string cannot be extracted', $call['name'])
                    );
                    continue;
                }
                if ($call['domain'] === $expected) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('text domain "%s" does not match the declared domain "%s"', $call['domain'], $expected)
                );
            }

            foreach ($file->find_calls(['load_plugin_textdomain'], false) as $call) {
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    'load_plugin_textdomain() is unnecessary for directory plugins since WordPress 4.6 and triggers a notice when called early since 6.7',
                    Severity::BEST_PRACTICE
                );
            }

            foreach ($this->untranslated_menu_labels($file) as $label) {
                $findings[] = $this->finding(
                    $file,
                    $label['line'],
                    sprintf('%s() is given an untranslated label', $label['name']),
                    Severity::BEST_PRACTICE
                );
            }
        }

        if ('' === (string) $context->header()->get('text domain')) {
            $findings[] = $this->file_finding(
                $context->header()->relative_path(),
                'no Text Domain header; it must be declared and equal to the slug',
                Severity::LIKELY_REJECT,
                $context->header()->line_of('Plugin Name')
            );
        }
        if ($context->source()->has('languages') && null === $context->header()->get('domain path')) {
            $findings[] = $this->file_finding(
                $context->header()->relative_path(),
                'a languages/ directory ships but no Domain Path header points at it',
                Severity::BEST_PRACTICE,
                $context->header()->line_of('Text Domain')
            );
        }

        return $findings;
    }

    /**
     * @return array<int,array{name:string,line:int,domain:string|false|null}>
     */
    private function gettext_calls(Source_File $file): array
    {
        $calls = [];
        $tokens = $file->tokens();
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            $name = $token[1];
            if (! isset(self::GETTEXT_FUNCTIONS[$name])) {
                continue;
            }
            $arguments = $this->arguments($tokens, $i);
            if (null === $arguments) {
                continue;
            }
            $index = self::GETTEXT_FUNCTIONS[$name];
            $domain = null;
            if (isset($arguments[$index])) {
                $literal = trim($arguments[$index]);
                $domain = preg_match('/^([\'"])([^\'"]*)\1$/', $literal, $matches) ? $matches[2] : false;
            }
            $calls[] = ['name' => $name, 'line' => $token[2], 'domain' => $domain];
        }
        return $calls;
    }

    /**
     * Top-level arguments of the call opening after $index, or null when the
     * token is not a call.
     *
     * @param  array<int,array|string> $tokens
     * @return string[]|null
     */
    private function arguments(array $tokens, int $index): ?array
    {
        $count = count($tokens);
        $i = $index + 1;
        while ($i < $count && is_array($tokens[$i]) && T_WHITESPACE === $tokens[$i][0]) {
            $i++;
        }
        if ($i >= $count || '(' !== $tokens[$i]) {
            return null;
        }
        $depth = 0;
        $arguments = [''];
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if ('(' === $token || '[' === $token) {
                    $depth++;
                    if (1 === $depth) {
                        continue;
                    }
                } elseif (')' === $token || ']' === $token) {
                    $depth--;
                    if (0 === $depth) {
                        break;
                    }
                } elseif (',' === $token && 1 === $depth) {
                    $arguments[] = '';
                    continue;
                }
                $arguments[count($arguments) - 1] .= $token;
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $arguments[count($arguments) - 1] .= $token[1];
        }
        return array_map('trim', $arguments);
    }

    /**
     * @return array<int,array{name:string,line:int}>
     */
    private function untranslated_menu_labels(Source_File $file): array
    {
        $found = [];
        $tokens = $file->tokens();
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            if (! in_array($token[1], self::MENU_FUNCTIONS, true)) {
                continue;
            }
            $arguments = $this->arguments($tokens, $index);
            if (null === $arguments) {
                continue;
            }
            // add_submenu_page() takes the parent slug first; the two label
            // arguments follow it.
            $offset = 'add_submenu_page' === $token[1] ? 1 : 0;
            foreach (array_slice($arguments, $offset, 2) as $argument) {
                if (preg_match('/^([\'"])(.+)\1$/', trim($argument))) {
                    $found[] = ['name' => $token[1], 'line' => $token[2]];
                    break;
                }
            }
        }
        return $found;
    }
}
