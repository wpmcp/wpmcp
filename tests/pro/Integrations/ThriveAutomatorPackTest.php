<?php

namespace WPMCP\Tests\Pro\Integrations;

use Thrive\Automator\Hooks;
use Thrive\Automator\Items\Action;
use Thrive\Automator\Items\App;
use Thrive\Automator\Items\Automation;
use Thrive\Automator\Items\Filter;
use Thrive\Automator\Items\Text_Filter;
use Thrive\Automator\Items\Trigger;
use Thrive\Automator\Items\Webhook;
use Thrive\Automator\Items\Wordpress_Add_User_Role;
use Thrive\Automator\Items\Wordpress_App;
use Thrive\Automator\Items\Wordpress_Post_Publish;
use Thrive\Automator\Items\Wordpress_Webhook_Receive;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thrive_Automator_Pack;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/thrive-automator-double.php';

/**
 * Issue #830: Thrive Automator automations as paid-tier ops on the
 * plugin-data pair.
 *
 * Thrive Automator is not loaded in the shared test core. Presence is driven
 * through wpmcp_thrive_automator_active and its registries and scheduling
 * calls come from the double. Automations are tve_automation posts seeded
 * with post_content in the serialized steps shape Thrive Automator 10.6.1.1
 * saves (Automation::save()).
 */
class ThriveAutomatorPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'thrive-automator-list-automations'   => 'read',
        'thrive-automator-get-automation'     => 'read',
        'thrive-automator-list-catalog'       => 'read',
        'thrive-automator-enable-automation'  => 'destructive',
        'thrive-automator-disable-automation' => 'write',
    ];

    private const BEARER = 'Bearer s3cr3t-header-value';

    private const API_KEY = 'k-0123456789abcdef';

    private const HOOK_PATH = '/hooks/catch/123/abcSECRET';

    private int $webhook_automation;

    private int $role_automation;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_thrive_automator_active', '__return_true');
        add_filter(Thrive_Automator_Pack::ENABLE_FILTER, '__return_true');
        foreach ([ Trigger::class, Action::class, Filter::class, App::class ] as $registry) {
            $registry::reset();
        }
        Trigger::register(Wordpress_Post_Publish::class);
        Trigger::register(Wordpress_Webhook_Receive::class);
        Action::register(Webhook::class);
        Action::register(Wordpress_Add_User_Role::class);
        Filter::register(Text_Filter::class);
        App::register(Wordpress_App::class);
        Automation::$calls = [];

        $this->webhook_automation = $this->automation('Send leads to CRM', 'publish', [
            [
                'id'   => 'step-1',
                'type' => 'triggers',
                'data' => [
                    't1' => [
                        'key'        => 'wordpress/webhook_receive',
                        'app_id'     => 'general',
                        'webhook_id' => 'abCDe',
                        'extra_data' => [ 'webhook_hash' => [ 'value' => 'abCDe' ] ],
                        'image'      => 'https://example.test/icon.png',
                    ],
                ],
            ],
            [
                'id'   => 'step-2',
                'type' => 'filters',
                'data' => [
                    'f1' => [ 'filter' => 'text_filter', 'field' => 'user_email', 'data_object' => 'user_data', 'value' => '@example.com', 'operator' => 'contains' ],
                ],
            ],
            [
                'id'   => 'step-3',
                'type' => 'delay',
                'data' => [ 'd1' => [ 'value' => 2, 'unit' => 'hours' ] ],
            ],
            [
                'id'   => 'step-4',
                'type' => 'actions',
                'data' => [
                    'a1' => [
                        'key'        => 'general/webhook',
                        'app_id'     => 'general',
                        'extra_data' => [
                            'url_webhook'            => [ 'value' => 'https://hooks.example.com' . self::HOOK_PATH . '?token=zzz' ],
                            'request_method'         => [ 'value' => 'post', 'subfield' => [ 'request_format' => [ 'value' => 'json' ] ] ],
                            'request_headers_toggle' => [
                                'value'    => 'custom',
                                'subfield' => [
                                    'webhook_headers' => [ 'value' => [ [ 'key' => 'Authorization', 'value' => self::BEARER ] ] ],
                                ],
                            ],
                            'fields_webhook'         => [
                                'value' => [
                                    [ 'key' => 'api_key', 'value' => self::API_KEY ],
                                    [ 'key' => 'email', 'value' => '%user_email%' ],
                                ],
                            ],
                            'password'               => [ 'value' => 'hunter2' ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->role_automation    = $this->automation('Tag new authors', 'draft', [
            [
                'id'   => 'step-1',
                'type' => 'triggers',
                'data' => [ 't1' => [ 'key' => 'wordpress/postpublish', 'app_id' => 'wordpress', 'extra_data' => [] ] ],
            ],
            [
                'id'   => 'step-2',
                'type' => 'actions',
                'data' => [
                    'a1' => [ 'key' => 'wordpress/add_user_role', 'app_id' => 'wordpress', 'extra_data' => [ 'user_role' => [ 'value' => 'author' ] ] ],
                    'a2' => [ 'key' => 'mailchimp/add_tag', 'app_id' => 'mailchimp', 'extra_data' => [] ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_thrive_automator_active', Thrive_Automator_Pack::ENABLE_FILTER, 'tap_post_publish', 'tap_post_updated' ] as $hook) {
            remove_all_filters($hook);
        }
        remove_action('transition_post_status', [ Hooks::class, 'launch_post_status_hooks' ], 10);
        remove_action('post_updated', [ Hooks::class, 'launch_post_updated_hook' ], 1);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** A tve_automation post as Automation::save() stores it. */
    private function automation(string $title, string $status, array $steps): int
    {
        global $wpdb;
        $id = self::factory()->post->create([ 'post_type' => Thrive_Automator_Pack::POST_TYPE, 'post_status' => $status, 'post_title' => $title ]);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the shape Thrive stores.
        $wpdb->update($wpdb->posts, [ 'post_content' => serialize($steps) ], [ 'ID' => $id ]);
        clean_post_cache($id);
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = [], bool $confirm = false): array
    {
        $input = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $input['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($input);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
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
        remove_all_filters('wpmcp_thrive_automator_active');
        add_filter('wpmcp_thrive_automator_active', '__return_false');
        $out = $this->read('thrive-automator-list-automations');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Thrive Automator', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);

        $out = $this->write('thrive-automator-disable-automation', [ 'id' => $this->webhook_automation ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertSame('publish', get_post_status($this->webhook_automation));
    }

    public function test_list_reports_each_automation_with_its_trigger_and_action_names(): void
    {
        $out  = $this->ok($this->read('thrive-automator-list-automations'))['result'];
        $rows = array_column($out['automations'], null, 'id');
        $this->assertSame(2, $out['total']);

        $hook = $rows[ $this->webhook_automation ];
        $this->assertSame('Send leads to CRM', $hook['title']);
        $this->assertTrue($hook['active']);
        $this->assertSame([ [ 'key' => 'wordpress/webhook_receive', 'name' => 'Incoming webhook' ] ], $hook['triggers']);
        $this->assertSame([ [ 'key' => 'general/webhook', 'name' => 'Send a webhook' ] ], $hook['actions']);
        $this->assertTrue($hook['valid']);
        $this->assertSame(4, $hook['steps']);

        $role = $rows[ $this->role_automation ];
        $this->assertFalse($role['active']);
        $this->assertSame([ 'key' => 'mailchimp/add_tag', 'name' => null ], $role['actions'][1]);
        $this->assertFalse($role['valid'], 'an action this site no longer has makes it invalid');

        $active = $this->ok($this->read('thrive-automator-list-automations', [ 'status' => 'active' ]))['result'];
        $this->assertSame([ $this->webhook_automation ], array_column($active['automations'], 'id'));
        $off = $this->ok($this->read('thrive-automator-list-automations', [ 'status' => 'inactive', 'per_page' => 1 ]))['result'];
        $this->assertSame([ $this->role_automation ], array_column($off['automations'], 'id'));

        $this->assertStringNotContainsString(self::BEARER, (string) wp_json_encode($out));
    }

    public function test_get_returns_every_step_and_never_a_credential(): void
    {
        $out = $this->ok($this->read('thrive-automator-get-automation', [ 'id' => $this->webhook_automation ]))['result']['automation'];

        $this->assertSame([ 'triggers', 'filters', 'delay', 'actions' ], array_column($out['steps'], 'type'));
        $trigger = $out['steps'][0]['items'][0];
        $this->assertSame('wordpress/webhook_receive', $trigger['key']);
        $this->assertSame('Incoming webhook', $trigger['name']);
        $this->assertTrue($trigger['available']);
        $this->assertSame('[redacted]', $trigger['settings']['webhook_id']);
        $this->assertSame('[redacted]', $trigger['settings']['extra_data']['webhook_hash']);
        $this->assertArrayNotHasKey('image', $trigger['settings']);

        $filter = $out['steps'][1]['items'][0];
        $this->assertSame('text_filter', $filter['filter']);
        $this->assertSame('Text', $filter['name']);
        $this->assertSame('@example.com', $filter['settings']['value']);
        $this->assertSame([ 'value' => 2, 'unit' => 'hours' ], $out['steps'][2]['items'][0]['settings']);

        $action = $out['steps'][3]['items'][0]['settings']['extra_data'];
        $this->assertSame('https://hooks.example.com/[redacted]', $action['url_webhook']['value']);
        $this->assertSame([ [ 'key' => 'Authorization', 'value' => '[redacted]' ] ], $action['request_headers_toggle']['subfield']['webhook_headers']['value']);
        $this->assertSame([ 'key' => 'api_key', 'value' => '[redacted]' ], $action['fields_webhook']['value'][0]);
        $this->assertSame([ 'key' => 'email', 'value' => '%user_email%' ], $action['fields_webhook']['value'][1]);
        $this->assertSame('[redacted]', $action['password']);
        $this->assertSame('json', $action['request_method']['subfield']['request_format']['value']);

        $json = (string) wp_json_encode($out);
        foreach ([ self::BEARER, self::API_KEY, self::HOOK_PATH, 'abCDe', 'hunter2', 'token=zzz' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function test_get_refuses_a_post_that_is_not_an_automation(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        foreach ([ $page, 999999 ] as $id) {
            $out = $this->read('thrive-automator-get-automation', [ 'id' => $id ]);
            $this->assertSame('automation_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        }
    }

    public function test_catalog_lists_triggers_filters_actions_and_apps(): void
    {
        $out = $this->ok($this->read('thrive-automator-list-catalog'))['result'];
        $this->assertSame([ 'wordpress/postpublish', 'wordpress/webhook_receive' ], array_column($out['triggers'], 'id'));
        $this->assertSame('Post is published', $out['triggers'][0]['name']);
        $this->assertSame('wordpress', $out['triggers'][0]['app_id']);
        $this->assertTrue($out['triggers'][0]['available']);
        $this->assertSame([ 'general/webhook', 'wordpress/add_user_role' ], array_column($out['actions'], 'id'));
        $this->assertSame([ [ 'id' => 'text_filter', 'name' => 'Text' ] ], $out['filters']);
        $this->assertSame('WordPress', $out['apps'][0]['name']);

        $wp = $this->ok($this->read('thrive-automator-list-catalog', [ 'kind' => 'actions', 'app' => 'wordpress' ]))['result'];
        $this->assertSame([ 'actions' ], array_keys($wp));
        $this->assertSame([ 'wordpress/add_user_role' ], array_column($wp['actions'], 'id'));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Thrive_Automator_Pack::ENABLE_FILTER);
        $out = $this->write('thrive-automator-disable-automation', [ 'id' => $this->webhook_automation ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Thrive_Automator_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('publish', get_post_status($this->webhook_automation));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_enable_needs_confirm_and_previews_what_it_would_run(): void
    {
        $out = $this->write('thrive-automator-enable-automation', [ 'id' => $this->role_automation ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Add user role', $out['error']['message']);
        $this->assertSame('draft', get_post_status($this->role_automation));
        $this->assertSame([], Automation::$calls);
    }

    public function test_enable_turns_it_on_reschedules_and_rolls_back_exactly(): void
    {
        Plugin::register_rollback_refreshers([ [ Thrive_Automator_Pack::class, 'after_post_restored', 'wpmcp_rollback_post_restored' ] ]);
        $before = get_post($this->role_automation, ARRAY_A);

        $out = $this->ok($this->write('thrive-automator-enable-automation', [ 'id' => $this->role_automation ], true));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['automation']['active']);
        $this->assertSame('publish', get_post_status($this->role_automation));
        $this->assertSame(
            [ [ 'unschedule', $this->role_automation, 'draft' ], [ 'schedule', $this->role_automation, 'publish' ] ],
            Automation::$calls,
            'events are cleared before and set after, as Automation::save() does'
        );
        $this->assertSame($before['post_content'], get_post($this->role_automation)->post_content, 'the steps are untouched');

        Automation::$calls = [];
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('draft', get_post_status($this->role_automation));
        $this->assertSame($before['post_content'], get_post($this->role_automation)->post_content);
        $this->assertSame(
            [ [ 'unschedule', $this->role_automation, 'draft' ], [ 'schedule', $this->role_automation, 'draft' ] ],
            Automation::$calls,
            'the undo resets the scheduled events for the restored status'
        );
    }

    public function test_disable_turns_it_off_and_rolls_back(): void
    {
        $out = $this->ok($this->write('thrive-automator-disable-automation', [ 'id' => $this->webhook_automation ]));
        $this->assertFalse($out['result']['automation']['active']);
        $this->assertSame('draft', get_post_status($this->webhook_automation));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($this->webhook_automation));
    }

    public function test_a_status_change_does_not_fire_thrive_post_triggers(): void
    {
        add_action('transition_post_status', [ Hooks::class, 'launch_post_status_hooks' ], 10, 3);
        add_action('post_updated', [ Hooks::class, 'launch_post_updated_hook' ], 1, 3);
        $fired = 0;
        $count = static function () use (&$fired): void {
            ++$fired;
        };
        add_action('tap_post_publish', $count);
        add_action('tap_post_updated', $count);

        $this->ok($this->write('thrive-automator-enable-automation', [ 'id' => $this->role_automation ], true));
        $this->ok($this->write('thrive-automator-disable-automation', [ 'id' => $this->webhook_automation ]));
        $this->assertSame(0, $fired);
        $this->assertSame(10, has_action('transition_post_status', [ Hooks::class, 'launch_post_status_hooks' ]), 'Thrive\'s listeners are put back');
        $this->assertSame(1, has_action('post_updated', [ Hooks::class, 'launch_post_updated_hook' ]));
    }

    public function test_a_write_in_the_state_already_set_is_refused(): void
    {
        $out = $this->write('thrive-automator-enable-automation', [ 'id' => $this->webhook_automation ], true);
        $this->assertSame('automation_already_active', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $out = $this->write('thrive-automator-disable-automation', [ 'id' => $this->role_automation ]);
        $this->assertSame('automation_already_inactive', $out['error']['code'] ?? null);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('thrive-automator-get-automation', [ 'id' => $this->webhook_automation ]));
        $this->assertArrayHasKey('error', $this->write('thrive-automator-disable-automation', [ 'id' => $this->webhook_automation ]));
        $this->assertSame('publish', get_post_status($this->webhook_automation));
    }
}
