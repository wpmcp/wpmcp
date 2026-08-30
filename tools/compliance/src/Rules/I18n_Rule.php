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

    /** function => zero-based indexes of the arguments that carry translatable text */
    private const TEXT_ARGUMENTS = [
        '__' => [0],
        '_e' => [0],
        'esc_html__' => [0],
        'esc_html_e' => [0],
        'esc_attr__' => [0],
        'esc_attr_e' => [0],
        'translate' => [0],
        '_x' => [0],
        '_ex' => [0],
        'esc_html_x' => [0],
        'esc_attr_x' => [0],
        '_n' => [0, 1],
        '_n_noop' => [0, 1],
        '_nx' => [0, 1],
        '_nx_noop' => [0, 1],
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
            . 'for directory plugins since WordPress 4.6 and warns since 6.7. The translatable text '
            . 'itself must also be one string literal, and a string carrying placeholders needs a '
            . '/* translators: */ comment so the translator knows what each one will hold.';
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

            foreach ($this->non_literal_texts($file) as $problem) {
                $findings[] = $this->finding($file, $problem['line'], $problem['message']);
            }

            foreach ($this->missing_translators_comments($file) as $problem) {
                $findings[] = $this->finding($file, $problem['line'], $problem['message']);
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
     * WordPress.WP.I18n.MissingTranslatorsComment. A string with printf
     * placeholders is meaningless to a translator without a note saying what
     * each one holds, so WPCS and Plugin Check both require a comment ending
     * on the call's own line or the line directly above it.
     *
     * Public so the src/-wide regression guard can run the same check the
     * engine runs, instead of reimplementing the sniff beside it.
     *
     * @return array<int,array{line:int,message:string}>
     */
    public function missing_translators_comments(Source_File $file): array
    {
        $problems = [];
        foreach ($this->text_arguments($file) as $argument) {
            if (! $this->has_placeholder($argument['text'])) {
                continue;
            }
            if ($this->has_translators_comment($file->tokens(), $argument['index'], $argument['line'])) {
                continue;
            }
            $problems[] = [
                'line' => $argument['line'],
                'message' => sprintf(
                    '%s() has placeholders but no translators comment on or above the line; a translator cannot tell what %s holds',
                    $argument['name'],
                    $this->first_placeholder($argument['text'])
                ),
            ];
        }
        return $problems;
    }

    /**
     * WordPress.WP.I18n.NonSingularStringLiteralText. make-pot reads the
     * source, not the runtime, so a concatenated or interpolated argument
     * produces no string in the .pot file at all.
     *
     * @return array<int,array{line:int,message:string}>
     */
    public function non_literal_texts(Source_File $file): array
    {
        $problems = [];
        foreach ($this->text_arguments($file) as $argument) {
            if ($argument['literal']) {
                continue;
            }
            $problems[] = [
                'line' => $argument['line'],
                'message' => sprintf(
                    'the text passed to %s() is not a single string literal, so make-pot cannot extract it',
                    $argument['name']
                ),
            ];
        }
        return $problems;
    }

    /**
     * Every translatable-text argument of every gettext call in the file.
     *
     * @return array<int,array{name:string,index:int,line:int,text:string,literal:bool}>
     */
    private function text_arguments(Source_File $file): array
    {
        $found = [];
        $tokens = $file->tokens();
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || T_STRING !== $token[0] || ! isset(self::TEXT_ARGUMENTS[$token[1]])) {
                continue;
            }
            $previous = $this->previous_significant($tokens, $i);
            if (null !== $previous && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $arguments = $this->argument_tokens($tokens, $i);
            if (null === $arguments) {
                continue;
            }
            foreach (self::TEXT_ARGUMENTS[$token[1]] as $position) {
                if (! isset($arguments[$position])) {
                    continue;
                }
                $parts = $arguments[$position];
                $literal = 1 === count($parts)
                    && is_array($parts[0])
                    && T_CONSTANT_ENCAPSED_STRING === $parts[0][0];
                $found[] = [
                    'name' => $token[1],
                    'index' => $i,
                    'line' => $token[2],
                    'text' => $literal ? (string) $parts[0][1] : '',
                    'literal' => $literal,
                ];
            }
        }
        return $found;
    }

    /**
     * A printf placeholder, ignoring the escaped %% that renders a literal
     * percent sign and is not a placeholder.
     */
    private function has_placeholder(string $text): bool
    {
        return '' !== $this->first_placeholder($text);
    }

    private function first_placeholder(string $text): string
    {
        $text = str_replace('%%', '', $text);
        return preg_match('/%(?:\\d+\\$)?[-+ 0\']*[0-9]*(?:\\.[0-9]+)?[bcdeEfFgGosuxX]/', $text, $matches)
            ? $matches[0]
            : '';
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private function has_translators_comment(array $tokens, int $index, int $line): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_string($token)) {
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $ends_on = $token[2] + substr_count((string) $token[1], "\n");
                if ($ends_on < $line - 1) {
                    return false;
                }
                if (false !== stripos((string) $token[1], 'translators:')) {
                    return true;
                }
                continue;
            }
            if ($token[2] < $line - 1) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param  array<int,array|string> $tokens
     * @return array{0:array|string}[]|null
     */
    private function argument_tokens(array $tokens, int $index): ?array
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
        $arguments = [[]];
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
                    $arguments[] = [];
                    continue;
                }
                $arguments[count($arguments) - 1][] = $token;
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $arguments[count($arguments) - 1][] = $token;
        }
        return $arguments;
    }

    /**
     * @param  array<int,array|string> $tokens
     * @return array|null
     */
    private function previous_significant(array $tokens, int $index): ?array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_string($token)) {
                return null;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }
        return null;
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
