<?php

namespace WPMCP\Tools\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: run the Site Health tests and return their results (issue #381).
 *
 * The test list is WP_Site_Health::get_tests(), so it is filtered through
 * site_status_tests and includes every test another plugin registers, and
 * each callable result passes through site_status_test_result, the same
 * filter the Site Health screen applies. Direct tests always run. Async tests
 * run one after another inside a time bound: a test is not started once the
 * bound is reached, a test that finishes past it is reported as not
 * completed, and while one runs every outgoing HTTP request has its timeout
 * capped to the time left, so no single test can hold the call open.
 *
 * Async tests are run the way core's scheduled check runs them: through
 * async_direct_test when the test provides one, otherwise through its REST
 * route dispatched in-process as the current user. A test that only exists
 * as an admin-ajax action, or that needs request headers only the browser
 * screen sends, cannot be run from here and is reported as not completed
 * with the reason.
 *
 * HTML in labels, descriptions and actions is reduced to plain text; the
 * links in actions are kept separately as action_links.
 *
 * A full run (no tests subset) is stored in a transient so cached mode can
 * return it without rerunning anything. Nothing else is written.
 */
class Get_Site_Health
{
    public const CACHE_KEY = 'wpmcp_site_health_last';

    public const DEFAULT_TIMEOUT = 10;
    public const MAX_TIMEOUT     = 30;

    private const STATUSES = [ 'good', 'recommended', 'critical' ];

    /** @var callable(): float */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function handle(array $args): array
    {
        if (! empty($args['cached'])) {
            return $this->cached();
        }

        require_once ABSPATH . 'wp-admin/includes/admin.php';
        if (! class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        $site_health = \WP_Site_Health::get_instance();
        $tests       = \WP_Site_Health::get_tests();

        // The Site Health screen skips the HTTPS test on development sites.
        if ($site_health->is_development_environment()) {
            unset($tests['async']['https_status']);
        }

        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        $async  = is_array($tests['async'] ?? null) ? $tests['async'] : [];

        $subset  = null;
        $unknown = [];
        if (isset($args['tests']) && is_array($args['tests']) && [] !== $args['tests']) {
            $subset  = array_values(array_unique(array_map('strval', $args['tests'])));
            $known   = array_merge(array_keys($direct), array_keys($async));
            $unknown = array_values(array_diff($subset, array_map('strval', $known)));
            $direct  = array_intersect_key($direct, array_flip($subset));
            $async   = array_intersect_key($async, array_flip($subset));
        }

        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : self::DEFAULT_TIMEOUT;
        $timeout = max(1, min(self::MAX_TIMEOUT, $timeout));

        $results = [];
        foreach ($direct as $id => $test) {
            $results[] = $this->run_direct($site_health, (string) $id, is_array($test) ? $test : []);
        }

        $deadline = ($this->clock)() + $timeout;
        foreach ($async as $id => $test) {
            $results[] = $this->run_async((string) $id, is_array($test) ? $test : [], $deadline);
        }

        $payload = [
            'cached'       => false,
            'generated_at' => gmdate('c'),
            'summary'      => self::summarize($results),
            'results'      => $results,
        ];

        if (null === $subset) {
            set_transient(self::CACHE_KEY, $payload, WEEK_IN_SECONDS);
        } else {
            $payload['unknown_tests'] = $unknown;
        }

        return $payload;
    }

    private function cached(): array
    {
        $stored = get_transient(self::CACHE_KEY);
        $screen = json_decode((string) get_transient('health-check-site-status-result'), true);
        $screen = is_array($screen) ? $screen : null;

        if (! is_array($stored) || ! isset($stored['results'])) {
            return [
                'cached'         => true,
                'available'      => false,
                'generated_at'   => null,
                'summary'        => self::summarize([]),
                'results'        => [],
                'screen_summary' => $screen,
            ];
        }

        $stored['cached']         = true;
        $stored['available']      = true;
        $stored['screen_summary'] = $screen;

        return $stored;
    }

    private function run_direct(\WP_Site_Health $site_health, string $id, array $test): array
    {
        $callback = null;
        if (isset($test['test']) && is_string($test['test'])) {
            $method = 'get_test_' . $test['test'];
            if (method_exists($site_health, $method) && is_callable([ $site_health, $method ])) {
                $callback = [ $site_health, $method ];
            }
        }
        if (null === $callback && isset($test['test']) && is_callable($test['test'])) {
            $callback = $test['test'];
        }

        if (null === $callback) {
            return self::not_completed($id, 'direct', $test, 'The test has no callable to run.');
        }

        return $this->call($id, 'direct', $test, $callback);
    }

    private function run_async(string $id, array $test, float $deadline): array
    {
        if (($this->clock)() >= $deadline) {
            return self::not_completed($id, 'async', $test, 'Not started: the time bound was reached before this test ran.');
        }

        $cap = function (array $request_args) use ($deadline): array {
            $left                    = max(1, (int) ceil($deadline - ($this->clock)()));
            $current                 = isset($request_args['timeout']) ? (float) $request_args['timeout'] : $left;
            $request_args['timeout'] = min($current, $left);
            return $request_args;
        };
        add_filter('http_request_args', $cap, PHP_INT_MAX);

        try {
            if (! empty($test['async_direct_test']) && is_callable($test['async_direct_test'])) {
                $row = $this->call($id, 'async', $test, $test['async_direct_test']);
            } elseif (! empty($test['has_rest']) && isset($test['test']) && is_string($test['test'])) {
                $row = $this->run_rest($id, $test);
            } else {
                $row = self::not_completed($id, 'async', $test, 'The test only runs through admin-ajax from the Site Health screen.');
            }
        } finally {
            remove_filter('http_request_args', $cap, PHP_INT_MAX);
        }

        if ($row['completed'] && ($this->clock)() > $deadline) {
            return self::not_completed($id, 'async', $test, 'The test exceeded the time bound, so its result is not reported.');
        }

        return $row;
    }

    private function run_rest(string $id, array $test): array
    {
        if (! empty($test['headers'])) {
            return self::not_completed($id, 'async', $test, 'The test needs request headers that only the Site Health screen sends.');
        }

        $route = self::rest_route((string) $test['test']);
        if (null === $route) {
            return self::not_completed($id, 'async', $test, 'The test endpoint is not a REST route on this site.');
        }

        $response = rest_do_request(new \WP_REST_Request('GET', $route));
        $data     = $response->get_data();
        if ($response->is_error() || ! is_array($data)) {
            return self::not_completed($id, 'async', $test, 'The test endpoint returned an error.');
        }

        return self::normalize($id, 'async', $data);
    }

    /** Turn a rest_url() into a route, or null when it points elsewhere. */
    private static function rest_route(string $url): ?string
    {
        if ('' !== $url && '/' === $url[0]) {
            return $url;
        }

        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        if (isset($params['rest_route']) && is_string($params['rest_route'])) {
            return '/' . ltrim($params['rest_route'], '/');
        }

        $base = untrailingslashit(rest_url());
        if (0 === strpos($url, $base)) {
            $route = (string) strtok(substr($url, strlen($base)), '?');
            return '/' . ltrim($route, '/');
        }

        return null;
    }

    private function call(string $id, string $type, array $test, callable $callback): array
    {
        try {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying core's Site Health result filter, as the Site Health screen does, not defining one.
            $result = apply_filters('site_status_test_result', call_user_func($callback));
        } catch (\Throwable $e) {
            return self::not_completed($id, $type, $test, 'The test failed with an error: ' . $e->getMessage());
        }

        if (! is_array($result)) {
            return self::not_completed($id, $type, $test, 'The test returned no result.');
        }

        return self::normalize($id, $type, $result);
    }

    private static function normalize(string $id, string $type, array $result): array
    {
        $status = isset($result['status']) ? (string) $result['status'] : '';
        if (! in_array($status, self::STATUSES, true)) {
            $status = 'recommended';
        }

        $badge   = isset($result['badge']) && is_array($result['badge']) ? $result['badge'] : [];
        $actions = isset($result['actions']) ? (string) $result['actions'] : '';

        return [
            'id'           => $id,
            'type'         => $type,
            'completed'    => true,
            'status'       => $status,
            'label'        => self::plain(isset($result['label']) ? (string) $result['label'] : ''),
            'badge'        => [
                'label' => self::plain(isset($badge['label']) ? (string) $badge['label'] : ''),
                'color' => isset($badge['color']) ? sanitize_key((string) $badge['color']) : '',
            ],
            'description'  => self::plain(isset($result['description']) ? (string) $result['description'] : ''),
            'actions'      => self::plain($actions),
            'action_links' => self::links($actions),
        ];
    }

    private static function not_completed(string $id, string $type, array $test, string $reason): array
    {
        return [
            'id'        => $id,
            'type'      => $type,
            'completed' => false,
            'status'    => 'not_completed',
            'label'     => self::plain(isset($test['label']) ? (string) $test['label'] : $id),
            'reason'    => $reason,
        ];
    }

    /** @return array{good: int, recommended: int, critical: int, not_completed: int} */
    private static function summarize(array $results): array
    {
        $summary = [ 'good' => 0, 'recommended' => 0, 'critical' => 0, 'not_completed' => 0 ];
        foreach ($results as $row) {
            ++$summary[ $row['status'] ];
        }
        return $summary;
    }

    /** Reduce HTML to plain text, keeping paragraph and list breaks as newlines. */
    private static function plain(string $html): string
    {
        if ('' === $html) {
            return '';
        }

        $text = (string) preg_replace('#<br\s*/?>|</(p|div|li|ul|ol|h[1-6]|tr|table)>#i', "\n", $html);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            explode("\n", $text)
        );

        return trim(implode("\n", array_filter($lines, static fn (string $line): bool => '' !== $line)));
    }

    /** @return array<int, array{text: string, url: string}> */
    private static function links(string $html): array
    {
        if ('' === $html || ! preg_match_all('#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $links = [];
        foreach ($matches as $match) {
            $links[] = [
                'text' => self::plain($match[3]),
                'url'  => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
        return $links;
    }
}
