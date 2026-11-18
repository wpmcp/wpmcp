<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;

/**
 * WP Simple Pay (issue #666): read-only payment form ops on the plugin-data
 * dispatcher pair, free tier, so they add no top-level tools.
 *
 * WP Simple Pay is not loaded in the shared test core. Presence is driven
 * through wpmcp_simple_pay_active, the simple-pay post type is registered
 * here as includes/core/post-types/simple-pay/register.php does (private,
 * capability_type post), and each form is stored the way the plugin stores
 * it: post meta _form_display_type, _livemode, _company_name,
 * _item_description, _checkout_button_text, _success_redirect_type, and the
 * price options as _simpay_prices_live / _simpay_prices_test arrays (unit
 * amounts in cents), with the global test mode in the simpay_settings option.
 */
class SimplePayPackTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('simple-pay', [ 'public' => false, 'show_ui' => true, 'capability_type' => 'post', 'supports' => [ 'title' ] ]);
        add_filter('wpmcp_simple_pay_active', '__return_true');
        update_option('simpay_settings', [ 'test_mode' => 'enabled' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_simple_pay_active');
        delete_option('simpay_settings');
        unregister_post_type('simple-pay');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A payment form as WP Simple Pay stores it. */
    private function form(array $o = []): int
    {
        $o += [ 'title' => 'Donate', 'status' => 'publish', 'display' => 'stripe_checkout', 'livemode' => null, 'prices' => [], 'meta' => [] ];
        $id = self::factory()->post->create([ 'post_type' => 'simple-pay', 'post_title' => $o['title'], 'post_status' => $o['status'] ]);
        update_post_meta($id, '_form_display_type', $o['display']);
        if (null !== $o['livemode']) {
            update_post_meta($id, '_livemode', $o['livemode']);
        }
        update_post_meta($id, '_simpay_prices_test', $o['prices']);
        foreach ($o['meta'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private static function price(array $o = []): array
    {
        return $o + [ 'id' => 'price_123', 'default' => true, 'label' => 'One time', 'currency' => 'usd', 'unit_amount' => 1500, 'can_recur' => false ];
    }

    public function test_the_catalog_lists_both_read_ops(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'simplepay-list-forms', 'simplepay-get-form' ] as $name) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame('read', $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_simple_pay_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_simple_pay_active');
        add_filter('wpmcp_simple_pay_active', '__return_false');
        $out = $this->read('simplepay-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate WP Simple Pay', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_an_editor_may_not_read_payment_forms(): void
    {
        $id = $this->form();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('simplepay-list-forms')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('simplepay-get-form', [ 'id' => $id ])['error']['code'] ?? null);
    }

    public function test_list_forms_reports_status_mode_display_type_and_prices(): void
    {
        $a = $this->form([ 'title' => 'Donate', 'prices' => [ self::price(), self::price([ 'id' => 'price_456', 'default' => false, 'label' => 'Monthly', 'unit_amount' => 500, 'recurring' => [ 'interval' => 'month', 'interval_count' => 1 ] ]) ] ]);
        $b = $this->form([ 'title' => 'Draft form', 'status' => 'draft', 'display' => 'embedded', 'livemode' => '1' ]);
        update_post_meta($b, '_simpay_prices_live', [ self::price([ 'unit_amount' => 9900, 'currency' => 'eur' ]) ]);

        $out  = $this->ok($this->read('simplepay-list-forms'));
        $this->assertSame(2, $out['total']);
        $this->assertSame('test', $out['global_mode']);
        $rows = array_column($out['forms'], null, 'id');

        $this->assertSame('Donate', $rows[ $a ]['title']);
        $this->assertSame('publish', $rows[ $a ]['status']);
        $this->assertSame('stripe_checkout', $rows[ $a ]['display_type']);
        $this->assertSame('test', $rows[ $a ]['mode'], 'no per-form _livemode: the global test mode applies');
        $this->assertSame(2, $rows[ $a ]['price_count']);
        $this->assertSame([ 'label' => 'One time', 'amount' => 1500, 'currency' => 'usd', 'recurring' => null, 'default' => true ], $rows[ $a ]['prices'][0]);
        $this->assertSame([ 'interval' => 'month', 'interval_count' => 1 ], $rows[ $a ]['prices'][1]['recurring']);

        $this->assertSame('draft', $rows[ $b ]['status']);
        $this->assertSame('embedded', $rows[ $b ]['display_type']);
        $this->assertSame('live', $rows[ $b ]['mode'], 'the per-form _livemode overrides the global mode');
        $this->assertSame(9900, $rows[ $b ]['prices'][0]['amount'], 'live forms read the live prices');
        $this->assertSame('eur', $rows[ $b ]['prices'][0]['currency']);
    }

    public function test_list_forms_filters_by_status_and_pages(): void
    {
        $this->form([ 'title' => 'One' ]);
        $this->form([ 'title' => 'Two' ]);
        $this->form([ 'title' => 'Three', 'status' => 'draft' ]);

        $this->assertSame(2, $this->ok($this->read('simplepay-list-forms', [ 'status' => 'publish' ]))['total']);
        $page = $this->ok($this->read('simplepay-list-forms', [ 'per_page' => 1, 'page' => 2 ]));
        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['forms']);
    }

    public function test_get_form_returns_the_full_configuration(): void
    {
        update_option('simpay_settings', [ 'test_mode' => 'disabled' ]);
        $id = $this->form([
            'title'  => 'Tickets',
            'prices' => [],
            'meta'   => [
                '_company_name'          => 'Acme Events',
                '_item_description'      => 'Annual meetup ticket',
                '_checkout_button_text'  => 'Buy now',
                '_success_redirect_type' => 'page',
                '_success_redirect_page' => '42',
                '_simpay_prices_live'    => [ self::price([ 'unit_amount' => 2500, 'unit_amount_min' => 1000, 'custom' => true ]) ],
            ],
        ]);

        $form = $this->ok($this->read('simplepay-get-form', [ 'id' => $id ]))['form'];
        $this->assertSame($id, $form['id']);
        $this->assertSame('Tickets', $form['title']);
        $this->assertSame('live', $form['mode'], 'global test mode disabled means live');
        $this->assertSame('Acme Events', $form['company_name']);
        $this->assertSame('Annual meetup ticket', $form['item_description']);
        $this->assertSame('Buy now', $form['checkout_button_text']);
        $this->assertSame([ 'type' => 'page', 'page_id' => 42, 'url' => '' ], $form['success_redirect']);
        $this->assertSame(2500, $form['prices'][0]['amount']);
        $this->assertSame(1000, $form['prices'][0]['amount_min']);
        $this->assertSame('price_123', $form['prices'][0]['id']);
    }

    public function test_get_form_refuses_a_post_that_is_not_a_payment_form(): void
    {
        $post = self::factory()->post->create();
        $out  = $this->read('simplepay-get-form', [ 'id' => $post ]);
        $this->assertSame('form_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('form_not_found', $this->read('simplepay-get-form', [ 'id' => 999999 ])['error']['code'] ?? null);
    }
}
