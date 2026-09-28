<?php

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateOptionTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        delete_option('wpmcp_test_option');
        remove_all_filters('wpmcp_enable_option_write');
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Update_Option())->handle(['name' => 'wpmcp_test_option', 'value' => 'x']);
    }

    public function test_enabled_via_filter(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');

        $out = (new Update_Option())->handle(['name' => 'wpmcp_test_option', 'value' => 'x']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('x', get_option('wpmcp_test_option'));
    }

    public function test_refuses_a_denylisted_option_even_when_enabled(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Option())->handle(['name' => 'siteurl', 'value' => 'https://evil.example']);
    }

    /**
     * The custom code store (issue #63) holds page CSS that is printed raw
     * into <style> and the site JS snippet. Its own tools demand edit_css /
     * unfiltered_html plus a default-off gate; the generic option tool must
     * not be a manage_options-only side door around them, and a site filter
     * trimming the pattern list must not reopen it.
     *
     * @dataProvider custom_code_option_names
     */
    public function test_refuses_the_custom_code_store_even_when_enabled(string $name): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        add_filter('wpmcp_option_denylist', '__return_empty_array');
        add_filter('wpmcp_option_denylist_patterns', '__return_empty_array');

        try {
            (new Update_Option())->handle(['name' => $name, 'value' => '</style><script>alert(1)</script>']);
            $this->fail("update-option should refuse {$name}.");
        } catch (\RuntimeException $e) {
            $this->assertFalse(get_option($name));
        } finally {
            remove_all_filters('wpmcp_option_denylist');
            remove_all_filters('wpmcp_option_denylist_patterns');
        }
    }

    /** @return array<string, array{0:string}> */
    public static function custom_code_option_names(): array
    {
        return [
            'js store'       => ['wpmcp_custom_code'],
            'page css block' => ['wpmcp_custom_code_post_12'],
            'mixed case'     => ['WPMCP_Custom_Code_post_12'],
        ];
    }

    public function test_write_is_snapshotted_and_rollback_restores_prior_value(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option('wpmcp_test_option', 'original');

        $out = (new Update_Option())->handle(['name' => 'wpmcp_test_option', 'value' => 'mutated']);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('mutated', get_option('wpmcp_test_option'));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $this->assertSame('original', get_option('wpmcp_test_option'));
    }
}
