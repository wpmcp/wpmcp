<?php

namespace WPMCP\Tests\Free\Privacy;

/**
 * Drives WordPress's own personal data export and erasure screens (issue
 * #478): a real user request, core's admin-ajax handlers, every registered
 * exporter or eraser in the order core runs them, and every page of each
 * until it reports done, exactly as Tools > Export Personal Data and Erase
 * Personal Data do in the browser. Used by WP_Ajax_UnitTestCase tests.
 *
 * Core's export page filter also builds the downloadable zip on the last
 * page; that file step is unhooked so the tests read the JSON pages instead.
 */
trait Privacy_Request_Runner
{
    /** @var array<string, int> Pages requested per exporter or eraser key, from the last run. */
    protected array $pages_requested = [];

    /**
     * Run a full export through core's ajax handler.
     *
     * @return array<string, array> The export items each exporter returned, by exporter key.
     */
    protected function run_core_export(string $email): array
    {
        remove_filter('wp_privacy_personal_data_export_page', 'wp_privacy_process_personal_data_export_page', 10);
        $request_id = wp_create_user_request($email, 'export_personal_data');
        $this->assertIsInt($request_id, 'The export request was not created.');

        return $this->run_core_request(
            'wp-privacy-export-personal-data',
            'exporter',
            $request_id,
            (array) apply_filters('wp_privacy_personal_data_exporters', []),
            static fn (array $data): array => (array) $data['data']
        );
    }

    /**
     * Run a full erasure through core's ajax handler.
     *
     * @return array<string, array> Every page's response, by eraser key.
     */
    protected function run_core_erasure(string $email): array
    {
        $request_id = wp_create_user_request($email, 'remove_personal_data');
        $this->assertIsInt($request_id, 'The erasure request was not created.');

        return $this->run_core_request(
            'wp-privacy-erase-personal-data',
            'eraser',
            $request_id,
            (array) apply_filters('wp_privacy_personal_data_erasers', []),
            static fn (array $data): array => [ $data ]
        );
    }

    private function run_core_request(string $action, string $index_field, int $request_id, array $handlers, callable $collect): array
    {
        // The ajax harness registers core's handlers only when their file is
        // already loaded, which nothing in this suite's bootstrap does.
        require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
        $handler = 'wp_ajax_' . str_replace('-', '_', $action);
        if (! has_action('wp_ajax_' . $action, $handler)) {
            add_action('wp_ajax_' . $action, $handler, 1);
        }
        $this->_setRole('administrator');
        // On a network, handling another person's privacy request is the
        // network administrator's (issue #530).
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->pages_requested = [];
        $out                   = [];
        $index                 = 0;
        foreach (array_keys($handlers) as $key) {
            ++$index;
            $out[ $key ] = [];
            for ($page = 1; $page <= 500; $page++) {
                $_POST                  = [];
                $_POST['id']            = $request_id;
                $_POST[ $index_field ]  = $index;
                $_POST['page']          = $page;
                $_POST['security']      = wp_create_nonce($action . '-' . $request_id);
                $_POST['sendAsEmail']   = 'false';
                $this->_last_response   = '';
                // A real request to admin-ajax.php has this; without it the
                // bundled Freemius SDK's admin page check reads a null match
                // on multisite and prints a deprecation into the JSON (#530).
                $php_self             = $_SERVER['PHP_SELF'] ?? null;
                $_SERVER['PHP_SELF']  = '/wp-admin/admin-ajax.php';
                try {
                    $this->_handleAjax($action);
                } catch (\WPAjaxDieContinueException $e) {
                    unset($e);
                } catch (\WPAjaxDieStopException $e) {
                    $this->fail(sprintf('%s page %d stopped: %s', $key, $page, $e->getMessage()));
                } finally {
                    $_SERVER['PHP_SELF'] = $php_self;
                }
                $response = json_decode($this->_last_response, true);
                $this->assertIsArray($response, sprintf('%s page %d returned no JSON: %s', $key, $page, $this->_last_response));
                $this->assertTrue($response['success'], sprintf('%s page %d failed: %s', $key, $page, $this->_last_response));
                $this->pages_requested[ $key ] = $page;
                $out[ $key ]                   = array_merge($out[ $key ], $collect($response['data']));
                if (! empty($response['data']['done'])) {
                    break;
                }
            }
        }
        unset($_POST);
        $_POST = [];
        return $out;
    }

    /** Every name/value pair of a list of export items, flattened to one string for searching. */
    protected static function export_text(array $items): string
    {
        $text = '';
        foreach ($items as $item) {
            $text .= (string) ($item['group_id'] ?? '') . "\n";
            foreach ((array) ($item['data'] ?? []) as $pair) {
                $text .= (string) ($pair['name'] ?? '') . ': ' . (string) ($pair['value'] ?? '') . "\n";
            }
        }
        return $text;
    }
}
