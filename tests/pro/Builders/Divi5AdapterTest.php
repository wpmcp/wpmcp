<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Divi_Content, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #636: Divi 5 element-level editing end to end through the builder
 * pair: detection, the tree read with its hash, each element operation, the
 * expected_hash lock, the Divi 5 availability refusals and exact rollback of
 * post_content and both builder flags. Divi is a commercial theme that is
 * not installed here, so the layout is the one Divi5BlocksTest records from
 * Divi 5.18.0 and the theme's presence is stood in for through the
 * wpmcp_divi_version filter; the live Divi leg runs the same edits against
 * the real theme (Divi5LiveTest).
 */
class Divi5AdapterTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wpmcp_divi_version', static fn () => '5.18.0');
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_divi_version');
        parent::tearDown();
    }

    private function page(string $content, array $meta = ['_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on']): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash($content)]);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    private function read(int $post_id): array
    {
        return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'divi'] + $args);
    }

    private function state(int $post_id): array
    {
        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    // ------------------------------------------------------------ detection

    public function test_divi5_pages_are_detected_by_flag_or_by_markup_alone(): void
    {
        $flagged  = $this->page('', ['_et_pb_use_divi_5' => 'on']);
        $imported = $this->page(Divi5BlocksTest::FIXTURE, []);
        $blocks   = $this->page('<!-- wp:paragraph --><p>divi/x</p><!-- /wp:paragraph -->', []);

        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $flagged])['builder']);
        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $imported])['builder']);
        $this->assertSame('gutenberg', (new Detect_Builder())->handle(['post_id' => $blocks])['builder']);
    }

    // ----------------------------------------------------------------- read

    public function test_read_returns_tree_hash_version_and_content(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);

        $out = $this->read($post_id);

        $this->assertSame('divi', $out['builder']);
        $this->assertSame('divi5', $out['format']);
        $this->assertTrue($out['plugin_active']);
        $this->assertSame('5.18.0', $out['plugin_version']);
        $this->assertSame(hash('sha256', Divi5BlocksTest::FIXTURE), $out['hash']);
        $this->assertSame(Divi5BlocksTest::FIXTURE, $out['content']);
        $this->assertSame('divi/heading', $out['tree'][0]['children'][0]['children'][0]['children'][0]['name']);
        $this->assertSame('0.0.0.0', $out['tree'][0]['children'][0]['children'][0]['children'][0]['path']);
    }

    public function test_read_reports_an_inactive_or_old_divi_and_a_broken_layout(): void
    {
        $post_id = $this->page('<!-- wp:divi/placeholder --><!-- wp:divi/section -->');

        remove_all_filters('wpmcp_divi_version');
        $out = $this->read($post_id);
        $this->assertFalse($out['plugin_active']);
        $this->assertNull($out['plugin_version']);
        $this->assertSame([], $out['tree']);
        $this->assertStringContainsString('not balanced', $out['tree_error']);

        add_filter('wpmcp_divi_version', static fn () => '4.27.4');
        $this->assertFalse($this->read($post_id)['plugin_active']);
    }

    public function test_a_divi4_page_reads_as_before(): void
    {
        $post_id = $this->page('[et_pb_section][/et_pb_section]', ['_et_pb_use_builder' => 'on']);

        $this->assertSame(
            ['post_id' => $post_id, 'builder' => 'divi', 'content' => '[et_pb_section][/et_pb_section]', 'use_builder' => true],
            $this->read($post_id)
        );
    }

    // ----------------------------------------------------- operations, undo

    public function test_each_operation_is_written_and_rolled_back_exactly(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        $before  = $this->state($post_id);
        $session = wp_generate_uuid4();

        $hash = $this->read($post_id)['hash'];
        $out  = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'text' => 'Hello', 'expected_hash' => $hash, 'session_id' => $session]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('0.0.0.0', $out['path']);
        $this->assertSame($this->read($post_id)['hash'], $out['hash']);
        $this->assertSame('Hello', $this->read($post_id)['tree'][0]['children'][0]['children'][0]['children'][0]['attrs']->title->innerContent->desktop->value);

        $out = $this->update($post_id, [
            'operation'     => 'add',
            'to'            => '0.0.1',
            'index'         => 0,
            'element'       => ['name' => 'text', 'text' => '<p>Added</p>'],
            'expected_hash' => $out['hash'],
            'session_id'    => $session,
        ]);
        $this->assertSame('0.0.1.0', $out['path']);
        $added = $this->read($post_id)['tree'][0]['children'][0]['children'][1]['children'][0];
        $this->assertSame('divi/text', $added['name']);
        $this->assertSame('5.18.0', $added['attrs']->builderVersion);

        $out = $this->update($post_id, ['operation' => 'move', 'path' => '0.0.1.0', 'to' => '1', 'expected_hash' => $out['hash'], 'session_id' => $session]);
        $this->assertSame('divi/text', $this->read($post_id)['tree'][1]['children'][0]['name']);

        $out = $this->update($post_id, ['operation' => 'remove', 'path' => '0.0.0', 'expected_hash' => $out['hash'], 'session_id' => $session]);
        $this->assertArrayNotHasKey('path', $out);
        $this->assertStringNotContainsString('wp:divi/heading', get_post($post_id)->post_content);

        Rollback_Service::restore_session($session);

        $this->assertSame($before, $this->state($post_id));
    }

    public function test_one_operation_rolls_back_alone(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'remove', 'path' => '1', 'expected_hash' => $this->read($post_id)['hash']]);
        $this->assertCount(1, $this->read($post_id)['tree']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_whole_layout_write_on_a_blank_page_sets_both_flags_and_rolls_back(): void
    {
        $post_id = $this->page('', []);
        $layout  = '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $session = wp_generate_uuid4();

        $out = $this->update($post_id, ['content' => $layout, 'session_id' => $session]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($layout, get_post($post_id)->post_content);
        $this->assertSame('on', get_post_meta($post_id, '_et_pb_use_builder', true));
        $this->assertSame('on', get_post_meta($post_id, '_et_pb_use_divi_5', true));

        Rollback_Service::restore_session($session);
        $this->assertSame('', get_post($post_id)->post_content);
        $this->assertFalse(metadata_exists('post', $post_id, '_et_pb_use_divi_5'));
        $this->assertFalse(metadata_exists('post', $post_id, '_et_pb_use_builder'));
    }

    public function test_first_element_on_a_blank_page_needs_no_hash(): void
    {
        $post_id = $this->page('', []);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['name' => 'section']]);

        $this->assertSame('0', $out['path']);
        $this->assertSame(
            '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.18.0"} --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
            get_post($post_id)->post_content
        );
        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
    }

    // -------------------------------------------------------------- refusals

    public function test_writes_to_a_divi5_page_need_the_current_hash(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        $before  = $this->state($post_id);

        $missing = $this->update($post_id, ['operation' => 'remove', 'path' => '1']);
        $stale   = $this->update($post_id, ['operation' => 'remove', 'path' => '1', 'expected_hash' => hash('sha256', 'old')]);
        $whole   = $this->update($post_id, ['content' => '<!-- wp:divi/placeholder /-->']);

        $this->assertSame('missing_expected_hash', $missing->get_error_code());
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertSame('missing_expected_hash', $whole->get_error_code());
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_writes_need_divi5_loaded(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        $hash    = hash('sha256', Divi5BlocksTest::FIXTURE);

        remove_all_filters('wpmcp_divi_version');
        $missing = $this->update($post_id, ['operation' => 'remove', 'path' => '1', 'expected_hash' => $hash]);
        $this->assertSame('plugin_missing', $missing->get_error_code());
        $this->assertStringContainsString('Install and activate Divi 5', $missing->get_error_message());
        $this->assertSame(admin_url('themes.php'), $missing->get_error_data()['url']);

        add_filter('wpmcp_divi_version', static fn () => '4.27.4');
        $old = $this->update($post_id, ['operation' => 'remove', 'path' => '1', 'expected_hash' => $hash]);
        $this->assertSame('plugin_version_unsupported', $old->get_error_code());
        $this->assertSame('4.27.4', $old->get_error_data()['plugin_version']);

        $this->assertSame(Divi5BlocksTest::FIXTURE, get_post($post_id)->post_content);
    }

    public function test_operations_on_divi4_and_other_pages_are_refused(): void
    {
        $divi4  = $this->page('[et_pb_section][/et_pb_section]', ['_et_pb_use_builder' => 'on']);
        $blocks = $this->page('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', []);

        $this->assertSame('divi4_operations_unsupported', $this->update($divi4, ['operation' => 'remove', 'path' => '0'])->get_error_code());
        $this->assertSame('unsupported_builder', $this->update($blocks, ['operation' => 'remove', 'path' => '0'])->get_error_code());
    }

    public function test_bad_operations_and_layouts_are_refused_without_a_write(): void
    {
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        $hash    = hash('sha256', Divi5BlocksTest::FIXTURE);

        foreach ([
            ['operation' => 'update', 'path' => '9'],
            ['operation' => 'update', 'path' => '0', 'attrs' => 'x'],
            ['operation' => 'update', 'path' => '0', 'text' => 5],
            ['operation' => 'add', 'to' => '0'],
            ['operation' => 'explode', 'path' => '0'],
            ['content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section -->'],
        ] as $args) {
            $out = $this->update($post_id, $args + ['expected_hash' => $hash]);
            $this->assertSame('invalid_divi_request', $out->get_error_code(), wp_json_encode($args));
        }
        $this->assertSame(Divi5BlocksTest::FIXTURE, get_post($post_id)->post_content);
    }

    public function test_a_user_without_unfiltered_html_gets_kses_on_what_the_call_writes(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $post_id = $this->page(Divi5BlocksTest::FIXTURE);
        wp_set_current_user($author);
        $this->assertFalse(current_user_can('unfiltered_html'));

        $out = $this->update($post_id, [
            'operation'     => 'update',
            'path'          => '0.0.0.1',
            'text'          => '<p>Safe</p><script>alert(1)</script>',
            'expected_hash' => hash('sha256', Divi5BlocksTest::FIXTURE),
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $value = $this->read($post_id)['tree'][0]['children'][0]['children'][0]['children'][1]['attrs']->content->innerContent->desktop->value;
        $this->assertSame('<p>Safe</p>alert(1)', $value);

        $out = $this->update($post_id, [
            'content'       => '<!-- wp:divi/placeholder --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<script>x</script>"}}}} /--><!-- /wp:divi/placeholder -->',
            'expected_hash' => $out['hash'],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('x', $this->read($post_id)['tree'][0]['attrs']->content->innerContent->desktop->value);
    }
}
