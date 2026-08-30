<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\I18n_Rule;
use WPMCP\Compliance\Source_File;

/**
 * Issue #176 (finding B-27) was a placeholder-bearing translatable string with
 * no translators comment, and nothing in the repo could catch the next one:
 * composer lint resolves to a PSR-12 ruleset with no WordPressCS, and the
 * compliance engine had no equivalent check. This test walks the real src/
 * tree so a regression fails here instead of at the wp.org reviewer.
 *
 * It runs the shipped I18n_Rule over every source file rather than
 * reimplementing the sniff, so the guard and the engine cannot drift apart.
 */
class TranslatorsCommentGuardTest extends Compliance_Test_Case
{
    /** @return string[] absolute paths of every PHP file under src/ */
    private function source_files(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @param  callable(I18n_Rule,Source_File):array<int,array{line:int,message:string}> $probe
     * @return string[]
     */
    private function scan(callable $probe): array
    {
        $rule = new I18n_Rule();
        $root = dirname(__DIR__, 3);
        $problems = [];
        foreach ($this->source_files() as $path) {
            $file = new Source_File($path, ltrim(str_replace($root, '', $path), '/'));
            foreach ($probe($rule, $file) as $problem) {
                $problems[] = $file->relative_path() . ':' . $problem['line'] . ' ' . $problem['message'];
            }
        }
        return $problems;
    }

    public function test_every_placeholder_string_in_src_has_a_translators_comment(): void
    {
        $problems = $this->scan(
            static fn (I18n_Rule $rule, Source_File $file): array => $rule->missing_translators_comments($file)
        );

        $this->assertSame(
            [],
            $problems,
            "a translatable string with placeholders needs a /* translators: */ comment on the line above it:\n"
                . implode("\n", $problems)
        );
    }

    public function test_no_translatable_string_in_src_is_built_by_concatenation(): void
    {
        $problems = $this->scan(
            static fn (I18n_Rule $rule, Source_File $file): array => $rule->non_literal_texts($file)
        );

        $this->assertSame(
            [],
            $problems,
            "make-pot cannot extract a concatenated or interpolated string; pass one literal:\n"
                . implode("\n", $problems)
        );
    }
}
