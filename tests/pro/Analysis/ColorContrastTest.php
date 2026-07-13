<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Analysis\Color_Contrast;

class ColorContrastTest extends \WP_UnitTestCase
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

    public function test_hex_to_rgb_six_digit(): void
    {
        $this->assertSame([255, 255, 255], Color_Contrast::hex_to_rgb('#FFFFFF'));
        $this->assertSame([0, 0, 0], Color_Contrast::hex_to_rgb('#000000'));
        $this->assertSame([99, 102, 241], Color_Contrast::hex_to_rgb('#6366F1'));
    }

    public function test_hex_to_rgb_three_digit_and_no_hash(): void
    {
        $this->assertSame([255, 255, 255], Color_Contrast::hex_to_rgb('fff'));
        $this->assertSame([17, 34, 51], Color_Contrast::hex_to_rgb('#123'));
    }

    public function test_hex_to_rgb_eight_digit_ignores_alpha(): void
    {
        $this->assertSame([255, 255, 255], Color_Contrast::hex_to_rgb('#FFFFFF1F'));
    }

    public function test_hex_to_rgb_invalid_returns_null(): void
    {
        $this->assertNull(Color_Contrast::hex_to_rgb('not-a-color'));
        $this->assertNull(Color_Contrast::hex_to_rgb('#GGG'));
        $this->assertNull(Color_Contrast::hex_to_rgb('#FFFF'));
    }

    public function test_relative_luminance_bounds(): void
    {
        $this->assertEqualsWithDelta(1.0, Color_Contrast::relative_luminance([255, 255, 255]), 0.0001);
        $this->assertEqualsWithDelta(0.0, Color_Contrast::relative_luminance([0, 0, 0]), 0.0001);
    }

    public function test_contrast_ratio_black_white_is_21(): void
    {
        $this->assertEqualsWithDelta(21.0, Color_Contrast::contrast_ratio('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(21.0, Color_Contrast::contrast_ratio('#FFFFFF', '#000000'), 0.01);
    }

    public function test_contrast_ratio_identical_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, Color_Contrast::contrast_ratio('#6366F1', '#6366F1'), 0.0001);
    }

    public function test_contrast_ratio_invalid_returns_null(): void
    {
        $this->assertNull(Color_Contrast::contrast_ratio('#FFFFFF', 'bogus'));
    }

    public function test_passes_thresholds(): void
    {
        $this->assertTrue(Color_Contrast::passes(4.5));
        $this->assertFalse(Color_Contrast::passes(4.49));
        $this->assertTrue(Color_Contrast::passes(3.0, true));
        $this->assertFalse(Color_Contrast::passes(2.99, true));
        $this->assertTrue(Color_Contrast::passes(7.0, false, 'AAA'));
        $this->assertFalse(Color_Contrast::passes(6.9, false, 'AAA'));
        $this->assertTrue(Color_Contrast::passes(4.5, true, 'AAA'));
        $this->assertFalse(Color_Contrast::passes(4.49, true, 'AAA'));
    }
}
