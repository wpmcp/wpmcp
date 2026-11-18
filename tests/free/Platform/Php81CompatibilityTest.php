<?php

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

/**
 * readme.txt says "Requires PHP: 8.1", and the local gate runs a newer PHP,
 * so syntax that only PHP 8.2 accepts passes every local run and fatals on
 * an 8.1 site (issue #960: `admit(): true|\WP_Error` stopped the plugin from
 * loading). This scans the shipped code and the tests for the 8.2-only
 * constructs that have reached the tree, so the local gate catches them too:
 *
 *  - `true` as a type, alone or in a union (8.2);
 *  - `false` or `null` as a standalone return type (8.2);
 *  - constants declared in a trait (8.2);
 *  - readonly classes (8.2).
 */
class Php81CompatibilityTest extends TestCase
{
    public function test_no_file_uses_syntax_newer_than_php_8_1(): void
    {
        $root     = dirname(__DIR__, 3);
        $problems = [];
        foreach ([ 'src', 'tests', 'wpmcp.php', 'uninstall.php' ] as $path) {
            foreach ($this->php_files($root . '/' . $path) as $file) {
                foreach (self::problems((string) file_get_contents($file)) as $problem) {
                    $problems[] = substr($file, strlen($root) + 1) . ':' . $problem;
                }
            }
        }

        $this->assertSame([], $problems, "PHP 8.2-only syntax; readme.txt promises PHP 8.1:\n" . implode("\n", $problems));
    }

    public function test_the_scan_finds_each_construct(): void
    {
        $src = "<?php\n"
            . "class A { public function a(): true|\\WP_Error {} public function b(int|true \$x): void {}\n"
            . "public function c(): false {} public function d(): null {} public function e(): bool|false {} }\n"
            . "trait T { const X = 1; public function f(): void { \$y = true | false; } }\n"
            . "readonly class R {}\n"
            . "trait Fine { public function g(): array { return [ 'const' => true ]; } }\n";

        $found = implode("\n", self::problems($src));

        $this->assertStringContainsString('2: true as a type', $found);
        $this->assertSame(2, substr_count($found, 'true as a type'), $found);
        $this->assertStringContainsString('3: false as a standalone return type', $found);
        $this->assertStringContainsString('3: null as a standalone return type', $found);
        $this->assertStringContainsString('4: constant in a trait', $found);
        $this->assertStringContainsString('5: readonly class', $found);
        $this->assertSame(6, count(self::problems($src)), $found);
    }

    /**
     * "line: what" for every 8.2-only construct in $src.
     *
     * @return string[]
     */
    private static function problems(string $src): array
    {
        $tokens = array_values(array_filter(
            token_get_all($src),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)
        ));
        $out         = [];
        $line        = 1;
        $depth       = 0;
        $trait_depth = null;
        $trait_next  = false;
        $count       = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[ $i ];
            if (is_array($t)) {
                $line = $t[2];
            }
            if ('{' === $t || (is_array($t) && in_array($t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true))) {
                $depth++;
                if ($trait_next) {
                    $trait_depth = $depth;
                    $trait_next  = false;
                }
                continue;
            }
            if ('}' === $t) {
                if ($trait_depth === $depth) {
                    $trait_depth = null;
                }
                $depth--;
                continue;
            }
            if (! is_array($t)) {
                continue;
            }
            if (T_TRAIT === $t[0]) {
                $trait_next = true;
                continue;
            }
            if (T_CONST === $t[0] && null !== $trait_depth && $depth === $trait_depth) {
                $out[] = $line . ': constant in a trait';
                continue;
            }
            if (T_READONLY === $t[0] && ($tokens[ $i + 1 ] ?? null) !== null && is_array($tokens[ $i + 1 ]) && T_CLASS === $tokens[ $i + 1 ][0]) {
                $out[] = $line . ': readonly class';
                continue;
            }
            if (! in_array($t[0], [ T_FUNCTION, T_FN ], true)) {
                continue;
            }
            foreach (self::signature_types($tokens, $i) as [$kind, $type]) {
                $names = array_map(
                    static fn ($x): string => strtolower(is_array($x) ? $x[1] : $x),
                    array_values(array_filter($type, static fn ($x): bool => is_array($x)))
                );
                if (in_array('true', $names, true)) {
                    $out[] = $line . ': true as a type';
                } elseif ('return' === $kind && 1 === count($names) && in_array($names[0], [ 'false', 'null' ], true)) {
                    $out[] = $line . ': ' . $names[0] . ' as a standalone return type';
                }
            }
        }

        return $out;
    }

    /**
     * The parameter types and the return type of the function whose
     * `function` or `fn` token is at $i, as [kind, tokens] pairs. A
     * parameter's type is what precedes its variable, so a default value
     * (`$x = true`) is never read as a type.
     *
     * @param array<int,mixed> $tokens
     * @return array<int,array{0:string,1:array<int,mixed>}>
     */
    private static function signature_types(array $tokens, int $i): array
    {
        $count = count($tokens);
        $k     = $i + 1;
        while ($k < $count && '(' !== $tokens[ $k ]) {
            if ('{' === $tokens[ $k ] || ';' === $tokens[ $k ]) {
                return [];
            }
            $k++;
        }
        $out   = [];
        $depth = 0;
        $param = [];
        $typed = true;
        for (; $k < $count; $k++) {
            $t = $tokens[ $k ];
            if ('(' === $t || '[' === $t) {
                $depth++;
                if (1 === $depth) {
                    continue;
                }
            } elseif (')' === $t || ']' === $t) {
                $depth--;
                if (0 === $depth) {
                    if ([] !== $param) {
                        $out[] = [ 'param', $param ];
                    }
                    break;
                }
            }
            if (1 === $depth && ',' === $t) {
                if ([] !== $param) {
                    $out[] = [ 'param', $param ];
                }
                $param = [];
                $typed = true;
                continue;
            }
            if (1 === $depth && is_array($t) && T_VARIABLE === $t[0]) {
                $typed = false;
            }
            if ($typed && 1 === $depth) {
                $param[] = $t;
            }
        }
        if (':' === ($tokens[ $k + 1 ] ?? null)) {
            $return = [];
            for ($j = $k + 2; $j < $count; $j++) {
                $t = $tokens[ $j ];
                if ('{' === $t || ';' === $t || (is_array($t) && T_DOUBLE_ARROW === $t[0])) {
                    break;
                }
                $return[] = $t;
            }
            $out[] = [ 'return', $return ];
        }

        return $out;
    }

    /** @return string[] */
    private function php_files(string $path): array
    {
        if (is_file($path)) {
            return [ $path ];
        }
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' === $file->getExtension()) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
