<?php

namespace WPMCP\Tests\Free;

/**
 * The WordPress.org strip (issue #160), asserted against the tree it actually
 * produces rather than against the edit list it is written from.
 *
 * Guideline 5 forbids shipping functionality "restricted or locked, only to be
 * made available by payment or upgrade", so the directory build removes the
 * paid tier instead of gating it. Deleting the Registrar tier branch removed
 * the last runtime backstop, which makes these three invariants the thing that
 * keeps the build honest:
 *
 *  1. every Ability the artifact constructs is free, checked at token level so
 *     a tier computed at runtime (Integration_Dispatcher::tier()) cannot slip
 *     past a source-literal regex;
 *  2. the registrar names no tier at all;
 *  3. no shipped file claims a licence- or tier-dependent behaviour that this
 *     build no longer has, because a reviewer reads the docblocks too.
 *
 * scripts/build-wporg-release.sh gates on the same three from the staged tree.
 */
class WporgStripTest extends \WP_UnitTestCase
{
    /** @var string|null Stage shared by every test in the class; stripped once. */
    private static ?string $stage = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$stage) {
            exec('rm -rf ' . escapeshellarg(dirname(self::$stage)));
            self::$stage = null;
        }
        parent::tearDownAfterClass();
    }

    public function test_every_ability_the_stripped_tree_constructs_is_free_tier(): void
    {
        $offenders = [];

        foreach ($this->stripped_php_files() as $file) {
            foreach ($this->ability_tier_arguments($file) as [$line, $tier]) {
                if ("'free'" !== $tier) {
                    $offenders[] = sprintf('%s:%d passes %s', $this->relative($file), $line, $tier);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The directory build must construct only free abilities, and must do it with a\n"
                . "literal so the build can see it. Non-literal tiers found:\n  "
                . implode("\n  ", $offenders)
        );
    }

    public function test_the_stripped_registrar_never_names_a_tier(): void
    {
        $registrar = $this->stage() . '/src/MCP/Registrar.php';
        $this->assertFileExists($registrar);

        $hits = [];
        foreach (file($registrar) as $i => $line) {
            if (false !== stripos($line, 'tier')) {
                $hits[] = ($i + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame([], $hits, "Registrar.php still names ability tiers:\n  " . implode("\n  ", $hits));
    }

    /**
     * Prose that was true of the full plugin and is false of this build. Each
     * pattern describes a licence- or tier-dependent behaviour of THIS
     * plugin's registration/permission path, so none of them has an innocent
     * reading: a stock-image licence field or a "paid plugin" note about WPML
     * does not match.
     *
     * @return array<string, array{0: string}>
     */
    public static function false_licensing_claims(): array
    {
        return [
            'pro tier'            => ['/pro[- ]tier/i'],
            'pro gate'            => ['/pro[- ]gat(e|ing)/i'],
            'pro licence'         => ['/pro[- ]licen[cs]e/i'],
            'unlicensed'          => ['/unlicensed/i'],
            'without a licence'   => ['/without a (live )?licen[cs]e/i'],
            'a licence lapsing'   => ['/licen[cs]e (that )?(lapse|laps)/i'],
            'payment unlocking'   => ['/payment to unlock/i'],
            'a licence gate'      => ['/licen[cs]e gate/i'],
        ];
    }

    /** @dataProvider false_licensing_claims */
    public function test_no_shipped_file_claims_a_licence_gated_behaviour(string $pattern): void
    {
        $hits = [];
        foreach ($this->stripped_php_files() as $file) {
            foreach (file($file) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = sprintf('%s:%d %s', $this->relative($file), $i + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "A shipped file describes licence gating this build does not have:\n  " . implode("\n  ", $hits)
        );
    }

    /**
     * The free-tier assertion above is only worth anything if the scan can
     * actually see a tier that is not a source literal, which is the exact
     * case Integration_Dispatcher used to be and the case gate 3's
     * "^\s*'pro',$" pattern is blind to.
     */
    public function test_the_tier_scan_reports_a_tier_that_is_not_a_literal(): void
    {
        $fixture = (string) tempnam(sys_get_temp_dir(), 'wpmcp-tier') . '.php';
        file_put_contents($fixture, "<?php\n\$x = new Ability(\n    'wpmcp/demo',\n    \$this->tier(),\n    'desc'\n);\n");

        try {
            $found = $this->ability_tier_arguments($fixture);
        } finally {
            unlink($fixture);
        }

        $this->assertCount(1, $found);
        $this->assertSame('$this->tier()', $found[0][1]);
    }

    // ------------------------------------------------------------- helpers

    /** Stage src/ into a temp dir and run the real strip over it, once. */
    private function stage(): string
    {
        if (null !== self::$stage) {
            return self::$stage;
        }

        $root = dirname(__DIR__, 2);
        $parent = (string) tempnam(sys_get_temp_dir(), 'wpmcp-strip');
        unlink($parent);
        mkdir($parent, 0o777, true);
        $stage = $parent . '/wpmcp';
        mkdir($stage, 0o777, true);

        exec(sprintf('cp -R %s %s', escapeshellarg($root . '/src'), escapeshellarg($stage . '/src')), $out, $status);
        $this->assertSame(0, $status, 'could not stage src/ for the strip');

        exec(
            sprintf('php %s %s 2>&1', escapeshellarg($root . '/scripts/flavors/wporg/strip.php'), escapeshellarg($stage)),
            $output,
            $status
        );
        $this->assertSame(0, $status, "the wp.org strip failed:\n" . implode("\n", $output));

        return self::$stage = $stage;
    }

    /** @return string[] absolute paths of every PHP file in the stripped tree. */
    private function stripped_php_files(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->stage() . '/src'));
        foreach ($it as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return ltrim(substr($file, strlen($this->stage())), '/');
    }

    /**
     * Second constructor argument of every `new Ability(...)` in one file, as
     * source text. Token-level, so a comment or a string mentioning the class
     * cannot produce a hit and a computed tier is reported as what it is.
     *
     * @return array<int, array{0: int, 1: string}> [line, tier expression]
     */
    private function ability_tier_arguments(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || T_NEW !== $tokens[$i][0]) {
                continue;
            }
            $name = '';
            $j = $i + 1;
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE], true)) {
                    continue;
                }
                if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $t[1];
                    continue;
                }
                break;
            }
            if ('Ability' !== ltrim(substr($name, (int) strrpos('\\' . $name, '\\')), '\\')) {
                continue;
            }
            if (! isset($tokens[$j]) || '(' !== $tokens[$j]) {
                continue;
            }

            $args = $this->split_arguments($tokens, $j);
            if (count($args) < 2) {
                continue;
            }
            $found[] = [is_array($tokens[$i]) ? $tokens[$i][2] : 0, $args[1]];
        }

        return $found;
    }

    /**
     * Split the argument list whose '(' sits at $open into trimmed source
     * strings, one per top-level comma.
     *
     * @param array<int, array|string> $tokens
     * @return string[]
     */
    private function split_arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $args = [];
        $current = '';
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                if (1 === $depth) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if (0 === $depth) {
                    $args[] = trim($current);
                    break;
                }
            } elseif (',' === $text && 1 === $depth) {
                $args[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $text;
        }

        return $args;
    }
}
