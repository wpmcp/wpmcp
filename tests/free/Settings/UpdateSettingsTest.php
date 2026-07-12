<?php

namespace WPMCP\Tests\Free\Settings;

use WPMCP\Tools\Settings\Update_Settings;
use WPMCP\Safety\Snapshot_Store;

class UpdateSettingsTest extends \WP_UnitTestCase
{
    private array $original_options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach ([
            'blogname', 'blogdescription', 'admin_email', 'posts_per_page',
            'blog_public', 'show_on_front', 'permalink_structure', 'siteurl',
        ] as $key) {
            $this->original_options[ $key ] = get_option($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original_options as $key => $value) {
            update_option($key, $value);
        }
        parent::tearDown();
    }

    public function test_update_writes_allowlisted_key(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['blogname' => 'Renamed']]);

        $this->assertSame('Renamed', $out['updated']['blogname']);
        $this->assertFalse($out['rewrite_flushed']);
        $this->assertSame('Renamed', get_option('blogname'));
    }

    public function test_update_rejects_permalink_with_unsafe_chars(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['permalink_structure' => '/%postname%/?q=1']]);

        $reasons = array_column($out['skipped'], 'key');
        $this->assertContains('permalink_structure', $reasons);
        $this->assertArrayNotHasKey('permalink_structure', $out['updated']);
    }

    public function test_update_allows_valid_permalink_structure(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['permalink_structure' => '/%category%/%postname%/']]);

        $this->assertArrayHasKey('permalink_structure', $out['updated']);
        $this->assertTrue($out['rewrite_flushed']);
    }

    public function test_update_skips_non_allowlisted_key(): void
    {
        update_option('an_unlisted_option', 'original');

        $out     = (new Update_Settings())->handle(['settings' => ['an_unlisted_option' => 'hacked']]);
        $reasons = array_column($out['skipped'], 'key');

        $this->assertContains('an_unlisted_option', $reasons);
        $this->assertArrayNotHasKey('an_unlisted_option', $out['updated']);
        $this->assertSame('original', get_option('an_unlisted_option'));

        delete_option('an_unlisted_option');
    }

    public function test_update_skips_read_only_admin_email(): void
    {
        update_option('admin_email', 'admin@example.com');

        $out     = (new Update_Settings())->handle(['settings' => ['admin_email' => 'new@example.com']]);
        $skipped = [];
        foreach ($out['skipped'] as $s) {
            $skipped[ $s['key'] ] = $s['reason'];
        }

        $this->assertArrayHasKey('admin_email', $skipped);
        $this->assertSame('read-only', $skipped['admin_email']);
        $this->assertSame('admin@example.com', get_option('admin_email'));
    }

    public function test_update_skips_invalid_enum(): void
    {
        update_option('show_on_front', 'posts');

        $out     = (new Update_Settings())->handle(['settings' => ['show_on_front' => 'banana']]);
        $skipped = array_column($out['skipped'], 'key');

        $this->assertContains('show_on_front', $skipped);
        $this->assertArrayNotHasKey('show_on_front', $out['updated']);
        $this->assertSame('posts', get_option('show_on_front'));
    }

    public function test_update_clamps_int_to_range(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['posts_per_page' => 5000]]);
        $this->assertSame(100, $out['updated']['posts_per_page']);
        $this->assertSame(100, (int) get_option('posts_per_page'));
    }

    public function test_update_coerces_bool(): void
    {
        update_option('blog_public', '1');

        $out = (new Update_Settings())->handle(['settings' => ['blog_public' => false]]);

        $this->assertFalse((bool) get_option('blog_public'));
        $this->assertFalse($out['updated']['blog_public']);
    }

    public function test_permalink_change_sets_flush_flag(): void
    {
        $out = (new Update_Settings())->handle(['settings' => ['permalink_structure' => '/blog/%postname%/']]);
        $this->assertTrue($out['rewrite_flushed']);
    }

    public function test_partial_failure_applies_valid_subset(): void
    {
        update_option('show_on_front', 'posts');

        $out = (new Update_Settings())->handle([
            'settings' => [
                'blogname'           => 'Good',
                'an_unlisted_option' => 'hacked',
                'show_on_front'      => 'banana',
            ],
        ]);

        $this->assertSame('Good', $out['updated']['blogname']);
        $this->assertCount(2, $out['skipped']);
    }

    public function test_empty_map_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Settings())->handle(['settings' => []]);
    }

    public function test_missing_settings_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Settings())->handle([]);
    }

    public function test_write_is_snapshotted_and_rollback_restores_prior_value(): void
    {
        update_option('blogname', 'Original Name');

        $out = (new Update_Settings())->handle(['settings' => ['blogname' => 'Changed Name']]);
        $this->assertSame('Changed Name', get_option('blogname'));
        $this->assertNotEmpty($out['operation_ids']);

        $operation_id = $out['operation_ids'][0];
        $this->assertNotNull(Snapshot_Store::get_by_operation($operation_id));

        $rolled_back = (new \WPMCP\Tools\Rollback_Operation())->handle(['operation_id' => $operation_id]);
        $this->assertTrue($rolled_back['restored']);
        $this->assertSame('Original Name', get_option('blogname'));
    }
}
