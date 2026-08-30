<?php

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

/**
 * Post-conditions of the wp.org directory strip (issue #159).
 *
 * scripts/build-wporg-release.sh gates the staged tree at build time, but the
 * build only runs in CI's compliance job and on a release. This suite runs the
 * same strip against a throwaway stage on every test run, so a rename or a
 * reworded document that puts pay-to-unlock surface back into the directory cut
 * fails here first, with a diff, instead of at release time.
 *
 * The strip only ever touches src/, so a stage containing just src/ is enough
 * to exercise it end to end.
 */
class WporgStripTest extends TestCase
{
    private static string $stage = '';
    private static string $root = '';

    /**
     * Paid-tier and licensing surface. These are the build script's gate 3
     * patterns, kept here so both scanners fail on the same vocabulary.
     *
     * @var string[]
     */
    private const PAID_SOURCE_PATTERNS = [
        '/Pro\\\\Gate/',
        '/\bis_pro\b/',
        '/\bGate::/',
        '/\bpro_active\b/',
        '/\bpro_locked\b/',
        '/can_use_premium_code/',
        '/[Ff]reemius/',
        '/WPMCP_FS_/',
        '/\bfs_dynamic_init\b/',
        '/^\s*\'pro\',\s*$/m',
        '/\'tier\'\s*=>\s*\'pro\'/',
    ];

    /**
     * Pay-to-unlock copy, applied to every staged file whatever its
     * extension. Guideline 9 is about what the user is told as much as what
     * the code does, and the bundled SKILL.md playbooks are read by agents.
     *
     * @var string[]
     */
    private const LICENSING_COPY_PATTERNS = [
        '/pro licen[sc]e/i',
        '/pro[ -]?tier/i',
        '/premium/i',
        '/unlicensed/i',
        '/\blicence\b/i',
        '/\blicense\b/i',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$root = dirname(__DIR__, 3);
        $parent = sys_get_temp_dir() . '/wpmcp-strip-' . bin2hex(random_bytes(6));
        self::$stage = $parent . '/wpmcp';
        mkdir(self::$stage, 0777, true);

        exec(
            sprintf('cp -R %s %s', escapeshellarg(self::$root . '/src'), escapeshellarg(self::$stage . '/src')),
            $out,
            $status
        );
        if (0 !== $status) {
            self::fail('could not stage src/ for the strip');
        }

        exec(
            sprintf(
                'php %s %s 2>&1',
                escapeshellarg(self::$root . '/scripts/flavors/wporg/strip.php'),
                escapeshellarg(self::$stage)
            ),
            $strip_out,
            $strip_status
        );
        if (0 !== $strip_status) {
            self::fail("strip.php failed against a fresh stage:\n" . implode("\n", $strip_out));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$stage && is_dir(self::$stage)) {
            exec(sprintf('rm -rf %s', escapeshellarg(dirname(self::$stage))));
        }
        parent::tearDownAfterClass();
    }

    /**
     * Every path the strip declares it removes has to be gone. remove_path()
     * ignores the return value of unlink()/rmdir(), so a partial removal is
     * otherwise silent.
     */
    public function test_every_declared_removed_path_is_absent_from_the_stage(): void
    {
        $removed = self::removed_paths();
        $this->assertNotEmpty($removed, 'REMOVED_PATHS could not be read out of strip.php');

        foreach ($removed as $relative) {
            $this->assertFileExists(
                self::$root . '/' . $relative,
                "REMOVED_PATHS names {$relative}, which no longer exists in the source tree"
            );
            $this->assertFileDoesNotExist(
                self::$stage . '/' . $relative,
                "{$relative} survived the wp.org strip"
            );
        }
    }

    public function test_pro_gate_and_licensing_bootstrap_are_absent(): void
    {
        $this->assertFileDoesNotExist(self::$stage . '/src/Pro', 'src/Pro is still in the directory cut');
        $this->assertFileDoesNotExist(self::$stage . '/src/Pro/Gate.php', 'src/Pro/Gate.php is still in the directory cut');
        $this->assertFileDoesNotExist(self::$stage . '/src/Freemius', 'src/Freemius is still in the directory cut');
    }

    /** No paid predicate survives in the staged PHP. */
    public function test_staged_source_has_no_paid_predicate(): void
    {
        $hits = [];
        foreach (self::staged_files('php') as $file) {
            $contents = (string) file_get_contents($file);
            foreach (self::PAID_SOURCE_PATTERNS as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = self::relative($file) . ' matches ' . $pattern;
                }
            }
        }

        $this->assertSame([], $hits, "paid predicate survived the strip:\n" . implode("\n", $hits));
    }

    /**
     * The bundled playbooks ship inside src/, so they are in the zip. A
     * document that still tells the agent a feature needs a licence is the
     * same guideline 9 problem as the code that used to enforce it.
     */
    public function test_staged_documents_carry_no_pay_to_unlock_copy(): void
    {
        $hits = [];
        foreach (self::staged_files(null) as $file) {
            if ('php' === strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                continue;
            }
            foreach (file($file) ?: [] as $number => $line) {
                foreach (self::LICENSING_COPY_PATTERNS as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = sprintf('%s:%d %s', self::relative($file), $number + 1, trim($line));
                        continue 2;
                    }
                }
            }
        }

        $this->assertSame([], $hits, "pay-to-unlock copy survived the strip:\n" . implode("\n", $hits));
    }

    /**
     * The free-tier snapshot quota is the guideline 5 "quota that a payment
     * lifts". It has to be one flat filterable number, and no document may
     * describe a cap that only unlicensed sites get.
     */
    public function test_snapshot_retention_is_one_flat_filterable_number(): void
    {
        $store = (string) file_get_contents(self::$stage . '/src/Safety/Snapshot_Store.php');
        $this->assertStringContainsString('public static function history_limit(): int', $store);
        $this->assertStringContainsString('wpmcp_snapshot_history_limit', $store);
        $this->assertStringNotContainsString('Gate::history_limit', $store);
    }

    /** @return string[] REMOVED_PATHS as declared by the strip script. */
    private static function removed_paths(): array
    {
        $source = (string) file_get_contents(self::$root . '/scripts/flavors/wporg/strip.php');
        if (! preg_match('/const REMOVED_PATHS = \[(.*?)\n\];/s', $source, $m)) {
            return [];
        }
        preg_match_all("/^\s*'([^']+)',/m", $m[1], $paths);

        return $paths[1];
    }

    /**
     * @param string|null $extension Extension filter, or null for every file.
     * @return string[]
     */
    private static function staged_files(?string $extension): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::$stage,
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if (null !== $extension && strtolower($file->getExtension()) !== $extension) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace(self::$stage, '', $path), '/');
    }
}
