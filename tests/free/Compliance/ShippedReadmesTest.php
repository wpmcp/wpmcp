<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Trademark_Rule;

/**
 * Every readme.txt in this repository is a wp.org listing for some build.
 *
 * `Plugin_Source::readme()` only ever resolves the readme at the root of the
 * tree it is pointed at, so a `composer compliance` run over the checkout sees
 * the root readme and nothing else. The flavor readmes under `scripts/flavors/`
 * are copied verbatim into their zips (`scripts/build-wporg-release.sh:37`,
 * `scripts/build-woo-release.sh:25`), which means a trademarked tag in one of
 * them is invisible to the engine and surfaces for the first time in front of a
 * directory reviewer. These tests close that gap by asserting the guideline
 * directly against every readme the build scripts can ship.
 */
class ShippedReadmesTest extends \WP_UnitTestCase
{
    /** Guideline 12: five tags is the maximum. */
    private const MAX_TAGS = 5;

    public function test_the_shipped_readme_set_is_the_one_the_build_scripts_copy(): void
    {
        $this->assertSame(
            ['readme.txt', 'scripts/flavors/woocommerce/readme.txt', 'scripts/flavors/wporg/readme.txt'],
            array_keys($this->shipped_readmes()),
            'a new flavor readme needs the build script wired and this list updated'
        );
    }

    public function test_no_shipped_readme_tags_a_third_party_vendor(): void
    {
        foreach ($this->shipped_readmes() as $relative => $absolute) {
            foreach ($this->tags($absolute) as $tag) {
                foreach (Trademark_Rule::VENDOR_MARKS as $mark) {
                    $this->assertStringNotContainsString(
                        $mark,
                        $this->slugify($tag),
                        sprintf('%s tags "%s", which carries the third-party mark "%s"', $relative, $tag, $mark)
                    );
                }
            }
        }
    }

    public function test_no_shipped_readme_exceeds_the_tag_maximum(): void
    {
        foreach ($this->shipped_readmes() as $relative => $absolute) {
            $tags = $this->tags($absolute);
            $this->assertNotEmpty($tags, sprintf('%s has no Tags line', $relative));
            $this->assertLessThanOrEqual(
                self::MAX_TAGS,
                count($tags),
                sprintf('%s lists %d tags; guideline 12 allows %d', $relative, count($tags), self::MAX_TAGS)
            );
        }
    }

    public function test_the_root_readme_carries_the_documented_tag_list(): void
    {
        // WPORG-SUBMISSION.md pins one tag list for the directory listing;
        // three readmes drifting into three different lists is how the
        // trademarked tag survived in the first place.
        $documented = ['mcp', 'mcp server', 'ai agent', 'automation', 'undo'];

        $this->assertSame($documented, $this->tags($this->repository() . '/readme.txt'));
        $this->assertSame($documented, $this->tags($this->repository() . '/scripts/flavors/wporg/readme.txt'));
    }

    /** @return array<string,string> repository-relative path => absolute path */
    private function shipped_readmes(): array
    {
        $root = $this->repository();
        $found = ['readme.txt' => $root . '/readme.txt'];
        foreach (glob($root . '/scripts/flavors/*/readme.txt') ?: [] as $absolute) {
            $found[substr($absolute, strlen($root) + 1)] = $absolute;
        }
        ksort($found);

        return $found;
    }

    /** @return string[] the Tags header, split and trimmed */
    private function tags(string $absolute): array
    {
        $this->assertFileExists($absolute);
        $contents = (string) file_get_contents($absolute);
        if (! preg_match('/^Tags:\s*(.*)$/mi', $contents, $matches)) {
            return [];
        }
        $tags = array_map('trim', explode(',', $matches[1]));

        return array_values(array_filter($tags, static fn ($tag) => '' !== $tag));
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));

        return trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
    }

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }
}
