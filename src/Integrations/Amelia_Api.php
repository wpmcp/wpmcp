<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The bridge into Amelia's own application layer (issue #499). Every Amelia
 * write runs through the controller Amelia's admin pages call, so Amelia's
 * permission checks, its slot and working-hours checks, its domain events
 * (notifications, calendar and other integrations) and its own time
 * conversions all run: the request is built and handed to the controller
 * directly, the way Amelia's own abilities do, with the nonce check skipped
 * because the caller is already authenticated here.
 *
 * Amelia_Pack is the only caller; the test suite swaps in a stand-in that
 * writes the fixture tables (Amelia_Pack::use_api_for_tests()).
 */
class Amelia_Api
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Whether Amelia is loaded. */
    public function active(): bool
    {
        return defined('AMELIA_PATH') && class_exists('AmeliaBooking\\Plugin', false);
    }

    /** Amelia's edition: Lite, Starter, Basic, Pro or Developer. */
    public function edition(): string
    {
        if (class_exists('AmeliaBooking\\Infrastructure\\Licence\\Licence')) {
            return (string) \AmeliaBooking\Infrastructure\Licence\Licence::getLicence();
        }
        return 'Lite';
    }

    /**
     * Run one Amelia controller and return its data. A refusal (any 4xx or
     * 5xx answer, or an exception Amelia raised) is an Operation_Error
     * carrying Amelia's own message: 'slot_unavailable' when Amelia says the
     * time slot is taken or outside working hours, 'amelia_refused'
     * otherwise.
     *
     * @param string               $controller class under AmeliaBooking\Application\Controller\, e.g. 'Bookable\Service\AddServiceController'
     * @param array<string, mixed> $params     the request body, or the query for a GET
     * @param array<string, mixed> $args       route arguments, e.g. ['id' => 3]
     * @return array<string, mixed>
     */
    public function request(string $controller, array $params, array $args = [], string $method = 'POST'): array
    {
        $class = 'AmeliaBooking\\Application\\Controller\\' . $controller;
        if (1 !== preg_match('/^[A-Za-z\\\\]+Controller$/', $controller) || ! class_exists($class)) {
            throw new Operation_Error('amelia_refused', sprintf('Amelia has no %s on this site.', $controller));
        }

        try {
            $container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
            $factory   = new \Slim\Psr7\Factory\ServerRequestFactory();
            // The controller reads the query string and body, never the host.
            $uri = home_url('/') . ('GET' === $method && [] !== $params ? '?' . http_build_query($params) : '');
            $request   = $factory->createServerRequest($method, $uri)->withHeader('Content-Type', 'application/json');
            if ('GET' !== $method) {
                $request = $request->withParsedBody($params);
            }
            $response = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse();
            $result   = ( new $class($container) )($request, $response, $args, true);
        } catch (Operation_Error $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Operation_Error('amelia_refused', 'Amelia refused the change: ' . $e->getMessage());
        }

        $status = (int) $result->getStatusCode();
        $body   = json_decode((string) $result->getBody(), true);
        $body   = is_array($body) ? $body : [];
        $data   = is_array($body['data'] ?? null) ? $body['data'] : [];
        if ($status >= 400) {
            $message = (string) ($body['message'] ?? $data['message'] ?? '');
            throw new Operation_Error(
                ! empty($data['timeSlotUnavailable']) ? 'slot_unavailable' : 'amelia_refused',
                '' !== $message ? 'Amelia refused: ' . $message : sprintf('Amelia refused the change (HTTP %d).', $status),
                [ 'amelia' => $data ]
            );
        }
        return $data;
    }
}
