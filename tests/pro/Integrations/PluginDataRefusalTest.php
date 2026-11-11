<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Dispatch\Call_Tool;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;

/**
 * Issue #815: with none of the plugin-data plugins active the pair is not
 * registered, and a call to one of its ops names the plugin that op works
 * with (MemberPress for a memberpress-* op), the way an op guard names
 * WooCommerce Subscriptions. Without a known op the refusal lists every
 * plugin the pair works with, Amelia, Polylang, WPML and MemberPress
 * included.
 */
class PluginDataRefusalTest extends \WP_UnitTestCase
{
    /** Every plugin-data presence filter, all forced off so the pair is held, not registered. */
    private const PRESENCE = [
        'wpmcp_amelia_active', 'wpmcp_awesome_support_active', 'wpmcp_bbpress_active', 'wpmcp_betterdocs_active', 'wpmcp_buddypress_active', 'wpmcp_code_snippets_active',
        'wpmcp_elementor_pro_active', 'wpmcp_fluent_support_active', 'wpmcp_fluentcrm_active', 'wpmcp_jetengine_active', 'wpmcp_learndash_active',
        'wpmcp_lifterlms_active', 'wpmcp_mailpoet_active', 'wpmcp_memberpress_active', 'wpmcp_multilingual_active',
        'wpmcp_pods_active', 'wpmcp_tablepress_active', 'wpmcp_translatepress_active', 'wpmcp_tutor_active',
        'wpmcp_ws_form_active',
    ];

    private Registrar $original;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_false', 999);
        }
        $this->original = Plugin::instance()->registrar();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        foreach (self::PRESENCE as $filter) {
            remove_filter($filter, '__return_false', 999);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Runs Plugin's own registration loop over the real pair into a fresh Registrar, made the live one. */
    private static function register(Plugin_Data_Integration $integration): Registrar
    {
        $fresh = new Registrar();
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $fresh);
        (new \ReflectionMethod(Plugin::class, 'register_integrations'))->invoke(Plugin::instance(), $fresh, [ $integration ]);
        return $fresh;
    }

    private static function call(string $tool, array $arguments): \WP_Error
    {
        $result = (new Call_Tool())->handle(['name' => $tool, 'arguments' => $arguments]);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('plugin_missing', $result->get_error_code());
        return $result;
    }

    public function test_a_memberpress_op_names_memberpress(): void
    {
        $integration = new Plugin_Data_Integration();
        $this->assertFalse($integration->is_available());
        $registrar = self::register($integration);
        $this->assertNull($registrar->get('wpmcp/plugin-data-read'));

        $result = self::call('wpmcp/plugin-data-read', ['operation' => 'memberpress-list-memberships', 'args' => []]);
        $this->assertSame(
            'MemberPress is not active on this site, so op "memberpress-list-memberships" of wpmcp/plugin-data-read cannot run. Install and activate MemberPress, then try again.',
            $result->get_error_message()
        );
        $this->assertSame([ 'plugin' => 'MemberPress', 'operation' => 'memberpress-list-memberships' ], $result->get_error_data());
    }

    public function test_each_op_names_its_own_plugin(): void
    {
        self::register(new Plugin_Data_Integration());

        $amelia = self::call('wpmcp/plugin-data-read', ['operation' => 'amelia-list-services']);
        $this->assertStringStartsWith('Amelia is not active on this site', $amelia->get_error_message());

        $tutor = self::call('wpmcp/plugin-data-read', ['operation' => 'tutor-list-courses']);
        $this->assertStringStartsWith('Tutor LMS is not active on this site', $tutor->get_error_message());

        $i18n = self::call('wpmcp/plugin-data-write', ['operation' => 'create-translation']);
        $this->assertSame(
            'Op "create-translation" of wpmcp/plugin-data-write works with Polylang, WPML, and none of them is active on this site, so it cannot run. Install and activate one of them, then try again.',
            $i18n->get_error_message()
        );
    }

    public function test_an_unknown_op_or_no_op_lists_every_plugin(): void
    {
        self::register(new Plugin_Data_Integration());

        foreach ([ ['operation' => 'list-memberships'], [] ] as $arguments) {
            $message = self::call('wpmcp/plugin-data-read', $arguments)->get_error_message();
            $this->assertStringStartsWith('None of the plugins wpmcp/plugin-data-read works with is active on this site (', $message);
            foreach ([ 'MemberPress', 'Amelia', 'Polylang', 'WPML', 'Elementor Pro', 'WS Form' ] as $plugin) {
                $this->assertStringContainsString($plugin, $message);
            }
        }

        $schema = (new Get_Tool_Schema())->handle(['name' => 'wpmcp/plugin-data-read']);
        $this->assertSame('plugin_missing', $schema->get_error_code());
        $this->assertStringContainsString('MemberPress', $schema->get_error_message());
    }

    /** Every op the pair merges has a plugin, and that plugin is named in the pair's own refusal list. */
    public function test_every_op_has_a_host_named_in_the_pair_summary(): void
    {
        $integration = new Plugin_Data_Integration();
        $hosts       = $integration->operation_hosts();
        $summary     = $integration->host_plugin()['name'];

        $ops = array_column($integration->catalog()['operations'], 'name');
        $this->assertNotEmpty($ops);
        $this->assertEqualsCanonicalizing($ops, array_keys($hosts));

        foreach ($hosts as $op => $host) {
            foreach (explode(', ', $host['name']) as $plugin) {
                $this->assertStringContainsString($plugin, $summary, "{$op}: {$plugin} missing from the pair summary");
            }
        }
    }

    public function test_an_unlicensed_install_does_not_name_a_plugin_for_a_paid_op(): void
    {
        Gate::set_pro_for_tests(false);

        $hosts = (new Plugin_Data_Integration())->operation_hosts();

        $this->assertArrayNotHasKey('memberpress-list-memberships', $hosts);
        $this->assertArrayHasKey('jetengine-list-fields', $hosts);
    }
}
