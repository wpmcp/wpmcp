<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Update_Option;

/**
 * Issue #729: gateway credential masking follows the option name, not the
 * stored shape. A gateway's settings stored as JSON text, a serialized
 * string, an object or plain text are masked on every read path, and
 * update-option does not turn the settings array into text unless the
 * caller asks for that explicitly. Fixture shared with
 * WooGatewaySecretMaskingTest.
 */
class GatewaySecretShapeMaskingTest extends \WP_UnitTestCase
{
    private const OPTION = 'woocommerce_wpmcp_shape_pay_settings';
    private const MASK   = '[redacted]';

    private const SECRETS = [
        'secret_key'      => 'sk_live_SHAPE123',
        'publishable_key' => 'pk_live_SHAPE456',
        'webhook_signing' => 'whsec_SHAPE789',
        'access_token'    => 'tok_SHAPE000',
    ];

    private const PLAIN = [
        'enabled'  => 'yes',
        'title'    => 'Shape Pay',
        'testmode' => 'no',
    ];

    /** @var callable|null */
    private $register = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        update_option(self::OPTION, self::SECRETS + self::PLAIN);

        $fake = new class extends \WC_Payment_Gateway {
            public function __construct()
            {
                $this->id                 = 'wpmcp_shape_pay';
                $this->method_title       = 'Shape Pay';
                $this->method_description = 'Masking fixture.';
                $this->init_form_fields();
                $this->init_settings();
            }

            public function init_form_fields()
            {
                $this->form_fields = [
                    'enabled'         => [ 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ],
                    'title'           => [ 'title' => 'Title', 'type' => 'text', 'default' => 'Shape Pay' ],
                    'secret_key'      => [ 'title' => 'Secret key', 'type' => 'text', 'default' => '' ],
                    'publishable_key' => [ 'title' => 'Publishable key', 'type' => 'text', 'default' => '' ],
                    'webhook_signing' => [ 'title' => 'Webhook signing', 'type' => 'password', 'default' => '' ],
                    'access_token'    => [ 'title' => 'Access token', 'type' => 'text', 'default' => '' ],
                    'testmode'        => [ 'title' => 'Test mode', 'type' => 'checkbox', 'default' => 'no' ],
                ];
            }
        };
        $this->register = static function ($gateways) use ($fake) {
            $gateways[] = $fake;
            return $gateways;
        };
        add_filter('woocommerce_payment_gateways', $this->register);
        $this->reload();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        if (null !== $this->register) {
            remove_filter('woocommerce_payment_gateways', $this->register);
            $this->reload();
        }
        remove_all_filters('wpmcp_enable_option_write');
        remove_all_filters('wpmcp_enable_db_writes');
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function reload(): void
    {
        $gateways                   = WC()->payment_gateways();
        $gateways->payment_gateways = [];
        $gateways->init();
    }

    /** @return mixed */
    private function stored()
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return get_option(self::OPTION);
    }

    /** @param mixed $out */
    private function assertNoCredential($out, string $where): void
    {
        $text = is_string($out) ? $out : (string) wp_json_encode($out);
        foreach (self::SECRETS as $key => $value) {
            $this->assertStringNotContainsString($value, $text, "{$key} leaked through {$where}");
        }
    }

    /** @param array<mixed> $settings */
    private function assertMaskedKeys(array $settings, string $where): void
    {
        foreach (array_keys(self::SECRETS) as $key) {
            $this->assertSame(self::MASK, $settings[ $key ] ?? null, "{$key} not masked through {$where}");
        }
        $this->assertSame('Shape Pay', $settings['title'] ?? null, "{$where} masked a non-secret field");
    }

    /**
     * Each shape the settings can be stored in, as get-option returns it.
     *
     * @return array<string, array{0: callable(array): mixed, 1: callable(mixed): ?array}>
     */
    public static function shapes(): array
    {
        return [
            'json text'         => [
                static fn (array $s) => wp_json_encode($s),
                static fn ($v) => is_string($v) ? json_decode($v, true) : null,
            ],
            'serialized string' => [
                static fn (array $s) => serialize($s),
                static fn ($v) => is_string($v) ? unserialize($v, [ 'allowed_classes' => false ]) : null,
            ],
            'json in serialized string' => [
                static fn (array $s) => serialize(wp_json_encode($s)),
                static fn ($v) => is_string($v) ? json_decode((string) unserialize($v, [ 'allowed_classes' => false ]), true) : null,
            ],
            'object'            => [
                static fn (array $s) => (object) $s,
                static fn ($v) => is_object($v) ? get_object_vars($v) : null,
            ],
            'nested json'       => [
                static fn (array $s) => wp_json_encode([ 'settings' => $s ]),
                static fn ($v) => is_string($v) ? (json_decode($v, true)['settings'] ?? null) : null,
            ],
        ];
    }

    /** @dataProvider shapes */
    public function test_get_option_masks_the_settings_whatever_their_shape(callable $store, callable $read): void
    {
        update_option(self::OPTION, $store(self::SECRETS + self::PLAIN));

        $out = (new Get_Option())->handle([ 'name' => self::OPTION ]);

        $this->assertNoCredential($out, 'get-option');
        $settings = $read($out['value']);
        $this->assertIsArray($settings, 'get-option changed the shape of the stored value');
        $this->assertMaskedKeys($settings, 'get-option');
    }

    public function test_get_option_masks_settings_text_it_cannot_parse_whole(): void
    {
        update_option(self::OPTION, 'secret_key=' . self::SECRETS['secret_key'] . '&title=Shape Pay');

        $out = (new Get_Option())->handle([ 'name' => self::OPTION ]);

        $this->assertSame(self::MASK, $out['value']);
    }

    public function test_update_option_refuses_to_turn_the_settings_array_into_text(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');

        try {
            (new Update_Option())->handle([ 'name' => self::OPTION, 'value' => wp_json_encode(self::SECRETS + self::PLAIN) ]);
            $this->fail('update-option replaced the settings array with text.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('allow_type_change', $e->getMessage());
            $this->assertNoCredential($e->getMessage(), 'the refusal');
        }
        $this->assertSame(self::SECRETS + self::PLAIN, $this->stored());
    }

    public function test_an_explicit_type_change_is_masked_on_the_way_out_and_undoable(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');

        $out = (new Update_Option())->handle([
            'name'              => self::OPTION,
            'value'             => wp_json_encode(self::SECRETS + self::PLAIN),
            'allow_type_change' => true,
        ]);

        $this->assertIsString($this->stored());
        $this->assertNoCredential($out, 'update-option');
        $this->assertNoCredential((new Get_Option())->handle([ 'name' => self::OPTION ]), 'get-option');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(self::SECRETS + self::PLAIN, $this->stored());
    }

    public function test_a_masked_round_trip_of_json_text_keeps_the_credentials(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        update_option(self::OPTION, wp_json_encode(self::SECRETS + self::PLAIN));

        $read          = json_decode((new Get_Option())->handle([ 'name' => self::OPTION ])['value'], true);
        $read['title'] = 'Renamed Pay';
        (new Update_Option())->handle([ 'name' => self::OPTION, 'value' => wp_json_encode($read) ]);

        $stored = json_decode((string) $this->stored(), true);
        $this->assertSame('Renamed Pay', $stored['title']);
        foreach (self::SECRETS as $key => $value) {
            $this->assertSame($value, $stored[ $key ], "update-option overwrote {$key} with the mask");
        }
    }

    public function test_writing_back_a_whole_mask_keeps_the_stored_text(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        $text = 'secret_key=' . self::SECRETS['secret_key'];
        update_option(self::OPTION, $text);

        (new Update_Option())->handle([ 'name' => self::OPTION, 'value' => self::MASK ]);

        $this->assertSame($text, $this->stored());
    }

    public function test_update_rows_masks_a_json_text_before_image(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');
        update_option(self::OPTION, wp_json_encode(self::SECRETS + self::PLAIN));

        $out = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => [ 'option_value' => serialize([ 'enabled' => 'no' ]) ],
            'where'   => [ 'option_name' => self::OPTION ],
            'confirm' => true,
        ]);

        $this->assertNoCredential($out, 'update-rows before_image');
        $this->assertMaskedKeys((array) json_decode($out['before_image'][0]['option_value'], true), 'update-rows before_image');

        $audit = (array) get_option(Database_Guard::AUDIT_OPTION, []);
        $this->assertNoCredential(end($audit), 'the stored audit log');
    }
}
