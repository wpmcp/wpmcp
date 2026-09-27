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
 * Kept dependency-free for the same reason as exec-gate.php: the build runs it
 * with plain `php <file>` against the staged tree it is checking.
 *
 * Usage:
 *
 *   php scripts/lib/class-ref-gate.php <stage> <subdir> [--strings]
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
}

// -------------------------------------------------------------------- runner
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args    = array_slice($argv, 1);
    $strings = in_array('--strings', $args, true);
    $args    = array_values(array_diff($args, ['--strings']));
    if (count($args) !== 2) {
        fwrite(STDERR, "usage: class-ref-gate.php <stage> <subdir> [--strings]\n");
        exit(2);
    }

    try {
        $missing = Class_Ref_Gate::missing($args[0], $args[1], $strings);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }

    if ([] !== $missing) {
        fwrite(STDERR, implode("\n", $missing) . "\n");
        exit(1);
    }
}
