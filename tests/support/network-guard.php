<?php
/**
 * Test network guard (issue #323): the suite must never perform a real HTTP
 * request.
 *
 * A `pre_http_request` filter runs last (priority PHP_INT_MAX). When a test's
 * own `pre_http_request` mock has already answered, the guard passes that
 * answer through untouched. Anything still unanswered would go out on the
 * wire, so the guard short-circuits it with a WP_Error (production code sees
 * an ordinary transport failure, never a hang on the network) and records the
 * URL. Network_Guard_Listener then fails the test that made the request,
 * naming the URL and the caller. Recording and failing from the listener,
 * rather than throwing from the filter, means code that catches Throwable
 * cannot swallow the violation.
 *
 * Mock HTTP with your own `pre_http_request` filter (any priority below
 * PHP_INT_MAX). Never allow a real public host.
 *
 * Opt-ins, both explicit:
 * - Network_Guard::allow( 'http://127.0.0.1:8080/' ) lets the current test
 *   reach a URL prefix it deliberately serves itself, such as a local test
 *   server. Only loopback prefixes are accepted, and the allowance ends with
 *   the test.
 * - WPMCP_TESTS_ALLOW_NETWORK=1 disables the guard for a whole run, for
 *   manual debugging only. The gate never sets it.
 */

namespace WPMCP\Tests\Support;

final class Network_Guard
{
    /** @var array<int, array{url: string, via: string}> requests blocked since the last reset. */
    private static array $violations = [];

    /** @var string[] loopback URL prefixes the current test opted in to. */
    private static array $allowed = [];

    public static function register(): void
    {
        if ('1' === (string) getenv('WPMCP_TESTS_ALLOW_NETWORK')) {
            return;
        }
        tests_add_filter('pre_http_request', [self::class, 'filter'], PHP_INT_MAX, 3);
    }

    /**
     * @param false|array|\WP_Error $pre
     * @return false|array|\WP_Error
     */
    public static function filter($pre, $args, $url)
    {
        if (false !== $pre) {
            return $pre;
        }

        $url = (string) $url;
        foreach (self::$allowed as $prefix) {
            if (0 === strpos($url, $prefix)) {
                return $pre;
            }
        }

        // The query string can run to kilobytes (core's version check sends
        // every loaded extension); the endpoint is what identifies the call.
        $shown = strtok($url, '?') . (false !== strpos($url, '?') ? '?...' : '');

        self::$violations[] = ['url' => $shown, 'via' => self::caller()];

        return new \WP_Error(
            'wpmcp_test_network_blocked',
            'Unmocked HTTP request blocked by the test network guard: ' . $url
        );
    }

    /**
     * Let the current test reach a loopback URL prefix it serves itself.
     * Public hosts are refused: mock those with a pre_http_request filter.
     */
    public static function allow(string $prefix): void
    {
        $host = strtolower((string) wp_parse_url($prefix, PHP_URL_HOST));
        if (! in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)) {
            throw new \InvalidArgumentException(
                'Network_Guard::allow() only accepts loopback URLs; mock "' . $prefix . '" with a pre_http_request filter instead.'
            );
        }
        self::$allowed[] = $prefix;
    }

    /**
     * The requests blocked since the last call, and reset for the next test.
     *
     * @return array<int, array{url: string, via: string}>
     */
    public static function take_violations(): array
    {
        $out              = self::$violations;
        self::$violations = [];
        return $out;
    }

    public static function reset_allowances(): void
    {
        self::$allowed = [];
    }

    /** The first frames above WordPress's HTTP API, to say who made the request. */
    private static function caller(): string
    {
        $skip  = ['filter', 'apply_filters', 'request', 'get', 'post', 'head', 'wp_remote_request',
            'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_safe_remote_request',
            'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'caller'];
        $names = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $fn = $frame['function'] ?? '';
            if (in_array($fn, $skip, true) || 0 === strpos($fn, '{closure')) {
                continue;
            }
            $names[] = (isset($frame['class']) ? $frame['class'] . '::' : '') . $fn . '()';
            if (count($names) >= 4) {
                break;
            }
        }
        return implode(' <- ', $names);
    }
}

/**
 * Fails any test during which Network_Guard blocked a request.
 */
final class Network_Guard_Listener implements \PHPUnit\Framework\TestListener
{
    use \PHPUnit\Framework\TestListenerDefaultImplementation;

    public function startTest(\PHPUnit\Framework\Test $test): void
    {
        // Anything recorded outside a test (bootstrap, a previous
        // setUpBeforeClass) is reported against this, the next test.
        Network_Guard::reset_allowances();
    }

    public function endTest(\PHPUnit\Framework\Test $test, float $time): void
    {
        Network_Guard::reset_allowances();
        $violations = Network_Guard::take_violations();
        if ([] === $violations || ! $test instanceof \PHPUnit\Framework\TestCase) {
            return;
        }

        $lines = array_map(
            static fn (array $v): string => '  ' . $v['url'] . ($v['via'] ? "\n    via " . $v['via'] : ''),
            $violations
        );
        $test->getTestResultObject()->addFailure(
            $test,
            new \PHPUnit\Framework\AssertionFailedError(
                "Unmocked HTTP request(s) blocked by the test network guard (issue #323). Mock them with a pre_http_request filter:\n"
                . implode("\n", $lines)
            ),
            $time
        );
    }
}
