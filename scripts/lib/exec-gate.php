<?php

/**
 * Token-level execution-construct gate shared by the release builds
 * (issue #167).
 *
 * The directory builds physically remove the two execution call sites
 * (eval in Php_Snippet_Runner, proc_open in Wp_Cli_Executor); this gate
 * re-checks the staged tree from scratch so a strip or prune that silently
 * no-ops can never produce a zip. Token-level on purpose: strings and
 * comments (e.g. Malware_Audit's pattern descriptions) must not
 * false-positive, and a method, property, constant, class name or
 * declaration that happens to share a name with a listed function is not a
 * call to the global function.
 *
 * Every shape a real call site takes is matched, not just the bare one:
 * `\proc_open(...)` and `Foo\system(...)` tokenize as T_NAME_FULLY_QUALIFIED
 * and T_NAME_QUALIFIED on PHP 8, and leading-backslash globals are the house
 * style of exactly the vendor tree this gate now walks. Backticks are shell
 * execution too, and wp.org reviewers read them as shell_exec.
 *
 * Usage:
 *
 *   php scripts/lib/exec-gate.php <path> [<path>...]
 *
 * Each path may be a file or a directory (walked recursively; php, inc and
 * phtml, case-insensitive). Prints every finding to STDERR and exits 1 if any
 * listed construct survives; exits 2 when the gate could not do its job at
 * all (a path that does not exist, an unreadable file or directory), so a
 * build that points the gate at a directory it forgot to stage, or at a tree
 * it cannot read, fails loudly and distinguishably rather than passing clean.
 */

declare(strict_types=1);

final class Exec_Gate
{
    /**
     * The execution family.
     *
     * Kept identical to WPMCP\Compliance\Rules\Dangerous_Constructs_Rule::CONSTRUCTS,
     * which the same build runs over the finished zip: if the two lists drift,
     * one step passes what the other rejects. ExecGateTest pins them together.
     */
    public const EXECUTION_CONSTRUCTS = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'pcntl_exec',
        'create_function',
        'assert',
    ];

    /**
     * Not execution, but rejected on sight by directory reviewers all the
     * same: str_rot13 is the canonical obfuscation tell, move_uploaded_file
     * an unrestricted-upload one (issue #167).
     */
    public const OBFUSCATION_CONSTRUCTS = [
        'str_rot13',
        'move_uploaded_file',
    ];

    /** File extensions that hold PHP, lowercased before comparison. */
    public const EXTENSIONS = ['php', 'inc', 'phtml'];

    /** Tokens that carry no meaning for "what comes before/after this name". */
    private const INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * Everything the gate rejects, execution first.
     *
     * @return string[]
     */
    public static function banned(): array
    {
        return array_merge(self::EXECUTION_CONSTRUCTS, self::OBFUSCATION_CONSTRUCTS);
    }

    /**
     * Scan one file.
     *
     * @throws RuntimeException when the file cannot be read. An I/O failure is
     *                          never a pass: a gate that scans a vanished or
     *                          unreadable file as empty content reports it
     *                          clean, which is the one answer it must not give.
     * @return array<int,array{path:string,line:int,construct:string}>
     */
    public static function scan_file(string $path): array
    {
        $source = @file_get_contents($path);
        if (false === $source) {
            throw new RuntimeException("exec-gate: cannot read file: $path");
        }

        $wanted = array_flip(self::banned());
        $tokens = token_get_all($source);
        $found  = [];
        $line   = 1;
        $ticked = false;

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                if ('`' === $token) {
                    // token_get_all emits the operator as two bare backtick
                    // tokens around the command; report the opening one only.
                    if (! $ticked) {
                        $found[] = ['path' => $path, 'line' => $line, 'construct' => '`` (shell execution)'];
                    }
                    $ticked = ! $ticked;
                }
                continue;
            }

            $line = $token[2] + substr_count($token[1], "\n");

            if (T_EVAL === $token[0]) {
                $found[] = ['path' => $path, 'line' => $token[2], 'construct' => 'eval'];
                continue;
            }

            $name = self::identifier($token);
            if (null === $name || ! isset($wanted[$name])) {
                continue;
            }
            // A name is only a global call when a "(" follows it and no
            // member, declaration or instantiation keyword precedes it. That
            // rules out `class Exec {}`, `const SYSTEM = 1;`, `function
            // popen();`, `$o->exec()`, `C::exec()`, `new Popen()` and type
            // hints, all of which are plausible in the third-party vendor
            // tree the gate walks and none of which is an execution call.
            if ('(' !== self::next_significant($tokens, $index)) {
                continue;
            }
            if (in_array(self::previous_significant($tokens, $index), ['->', '::', '?->', 'function', 'new'], true)) {
                continue;
            }
            $found[] = ['path' => $path, 'line' => $token[2], 'construct' => $name];
        }

        return $found;
    }

    /**
     * Scan a list of files and directories.
     *
     * Every path is validated before anything is scanned, so a build that
     * forgot to stage one of them is told that, rather than being handed a
     * partial list of findings from the paths that did exist.
     *
     * @param  string[] $paths
     * @throws RuntimeException on a path that does not exist or cannot be read.
     * @return array<int,array{path:string,line:int,construct:string}>
     */
    public static function scan_paths(array $paths): array
    {
        foreach ($paths as $path) {
            if (! file_exists($path)) {
                throw new RuntimeException("exec-gate: no such path: $path");
            }
        }

        $found = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $found = array_merge($found, self::scan_file($path));
                continue;
            }
            foreach (self::php_files($path) as $file) {
                $found = array_merge($found, self::scan_file($file));
            }
        }
        return $found;
    }

    /**
     * Every PHP file under a directory.
     *
     * @throws RuntimeException when the tree cannot be walked.
     * @return string[]
     */
    private static function php_files(string $directory): array
    {
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
            );
            $files = [];
            foreach ($items as $item) {
                if (in_array(strtolower($item->getExtension()), self::EXTENSIONS, true)) {
                    $files[] = $item->getPathname();
                }
            }
            return $files;
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException("exec-gate: cannot walk directory $directory: " . $e->getMessage());
        }
    }

    /**
     * The bare function name a token stands for, or null when the token is
     * not an identifier.
     *
     * `\proc_open` and `Foo\system` are the qualified shapes; both reduce to
     * their last segment, lowercased, because PHP resolves an unimported
     * qualified call to a global function when the namespaced one does not
     * exist, and a gate is the wrong place to be optimistic about which.
     *
     * @param array{0:int,1:string,2:int} $token
     */
    private static function identifier(array $token): ?string
    {
        $named = [T_STRING];
        foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $named[] = constant($constant);
            }
        }
        if (! in_array($token[0], $named, true)) {
            return null;
        }
        $text = ltrim($token[1], '\\');
        $last = strrchr($text, '\\');
        return strtolower(false === $last ? $text : substr($last, 1));
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private static function next_significant(array $tokens, int $index): ?string
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], self::INSIGNIFICANT, true)) {
                    continue;
                }
                return $token[1];
            }
            if ('' !== $token) {
                return $token;
            }
        }
        return null;
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private static function previous_significant(array $tokens, int $index): ?string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], self::INSIGNIFICANT, true)) {
                    continue;
                }
                return $token[1];
            }
            if ('' !== $token) {
                return $token;
            }
        }
        return null;
    }
}

// -------------------------------------------------------------------- runner
// Only when invoked as a script: the test suite requires this file for the
// class and must not run the CLI.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $paths = array_slice($argv, 1);
    if ([] === $paths) {
        fwrite(STDERR, "usage: exec-gate.php <path> [<path>...]\n");
        exit(2);
    }

    try {
        $findings = Exec_Gate::scan_paths($paths);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }

    if ([] !== $findings) {
        foreach ($findings as $finding) {
            fwrite(STDERR, $finding['path'] . ':' . $finding['line'] . ' ' . $finding['construct'] . "\n");
        }
        exit(1);
    }
}
