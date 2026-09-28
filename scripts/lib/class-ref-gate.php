<?php

/**
 * Class-reference gate shared by the release builds.
 *
 * Both directory builds prune source files, and a pruned class that shipped
 * code still names builds green and fatals at runtime (the case this was
 * written for: pruning Php_Snippet_Store.php while src/Safety still named it).
 * This walks a staged tree and resolves every WPMCP class it names against
 * composer's authoritative classmap, the map WordPress autoloads from.
 *
 * "Names" covers every shape a reference can take:
 *  - imports, including an alias (`use WPMCP\A\B as C;`), a comma list
 *    (`use WPMCP\A, WPMCP\B;`) and group use (`use WPMCP\A\{B, C as D};`).
 *    Missing any of these let a pruned class through with a fatal behind it;
 *  - `new WPMCP\...(`;
 *  - static calls and `::class`, which resolve at compile time;
 *  - optionally, string callables ('WPMCP\\Foo::bar'), the shape add_action()
 *    takes, which fatal on the hook rather than at load.
 *
 * A second mode, --hooks, reads add_action()/add_filter() callbacks only, and
 * is the one a flavor build that keeps Plugin.php whole can run over all of
 * src. Plugin.php names hundreds of classes a vertical build prunes, inside
 * branches that build never enters, so a plain scan of it cannot pass there;
 * but a hook callback naming a pruned class outside such a branch loads fine
 * and fatals the first time the hook fires, which is how the WooCommerce
 * build came to crash on every option rollback. A callback is excused only
 * when an enclosing `if` makes it unreachable in this build: a class_exists()
 * check on the same class, or `$this->group_enabled('<group>')` for a group
 * the flavor never enables (--flavor=<name> reads that list from the staged
 * Plugin::FLAVOR_GROUPS).
 *
 * Kept dependency-free for the same reason as exec-gate.php: the build runs it
 * with plain `php <file>` against the staged tree it is checking.
 *
 * Usage:
 *
 *   php scripts/lib/class-ref-gate.php <stage> <subdir> [--strings]
 *   php scripts/lib/class-ref-gate.php <stage> <subdir> --hooks [--flavor=<name>]
 *
 * Prints each missing reference to STDERR and exits 1 when any is found, 2
 * when the stage or its classmap is missing.
 */

declare(strict_types=1);

final class Class_Ref_Gate
{
    /**
     * Every fully qualified WPMCP class $src names.
     *
     * @return string[]
     */
    public static function named_classes(string $src, bool $include_strings = false): array
    {
        $named = self::imported_classes($src);

        preg_match_all('/new\s+(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)\s*\(/', $src, $news);
        preg_match_all('/(\\\\?WPMCP\\\\[A-Za-z0-9_\\\\]+)::/', $src, $statics);
        $named = array_merge($named, $news[1], $statics[1]);

        if ($include_strings) {
            preg_match_all('/[\'"]\\\\{0,2}(WPMCP(?:\\\\{1,2}[A-Za-z0-9_]+)+)[\'"]/', $src, $strings);
            $named = array_merge($named, $strings[1]);
        }

        // A reference written inside a PHP string ('WPMCP\\Foo::bar') carries
        // doubled separators; normalise them so it resolves like any other.
        $out = [];
        foreach ($named as $class) {
            $out[] = ltrim(str_replace('\\\\', '\\', trim($class)), '\\');
        }

        return array_values(array_unique($out));
    }

    /**
     * Classes named by top-level `use` statements (not `use function` or
     * `use const`), with aliases dropped and group use expanded.
     *
     * @return string[]
     */
    public static function imported_classes(string $src): array
    {
        preg_match_all('/^use\s+(?!function\b|const\b)([^;]+);/m', $src, $statements);

        $out = [];
        foreach ($statements[1] as $statement) {
            $statement = trim($statement);
            $prefix    = '';
            $items     = $statement;

            if (false !== ($brace = strpos($statement, '{'))) {
                $prefix = rtrim(substr($statement, 0, $brace));
                $items  = trim(substr($statement, $brace + 1), " \t\r\n}");
            }

            foreach (explode(',', $items) as $item) {
                $item = preg_replace('/\s+as\s+\w+\s*$/i', '', trim($item));
                if ('' === $item) {
                    continue;
                }
                $class = ltrim($prefix . $item, '\\');
                if (0 === strpos($class, 'WPMCP\\')) {
                    $out[] = $class;
                }
            }
        }

        return $out;
    }

    /**
     * References under $stage/$subdir to classes missing from the stage's
     * composer classmap, as "path -> Class" lines.
     *
     * @return string[]
     */
    public static function missing(string $stage, string $subdir, bool $include_strings = false): array
    {
        $classmap = $stage . '/vendor/composer/autoload_classmap.php';
        $root     = $stage . '/' . $subdir;
        if (! is_file($classmap) || ! is_dir($root)) {
            throw new RuntimeException("class-ref-gate: missing classmap or directory under {$stage}");
        }

        $known = [];
        foreach (array_keys(require $classmap) as $class) {
            $known[strtolower($class)] = true;
        }

        $missing = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            foreach (self::named_classes($src, $include_strings) as $class) {
                if (! isset($known[strtolower($class)])) {
                    $missing[] = $file->getPathname() . ' -> ' . $class;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * add_action()/add_filter() callbacks under $stage/$subdir that name a
     * class missing from the stage's classmap and sit in a branch this build
     * can reach, as "path:line -> Class" lines.
     *
     * @param string[]|null $live_groups ability groups the build enables, or
     *                                   null when every group is live
     * @return string[]
     */
    public static function dangling_hooks(string $stage, string $subdir, ?array $live_groups = null): array
    {
        $known   = self::known_classes($stage, $subdir);
        $missing = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage . '/' . $subdir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            foreach (self::hook_callbacks($src) as [$line, $class, $conditions]) {
                if (isset($known[strtolower($class)]) || self::unreachable($class, $conditions, $live_groups)) {
                    continue;
                }
                $missing[] = $file->getPathname() . ':' . $line . ' -> ' . $class;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * The ability groups $flavor enables, read from the staged
     * Plugin::FLAVOR_GROUPS without loading the class (it exits without
     * ABSPATH), or null when the flavor is not listed there.
     *
     * @return string[]|null
     */
    public static function flavor_groups(string $plugin_src, string $flavor): ?array
    {
        if (! preg_match('/const\s+FLAVOR_GROUPS\s*=\s*\[(.*?)\n    \];/s', $plugin_src, $table)) {
            return null;
        }
        if (! preg_match('/[\'"]' . preg_quote($flavor, '/') . '[\'"]\s*=>\s*\[([^\]]*)\]/', $table[1], $list)) {
            return null;
        }
        preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $list[1], $groups);

        return $groups[1];
    }

    /**
     * Every WPMCP class named by an add_action()/add_filter() callback in
     * $src, with its line and the conditions of the `if` blocks around it.
     *
     * @return array<int,array{0:int,1:string,2:array<int,array>}>
     */
    public static function hook_callbacks(string $src): array
    {
        $tokens    = token_get_all($src);
        $namespace = '';
        $imports   = self::import_map($src);
        $stack     = [];   // one entry per open brace: a condition's tokens, or null
        $pending   = null; // an if/elseif condition waiting for its block
        $out       = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[ $i ];
            if (is_array($t) && T_NAMESPACE === $t[0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && ! in_array($tokens[ $j ], [';', '{'], true); $j++) {
                    if (is_array($tokens[ $j ]) && T_WHITESPACE !== $tokens[ $j ][0]) {
                        $namespace .= $tokens[ $j ][1];
                    }
                }
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_IF, T_ELSEIF], true)) {
                [$pending, $i] = self::balanced($tokens, $i + 1, '(', ')');
                continue;
            }
            if ('{' === $t || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $stack[] = $pending;
                $pending = null;
                continue;
            }
            if ('}' === $t) {
                array_pop($stack);
                continue;
            }
            if (';' === $t) {
                $pending = null;
                continue;
            }
            if (! is_array($t) || T_STRING !== $t[0] || ! in_array(strtolower($t[1]), ['add_action', 'add_filter'], true)) {
                continue;
            }
            $prev = self::previous_code_token($tokens, $i);
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            [$args, $end] = self::balanced($tokens, $i + 1, '(', ')');
            if (null === $args) {
                continue;
            }
            $callback   = self::argument($args, 1);
            $conditions = array_values(array_filter(array_merge($stack, [$pending])));
            foreach (self::classes_in($callback, $namespace, $imports) as $class) {
                $out[] = [$t[2], $class, array_map(
                    static fn (array $c): array => ['text' => self::text($c), 'classes' => self::classes_in($c, $namespace, $imports)],
                    $conditions
                )];
            }
            $i = $end;
        }

        return $out;
    }

    /**
     * Whether one of $conditions keeps a callback naming $class from running
     * in this build.
     *
     * @param array<int,array{text:string,classes:string[]}> $conditions
     * @param string[]|null $live_groups
     */
    private static function unreachable(string $class, array $conditions, ?array $live_groups): bool
    {
        foreach ($conditions as $condition) {
            $text = $condition['text'];
            if (false === strpos($text, '||')
                && preg_match('/(?<!!)\bclass_exists\(/', str_replace(' ', '', $text))
                && ! preg_match('/!\s*class_exists/', $text)
                && in_array(strtolower($class), array_map('strtolower', $condition['classes']), true)) {
                return true;
            }
            if (null !== $live_groups
                && preg_match('/^\(\s*\$this->group_enabled\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)\s*\)$/', $text, $m)
                && ! in_array($m[1], $live_groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The WPMCP classes a token run names: `X::class`, `new X`, and string
     * callables ('WPMCP\\X' or 'WPMCP\\X::method'), resolved against the
     * file's namespace and imports.
     *
     * @param array<int,mixed> $tokens
     * @param array<string,string> $imports
     * @return string[]
     */
    private static function classes_in(array $tokens, string $namespace, array $imports): array
    {
        $names = [];
        $code  = array_values(array_filter($tokens, static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $n     = count($code);
        for ($k = 0; $k < $n; $k++) {
            $t = $code[ $k ];
            if (! is_array($t)) {
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $t[0]) {
                $value = str_replace('\\\\', '\\', substr($t[1], 1, -1));
                if (preg_match('/^\\\\?(WPMCP(?:\\\\[A-Za-z0-9_]+)+)(?:::\w+)?$/', $value, $m)) {
                    $names[] = $m[1];
                }
                continue;
            }
            if (! self::is_name($t)) {
                continue;
            }
            $next  = $code[ $k + 1 ] ?? null;
            $after = $code[ $k + 2 ] ?? null;
            $prev  = $code[ $k - 1 ] ?? null;
            $is_class_const = is_array($next) && T_DOUBLE_COLON === $next[0] && is_array($after) && 'class' === strtolower($after[1]);
            $is_new         = is_array($prev) && T_NEW === $prev[0];
            if ($is_class_const || $is_new) {
                $names[] = self::resolve($t, $namespace, $imports);
            }
        }

        $out = [];
        foreach ($names as $name) {
            $name = ltrim($name, '\\');
            if (0 === strpos($name, 'WPMCP\\')) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param mixed $t */
    private static function is_name($t): bool
    {
        $kinds = [T_STRING];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $kind) {
            if (defined($kind)) {
                $kinds[] = constant($kind);
            }
        }

        return is_array($t) && in_array($t[0], $kinds, true)
            && ! in_array(strtolower($t[1]), ['self', 'static', 'parent'], true);
    }

    /**
     * @param array{0:int,1:string} $t
     * @param array<string,string> $imports
     */
    private static function resolve(array $t, string $namespace, array $imports): string
    {
        $name = $t[1];
        if ('\\' === $name[0]) {
            return $name;
        }
        if (0 === stripos($name, 'namespace\\')) {
            return $namespace . substr($name, 9);
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($imports[ $first ])) {
            $parts[0] = $imports[ $first ];
            return implode('\\', $parts);
        }

        return ('' === $namespace ? '' : $namespace . '\\') . $name;
    }

    /**
     * Top-level class imports as lowercased alias => fully qualified name.
     *
     * @return array<string,string>
     */
    private static function import_map(string $src): array
    {
        preg_match_all('/^use\s+(?!function\b|const\b)([^;]+);/m', $src, $statements);

        $map = [];
        foreach ($statements[1] as $statement) {
            $statement = trim($statement);
            $prefix    = '';
            $items     = $statement;
            if (false !== ($brace = strpos($statement, '{'))) {
                $prefix = rtrim(substr($statement, 0, $brace));
                $items  = trim(substr($statement, $brace + 1), " \t\r\n}");
            }
            foreach (explode(',', $items) as $item) {
                $item = trim($item);
                if ('' === $item) {
                    continue;
                }
                $alias = null;
                if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $m)) {
                    [$item, $alias] = [$m[1], $m[2]];
                }
                $class = ltrim($prefix . $item, '\\');
                $parts = explode('\\', $class);
                $map[ strtolower($alias ?? end($parts)) ] = $class;
            }
        }

        return $map;
    }

    /**
     * The tokens between the $open at or after $from and its matching $close
     * (both included), and the index of that $close; [null, $from] when no
     * $open comes first.
     *
     * @param array<int,mixed> $tokens
     * @return array{0:array<int,mixed>|null,1:int}
     */
    private static function balanced(array $tokens, int $from, string $open, string $close): array
    {
        $count = count($tokens);
        while ($from < $count && is_array($tokens[ $from ]) && in_array($tokens[ $from ][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $from++;
        }
        if (($tokens[ $from ] ?? null) !== $open) {
            return [null, $from];
        }
        $depth = 0;
        for ($k = $from; $k < $count; $k++) {
            $t = $tokens[ $k ];
            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                $depth--;
                if (0 === $depth) {
                    return [array_slice($tokens, $from, $k - $from + 1), $k];
                }
            }
        }

        return [null, $from];
    }

    /**
     * The tokens of the $index-th top-level argument inside a balanced
     * `( ... )` token run.
     *
     * @param array<int,mixed> $args
     * @return array<int,mixed>
     */
    private static function argument(array $args, int $index): array
    {
        $out   = [[]];
        $depth = 0;
        foreach (array_slice($args, 1, -1) as $t) {
            if (in_array($t, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                $depth--;
            } elseif (',' === $t && 0 === $depth) {
                $out[] = [];
                continue;
            }
            $out[ count($out) - 1 ][] = $t;
        }

        return $out[ $index ] ?? [];
    }

    /**
     * @param array<int,mixed> $tokens
     * @return mixed
     */
    private static function previous_code_token(array $tokens, int $i)
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (! is_array($tokens[ $k ]) || ! in_array($tokens[ $k ][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[ $k ];
            }
        }

        return null;
    }

    /** @param array<int,mixed> $tokens */
    private static function text(array $tokens): string
    {
        $text = '';
        foreach ($tokens as $t) {
            $text .= is_array($t) ? $t[1] : $t;
        }

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * Lowercased class names in the stage's composer classmap.
     *
     * @return array<string,true>
     */
    private static function known_classes(string $stage, string $subdir): array
    {
        $classmap = $stage . '/vendor/composer/autoload_classmap.php';
        if (! is_file($classmap) || ! is_dir($stage . '/' . $subdir)) {
            throw new RuntimeException("class-ref-gate: missing classmap or directory under {$stage}");
        }

        $known = [];
        foreach (array_keys(require $classmap) as $class) {
            $known[strtolower($class)] = true;
        }

        return $known;
    }
}

// -------------------------------------------------------------------- runner
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args    = array_slice($argv, 1);
    $strings = in_array('--strings', $args, true);
    $hooks   = in_array('--hooks', $args, true);
    $flavor  = null;
    foreach ($args as $arg) {
        if (0 === strpos($arg, '--flavor=')) {
            $flavor = substr($arg, 9);
        }
    }
    $args = array_values(array_filter($args, static fn (string $a): bool => 0 !== strpos($a, '--')));
    if (count($args) !== 2) {
        fwrite(STDERR, "usage: class-ref-gate.php <stage> <subdir> [--strings | --hooks [--flavor=<name>]]\n");
        exit(2);
    }

    try {
        if ($hooks) {
            $live = null;
            if (null !== $flavor) {
                $live = Class_Ref_Gate::flavor_groups((string) @file_get_contents($args[0] . '/src/Plugin.php'), $flavor);
                if (null === $live) {
                    throw new RuntimeException("class-ref-gate: no FLAVOR_GROUPS entry for '{$flavor}' in the staged src/Plugin.php");
                }
            }
            $missing = Class_Ref_Gate::dangling_hooks($args[0], $args[1], $live);
        } else {
            $missing = Class_Ref_Gate::missing($args[0], $args[1], $strings);
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }

    if ([] !== $missing) {
        fwrite(STDERR, implode("\n", $missing) . "\n");
        exit(1);
    }
}
