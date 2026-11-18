<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * Issue #1029: a message must not put a fixed "a" in front of a value that is
 * only known at run time.
 *
 * The read-only refusal read 'is a %2$s ability' and so said "is a update
 * ability"; the same pattern said "is a attachment", "a external product",
 * "a html snippet", "a Astra Pro layout" and "a Awesome Support ticket"
 * elsewhere. New messages name the value in quotes ('has type "%s"'), use
 * "the" or "one", or otherwise read correctly whatever the value is.
 *
 * This scans src/ for "a"/"an" directly before a sprintf placeholder, an
 * interpolated variable or a concatenation. A site is allowed only when every
 * value it can take is fixed in code and reads correctly after "a"; each one
 * is listed below with the reason.
 */
class ArticleBeforePlaceholderGuardTest extends \WP_UnitTestCase
{
    /**
     * Relative path => substrings of the allowed lines in that file.
     *
     * @var array<string, string[]>
     */
    private const ALLOWED = [
        // shape is month, week or day.
        'src/Tools/WooCommerce/Catalog/Booking_Ops.php'         => ['must be a {$shape} number'],
        // group is line_items, shipping_lines or fee_lines.
        'src/Tools/WooCommerce/Catalog/Order_Ops.php'           => ['"A {$group} removal', '"a {$group} change"'],
        // node and parent types are row, column-group, column or module.
        'src/Tools/Builders/Beaver_Builder_Nodes.php'           => ["'A %s cannot go %s.'", '"inside a {$parent_type}"'],
        // type() is widget, block or snippet.
        'src/Tools/Portable/Spec_Bundle_Kind.php'               => ["'A %s item needs", "'A %s named"],
        // FORMAT is wpmcp-bundle.
        'src/Tools/Portable/Bundle.php'                         => ["'Not a %s document"],
        // Elementor variable types are color, font, size or custom-size.
        'src/Tools/Elementor/Update_Global_Variable.php'        => ["'A %s variable cannot become"],
        // prefix is padding or margin.
        'src/Tools/Elementor/Atomic_Styles.php'                 => ['is not a %s side'],
        // subject is "global class" or "style".
        'src/Tools/Elementor/Global_Class_Schema.php'           => ['so a %s cannot be validated'],
        // Only reached for the single context: archive and search are the loop contexts.
        'src/Tools/ThemeBuilder/Dynamic/Binding_Resolver.php'   => ["'A %s template has no loop"],
        // ARTIFACT_PREFIX is wpmcp-changeset-.
        'src/Tools/Sync/Change_Set_Format.php'                  => ['expected a %s*.json'],
        // multisite or single-site.
        'src/Tools/Backup/Restore_Site_Backup.php'              => ['from a %s install'],
        // plugin is JetEngine or Pods.
        'src/Integrations/Plugin_Data_Integration.php'          => ["'Not a %s field of post type"],
        // Suite labels are Kadence Blocks, GenerateBlocks, Spectra, Otter Blocks, Blocksy Companion.
        'src/Integrations/Block_Suites_Integration.php'         => ['not a %s block'],
        // label() is FluentCRM or MailPoet; kind is list or tag.
        'src/Integrations/CRM_Adapter.php'                      => ['a {$label}', 'Not a %s', 'already has a %s'],
        // label() is LearnDash, LifterLMS or Tutor LMS; section is lesson, section or topic.
        'src/Integrations/LMS_Adapter.php'                      => ["'a ' . \$section", 'a {$label}', 'A {$label}', 'a {$section}', 'a %s', 'A %s'],
    ];

    public function test_no_message_puts_a_fixed_article_before_a_runtime_value(): void
    {
        $root  = dirname(__DIR__, 3);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('#^\s*(\*|//|/\*)#', $line)) {
                    continue;
                }
                if (! preg_match('/\b[aA]n? (%(\d+\$)?s\b|\{\$|\' \. \$)/', $line)) {
                    continue;
                }
                if ($this->allowed($relative, $line)) {
                    continue;
                }
                $found[] = $relative . ':' . ($number + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame([], $found, 'A fixed "a"/"an" sits before a runtime value. Quote the value (has type "%s"), use "the" or "one", or, if every value is fixed in code and reads correctly, list the site in ALLOWED with the reason.');
    }

    private function allowed(string $relative, string $line): bool
    {
        foreach (self::ALLOWED[ $relative ] ?? [] as $needle) {
            if (false !== strpos($line, $needle)) {
                return true;
            }
        }
        return false;
    }
}
