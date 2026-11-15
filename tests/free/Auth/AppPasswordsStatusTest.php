<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\App_Passwords_Status;
use WPMCP\Connect\Connection_Tester;
use WPMCP\Tools\Connect\Get_Connection_Info;

/**
 * Issue #883: another plugin (Wordfence by default) can switch Application
 * Passwords off, and the client then sees a bare 401. The 401 says why, the
 * Connection self-test reports it, and get-connection-info carries it.
 */
class AppPasswordsStatusTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // wp_is_application_passwords_supported() needs HTTPS or a local environment.
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS']);
        remove_all_filters('wp_is_application_passwords_available');
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private static function request(string $authorization): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/mcp/wpmcp-server');
        if ('' !== $authorization) {
            $request->set_header('authorization', $authorization);
        }
        return $request;
    }

    private static function unauthorized(): \WP_REST_Response
    {
        return new \WP_REST_Response([ 'code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.', 'data' => [ 'status' => 401 ] ], 401);
    }

    public function test_available_passwords_report_available(): void
    {
        $this->assertSame([ 'available' => true ], App_Passwords_Status::state());
    }

    public function test_a_forced_off_filter_is_named_with_the_fix(): void
    {
        add_filter('wp_is_application_passwords_available', '__return_false');

        $state = App_Passwords_Status::state();
        $this->assertFalse($state['available']);
        $this->assertSame('disabled_by_plugin', $state['reason']);
        $this->assertStringContainsString('Application Passwords are disabled on this site', $state['message']);
        $this->assertStringContainsString('OAuth', $state['message']);
    }

    public function test_a_basic_credential_401_says_application_passwords_are_disabled(): void
    {
        add_filter('wp_is_application_passwords_available', '__return_false');
        $secret = base64_encode('admin:abcd efgh ijkl mnop qrst uvwx');

        $out = App_Passwords_Status::explain_unauthorized(self::unauthorized(), null, self::request('Basic ' . $secret));

        $this->assertSame(401, $out->get_status());
        $data = $out->get_data();
        $this->assertSame('rest_forbidden', $data['code']);
        $this->assertStringContainsString('Application Passwords are disabled on this site', $data['message']);
        $this->assertFalse($data['data']['application_passwords_available']);
        $this->assertSame(401, $data['data']['status']);
        $this->assertStringNotContainsString($secret, wp_json_encode($data));
    }

    public function test_the_json_rpc_error_shape_is_explained_too(): void
    {
        add_filter('wp_is_application_passwords_available', '__return_false');
        $response = new \WP_REST_Response([ 'jsonrpc' => '2.0', 'id' => 1, 'error' => [ 'code' => -32001, 'message' => 'Unauthorized' ] ], 401);

        $out = App_Passwords_Status::explain_unauthorized($response, null, self::request('Basic abc'));

        $this->assertStringContainsString('Application Passwords are disabled', $out->get_data()['error']['message']);
        $this->assertSame(-32001, $out->get_data()['error']['code']);
    }

    public function test_the_normal_invalid_credentials_error_is_left_alone(): void
    {
        // Available: a wrong password keeps its own error.
        $out = App_Passwords_Status::explain_unauthorized(self::unauthorized(), null, self::request('Basic abc'));
        $this->assertSame('Sorry, you are not allowed to do that.', $out->get_data()['message']);

        // Off, but the request is not Basic or carries no credential at all.
        add_filter('wp_is_application_passwords_available', '__return_false');
        foreach ([ 'Bearer abc', '' ] as $header) {
            $out = App_Passwords_Status::explain_unauthorized(self::unauthorized(), null, self::request($header));
            $this->assertSame('Sorry, you are not allowed to do that.', $out->get_data()['message']);
        }
    }

    public function test_the_self_test_reports_it_and_clears_when_back(): void
    {
        add_filter('pre_http_request', static fn () => [ 'response' => [ 'code' => 401, 'message' => '' ], 'headers' => [], 'body' => '' ]);
        $find = static function (array $result): array {
            foreach ($result['checks'] as $check) {
                if ('application_passwords' === $check['id']) {
                    return $check;
                }
            }
            return [];
        };

        add_filter('wp_is_application_passwords_available', '__return_false');
        $off = (new Connection_Tester())->test();
        $this->assertFalse($find($off)['ok']);
        $this->assertStringContainsString('Application Passwords are disabled', $off['message']);

        remove_all_filters('wp_is_application_passwords_available');
        $on = (new Connection_Tester())->test();
        $this->assertTrue($find($on)['ok']);
    }

    public function test_get_connection_info_reports_the_state(): void
    {
        $this->assertTrue((new Get_Connection_Info())->handle([])['application_passwords_available']);

        add_filter('wp_is_application_passwords_available', '__return_false');
        $info = (new Get_Connection_Info())->handle([]);
        $this->assertFalse($info['application_passwords_available']);
        $this->assertStringContainsString('Application Passwords are disabled', $info['application_passwords_notice']);
    }
}
