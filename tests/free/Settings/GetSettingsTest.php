<?php

namespace WPMCP\Tests\Free\Settings;

use WPMCP\Tools\Settings\Get_Settings;

class GetSettingsTest extends \WP_UnitTestCase
{
    private array $original_options = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['blogname', 'blogdescription', 'admin_email', 'posts_per_page', 'blog_public', 'show_on_front', 'permalink_structure'] as $key) {
            $this->original_options[ $key ] = get_option($key);
        }
        update_option('blogname', 'My Site');
    }

    protected function tearDown(): void
    {
        foreach ($this->original_options as $key => $value) {
            update_option($key, $value);
        }
        parent::tearDown();
    }

    private function find(array $out, string $key): ?array
    {
        foreach ($out['settings'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    public function test_get_all_returns_rows_with_metadata(): void
    {
        $out = (new Get_Settings())->handle([]);
        $this->assertArrayHasKey('settings', $out);

        $row = $this->find($out, 'blogname');
        $this->assertNotNull($row);
        $this->assertSame('general', $row['group']);
        $this->assertSame('string', $row['type']);
        $this->assertSame('My Site', $row['value']);
        $this->assertTrue($row['writable']);
    }

    public function test_get_coerces_int_and_bool(): void
    {
        update_option('posts_per_page', '12');
        update_option('blog_public', '1');

        $out = (new Get_Settings())->handle([]);
        $ppp = $this->find($out, 'posts_per_page');
        $pub = $this->find($out, 'blog_public');

        $this->assertSame(12, $ppp['value']);
        $this->assertTrue($pub['value']);
    }

    public function test_enum_row_carries_options(): void
    {
        $out = (new Get_Settings())->handle(['keys' => ['show_on_front']]);
        $this->assertCount(1, $out['settings']);
        $this->assertSame(['posts', 'page'], $out['settings'][0]['options']);
    }

    public function test_admin_email_is_read_only(): void
    {
        update_option('admin_email', 'admin@example.com');
        $out = (new Get_Settings())->handle(['keys' => ['admin_email']]);
        $this->assertFalse($out['settings'][0]['writable']);
        $this->assertSame('admin@example.com', $out['settings'][0]['value']);
    }

    public function test_group_filter_only_returns_that_screen(): void
    {
        $out    = (new Get_Settings())->handle(['group' => 'permalinks']);
        $groups = array_unique(array_column($out['settings'], 'group'));
        $this->assertSame(['permalinks'], $groups);
    }

    public function test_keys_filter_ignores_non_allowlisted(): void
    {
        $out  = (new Get_Settings())->handle(['keys' => ['blogname', 'unknown_option', 'not_a_setting']]);
        $keys = array_column($out['settings'], 'key');
        $this->assertSame(['blogname'], $keys);
    }
}
