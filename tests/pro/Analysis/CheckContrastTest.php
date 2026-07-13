<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Analysis\Check_Contrast;

class CheckContrastTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_black_on_white_passes_aaa(): void
    {
        $out = (new Check_Contrast())->handle([
            'foreground' => '#000000',
            'background' => '#FFFFFF',
        ]);

        $this->assertEqualsWithDelta(21.0, $out['ratio'], 0.01);
        $this->assertTrue($out['normal_text']['aa']);
        $this->assertTrue($out['normal_text']['aaa']);
        $this->assertTrue($out['large_text']['aa']);
        $this->assertTrue($out['large_text']['aaa']);
    }

    public function test_mid_grey_on_white_fails_aa_normal_text(): void
    {
        $out = (new Check_Contrast())->handle([
            'foreground' => '#999999',
            'background' => '#FFFFFF',
        ]);

        $this->assertLessThan(4.5, $out['ratio']);
        $this->assertFalse($out['normal_text']['aa']);
        $this->assertFalse($out['normal_text']['aaa']);
        // ~2.85:1 also fails AA large text (needs 3:1).
        $this->assertFalse($out['large_text']['aa']);
    }

    public function test_invalid_color_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Check_Contrast())->handle([
            'foreground' => 'bogus',
            'background' => '#FFFFFF',
        ]);
    }

    public function test_missing_colors_throw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Check_Contrast())->handle([]);
    }

    private function make_ability(): Ability
    {
        return new Ability(
            'wpmcp/check-contrast',
            'pro',
            'Compute WCAG contrast ratio for two colors.',
            [
                'type'       => 'object',
                'properties' => [
                    'foreground' => ['type' => 'string'],
                    'background' => ['type' => 'string'],
                ],
                'required'   => ['foreground', 'background'],
            ],
            [new Check_Contrast(), 'handle'],
            'edit_posts',
            'analysis',
            'read'
        );
    }

    public function test_registrar_skips_check_contrast_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_check_contrast_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/check-contrast', $names);
    }
}
