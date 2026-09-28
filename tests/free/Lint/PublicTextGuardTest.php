<?php

namespace WPMCP\Tests\Free\Lint;

use PHPUnit\Framework\TestCase;

/**
 * Keeps two kinds of text out of every tracked file (vendor/ excepted):
 *
 * 1. Em-dashes and en-dashes. The project writes a comma, colon, period or
 *    plain hyphen instead. The characters are matched by their UTF-8 bytes so
 *    this file never contains one.
 * 2. Names of third-party MCP products. The list itself is private and must
 *    not be published, so it is checked in two ways:
 *    - Always: sha256 digests of the normalized phrases (lowercased, split on
 *      anything that is not a letter or digit, joined by single spaces). Every
 *      line of every file is tokenized the same way and its word n-grams are
 *      compared against the digests, so "Foo MCP", "foo-mcp" and "FOO_mcp" all
 *      match one entry. A digest is obfuscation rather than secrecy (a short
 *      name can be guessed and confirmed), but it keeps the names out of the
 *      repository's text and out of search results while still letting CI
 *      catch a regression.
 *    - Optionally: when WPMCP_SCRUB_PATTERNS_FILE names a readable local file,
 *      each non-empty line in it that does not start with # is also applied
 *      as a case-insensitive regular expression, which is the authoritative
 *      check and picks up entries added since the digests were last updated.
 */
class PublicTextGuardTest extends TestCase
{
    /** Phrase digest => number of words in the phrase. */
    private const PHRASE_DIGESTS = [
        '421a551c8200030f040aefb5b6beec3f6d2855b585f8ee0651d3a10e885f2806' => 1,
        '0cc10b489bd42645cff1bb112c33cdaff39d442db795912ff59970bb8bf4741a' => 1,
        'c554b20cd037ee2b74bc98382b96597d021c55af0c899a629c803c03f55d6c56' => 2,
        '14a3e00ef666f98f934e1c24c990d7cac4a88f1c159bbd9bf7e597aca2060ac7' => 4,
        'bca3970dd1ed199faecb4ecc6d2062c6d3ad173ce7b051ebe715848d8c11ec10' => 3,
        '4b2da5954f4c3fc321001681261842b01c8a5ecfe8e674e53fbaaaff3250efac' => 2,
    ];

    /** Digests of the first word of each phrase: only these start an n-gram check. */
    private const FIRST_WORD_DIGESTS = [
        '421a551c8200030f040aefb5b6beec3f6d2855b585f8ee0651d3a10e885f2806',
        '0cc10b489bd42645cff1bb112c33cdaff39d442db795912ff59970bb8bf4741a',
        'aa43038afb6314a3b24c02613eb13eb4821b24187af19c86431563f46f11ce6f',
        '10182ab855ff772753c05b2fea333666b5f312835d32936b6b03e08ef2cbd6d3',
        '7377260b379f7f5b5e4699a0b7da485b0d357e1881edb969ddb026b3528a5949',
        '32e83e92d45d71f69dcf9d214688f0375542108631b45d344e5df2eb91c11566',
    ];

    private const EN_DASH = "\xE2\x80\x93";
    private const EM_DASH = "\xE2\x80\x94";

    /** @var array<string,string>|null path => contents, shared by every test. */
    private static ?array $files = null;

    public function test_no_tracked_file_contains_an_em_or_en_dash(): void
    {
        $hits = [];
        foreach ($this->tracked_files() as $path => $contents) {
            foreach ($this->lines($contents) as $number => $line) {
                if (str_contains($line, self::EM_DASH) || str_contains($line, self::EN_DASH)) {
                    $hits[] = $path . ':' . $number;
                }
            }
        }

        $this->assertSame([], $hits, 'Replace each em/en-dash with a comma, colon, period or plain hyphen.');
    }

    public function test_no_tracked_file_names_a_listed_third_party_product(): void
    {
        $hits = [];
        foreach ($this->tracked_files() as $path => $contents) {
            foreach ($this->lines($contents) as $number => $line) {
                if ($this->line_matches_a_digest($line)) {
                    $hits[] = $path . ':' . $number;
                }
            }
        }

        $this->assertSame([], $hits, 'Describe the behavior generically instead of naming a third-party product.');
    }

    public function test_no_tracked_file_matches_the_local_pattern_file(): void
    {
        $patterns = $this->local_patterns();
        if (null === $patterns) {
            $this->markTestSkipped('WPMCP_SCRUB_PATTERNS_FILE is not set; the digest check still runs.');
        }

        $hits = [];
        foreach ($this->tracked_files() as $path => $contents) {
            foreach ($this->lines($contents) as $number => $line) {
                foreach ($patterns as $pattern) {
                    if (1 === preg_match($pattern, $line)) {
                        $hits[] = $path . ':' . $number;
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $hits, 'Describe the behavior generically instead of naming a third-party product.');
    }

    public function test_the_digest_matcher_normalizes_case_and_separators(): void
    {
        // Self-check with a phrase that is not on the list, through the same
        // normalization, so the matcher cannot silently stop matching.
        $digests = [hash('sha256', 'acme widget') => 2];
        $firsts  = [hash('sha256', 'acme')];

        $this->assertTrue($this->line_matches_a_digest('uses the ACME-Widget store', $digests, $firsts));
        $this->assertTrue($this->line_matches_a_digest('acme_widget', $digests, $firsts));
        $this->assertFalse($this->line_matches_a_digest('acmewidget', $digests, $firsts));
        $this->assertFalse($this->line_matches_a_digest('acme gadget widget', $digests, $firsts));
    }

    /**
     * @param array<string,int>|null $digests
     * @param string[]|null          $firsts
     */
    private function line_matches_a_digest(string $line, ?array $digests = null, ?array $firsts = null): bool
    {
        static $cache = [];

        $digests ??= self::PHRASE_DIGESTS;
        $firsts    = array_flip($firsts ?? self::FIRST_WORD_DIGESTS);
        $lengths   = array_unique(array_values($digests));

        $words = preg_split('/[^a-z0-9]+/', strtolower($line), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($words)) {
            return false;
        }

        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $word = $words[$i];
            if (! isset($cache[$word])) {
                $cache[$word] = hash('sha256', $word);
            }
            if (! isset($firsts[$cache[$word]])) {
                continue;
            }
            foreach ($lengths as $n) {
                if ($i + $n > $count) {
                    continue;
                }
                $digest = hash('sha256', implode(' ', array_slice($words, $i, $n)));
                if (isset($digests[$digest]) && $digests[$digest] === $n) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return string[]|null compiled patterns, or null when no local file is configured. */
    private function local_patterns(): ?array
    {
        $file = getenv('WPMCP_SCRUB_PATTERNS_FILE');
        if (! is_string($file) || '' === $file) {
            return null;
        }
        if (! is_readable($file)) {
            $this->fail('WPMCP_SCRUB_PATTERNS_FILE is set but not readable: ' . $file);
        }

        $patterns = [];
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $pattern = '~' . str_replace('~', '\~', $line) . '~i';
            $this->assertNotFalse(@preg_match($pattern, ''), 'Invalid pattern in WPMCP_SCRUB_PATTERNS_FILE: ' . $line);
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /** @return array<int,string> 1-based line number => line. */
    private function lines(string $contents): array
    {
        $lines = explode("\n", $contents);

        return array_combine(range(1, count($lines)), $lines);
    }

    /** @return array<string,string> tracked text files outside vendor/, path => contents. */
    private function tracked_files(): array
    {
        if (null !== self::$files) {
            return self::$files;
        }

        $root = dirname(__DIR__, 3);
        if (! is_dir($root . '/.git') && ! is_file($root . '/.git')) {
            $this->markTestSkipped('Not a git checkout; nothing to enumerate tracked files from.');
        }

        $listing = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null');
        if (! is_string($listing) || '' === $listing) {
            $this->markTestSkipped('git ls-files is unavailable here.');
        }

        $files = [];
        foreach (explode("\0", $listing) as $path) {
            if ('' === $path || str_starts_with($path, 'vendor/')) {
                continue;
            }
            $full = $root . '/' . $path;
            if (! is_file($full)) {
                continue;
            }
            $contents = (string) file_get_contents($full);
            if (str_contains(substr($contents, 0, 8000), "\0")) {
                continue; // Binary.
            }
            $files[$path] = $contents;
        }

        return self::$files = $files;
    }
}
