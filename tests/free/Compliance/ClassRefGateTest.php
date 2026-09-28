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

    /**
     * A hook callback is where a pruned class hides best: it resolves only
     * when the hook fires, so the build loads fine and the first request that
     * fires the hook fatals. The WooCommerce build shipped exactly that
     * (a rollback listener naming a class under the pruned src/Integrations),
     * so the hook scan covers the whole tree, and only a branch that cannot
     * run in this build (a class_exists() guard on the same class, or an
     * ability group the flavor never enables) excuses a reference.
     */
    public function test_a_hook_callback_naming_a_pruned_class_is_reported(): void
    {
        $missing = $this->dangling_hooks(
            "<?php\nnamespace WPMCP;\nuse WPMCP\\Kept\\Short_Import;\nclass Boot {\n    public function boot(): void {\n"
            . "        add_action('wpmcp_rollback_options_restored', [\\WPMCP\\Integrations\\Pruned_Pack::class, 'refresh']);\n"
            . "        add_filter('the_title', ['\\\\WPMCP\\\\Integrations\\\\Pruned_String', 'filter'], 10, 2);\n"
            . "        add_action('init', [new \\WPMCP\\Integrations\\Pruned_New(), 'run']);\n"
            . "        add_action('init', [Short_Import::class, 'run']);\n"
            . "        add_action('init', [Pruned_Relative::class, 'run']);\n"
            . "        add_action('init', [\\WPMCP\\Kept\\Present::class, 'run']);\n"
            . "    }\n}\n",
            []
        );

        $this->assertStringContainsString('WPMCP\\Integrations\\Pruned_Pack', $missing);
        $this->assertStringContainsString('WPMCP\\Integrations\\Pruned_String', $missing);
        $this->assertStringContainsString('WPMCP\\Integrations\\Pruned_New', $missing);
        $this->assertStringContainsString('WPMCP\\Kept\\Short_Import', $missing);
        $this->assertStringContainsString('WPMCP\\Pruned_Relative', $missing);
        $this->assertStringNotContainsString('WPMCP\\Kept\\Present', $missing);
    }

    public function test_a_hook_in_a_branch_the_build_cannot_reach_is_not_reported(): void
    {
        $missing = $this->dangling_hooks(
            "<?php\nnamespace WPMCP;\nclass Boot {\n    public function boot(): void {\n"
            . "        if (class_exists(\\WPMCP\\Integrations\\Guarded::class)) {\n"
            . "            add_action('init', [\\WPMCP\\Integrations\\Guarded::class, 'run']);\n"
            . "        }\n"
            . "        if (class_exists(\\WPMCP\\Integrations\\Other::class)) {\n"
            . "            add_action('init', [\\WPMCP\\Integrations\\Wrong_Guard::class, 'run']);\n"
            . "        }\n"
            . "        if (\$this->group_enabled('theme_builder')) {\n"
            . "            add_action('init', ['\\\\WPMCP\\\\Tools\\\\ThemeBuilder\\\\Store', 'run']);\n"
            . "        }\n"
            . "        if (\$this->group_enabled('woocommerce')) {\n"
            . "            add_action('init', [\\WPMCP\\Tools\\Woo\\Pruned_But_Live::class, 'run']);\n"
            . "        }\n"
            . "    }\n}\n",
            [ 'woocommerce' ]
        );

        $this->assertStringNotContainsString('WPMCP\\Integrations\\Guarded', $missing);
        $this->assertStringNotContainsString('ThemeBuilder', $missing);
        $this->assertStringContainsString('WPMCP\\Integrations\\Wrong_Guard', $missing);
        $this->assertStringContainsString('WPMCP\\Tools\\Woo\\Pruned_But_Live', $missing);
    }

    /**
     * Runs the hook scan over a one-file stage whose classmap holds only
     * WPMCP\Boot and WPMCP\Kept\Present.
     *
     * @param string[] $live_groups
     */
    private function dangling_hooks(string $src, array $live_groups): string
    {
        $stage = sys_get_temp_dir() . '/wpmcp-class-ref-gate-' . wp_generate_uuid4();
        mkdir($stage . '/vendor/composer', 0777, true);
        mkdir($stage . '/src', 0777, true);
        file_put_contents(
            $stage . '/vendor/composer/autoload_classmap.php',
            "<?php return ['WPMCP\\\\Boot' => 'x', 'WPMCP\\\\Kept\\\\Present' => 'x'];"
        );
        file_put_contents($stage . '/src/Boot.php', $src);

        try {
            return implode("\n", \Class_Ref_Gate::dangling_hooks($stage, 'src', $live_groups));
        } finally {
            unlink($stage . '/src/Boot.php');
            unlink($stage . '/vendor/composer/autoload_classmap.php');
        }
    }
}
