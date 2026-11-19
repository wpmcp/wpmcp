<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Checkout_Field_Editor_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #660 (first slice): Checkout Field Editor for WooCommerce
 * (ThemeHigh, wordpress.org slug woo-checkout-field-editor-pro) checkout
 * fields as paid-tier ops on the plugin-data pair.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_checkout_field_editor_active, and the section options are
 * seeded in the shape version 2.2.1 stores them (THWCFD_Utils::update_fields
 * writes wc_fields_billing, wc_fields_shipping and wc_fields_additional,
 * each a name => field map).
 */
class CheckoutFieldEditorPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-checkout-fields'   => 'read',
        'update-checkout-fields' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($admin)->add_cap('manage_woocommerce');
        wp_set_current_user($admin);
        add_filter('wpmcp_checkout_field_editor_active', '__return_true');
        add_filter(Checkout_Field_Editor_Pack::ENABLE_FILTER, '__return_true');
        update_option('wc_fields_billing', self::billing(), 'no');
        update_option('wc_fields_additional', [
            'order_comments' => [ 'type' => 'textarea', 'name' => 'order_comments', 'label' => 'Order notes', 'placeholder' => 'Notes about your order', 'class' => [ 'notes' ], 'custom' => 0, 'enabled' => 1, 'show_in_email' => 1, 'show_in_order' => 1 ],
        ], 'no');
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_checkout_field_editor_active', Checkout_Field_Editor_Pack::ENABLE_FILTER ] as $hook) {
            remove_all_filters($hook);
        }
        foreach ([ 'billing', 'shipping', 'additional' ] as $section) {
            delete_option('wc_fields_' . $section);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** The billing section as Checkout Field Editor 2.2.1 saves it. */
    private static function billing(): array
    {
        return [
            'billing_first_name' => [ 'label' => 'First name', 'required' => true, 'class' => [ 'form-row-first' ], 'autocomplete' => 'given-name', 'priority' => 10, 'custom' => 0, 'enabled' => 1, 'show_in_email' => 1, 'show_in_order' => 1 ],
            'billing_company'    => [ 'label' => 'Company name', 'class' => [ 'form-row-wide' ], 'autocomplete' => 'organization', 'priority' => 30, 'required' => false, 'custom' => 0, 'enabled' => 1, 'show_in_email' => 1, 'show_in_order' => 1 ],
            'billing_vat'        => [ 'type' => 'text', 'name' => 'billing_vat', 'label' => 'VAT number', 'default' => '', 'placeholder' => '', 'class' => [ 'form-row-wide' ], 'validate' => '', 'required' => 0, 'enabled' => 1, 'show_in_email' => 1, 'show_in_order' => 1, 'options' => '', 'autocomplete' => '', 'priority' => 120, 'custom' => 1 ],
        ];
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_woocommerce(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_woocommerce', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_checkout_field_editor_active');
        add_filter('wpmcp_checkout_field_editor_active', '__return_false');

        $out = $this->read('list-checkout-fields');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Checkout Field Editor for WooCommerce', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);

        $out = $this->write('update-checkout-fields', [ 'section' => 'billing', 'fields' => [ 'billing_company' => [ 'enabled' => false ] ] ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertSame(1, get_option('wc_fields_billing')['billing_company']['enabled']);
    }

    public function test_a_plugin_installed_but_off_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_checkout_field_editor_active');
        add_filter('wpmcp_checkout_field_editor_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/woo-checkout-field-editor-pro';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/checkout-form-designer.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Checkout Field Editor for WooCommerce\n");
        }
        try {
            $out = $this->read('list-checkout-fields');
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_list_reports_each_section_in_priority_order(): void
    {
        $out = $this->ok($this->read('list-checkout-fields'))['result'];

        $this->assertSame([ 'billing', 'shipping', 'additional' ], array_column($out['sections'], 'section'));
        $billing = $out['sections'][0];
        $this->assertTrue($billing['stored']);
        $this->assertSame([ 'billing_first_name', 'billing_company', 'billing_vat' ], array_column($billing['fields'], 'name'));

        $first = $billing['fields'][0];
        $this->assertSame('First name', $first['label']);
        $this->assertTrue($first['required']);
        $this->assertTrue($first['enabled']);
        $this->assertFalse($first['custom']);
        $this->assertSame(10, $first['priority']);
        $this->assertSame([ 'form-row-first' ], $first['class']);

        $vat = $billing['fields'][2];
        $this->assertSame('text', $vat['type']);
        $this->assertTrue($vat['custom']);
        $this->assertFalse($vat['required']);

        $shipping = $out['sections'][1];
        $this->assertFalse($shipping['stored'], 'an unsaved section says WooCommerce defaults apply');
        $this->assertSame([], $shipping['fields']);
        $this->assertTrue($out['writes_enabled']);
    }

    public function test_list_takes_one_section(): void
    {
        $out = $this->ok($this->read('list-checkout-fields', [ 'section' => 'additional' ]))['result'];
        $this->assertSame([ 'additional' ], array_column($out['sections'], 'section'));
        $this->assertSame('Order notes', $out['sections'][0]['fields'][0]['label']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Checkout_Field_Editor_Pack::ENABLE_FILTER);
        $out = $this->write('update-checkout-fields', [ 'section' => 'billing', 'fields' => [ 'billing_company' => [ 'enabled' => false ] ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Checkout_Field_Editor_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame(self::billing(), get_option('wc_fields_billing'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
        $this->assertFalse($this->ok($this->read('list-checkout-fields'))['result']['writes_enabled']);
    }

    public function test_update_changes_only_the_named_properties_and_rolls_back_exactly(): void
    {
        $before = get_option('wc_fields_billing');

        $out = $this->ok($this->write('update-checkout-fields', [
            'section' => 'billing',
            'fields'  => [
                'billing_company' => [ 'enabled' => false ],
                'billing_vat'     => [ 'label' => 'VAT ID', 'required' => true, 'priority' => 5, 'placeholder' => 'EU VAT' ],
            ],
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'billing_vat', 'billing_first_name', 'billing_company' ], array_column($out['result']['fields'], 'name'));
        $this->assertSame([ 'billing_company', 'billing_vat' ], $out['result']['changed']);
        $this->assertStringContainsString('checkout', $out['result']['check']);

        $stored = get_option('wc_fields_billing');
        $this->assertSame(0, $stored['billing_company']['enabled']);
        $this->assertSame('VAT ID', $stored['billing_vat']['label']);
        $this->assertSame(1, $stored['billing_vat']['required']);
        $this->assertSame(5, $stored['billing_vat']['priority']);
        $this->assertSame('EU VAT', $stored['billing_vat']['placeholder']);
        $this->assertSame('organization', $stored['billing_company']['autocomplete'], 'other properties are untouched');
        $this->assertSame($before['billing_first_name'], $stored['billing_first_name']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('wc_fields_billing'));
    }

    public function test_update_refuses_unknown_fields_unsaved_sections_bad_props_and_no_change(): void
    {
        $cases = [
            'unknown_field'      => [ 'section' => 'billing', 'fields' => [ 'billing_nope' => [ 'enabled' => false ] ] ],
            'section_not_stored' => [ 'section' => 'shipping', 'fields' => [ 'shipping_company' => [ 'enabled' => false ] ] ],
            'invalid_property'   => [ 'section' => 'billing', 'fields' => [ 'billing_vat' => [ 'validate' => [ 'nonsense' ] ] ] ],
            'no_change'          => [ 'section' => 'billing', 'fields' => [ 'billing_company' => [ 'enabled' => true, 'label' => 'Company name' ] ] ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('update-checkout-fields', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $out = $this->write('update-checkout-fields', [ 'section' => 'billing', 'fields' => [ 'billing_vat' => [ 'type' => 'select' ] ] ]);
        $this->assertArrayHasKey('error', $out, 'type and name are not writable');

        $this->assertSame(self::billing(), get_option('wc_fields_billing'));
        $this->assertFalse(get_option('wc_fields_shipping'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_label_markup_is_sanitized_like_the_settings_screen(): void
    {
        $this->ok($this->write('update-checkout-fields', [ 'section' => 'billing', 'fields' => [ 'billing_vat' => [ 'label' => 'VAT <script>alert(1)</script><strong>ID</strong>', 'class' => [ 'form-row-first', 'Bad Class!' ] ] ] ]));
        $stored = get_option('wc_fields_billing')['billing_vat'];
        $this->assertStringNotContainsString('<script>', $stored['label']);
        $this->assertStringContainsString('<strong>ID</strong>', $stored['label']);
        $this->assertSame([ 'form-row-first', 'badclass' ], $stored['class']);
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('list-checkout-fields'));
        $out = $this->write('update-checkout-fields', [ 'section' => 'billing', 'fields' => [ 'billing_company' => [ 'enabled' => false ] ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame(self::billing(), get_option('wc_fields_billing'));
    }
}
