<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Settings\Update_Settings;

/**
 * Input-boundary tests for the Settings domain: missing/empty settings
 * payload, non-allowlisted keys, read-only keys, out-of-range int values,
 * invalid enum values, and unsafe permalink structures must all fail
 * cleanly by being skipped with a reason, never a fatal or a silent
 * unvalidated write.
 */
class SettingsInputTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_rejects_missing_settings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Settings())->handle([]);
    }

    public function test_rejects_non_array_settings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Settings())->handle(['settings' => 'not-an-array']);
    }

    public function test_rejects_empty_array_settings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Settings())->handle(['settings' => []]);
    }

    public function test_skips_non_allowlisted_key_without_fatal(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['not_a_real_option' => 'x']]);

        $this->assertSame([], $result['updated']);
        $this->assertSame('not_a_real_option', $result['skipped'][0]['key']);
        $this->assertSame('not allowlisted', $result['skipped'][0]['reason']);
    }

    public function test_skips_read_only_key_without_fatal(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['admin_email' => 'new@example.com']]);

        $this->assertSame([], $result['updated']);
        $this->assertSame('admin_email', $result['skipped'][0]['key']);
        $this->assertSame('read-only', $result['skipped'][0]['reason']);
    }

    public function test_clamps_out_of_range_int_value_to_the_registered_max(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['posts_per_page' => 99999]]);

        $this->assertSame(100, $result['updated']['posts_per_page']);
    }

    public function test_clamps_out_of_range_int_value_to_the_registered_min(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['posts_per_page' => -50]]);

        $this->assertSame(1, $result['updated']['posts_per_page']);
    }

    public function test_skips_invalid_enum_value_without_fatal(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['show_on_front' => 'not-a-valid-choice']]);

        $this->assertSame([], $result['updated']);
        $this->assertSame('show_on_front', $result['skipped'][0]['key']);
        $this->assertSame('invalid value', $result['skipped'][0]['reason']);
    }

    public function test_skips_unsafe_permalink_structure_without_fatal(): void
    {
        $result = (new Update_Settings())->handle(['settings' => ['permalink_structure' => '/%postname%; rm -rf /']]);

        $this->assertSame([], $result['updated']);
        $this->assertSame('permalink_structure', $result['skipped'][0]['key']);
        $this->assertSame('unsafe permalink structure', $result['skipped'][0]['reason']);
    }

    public function test_partial_batch_applies_valid_keys_and_reports_invalid_ones(): void
    {
        $result = (new Update_Settings())->handle([
            'settings' => [
                'blogname'          => 'A New Name',
                'not_a_real_option' => 'x',
            ],
        ]);

        $this->assertSame('A New Name', $result['updated']['blogname']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('not_a_real_option', $result['skipped'][0]['key']);
    }
}
