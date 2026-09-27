<?php

namespace WPMCP\Tests\Free\Compliance;

require_once dirname(__DIR__, 3) . '/scripts/lib/class-ref-gate.php';

/**
 * The class-reference gate both release builds run over their staged tree
 * (scripts/lib/class-ref-gate.php). A reference it misses is a pruned class
 * that ships and fatals at runtime, so every import shape is pinned: plain,
 * aliased, comma-listed and grouped.
 */
class ClassRefGateTest extends \WP_UnitTestCase
{
    public function test_every_import_shape_is_resolved_to_the_imported_class(): void
    {
        $src = "<?php\n"
            . "namespace WPMCP\\Safety;\n"
            . "use WPMCP\\Tools\\Code\\Plain;\n"
            . "use WPMCP\\Tools\\Code\\Aliased as Store;\n"
            . "use WPMCP\\Tools\\A, WPMCP\\Tools\\B as Bee;\n"
            . "use WPMCP\\Tools\\Code\\{Grouped_One, Grouped_Two as Two};\n"
            . "use function WPMCP\\helper;\n"
            . "use const WPMCP\\SOME_CONST;\n"
            . "use Other\\Vendor\\Thing;\n";

        $this->assertEqualsCanonicalizing(
            [
                'WPMCP\\Tools\\Code\\Plain',
                'WPMCP\\Tools\\Code\\Aliased',
                'WPMCP\\Tools\\A',
                'WPMCP\\Tools\\B',
                'WPMCP\\Tools\\Code\\Grouped_One',
                'WPMCP\\Tools\\Code\\Grouped_Two',
            ],
            \Class_Ref_Gate::imported_classes($src)
        );
    }

    public function test_new_static_and_string_references_are_found(): void
    {
        $src = "<?php\n"
            . "\$a = new \\WPMCP\\X\\Made();\n"
            . "\\WPMCP\\X\\Called::go();\n"
            . "add_action('init', 'WPMCP\\\\X\\\\Hooked::run');\n";

        $this->assertEqualsCanonicalizing(
            ['WPMCP\\X\\Made', 'WPMCP\\X\\Called', 'WPMCP\\X\\Hooked'],
            \Class_Ref_Gate::named_classes($src, true)
        );
    }

    public function test_an_aliased_or_grouped_import_of_a_pruned_class_is_reported(): void
    {
        $stage = sys_get_temp_dir() . '/wpmcp-class-ref-gate-' . wp_generate_uuid4();
        mkdir($stage . '/vendor/composer', 0777, true);
        mkdir($stage . '/src/Safety', 0777, true);
        file_put_contents(
            $stage . '/vendor/composer/autoload_classmap.php',
            "<?php return ['WPMCP\\\\Safety\\\\Kept' => 'x'];"
        );
        file_put_contents(
            $stage . '/src/Safety/Uses.php',
            "<?php\nuse WPMCP\\Tools\\Code\\Pruned_Store as Store;\nuse WPMCP\\Tools\\Code\\{Pruned_Guard};\nuse WPMCP\\Safety\\Kept;\n"
        );

        try {
            $missing = implode("\n", \Class_Ref_Gate::missing($stage, 'src/Safety'));
        } finally {
            unlink($stage . '/src/Safety/Uses.php');
            unlink($stage . '/vendor/composer/autoload_classmap.php');
        }

        $this->assertStringContainsString('WPMCP\\Tools\\Code\\Pruned_Store', $missing);
        $this->assertStringContainsString('WPMCP\\Tools\\Code\\Pruned_Guard', $missing);
        $this->assertStringNotContainsString('WPMCP\\Safety\\Kept', $missing);
    }
}
