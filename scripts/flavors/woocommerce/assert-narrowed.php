<?php
/**
 * Fail the WordPress.org WooCommerce build if anything the narrowing strips
 * is back (issue #1140).
 *
 *   php scripts/flavors/woocommerce/assert-narrowed.php <staged-or-extracted-plugin-dir>
 *
 * Re-derives everything from the tree it is pointed at and never trusts the
 * strip to have done its job, the way the other flavor gates do. Runs over
 * the stage before the zip and again over the extracted zip. What it checks,
 * against scripts/flavors/woocommerce/narrowed.php:
 *
 *   1. no listed path exists, and no file sits where a listed class would
 *      autoload from;
 *   2. in every PHP file under src/ and the main file, by token: no listed
 *      class is declared, imported, named fully qualified, named in a string,
 *      or named unqualified from inside its own namespace; no listed method
 *      is declared; and no listed ability name or stripped action name
 *      appears as a string literal outside src/MCP/Not_In_Build.php;
 *   3. no shipped document (readme.txt, every non-PHP file under src/) names
 *      a listed ability or stripped action;
 *   4. src/MCP/Not_In_Build.php ships, refuses exactly the listed abilities
 *      and actions, and both transports consult it first.
 *
 * vendor/ is not scanned: it holds third-party code that cannot name these.
 *
 * Exit 0 when clean, 1 with one finding per line on stderr, 2 on bad usage.
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
if ('' === $root || ! is_dir($root . '/src')) {
    fwrite(STDERR, "usage: assert-narrowed.php <plugin-dir>\n");
    exit(2);
}

/** @var array{abilities: array<string,string>, classes: string[], paths: string[], methods: string[], actions: array<string,array<string,string>>} $narrowed */
$narrowed  = require __DIR__ . '/narrowed.php';
$abilities = $narrowed['abilities'];
$classes   = array_fill_keys(array_map(static fn (string $c): string => ltrim($c, '\\'), $narrowed['classes']), true);
$methods   = array_fill_keys($narrowed['methods'], true);
$actions   = $narrowed['actions'];
$literals  = [];
foreach ($actions as $action_list) {
    $literals = array_merge($literals, array_keys($action_list));
}
$refusal   = 'src/MCP/Not_In_Build.php';

$findings = [];

// 1. Paths, and the autoload location of every listed class.
foreach ($narrowed['paths'] as $relative) {
    if (file_exists($root . '/' . $relative)) {
        $findings[] = "$relative: stripped path is present";
    }
}
foreach (array_keys($classes) as $class) {
    $relative = 'src/' . str_replace('\\', '/', substr($class, strlen('WPMCP\\'))) . '.php';
    if (file_exists($root . '/' . $relative)) {
        $findings[] = "$relative: stripped class $class is present";
    }
}

// 2. PHP, by token.
$php_files = [];
$documents = [];
$iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile()) {
        continue;
    }
    if ('php' === $file->getExtension()) {
        $php_files[] = $file->getPathname();
    } else {
        $documents[] = $file->getPathname();
    }
}
foreach (glob($root . '/*.php') ?: [] as $main) {
    if ('uninstall.php' !== basename($main)) {
        $php_files[] = $main;
    }
}
sort($php_files);
if (is_file($root . '/readme.txt')) {
    $documents[] = $root . '/readme.txt';
}

$short_names = [];
foreach (array_keys($classes) as $class) {
    $short_names[ substr($class, (int) strrpos($class, '\\') + 1) ][] = $class;
}

foreach ($php_files as $path) {
    $relative = substr($path, strlen($root) + 1);
    $tokens   = token_get_all((string) file_get_contents($path));
    $count    = count($tokens);
    $namespace = '';
    $current_class = null;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[ $i ];
        if (! is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;

        if (T_NAMESPACE === $id) {
            $name = next_significant($tokens, $i);
            if (null !== $name && in_array($name[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                $namespace = $name[1];
            }
            continue;
        }

        if (T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id || T_ENUM === $id) {
            $name = next_significant($tokens, $i);
            if (null !== $name && T_STRING === $name[0]) {
                $current_class = ltrim($namespace . '\\' . $name[1], '\\');
                if (isset($classes[ $current_class ])) {
                    $findings[] = "$relative:$line: declares stripped class $current_class";
                }
            }
            continue;
        }

        if (T_FUNCTION === $id && null !== $current_class) {
            $name = next_significant($tokens, $i);
            if (null !== $name && T_STRING === $name[0] && isset($methods[ $current_class . '::' . $name[1] ])) {
                $findings[] = "$relative:$line: declares stripped method $current_class::{$name[1]}";
            }
            continue;
        }

        if (T_NAME_QUALIFIED === $id || T_NAME_FULLY_QUALIFIED === $id) {
            $named = ltrim($text, '\\');
            if (isset($classes[ $named ])) {
                $findings[] = "$relative:$line: names stripped class $named";
            }
            continue;
        }

        if (T_STRING === $id && isset($short_names[ $text ])) {
            foreach ($short_names[ $text ] as $class) {
                if (substr($class, 0, (int) strrpos($class, '\\')) === $namespace && ! is_member_access($tokens, $i)) {
                    $findings[] = "$relative:$line: names stripped class $class";
                }
            }
            continue;
        }

        if (T_CONSTANT_ENCAPSED_STRING === $id) {
            $value = stripcslashes(substr($text, 1, -1));
            $named = ltrim(str_replace('\\\\', '\\', $value), '\\');
            if (isset($classes[ $named ])) {
                $findings[] = "$relative:$line: names stripped class $named in a string";
            }
            if ($relative !== $refusal && in_array($value, $literals, true)) {
                $findings[] = "$relative:$line: stripped literal '$value' is present";
            }
            if ($relative !== $refusal && isset($abilities[ $value ])) {
                $findings[] = "$relative:$line: names stripped ability $value";
            }
        }
    }
}

// 3. Shipped documents.
foreach ($documents as $path) {
    $relative = substr($path, strlen($root) + 1);
    $lines    = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
    foreach ($lines as $index => $text) {
        foreach (array_merge(array_keys($abilities), $literals) as $needle) {
            if (preg_match('~(?<![A-Za-z0-9/_-])' . preg_quote($needle, '~') . '(?![A-Za-z0-9_-])~', $text)) {
                $findings[] = sprintf('%s:%d: names stripped %s', $relative, $index + 1, $needle);
            }
        }
    }
}

// 4. The refusal ships, matches the list, and both transports use it.
if (! is_file($root . '/' . $refusal)) {
    $findings[] = "$refusal: the not-in-this-build refusal is missing";
} else {
    [$refused, $refused_actions] = refusal_lists($root . '/' . $refusal);
    foreach (array_diff_key($abilities, $refused) as $name => $_) {
        $findings[] = "$refusal: does not refuse stripped ability $name";
    }
    foreach (array_diff_key($refused, $abilities) as $name => $_) {
        $findings[] = "$refusal: refuses $name, which narrowed.php does not strip";
    }
    $wanted_actions = [];
    foreach ($actions as $ability => $action_list) {
        foreach (array_keys($action_list) as $action) {
            $wanted_actions["$ability $action"] = true;
        }
    }
    foreach (array_diff_key($wanted_actions, $refused_actions) as $pair => $_) {
        $findings[] = "$refusal: does not refuse stripped action $pair";
    }
    foreach (array_diff_key($refused_actions, $wanted_actions) as $pair => $_) {
        $findings[] = "$refusal: refuses action $pair, which narrowed.php does not strip";
    }
}
foreach (
    [
        'src/MCP/Hidden_Tool_Refusal.php' => '$not_in_build = Not_In_Build::for_message($message);',
        'src/MCP/Stdio_Transport.php'     => '$not_in_build = Not_In_Build::refusal($tool_name, ',
    ] as $relative => $needle
) {
    if (! str_contains((string) @file_get_contents($root . '/' . $relative), $needle)) {
        $findings[] = "$relative: does not consult Not_In_Build::refusal()";
    }
}

if ([] !== $findings) {
    fwrite(STDERR, "the WooCommerce wp.org build ships something the narrowing strips (scripts/flavors/woocommerce/narrowed.php):\n  " . implode("\n  ", array_unique($findings)) . "\n");
    exit(1);
}

printf("ok: none of the %d stripped abilities, %d classes or %d paths ship\n", count($abilities), count($classes), count($narrowed['paths']));
exit(0);

// ---------------------------------------------------------------- helpers

/**
 * The next token after $i that is not whitespace or a comment.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 * @return array{0:int,1:string,2:int}|null
 */
function next_significant(array $tokens, int $i): ?array
{
    for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
        $token = $tokens[ $j ];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($token) ? $token : null;
    }

    return null;
}

/**
 * Whether the T_STRING at $i is a property, method or constant name
 * (`->Name`, `?->Name`, `::Name`) rather than a class reference.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
function is_member_access(array $tokens, int $i): bool
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $token = $tokens[ $j ];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    return false;
}

/**
 * Not_In_Build::ABILITIES keys, and "ability action" pairs from
 * Not_In_Build::ACTIONS, read by token so the gate never executes shipped
 * code.
 *
 * @return array{0: array<string, true>, 1: array<string, true>}
 */
function refusal_lists(string $path): array
{
    $tokens    = token_get_all((string) file_get_contents($path));
    $abilities = [];
    $actions   = [];
    $const     = null;
    $depth     = 0;
    $outer     = null;
    foreach ($tokens as $i => $token) {
        if (null === $const) {
            if (is_array($token) && T_STRING === $token[0] && in_array($token[1], ['ABILITIES', 'ACTIONS'], true) && previous_is_const($tokens, $i)) {
                $const = $token[1];
                $depth = 0;
            }
            continue;
        }
        if ('[' === $token) {
            $depth++;
            continue;
        }
        if (']' === $token) {
            $depth--;
            if (0 === $depth) {
                $const = null;
            }
            continue;
        }
        if (! is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
            continue;
        }
        $next = next_significant($tokens, $i);
        if (null === $next || T_DOUBLE_ARROW !== $next[0]) {
            continue;
        }
        $key = stripcslashes(substr($token[1], 1, -1));
        if ('ABILITIES' === $const && 1 === $depth) {
            $abilities[ $key ] = true;
        } elseif ('ACTIONS' === $const && 1 === $depth) {
            $outer = $key;
        } elseif ('ACTIONS' === $const && 2 === $depth && null !== $outer) {
            $actions[ "$outer $key" ] = true;
        }
    }

    return [$abilities, $actions];
}

/**
 * Whether the token before $i, skipping whitespace and comments, is `const`,
 * so a constant's declaration is read and a later self::NAME use is not.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
function previous_is_const(array $tokens, int $i): bool
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $token = $tokens[ $j ];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($token) && T_CONST === $token[0];
    }

    return false;
}
