<?php

namespace WPMCP\Tests\Free\Rest;

use WPMCP\Plugin;

/**
 * The transport boundary: what a real MCP client can actually see and call.
 *
 * Every other test in this suite asserts in-process registry state, which is
 * why an ability surface that was completely invisible over REST shipped
 * unnoticed across 46 releases. Registration is not exposure. WordPress core
 * defaults WP_Ability::DEFAULT_SHOW_IN_REST to false and gates BOTH the list
 * controller (class-wp-rest-abilities-v1-list-controller.php) and the run
 * controller (class-wp-rest-abilities-v1-run-controller.php) on that meta
 * item, so an ability registered without it is not merely undiscoverable -
 * it cannot be executed at all. A registry assertion cannot tell the
 * difference; only a request through the REST controller can.
 */
class AbilityRestExposureTest extends \WP_Test_REST_TestCase
{
    private const LIST_ROUTE = '/wp-abilities/v1/abilities';

    /** A read tool, a write tool and a safety tool, so a partial regression still trips this. */
    private const REPRESENTATIVE = [
        'wpmcp/get-post',
        'wpmcp/create-post',
        'wpmcp/list-operations',
    ];

    private int $admin_id;

    /**
     * Fired once for the class, not per test: wp_abilities_api_init is not
     * idempotent, and re-running it makes the registry emit an
     * "already registered" doing_it_wrong for every ability.
     */
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_every_registered_ability_is_exposed_over_rest(): void
    {
        $hidden = [];

        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $registered = wp_get_ability($ability->name);
            if (null === $registered || ! $registered->get_meta_item('show_in_rest')) {
                $hidden[] = $ability->name;
            }
        }

        $this->assertSame(
            [],
            $hidden,
            'These abilities are registered but invisible and unrunnable over REST/MCP: '
                . implode(', ', array_slice($hidden, 0, 10))
                . (count($hidden) > 10 ? sprintf(' (and %d more)', count($hidden) - 10) : '')
        );
    }

    public function test_abilities_are_listed_by_the_rest_controller(): void
    {
        $request  = new \WP_REST_Request('GET', self::LIST_ROUTE);
        $request->set_param('per_page', 100);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $names = array_column((array) $response->get_data(), 'name');

        foreach (self::REPRESENTATIVE as $name) {
            $this->assertContains(
                $name,
                $names,
                "{$name} is registered but the REST list controller does not return it, so no MCP client can discover it."
            );
        }
    }

    public function test_a_registered_ability_can_be_run_through_the_rest_controller(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'   => 'Transport boundary probe',
            'post_content' => 'body',
        ]);

        $request = new \WP_REST_Request('POST', self::LIST_ROUTE . '/wpmcp/get-post/run');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['input' => ['post_id' => $post_id]]));

        $response = rest_do_request($request);

        $this->assertNotSame(
            404,
            $response->get_status(),
            'The run controller refuses abilities without show_in_rest, so the tool cannot be executed at all.'
        );
        $this->assertSame(200, $response->get_status());

        $data = (array) $response->get_data();
        $this->assertSame($post_id, $data['post_id'] ?? null);
        $this->assertSame('Transport boundary probe', $data['title'] ?? null);
    }
}
