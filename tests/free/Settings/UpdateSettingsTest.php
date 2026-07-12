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
}
