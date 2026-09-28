<?php

namespace WPMCP\Tools\Analysis\SeoData;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * DataForSEO adapter (issue #304). Two documented endpoints, both POST with
 * HTTP Basic auth from the account's API login and password, which the
 * credential store holds as one "login:password" string:
 *
 *  - dataforseo_labs/google/keyword_overview/live: search volume, CPC,
 *    competition, keyword difficulty and main intent per keyword.
 *  - backlinks/summary/live: backlink, referring domain and page counts for
 *    a domain (bare, without scheme or www) or an absolute page URL.
 *
 * Errors come both as HTTP codes and as status_code fields on the envelope
 * and on each task (20000 is success). 401/40100 is a rejected credential,
 * 429/40202/40209 are rate limits, 402/40200/40210 are billing.
 */
class Dataforseo_Provider implements Seo_Data_Provider
{
    private const BASE = 'https://api.dataforseo.com/v3/';

    /** Pinned so the request carries no site identity (see Search_Stock_Images). */
    private const USER_AGENT = 'WPMCP-SEO-Data/1.0';

    private const OK = 20000;

    private const AUTH_CODES       = [40100, 40101];
    private const RATE_LIMIT_CODES = [40202, 40209];

    public function slug(): string
    {
        return 'dataforseo';
    }

    public function validate_credential(string $credential): void
    {
        $parts = explode(':', $credential, 2);
        if (2 !== count($parts) || '' === trim($parts[0]) || '' === trim($parts[1])) {
            throw new \InvalidArgumentException('A dataforseo credential is the API login and API password joined as "login:password".');
        }
    }

    public function keyword_metrics(string $credential, array $keywords, int $location_code, string $language_code): array
    {
        $result = $this->post($credential, 'dataforseo_labs/google/keyword_overview/live', [
            'keywords'      => array_values($keywords),
            'location_code' => $location_code,
            'language_code' => $language_code,
        ]);

        $out = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['keyword'])) {
                continue;
            }
            $keyword = function_exists('mb_strtolower') ? mb_strtolower((string) $item['keyword'], 'UTF-8') : strtolower((string) $item['keyword']);
            $info    = (array) ($item['keyword_info'] ?? []);
            $props   = (array) ($item['keyword_properties'] ?? []);
            $intent  = (array) ($item['search_intent_info'] ?? []);

            $out[ $keyword ] = [
                'search_volume'      => self::int_or_null($info['search_volume'] ?? null),
                'keyword_difficulty' => self::int_or_null($props['keyword_difficulty'] ?? null),
                'cpc'                => self::float_or_null($info['cpc'] ?? null),
                'competition'        => self::float_or_null($info['competition'] ?? null),
                'intent'             => isset($intent['main_intent']) ? (string) $intent['main_intent'] : null,
            ];
        }
        return $out;
    }

    public function backlink_summary(string $credential, string $target): array
    {
        $result = $this->post($credential, 'backlinks/summary/live', [
            'target'             => $target,
            'include_subdomains' => true,
        ]);

        return [
            'rank'                   => self::int_or_null($result['rank'] ?? null),
            'backlinks'              => self::int_or_null($result['backlinks'] ?? null),
            'referring_domains'      => self::int_or_null($result['referring_domains'] ?? null),
            'referring_main_domains' => self::int_or_null($result['referring_main_domains'] ?? null),
            'referring_pages'        => self::int_or_null($result['referring_pages'] ?? null),
            'broken_backlinks'       => self::int_or_null($result['broken_backlinks'] ?? null),
            'spam_score'             => self::int_or_null($result['backlinks_spam_score'] ?? null),
        ];
    }

    /** @return array the first task's first result, [] when it has none. */
    private function post(string $credential, string $path, array $task): array
    {
        $response = wp_remote_post(self::BASE . $path, [
            'timeout'     => 30,
            'redirection' => 0,
            'user-agent'  => self::USER_AGENT,
            'headers'     => [
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, which the provider requires.
                'Authorization' => 'Basic ' . base64_encode($credential),
                'Content-Type'  => 'application/json',
            ],
            'body'        => (string) wp_json_encode([$task]),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf(
                'The dataforseo request failed: %s',
                esc_html(Seo_Data_Lookup::scrub($response->get_error_message(), $credential))
            ));
        }

        $http = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (429 === $http) {
            throw new Seo_Data_Rate_Limited('rate limited', (int) wp_remote_retrieve_header($response, 'retry-after'));
        }
        if (401 === $http || 403 === $http) {
            $this->refuse_credential($http);
        }
        if (is_array($body)) {
            $this->check_status($body, $credential);
        }
        if (200 !== $http) {
            throw new \RuntimeException(sprintf('The dataforseo API answered HTTP %d.', (int) $http));
        }
        if (! is_array($body)) {
            throw new \RuntimeException('The dataforseo API returned an unparseable body.');
        }

        $first = $body['tasks'][0] ?? null;
        if (! is_array($first)) {
            throw new \RuntimeException('The dataforseo API returned no task.');
        }
        $this->check_status($first, $credential);

        $result = $first['result'][0] ?? [];
        return is_array($result) ? $result : [];
    }

    /** Throw for a non-success status_code on an envelope or a task. */
    private function check_status(array $node, string $credential): void
    {
        if (! isset($node['status_code'])) {
            return;
        }
        $code = (int) $node['status_code'];
        if (self::OK === $code) {
            return;
        }
        if (in_array($code, self::AUTH_CODES, true)) {
            $this->refuse_credential($code);
        }
        if (in_array($code, self::RATE_LIMIT_CODES, true)) {
            throw new Seo_Data_Rate_Limited('rate limited', 0);
        }

        $message = substr(Seo_Data_Lookup::scrub((string) ($node['status_message'] ?? ''), $credential), 0, 200);
        throw new \RuntimeException(sprintf(
            'The dataforseo API refused the request (status %d): %s',
            (int) $code,
            esc_html($message)
        ));
    }

    private function refuse_credential(int $code): void
    {
        throw new \RuntimeException(sprintf(
            'The dataforseo API rejected the saved credentials (%d). Check the API login and password and save them again with set-seo-data-key.',
            (int) $code
        ));
    }

    private static function int_or_null($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function float_or_null($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
