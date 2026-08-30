<?php

namespace WPMCP\Compliance;

/**
 * A single file inside the scanned plugin, with the token and line lookups
 * the rules need.
 *
 * Token-level lookups exist so that pattern strings and documentation
 * comments never false-positive: scripts/lib/exec-gate.php already learned
 * that lesson for eval/proc_open, and every construct rule here follows it.
 */
final class Source_File
{
    private ?string $contents = null;
    /** @var array<int,string>|null */
    private ?array $lines = null;
    /** @var array<int,array|string>|null */
    private ?array $tokens = null;

    public function __construct(private string $absolute_path, private string $relative_path)
    {
    }

    public function path(): string
    {
        return $this->absolute_path;
    }

    public function relative_path(): string
    {
        return $this->relative_path;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->relative_path, PATHINFO_EXTENSION));
    }

    public function is_php(): bool
    {
        return 'php' === $this->extension();
    }

    public function contents(): string
    {
        if (null === $this->contents) {
            $raw = @file_get_contents($this->absolute_path);
            $this->contents = false === $raw ? '' : $raw;
        }
        return $this->contents;
    }

    /**
     * @return array<int,string> 1-indexed lines, newlines stripped.
     */
    public function lines(): array
    {
        if (null === $this->lines) {
            $split = preg_split('/\r\n|\r|\n/', $this->contents()) ?: [];
            $this->lines = [];
            foreach ($split as $i => $text) {
                $this->lines[$i + 1] = $text;
            }
        }
        return $this->lines;
    }

    public function line(int $number): string
    {
        return $this->lines()[$number] ?? '';
    }

    /**
     * True when $number carries a justified phpcs:ignore for $sniff, either
     * on the line itself or on the line immediately above it.
     *
     * PHPCS, and therefore Plugin Check, honours these annotations, so a rule
     * that mirrors a sniff has to honour them too or it contradicts its own
     * remediation advice. WordPressCS requires a justification after "--";
     * a bare "phpcs:ignore" suppresses nothing here, which keeps the
     * annotation from becoming a silent mute button.
     *
     * @param string $sniff sniff code, or a prefix of one
     */
    public function has_phpcs_ignore(int $number, string $sniff): bool
    {
        foreach ([$number, $number - 1] as $candidate) {
            $text = $this->line($candidate);
            if (! preg_match('/phpcs:ignore\s+([^-\n]*?)\s*--\s*(\S.*)$/', $text, $matches)) {
                continue;
            }
            if ('' === trim($matches[2])) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', trim($matches[1])) ?: [] as $code) {
                // PHPCS matches an annotation against a sniff by prefix, so
                // "WordPress.Security" suppresses every code under it. The
                // rule may pass either the sniff or a partial name, so a
                // prefix in either direction counts.
                if ('' === $code) {
                    continue;
                }
                if (str_starts_with($sniff, $code) || str_starts_with($code, $sniff)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A trimmed, length-capped copy of a line, for the evidence column.
     */
    public function snippet(int $number, int $max = 120): string
    {
        $text = trim($this->line($number));
        if (strlen($text) > $max) {
            $text = substr($text, 0, $max - 3) . '...';
        }
        return $text;
    }

    /**
     * @return array<int,array|string>
     */
    public function tokens(): array
    {
        if (null === $this->tokens) {
            $this->tokens = $this->is_php() ? @token_get_all($this->contents()) : [];
        }
        return $this->tokens;
    }

    /**
     * Call sites of any of $names.
     *
     * A call site is a T_STRING equal to one of the names whose next
     * significant token is "(". Comments and string literals cannot match,
     * because they are different token types.
     *
     * @param  string[] $names           lowercase function names
     * @param  bool     $include_members whether ->name() and ::name() count
     * @return array<int,array{name:string,line:int}>
     */
    public function find_calls(array $names, bool $include_members = true): array
    {
        $wanted = array_flip($names);
        $tokens = $this->tokens();
        $found = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            if (! isset($wanted[strtolower($token[1])])) {
                continue;
            }
            if ('(' !== $this->next_significant($tokens, $index)) {
                continue;
            }
            if (! $include_members) {
                $previous = $this->previous_significant($tokens, $index);
                if (in_array($previous, ['->', '::', '?->', 'function'], true)) {
                    continue;
                }
            }
            $found[] = [
                'name' => strtolower($token[1]),
                'line' => $token[2],
                'array_value' => $this->call_is_array_element_value($tokens, $index),
            ];
        }
        return $found;
    }

    /**
     * True when the call sits on the right of a "=>", i.e. its result is a
     * value in an array literal. Walks back over a "Foo::" or "$x->" prefix
     * first so Gate::is_pro() is judged on where the whole expression sits.
     *
     * @param array<int,array|string> $tokens
     */
    private function call_is_array_element_value(array $tokens, int $index): bool
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_STATIC];
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if (in_array($token, ['-', '>'], true)) {
                    continue;
                }
                return false;
            }
            if (in_array($token[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            if (in_array($token[0], $skip, true)) {
                continue;
            }
            return T_DOUBLE_ARROW === $token[0];
        }
        return false;
    }

    /**
     * Every T_STRING matching $names regardless of what follows it. Use for
     * constants and for names referenced without a call, never for comments.
     *
     * @param  string[] $names
     * @return array<int,array{name:string,line:int}>
     */
    public function find_symbols(array $names): array
    {
        $wanted = array_flip($names);
        $found = [];
        foreach ($this->tokens() as $token) {
            if (! is_array($token)) {
                continue;
            }
            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $bare = strtolower(substr(strrchr('\\' . $token[1], '\\') ?: '', 1));
            if (isset($wanted[$bare])) {
                $found[] = ['name' => $bare, 'line' => $token[2]];
            }
        }
        return $found;
    }

    /**
     * Lines carrying a token of any of the given token ids.
     *
     * @param  int[] $ids
     * @return int[]
     */
    public function lines_with_tokens(array $ids): array
    {
        $lines = [];
        foreach ($this->tokens() as $token) {
            if (is_array($token) && in_array($token[0], $ids, true)) {
                $lines[] = $token[2];
            }
        }
        return array_values(array_unique($lines));
    }

    /**
     * Every string literal in the file, with its line.
     *
     * @return array<int,array{value:string,line:int}>
     */
    public function string_literals(): array
    {
        $literals = [];
        $tokens = $this->tokens();
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $literals[] = [
                    'value' => $this->unquote($token[1]),
                    'line' => $token[2],
                    'array_value' => $this->is_array_element_value($tokens, $index),
                ];
                continue;
            }
            if (T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                $literals[] = [
                    'value' => $token[1],
                    'line' => $token[2],
                    'array_value' => $this->is_array_element_value($tokens, $index),
                ];
            }
        }
        return $literals;
    }

    /**
     * True when the literal sits on the right of a "=>", i.e. it is a value in
     * an array literal. Such a string is data the code hands back, not an
     * expression the code acts on, which is how a URL that is merely linked can
     * be told apart from one that is requested.
     *
     * @param array<int,array|string> $tokens
     */
    private function is_array_element_value(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) && T_DOUBLE_ARROW === $token[0];
        }
        return false;
    }

    /**
     * Regex matches over the raw text, including comments.
     *
     * @return array<int,array{line:int,text:string,match:string}>
     */
    public function grep(string $pattern): array
    {
        $hits = [];
        foreach ($this->lines() as $number => $text) {
            if (preg_match($pattern, $text, $matches)) {
                $hits[] = ['line' => $number, 'text' => $text, 'match' => $matches[0]];
            }
        }
        return $hits;
    }

    public function contains(string $needle, bool $case_insensitive = true): bool
    {
        return $case_insensitive
            ? false !== stripos($this->contents(), $needle)
            : false !== strpos($this->contents(), $needle);
    }

    /**
     * True when the file declares only namespace/use/class-like constructs,
     * i.e. including it has no side effects. Mirrors the exemption in
     * Plugin Check's Direct_File_Access_Check.
     */
    public function is_declaration_only(): bool
    {
        $depth = 0;
        foreach ($this->tokens() as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    if (T_INLINE_HTML === $token[0] && '' !== trim($token[1])) {
                        return false;
                    }
                    continue;
                }
                if (in_array($token[0], [T_NAMESPACE, T_USE, T_DECLARE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true) && 0 === $depth) {
                    continue;
                }
                if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_ABSTRACT, T_FINAL, T_READONLY], true) && 0 === $depth) {
                    // Everything from here is a class body: no side effects.
                    return true;
                }
                if (0 === $depth) {
                    return false;
                }
                continue;
            }
            if (0 === $depth && in_array($token, ['(', ')', ',', '='], true)) {
                return false;
            }
        }
        return true;
    }

    private function unquote(string $literal): string
    {
        $trimmed = trim($literal);
        if (strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            if ("'" === $first || '"' === $first) {
                $trimmed = substr($trimmed, 1, -1);
            }
        }
        return str_replace(['\\\'', '\\"', '\\\\'], ["'", '"', '\\'], $trimmed);
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private function next_significant(array $tokens, int $index): string
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return strtolower($token[1]);
            }
            return $token;
        }
        return '';
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private function previous_significant(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return strtolower($token[1]);
            }
            return $token;
        }
        return '';
    }
}
